<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Drops every table the kanzleicheck app has ever created, migration rows, and app config.
 *
 * Disable (including auto-disable during a server upgrade) always preserves data;
 * only an explicit app removal drops tables. The documents root folder in Files is
 * NEVER touched — it holds the customer's records (SPEC §6 upgrade/uninstall row).
 */
namespace OCA\KanzleiCheck\Repair;

use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

final class UninstallDropTables implements IRepairStep
{
	public const APP_ID = 'kanzleicheck';

	/**
	 * Legacy counter from the two-pass implementation. Cleared on disable and by schema
	 * repair steps so upgrades never inherit a stale value.
	 */
	public const REPAIR_PASS_KEY = 'uninstall_repair_pass';

	/**
	 * Sorted list of every table this app has ever created across all migrations.
	 * Kept in sync by the DB-standards linter.
	 */
	public const TABLES = [
		'kzc_approval',
		'kzc_audit',
		'kzc_contact',
		'kzc_conv',
		'kzc_device',
		'kzc_form',
		'kzc_form_ans',
		'kzc_form_tpl',
		'kzc_identity',
		'kzc_intake',
		'kzc_intake_hold',
		'kzc_job_cursor',
		'kzc_license_state',
		'kzc_mail_outbox',
		'kzc_member',
		'kzc_message',
		'kzc_mobile_seat',
		'kzc_notify',
		'kzc_org',
		'kzc_otp',
		'kzc_read',
		'kzc_req',
		'kzc_req_file',
		'kzc_req_item',
		'kzc_req_remind',
		'kzc_req_tpl',
		'kzc_req_tpl_org',
		'kzc_session',
		'kzc_sync',
		'kzc_task',
		'kzc_ulink',
		'kzc_upload',
		'kzc_upload_chunk',
	];

	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
		private readonly IRootFolder $rootFolder,
		private readonly ?\OCP\Share\IManager $shareManager = null,
	) {
	}

	public function getName(): string
	{
		return 'Drop kanzleicheck tables and install metadata on uninstall';
	}

	public function run(IOutput $output): void
	{
		if (UninstallRepairFlow::isRemovalContext()) {
			$this->dropAllTablesAndMetadata($output);
			return;
		}

		// Disable path (manual or auto during server upgrade): idempotent — never drop.
		$this->config->deleteAppValue(self::APP_ID, self::REPAIR_PASS_KEY);
		$output->info(
			'kanzleicheck: preserving data on disable. '
			. 'Tables, migration history, and settings are kept until the app is fully removed.'
		);
	}

	private function dropAllTablesAndMetadata(IOutput $output): void
	{
		// GAC-7/US-S11: every live binding share is revoked BEFORE kzc_sync
		// drops — app removal must leave zero dangling shares.
		$this->revokeBindingShares($output);
		$provider = $this->connection->getDatabaseProvider();
		$fkChecksDisabled = false;
		if ($provider === IDBConnection::PLATFORM_MYSQL) {
			$this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
			$fkChecksDisabled = true;
		}

		$dropped = 0;
		foreach (self::TABLES as $table) {
			if ($this->dropLogicalTableIfExists($table)) {
				$dropped++;
			}
		}

		if ($fkChecksDisabled) {
			$this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->delete('migrations')
			->where($qb->expr()->eq('app', $qb->createNamedParameter(self::APP_ID)));
		$migrationsRemoved = $qb->executeStatement();

		$this->config->deleteAppValues(self::APP_ID);

		$output->info(sprintf(
			'kanzleicheck: dropped %d of %d table(s); removed %d migration row(s) and app config. Documents in Files are untouched.',
			$dropped,
			count(self::TABLES),
			$migrationsRemoved,
		));
	}

	private function dropLogicalTableIfExists(string $logicalTable): bool
	{
		if (!$this->connection->tableExists($logicalTable)) {
			return false;
		}

		$prefix = (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
		$this->connection->executeStatement('DROP TABLE IF EXISTS ' . $prefix . $logicalTable);
		return true;
	}

	private function revokeBindingShares(IOutput $output): void
	{
		if ($this->shareManager === null || !$this->connection->tableExists('kzc_sync')) {
			return;
		}
		$revoked = 0;
		try {
			$qb = $this->connection->getQueryBuilder();
			$qb->select('s.share_id', 'i.nc_uid')->from('kzc_sync', 's')
				->leftJoin('s', 'kzc_identity', 'i', 'i.id = s.identity_id')
				->where($qb->expr()->neq('s.state', $qb->createNamedParameter('revoked')));
			foreach ($qb->executeQuery()->fetchAll() ?: [] as $row) {
				$shareId = (string)($row['share_id'] ?? '');
				$uid = (string)($row['nc_uid'] ?? '');
				if ($shareId === '' || $uid === '') {
					continue;
				}
				try {
					$this->shareManager->deleteShare($this->shareManager->getShareById($shareId, $uid));
					$revoked++;
				} catch (\Throwable) {
					// already gone — uninstall tolerates it
				}
			}
		} catch (\Throwable) {
			// join against a partially-migrated tree must not block removal
		}
		if ($revoked > 0) {
			$output->info(sprintf('kanzleicheck: revoked %d binding share(s) before table drop.', $revoked));
		}
	}
}
