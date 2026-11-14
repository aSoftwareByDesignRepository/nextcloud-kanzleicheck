<?php

declare(strict_types=1);

/**
 * KanzleiCheck transfer manifest — every appconfig key and every kzc_* table
 * classified exactly once
 * (gate: scripts/check-config-transfer-manifest.py).
 *
 * The 'transfer' settings section lives in PageController::settings() —
 * KanzleiCheck has no *SectionCatalog; the settings surface is the staff SPA
 * and /settings/transfer renders the synced part as a dedicated page.
 */

namespace OCA\KanzleiCheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door / admin policy — written atomically through
			// AccessDoorService::saveDoor (never raw setAppValue; the
			// SYNC_DOOR_BINDINGS_LIVE guard is a door-state invariant) ──
			'door_restricted' => ['class' => 'mirrored', 'security' => true, 'section' => 'access'],
			'allowed_groups' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'access'],
			'allowed_users' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'app_admins' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			// ── general settings (Settings → General / inbound mail) ──
			'preset' => ['class' => 'plain', 'section' => 'settings'],
			'provider_label' => ['class' => 'plain', 'section' => 'settings'],
			'upload_max_mb' => ['class' => 'plain', 'section' => 'settings'],
			'poll_ms' => ['class' => 'plain', 'section' => 'settings'],
			'otp_ttl_min' => ['class' => 'plain', 'section' => 'settings'],
			'sess_days' => ['class' => 'plain', 'section' => 'settings'],
			'audit_keep_days' => ['class' => 'plain', 'section' => 'settings'],
			'approval_keep_days' => ['class' => 'plain', 'section' => 'settings'],
			// Plain HTTP login is a security-relevant weakening — highlighted
			// in the report, still a normal transferable flag.
			'allow_http_login' => ['class' => 'plain', 'security' => true, 'section' => 'settings'],
			'inbound_enabled' => ['class' => 'plain', 'section' => 'settings'],
			'inbound_host' => ['class' => 'plain', 'section' => 'settings'],
			'inbound_port' => ['class' => 'plain', 'section' => 'settings'],
			'inbound_user' => ['class' => 'plain', 'section' => 'settings'],
			'inbound_local' => ['class' => 'plain', 'section' => 'settings'],
			'inbound_domain' => ['class' => 'plain', 'section' => 'settings'],
			// ── secrets — redacted at export unless --include-secrets ──
			'inbound_pass_enc' => 'secret',
			'inbound_secret' => 'secret',
			// ── instance-bound — never transferred ──
			// docs_root_id is a Nextcloud file id (documents root folder) —
			// meaningless on another instance.
			'docs_root_id' => 'env',
			// inbound_cursor is the IMAP sync cursor — live state, not config.
			'inbound_cursor' => 'internal',
			// ── internal bookkeeping — never transferred ──
			'uninstall_repair_pass' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_job_state' => 'internal',
			'transfer_last_import_at' => 'internal',
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ org + catalog master data ══
			// ref is the app-level unique org key but nullable — name +
			// created_by keep NULL-ref orgs distinct. created_by is
			// provenance — carried verbatim like every *_by / *_at column
			// below: a ghost uid degrades a label, never sinks a row.
			// folder_id/folder_path/pc_cust_id are this instance's Files ids
			// and the customercheck link — env (NULL on insert).
			'kzc_org' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['ref', 'name', 'created_by'],
				'env_columns' => ['folder_id', 'folder_path', 'pc_cust_id']],
			'kzc_req_tpl' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name']],
			'kzc_req_tpl_org' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['template_id', 'org_id'], 'pk' => ['template_id', 'org_id'],
				'fk' => ['template_id' => 'kzc_req_tpl', 'org_id' => 'kzc_org'],
				'depends_on' => ['kzc_req_tpl', 'kzc_org']],
			'kzc_form_tpl' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name']],

			// ══ client identities + org-scoped business data ══
			// nc_uid/nc_uid_origin/nc_uid_mismatch carry verbatim: a uid that
			// does not exist on the target is a bindable identity, not a
			// lost client — SyncReconcileJob already owns the durable
			// mismatch flag (US-B spec) that heals the link.
			'kzc_identity' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['email']],
			'kzc_member' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['identity_id', 'org_id'], 'pk' => ['identity_id', 'org_id'],
				'fk' => ['identity_id' => 'kzc_identity', 'org_id' => 'kzc_org'],
				'depends_on' => ['kzc_identity', 'kzc_org']],
			// Staff contact persons point at NC uids — a contact for a uid
			// that does not exist on the target is meaningless → skip row.
			'kzc_contact' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['org_id', 'user_id'], 'pk' => ['org_id', 'user_id'],
				'fk' => ['org_id' => 'kzc_org'], 'depends_on' => ['kzc_org'],
				'uid_ref' => ['user_id']],
			'kzc_conv' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['org_id'],
				'fk' => ['org_id' => 'kzc_org'], 'depends_on' => ['kzc_org']],
			// author is a mixed actor string (staff uid / client identity /
			// 'system') — never a uid_ref; verbatim like every actor column.
			// file_id/upload_id reference this instance's Files and the
			// internal kzc_upload table — env (NULL on insert).
			'kzc_message' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['conv_id', 'author', 'client_uuid', 'created_at'],
				'fk' => ['conv_id' => 'kzc_conv'], 'depends_on' => ['kzc_conv'],
				'env_columns' => ['file_id', 'upload_id']],
			'kzc_task' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['org_id', 'title', 'created_at'],
				'fk' => ['org_id' => 'kzc_org'], 'depends_on' => ['kzc_org']],
			'kzc_req' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['template_id', 'org_id', 'period_key', 'title', 'created_at'],
				'fk' => ['org_id' => 'kzc_org', 'template_id' => 'kzc_req_tpl', 'task_id' => 'kzc_task'],
				'depends_on' => ['kzc_org', 'kzc_req_tpl', 'kzc_task']],
			// form_id ↔ kzc_form.req_item_id is a cyclic pair — both resolve
			// post-load (fk_late); an unresolved late ref NULLs, never skips.
			'kzc_req_item' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['req_id', 'position', 'label'],
				'fk' => ['req_id' => 'kzc_req'],
				'fk_late' => ['form_id' => 'kzc_form'],
				'depends_on' => ['kzc_req']],
			'kzc_form' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['org_id', 'tpl_id', 'created_at'],
				'fk' => ['org_id' => 'kzc_org', 'tpl_id' => 'kzc_form_tpl'],
				'fk_late' => ['req_item_id' => 'kzc_req_item'],
				'depends_on' => ['kzc_org', 'kzc_form_tpl']],
			'kzc_form_ans' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['form_id', 'field_key'], 'pk' => ['form_id', 'field_key'],
				'fk' => ['form_id' => 'kzc_form'], 'depends_on' => ['kzc_form']],

			// ══ per-actor state — only for actors on both instances ══
			'kzc_notify' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['identity_id', 'org_id'], 'pk' => ['identity_id', 'org_id'],
				'fk' => ['identity_id' => 'kzc_identity', 'org_id' => 'kzc_org'],
				'depends_on' => ['kzc_identity', 'kzc_org']],
			'kzc_read' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['conv_id', 'actor'], 'pk' => ['conv_id', 'actor'],
				'fk' => ['conv_id' => 'kzc_conv'], 'depends_on' => ['kzc_conv']],

			// ══ internal / derived / log — NEVER transferred ══
			// Sessions, OTPs and device tokens are instance-bound bearer
			// credentials — never transferable (token_hash is a secret too).
			'kzc_session' => ['class' => 'internal'],
			'kzc_device' => ['class' => 'internal'],
			'kzc_otp' => ['class' => 'internal'],
			// Intake + upload-link rows carry bearer token_hash credentials —
			// a link minted on the source is meaningless and unsafe elsewhere.
			'kzc_intake' => ['class' => 'internal'],
			'kzc_intake_hold' => ['class' => 'internal'],
			'kzc_ulink' => ['class' => 'internal'],
			// Upload metadata rows point at Files blobs via file_id (NOT NULL
			// — env cannot NULL it): a verbatim id would address a DIFFERENT
			// file on the target (wrong-file class) → table stays internal.
			'kzc_upload' => ['class' => 'internal'],
			'kzc_upload_chunk' => ['class' => 'internal'],
			'kzc_req_file' => ['class' => 'internal'],
			// Approvals hash-bind to a Files file_id (NOT NULL) — same
			// env-impossible constraint as kzc_upload.
			'kzc_approval' => ['class' => 'internal'],
			// Outbox rows hold encrypted mail payloads + per-instance state.
			'kzc_mail_outbox' => ['class' => 'internal'],
			'kzc_job_cursor' => ['class' => 'internal'],
			// License state + seats are bound to this instance's id and the
			// vendor signature — never transferable.
			'kzc_license_state' => ['class' => 'internal'],
			'kzc_mobile_seat' => ['class' => 'internal'],
			// share_id/share_node_id bind live Nextcloud shares — per-instance.
			'kzc_sync' => ['class' => 'internal'],
			'kzc_audit' => ['class' => 'log'],
			// Reminder schedule rows are recomputed by SchedulerJob from
			// kzc_req.reminder_policy — derived, never a copy source.
			'kzc_req_remind' => ['class' => 'derived'],
		]);
	}
}
