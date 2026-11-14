<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Controller;

use OCA\KanzleiCheck\AppInfo\Application;
use OCA\KanzleiCheck\Db\ApprovalMapper;
use OCA\KanzleiCheck\Db\ConversationMapper;
use OCA\KanzleiCheck\Db\DeviceMapper;
use OCA\KanzleiCheck\Db\DocRequestMapper;
use OCA\KanzleiCheck\Db\MessageMapper;
use OCA\KanzleiCheck\Db\OrgMapper;
use OCA\KanzleiCheck\Db\PortalFormMapper;
use OCA\KanzleiCheck\Db\ReqItemMapper;
use OCA\KanzleiCheck\Db\TaskMapper;
use OCA\KanzleiCheck\Db\UploadMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Service\ActorResolver;
use OCA\KanzleiCheck\Service\ApprovalService;
use OCA\KanzleiCheck\Service\ChatService;
use OCA\KanzleiCheck\Service\DocumentService;
use OCA\KanzleiCheck\Service\IdentityService;
use OCA\KanzleiCheck\Service\IntakeService;
use OCA\KanzleiCheck\Db\NotifyMapper;
use OCA\KanzleiCheck\Service\MailOutboxService;
use OCA\KanzleiCheck\Service\NotifyService;
use OCA\KanzleiCheck\Service\OtpService;
use OCA\KanzleiCheck\Service\OrgService;
use OCA\KanzleiCheck\Service\PresetCatalog;
use OCA\KanzleiCheck\Service\RequestService;
use OCA\KanzleiCheck\Service\SettingsService;
use OCA\KanzleiCheck\Service\TaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Client portal API (/portal/api/**) — session-gated via
 * PortalSessionMiddleware which sets ->portalIdentity. Every org-scoped
 * route checks membership before touching data; every miss is uniform.
 */
class PortalApiController extends Controller implements \OCA\KanzleiCheck\Contracts\PortalSessionAware
{
	use ApiTrait;

	/** @var \OCA\KanzleiCheck\Db\Identity */
	public $portalIdentity;
	public ?string $portalSessionToken = null;

	public function setPortalIdentity(\OCA\KanzleiCheck\Db\Identity $identity, string $sessionToken): void
	{
		$this->portalIdentity = $identity;
		$this->portalSessionToken = $sessionToken;
	}

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IdentityService $identities,
		private readonly OrgMapper $orgs,
		private readonly OrgService $orgService,
		private readonly ChatService $chat,
		private readonly ConversationMapper $convs,
		private readonly MessageMapper $messages,
		private readonly TaskService $tasks,
		private readonly TaskMapper $taskMapper,
		private readonly RequestService $requests,
		private readonly DocRequestMapper $requestMapper,
		private readonly ReqItemMapper $items,
		private readonly ApprovalService $approvals,
		private readonly ApprovalMapper $approvalMapper,
		private readonly PortalFormMapper $forms,
		private readonly DeviceMapper $devices,
		private readonly UploadMapper $uploads,
		private readonly NotifyMapper $notify,
		private readonly IntakeService $intake,
		private readonly DocumentService $docs,
		private readonly PresetCatalog $presets,
		private readonly SettingsService $settings,
		private readonly NotifyService $notifyService,
		private readonly \OCA\KanzleiCheck\Service\FormService $formService,
		private readonly MailOutboxService $mailOutbox,
		private readonly IURLGenerator $urls,
	) {
		parent::__construct($appName, $request);
	}

	private function org(int $orgId): \OCA\KanzleiCheck\Db\Org
	{
		if ($orgId <= 0 || !$this->identities->isMember((int)$this->portalIdentity->getId(), $orgId)) {
			throw new NotFoundException();
		}
		$org = $this->orgs->find($orgId);
		if ($org === null || $org->getState() !== 'active') {
			throw new NotFoundException();
		}
		return $org;
	}

	private function actor(): string
	{
		return ActorResolver::portal((int)$this->portalIdentity->getId());
	}

	#[PublicPage] #[NoCSRFRequired]
	public function orgs(): JSONResponse
	{
		$out = [];
		foreach ($this->identities->orgIdsFor((int)$this->portalIdentity->getId()) as $oid) {
			$org = $this->orgs->find($oid);
			if ($org === null || $org->getState() !== 'active') {
				continue;
			}
			$conv = $this->convs->findByOrg($oid);
			$unread = $conv !== null ? $this->chat->unreadCount((int)$conv->getId(), $this->actor()) : 0;
			$out[] = ['id' => $oid, 'name' => $org->getName(), 'unread' => $unread,
				'bookingUrl' => \OCP\Server::get(\OCA\KanzleiCheck\Service\IntegrationService::class)->bookingUrlFor($org)];
		}
		return $this->ok(['orgs' => $out, 'me' => ['id' => (int)$this->portalIdentity->getId(), 'displayName' => $this->portalIdentity->getDisplayName()]]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function messages(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$conv = $this->chat->convForOrg($orgId);
		if ($conv === null || $conv->getState() !== 'open') {
			throw new NotFoundException();
		}
		$after = $this->strictId($this->request->getParam('after', 0)) ?? 0;
		$msgs = array_map(fn($m) => [
			'id' => (int)$m->getId(), 'author' => $m->getAuthor(), 'kind' => $m->getAuthorKind(),
			'body' => $m->getBody(), 'uploadId' => $m->getUploadId(), 'createdAt' => $m->getCreatedAt()->format('c'),
			'mine' => $m->getAuthor() === $this->actor(),
		], $this->messages->listAfter((int)$conv->getId(), $after));
		$poll = max(5000, $this->settings->getInt('poll_ms', 15000));
		return $this->ok(['messages' => $msgs, 'pollAfterMs' => (int)($poll * (0.7 + (lcg_value() * 0.6)))]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function sendMessage(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$m = $this->chat->post($orgId, $this->actor(), 'client',
			(string)$this->request->getParam('body', ''),
			(string)$this->request->getParam('clientUuid', '') ?: null,
			$this->strictId($this->request->getParam('uploadId')));
		$this->notifyStaffNewMessage($orgId);
		return $this->ok(['message' => ['id' => (int)$m->getId()]], 201);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function markRead(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$conv = $this->convs->findByOrg($orgId);
		$last = $this->strictId($this->request->getParam('lastReadId', 0)) ?? 0;
		if ($conv !== null && $last > 0) {
			$this->chat->markRead((int)$conv->getId(), $this->actor(), $last);
		}
		return new JSONResponse(null, 204);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function tasks(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$p = $this->presets->get($this->settings->get('preset'));
		$out = array_map(fn($t) => [
			'id' => (int)$t->getId(), 'title' => $t->getTitle(),
			'statusKey' => $t->getStatus(), 'statusLabel' => $p['board'][$t->getStatus()] ?? $t->getStatus(),
			'dueDate' => $t->getDueDate()?->format('Y-m-d'), 'doneAt' => $t->getDoneAt()?->format('c'),
		], $this->taskMapper->findVisibleForOrg($orgId));
		return $this->ok(['tasks' => $out]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function requests(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$out = [];
		foreach ($this->requestMapper->findByOrg($orgId, 'open') as $r) {
			$items = array_map(fn($i) => [
				'id' => (int)$i->getId(), 'label' => $i->getLabel(), 'hint' => $i->getHint(),
				'kind' => $i->getKind(), 'formId' => $i->getFormId(), 'state' => $i->getState(),
				'rejectReason' => $i->getRejectReason(),
			], $this->items->findByReq((int)$r->getId()));
			$out[] = ['id' => (int)$r->getId(), 'title' => $r->getTitle(), 'note' => $r->getNote(),
				'deadline' => $r->getDeadline()?->format('Y-m-d'), 'overdue' => $this->requests->overdue($r),
				'items' => $items];
		}
		return $this->ok(['requests' => $out]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function confirmItem(int $id, int $itemId): JSONResponse
	{
		$this->orgOfRequest($id);
		$i = $this->requests->confirmItem($this->actor(), $id, $itemId);
		return $this->ok(['itemState' => $i->getState()]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function approvals(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$out = array_map(function ($a) {
			$this->approvals->checkVoid($a);
			return ['id' => (int)$a->getId(), 'fileName' => $a->getFileName(), 'state' => $a->getState(),
				'deadline' => $a->getDeadline()?->format('Y-m-d'), 'note' => $a->getNote()];
		}, $this->approvalMapper->findByOrg($orgId));
		return $this->ok(['approvals' => $out]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function approvalStart(int $id): JSONResponse
	{
		$orgId = $this->orgOfApproval($id);
		$this->approvals->mustGetInOrg($id, $orgId);
		$res = $this->approvals->start($id, $this->portalIdentity->getEmail(), $this->request->getRemoteAddress() ?: '');
		$this->notifyOtp($res['code'], $id);
		return $this->ok(['sent' => true], 202);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function approvalDecide(int $id): JSONResponse
	{
		$orgId = $this->orgOfApproval($id);
		$a = $this->approvals->decide($this->actor(), $this->portalIdentity->getEmail(), $id, $orgId,
			(string)$this->request->getParam('decision', ''),
			$this->request->getParam('code') !== null ? (string)$this->request->getParam('code') : null,
			$this->request->getParam('reason') !== null ? (string)$this->request->getParam('reason') : null);
		return $this->ok(['state' => $a->getState()]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function unread(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$conv = $this->convs->findByOrg($orgId);
		return $this->ok([
			'messages' => $conv !== null ? $this->chat->unreadCount((int)$conv->getId(), $this->actor()) : 0,
			'documents' => $this->uploads->countUnreadByOrg($orgId),
			'tasks' => count($this->taskMapper->findVisibleForOrg($orgId)),
		]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function devices(): JSONResponse
	{
		$out = array_map(fn($d) => ['id' => (int)$d->getId(), 'name' => $d->getDeviceName(), 'platform' => $d->getPlatform(),
			'pairedAt' => $d->getCreatedAt()->format('c'), 'lastUsedAt' => $d->getLastUsedAt()->format('c')],
			$this->devices->findByIdentity((int)$this->portalIdentity->getId()));
		return $this->ok(['devices' => $out]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function intake(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$address = $this->intake->addressFor($orgId);
		return $this->ok(['enabled' => $address !== null, 'address' => $address]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function invoices(int $orgId): JSONResponse
	{
		$org = $this->org($orgId);
		$integrations = \OCP\Server::get(\OCA\KanzleiCheck\Service\IntegrationService::class);
		if (!$integrations->invoiceAvailable() || $org->getPcCustId() === null) {
			throw new NotFoundException();
		}
		return $this->ok(['invoices' => $integrations->invoicesForOrg((int)$org->getPcCustId())]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function notifyGet(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$row = $this->notify->find((int)$this->portalIdentity->getId(), $orgId);
		return $this->ok(['mailsEnabled' => $row === null || (int)$row['mails_enabled'] === 1]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function contact(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$out = [];
		foreach (\OCP\Server::get(\OCA\KanzleiCheck\Db\ContactMapper::class)->findForOrg($orgId) as $c) {
			$user = \OCP\Server::get(\OCP\IUserManager::class)->get((string)$c['user_id']);
			if ($user === null || !$user->isEnabled()) {
				continue;
			}
			$out[] = [
				'name' => $user->getDisplayName(),
				'role' => $c['role_label'],
				'email' => !empty($c['show_email']) ? $user->getEMailAddress() : null,
				'avatarUrl' => $this->urls->linkToRouteAbsolute('core.avatar.getAvatar', ['userId' => $user->getUID(), 'size' => 128]),
			];
		}
		return $this->ok(['contacts' => $out]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function notifyPut(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$enabled = $this->request->getParam('mailsEnabled');
		$this->notify->setMailsEnabled((int)$this->portalIdentity->getId(), $orgId, $enabled === true || $enabled === '1' || $enabled === 'true');
		return new JSONResponse(null, 204);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function todo(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$todo = [];
		foreach ($this->requestMapper->findByOrg($orgId, 'open') as $r) {
			$open = array_filter($this->items->findByReq((int)$r->getId()), fn($i) => $i->getState() !== 'accepted');
			foreach ($open as $i) {
				$todo[] = ['kind' => 'request', 'id' => (int)$r->getId(), 'itemId' => (int)$i->getId(),
					'title' => $i->getLabel(), 'state' => $i->getState(), 'rejectReason' => $i->getRejectReason(),
					'deadline' => $r->getDeadline()?->format('Y-m-d'), 'overdue' => $this->requests->overdue($r)];
			}
		}
		foreach ($this->approvalMapper->findByOrg($orgId, 'pending') as $a) {
			$this->approvals->checkVoid($a);
			if ($a->getState() === 'pending') {
				$todo[] = ['kind' => 'approval', 'id' => (int)$a->getId(), 'title' => $a->getFileName(),
					'deadline' => $a->getDeadline()?->format('Y-m-d')];
			}
		}
		foreach ($this->forms->findByOrg($orgId, 'open') as $f) {
			$todo[] = ['kind' => 'form', 'id' => (int)$f->getId(), 'title' => 'Formular', 'deadline' => null];
		}
		usort($todo, fn($a, $b) => (($a['overdue'] ?? false) ? 0 : 1) <=> (($b['overdue'] ?? false) ? 0 : 1) ?: strcmp($a['deadline'] ?? '9999', $b['deadline'] ?? '9999'));
		return $this->ok(['todo' => $todo]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function session(): JSONResponse
	{
		$raw = (string)$this->request->getCookie(\OCA\KanzleiCheck\Middleware\PortalSessionMiddleware::SESSION_COOKIE);
		return $this->ok([
			'csrf' => (new \OCA\KanzleiCheck\Service\CsrfService())->tokenFor($raw),
			'identity' => ['id' => (int)$this->portalIdentity->getId(), 'displayName' => $this->portalIdentity->getDisplayName()],
		]);
	}

	// ── uploads (direct + chunked) ──────────────────────────────────

	private function orgFolder(int $orgId): \OCP\Files\Folder
	{
		$org = $this->org($orgId);
		if (!$this->docs->rootHealthy()) {
			throw new \OCA\KanzleiCheck\Exception\ApiException(503, 'NO_DOCS_ROOT', 'document area unavailable');
		}
		$f = $this->docs->orgFolder((int)$org->getId(), (string)$org->getName(), $org->getFolderId(), $org->getFolderPath());
		if ($f === null) {
			throw new \OCA\KanzleiCheck\Exception\ApiException(503, 'NO_DOCS_ROOT', 'document area unavailable');
		}
		// persist the binding once (folder created or rebound)
		if ($f->getId() !== $org->getFolderId()) {
			$org->setFolderId((int)$f->getId());
			// unique-conflict:ok folder_id rebind only — the unique ref column
			// is never SET on this write path.
			$this->orgs->update($org);
		}
		return $f;
	}

	#[PublicPage] #[NoCSRFRequired]
	public function upload(int $orgId): JSONResponse
	{
		$eingang = $this->docs->ensureSubFolder($this->orgFolder($orgId), \OCA\KanzleiCheck\Service\DocumentService::DIR_INBOX);
		$file = $_FILES['file'] ?? null;
		if ($file === null || !is_uploaded_file($file['tmp_name'] ?? '')) {
			throw new \OCA\KanzleiCheck\Exception\ApiException(400, 'VALIDATION_FAILED', 'no file');
		}
		$uploadSvc = new \OCA\KanzleiCheck\Service\UploadService($this->uploads, $this->settings, $this->docs);
		$uploadSvc->validateFile($file['tmp_name'], (string)$file['name'], $uploadSvc->maxBytes());
		$note = $this->request->getParam('note') !== null ? (string)$this->request->getParam('note') : null;
		$reqItemId = $this->strictId($this->request->getParam('reqItemId'));
		$res = $uploadSvc->store($eingang, $orgId, $this->actor(), $file['tmp_name'], (string)$file['name'], 'portal', $note);
		if ($reqItemId !== null && $this->request->getParam('reqId') !== null) {
			$reqId = $this->strictId($this->request->getParam('reqId')) ?? 0;
			// request must belong to THIS org — a foreign-org request id can
			// never silently mark delivery against an unrelated checklist
			$this->requests->mustGetInOrg($reqId, $orgId);
			$this->requests->deliverItem($this->actor(), $reqId, $reqItemId, (int)$res['upload']->getId());
		}
		$this->notifyService->clientMail($orgId, 'document');
		return $this->ok(['id' => (int)$res['upload']->getId(), 'duplicate' => $res['duplicate'] !== null], 201);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function chunkStart(int $orgId): JSONResponse
	{
		$this->org($orgId);
		$uploadId = (string)$this->request->getParam('uploadId', '');
		$chunks = $this->chunkSvc();
		$chunks->start($uploadId, $orgId, $this->actor(),
			(string)$this->request->getParam('fileName', 'upload'),
			(int)$this->request->getParam('totalBytes', 0));
		return $this->ok(['uploadId' => $uploadId], 201);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function chunkPut(string $uploadKey, int $index): JSONResponse
	{
		$row = $this->chunkSvc()->find($uploadKey);
		if ($row === null) {
			throw new NotFoundException();
		}
		$this->org((int)$row['org_id']);
		$file = $_FILES['chunk'] ?? null;
		if ($file === null || !is_uploaded_file($file['tmp_name'] ?? '')) {
			throw new \OCA\KanzleiCheck\Exception\ApiException(400, 'VALIDATION_FAILED', 'no chunk');
		}
		$this->chunkSvc()->putChunk($uploadKey, (int)$row['org_id'], $this->actor(), $index, $file['tmp_name']);
		return $this->ok(['received' => true]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function chunkFinish(string $uploadKey): JSONResponse
	{
		$row = $this->chunkSvc()->find($uploadKey);
		if ($row === null) {
			throw new NotFoundException();
		}
		$orgId = (int)$row['org_id'];
		$eingang = $this->docs->ensureSubFolder($this->orgFolder($orgId), \OCA\KanzleiCheck\Service\DocumentService::DIR_INBOX);
		$res = $this->chunkSvc()->finish($uploadKey, $orgId, $this->actor());
		$uploadSvc = new \OCA\KanzleiCheck\Service\UploadService($this->uploads, $this->settings, $this->docs);
		try {
			$uploadSvc->validateFile($res['tmp'], (string)$row['file_name'], $uploadSvc->maxBytes());
			$stored = $uploadSvc->store($eingang, $orgId, $this->actor(), $res['tmp'], (string)$row['file_name'], 'portal', null);
		} finally {
			@unlink($res['tmp']);
		}
		$this->chunkSvc()->complete($uploadKey);
		$this->notifyService->clientMail($orgId, 'document');
		return $this->ok(['id' => (int)$stored['upload']->getId(), 'duplicate' => $stored['duplicate'] !== null], 201);
	}

	private function chunkSvc(): \OCA\KanzleiCheck\Service\ChunkUploadService
	{
		return \OCP\Server::get(\OCA\KanzleiCheck\Service\ChunkUploadService::class);
	}

	// ── devices (revoke own) ─────────────────────────────────────────

	#[PublicPage] #[NoCSRFRequired]
	public function revokeDevice(int $id): JSONResponse
	{
		$svc = \OCP\Server::get(\OCA\KanzleiCheck\Service\DeviceService::class);
		if (!$svc->revoke($id, (int)$this->portalIdentity->getId())) {
			throw new NotFoundException();
		}
		return new JSONResponse(null, 204);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function revokeAllDevices(): JSONResponse
	{
		$this->devices->revokeForIdentity((int)$this->portalIdentity->getId());
		return new JSONResponse(null, 204);
	}

	// ── documents (Dokumente read surface) ───────────────────────────

	#[PublicPage] #[NoCSRFRequired]
	public function documents(int $orgId): JSONResponse
	{
		$folder = $this->orgFolder($orgId);
		$dir = $this->docs->subFolder($folder, \OCA\KanzleiCheck\Service\DocumentService::DIR_DOCS);
		$files = [];
		if ($dir !== null) {
			foreach ($dir->getDirectoryListing() as $node) {
				if ($node instanceof \OCP\Files\File) {
					$files[] = ['id' => (int)$node->getId(), 'name' => $node->getName(), 'size' => $node->getSize(), 'mtime' => $node->getMTime(), 'mime' => $node->getMimeType()];
				}
			}
		}
		return $this->ok(['files' => $files]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function download(int $orgId, int $fileId): \OCP\AppFramework\Http\Response
	{
		$this->org($orgId);
		$node = $this->docs->documentNodeForOrg($orgId, $fileId);
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

	// ── request item delivery (non-upload kinds already covered) ─────

	#[PublicPage] #[NoCSRFRequired]
	public function deliverItem(int $id, int $itemId): JSONResponse
	{
		$this->orgOfRequest($id);
		$uploadId = $this->strictId($this->request->getParam('uploadId')) ?? 0;
		$i = $this->requests->deliverItem($this->actor(), $id, $itemId, $uploadId);
		return $this->ok(['itemState' => $i->getState()]);
	}

	// ── forms ────────────────────────────────────────────────────────

	#[PublicPage] #[NoCSRFRequired]
	public function form(int $formId): JSONResponse
	{
		$form = $this->forms->find($formId);
		if ($form === null) {
			throw new NotFoundException();
		}
		$this->org((int)$form->getOrgId());
		$tplMapper = \OCP\Server::get(\OCA\KanzleiCheck\Db\FormTemplateMapper::class);
		$tpl = $tplMapper->find((int)$form->getTplId());
		$answers = \OCP\Server::get(\OCA\KanzleiCheck\Db\FormAnswerMapper::class)->allForForm($formId);
		return $this->ok(['form' => [
			'id' => $formId, 'state' => $form->getState(),
			'fields' => json_decode((string)($tpl?->getFieldsJson() ?? '[]'), true) ?: [],
			'answers' => array_map(fn($k, $v) => ['key' => $k, 'value' => $v['value'], 'revision' => $v['revision'], 'author' => $v['updated_by']], array_keys($answers), $answers),
		]]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function formAnswer(int $formId): JSONResponse
	{
		$form = $this->forms->find($formId);
		if ($form === null) {
			throw new NotFoundException();
		}
		$this->org((int)$form->getOrgId());
		$svc = \OCP\Server::get(\OCA\KanzleiCheck\Service\FormService::class);
		$r = $svc->saveAnswer($this->actor(), $formId, (int)$form->getOrgId(),
			(string)$this->request->getParam('field', ''),
			(string)$this->request->getParam('value', ''),
			(int)$this->request->getParam('revision', 0));
		return $this->ok($r);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function formSubmit(int $formId): JSONResponse
	{
		$form = $this->forms->find($formId);
		if ($form === null) {
			throw new NotFoundException();
		}
		$this->org((int)$form->getOrgId());
		$svc = \OCP\Server::get(\OCA\KanzleiCheck\Service\FormService::class);
		$svc->submit($this->actor(), $formId, (int)$form->getOrgId());
		if ($form->getReqItemId() !== null) {
			try {
				$item = \OCP\Server::get(\OCA\KanzleiCheck\Db\ReqItemMapper::class)->find((int)$form->getReqItemId());
				if ($item !== null) {
					$this->requests->deliverItem($this->actor(), (int)$item->getReqId(), (int)$item->getId());
				}
			} catch (\Throwable $e) {
				\OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning('kanzleicheck form submit auto-deliver failed', ['form' => $formId, 'e' => $e->getMessage()]);
			}
		}
		return new JSONResponse(null, 204);
	}

	private function orgOfRequest(int $reqId): int
	{
		$r = $this->requestMapper->find($reqId);
		if ($r === null) {
			throw new NotFoundException();
		}
		$this->org((int)$r->getOrgId());
		return (int)$r->getOrgId();
	}

	private function orgOfApproval(int $id): int
	{
		$a = $this->approvalMapper->find($id);
		if ($a === null) {
			throw new NotFoundException();
		}
		$this->org((int)$a->getOrgId());
		return (int)$a->getOrgId();
	}

	private function notifyStaffNewMessage(int $orgId): void
	{
		foreach ($this->notifyService->staffRecipients($orgId) as $uid) {
			$this->notifyService->staffNotify($uid, 'portal_message', $orgId, $this->urls->linkToRouteAbsolute('kanzleicheck.page.index') . '?view=inbox&org=' . $orgId);
		}
		$this->notifyService->clientMail($orgId, 'message');
	}

	private function notifyOtp(string $code, int $approvalId): void
	{
		try {
			$this->mailOutbox->enqueue($this->portalIdentity->getEmail(), 'approve',
				'approve:' . $approvalId . ':' . hash('sha256', $code),
				['code' => $code, 'provider' => $this->settings->get('provider_label')], 'de');
			$this->mailOutbox->drain(3);
		} catch (\Throwable $e) {
			\OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning('kanzleicheck approval OTP mail enqueue failed', ['approval' => $approvalId, 'e' => $e->getMessage()]);
		}
	}
}
