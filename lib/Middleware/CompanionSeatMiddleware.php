<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Middleware;

use OCA\KanzleiCheck\AppInfo\Application;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Service\LicenseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Companion staff seat gate (spec §9.5/9.7, TicketCheck CompanionGateService
 * pattern): staff API use through an explicit client credential — app
 * password / token (Basic or Bearer) — requires a licensed, assigned,
 * within-limit mobile seat.
 *
 * Discriminator is the credential, never a client flag: an Authorization
 * header marks companion-class auth; a plain cookie session is the web UI
 * and stays ungated forever (NN-P06). Runs AFTER StaffAccessMiddleware, so
 * non-staff gets 403 and never sees a payment code (no seat-state oracle).
 */
class CompanionSeatMiddleware extends Middleware
{
	public function __construct(
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
		private readonly LicenseService $license,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		if (!str_starts_with(get_class($controller), 'OCA\\KanzleiCheck\\')) {
			return;
		}
		$rest = $this->restPath();
		if ($rest === null || !str_starts_with($rest, '/api/portal/')) {
			return;
		}
		if (trim((string)$this->request->getHeader('Authorization')) === '') {
			return; // cookie session = web UI, never license-gated
		}
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			return; // no authenticated user — the door middleware answers
		}
		$gate = $this->license->seatGate($uid);
		if ($gate !== 'ok') {
			throw new ApiException(402, $gate, 'a licensed mobile seat is required');
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
