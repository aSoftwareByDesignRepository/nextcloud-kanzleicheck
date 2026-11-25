<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Controller;

use OCA\KanzleiCheck\AppInfo\Application;
use OCA\KanzleiCheck\Db\ApprovalMapper;
use OCA\KanzleiCheck\Db\ConversationMapper;
use OCA\KanzleiCheck\Db\DeviceMapper;
use OCA\KanzleiCheck\Db\IdentityMapper;
use OCA\KanzleiCheck\Db\IntakeHoldMapper;
use OCA\KanzleiCheck\Db\MemberMapper;
use OCA\KanzleiCheck\Db\MessageMapper;
use OCA\KanzleiCheck\Db\OrgMapper;
use OCA\KanzleiCheck\Db\PortalFormMapper;
use OCA\KanzleiCheck\Db\ReqTemplateMapper;
use OCA\KanzleiCheck\Db\TaskMapper;
use OCA\KanzleiCheck\Db\UploadLinkMapper;
use OCA\KanzleiCheck\Db\UploadMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Exception\ValidationException;
use OCA\KanzleiCheck\Service\AccessDoorService;
use OCA\KanzleiCheck\Service\ActorResolver;
use OCA\KanzleiCheck\Service\ApprovalService;
use OCA\KanzleiCheck\Service\ChatService;
use OCA\KanzleiCheck\Service\DiagnosticsService;
use OCA\KanzleiCheck\Service\FormService;
use OCA\KanzleiCheck\Service\GdprService;
use OCA\KanzleiCheck\Service\IdentityService;
use OCA\KanzleiCheck\Service\ImportService;
use OCA\KanzleiCheck\Service\IntegrationService;
use OCA\KanzleiCheck\Service\IntakeService;
use OCA\KanzleiCheck\Service\LicenseService;
use OCA\KanzleiCheck\Service\NotifyService;
use OCA\KanzleiCheck\Service\OrgService;
use OCA\KanzleiCheck\Service\PresetCatalog;
use OCA\KanzleiCheck\Service\RequestService;
use OCA\KanzleiCheck\Service\SettingsService;
use OCA\KanzleiCheck\Service\TaskService;
use OCA\KanzleiCheck\Service\UploadLinkService;
use OCA\KanzleiCheck\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;

