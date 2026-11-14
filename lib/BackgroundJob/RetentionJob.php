<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\BackgroundJob;

use OCA\KanzleiCheck\Db\AuditMapper;
use OCA\KanzleiCheck\Db\DeviceMapper;
use OCA\KanzleiCheck\Db\IntakeHoldMapper;
use OCA\KanzleiCheck\Db\MailOutboxMapper;
use OCA\KanzleiCheck\Db\OtpMapper;
use OCA\KanzleiCheck\Db\PortalSessionMapper;
use OCA\KanzleiCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Daily retention (ZF-08): OTP 24h · sessions/devices 30d past expiry · outbox sent 30d/failed 90d · holds 90d · audit 3y. */
class RetentionJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly OtpMapper $otps,
		private readonly PortalSessionMapper $sessions,
		private readonly DeviceMapper $devices,
		private readonly MailOutboxMapper $outbox,
		private readonly IntakeHoldMapper $holds,
		private readonly AuditMapper $audits,
		private readonly SettingsService $settings,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
	}

	protected function run($argument): void
	{
		$now = new \DateTimeImmutable();
		$this->otps->deleteOlderThan($now->modify('-24 hours'));
		$this->sessions->deleteExpired($now->modify('-30 days'));
		$this->devices->deleteExpired($now->modify('-30 days'));
		$this->outbox->deleteSentBefore($now->modify('-30 days'));
		$this->outbox->deleteFailedBefore($now->modify('-90 days'));
		$this->holds->deleteBefore($now->modify('-90 days'));
		$auditDays = max(365, $this->settings->getInt('audit_keep_days', 1095));
		$this->audits->deleteBefore($now->modify('-' . $auditDays . ' days'));
	}
}
