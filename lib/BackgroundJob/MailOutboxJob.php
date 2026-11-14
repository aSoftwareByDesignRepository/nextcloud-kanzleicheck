<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\BackgroundJob;

use OCA\KanzleiCheck\Service\MailOutboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Outbox drain — every 5 min; OTP mails additionally drain post-response (ZF-04/09). */
class MailOutboxJob extends TimedJob
{
	public function __construct(ITimeFactory $time, private readonly MailOutboxService $outbox)
	{
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void
	{
		$this->outbox->drain(50);
	}
}
