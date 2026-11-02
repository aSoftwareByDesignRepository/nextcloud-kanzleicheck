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
