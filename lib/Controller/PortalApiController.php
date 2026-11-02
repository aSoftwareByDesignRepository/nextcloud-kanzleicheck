<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Controller;

use OCA\PortalCheck\AppInfo\Application;
use OCA\PortalCheck\Db\ApprovalMapper;
use OCA\PortalCheck\Db\ConversationMapper;
use OCA\PortalCheck\Db\DeviceMapper;
use OCA\PortalCheck\Db\DocRequestMapper;
use OCA\PortalCheck\Db\MessageMapper;
use OCA\PortalCheck\Db\OrgMapper;
use OCA\PortalCheck\Db\PortalFormMapper;
use OCA\PortalCheck\Db\ReqItemMapper;
use OCA\PortalCheck\Db\TaskMapper;
use OCA\PortalCheck\Db\UploadMapper;
use OCA\PortalCheck\Exception\NotFoundException;
use OCA\PortalCheck\Service\ActorResolver;
use OCA\PortalCheck\Service\ApprovalService;
use OCA\PortalCheck\Service\ChatService;
use OCA\PortalCheck\Service\DocumentService;
use OCA\PortalCheck\Service\IdentityService;
use OCA\PortalCheck\Service\IntakeService;
use OCA\PortalCheck\Db\NotifyMapper;
use OCA\PortalCheck\Service\MailOutboxService;
use OCA\PortalCheck\Service\NotifyService;
use OCA\PortalCheck\Service\OtpService;
use OCA\PortalCheck\Service\OrgService;
use OCA\PortalCheck\Service\PresetCatalog;
use OCA\PortalCheck\Service\RequestService;
use OCA\PortalCheck\Service\SettingsService;
use OCA\PortalCheck\Service\TaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Client portal API (/portal/api/**) — session-gated via
 * PortalSessionMiddleware which sets ->portalIdentity. Every org-scoped
 * route checks membership before touching data; every miss is uniform.
 */
class PortalApiController extends Controller implements \OCA\PortalCheck\Contracts\PortalSessionAware
{
	use ApiTrait;

	/** @var \OCA\PortalCheck\Db\Identity */
	public $portalIdentity;
	public ?string $portalSessionToken = null;

	public function setPortalIdentity(\OCA\PortalCheck\Db\Identity $identity, string $sessionToken): void
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
		private readonly \OCA\PortalCheck\Service\FormService $formService,
		private readonly MailOutboxService $mailOutbox,
	) {
		parent::__construct($appName, $request);
	}

	private function org(int $orgId): \OCA\PortalCheck\Db\Org
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
			$out[] = ['id' => $oid, 'name' => $org->getName(), 'unread' => $unread];
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
		return $this->ok(['enabled' => true, 'address' => $this->intake->addressFor($orgId)]);
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
					'title' => $i->getLabel(), 'deadline' => $r->getDeadline()?->format('Y-m-d'), 'overdue' => $this->requests->overdue($r)];
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
			$this->notifyService->staffNotify($uid, 'portal_message', $orgId, '/apps/' . Application::APP_ID . '/inbox?org=' . $orgId);
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
		} catch (\Throwable) {
		}
	}
}
