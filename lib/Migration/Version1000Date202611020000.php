<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial PortalCheck schema (SPEC v2.1.0 §9.2 + LICENSE-PTL2-SPEC §5).
 *
 * Every table carries a short PK name and guarded creation; index names stay
 * ≤30 chars for Oracle-style identifier rules. No DB foreign keys — cross-table
 * references are validated in mappers/services (NC app convention).
 */
class Version1000Date202611020000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ptl_org')) {
			$t = $schema->createTable('ptl_org');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('ref', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 16, 'default' => 'company']);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$t->addColumn('app_on', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('app_on_at', 'datetime', ['notnull' => false]);
			$t->addColumn('folder_id', 'bigint', ['notnull' => false]);
			$t->addColumn('folder_path', 'string', ['notnull' => false, 'length' => 512]);
			$t->addColumn('pc_cust_id', 'bigint', ['notnull' => false]);
			$t->addColumn('book_url', 'string', ['notnull' => false, 'length' => 255]);
			$t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'ptl_org_pk');
			$t->addUniqueIndex(['ref'], 'ptl_org_ref_uq');
			$t->addIndex(['state'], 'ptl_org_state_idx');
		}

		if (!$schema->hasTable('ptl_identity')) {
			$t = $schema->createTable('ptl_identity');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('email', 'string', ['notnull' => true, 'length' => 254]);
			$t->addColumn('display_name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$t->addColumn('locale', 'string', ['notnull' => false, 'length' => 16]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('last_login_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'ptl_ident_pk');
			$t->addUniqueIndex(['email'], 'ptl_ident_email_uq');
		}

		if (!$schema->hasTable('ptl_member')) {
			$t = $schema->createTable('ptl_member');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('identity_id', 'bigint', ['notnull' => true]);
			$t->addColumn('org_id', 'bigint', ['notnull' => true]);
			$t->addColumn('role', 'string', ['notnull' => true, 'length' => 16, 'default' => 'member']);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['id'], 'ptl_mem_pk');
			$t->addUniqueIndex(['identity_id', 'org_id'], 'ptl_mem_uq');
			$t->addIndex(['org_id'], 'ptl_mem_org_idx');
		}

		if (!$schema->hasTable('ptl_otp')) {
			$t = $schema->createTable('ptl_otp');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('email', 'string', ['notnull' => true, 'length' => 254]);
			$t->addColumn('purpose', 'string', ['notnull' => true, 'length' => 32, 'default' => 'login']);
			$t->addColumn('code_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('magic_hash', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('attempts', 'smallint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('expires_at', 'datetime', ['notnull' => true]);
			$t->addColumn('used_at', 'datetime', ['notnull' => false]);
			$t->addColumn('ip_hash', 'string', ['notnull' => false, 'length' => 64]);
			$t->setPrimaryKey(['id'], 'ptl_otp_pk');
			$t->addIndex(['email', 'purpose'], 'ptl_otp_email_idx');
			$t->addIndex(['magic_hash'], 'ptl_otp_magic_idx');
		}

		if (!$schema->hasTable('ptl_session')) {
			$t = $schema->createTable('ptl_session');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('token_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('identity_id', 'bigint', ['notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('expires_at', 'datetime', ['notnull' => true]);
			$t->addColumn('abs_expires_at', 'datetime', ['notnull' => true]);
			$t->addColumn('last_seen_at', 'datetime', ['notnull' => true]);
			$t->addColumn('ua_short', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('revoked_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'ptl_sess_pk');
			$t->addUniqueIndex(['token_hash'], 'ptl_sess_tok_uq');
			$t->addIndex(['identity_id'], 'ptl_sess_ident_idx');
		}

		if (!$schema->hasTable('ptl_device')) {
			$t = $schema->createTable('ptl_device');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('identity_id', 'bigint', ['notnull' => true]);
			$t->addColumn('token_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('device_name', 'string', ['notnull' => true, 'length' => 100]);
			$t->addColumn('platform', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('last_used_at', 'datetime', ['notnull' => true]);
			$t->addColumn('abs_expires_at', 'datetime', ['notnull' => true]);
			$t->addColumn('revoked_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'ptl_dev_pk');
			$t->addUniqueIndex(['token_hash'], 'ptl_dev_tok_uq');
			$t->addIndex(['identity_id'], 'ptl_dev_ident_idx');
		}

		if (!$schema->hasTable('ptl_notify')) {
			$t = $schema->createTable('ptl_notify');
			$t->addColumn('identity_id', 'bigint', ['notnull' => true]);
			$t->addColumn('org_id', 'bigint', ['notnull' => true]);
			$t->addColumn('mails_enabled', 'boolean', ['notnull' => false, 'default' => true]);
			$t->addColumn('last_mail_at', 'datetime', ['notnull' => false]);
			$t->addColumn('pending_kinds', 'string', ['notnull' => false, 'length' => 64]);
			$t->setPrimaryKey(['identity_id', 'org_id'], 'ptl_notify_pk');
		}

		if (!$schema->hasTable('ptl_conv')) {
			$t = $schema->createTable('ptl_conv');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('org_id', 'bigint', ['notnull' => true]);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('last_message_at', 'datetime', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'ptl_conv_pk');
			$t->addUniqueIndex(['org_id'], 'ptl_conv_org_uq');
		}

		if (!$schema->hasTable('ptl_message')) {
			$t = $schema->createTable('ptl_message');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('conv_id', 'bigint', ['notnull' => true]);
			$t->addColumn('author', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('author_kind', 'string', ['notnull' => true, 'length' => 8]);
			$t->addColumn('client_uuid', 'string', ['notnull' => false, 'length' => 36]);
			$t->addColumn('body', 'text', ['notnull' => false]);
			$t->addColumn('file_id', 'bigint', ['notnull' => false]);
			$t->addColumn('upload_id', 'bigint', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'ptl_msg_pk');
			$t->addUniqueIndex(['conv_id', 'author', 'client_uuid'], 'ptl_msg_dedupe_uq');
			$t->addIndex(['conv_id', 'id'], 'ptl_msg_conv_idx');
		}

		if (!$schema->hasTable('ptl_read')) {
			$t = $schema->createTable('ptl_read');
			$t->addColumn('conv_id', 'bigint', ['notnull' => true]);
			$t->addColumn('actor', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('last_read_id', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['conv_id', 'actor'], 'ptl_read_pk');
		}

		if (!$schema->hasTable('ptl_upload')) {
			$t = $schema->createTable('ptl_upload');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('org_id', 'bigint', ['notnull' => true]);
			$t->addColumn('uploader', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('file_id', 'bigint', ['notnull' => true]);
			$t->addColumn('file_name', 'string', ['notnull' => true, 'length' => 255]);
			$t->addColumn('size_bytes', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('sha256', 'string', ['notnull' => true, 'length' => 64, 'default' => '']);
			$t->addColumn('source', 'string', ['notnull' => true, 'length' => 16, 'default' => 'portal']);
			$t->addColumn('is_duplicate', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('note', 'string', ['notnull' => false, 'length' => 500]);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'neu']);
			$t->addColumn('reviewed_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('reviewed_at', 'datetime', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'ptl_upl_pk');
			$t->addIndex(['org_id', 'sha256'], 'ptl_upl_hash_idx');
			$t->addIndex(['org_id', 'state'], 'ptl_upl_state_idx');
		}

		if (!$schema->hasTable('ptl_audit')) {
			$t = $schema->createTable('ptl_audit');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('actor', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('action', 'string', ['notnull' => true, 'length' => 48]);
			$t->addColumn('target_type', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('target_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('detail', 'text', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'ptl_audit_pk');
			$t->addIndex(['target_type', 'target_id'], 'ptl_audit_tgt_idx');
			$t->addIndex(['created_at'], 'ptl_audit_time_idx');
		}

		if (!$schema->hasTable('ptl_task')) {
			$t = $schema->createTable('ptl_task');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('org_id', 'bigint', ['notnull' => true]);
			$t->addColumn('title', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('note', 'text', ['notnull' => false]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'new']);
			$t->addColumn('priority', 'string', ['notnull' => true, 'length' => 8, 'default' => 'normal']);
			$t->addColumn('due_date', 'date', ['notnull' => false]);
			$t->addColumn('done_at', 'datetime', ['notnull' => false]);
			$t->addColumn('portal_visibility', 'string', ['notnull' => true, 'length' => 8, 'default' => 'status']);
			$t->addColumn('revision', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('assignee', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'ptl_task_pk');
			$t->addIndex(['status', 'org_id'], 'ptl_task_board_idx');
			$t->addIndex(['due_date'], 'ptl_task_due_idx');
		}

		if (!$schema->hasTable('ptl_req')) {
			$t = $schema->createTable('ptl_req');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('org_id', 'bigint', ['notnull' => true]);
			$t->addColumn('title', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('note', 'text', ['notnull' => false]);
			$t->addColumn('deadline', 'date', ['notnull' => false]);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('task_id', 'bigint', ['notnull' => false]);
			$t->addColumn('template_id', 'bigint', ['notnull' => false]);
			$t->addColumn('period_key', 'string', ['notnull' => false, 'length' => 16]);
			$t->addColumn('reminder_policy', 'string', ['notnull' => true, 'length' => 64, 'default' => '-3,0,+3x5']);
			$t->addColumn('reminders_sent', 'smallint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('last_reminder_at', 'datetime', ['notnull' => false]);
			$t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('closed_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'ptl_req_pk');
			$t->addUniqueIndex(['template_id', 'org_id', 'period_key'], 'ptl_req_period_uq');
			$t->addIndex(['org_id', 'state'], 'ptl_req_org_idx');
		}

		if (!$schema->hasTable('ptl_req_item')) {
			$t = $schema->createTable('ptl_req_item');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('req_id', 'bigint', ['notnull' => true]);
			$t->addColumn('position', 'smallint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('label', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('hint', 'string', ['notnull' => false, 'length' => 500]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 8, 'default' => 'upload']);
			$t->addColumn('form_id', 'bigint', ['notnull' => false]);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('reject_reason', 'string', ['notnull' => false, 'length' => 500]);
			$t->addColumn('delivered_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('delivered_at', 'datetime', ['notnull' => false]);
			$t->addColumn('reviewed_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('reviewed_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'ptl_req_item_pk');
			$t->addIndex(['req_id'], 'ptl_req_item_req_idx');
		}

		if (!$schema->hasTable('ptl_req_file')) {
			$t = $schema->createTable('ptl_req_file');
			$t->addColumn('item_id', 'bigint', ['notnull' => true]);
			$t->addColumn('upload_id', 'bigint', ['notnull' => true]);
			$t->setPrimaryKey(['item_id', 'upload_id'], 'ptl_req_file_pk');
		}

		if (!$schema->hasTable('ptl_req_tpl')) {
			$t = $schema->createTable('ptl_req_tpl');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('preset', 'string', ['notnull' => false, 'length' => 32]);
			$t->addColumn('items_json', 'text', ['notnull' => true]);
			$t->addColumn('deadline_offset_days', 'smallint', ['notnull' => false]);
			$t->addColumn('reminder_policy', 'string', ['notnull' => true, 'length' => 64, 'default' => '-3,0,+3x5']);
			$t->addColumn('recurrence', 'string', ['notnull' => true, 'length' => 16, 'default' => 'none']);
			$t->addColumn('recurrence_day', 'smallint', ['notnull' => false]);
			$t->addColumn('recurrence_month', 'smallint', ['notnull' => false]);
			$t->addColumn('active', 'boolean', ['notnull' => false, 'default' => true]);
			$t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'ptl_req_tpl_pk');
		}

		if (!$schema->hasTable('ptl_req_tpl_org')) {
			$t = $schema->createTable('ptl_req_tpl_org');
			$t->addColumn('template_id', 'bigint', ['notnull' => true]);
			$t->addColumn('org_id', 'bigint', ['notnull' => true]);
			$t->setPrimaryKey(['template_id', 'org_id'], 'ptl_req_tpl_org_pk');
			$t->addIndex(['org_id'], 'ptl_req_tpl_org_o_idx');
		}

		if (!$schema->hasTable('ptl_req_remind')) {
			$t = $schema->createTable('ptl_req_remind');
			$t->addColumn('req_id', 'bigint', ['notnull' => true]);
			$t->addColumn('remind_on', 'date', ['notnull' => true]);
			$t->setPrimaryKey(['req_id', 'remind_on'], 'ptl_req_remind_pk');
		}

		if (!$schema->hasTable('ptl_approval')) {
			$t = $schema->createTable('ptl_approval');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('org_id', 'bigint', ['notnull' => true]);
			$t->addColumn('file_id', 'bigint', ['notnull' => true]);
			$t->addColumn('file_sha256', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('file_etag', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('file_name', 'string', ['notnull' => true, 'length' => 255]);
			$t->addColumn('required_identity_id', 'bigint', ['notnull' => false]);
			$t->addColumn('deadline', 'date', ['notnull' => false]);
			$t->addColumn('note', 'string', ['notnull' => false, 'length' => 1000]);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$t->addColumn('decided_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('decided_at', 'datetime', ['notnull' => false]);
			$t->addColumn('reason', 'string', ['notnull' => false, 'length' => 1000]);
			$t->addColumn('confirm_method', 'string', ['notnull' => false, 'length' => 16]);
			$t->addColumn('otp_id', 'bigint', ['notnull' => false]);
			$t->addColumn('requested_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('requested_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'ptl_appr_pk');
			$t->addIndex(['org_id', 'state'], 'ptl_appr_org_idx');
		}

		if (!$schema->hasTable('ptl_intake')) {
			$t = $schema->createTable('ptl_intake');
			$t->addColumn('org_id', 'bigint', ['notnull' => true]);
			$t->addColumn('enabled', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('token_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('rotated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['org_id'], 'ptl_intake_pk');
		}

		if (!$schema->hasTable('ptl_intake_hold')) {
			$t = $schema->createTable('ptl_intake_hold');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('org_id', 'bigint', ['notnull' => true]);
			$t->addColumn('msg_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('sender_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('received_at', 'datetime', ['notnull' => true]);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'held']);
			$t->addColumn('reviewed_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('reviewed_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'ptl_ihold_pk');
			$t->addIndex(['org_id', 'state'], 'ptl_ihold_org_idx');
		}

		if (!$schema->hasTable('ptl_contact')) {
			$t = $schema->createTable('ptl_contact');
			$t->addColumn('org_id', 'bigint', ['notnull' => true]);
			$t->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('role_label', 'string', ['notnull' => false, 'length' => 80]);
			$t->addColumn('show_phone', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('show_email', 'boolean', ['notnull' => false, 'default' => true]);
			$t->addColumn('position', 'smallint', ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['org_id', 'user_id'], 'ptl_contact_pk');
		}

		if (!$schema->hasTable('ptl_form_tpl')) {
			$t = $schema->createTable('ptl_form_tpl');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('fields_json', 'text', ['notnull' => true]);
			$t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'ptl_form_tpl_pk');
		}

		if (!$schema->hasTable('ptl_form')) {
			$t = $schema->createTable('ptl_form');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('org_id', 'bigint', ['notnull' => true]);
			$t->addColumn('tpl_id', 'bigint', ['notnull' => true]);
			$t->addColumn('req_item_id', 'bigint', ['notnull' => false]);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('submitted_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('submitted_at', 'datetime', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'ptl_form_pk');
			$t->addIndex(['org_id', 'state'], 'ptl_form_org_idx');
		}

		if (!$schema->hasTable('ptl_form_ans')) {
			$t = $schema->createTable('ptl_form_ans');
			$t->addColumn('form_id', 'bigint', ['notnull' => true]);
			$t->addColumn('field_key', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('value', 'text', ['notnull' => false]);
			$t->addColumn('revision', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('updated_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['form_id', 'field_key'], 'ptl_form_ans_pk');
		}

		if (!$schema->hasTable('ptl_ulink')) {
			$t = $schema->createTable('ptl_ulink');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('org_id', 'bigint', ['notnull' => true]);
			$t->addColumn('label', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('token_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('expires_at', 'datetime', ['notnull' => true]);
			$t->addColumn('max_files', 'smallint', ['notnull' => true, 'default' => 50]);
			$t->addColumn('used_files', 'smallint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('max_bytes', 'bigint', ['notnull' => true, 'default' => 209715200]);
			$t->addColumn('used_bytes', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('show_org', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('revoked_at', 'datetime', ['notnull' => false]);
			$t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'ptl_ulink_pk');
			$t->addUniqueIndex(['token_hash'], 'ptl_ulink_tok_uq');
			$t->addIndex(['org_id'], 'ptl_ulink_org_idx');
		}

		if (!$schema->hasTable('ptl_mail_outbox')) {
			$t = $schema->createTable('ptl_mail_outbox');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('idem_key', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('to_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('to_enc', 'text', ['notnull' => false]);
			$t->addColumn('locale', 'string', ['notnull' => false, 'length' => 16]);
			$t->addColumn('params_json', 'text', ['notnull' => false]);
			$t->addColumn('discard', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$t->addColumn('tries', 'smallint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('next_try_at', 'datetime', ['notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('sent_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'ptl_outbox_pk');
			$t->addUniqueIndex(['idem_key'], 'ptl_outbox_idem_uq');
			$t->addIndex(['state', 'next_try_at'], 'ptl_outbox_due_idx');
		}

		if (!$schema->hasTable('ptl_upload_chunk')) {
			$t = $schema->createTable('ptl_upload_chunk');
			$t->addColumn('upload_id', 'string', ['notnull' => true, 'length' => 36]);
			$t->addColumn('org_id', 'bigint', ['notnull' => true]);
			$t->addColumn('actor', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('file_name', 'string', ['notnull' => true, 'length' => 255]);
			$t->addColumn('total_bytes', 'bigint', ['notnull' => true]);
			$t->addColumn('received_bytes', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('chunk_count', 'smallint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'receiving']);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('last_chunk_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['upload_id'], 'ptl_chunk_pk');
			$t->addIndex(['last_chunk_at'], 'ptl_chunk_idle_idx');
		}

		if (!$schema->hasTable('ptl_job_cursor')) {
			$t = $schema->createTable('ptl_job_cursor');
			$t->addColumn('job', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('cursor', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['job'], 'ptl_jcur_pk');
		}

		if (!$schema->hasTable('ptl_license_state')) {
			$t = $schema->createTable('ptl_license_state');
			$t->addColumn('id', 'integer', ['notnull' => true]);
			$t->addColumn('key_fingerprint', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('payload_json', 'text', ['notnull' => true]);
			$t->addColumn('signature_b64', 'string', ['notnull' => true, 'length' => 128]);
			$t->addColumn('customer_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('valid_until', 'string', ['notnull' => true, 'length' => 10]);
			$t->addColumn('mobile_seats', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('portal_clients', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('applied_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('applied_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'ptl_lic_pk');
		}

		if (!$schema->hasTable('ptl_mobile_seat')) {
			$t = $schema->createTable('ptl_mobile_seat');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('assigned_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('assigned_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'ptl_mseat_pk');
			$t->addUniqueIndex(['user_id'], 'ptl_mseat_user_uq');
		}

		return $schema;
	}
}
