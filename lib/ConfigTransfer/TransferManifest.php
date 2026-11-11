<?php

declare(strict_types=1);

/**
 * PortalCheck transfer manifest — every appconfig key and every ptl_* table
 * classified exactly once
 * (gate: scripts/check-config-transfer-manifest.py).
 *
 * The 'transfer' settings section lives in PageController::settings() —
 * PortalCheck has no *SectionCatalog; the settings surface is the staff SPA
 * and /settings/transfer renders the synced part as a dedicated page.
 */

namespace OCA\PortalCheck\ConfigTransfer;

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
			'ptl_org' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['ref', 'name', 'created_by'],
				'env_columns' => ['folder_id', 'folder_path', 'pc_cust_id']],
			'ptl_req_tpl' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name']],
			'ptl_req_tpl_org' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['template_id', 'org_id'], 'pk' => ['template_id', 'org_id'],
				'fk' => ['template_id' => 'ptl_req_tpl', 'org_id' => 'ptl_org'],
				'depends_on' => ['ptl_req_tpl', 'ptl_org']],
			'ptl_form_tpl' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name']],

			// ══ client identities + org-scoped business data ══
			// nc_uid/nc_uid_origin/nc_uid_mismatch carry verbatim: a uid that
			// does not exist on the target is a bindable identity, not a
			// lost client — SyncReconcileJob already owns the durable
			// mismatch flag (US-B spec) that heals the link.
			'ptl_identity' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['email']],
			'ptl_member' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['identity_id', 'org_id'], 'pk' => ['identity_id', 'org_id'],
				'fk' => ['identity_id' => 'ptl_identity', 'org_id' => 'ptl_org'],
				'depends_on' => ['ptl_identity', 'ptl_org']],
			// Staff contact persons point at NC uids — a contact for a uid
			// that does not exist on the target is meaningless → skip row.
			'ptl_contact' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['org_id', 'user_id'], 'pk' => ['org_id', 'user_id'],
				'fk' => ['org_id' => 'ptl_org'], 'depends_on' => ['ptl_org'],
				'uid_ref' => ['user_id']],
			'ptl_conv' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['org_id'],
				'fk' => ['org_id' => 'ptl_org'], 'depends_on' => ['ptl_org']],
			// author is a mixed actor string (staff uid / client identity /
			// 'system') — never a uid_ref; verbatim like every actor column.
			// file_id/upload_id reference this instance's Files and the
			// internal ptl_upload table — env (NULL on insert).
			'ptl_message' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['conv_id', 'author', 'client_uuid', 'created_at'],
				'fk' => ['conv_id' => 'ptl_conv'], 'depends_on' => ['ptl_conv'],
				'env_columns' => ['file_id', 'upload_id']],
			'ptl_task' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['org_id', 'title', 'created_at'],
				'fk' => ['org_id' => 'ptl_org'], 'depends_on' => ['ptl_org']],
			'ptl_req' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['template_id', 'org_id', 'period_key', 'title', 'created_at'],
				'fk' => ['org_id' => 'ptl_org', 'template_id' => 'ptl_req_tpl', 'task_id' => 'ptl_task'],
				'depends_on' => ['ptl_org', 'ptl_req_tpl', 'ptl_task']],
			// form_id ↔ ptl_form.req_item_id is a cyclic pair — both resolve
			// post-load (fk_late); an unresolved late ref NULLs, never skips.
			'ptl_req_item' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['req_id', 'position', 'label'],
				'fk' => ['req_id' => 'ptl_req'],
				'fk_late' => ['form_id' => 'ptl_form'],
				'depends_on' => ['ptl_req']],
			'ptl_form' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['org_id', 'tpl_id', 'created_at'],
				'fk' => ['org_id' => 'ptl_org', 'tpl_id' => 'ptl_form_tpl'],
				'fk_late' => ['req_item_id' => 'ptl_req_item'],
				'depends_on' => ['ptl_org', 'ptl_form_tpl']],
			'ptl_form_ans' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['form_id', 'field_key'], 'pk' => ['form_id', 'field_key'],
				'fk' => ['form_id' => 'ptl_form'], 'depends_on' => ['ptl_form']],

			// ══ per-actor state — only for actors on both instances ══
			'ptl_notify' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['identity_id', 'org_id'], 'pk' => ['identity_id', 'org_id'],
				'fk' => ['identity_id' => 'ptl_identity', 'org_id' => 'ptl_org'],
				'depends_on' => ['ptl_identity', 'ptl_org']],
			'ptl_read' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['conv_id', 'actor'], 'pk' => ['conv_id', 'actor'],
				'fk' => ['conv_id' => 'ptl_conv'], 'depends_on' => ['ptl_conv']],

			// ══ internal / derived / log — NEVER transferred ══
			// Sessions, OTPs and device tokens are instance-bound bearer
			// credentials — never transferable (token_hash is a secret too).
			'ptl_session' => ['class' => 'internal'],
			'ptl_device' => ['class' => 'internal'],
			'ptl_otp' => ['class' => 'internal'],
			// Intake + upload-link rows carry bearer token_hash credentials —
			// a link minted on the source is meaningless and unsafe elsewhere.
			'ptl_intake' => ['class' => 'internal'],
			'ptl_intake_hold' => ['class' => 'internal'],
			'ptl_ulink' => ['class' => 'internal'],
			// Upload metadata rows point at Files blobs via file_id (NOT NULL
			// — env cannot NULL it): a verbatim id would address a DIFFERENT
			// file on the target (wrong-file class) → table stays internal.
			'ptl_upload' => ['class' => 'internal'],
			'ptl_upload_chunk' => ['class' => 'internal'],
			'ptl_req_file' => ['class' => 'internal'],
			// Approvals hash-bind to a Files file_id (NOT NULL) — same
			// env-impossible constraint as ptl_upload.
			'ptl_approval' => ['class' => 'internal'],
			// Outbox rows hold encrypted mail payloads + per-instance state.
			'ptl_mail_outbox' => ['class' => 'internal'],
			'ptl_job_cursor' => ['class' => 'internal'],
			// License state + seats are bound to this instance's id and the
			// vendor signature — never transferable.
			'ptl_license_state' => ['class' => 'internal'],
			'ptl_mobile_seat' => ['class' => 'internal'],
			// share_id/share_node_id bind live Nextcloud shares — per-instance.
			'ptl_sync' => ['class' => 'internal'],
			'ptl_audit' => ['class' => 'log'],
			// Reminder schedule rows are recomputed by SchedulerJob from
			// ptl_req.reminder_policy — derived, never a copy source.
			'ptl_req_remind' => ['class' => 'derived'],
		]);
	}
}
