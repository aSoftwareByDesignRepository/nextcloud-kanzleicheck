<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Middleware;

use OCA\KanzleiCheck\AppInfo\Application;
use OCA\KanzleiCheck\Exception\DeviceRevokedException;
use OCA\KanzleiCheck\Service\DeviceService;
use OCA\KanzleiCheck\Service\IdentityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Companion device auth (AF-03): X-KanzleiCheck-Device header → identity.
 * Never Authorization (NC core claims that channel). Open: /pair + /bootstrap.
 */
class DeviceAuthMiddleware extends Middleware
{
	public const HEADER = 'X-KanzleiCheck-Device';
	private const OPEN = ['/api/companion/v1/pair', '/api/companion/v1/bootstrap'];

	public function __construct(
		private readonly IRequest $request,
		private readonly DeviceService $devices,
		private readonly IdentityService $identities,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		if (!str_starts_with(get_class($controller), 'OCA\\KanzleiCheck\\')) {
			return;
		}
		$rest = $this->restPath();
		if ($rest === null || !str_starts_with($rest, '/api/companion/')) {
			return;
		}
		$open = in_array($rest, self::OPEN, true);
		$token = $this->request->getHeader(self::HEADER);
		if ($open && $token === '') {
			return;
		}
		$device = $token !== '' ? $this->devices->resolve($token) : null;
		if ($device === null) {
			if ($open) {
				return; // soft-auth: bogus/absent token on open routes → anonymous bootstrap
			}
			throw new DeviceRevokedException();
		}
		$identity = $this->identities->activeById((int)$device->getIdentityId());
		if ($identity === null) {
			if ($open) {
				return;
			}
			throw new DeviceRevokedException();
		}
		if ($controller instanceof \OCA\KanzleiCheck\Contracts\CompanionContextAware) {
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
