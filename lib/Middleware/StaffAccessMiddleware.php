<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Middleware;

use OCA\KanzleiCheck\AppInfo\Application;
use OCA\KanzleiCheck\Exception\ForbiddenException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Service\AccessDoorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Staff door (NN-P01): every non-public route requires an NC session AND
 * admission by the access door. Non-admitted users get the access-denied
 * page on the UI route and FORBIDDEN on APIs — never client data.
 */
class StaffAccessMiddleware extends Middleware
{
	public function __construct(
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AccessDoorService $door,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		if (!str_starts_with(get_class($controller), 'OCA\\KanzleiCheck\\')) {
			return;
		}
		$rest = $this->restPath();
		if ($rest === null || str_starts_with($rest, '/portal') || str_starts_with($rest, '/api/companion')) {
			return; // public client surface + companion gate handle themselves
		}
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '' || !$this->door->isStaff($uid)) {
			throw new ForbiddenException();
		}
		if ($controller instanceof \OCA\KanzleiCheck\Contracts\StaffContextAware) {
			$controller->setStaffContext($uid, $this->door->isAppAdmin($uid));
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
