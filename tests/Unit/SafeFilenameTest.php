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

	public function testTruncationIsCharBasedNotByteBased(): void
	{
		// 200 multibyte chars fit under the 255-char cap — byte-based substr
		// would cut at ~127 chars and could mangle UTF-8 mid-sequence.
		$out = SafeFilenameService::sanitize(str_repeat('ä', 200) . '.pdf');
		$this->assertSame(204, mb_strlen($out));
		$this->assertStringEndsWith('.pdf', $out);
	}

	public function testUnderscoreOnlyNameFallsBackToDefault(): void
	{
		$this->assertSame('upload', SafeFilenameService::sanitize('___'));
		$this->assertSame('upload', SafeFilenameService::sanitize('_ _'));
		$this->assertSame('ok', SafeFilenameService::sanitize('_ok_'));
	}

	public function testWhitespaceOnlyNameFallsBackToDefault(): void
	{
		$this->assertSame('upload', SafeFilenameService::sanitize('   '));
	}

	public function testQuotesStripped(): void
	{
		$this->assertSame('its a file.txt', SafeFilenameService::sanitize('it\'s "a" file.txt'));
	}
}
