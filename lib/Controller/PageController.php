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

	/** Print-proof page (US-P23): hash-bound decision record, printable. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function approvalProof(int $id): TemplateResponse
	{
		$a = \OCP\Server::get(\OCA\PortalCheck\Db\ApprovalMapper::class)->find($id);
		if ($a === null) {
			throw new \OCA\PortalCheck\Exception\NotFoundException();
		}
		return new TemplateResponse(Application::APP_ID, 'staff/approval-proof', [
			'id' => (int)$a->getId(), 'fileName' => $a->getFileName(), 'sha' => $a->getFileSha256(),
			'state' => $a->getState(), 'requestedBy' => $a->getRequestedBy(),
			'requestedAt' => $a->getRequestedAt()->format('d.m.Y H:i'),
			'decidedBy' => $a->getDecidedBy(), 'decidedAt' => $a->getDecidedAt()?->format('d.m.Y H:i'),
			'method' => $a->getConfirmMethod(), 'reason' => $a->getReason(),
		]);
	}
}