/** Staff API (/api/portal/**) — door enforced by StaffAccessMiddleware (->staffUid). */
class StaffApiController extends Controller implements \OCA\KanzleiCheck\Contracts\StaffContextAware
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
		private readonly IURLGenerator $urls,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
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
		$reqMapper = new \OCA\KanzleiCheck\Db\DocRequestMapper($this->db());
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
		return $this->ok(['stats' => ['unread' => $unread, 'newUploads' => $newUploads, 'overdueRequests' => $overdue, 'openApprovals' => $openApprovals], 'queue' => array_slice($this->withOrgNames($inbox), 0, 10)]);
	}

	private function db(): \OCP\IDBConnection
	{
		return \OCP\Server::get(\OCP\IDBConnection::class);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function inbox(): JSONResponse
	{
		$orgIds = array_map(fn($o) => (int)$o->getId(), $this->orgs->findAllOrdered());
		if ($this->request->getParam('mine') === '1') {
			$orgIds = array_values(array_intersect($orgIds, $this->contactMapper()->orgsForUser($this->staffUid)));
		}
		$inbox = $this->chat->inboxForStaff($this->staffUid, $orgIds);
		return $this->ok(['conversations' => $this->withOrgNames($inbox)]);
	}

	private function withOrgNames(array $conversations): array
	{
		$names = [];
		foreach ($this->orgs->findAllOrdered() as $o) {
			$names[(int)$o->getId()] = $o->getName();
		}
		foreach ($conversations as &$c) {
			$c['orgName'] = $names[$c['orgId']] ?? '';
			$c['lastAuthorName'] = $c['lastAuthor'] !== null ? $this->actors->displayName($c['lastAuthor']) : null;
		}
		return $conversations;
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

	/** Mirror of portalApi#markRead / companion#markRead — staff unread
	 *  badges only clear when the read marker advances (monotonic, per uid). */
	#[NoAdminRequired] #[NoCSRFRequired]
	public function markRead(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$conv = $this->convs->findByOrg($orgId);
		$last = $this->strictId($this->request->getParam('lastReadId', 0)) ?? 0;
		if ($conv !== null && $last > 0) {
			$this->chat->markRead((int)$conv->getId(), $this->staffUid, $last);
		}
		return new JSONResponse(null, 204);
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

	/** Task detail for the board card dialog — the board payload stays lean
	 * (500 cards, no note/assignee), the dialog fetches the full row and a
	 * fresh CAS revision on open. Uniform 404 on unknown ids. */
	#[NoAdminRequired] #[NoCSRFRequired]
	public function task(int $id): JSONResponse
	{
		$task = $this->taskMapper->find($id);
		if ($task === null) {
			throw new NotFoundException();
		}
		$p = $this->presets->get($this->settings->get('preset'));
		$org = $this->orgs->find((int)$task->getOrgId());
		$today = new \DateTimeImmutable('today');
		return $this->ok(['task' => [
			'id' => (int)$task->getId(),
			'title' => $task->getTitle(),
			'note' => $task->getNote(),
			'status' => $task->getStatus(),
			'statusLabel' => $p['board'][$task->getStatus()] ?? $task->getStatus(),
			'priority' => $task->getPriority(),
			'dueDate' => $task->getDueDate()?->format('Y-m-d'),
			'overdue' => $task->getDueDate() !== null && $task->getStatus() !== 'done' && $task->getDueDate() < $today,
			'assignee' => $task->getAssignee(),
			'visibility' => $task->getPortalVisibility(),
			'revision' => (int)$task->getRevision(),
			'orgId' => (int)$task->getOrgId(),
			'orgName' => $org?->getName() ?? '',
			'createdBy' => $task->getCreatedBy(),
			'createdAt' => $task->getCreatedAt()->format(\DateTimeInterface::ATOM),
			'updatedAt' => $task->getUpdatedAt()->format(\DateTimeInterface::ATOM),
			'doneAt' => $task->getDoneAt()?->format(\DateTimeInterface::ATOM),
		]]);
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
		if ($this->request->getParam('mine') === '1') {
			$orgs = array_values(array_intersect($orgs, $this->contactMapper()->orgsForUser($this->staffUid)));
		}
		$reqMapper = new \OCA\KanzleiCheck\Db\DocRequestMapper($this->db());
		$itemMapper = new \OCA\KanzleiCheck\Db\ReqItemMapper($this->db());
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
		$sync = $this->syncBindings();
		if ($dry) {
			return $this->ok(['rows' => $this->import->plan($rows, $this->orgService, $this->identities, fn(string $u, string $e): string => $sync->classifyNcUid($u, $e)), 'dryRun' => true]);
		}
		$sendInvites = $this->request->getParam('sendInvitations');
		$sendInvites = $sendInvites === true || $sendInvites === '1' || $sendInvites === 'true';
		$outbox = \OCP\Server::get(\OCA\KanzleiCheck\Service\MailOutboxService::class);
		$applied = 0;
		$syncRows = [];
		$line = 1;
		foreach ($rows as $r) {
			$line++;
			$orgName = trim((string)($r['org_name'] ?? ''));
			$email = IdentityService::normalizeEmail((string)$r['email'] ?? '');
			if ($orgName === '' || !IdentityService::isValidEmail($email)) {
				continue;
			}
			$ref = trim((string)($r['org_ref'] ?? ''));
			$org = $ref !== '' ? $this->orgs->findByRef($ref) : null;
			if ($org === null) {
				$kind = trim((string)($r['kind'] ?? '')) ?: 'company';
				$org = $this->orgService->create($this->staffUid, $orgName, $ref !== '' ? $ref : null, $kind);
			}
			$identity = $this->identities->upsert($email, (string)($r['display_name'] ?? $email));
			$this->identities->addMembership((int)$identity->getId(), (int)$org->getId(), $this->staffUid);
			$ncUid = trim((string)($r['nc_uid'] ?? ''));
			if ($ncUid !== '') {
				// AF-06: re-classify at write time — door/user state may have
				// changed since the dry run; blocked rows are reported, never
				// silently skipped.
				$bucket = $sync->classifyNcUid($ncUid, $email);
				if ($bucket !== 'ok') {
					$syncRows[] = ['line' => $line, 'email' => $email, 'sync' => $bucket];
				} else {
					try {
						$sync->attach($this->staffUid, (int)$identity->getId(), $ncUid);
						$sync->enable($this->staffUid, (int)$org->getId(), (int)$identity->getId());
						$syncRows[] = ['line' => $line, 'email' => $email, 'sync' => 'ok'];
					} catch (\Throwable $e) {
						$syncRows[] = ['line' => $line, 'email' => $email, 'sync' => $e instanceof ApiException ? $e->getErrorCode() : 'sync_failed'];
					}
				}
			}
			if ($sendInvites) {
				$outbox->enqueue($email, 'invite',
					'invite:' . $email . ':' . $org->getId(),
					['provider' => $this->settings->get('provider_label'), 'portalUrl' => $this->urls->linkToRouteAbsolute('kanzleicheck.portal.index')],
					'de');
			}
			$applied++;
		}
		if ($sendInvites) {
			$outbox->drain(20);
		}
		return $this->ok(['applied' => $applied, 'sync' => $syncRows]);
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
				$this->settings->setSecret('inbound_pass_enc', (string)$in['pass']);
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
			if ($res['error'] === 'SYNC_DOOR_BINDINGS_LIVE') {
				// D-B12: opening the door while bindings exist is refused —
				// actionable error naming the fix, never a bare 400.
				throw new ApiException(409, 'SYNC_DOOR_BINDINGS_LIVE', 'active sync bindings exist — revoke them before opening the door');
			}
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
			return ['identityId' => (int)$m->getIdentityId(), 'role' => $m->getRole(), 'email' => $i?->getEmail(), 'displayName' => $i?->getDisplayName(), 'status' => $i?->getStatus(), 'ncUid' => $i?->getNcUid(), 'ncUidMismatch' => (bool)($i?->getNcUidMismatch())];
		}, $this->members->findByOrg($id));
		return $this->ok(['org' => [
			'id' => $id, 'name' => $o->getName(), 'ref' => $o->getRef(), 'kind' => $o->getKind(),
			'state' => $o->getState(), 'appOn' => (bool)$o->getAppOn(), 'bookUrl' => $o->getBookUrl(),
		], 'members' => $members, 'intake' => ['enabled' => true, 'address' => $this->intake->addressFor($id)],
		'ulinks' => array_values(array_filter(array_map(fn($l) => (int)$l->getOrgId() === $id ? ['id' => (int)$l->getId(), 'label' => $l->getLabel(), 'expiresAt' => $l->getExpiresAt()->format('c'), 'usedFiles' => (int)$l->getUsedFiles(), 'maxFiles' => (int)$l->getMaxFiles(), 'revoked' => $l->getRevokedAt() !== null] : null, $this->ulinkMapper->findAllOrdered('id', 'DESC')))),
		'invoices' => $o->getPcCustId() !== null ? $this->integrations->invoicesForOrg((int)$o->getPcCustId()) : [],
		'invoiceAvailable' => $this->integrations->invoiceAvailable() && $o->getPcCustId() !== null,
		'pcCustId' => $o->getPcCustId(),
		'contacts' => $this->contactMapper()->findForOrg($id),
		'sync' => $this->syncBindings()->listForOrg($id),
		'doorRestricted' => $this->door->isRestricted()]);
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
		// MH-15: the member's sync binding is revoked BEFORE the membership
		// row drops — the share must die before the binding loses its context.
		$this->syncBindings()->cascadeMemberRemove($this->staffUid, $identityId, $id);
		if (!$this->members->deleteByIdentityAndOrg($identityId, $id)) {
			throw new NotFoundException();
		}
		return new JSONResponse(null, 204);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function offboardOrg(int $id): JSONResponse
	{
		$this->org($id);
		// US-S06/ZF-03: sweep once before the state flip and once after —
		// a binding enabled mid-cascade is still caught. Failures surface as
		// a partial-offboard warning, never silently.
		$first = $this->syncBindings()->cascadeOffboard($this->staffUid, $id);
		$this->orgService->offboard($this->staffUid, $id);
		$this->chat->archiveForOrg($id);
		$second = $this->syncBindings()->cascadeOffboard($this->staffUid, $id);
		$failed = $first['failed'] + $second['failed'];
		if ($failed > 0) {
			return $this->ok(['offboarded' => true, 'partialWarning' => 'sync_revoke_failed', 'failed' => $failed]);
		}
		return new JSONResponse(null, 204);
	}

	// ── Desktop-Sync (nc-user-binding, staff surface) ────────────────

	/** Bindings of an org with live (rate-capped) verify — door-admitted read. */
	#[NoAdminRequired] #[NoCSRFRequired]
	public function syncList(int $id): JSONResponse
	{
		$this->org($id);
		return $this->ok(['bindings' => $this->syncBindings()->listForOrg($id), 'doorRestricted' => $this->door->isRestricted()]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function syncEnable(int $id): JSONResponse
	{
		$this->requireAdmin();
		$identityId = $this->strictId($this->request->getParam('identityId'));
		if ($identityId === null) {
			throw new ValidationException('VALIDATION_FAILED', ['identityId' => 'required']);
		}
		$b = $this->syncBindings()->enable($this->staffUid, $id, $identityId, (string)$this->request->getParam('perms', 'rw'));
		return $this->ok(['binding' => $this->bindingJson($b)], 201);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function ncBind(int $id): JSONResponse
	{
		$this->requireAdmin();
		$i = $this->syncBindings()->attach(
			$this->staffUid, $id,
			(string)$this->request->getParam('ncUid', ''),
			$this->boolParam('acceptMismatch'),
			$this->boolParam('acceptShared'),
		);
		return $this->ok(['identity' => ['id' => $id, 'ncUid' => $i->getNcUid(), 'ncUidMismatch' => (bool)$i->getNcUidMismatch()]]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function ncUnbind(int $id): JSONResponse
	{
		$this->requireAdmin();
		$this->syncBindings()->unbind($this->staffUid, $id);
		return new JSONResponse(null, 204);
	}

	/** Email-match suggestion + current binding for the setup dialog (D-B21). */
	#[NoAdminRequired] #[NoCSRFRequired]
	public function ncSuggest(int $id): JSONResponse
	{
		$identity = $this->identityMapper->find($id);
		if ($identity === null) {
			throw new NotFoundException();
		}
		return $this->ok([
			'suggest' => $this->syncBindings()->suggestFor($identity),
			'ncUid' => $identity->getNcUid(),
			'ncUidMismatch' => (bool)$identity->getNcUidMismatch(),
			'doorRestricted' => $this->door->isRestricted(),
		]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function syncPerms(int $bid): JSONResponse
	{
		$this->requireAdmin();
		$b = $this->syncBindings()->setPerms($this->staffUid, $bid, (string)$this->request->getParam('perms', ''));
		return $this->ok(['binding' => $this->bindingJson($b)]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function syncRepair(int $bid): JSONResponse
	{
		$this->requireAdmin();
		$b = $this->syncBindings()->repair($this->staffUid, $bid);
		return $this->ok(['binding' => $this->bindingJson($b)]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function syncRevoke(int $bid): JSONResponse
	{
		$this->requireAdmin();
		$this->syncBindings()->revoke($this->staffUid, $bid);
		return new JSONResponse(null, 204);
	}

	/** People-search picker for the bind dialog — directory pick only, no raw uid typing. */
	#[NoAdminRequired] #[NoCSRFRequired]
	public function searchUsers(): JSONResponse
	{
		$q = trim((string)$this->request->getParam('q', ''));
		$out = [];
		if ($q !== '') {
			// search() only matches the user id on some backends — merge with the
			// display-name search so staff are found by name either way.
			$byName = [];
			try {
				$byName = (array)$this->userManager->searchDisplayName($q, 40, 0);
			} catch (\Throwable) {
				$byName = [];
			}
			$seen = [];
			foreach ([(array)$this->userManager->search($q, 40, 0), $byName] as $batch) {
				foreach ($batch as $u) {
					// Staff pickers never offer deactivated accounts — they cannot sign in.
					if (!$u->isEnabled()) {
						continue;
					}
					$uid = (string)$u->getUID();
					if ($uid === '' || isset($seen[$uid])) {
						continue;
					}
					$seen[$uid] = true;
					$out[] = ['uid' => $uid, 'displayName' => $u->getDisplayName(), 'email' => $u->getEMailAddress(), 'staff' => $this->door->isStaff($uid)];
					if (count($out) >= 20) {
						break 2;
					}
				}
			}
		}
		return $this->ok(['users' => $out]);
	}

	/** Group picker for the access door — directory pick only, no raw gid typing. */
	#[NoAdminRequired] #[NoCSRFRequired]
	public function searchGroups(): JSONResponse
	{
		$q = trim((string)$this->request->getParam('q', ''));
		$out = [];
		if ($q !== '') {
			foreach ((array)$this->groupManager->search($q, 20, 0) as $g) {
				$gid = (string)$g->getGID();
				if ($gid === '' || $gid === 'admin') {
					continue;
				}
				$out[] = ['gid' => $gid, 'displayName' => $g->getDisplayName()];
				if (count($out) >= 20) {
					break;
				}
			}
		}
		return $this->ok(['groups' => $out]);
	}

	private function syncBindings(): \OCA\KanzleiCheck\Service\SyncBindingService
	{
		return \OCP\Server::get(\OCA\KanzleiCheck\Service\SyncBindingService::class);
	}

	private function bindingJson(\OCA\KanzleiCheck\Db\SyncBinding $b): array
	{
		return ['bindingId' => (int)$b->getId(), 'identityId' => (int)$b->getIdentityId(), 'orgId' => (int)$b->getOrgId(), 'perms' => $b->getPerms(), 'state' => $b->getState()];
	}

	private function boolParam(string $key): bool
	{
		$v = $this->request->getParam($key);
		return $v === true || $v === '1' || $v === 'true';
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
			'duplicate' => (bool)$u->getIsDuplicate(), 'integrity' => $u->getIntegrity(), 'createdAt' => $u->getCreatedAt()->format('c'), 'note' => $u->getNote(),
		], $rows);
		return $this->ok(['uploads' => $out]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function reviewUpload(int $id): JSONResponse
	{
		$u = $this->uploadService->review($id, $this->staffUid, (string)$this->request->getParam('state', ''), $this->boolParam('reack'));
		return $this->ok(['upload' => ['id' => (int)$u->getId(), 'state' => $u->getState(), 'integrity' => $u->getIntegrity()]]);
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
		return $this->ok(['link' => ['id' => (int)$res['link']->getId(), 'url' => $this->urls->linkToRouteAbsolute('kanzleicheck.ulink.page', ['token' => $res['token']])]], 201);
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
		$holdSvc = \OCP\Server::get(\OCA\KanzleiCheck\Service\IntakeHoldService::class);
		if ($action === 'accept') {
			$res = $holdSvc->accept($this->staffUid, $id);
			return $this->ok(['state' => 'accepted', 'stored' => $res['stored'], 'failed' => $res['failed'], 'errors' => $res['errors'] ?? []]);
		}
		if ($action === 'discard' || $action === 'reject') {
			$holdSvc->discard($this->staffUid, $id);
			return $this->ok(['state' => 'discarded']);
		}
		throw new ValidationException('VALIDATION_FAILED', ['action' => 'invalid']);
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
		if (!is_array($items) || count($items) < 1 || count($items) > \OCA\KanzleiCheck\Service\RequestService::MAX_ITEMS) {
			throw new ValidationException('VALIDATION_FAILED', ['items' => 'count']);
		}
		$norm = [];
		foreach ($items as $i => $it) {
			$label = trim((string)($it['label'] ?? ''));
			if ($label === '' || mb_strlen($label) > 190) {
				throw new ValidationException('VALIDATION_FAILED', ['items' => 'label:' . $i]);
			}
			$kind = in_array($it['kind'] ?? '', \OCA\KanzleiCheck\Service\RequestService::ITEM_KINDS, true) ? $it['kind'] : 'upload';
			$norm[] = ['label' => $label, 'hint' => isset($it['hint']) ? mb_substr((string)$it['hint'], 0, 500) : null, 'kind' => $kind, 'formId' => $it['formId'] ?? null];
		}
		$rec = in_array($p['recurrence'] ?? '', ['none', 'monthly', 'quarterly', 'yearly'], true) ? $p['recurrence'] : 'none';
		$tpl = new \OCA\KanzleiCheck\Db\ReqTemplate();
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
			$this->tplOrgMapper()->setOrgs((int)$tpl->getId(), array_values(array_map('intval', $p['orgIds'])));
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
			$tpl = new \OCA\KanzleiCheck\Db\ReqTemplate();
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
		$this->tplOrgMapper()->setOrgs($id, is_array($orgIds) ? array_values(array_map('intval', $orgIds)) : []);
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
			if (!is_string($uid)
				|| !$this->userManager->userExists($uid)
				|| !$this->door->isStaff($uid)) {
				throw new ValidationException('VALIDATION_FAILED', ['userIds' => 'not_staff:' . (is_string($uid) ? $uid : 'invalid')]);
			}
		}
		$contacts = array_values(array_map(static fn(string $uid) => ['userId' => $uid, 'roleLabel' => null, 'showPhone' => false, 'showEmail' => true], array_slice($uids, 0, 50)));
		$this->contactMapper()->setForOrg($orgId, $contacts);
		return new JSONResponse(null, 204);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function contacts(int $orgId): JSONResponse
	{
		$this->org($orgId);
		return $this->ok(['contacts' => $this->contactMapper()->findForOrg($orgId)]);
	}

	private function tplOrgMapper(): \OCA\KanzleiCheck\Db\ReqTplOrgMapper
	{
		return \OCP\Server::get(\OCA\KanzleiCheck\Db\ReqTplOrgMapper::class);
	}

	// ── request detail (items + delivered files for staff review) ────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function requestDetail(int $id): JSONResponse
	{
		$r = $this->requests->mustGet($id);
		$this->org((int)$r->getOrgId());
		$itemMapper = new \OCA\KanzleiCheck\Db\ReqItemMapper($this->db());
		$fileMapper = new \OCA\KanzleiCheck\Db\ReqFileMapper($this->db());
		$items = array_map(function ($i) use ($fileMapper) {
			return ['id' => (int)$i->getId(), 'label' => $i->getLabel(), 'hint' => $i->getHint(),
				'kind' => $i->getKind(), 'formId' => $i->getFormId(), 'state' => $i->getState(),
				'rejectReason' => $i->getRejectReason(), 'deliveredAt' => $i->getDeliveredAt()?->format('c'),
				'files' => $fileMapper->uploadIdsForItem((int)$i->getId())];
		}, $itemMapper->findByReq($id));
		return $this->ok(['request' => [
			'id' => $id, 'orgId' => (int)$r->getOrgId(), 'title' => $r->getTitle(), 'note' => $r->getNote(),
			'state' => $r->getState(), 'deadline' => $r->getDeadline()?->format('Y-m-d'),
			'overdue' => $this->requests->overdue($r), 'items' => $items]]);
	}

	// ── questionnaires (staff side, US-P28) ──────────────────────────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function formTemplates(): JSONResponse
	{
		$out = array_map(fn($t) => [
			'id' => (int)$t->getId(), 'name' => $t->getName(),
			'fields' => json_decode((string)$t->getFieldsJson(), true) ?: [],
		], \OCP\Server::get(\OCA\KanzleiCheck\Db\FormTemplateMapper::class)->findAllOrdered('name', 'ASC'));
		return $this->ok(['templates' => $out]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function createFormTemplate(): JSONResponse
	{
		$this->requireAdmin();
		$t = $this->formService->createTemplate($this->staffUid,
			(string)$this->request->getParam('name', ''),
			is_array($this->request->getParam('fields')) ? $this->request->getParam('fields') : []);
		return $this->ok(['template' => ['id' => (int)$t->getId()]], 201);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function forms(): JSONResponse
	{
		$orgId = $this->strictId($this->request->getParam('orgId'));
		$state = (string)$this->request->getParam('state', '');
		if ($orgId !== null) {
			$this->org($orgId);
			$rows = $this->forms->findByOrg($orgId, $state);
		} else {
			$rows = [];
			foreach ($this->orgs->findAllOrdered() as $o) {
				$rows = array_merge($rows, $this->forms->findByOrg((int)$o->getId(), $state));
			}
		}
		$out = array_map(fn($f) => ['id' => (int)$f->getId(), 'orgId' => (int)$f->getOrgId(),
			'tplId' => (int)$f->getTplId(), 'reqItemId' => $f->getReqItemId() !== null ? (int)$f->getReqItemId() : null,
			'state' => $f->getState(), 'submittedAt' => $f->getSubmittedAt()?->format('c')],
			$rows);
		return $this->ok(['forms' => $out]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function sendForm(): JSONResponse
	{
		$orgId = $this->strictId($this->request->getParam('orgId')) ?? 0;
		$this->org($orgId);
		$f = $this->formService->instantiate($orgId,
			$this->strictId($this->request->getParam('tplId')) ?? 0,
			$this->strictId($this->request->getParam('reqItemId')));
		return $this->ok(['form' => ['id' => (int)$f->getId()]], 201);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function formDetail(int $id): JSONResponse
	{
		$f = $this->forms->find($id);
		if ($f === null) {
			throw new NotFoundException();
		}
		$this->org((int)$f->getOrgId());
		$tpl = \OCP\Server::get(\OCA\KanzleiCheck\Db\FormTemplateMapper::class)->find((int)$f->getTplId());
		$answers = \OCP\Server::get(\OCA\KanzleiCheck\Db\FormAnswerMapper::class)->allForForm($id);
		return $this->ok(['form' => [
			'id' => $id, 'orgId' => (int)$f->getOrgId(), 'name' => $tpl?->getName() ?? '', 'state' => $f->getState(),
			'fields' => json_decode((string)($tpl?->getFieldsJson() ?? '[]'), true) ?: [],
			'answers' => array_map(fn($k, $v) => ['key' => $k, 'value' => $v['value'], 'author' => $v['updated_by'], 'updatedAt' => $v['updated_at']], array_keys($answers), $answers),
		]]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function reopenForm(int $id): JSONResponse
	{
		$f = $this->forms->find($id);
		if ($f === null) {
			throw new NotFoundException();
		}
		$this->org((int)$f->getOrgId());
		$this->formService->reopen($id, $this->staffUid);
		return $this->ok(['state' => 'open']);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function exportFormCsv(int $id): \OCP\AppFramework\Http\Response
	{
		$f = $this->forms->find($id);
		if ($f === null) {
			throw new NotFoundException();
		}
		$this->org((int)$f->getOrgId());
		$tpl = \OCP\Server::get(\OCA\KanzleiCheck\Db\FormTemplateMapper::class)->find((int)$f->getTplId());
		$fields = json_decode((string)($tpl?->getFieldsJson() ?? '[]'), true) ?: [];
		$answers = \OCP\Server::get(\OCA\KanzleiCheck\Db\FormAnswerMapper::class)->allForForm($id);
		$rows = [['field', 'label', 'answer', 'author', 'updated_at']];
		foreach ($fields as $field) {
			$a = $answers[$field['key']] ?? null;
			$rows[] = [$field['key'], $field['label'], (string)($a['value'] ?? ''), (string)($a['updated_by'] ?? ''), (string)($a['updated_at'] ?? '')];
		}
		$csv = implode("\n", array_map(static fn(array $row): string =>
			implode(',', array_map(static fn(string $c): string =>
				'"' . str_replace('"', '""', \OCA\KanzleiCheck\Service\CsvFormulaGuard::escape($c)) . '"', $row)), $rows)) . "\n";
		$resp = new \OCP\AppFramework\Http\DataDownloadResponse($csv, 'form-' . $id . '.csv', 'text/csv; charset=utf-8');
		$resp->addHeader('X-Content-Type-Options', 'nosniff');
		return $resp;
	}

	// ── identity status (US-P07 AC-4 / US-P03 AC-3) ──────────────────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function setIdentityStatus(int $id): JSONResponse
	{
		$this->requireAdmin();
		$status = (string)$this->request->getParam('status', '');
		if (!in_array($status, ['active', 'disabled'], true)) {
			throw new ValidationException('VALIDATION_FAILED', ['status' => 'invalid']);
		}
		$identity = $this->identityMapper->find($id);
		if ($identity === null || $identity->getStatus() === 'erased') {
			throw new NotFoundException();
		}
		$identity->setStatus($status);
		$this->identityMapper->update($identity);
		if ($status === 'disabled') {
			// disable ⇒ every web session + device token dies now (AC-4)
			\OCP\Server::get(\OCA\KanzleiCheck\Service\PortalSessionService::class)->revokeAllForIdentity($id);
			\OCP\Server::get(\OCA\KanzleiCheck\Db\DeviceMapper::class)->revokeForIdentity($id);
		}
		\OCP\Server::get(\OCA\KanzleiCheck\Service\AuditService::class)
			->log($this->staffUid, 'identity.' . $status, 'identity', $id);
		return $this->ok(['identity' => ['id' => $id, 'status' => $status]]);
	}

	// ── staff file access + publish (US-P12) ─────────────────────────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function uploadFile(int $id): \OCP\AppFramework\Http\Response
	{
		$u = $this->uploads->find($id);
		if ($u === null) {
			throw new NotFoundException();
		}
		$this->org((int)$u->getOrgId());
		// US-S14: on rw-bound orgs the ingest sha256 is re-verified at staff
		// open — a sync-share tamper flags, never silently passes.
		$u = $this->uploadService->verifyIntegrity($u);
		if ($u->getIntegrity() === 'deleted_after_upload') {
			throw new ApiException(409, 'UPLOAD_DELETED', 'file was deleted after upload');
		}
		if ($u->getIntegrity() === 'modified_after_upload' && !$this->boolParam('reack')) {
			throw new ApiException(409, 'UPLOAD_MODIFIED', 'file was modified after upload — re-acknowledge to open anyway');
		}
		$node = \OCP\Server::get(\OCA\KanzleiCheck\Service\DocumentService::class)
			->orgNodeForOrg((int)$u->getOrgId(), (int)$u->getFileId(), null);
		$stream = $node->fopen('r');
		if (!is_resource($stream)) {
			throw new ApiException(500, 'READ_FAILED', 'cannot read file');
		}
		$resp = new \OCP\AppFramework\Http\StreamResponse($stream);
		$resp->addHeader('Content-Disposition', 'attachment; filename="' . \OCA\KanzleiCheck\Service\SafeFilenameService::sanitizeForContentDisposition($node->getName()) . '"');
		$resp->addHeader('X-Content-Type-Options', 'nosniff');
		$resp->addHeader('Content-Type', 'application/octet-stream');
		return $resp;
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function orgDocuments(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$docs = \OCP\Server::get(\OCA\KanzleiCheck\Service\DocumentService::class);
		$org = $this->orgs->find($orgId);
		$files = [];
		$folder = $org !== null ? $docs->orgFolder($orgId, (string)$org->getName(), $org->getFolderId(), $org->getFolderPath()) : null;
		$dir = $folder !== null ? $docs->subFolder($folder, \OCA\KanzleiCheck\Service\DocumentService::DIR_DOCS) : null;
		if ($dir !== null) {
			foreach ($dir->getDirectoryListing() as $node) {
				if ($node instanceof \OCP\Files\File) {
					$files[] = ['id' => (int)$node->getId(), 'name' => $node->getName(), 'size' => $node->getSize()];
				}
			}
		}
		return $this->ok(['files' => $files]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function publishUpload(int $id): JSONResponse
	{
		$u = $this->uploads->find($id);
		if ($u === null) {
			throw new NotFoundException();
		}
		$orgId = (int)$u->getOrgId();
		$this->org($orgId);
		$docs = \OCP\Server::get(\OCA\KanzleiCheck\Service\DocumentService::class);
		$node = $docs->orgNodeForOrg($orgId, (int)$u->getFileId(), \OCA\KanzleiCheck\Service\DocumentService::DIR_INBOX);
		$org = $this->orgs->find($orgId);
		if ($org === null) {
			throw new NotFoundException();
		}
		$folder = $docs->orgFolder($orgId, (string)$org->getName(), $org->getFolderId(), $org->getFolderPath());
		if (!$folder instanceof \OCP\Files\Folder) {
			throw new ApiException(500, 'NO_DOCS_ROOT', 'org folder unavailable');
		}
		$dokumente = $docs->ensureSubFolder($folder, \OCA\KanzleiCheck\Service\DocumentService::DIR_DOCS);
		$target = $docs->uniqueName($dokumente, $node->getName());
		$node->move(rtrim($dokumente->getPath(), '/') . '/' . $target);
		$u->setFileId((int)$node->getId());
		$u->setState('abgelegt');
		$u->setReviewedBy($this->staffUid);
		$u->setReviewedAt(new \DateTimeImmutable());
		$this->uploads->update($u);
		return $this->ok(['upload' => ['id' => $id, 'state' => 'abgelegt']]);
	}

	// ── setup coach status (US-P05) ──────────────────────────────────

	#[NoAdminRequired] #[NoCSRFRequired]
	public function setup(): JSONResponse
	{
		$this->requireAdmin();
		$docs = \OCP\Server::get(\OCA\KanzleiCheck\Service\DocumentService::class);
		return $this->ok(['setup' => [
			'preset' => $this->settings->get('preset'),
			'docsRoot' => $docs->rootHealthy(),
			'orgs' => count($this->orgs->findAllOrdered()),
			'identities' => count($this->identityMapper->search('')),
			'mailConfigured' => $this->settings->get('provider_label') !== '',
			'done' => $this->settings->get('preset') !== '' && $docs->rootHealthy() && count($this->orgs->findAllOrdered()) > 0,
		]]);
	}

	private function contactMapper(): \OCA\KanzleiCheck\Db\ContactMapper
	{
		return \OCP\Server::get(\OCA\KanzleiCheck\Db\ContactMapper::class);
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
			throw new \OCA\KanzleiCheck\Exception\ForbiddenException();
		}
	}
}
