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
	) {
	}

	public function snapshot(): array
	{
		$lastRun = $this->cursors->lastRun('scheduler');
		return [
			'cron' => ['lastRun' => $lastRun?->format('c'), 'stale' => $lastRun === null || $lastRun < (new \DateTimeImmutable())->modify('-2 hours')],
			'outbox' => ['pending' => $this->outbox->pendingCount(), 'failed' => $this->outbox->failedCount()],
			'docsRoot' => ['configured' => $this->settings->get('docs_root_id') !== '', 'healthy' => $this->docs->rootHealthy()],
			'license' => ['status' => $this->license->status(), 'portalClients' => $this->license->portalClientsTotal()],
			'mail' => ['configured' => true],
		];
	}
}
