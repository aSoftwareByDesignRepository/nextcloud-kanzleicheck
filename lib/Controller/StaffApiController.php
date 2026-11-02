<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Controller;

use OCA\PortalCheck\AppInfo\Application;
use OCA\PortalCheck\Db\ApprovalMapper;
use OCA\PortalCheck\Db\ConversationMapper;
use OCA\PortalCheck\Db\DeviceMapper;
use OCA\PortalCheck\Db\IdentityMapper;
use OCA\PortalCheck\Db\IntakeHoldMapper;
use OCA\PortalCheck\Db\MemberMapper;
use OCA\PortalCheck\Db\MessageMapper;
use OCA\PortalCheck\Db\OrgMapper;
use OCA\PortalCheck\Db\PortalFormMapper;
use OCA\PortalCheck\Db\ReqTemplateMapper;
use OCA\PortalCheck\Db\TaskMapper;
use OCA\PortalCheck\Db\UploadLinkMapper;
use OCA\PortalCheck\Db\UploadMapper;
use OCA\PortalCheck\Exception\NotFoundException;
use OCA\PortalCheck\Exception\ValidationException;
use OCA\PortalCheck\Service\AccessDoorService;
use OCA\PortalCheck\Service\ActorResolver;
use OCA\PortalCheck\Service\ApprovalService;
use OCA\PortalCheck\Service\ChatService;
use OCA\PortalCheck\Service\DiagnosticsService;
use OCA\PortalCheck\Service\FormService;
use OCA\PortalCheck\Service\GdprService;
use OCA\PortalCheck\Service\IdentityService;
use OCA\PortalCheck\Service\ImportService;
use OCA\PortalCheck\Service\IntegrationService;
use OCA\PortalCheck\Service\IntakeService;
use OCA\PortalCheck\Service\LicenseService;
use OCA\PortalCheck\Service\NotifyService;
use OCA\PortalCheck\Service\OrgService;
use OCA\PortalCheck\Service\PresetCatalog;
use OCA\PortalCheck\Service\RequestService;
use OCA\PortalCheck\Service\SettingsService;
use OCA\PortalCheck\Service\TaskService;
use OCA\PortalCheck\Service\UploadLinkService;
use OCA\PortalCheck\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;

/** Staff API (/api/portal/**) — door enforced by StaffAccessMiddleware (->staffUid). */
class StaffApiController extends Controller implements \OCA\PortalCheck\Contracts\StaffContextAware
{
	use ApiTrait;

	public string $staffUid = '';
	public bool $staffIsAdmin = false;

	public function setStaffContext(string $uid, bool $isAppAdmin): void
	{
		$this->staffUid = $uid;
		$this->staffIsAdmin = $isAppAdmin;
	}

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OrgMapper $orgs,
		private readonly OrgService $orgService,
		private readonly IdentityService $identities,
		private readonly IdentityMapper $identityMapper,
		private readonly MemberMapper $members,
		private readonly ChatService $chat,
		private readonly ConversationMapper $convs,
		private readonly MessageMapper $messages,
		private readonly TaskService $tasks,
		private readonly TaskMapper $taskMapper,
		private readonly RequestService $requests,
		private readonly ReqTemplateMapper $tplMapper,
		private readonly ApprovalService $approvals,
		private readonly ApprovalMapper $approvalMapper,
		private readonly UploadService $uploadService,
		private readonly UploadMapper $uploads,
		private readonly UploadLinkService $ulinks,
		private readonly UploadLinkMapper $ulinkMapper,
		private readonly IntakeService $intake,
		private readonly IntakeHoldMapper $intakeHolds,
		private readonly FormService $formService,
		private readonly PortalFormMapper $forms,
		private readonly AccessDoorService $door,
		private readonly LicenseService $license,
		private readonly ImportService $import,
		private readonly IntegrationService $integrations,
		private readonly GdprService $gdpr,
		private readonly DiagnosticsService $diagnostics,
		private readonly NotifyService $notify,
		private readonly PresetCatalog $presets,
		private readonly SettingsService $settings,
		private readonly ActorResolver $actors,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function overview(): JSONResponse
	{
		$orgIds = array_map(fn($o) => (int)$o->getId(), $this->orgs->findAllOrdered());
		$unread = 0;
		$inbox = $this->chat->inboxForStaff($this->staffUid, $orgIds);
		foreach ($inbox as $c) {
			$unread += $c['unread'];
		}
		$newUploads = count($this->uploads->findByState('neu', 500));
		$overdue = 0;
		$reqMapper = new \OCA\PortalCheck\Db\DocRequestMapper($this->db());
		foreach ($orgIds as $oid) {
			foreach ($reqMapper->findByOrg($oid, 'open') as $r) {
				if ($this->requests->overdue($r)) {
					$overdue++;
				}
			}
		}
		$openApprovals = 0;
		foreach ($orgIds as $oid) {
			$openApprovals += count($this->approvalMapper->findByOrg($oid, 'pending', 200));
		}
		return $this->ok(['stats' => ['unread' => $unread, 'newUploads' => $newUploads, 'overdueRequests' => $overdue, 'openApprovals' => $openApprovals], 'queue' => array_slice($inbox, 0, 10)]);
	}

