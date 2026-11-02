<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Middleware;

use OCA\PortalCheck\AppInfo\Application;
use OCA\PortalCheck\Exception\DeviceRevokedException;
use OCA\PortalCheck\Service\DeviceService;
use OCA\PortalCheck\Service\IdentityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Companion device auth (AF-03): X-PortalCheck-Device header → identity.
 * Never Authorization (NC core claims that channel). Open: /pair + /bootstrap.
 */
class DeviceAuthMiddleware extends Middleware
{
	public const HEADER = 'X-PortalCheck-Device';
	private const OPEN = ['/api/companion/v1/pair', '/api/companion/v1/bootstrap'];

	public function __construct(
		private readonly IRequest $request,
		private readonly DeviceService $devices,
		private readonly IdentityService $identities,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		if (!str_starts_with(get_class($controller), 'OCA\\PortalCheck\\')) {
			return;
		}
		$rest = $this->restPath();
		if ($rest === null || !str_starts_with($rest, '/api/companion/')) {
			return;
		}
		if (in_array($rest, self::OPEN, true)) {
			return;
		}
		$token = $this->request->getHeader(self::HEADER);
		$device = $token !== '' ? $this->devices->resolve($token) : null;
		if ($device === null) {
			throw new DeviceRevokedException();
		}
		$identity = $this->identities->activeById((int)$device->getIdentityId());
		if ($identity === null) {
			throw new DeviceRevokedException();
		}
		if ($controller instanceof \OCA\PortalCheck\Contracts\CompanionContextAware) {
			$controller->setCompanionContext($identity, $device);
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
