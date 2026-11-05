<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Controller;

use OCA\PortalCheck\Db\ApprovalMapper;
use OCA\PortalCheck\Db\ContactMapper;
use OCA\PortalCheck\Db\ConversationMapper;
use OCA\PortalCheck\Db\DeviceMapper;
use OCA\PortalCheck\Db\DocRequestMapper;
use OCA\PortalCheck\Db\MessageMapper;
use OCA\PortalCheck\Db\OrgMapper;
use OCA\PortalCheck\Db\PortalFormMapper;
use OCA\PortalCheck\Db\ReqItemMapper;
use OCA\PortalCheck\Db\TaskMapper;
use OCA\PortalCheck\Db\UploadMapper;
use OCA\PortalCheck\Exception\ApiException;
use OCA\PortalCheck\Exception\NotFoundException;
use OCA\PortalCheck\Service\ActorResolver;
use OCA\PortalCheck\Service\ApprovalService;
use OCA\PortalCheck\Service\ChatService;
use OCA\PortalCheck\Service\ChunkUploadService;
use OCA\PortalCheck\Service\DeviceService;
use OCA\PortalCheck\Service\DocumentService;
use OCA\PortalCheck\Service\FormService;
use OCA\PortalCheck\Service\IdentityService;
use OCA\PortalCheck\Service\IntakeService;
use OCA\PortalCheck\Service\IntegrationService;
use OCA\PortalCheck\Service\LicenseService;
use OCA\PortalCheck\Service\MailOutboxService;
use OCA\PortalCheck\Service\NotifyService;
use OCA\PortalCheck\Service\OtpService;
use OCA\PortalCheck\Service\PresetCatalog;
use OCA\PortalCheck\Service\RequestService;
use OCA\PortalCheck\Service\SafeFilenameService;
use OCA\PortalCheck\Service\SettingsService;
use OCA\PortalCheck\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * Companion API (/api/companion/v1/*). Pair is open (email+code); every
 * other route needs X-PortalCheck-Device → identity via middleware.
 * Mirrors the web portal client surface one-to-one (§9.5): every org-
 * scoped read/write verifies membership and misses uniformly.
 */
class CompanionController extends Controller implements \OCA\PortalCheck\Contracts\CompanionContextAware
{
	use ApiTrait;

	public $companionIdentity;
	public $companionDevice;

	public function setCompanionContext(\OCA\PortalCheck\Db\Identity $identity, \OCA\PortalCheck\Db\Device $device): void
	{
		$this->companionIdentity = $identity;
		$this->companionDevice = $device;
	}

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IdentityService $identities,
		private readonly OtpService $otp,
		private readonly DeviceService $devices,
		private readonly DeviceMapper $deviceMapper,
		private readonly LicenseService $license,
		private readonly MailOutboxService $outbox,
		private readonly SettingsService $settings,
		private readonly IURLGenerator $urls,
		private readonly OrgMapper $orgs,
		private readonly ChatService $chat,
		private readonly ConversationMapper $convs,
		private readonly MessageMapper $messages,
		private readonly TaskMapper $taskMapper,
		private readonly DocRequestMapper $requestMapper,
		private readonly ReqItemMapper $items,
		private readonly ApprovalService $approvals,
		private readonly ApprovalMapper $approvalMapper,
		private readonly RequestService $requests,
		private readonly DocumentService $docs,
		private readonly UploadService $uploadService,
		private readonly UploadMapper $uploads,
		private readonly IntakeService $intake,
		private readonly \OCA\PortalCheck\Db\NotifyMapper $notify,
		private readonly PortalFormMapper $forms,
		private readonly FormService $formService,
		private readonly IntegrationService $integrations,
		private readonly NotifyService $notifyService,
		private readonly ContactMapper $contacts,
		private readonly IUserManager $userManager,
		private readonly PresetCatalog $presets,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function pair(): JSONResponse
	{
		$email = IdentityService::normalizeEmail((string)$this->request->getParam('email', ''));
		$code = trim((string)$this->request->getParam('code', ''));
		$name = mb_substr(trim((string)$this->request->getParam('deviceName', 'device')), 0, 100);
		$platform = (string)$this->request->getParam('platform', '');
		$otpId = preg_match('/^\d{6}$/', $code) ? $this->otp->verify($email, OtpService::PURPOSE_LOGIN, $code) : null;
		$identity = $otpId !== null ? $this->identities->activeByEmail($email) : null;
		if ($otpId === null || $identity === null) {
			throw new ApiException(400, 'INVALID_CODE', 'invalid or expired code');
		}
		[$token, $device] = $this->devices->pair((int)$identity->getId(), $name, $platform);
		$this->outbox->enqueue($identity->getEmail(), 'device', 'device:' . $device->getId(),
			['deviceName' => $name, 'platform' => $platform, 'time' => (new \DateTimeImmutable())->format('Y-m-d H:i'),
			 'portalUrl' => $this->urls->linkToRouteAbsolute('portalcheck.portal.index'), 'provider' => $this->settings->get('provider_label')], 'de');
		return $this->ok(['deviceToken' => $token, 'identity' => ['id' => (int)$identity->getId(), 'displayName' => $identity->getDisplayName()]]);
	}

	// ── client-role data endpoints (device-token gated) ──────────────

	private function identity(): \OCA\PortalCheck\Db\Identity
	{
		if ($this->companionIdentity === null) {
			throw new \OCA\PortalCheck\Exception\UnauthorizedException();
		}
		return $this->companionIdentity;
	}

	private function companionOrg(int $orgId): \OCA\PortalCheck\Db\Org
	{
		$identityId = (int)$this->identity()->getId();
		if ($orgId <= 0 || !$this->identities->isMember($identityId, $orgId)) {
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
		return ActorResolver::portal((int)$this->identity()->getId());
	}

	private function orgFolder(int $orgId): \OCP\Files\Folder
	{
		$org = $this->companionOrg($orgId);
		if (!$this->docs->rootHealthy()) {
			throw new ApiException(503, 'NO_DOCS_ROOT', 'document area unavailable');
		}
		$f = $this->docs->orgFolder((int)$org->getId(), (string)$org->getName(), $org->getFolderId(), $org->getFolderPath());
		if ($f === null) {
			throw new ApiException(503, 'NO_DOCS_ROOT', 'document area unavailable');
		}
		if ($f->getId() !== $org->getFolderId()) {
			$org->setFolderId((int)$f->getId());
			// unique-conflict:ok folder_id rebind only — the unique ref column
			// is never SET on this write path.
			$this->orgs->update($org);
		}
		return $f;
	}


	/** Bootstrap always answers — role/capabilities/licence envelope (never gated). */
	#[PublicPage] #[NoCSRFRequired]
	public function bootstrap(): JSONResponse
	{
		$orgs = [];
		if ($this->companionIdentity !== null) {
			foreach ($this->identities->orgIdsFor((int)$this->companionIdentity->getId()) as $oid) {
				$org = $this->orgs->find($oid);
				if ($org === null || $org->getState() !== 'active') {
					continue;
				}
				$orgs[] = ['id' => $oid, 'name' => $org->getName(), 'appEnabled' => (bool)$org->getAppOn()];
			}
		}
		$status = $this->license->status();
		return $this->ok([
			'role' => $this->companionIdentity !== null ? 'client' : 'unknown',
			'capabilities' => ['portal' => true, 'portalcheck.companion.min' => 1],
			'pushAvailable' => false,
			'portal' => ['enabled' => $status !== 'expired', 'licenseStatus' => $status, 'orgs' => $orgs],
			'licensing' => ['status' => $status, 'product' => 'portalcheck'],
			'server' => ['appVersion' => '0.1.0', 'companionMin' => 1],
		]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function orgs(): JSONResponse
	{
		$this->identity();
		$out = [];
		foreach ($this->identities->orgIdsFor((int)$this->companionIdentity->getId()) as $oid) {
			$org = $this->orgs->find($oid);
			if ($org === null || $org->getState() !== 'active') {
				continue;
			}
			$conv = $this->convs->findByOrg($oid);
			$out[] = ['id' => $oid, 'name' => $org->getName(), 'appEnabled' => (bool)$org->getAppOn(),
				'bookingUrl' => $this->integrations->bookingUrlFor($org),
				'unread' => $conv !== null ? $this->chat->unreadCount((int)$conv->getId(), $this->actor()) : 0];
		}
		return $this->ok(['orgs' => $out]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function messages(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
		$conv = $this->chat->convForOrg($orgId);
		if ($conv === null || $conv->getState() !== 'open') {
			throw new NotFoundException();
		}
		$after = $this->strictId($this->request->getParam('after', 0)) ?? 0;
		$msgs = array_map(fn($m) => [
			'id' => (int)$m->getId(), 'author' => $m->getAuthor(), 'kind' => $m->getAuthorKind(),
			'body' => $m->getBody(), 'createdAt' => $m->getCreatedAt()->format('c'),
			'mine' => $m->getAuthor() === $this->actor(),
		], $this->messages->listAfter((int)$conv->getId(), $after));
		$poll = max(5000, $this->settings->getInt('poll_ms', 15000));
		return $this->ok(['messages' => $msgs, 'pollAfterMs' => (int)($poll * (0.7 + (lcg_value() * 0.6)))]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function sendMessage(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
		$m = $this->chat->post($orgId, $this->actor(), 'client',
			(string)$this->request->getParam('body', ''),
			(string)$this->request->getParam('clientUuid', '') ?: null,
			$this->strictId($this->request->getParam('uploadId')));
		foreach ($this->notifyService->staffRecipients($orgId) as $uid) {
			$this->notifyService->staffNotify($uid, 'portal_message', $orgId, $this->urls->linkToRoute('portalcheck.page.index') . '?view=inbox&org=' . $orgId);
		}
		return $this->ok(['message' => ['id' => (int)$m->getId()]], 201);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function markRead(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
		$conv = $this->convs->findByOrg($orgId);
		$last = $this->strictId($this->request->getParam('lastReadId', 0)) ?? 0;
		if ($conv !== null && $last > 0) {
			$this->chat->markRead((int)$conv->getId(), $this->actor(), $last);
		}
		return new JSONResponse(null, 204);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function unread(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
		$conv = $this->convs->findByOrg($orgId);
		return $this->ok([
			'messages' => $conv !== null ? $this->chat->unreadCount((int)$conv->getId(), $this->actor()) : 0,
			'documents' => $this->uploads->countUnreadByOrg($orgId),
			'tasks' => count($this->taskMapper->findVisibleForOrg($orgId)),
		]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function todo(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
		$todo = [];
		foreach ($this->requestMapper->findByOrg($orgId, 'open') as $r) {
			foreach ($this->items->findByReq((int)$r->getId()) as $i) {
				if ($i->getState() !== 'accepted') {
					$todo[] = ['kind' => 'request', 'id' => (int)$r->getId(), 'itemId' => (int)$i->getId(),
						'title' => $i->getLabel(), 'itemKind' => $i->getKind(), 'formId' => $i->getFormId(),
						'state' => $i->getState(), 'rejectReason' => $i->getRejectReason(),
						'deadline' => $r->getDeadline()?->format('Y-m-d'),
						'overdue' => $this->requests->overdue($r)];
				}
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
	public function tasks(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
		$p = $this->presets->get($this->settings->get('preset'));
		$out = array_map(fn($t) => ['id' => (int)$t->getId(), 'title' => $t->getTitle(),
			'status' => $t->getStatus(), 'statusLabel' => $p['board'][$t->getStatus()] ?? $t->getStatus(),
			'dueDate' => $t->getDueDate()?->format('Y-m-d'),
			'done' => $t->getDoneAt() !== null],
			$this->taskMapper->findVisibleForOrg($orgId));
		return $this->ok(['tasks' => $out]);
	}

	// ── requests ─────────────────────────────────────────────────────

	#[PublicPage] #[NoCSRFRequired]
	public function requestsList(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
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
	public function deliverItem(int $id, int $itemId): JSONResponse
	{
		$this->orgOfRequest($id);
		$uploadId = $this->strictId($this->request->getParam('uploadId')) ?? 0;
		$i = $this->requests->deliverItem($this->actor(), $id, $itemId, $uploadId);
		return $this->ok(['itemState' => $i->getState()]);
	}

	/** Multipart file directly against a request item (spec §9.5 files endpoint). */
	#[PublicPage] #[NoCSRFRequired]
	public function deliverItemFile(int $id, int $itemId): JSONResponse
	{
		$orgId = $this->orgOfRequest($id);
		$res = $this->storeUpload($orgId, 'app');
		$i = $this->requests->deliverItem($this->actor(), $id, $itemId, (int)$res['upload']->getId());
		return $this->ok(['uploadId' => (int)$res['upload']->getId(), 'itemState' => $i->getState(), 'duplicate' => $res['duplicate'] !== null], 201);
	}

	// ── uploads ──────────────────────────────────────────────────────

	private function storeUpload(int $orgId, string $source): array
	{
		$eingang = $this->docs->ensureSubFolder($this->orgFolder($orgId), DocumentService::DIR_INBOX);
		$file = $_FILES['file'] ?? null;
		if ($file === null || !is_uploaded_file($file['tmp_name'] ?? '')) {
			throw new ApiException(400, 'VALIDATION_FAILED', 'no file');
		}
		$this->uploadService->validateFile($file['tmp_name'], (string)$file['name'], $this->uploadService->maxBytes());
		$note = $this->request->getParam('note') !== null ? (string)$this->request->getParam('note') : null;
		return $this->uploadService->store($eingang, $orgId, $this->actor(), $file['tmp_name'], (string)$file['name'], $source, $note);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function upload(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
		$res = $this->storeUpload($orgId, 'app');
		$reqItemId = $this->strictId($this->request->getParam('reqItemId'));
		$reqId = $this->strictId($this->request->getParam('reqId'));
		if ($reqItemId !== null && $reqId !== null) {
			$this->requests->mustGetInOrg($reqId, $orgId);
			$this->requests->deliverItem($this->actor(), $reqId, $reqItemId, (int)$res['upload']->getId());
		}
		$this->notifyService->clientMail($orgId, 'document');
		return $this->ok(['id' => (int)$res['upload']->getId(), 'duplicate' => $res['duplicate'] !== null], 201);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function chunkStart(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
		$uploadId = (string)$this->request->getParam('uploadId', '');
		if (!preg_match('/^[a-zA-Z0-9-]{1,64}$/', $uploadId)) {
			$uploadId = bin2hex(random_bytes(18));
		}
		$this->chunkSvc()->start($uploadId, $orgId, $this->actor(),
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
		$this->companionOrg((int)$row['org_id']);
		// raw request body → temp file (mobile sends bytes, not multipart)
		$content = fopen('php://input', 'rb');
		$tmp = tempnam(sys_get_temp_dir(), 'ptl_chk_');
		$out = fopen($tmp, 'wb');
		if (is_resource($content) && $out !== false) {
			stream_copy_to_stream($content, $out);
		}
		if (is_resource($content)) {
			fclose($content);
		}
		if (is_resource($out)) {
			fclose($out);
		}
		try {
			$this->chunkSvc()->putChunk($uploadKey, (int)$row['org_id'], $this->actor(), $index, $tmp);
		} finally {
			@unlink($tmp);
		}
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
		$eingang = $this->docs->ensureSubFolder($this->orgFolder($orgId), DocumentService::DIR_INBOX);
		$res = $this->chunkSvc()->finish($uploadKey, $orgId, $this->actor());
		try {
			$this->uploadService->validateFile($res['tmp'], (string)$row['file_name'], $this->uploadService->maxBytes());
			$stored = $this->uploadService->store($eingang, $orgId, $this->actor(), $res['tmp'], (string)$row['file_name'], 'app', null);
		} finally {
			@unlink($res['tmp']);
		}
		$this->chunkSvc()->complete($uploadKey);
		$this->notifyService->clientMail($orgId, 'document');
		return $this->ok(['id' => (int)$stored['upload']->getId(), 'duplicate' => $stored['duplicate'] !== null], 201);
	}

	private function chunkSvc(): ChunkUploadService
	{
		return \OCP\Server::get(ChunkUploadService::class);
	}

	// ── documents ────────────────────────────────────────────────────

	#[PublicPage] #[NoCSRFRequired]
	public function documents(int $orgId): JSONResponse
	{
		$folder = $this->orgFolder($orgId);
		$dir = $this->docs->subFolder($folder, DocumentService::DIR_DOCS);
		$files = [];
		$folders = [];
		if ($dir !== null) {
			$base = rtrim($dir->getPath(), '/') . '/';
			$rel = '/' . ltrim((string)$this->request->getParam('path', ''), '/');
			$target = $dir;
			if ($rel !== '/' && $rel !== '') {
				try {
					$node = $dir->get(ltrim($rel, '/'));
					if ($node instanceof \OCP\Files\Folder && $this->docs->inside($dir, $node)) {
						$target = $node;
					}
				} catch (\OCP\Files\NotFoundException) {
					throw new NotFoundException();
				}
			}
			foreach ($target->getDirectoryListing() as $node) {
				if ($node instanceof \OCP\Files\Folder) {
					$folders[] = ['name' => $node->getName(), 'path' => substr(rtrim($node->getPath(), '/') . '/', strlen($base))];
				} elseif ($node instanceof \OCP\Files\File) {
					$files[] = ['id' => (int)$node->getId(), 'name' => $node->getName(), 'size' => $node->getSize(), 'mtime' => $node->getMTime(), 'mime' => $node->getMimeType()];
				}
			}
		}
		return $this->ok(['folders' => $folders, 'files' => $files]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function download(int $orgId, int $fileId): Response
	{
		$this->companionOrg($orgId);
		$node = $this->docs->documentNodeForOrg($orgId, $fileId);
		$stream = $node->fopen('r');
		if (!is_resource($stream)) {
			throw new ApiException(500, 'READ_FAILED', 'cannot read file');
		}
		$resp = new \OCP\AppFramework\Http\StreamResponse($stream);
		$resp->addHeader('Content-Disposition', 'attachment; filename="' . SafeFilenameService::sanitizeForContentDisposition($node->getName()) . '"');
		$resp->addHeader('X-Content-Type-Options', 'nosniff');
		$resp->addHeader('Content-Type', 'application/octet-stream');
		return $resp;
	}

	// ── approvals ────────────────────────────────────────────────────

	#[PublicPage] #[NoCSRFRequired]
	public function approvals(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
		$out = array_map(function ($a) {
			$this->approvals->checkVoid($a);
			return ['id' => (int)$a->getId(), 'fileId' => (int)$a->getFileId(), 'fileName' => $a->getFileName(),
				'state' => $a->getState(), 'deadline' => $a->getDeadline()?->format('Y-m-d'), 'note' => $a->getNote()];
		}, $this->approvalMapper->findByOrg($orgId));
		return $this->ok(['approvals' => $out]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function approvalStart(int $id): JSONResponse
	{
		$orgId = $this->orgOfApproval($id);
		$this->approvals->mustGetInOrg($id, $orgId);
		$res = $this->approvals->start($id, $this->identity()->getEmail(), $this->request->getRemoteAddress() ?: '');
		try {
			$this->outbox->enqueue($this->identity()->getEmail(), 'approve',
				'approve:' . $id . ':' . hash('sha256', $res['code']),
				['code' => $res['code'], 'provider' => $this->settings->get('provider_label')], 'de');
			$this->outbox->drain(3);
		} catch (\Throwable $e) {
			\OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning('portalcheck companion approval OTP mail enqueue failed', ['approval' => $id, 'e' => $e->getMessage()]);
		}
		return $this->ok(['sent' => true], 202);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function approvalDecide(int $id): JSONResponse
	{
		$orgId = $this->orgOfApproval($id);
		$a = $this->approvals->decide($this->actor(), $this->identity()->getEmail(), $id, $orgId,
			(string)$this->request->getParam('decision', ''),
			$this->request->getParam('code') !== null ? (string)$this->request->getParam('code') : null,
			$this->request->getParam('reason') !== null ? (string)$this->request->getParam('reason') : null);
		return $this->ok(['state' => $a->getState()]);
	}

	// ── devices / notify / intake / integrations ─────────────────────

	#[PublicPage] #[NoCSRFRequired]
	public function devices(): JSONResponse
	{
		$this->identity();
		$out = array_map(fn($d) => ['id' => (int)$d->getId(), 'name' => $d->getDeviceName(), 'platform' => $d->getPlatform(),
			'pairedAt' => $d->getCreatedAt()->format('c'), 'lastUsedAt' => $d->getLastUsedAt()->format('c'),
			'current' => (int)$d->getId() === (int)$this->companionDevice->getId()],
			$this->deviceMapper->findByIdentity((int)$this->companionIdentity->getId()));
		return $this->ok(['devices' => $out]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function revokeDevice(int $id): JSONResponse
	{
		$this->identity();
		if (!$this->devices->revoke($id, (int)$this->companionIdentity->getId())) {
			throw new NotFoundException();
		}
		return new JSONResponse(null, 204);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function revokeAllDevices(): JSONResponse
	{
		$this->identity();
		$this->deviceMapper->revokeForIdentity((int)$this->companionIdentity->getId());
		return new JSONResponse(null, 204);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function notifyPut(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
		$enabled = $this->request->getParam('mailsEnabled');
		$this->notify->setMailsEnabled((int)$this->companionIdentity->getId(), $orgId, $enabled === true || $enabled === '1' || $enabled === 'true');
		return new JSONResponse(null, 204);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function notifyGet(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
		$row = $this->notify->find((int)$this->companionIdentity->getId(), $orgId);
		return $this->ok(['mailsEnabled' => $row === null || (int)$row['mails_enabled'] === 1]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function intake(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
		$address = $this->intake->addressFor($orgId);
		return $this->ok(['enabled' => $address !== null, 'address' => $address]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function invoices(int $orgId): JSONResponse
	{
		$org = $this->companionOrg($orgId);
		if (!$this->integrations->invoiceAvailable() || $org->getPcCustId() === null) {
			throw new NotFoundException();
		}
		return $this->ok(['invoices' => $this->integrations->invoicesForOrg((int)$org->getPcCustId())]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function contact(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
		$out = [];
		foreach ($this->contacts->findForOrg($orgId) as $c) {
			$user = $this->userManager->get((string)$c['user_id']);
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

	// ── forms ────────────────────────────────────────────────────────

	private function formOrg(int $formId): int
	{
		$form = $this->forms->find($formId);
		if ($form === null) {
			throw new NotFoundException();
		}
		$this->companionOrg((int)$form->getOrgId());
		return (int)$form->getOrgId();
	}

	#[PublicPage] #[NoCSRFRequired]
	public function form(int $formId): JSONResponse
	{
		$this->formOrg($formId);
		$form = $this->forms->find($formId);
		if ($form === null) {
			throw new NotFoundException();
		}
		$tplMapper = \OCP\Server::get(\OCA\PortalCheck\Db\FormTemplateMapper::class);
		$tpl = $tplMapper->find((int)$form->getTplId());
		$answers = \OCP\Server::get(\OCA\PortalCheck\Db\FormAnswerMapper::class)->allForForm($formId);
		return $this->ok(['form' => [
			'id' => $formId, 'state' => $form->getState(),
			'name' => $tpl?->getName() ?? '',
			'fields' => json_decode((string)($tpl?->getFieldsJson() ?? '[]'), true) ?: [],
			'answers' => array_map(fn($k, $v) => ['key' => $k, 'value' => $v['value'], 'revision' => $v['revision'], 'author' => $v['updated_by']], array_keys($answers), $answers),
		]]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function formAnswer(int $formId): JSONResponse
	{
		$orgId = $this->formOrg($formId);
		$r = $this->formService->saveAnswer($this->actor(), $formId, $orgId,
			(string)$this->request->getParam('field', ''),
			(string)$this->request->getParam('value', ''),
			(int)$this->request->getParam('revision', 0));
		return $this->ok($r);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function formSubmit(int $formId): JSONResponse
	{
		$orgId = $this->formOrg($formId);
		$this->formService->submit($this->actor(), $formId, $orgId);
		$form = $this->forms->find($formId);
		if ($form !== null && $form->getReqItemId() !== null) {
			try {
				$item = $this->items->find((int)$form->getReqItemId());
				if ($item !== null) {
					$this->requests->deliverItem($this->actor(), (int)$item->getReqId(), (int)$item->getId());
				}
			} catch (\Throwable $e) {
				\OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning('portalcheck companion form submit auto-deliver failed', ['form' => $formId, 'e' => $e->getMessage()]);
			}
		}
		return new JSONResponse(null, 204);
	}

	// ── me ───────────────────────────────────────────────────────────

	#[PublicPage] #[NoCSRFRequired]
	public function me(): JSONResponse
	{
		$i = $this->identity();
		$devices = array_map(fn($d) => ['id' => (int)$d->getId(), 'name' => $d->getDeviceName(), 'platform' => $d->getPlatform(), 'current' => (int)$d->getId() === (int)$this->companionDevice->getId()], $this->deviceMapper->findByIdentity((int)$i->getId()));
		return $this->ok(['identity' => ['id' => (int)$i->getId(), 'displayName' => $i->getDisplayName()], 'devices' => $devices]);
	}

	private function orgOfRequest(int $reqId): int
	{
		$r = $this->requestMapper->find($reqId);
		if ($r === null) {
			throw new NotFoundException();
		}
		$this->companionOrg((int)$r->getOrgId());
		return (int)$r->getOrgId();
	}

	private function orgOfApproval(int $id): int
	{
		$a = $this->approvalMapper->find($id);
		if ($a === null) {
			throw new NotFoundException();
		}
		$this->companionOrg((int)$a->getOrgId());
		return (int)$a->getOrgId();
	}
}
