<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * NC-user binding schema (nc-user-binding spec §9.1, v1.3.0).
 *
 * Two-layer model (D-B01): the NC account link lives on the person
 * (kzc_identity.nc_uid + origin + durable mismatch flag), the per-org share
 * binding lives in kzc_sync keyed (identity_id, org_id). The table is
 * provisioned-safe from day one (origin 'attach'|'provisioned') so a later
 * phase-2 needs no re-migration.
 *
 * kzc_upload.integrity persists the US-S14 re-verify flag:
 * 'ok' | 'modified_after_upload' | 'deleted_after_upload'.
 *
 * All additions guarded (hasColumn/hasTable) — the migration is idempotent
 * and re-runnable per nextcloud-db-standards.
 */
class Version1001Date202611050000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kzc_identity')) {
			$t = $schema->getTable('kzc_identity');
			if (!$t->hasColumn('nc_uid')) {
				$t->addColumn('nc_uid', 'string', ['notnull' => false, 'length' => 64]);
			}
			if (!$t->hasColumn('nc_uid_origin')) {
				$t->addColumn('nc_uid_origin', 'string', ['notnull' => false, 'length' => 16]);
			}
			if (!$t->hasColumn('nc_uid_mismatch')) {
				$t->addColumn('nc_uid_mismatch', 'boolean', ['notnull' => false, 'default' => false]);
			}
		}

		if ($schema->hasTable('kzc_upload')) {
			$t = $schema->getTable('kzc_upload');
			if (!$t->hasColumn('integrity')) {
				$t->addColumn('integrity', 'string', ['notnull' => true, 'length' => 24, 'default' => 'ok']);
			}
		}

		if (!$schema->hasTable('kzc_sync')) {
			$t = $schema->createTable('kzc_sync');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('identity_id', 'bigint', ['notnull' => true]);
			$t->addColumn('org_id', 'bigint', ['notnull' => true]);
			$t->addColumn('share_id', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('share_node_id', 'bigint', ['notnull' => false]);
			$t->addColumn('shared_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('perms', 'string', ['notnull' => true, 'length' => 8, 'default' => 'rw']);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$t->addColumn('last_verify_at', 'datetime', ['notnull' => false]);
			$t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'kzc_sync_pk');
			$t->addUniqueIndex(['identity_id', 'org_id'], 'kzc_sync_uq');
			$t->addIndex(['org_id'], 'kzc_sync_org_idx');
			$t->addIndex(['state'], 'kzc_sync_state_idx');
		}

		return $schema;
	}
}
