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
use OCA\PortalCheck\Db\ReqItemMapper;
use OCA\PortalCheck\Db\TaskMapper;
use OCA\PortalCheck\Exception\ApiException;
use OCA\PortalCheck\Exception\NotFoundException;
use OCA\PortalCheck\Service\ApprovalService;
use OCA\PortalCheck\Service\ChatService;
use OCA\PortalCheck\Service\DeviceService;
use OCA\PortalCheck\Service\IdentityService;
use OCA\PortalCheck\Service\LicenseService;
use OCA\PortalCheck\Service\MailOutboxService;
use OCA\PortalCheck\Service\OtpService;
use OCA\PortalCheck\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Companion API (/api/companion/v1/*). Pair is open (email+code); every
 * other route needs X-PortalCheck-Device → identity via middleware.
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
		private readonly \OCA\PortalCheck\Service\RequestService $requests,
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

	private function companionOrg(int $orgId): \OCA\PortalCheck\Db\Org
	{
		if ($this->companionIdentity === null) {
			throw new \OCA\PortalCheck\Exception\UnauthorizedException();
		}
		$identityId = (int)$this->companionIdentity->getId();
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
		return \OCA\PortalCheck\Service\ActorResolver::portal((int)$this->companionIdentity->getId());
	}

	#[PublicPage] #[NoCSRFRequired]
	public function orgs(): JSONResponse
	{
		$this->requireIdentity();
		$out = [];
		foreach ($this->identities->orgIdsFor((int)$this->companionIdentity->getId()) as $oid) {
			$org = $this->orgs->find($oid);
			if ($org === null || $org->getState() !== 'active') {
				continue;
			}
			$conv = $this->convs->findByOrg($oid);
			$out[] = ['id' => $oid, 'name' => $org->getName(),
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
		return $this->ok(['messages' => $msgs]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function sendMessage(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
		$m = $this->chat->post($orgId, $this->actor(), 'client',
			(string)$this->request->getParam('body', ''),
			(string)$this->request->getParam('clientUuid', '') ?: null,
			$this->strictId($this->request->getParam('uploadId')));
		return $this->ok(['message' => ['id' => (int)$m->getId()]], 201);
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
						'title' => $i->getLabel(), 'deadline' => $r->getDeadline()?->format('Y-m-d'),
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
		return $this->ok(['todo' => $todo]);
	}

	#[PublicPage] #[NoCSRFRequired]
	public function tasks(int $orgId): JSONResponse
	{
		$this->companionOrg($orgId);
		$out = array_map(fn($t) => ['id' => (int)$t->getId(), 'title' => $t->getTitle(),
			'status' => $t->getStatus(), 'dueDate' => $t->getDueDate()?->format('Y-m-d'),
			'done' => $t->getDoneAt() !== null],
			$this->taskMapper->findVisibleForOrg($orgId));
		return $this->ok(['tasks' => $out]);
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
	public function me(): JSONResponse
	{
		$this->requireIdentity();
		$i = $this->companionIdentity;
		$devices = array_map(fn($d) => ['id' => (int)$d->getId(), 'name' => $d->getDeviceName(), 'platform' => $d->getPlatform(), 'current' => (int)$d->getId() === (int)$this->companionDevice->getId()], $this->deviceMapper->findByIdentity((int)$i->getId()));
		return $this->ok(['identity' => ['id' => (int)$i->getId(), 'displayName' => $i->getDisplayName()], 'devices' => $devices]);
	}

	private function requireIdentity(): void
	{
		if ($this->companionIdentity === null) {
			throw new \OCA\PortalCheck\Exception\UnauthorizedException();
		}
	}


	/** Bootstrap always answers — role/capabilities/licence envelope (never gated). */
	#[PublicPage] #[NoCSRFRequired]
	public function bootstrap(): JSONResponse
	{
		$orgs = [];
		if ($this->companionIdentity !== null) {
			foreach ($this->identities->orgIdsFor((int)$this->companionIdentity->getId()) as $oid) {
				$orgs[] = ['id' => $oid, 'appEnabled' => true];
			}
		}
		return $this->ok([
			'role' => $this->companionIdentity !== null ? 'client' : 'unknown',
			'capabilities' => ['portal' => true, 'portalcheck.companion.min' => 1],
			'pushAvailable' => false,
			'portal' => ['enabled' => true, 'orgs' => $orgs],
			'licensing' => ['status' => $this->license->status(), 'product' => 'portalcheck'],
			'server' => ['appVersion' => '0.1.0', 'companionMin' => 1],
		]);
	}
}
