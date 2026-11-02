<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Drops every table the portalcheck app has ever created, migration rows, and app config.
 *
 * Disable (including auto-disable during a server upgrade) always preserves data;
 * only an explicit app removal drops tables. The documents root folder in Files is
 * NEVER touched — it holds the customer's records (SPEC §6 upgrade/uninstall row).
 */
namespace OCA\PortalCheck\Repair;

use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

final class UninstallDropTables implements IRepairStep
{
	public const APP_ID = 'portalcheck';

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
		'ptl_approval',
		'ptl_audit',
		'ptl_contact',
		'ptl_conv',
		'ptl_device',
		'ptl_form',
		'ptl_form_ans',
		'ptl_form_tpl',
		'ptl_identity',
		'ptl_intake',
		'ptl_intake_hold',
		'ptl_job_cursor',
		'ptl_license_state',
		'ptl_mail_outbox',
		'ptl_member',
		'ptl_message',
		'ptl_mobile_seat',
		'ptl_notify',
		'ptl_org',
		'ptl_otp',
		'ptl_read',
		'ptl_req',
		'ptl_req_file',
		'ptl_req_item',
		'ptl_req_remind',
		'ptl_req_tpl',
		'ptl_req_tpl_org',
		'ptl_session',
		'ptl_task',
		'ptl_ulink',
		'ptl_upload',
		'ptl_upload_chunk',
	];

	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
		private readonly IRootFolder $rootFolder,
	) {
	}

	public function getName(): string
	{
		return 'Drop portalcheck tables and install metadata on uninstall';
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
			'portalcheck: preserving data on disable. '
			. 'Tables, migration history, and settings are kept until the app is fully removed.'
		);
	}

	private function dropAllTablesAndMetadata(IOutput $output): void
	{
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
			'portalcheck: dropped %d of %d table(s); removed %d migration row(s) and app config. Documents in Files are untouched.',
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
}
