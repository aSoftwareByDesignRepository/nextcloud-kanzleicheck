<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Middleware;

use OCA\PortalCheck\AppInfo\Application;
use OCA\PortalCheck\Exception\UnauthorizedException;
use OCA\PortalCheck\Service\IdentityService;
use OCA\PortalCheck\Service\PortalSessionService;
use OCA\PortalCheck\Service\CsrfService;
use OCA\PortalCheck\Service\ActorResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Client portal gate: /portal/api/** requires the ptl_session cookie →
 * portal identity + org membership context. Open paths: auth endpoints,
 * magic GET page, upload-link page/api (token-authed separately).
 * CSRF: non-GET requests must present X-PTL-CSRF bound to the session (AF-02).
 */
class PortalSessionMiddleware extends Middleware
{
	public const SESSION_COOKIE = 'ptl_session';
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
		if (!str_starts_with(get_class($controller), 'OCA\\PortalCheck\\')) {
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
			$presented = $this->request->getHeader(CsrfService::HEADER) ?: (string)$this->request->getParam('ptl_csrf', '');
			if (!$this->csrf->validate($token, $presented)) {
				throw new UnauthorizedException('CSRF');
			}
		}
		if ($controller instanceof \OCA\PortalCheck\Contracts\PortalSessionAware) {
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
