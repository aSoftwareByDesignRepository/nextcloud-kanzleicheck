<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Controller;

use OCA\PortalCheck\AppInfo\Application;
use OCA\PortalCheck\Service\AccessDoorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/** Staff app shell — access door enforced by StaffAccessMiddleware. */
class PageController extends Controller implements \OCA\PortalCheck\Contracts\StaffContextAware
{
	public string $staffUid = '';
	public bool $staffIsAdmin = false;

	public function setStaffContext(string $uid, bool $isAppAdmin): void
	{
		$this->staffUid = $uid;
		$this->staffIsAdmin = $isAppAdmin;
	}

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessDoorService $door,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return new TemplateResponse(Application::APP_ID, 'staff/main', ['appId' => Application::APP_ID]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(string $section = ''): TemplateResponse
	{
		return $this->index();
	}
}
