<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Controller;

use OCA\KanzleiCheck\AppInfo\Application;
use OCA\KanzleiCheck\Db\MailOutboxMapper;
use OCA\KanzleiCheck\Db\OtpMapper;
use OCA\KanzleiCheck\Middleware\PortalSessionMiddleware;
use OCA\KanzleiCheck\Service\CsrfService;
use OCA\KanzleiCheck\Service\IdentityService;
use OCA\KanzleiCheck\Service\MailOutboxService;
use OCA\KanzleiCheck\Service\OtpService;
use OCA\KanzleiCheck\Service\PortalSessionService;
use OCA\KanzleiCheck\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Passwordless client auth (US-P01): send → uniform 202 always (AF-04
 * constant path: unknown e-mails get a discard outbox row, identical
 * response); verify → session cookie; magic → GET confirm page, POST
 * consumes (AF-01). Client login requires HTTPS (A-22).
 */
class PortalAuthController extends Controller
{
	use ApiTrait;

	public const SESSION_COOKIE = 'kzc_session';

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OtpService $otp,
		private readonly OtpMapper $otpMapper,
		private readonly PortalSessionService $sessions,
		private readonly IdentityService $identities,
		private readonly MailOutboxService $outbox,
		private readonly SettingsService $settings,
		private readonly IURLGenerator $urls,
		private readonly CsrfService $csrf,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 300)]
	public function send(): JSONResponse
	{
		$email = IdentityService::normalizeEmail($this->strParam('email', ''));
		$ip = $this->request->getRemoteAddress() ?: '';
		$identity = $email !== '' ? $this->identities->activeByEmail($email) : null;
		$deliver = $identity !== null && $this->identities->orgIdsFor((int)$identity->getId()) !== [];
		// 5/h per-email cap — response identical either way (no oracle)
		$underCap = $email !== '' && $this->otpMapper->countRecentForEmail($email, (new \DateTimeImmutable())->modify('-1 hour')) < 5;
		if ($deliver && $underCap) {
			[$code, $magic, ] = $this->otp->issue($email, OtpService::PURPOSE_LOGIN, $ip, true);
			$portalUrl = $this->urls->linkToRouteAbsolute('kanzleicheck.portalAuth.magicGet', ['token' => $magic]);
			$this->outbox->enqueue($email, 'otp', 'otp:' . $email . ':' . hash('sha256', $code),
				['code' => $code, 'portalUrl' => $portalUrl, 'provider' => $this->settings->get('provider_label')], 'de');
			$this->drainAsync();
		} else {
			// discard row — same code path, never delivered (AF-04)
			$this->outbox->enqueue($email !== '' ? $email : 'unknown@invalid', 'otp', 'otp-discard:' . bin2hex(random_bytes(16)), [], 'de', true);
		}
		return $this->ok(['sent' => true], 202);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function verify(): JSONResponse
	{
		$email = IdentityService::normalizeEmail($this->strParam('email', ''));
		$code = trim($this->strParam('code', ''));
		$https = $this->request->getServerProtocol() === 'https' || $this->settings->getBool('allow_http_login');
		if (!preg_match('/^\d{6}$/', $code) || !$https) {
			return $this->invalidCode();
		}
		$otpId = $this->otp->verify($email, OtpService::PURPOSE_LOGIN, $code);
		$identity = $otpId !== null ? $this->identities->activeByEmail($email) : null;
		if ($otpId === null || $identity === null) {
			return $this->invalidCode();
		}
		return $this->issueSessionResponse((int)$identity->getId(), '/portal');
	}

	private function invalidCode(): JSONResponse
	{
		return new JSONResponse(['success' => false, 'error' => 'INVALID_CODE', 'message' => 'The code is incorrect or has expired.', 'fields' => (object)[]], 400);
	}

	private function issueSessionResponse(int $identityId, string $redirect): JSONResponse
	{
		[$token, $session] = $this->sessions->create($identityId, (string)($this->request->getHeader('User-Agent') ?? ''));
		$resp = $this->ok(['redirect' => $redirect, 'csrf' => $this->csrf->tokenFor($token)]);
		// NC emits app cookies HttpOnly + Secure(on https) at render time.
		$resp->addCookie(self::SESSION_COOKIE, $token, null, 'Lax');
		$resp->addCookie(CsrfService::COOKIE_NAME, $this->csrf->tokenFor($token), null, 'Lax');
		return $resp;
	}

	/** Magic GET → confirm page (never consumes — AF-01). */
	#[PublicPage]
	#[NoCSRFRequired]
	public function magicGet(string $token): TemplateResponse
	{
		return new TemplateResponse(Application::APP_ID, 'portal/magic', ['token' => $token], 'guest');
	}

	/** Magic POST → consume + session cookie. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function magicPost(string $token): JSONResponse
	{
		$otp = $this->otp->consumeMagic($token);
		$identity = $otp !== null ? $this->identities->activeByEmail((string)$otp->getEmail()) : null;
		if ($otp === null || $identity === null || $otp->getPurpose() !== OtpService::PURPOSE_LOGIN) {
			throw new \OCA\KanzleiCheck\Exception\NotFoundException();
		}
		return $this->issueSessionResponse((int)$identity->getId(), '/portal');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function logout(): JSONResponse
	{
		$token = (string)$this->request->getCookie(self::SESSION_COOKIE);
		if ($token !== '') {
			$this->sessions->revoke($token);
		}
		$resp = new JSONResponse(null, 204);
		$resp->addCookie(self::SESSION_COOKIE, '', new \DateTime('@1'), 'Lax');
		return $resp;
	}

	/** Post-response immediate drain attempt for OTP mails (ZF-09). */
	private function drainAsync(): void
	{
		try {
			$this->outbox->drain(5);
		} catch (\Throwable $e) {
			\OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning('kanzleicheck auth OTP drain failed', ['e' => $e->getMessage()]);
		}
	}
}
