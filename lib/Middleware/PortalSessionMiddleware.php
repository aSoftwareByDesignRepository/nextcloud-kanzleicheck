<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Middleware;

use OCA\KanzleiCheck\AppInfo\Application;
use OCA\KanzleiCheck\Exception\UnauthorizedException;
use OCA\KanzleiCheck\Service\IdentityService;
use OCA\KanzleiCheck\Service\PortalSessionService;
use OCA\KanzleiCheck\Service\CsrfService;
use OCA\KanzleiCheck\Service\ActorResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Client portal gate: /portal/api/** requires the kzc_session cookie →
 * portal identity + org membership context. Open paths: auth endpoints,
 * magic GET page, upload-link page/api (token-authed separately).
 * CSRF: non-GET requests must present X-KZC-CSRF bound to the session (AF-02).
 */
class PortalSessionMiddleware extends Middleware
{
	public const SESSION_COOKIE = 'kzc_session';
	private const OPEN_PREFIX = [
		'/portal/api/auth/send', '/portal/api/auth/verify', '/portal/auth/magic/',
		'/portal/login', '/portal/u/', '/portal/api/u/',
	];

	public function __construct(
		private readonly IRequest $request,
		private readonly PortalSessionService $sessions,
		private readonly IdentityService $identities,
		private readonly CsrfService $csrf,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		if (!str_starts_with(get_class($controller), 'OCA\\KanzleiCheck\\')) {
			return;
		}
		$rest = $this->restPath();
		if ($rest === null || !str_starts_with($rest, '/portal')) {
			return;
		}
		if ($rest === '/portal' || $rest === '/portal/') {
			return; // guest shell — JS drives the login flow on 401
		}
		foreach (self::OPEN_PREFIX as $p) {
			if (str_starts_with($rest, $p)) {
				return;
			}
		}
		$token = (string)$this->request->getCookie(self::SESSION_COOKIE);
		$session = $token !== '' ? $this->sessions->resolve($token) : null;
		if ($session === null) {
			throw new UnauthorizedException();
		}
		$identity = $this->identities->activeById((int)$session->getIdentityId());
		if ($identity === null) {
			throw new UnauthorizedException();
		}
		if ($this->request->getMethod() !== 'GET') {
			$body = $this->request->getParam('kzc_csrf', '');
			$presented = $this->request->getHeader(CsrfService::HEADER)
				?: (is_string($body) ? $body : '');
			if (!$this->csrf->validate($token, $presented)) {
				throw new UnauthorizedException('CSRF');
			}
		}
		if ($controller instanceof \OCA\KanzleiCheck\Contracts\PortalSessionAware) {
			$controller->setPortalIdentity($identity, $token);
		}
	}

	private function restPath(): ?string
	{
		$path = $this->request->getPathInfo() ?: '';
		foreach (['/index.php/apps/' . Application::APP_ID, '/apps/' . Application::APP_ID] as $p) {
			if (str_starts_with($path, $p)) {
				return substr($path, strlen($p));
			}
		}
		return null;
	}
}