	private function db(): \OCP\IDBConnection
	{
		return \OCP\Server::get(\OCP\IDBConnection::class);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function inbox(): JSONResponse
	{
		$orgIds = array_map(fn($o) => (int)$o->getId(), $this->orgs->findAllOrdered());
		$inbox = $this->chat->inboxForStaff($this->staffUid, $orgIds);
		$names = [];
		foreach ($this->orgs->findAllOrdered() as $o) {
			$names[(int)$o->getId()] = $o->getName();
		}
		foreach ($inbox as &$c) {
			$c['orgName'] = $names[$c['orgId']] ?? '';
			$c['lastAuthorName'] = $c['lastAuthor'] !== null ? $this->actors->displayName($c['lastAuthor']) : null;
		}
		return $this->ok(['conversations' => $inbox]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function messages(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$conv = $this->chat->convForOrg($orgId);
		if ($conv === null) {
			return $this->ok(['messages' => []]);
		}
		$after = $this->strictId($this->request->getParam('after', 0)) ?? 0;
		$msgs = array_map(fn($m) => ['id' => (int)$m->getId(), 'author' => $m->getAuthor(), 'authorName' => $this->actors->displayName($m->getAuthor()), 'kind' => $m->getAuthorKind(), 'body' => $m->getBody(), 'uploadId' => $m->getUploadId(), 'createdAt' => $m->getCreatedAt()->format('c')], $this->messages->listAfter((int)$conv->getId(), $after));
		return $this->ok(['messages' => $msgs, 'state' => $conv->getState()]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function sendMessage(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$m = $this->chat->post($orgId, $this->staffUid, 'staff', (string)$this->request->getParam('body', ''), (string)$this->request->getParam('clientUuid', '') ?: null, $this->strictId($this->request->getParam('uploadId')));
		$this->notify->clientMail($orgId, 'message');
		return $this->ok(['message' => ['id' => (int)$m->getId()]], 201);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function orgs(): JSONResponse
	{
		$out = array_map(fn($o) => ['id' => (int)$o->getId(), 'name' => $o->getName(), 'ref' => $o->getRef(), 'kind' => $o->getKind(), 'state' => $o->getState(), 'appOn' => (bool)$o->getAppOn()], $this->orgs->search((string)$this->request->getParam('q', '')));
		return $this->ok(['orgs' => $out]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function createOrg(): JSONResponse
	{
		$o = $this->orgService->create($this->staffUid, (string)$this->request->getParam('name', ''), $this->request->getParam('ref') !== null ? (string)$this->request->getParam('ref') : null, (string)$this->request->getParam('kind', 'company'));
		return $this->ok(['org' => ['id' => (int)$o->getId()]], 201);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function updateOrg(int $id): JSONResponse
	{
		$o = $this->orgService->update($this->staffUid, $id, $this->request->getParams());
		if (isset($this->request->getParams()['appOn'])) {
			$o = $this->orgService->setAppEnabled($this->staffUid, $id, $this->request->getParams()['appOn'] === true || $this->request->getParams()['appOn'] === '1');
		}
		return $this->ok(['org' => ['id' => (int)$o->getId(), 'appOn' => (bool)$o->getAppOn()]]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function board(): JSONResponse
	{
		$filters = ['orgId' => $this->strictId($this->request->getParam('orgId')), 'priority' => $this->request->getParam('priority') ?: null, 'due' => $this->request->getParam('due') ?: null, 'assignee' => $this->request->getParam('mine') === '1' ? $this->staffUid : ($this->request->getParam('assignee') ?: null)];
		$tasks = $this->taskMapper->boardQuery(array_filter($filters));
		$p = $this->presets->get($this->settings->get('preset'));
		$cols = [];
		foreach (TaskService::STATUSES as $s) {
			$cols[$s] = ['label' => $p['board'][$s] ?? $s, 'cards' => []];
		}
		$orgNames = [];
		foreach ($this->orgs->findAllOrdered() as $o) {
			$orgNames[(int)$o->getId()] = $o->getName();
		}
		$today = new \DateTimeImmutable('today');
		foreach ($tasks as $t) {
			$cols[$t->getStatus()]['cards'][] = ['id' => (int)$t->getId(), 'title' => $t->getTitle(), 'orgId' => (int)$t->getOrgId(), 'orgName' => $orgNames[(int)$t->getOrgId()] ?? '', 'priority' => $t->getPriority(), 'dueDate' => $t->getDueDate()?->format('Y-m-d'), 'overdue' => $t->getDueDate() !== null && $t->getStatus() !== 'done' && $t->getDueDate() < $today, 'revision' => (int)$t->getRevision(), 'visibility' => $t->getPortalVisibility()];
		}
		return $this->ok(['columns' => $cols, 'counts' => array_map(fn($c) => count($c['cards']), $cols)]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function createTask(): JSONResponse
	{
		$t = $this->tasks->create($this->staffUid, $this->strictId($this->request->getParam('orgId')) ?? 0, $this->request->getParams());
		return $this->ok(['task' => ['id' => (int)$t->getId()]], 201);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function updateTask(int $id): JSONResponse
	{
		$t = $this->tasks->update($this->staffUid, $id, $this->request->getParams(), (int)$this->request->getParam('revision', 0));
		return $this->ok(['task' => ['id' => (int)$t->getId(), 'revision' => (int)$t->getRevision()]]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function moveTask(int $id): JSONResponse
	{
		$t = $this->tasks->move($this->staffUid, $id, (string)$this->request->getParam('status', ''), (int)$this->request->getParam('revision', 0));
		return $this->ok(['task' => ['id' => (int)$t->getId(), 'revision' => (int)$t->getRevision()]]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function requestsList(): JSONResponse
	{
		$out = [];
		$orgId = $this->strictId($this->request->getParam('orgId'));
		$orgs = $orgId !== null ? [$orgId] : array_map(fn($o) => (int)$o->getId(), $this->orgs->findAllOrdered());
		$reqMapper = new \OCA\PortalCheck\Db\DocRequestMapper($this->db());
		$itemMapper = new \OCA\PortalCheck\Db\ReqItemMapper($this->db());
		foreach ($orgs as $oid) {
			foreach ($reqMapper->findByOrg($oid, (string)$this->request->getParam('state', '')) as $r) {
				$counts = $itemMapper->countsByReq([(int)$r->getId()]);
				$out[] = ['id' => (int)$r->getId(), 'orgId' => $oid, 'title' => $r->getTitle(), 'state' => $r->getState(), 'deadline' => $r->getDeadline()?->format('Y-m-d'), 'overdue' => $this->requests->overdue($r), 'items' => $counts[(int)$r->getId()] ?? ['total' => 0, 'accepted' => 0]];
			}
		}
		return $this->ok(['requests' => $out]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function createRequest(): JSONResponse
	{
		$orgId = $this->strictId($this->request->getParam('orgId')) ?? 0;
		$this->org($orgId);
		$r = $this->requests->create($this->staffUid, $orgId, $this->request->getParams());
		return $this->ok(['request' => ['id' => (int)$r->getId()]], 201);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function diagnostics(): JSONResponse
	{
		$this->requireAdmin();
		return $this->ok($this->diagnostics->snapshot());
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function applyLicense(): JSONResponse
	{
		$this->requireAdmin();
		$s = $this->license->apply((string)$this->request->getParam('key', ''), $this->staffUid);
		return $this->ok(['applied' => true, 'portalClients' => (int)$s->getPortalClients(), 'mobileSeats' => (int)$s->getMobileSeats()]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function importCsv(): JSONResponse
	{
		$this->requireAdmin();
		$csv = (string)$this->request->getParam('csv', '');
		$dry = $this->request->getParam('dryRun', '1') !== '0';
		$rows = ImportService::parse($csv);
		if ($dry) {
			return $this->ok(['rows' => $this->import->plan($rows, $this->orgService, $this->identities), 'dryRun' => true]);
		}
		$applied = 0;
		foreach ($rows as $r) {
			$orgName = trim((string)($r['org_name'] ?? ''));
			$email = IdentityService::normalizeEmail((string)($r['email'] ?? ''));
			if ($orgName === '' || !IdentityService::isValidEmail($email)) {
				continue;
			}
			$ref = trim((string)($r['org_ref'] ?? ''));
			$org = $ref !== '' ? $this->orgs->findByRef($ref) : null;
			if ($org === null) {
				$org = $this->orgService->create($this->staffUid, $orgName, $ref !== '' ? $ref : null);
			}
			$identity = $this->identities->upsert($email, (string)($r['display_name'] ?? $email));
			$this->identities->addMembership((int)$identity->getId(), (int)$org->getId(), $this->staffUid);
			$applied++;
		}
		return $this->ok(['applied' => $applied]);
	}

	// ── settings & door ──────────────────────────────────────────────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function getSettings(): JSONResponse
	{
		$this->requireAdmin();
		return $this->ok([
			'settings' => [
				'preset' => $this->settings->get('preset'),
				'providerLabel' => $this->settings->get('provider_label'),
				'uploadMaxMb' => $this->settings->getInt('upload_max_mb', 100),
				'pollMs' => $this->settings->getInt('poll_ms', 15000),
				'otpTtlMin' => $this->settings->getInt('otp_ttl_min', 10),
				'sessDays' => $this->settings->getInt('sess_days', 30),
				'auditKeepDays' => $this->settings->getInt('audit_keep_days', 1095),
				'docsRootId' => $this->settings->get('docs_root_id'),
				'inbound' => [
					'enabled' => $this->settings->getBool('inbound_enabled'),
					'host' => $this->settings->get('inbound_host'),
					'port' => $this->settings->getInt('inbound_port', 993),
					'user' => $this->settings->get('inbound_user'),
					'local' => $this->settings->get('inbound_local'),
					'domain' => $this->settings->get('inbound_domain'),
				],
			],
			'door' => [
				'restricted' => $this->settings->getBool('door_restricted'),
				'platformGroups' => $this->door->platformRestrictedGroups(),
				'allowedGroups' => $this->settings->getList('allowed_groups'),
				'allowedUsers' => $this->settings->getList('allowed_users'),
				'appAdmins' => $this->settings->getList('app_admins'),
			],
			'presets' => PresetCatalog::IDS,
		]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function saveSettings(): JSONResponse
	{
		$this->requireAdmin();
		$p = $this->request->getParams();
		if (isset($p['preset'])) {
			if (!in_array($p['preset'], PresetCatalog::IDS, true)) {
				throw new ValidationException('VALIDATION_FAILED', ['preset' => 'invalid']);
			}
			$this->settings->set('preset', (string)$p['preset']);
		}
		if (isset($p['providerLabel'])) {
			$this->settings->set('provider_label', mb_substr(trim((string)$p['providerLabel']), 0, 120));
		}
		foreach (['uploadMaxMb' => 'upload_max_mb', 'pollMs' => 'poll_ms', 'otpTtlMin' => 'otp_ttl_min', 'sessDays' => 'sess_days', 'auditKeepDays' => 'audit_keep_days'] as $in => $key) {
			if (isset($p[$in])) {
				$this->settings->set($key, (string)(int)$p[$in]);
			}
		}
		if (isset($p['docsRootId'])) {
			$this->settings->set('docs_root_id', (string)(int)$p['docsRootId']);
		}
		if (isset($p['inbound']) && is_array($p['inbound'])) {
			$in = $p['inbound'];
			$this->settings->setBool('inbound_enabled', !empty($in['enabled']) && $in['enabled'] !== '0' && $in['enabled'] !== 'false');
			foreach (['host' => 'inbound_host', 'user' => 'inbound_user', 'local' => 'inbound_local', 'domain' => 'inbound_domain'] as $k => $key) {
				if (isset($in[$k])) {
					$this->settings->set($key, mb_substr(trim((string)$in[$k]), 0, 190));
				}
			}
			if (isset($in['port'])) {
				$this->settings->set('inbound_port', (string)max(1, min(65535, (int)$in['port'])));
			}
			if (isset($in['pass']) && $in['pass'] !== '') {
				$this->settings->set('inbound_pass_enc', (string)$in['pass']); // stored via crypto upstream
			}
		}
		return $this->ok(['saved' => true]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function saveDoor(): JSONResponse
	{
		$this->requireAdmin();
		$p = $this->request->getParams();
		$restricted = !empty($p['restricted']) && $p['restricted'] !== '0' && $p['restricted'] !== 'false';
		$res = $this->door->saveDoor(
			$restricted,
			is_array($p['groups'] ?? null) ? $p['groups'] : [],
			is_array($p['users'] ?? null) ? $p['users'] : [],
			is_array($p['appAdmins'] ?? null) ? $p['appAdmins'] : [],
		);
		if (!$res['ok']) {
			throw new ValidationException('VALIDATION_FAILED', [$res['error'] => 'unknown']);
		}
		return $this->ok(['saved' => true]);
	}

	// ── identities & memberships ─────────────────────────────────────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function identities(): JSONResponse
	{
		$q = (string)$this->request->getParam('q', '');
		$out = array_map(fn($i) => ['id' => (int)$i->getId(), 'email' => $i->getEmail(), 'displayName' => $i->getDisplayName(), 'status' => $i->getStatus()], $this->identityMapper->search($q));
		return $this->ok(['identities' => $out]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function orgDetail(int $id): JSONResponse
	{
		$o = $this->orgService->mustGet($id);
		$members = array_map(function ($m) {
			$i = $this->identityMapper->find((int)$m->getIdentityId());
			return ['identityId' => (int)$m->getIdentityId(), 'role' => $m->getRole(), 'email' => $i?->getEmail(), 'displayName' => $i?->getDisplayName(), 'status' => $i?->getStatus()];
		}, $this->members->findByOrg($id));
		return $this->ok(['org' => [
			'id' => $id, 'name' => $o->getName(), 'ref' => $o->getRef(), 'kind' => $o->getKind(),
			'state' => $o->getState(), 'appOn' => (bool)$o->getAppOn(), 'bookUrl' => $o->getBookUrl(),
		], 'members' => $members, 'intake' => ['enabled' => true, 'address' => $this->intake->addressFor($id)],
		'ulinks' => array_values(array_filter(array_map(fn($l) => (int)$l->getOrgId() === $id ? ['id' => (int)$l->getId(), 'label' => $l->getLabel(), 'expiresAt' => $l->getExpiresAt()->format('c'), 'usedFiles' => (int)$l->getUsedFiles(), 'maxFiles' => (int)$l->getMaxFiles(), 'revoked' => $l->getRevokedAt() !== null] : null, $this->ulinkMapper->findAllOrdered('id', 'DESC')))),
		'invoices' => $o->getPcCustId() !== null ? $this->integrations->invoicesForOrg((int)$o->getPcCustId()) : [],
		'invoiceAvailable' => $this->integrations->invoiceAvailable() && $o->getPcCustId() !== null]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function addMember(int $id): JSONResponse
	{
		$this->org($id);
		$email = (string)$this->request->getParam('email', '');
		$name = (string)$this->request->getParam('displayName', '');
		$identity = $this->identities->upsert($email, $name !== '' ? $name : $email);
		$this->identities->addMembership((int)$identity->getId(), $id, $this->staffUid);
		return $this->ok(['member' => ['identityId' => (int)$identity->getId()]], 201);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function removeMember(int $id, int $identityId): JSONResponse
	{
		$this->org($id);
		if (!$this->members->deleteByIdentityAndOrg($identityId, $id)) {
			throw new NotFoundException();
		}
		return new JSONResponse(null, 204);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function offboardOrg(int $id): JSONResponse
	{
		$this->orgService->offboard($this->staffUid, $id);
		$this->chat->archiveForOrg($id);
		return new JSONResponse(null, 204);
	}

	// ── uploads queue ────────────────────────────────────────────────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function uploads(): JSONResponse
	{
		$state = (string)$this->request->getParam('state', 'neu');
		$orgId = $this->strictId($this->request->getParam('orgId'));
		$rows = $this->uploads->findByState($state, 500, $orgId);
		$orgNames = [];
		foreach ($this->orgs->findAllOrdered() as $o) {
			$orgNames[(int)$o->getId()] = $o->getName();
		}
		$out = array_map(fn($u) => [
			'id' => (int)$u->getId(), 'orgId' => (int)$u->getOrgId(), 'orgName' => $orgNames[(int)$u->getOrgId()] ?? '',
			'fileName' => $u->getFileName(), 'size' => (int)$u->getSizeBytes(), 'source' => $u->getSource(),
			'uploader' => $this->actors->displayName($u->getUploader()), 'state' => $u->getState(),
			'duplicate' => (bool)$u->getIsDuplicate(), 'createdAt' => $u->getCreatedAt()->format('c'), 'note' => $u->getNote(),
		], $rows);
		return $this->ok(['uploads' => $out]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function reviewUpload(int $id): JSONResponse
	{
		$u = $this->uploadService->review($id, $this->staffUid, (string)$this->request->getParam('state', ''));
		return $this->ok(['upload' => ['id' => (int)$u->getId(), 'state' => $u->getState()]]);
	}

	// ── upload links ─────────────────────────────────────────────────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function createUlink(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$res = $this->ulinks->create(
			$this->staffUid, $orgId,
			(string)$this->request->getParam('label', ''),
			(int)$this->request->getParam('expiryDays', 7),
			(int)$this->request->getParam('maxFiles', 20),
			(int)$this->request->getParam('maxBytes', 100 * 1024 * 1024),
			$this->request->getParam('showOrg') === true || $this->request->getParam('showOrg') === '1',
		);
		return $this->ok(['link' => ['id' => (int)$res['link']->getId(), 'url' => '/apps/portalcheck/portal/u/' . $res['token']]], 201);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function revokeUlink(int $id): JSONResponse
	{
		$l = $this->ulinkMapper->find($id);
		if ($l === null) {
			throw new NotFoundException();
		}
		$this->ulinks->revoke($id, (int)$l->getOrgId());
		return new JSONResponse(null, 204);
	}

	// ── intake holds ─────────────────────────────────────────────────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function intakeHolds(): JSONResponse
	{
		$orgId = $this->strictId($this->request->getParam('orgId')) ?? 0;
		$out = array_map(fn($h) => ['id' => (int)$h->getId(), 'orgId' => (int)$h->getOrgId(), 'receivedAt' => $h->getReceivedAt()->format('c'), 'state' => $h->getState()], $this->intakeHolds->findHeld($orgId));
		return $this->ok(['holds' => $out]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function decideHold(int $id): JSONResponse
	{
		$h = $this->intakeHolds->find($id);
		if ($h === null || $h->getState() !== 'held') {
			throw new NotFoundException();
		}
		$action = (string)$this->request->getParam('action', '');
		if (!in_array($action, ['accept', 'reject'], true)) {
			throw new ValidationException('VALIDATION_FAILED', ['action' => 'invalid']);
		}
		$h->setState($action === 'accept' ? 'accepted' : 'rejected');
		$this->intakeHolds->update($h);
		return $this->ok(['state' => $h->getState()]);
	}

	// ── approvals (staff side) ───────────────────────────────────────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function approvals(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$out = array_map(function ($a) {
			$this->approvals->checkVoid($a);
			return ['id' => (int)$a->getId(), 'fileName' => $a->getFileName(), 'state' => $a->getState(),
				'deadline' => $a->getDeadline()?->format('Y-m-d'), 'requestedAt' => $a->getRequestedAt()->format('c'),
				'decidedBy' => $a->getDecidedBy() !== null ? $this->actors->displayName($a->getDecidedBy()) : null,
				'decidedAt' => $a->getDecidedAt()?->format('c'), 'sha' => $a->getFileSha256()];
		}, $this->approvalMapper->findByOrg($orgId));
		return $this->ok(['approvals' => $out]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function requestApproval(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$a = $this->approvals->request($this->staffUid, $orgId,
			$this->strictId($this->request->getParam('fileId')) ?? 0,
			$this->request->getParam('deadline') !== null ? (string)$this->request->getParam('deadline') : null,
			$this->request->getParam('note') !== null ? (string)$this->request->getParam('note') : null,
			$this->strictId($this->request->getParam('requiredIdentityId')));
		return $this->ok(['approval' => ['id' => (int)$a->getId()]], 201);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function withdrawApproval(int $id): JSONResponse
	{
		$a = $this->approvals->withdraw($this->staffUid, $id);
		return $this->ok(['state' => $a->getState()]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function approvalProof(int $id): JSONResponse
	{
		$a = $this->approvalMapper->find($id);
		if ($a === null) {
			throw new NotFoundException();
		}
		return $this->ok(['proof' => [
			'id' => (int)$a->getId(), 'fileName' => $a->getFileName(), 'sha256' => $a->getFileSha256(),
			'state' => $a->getState(), 'requestedBy' => $a->getRequestedBy(), 'requestedAt' => $a->getRequestedAt()->format('c'),
			'decidedBy' => $a->getDecidedBy(), 'decidedAt' => $a->getDecidedAt()?->format('c'),
			'method' => $a->getConfirmMethod(), 'reason' => $a->getReason(),
		]]);
	}

	// ── request item review / close ──────────────────────────────────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function reviewReqItem(int $id, int $itemId): JSONResponse
	{
		$i = $this->requests->reviewItem($this->staffUid, $id, $itemId,
			$this->request->getParam('accept') === true || $this->request->getParam('accept') === '1' || $this->request->getParam('accept') === 'true',
			$this->request->getParam('reason') !== null ? (string)$this->request->getParam('reason') : null);
		return $this->ok(['itemState' => $i->getState()]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function closeRequest(int $id): JSONResponse
	{
		$this->requests->closeRequest($id, 'closed', $this->staffUid);
		return new JSONResponse(null, 204);
	}

	// ── GDPR ─────────────────────────────────────────────────────────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function exportIdentity(int $id): JSONResponse
	{
		$this->requireAdmin();
		return $this->ok($this->gdpr->exportIdentity($id));
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function eraseIdentity(int $id): JSONResponse
	{
		$this->requireAdmin();
		$this->gdpr->erase($this->staffUid, $id, $this->request->getParam('deleteMessages') === true || $this->request->getParam('deleteMessages') === '1');
		return new JSONResponse(null, 204);
	}

	// ── license state + intake toggles ───────────────────────────────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function licenseState(): JSONResponse
	{
		$this->requireAdmin();
		$s = $this->license->state();
		return $this->ok(['license' => [
			'status' => $this->license->status(),
			'customerId' => $s?->getCustomerId(), 'validUntil' => $s?->getValidUntil(),
			'mobileSeats' => $s?->getMobileSeats(), 'portalClients' => $s?->getPortalClients(),
			'usedPortalClients' => $this->orgs->countAppEnabled(),
		]]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function setIntake(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$enabled = $this->request->getParam('enabled');
		$this->intake->setEnabled($this->staffUid, $orgId, $enabled === true || $enabled === '1' || $enabled === 'true');
		return $this->ok(['address' => $this->intake->addressFor($orgId)]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function rotateIntake(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$this->intake->rotate($this->staffUid, $orgId);
		return $this->ok(['address' => $this->intake->addressFor($orgId)]);
	}

	// ── request templates ────────────────────────────────────────────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function templates(): JSONResponse
	{
		$out = array_map(fn($t) => [
			'id' => (int)$t->getId(), 'name' => $t->getName(), 'preset' => $t->getPreset(),
			'items' => json_decode((string)$t->getItemsJson(), true) ?: [],
			'deadlineOffsetDays' => $t->getDeadlineOffsetDays(), 'recurrence' => $t->getRecurrence(),
			'recurrenceDay' => $t->getRecurrenceDay(), 'recurrenceMonth' => $t->getRecurrenceMonth(),
			'reminderPolicy' => $t->getReminderPolicy(), 'active' => (bool)$t->getActive(),
			'orgIds' => $this->tplOrgMapper()->orgIdsFor((int)$t->getId()),
		], $this->tplMapper->findActive());
		return $this->ok(['templates' => $out]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function createTemplate(): JSONResponse
	{
		$this->requireAdmin();
		$p = $this->request->getParams();
		$name = trim((string)($p['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 190) {
			throw new ValidationException('VALIDATION_FAILED', ['name' => 'required']);
		}
		$items = $p['items'] ?? [];
		if (!is_array($items) || count($items) < 1 || count($items) > \OCA\PortalCheck\Service\RequestService::MAX_ITEMS) {
			throw new ValidationException('VALIDATION_FAILED', ['items' => 'count']);
		}
		$norm = [];
		foreach ($items as $i => $it) {
			$label = trim((string)($it['label'] ?? ''));
			if ($label === '' || mb_strlen($label) > 190) {
				throw new ValidationException('VALIDATION_FAILED', ['items' => 'label:' . $i]);
			}
			$kind = in_array($it['kind'] ?? '', \OCA\PortalCheck\Service\RequestService::ITEM_KINDS, true) ? $it['kind'] : 'upload';
			$norm[] = ['label' => $label, 'hint' => isset($it['hint']) ? mb_substr((string)$it['hint'], 0, 500) : null, 'kind' => $kind, 'formId' => $it['formId'] ?? null];
		}
		$rec = in_array($p['recurrence'] ?? '', ['none', 'monthly', 'quarterly', 'yearly'], true) ? $p['recurrence'] : 'none';
		$tpl = new \OCA\PortalCheck\Db\ReqTemplate();
		$tpl->setName($name);
		$tpl->setPreset($p['preset'] ?? null);
		$tpl->setItemsJson(json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
		$tpl->setDeadlineOffsetDays(isset($p['deadlineOffsetDays']) ? max(0, min(365, (int)$p['deadlineOffsetDays'])) : null);
		$tpl->setRecurrence($rec);
		$tpl->setRecurrenceDay(isset($p['recurrenceDay']) ? max(1, min(31, (int)$p['recurrenceDay'])) : null);
		$tpl->setRecurrenceMonth(isset($p['recurrenceMonth']) ? max(1, min(12, (int)$p['recurrenceMonth'])) : null);
		$tpl->setReminderPolicy(mb_substr((string)($p['reminderPolicy'] ?? '-3,0,+3x5'), 0, 64));
		$tpl->setActive(true);
		$tpl->setCreatedBy($this->staffUid);
		$tpl->setCreatedAt(new \DateTimeImmutable());
		$tpl = $this->tplMapper->insert($tpl);
		if (isset($p['orgIds']) && is_array($p['orgIds'])) {
			$this->tplOrgMapper()->setOrgs((int)$tpl->getId(), array_map('intval', $p['orgIds']));
		}
		return $this->ok(['template' => ['id' => (int)$tpl->getId()]], 201);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function applyPresetTemplates(): JSONResponse
	{
		$this->requireAdmin();
		$preset = $this->settings->get('preset');
		$created = 0;
		foreach ($this->presets->templateSet($preset) as $def) {
			$tpl = new \OCA\PortalCheck\Db\ReqTemplate();
			$tpl->setName($def['name']);
			$tpl->setPreset($preset);
			$tpl->setItemsJson(json_encode($def['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
			$tpl->setDeadlineOffsetDays($def['offsetDays']);
			$tpl->setRecurrence($def['recurrence']);
			$tpl->setRecurrenceDay($def['recurrenceDay']);
			$tpl->setReminderPolicy($this->presets->defaultReminderPolicy());
			$tpl->setActive(true);
			$tpl->setCreatedBy($this->staffUid);
			$tpl->setCreatedAt(new \DateTimeImmutable());
			$this->tplMapper->insert($tpl);
			$created++;
		}
		return $this->ok(['created' => $created]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function setTemplateOrgs(int $id): JSONResponse
	{
		if ($this->tplMapper->find($id) === null) {
			throw new NotFoundException();
		}
		$orgIds = $this->request->getParam('orgIds') ?? [];
		$this->tplOrgMapper()->setOrgs($id, is_array($orgIds) ? array_map('intval', $orgIds) : []);
		return new JSONResponse(null, 204);
	}

	// ── responsible staff contacts ───────────────────────────────────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function setContacts(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$uids = $this->request->getParam('userIds') ?? [];
		if (!is_array($uids)) {
			throw new ValidationException('VALIDATION_FAILED', ['userIds' => 'invalid']);
		}
		foreach ($uids as $uid) {
			if (!$this->door->isStaff((string)$uid)) {
				throw new ValidationException('VALIDATION_FAILED', ['userIds' => 'not_staff:' . $uid]);
			}
		}
		$this->contactMapper()->setForOrg($orgId, array_map('strval', array_slice($uids, 0, 50)));
		return new JSONResponse(null, 204);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function contacts(int $orgId): JSONResponse
	{
		$this->org($orgId);
		return $this->ok(['contacts' => $this->contactMapper()->findForOrg($orgId)]);
	}

	private function tplOrgMapper(): \OCA\PortalCheck\Db\ReqTplOrgMapper
	{
		return \OCP\Server::get(\OCA\PortalCheck\Db\ReqTplOrgMapper::class);
	}

	private function contactMapper(): \OCA\PortalCheck\Db\ContactMapper
	{
		return \OCP\Server::get(\OCA\PortalCheck\Db\ContactMapper::class);
	}

	private function org(int $id): void
	{
		if ($this->orgs->find($id) === null) {
			throw new NotFoundException();
		}
	}

	private function requireAdmin(): void
	{
		if (!$this->staffIsAdmin) {
			throw new \OCA\PortalCheck\Exception\ForbiddenException();
		}
	}
}
