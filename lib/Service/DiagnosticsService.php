<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\Db\JobCursorMapper;
use OCA\PortalCheck\Db\MailOutboxMapper;
use OCP\Mail\IMailer;

/** Admin "Portal-Diagnose" data: mail test, outbox, cron age, docs root (§6). */
class DiagnosticsService
{
	public function __construct(
		private readonly JobCursorMapper $cursors,
		private readonly MailOutboxMapper $outbox,
		private readonly SettingsService $settings,
		private readonly DocumentService $docs,
		private readonly LicenseService $license,
		private readonly ?SyncBindingService $syncBindings = null,
		private readonly ?AccessDoorService $door = null,
	) {
	}

	public function snapshot(): array
	{
		$lastRun = $this->cursors->lastRun('scheduler');
		$snap = [
			'cron' => ['lastRun' => $lastRun?->format('c'), 'stale' => $lastRun === null || $lastRun < (new \DateTimeImmutable())->modify('-2 hours')],
			'outbox' => ['pending' => $this->outbox->pendingCount(), 'failed' => $this->outbox->failedCount()],
			'docsRoot' => ['configured' => $this->settings->get('docs_root_id') !== '', 'healthy' => $this->docs->rootHealthy()],
			'license' => ['status' => $this->license->status(), 'portalClients' => $this->license->portalClientsTotal()],
			'mail' => ['configured' => true],
		];
		if ($this->syncBindings !== null && $this->door !== null) {
			// US-S09: door × bindings consistency in one glance.
			// staffBound > 0 is a FAIL finding — a bound client uid must never
			// be door-admitted; doorOpen with 0 bindings is WARN (sync disabled).
			$s = $this->syncBindings->diagnosticsSnapshot();
			$level = 'ok';
			if ($s['staffBound'] > 0) {
				$level = 'fail';
			} elseif ($s['broken'] > 0 || ($s['doorOpen'] && $s['bindings'] === 0)) {
				$level = 'warn';
			}
			$snap['sync'] = $s + ['level' => $level];
		}
		return $snap;
	}
}
