<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Controller;

use OCA\KanzleiCheck\AppInfo\Application;
use OCA\KanzleiCheck\ConfigTransfer\TransferReceiptStore;
use OCA\KanzleiCheck\Service\AccessDoorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

/** Staff app shell — access door enforced by StaffAccessMiddleware. */
class PageController extends Controller implements \OCA\KanzleiCheck\Contracts\StaffContextAware
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
		private readonly TransferReceiptStore $transferReceipts,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
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
		// 'transfer' is a dedicated server-rendered page (synced pack part),
		// not a card inside the settings SPA — app admins only.
		if ($section === 'transfer' && $this->staffIsAdmin) {
			$uid = $this->staffUid !== ''
				? $this->staffUid
				: (string)($this->userSession->getUser()?->getUID() ?? '');
			Util::addTranslations(Application::APP_ID);
			return new TemplateResponse(Application::APP_ID, 'transfer', [
				'appId' => Application::APP_ID,
				'isSystemAdmin' => $uid !== '' && $this->groupManager->isAdmin($uid),
				'transferLastReceipt' => $this->transferReceipts->lastReceipt(),
			]);
		}
		if ($section === 'about') {
			Util::addTranslations(Application::APP_ID);
			return new TemplateResponse(Application::APP_ID, 'about', ['appId' => Application::APP_ID]);
		}
		return $this->index();
	}

	/** Print-proof page (US-P23): hash-bound decision record, printable. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function approvalProof(int $id): TemplateResponse
	{
		$a = \OCP\Server::get(\OCA\KanzleiCheck\Db\ApprovalMapper::class)->find($id);
		if ($a === null) {
			throw new \OCA\KanzleiCheck\Exception\NotFoundException();
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
