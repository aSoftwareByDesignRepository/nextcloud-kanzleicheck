<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Tests\Unit;

use OCA\PortalCheck\Service\SafeFilenameService;
use PHPUnit\Framework\TestCase;

class SafeFilenameTest extends TestCase
{
	public function testTraversalStripped(): void
	{
		$this->assertSame('evil.php', SafeFilenameService::sanitize('../../evil.php'));
		$this->assertSame('evil.php', SafeFilenameService::sanitize('..\\..\\evil.php'));
	}

	public function testNullAndEmpty(): void
	{
		$this->assertSame('upload', SafeFilenameService::sanitize(null));
		$this->assertSame('upload', SafeFilenameService::sanitize(''));
		$this->assertSame('upload', SafeFilenameService::sanitize('.'));
	}

	public function testUtf8Preserved(): void
	{
		$this->assertSame('Rechnung Müller-2026.pdf', SafeFilenameService::sanitize('Rechnung Müller-2026.pdf'));
	}

	public function testLongNamesTruncated(): void
	{
		$this->assertLessThanOrEqual(255, mb_strlen(SafeFilenameService::sanitize(str_repeat('a', 300) . '.pdf')));
	}
}
