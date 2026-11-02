<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Tests\Unit;

use OCA\PortalCheck\Service\RequestService;
use PHPUnit\Framework\TestCase;

/** Reminder policy parsing "-3,0,+3x5" + due-date expansion. */
class PolicyTest extends TestCase
{
	public function testParseDefault(): void
	{
		[$offsets, $step, $max] = RequestService::parsePolicy('-3,0,+3x5');
		$this->assertSame([-3, 0], $offsets);
		$this->assertSame(3, $step);
		$this->assertSame(5, $max);
	}

	public function testParseClampsBounds(): void
	{
		[, $step, $max] = RequestService::parsePolicy('+99x99');
		$this->assertSame(30, $step);
		$this->assertSame(RequestService::MAX_REMINDERS, $max);
	}

	public function testGarbageYieldsEmptyOffsets(): void
	{
		[$offsets] = RequestService::parsePolicy('nonsense');
		$this->assertSame([], $offsets);
	}
}
