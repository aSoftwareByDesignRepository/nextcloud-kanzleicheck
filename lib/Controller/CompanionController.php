<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Controller;

use OCA\PortalCheck\AppInfo\Application;
use OCA\PortalCheck\Db\DeviceMapper;
use OCA\PortalCheck\Exception\ApiException;
use OCA\PortalCheck\Exception\NotFoundException;
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
