<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Service\IntakeMailService;
use OCA\KanzleiCheck\Support\MailToText;
use PHPUnit\Framework\TestCase;

/**
 * Intake mail body readability — same bug class as ticketcheck issue #11:
 * raw MIME soup, style/script source, glued lines, mojibake.
 */
class MailToTextTest extends TestCase
{
	public function testHtmlToTextDropsStyleScriptAndKeepsBreaks(): void
	{
		$out = MailToText::htmlToText(
			'<html><head><style>body{font-family:Arial}</style></head>'
			. '<body><p>Hallo<br>Team</p><script>alert(1)</script></body></html>'
		);
		self::assertStringContainsString("Hallo\nTeam", $out);
		self::assertStringNotContainsString('font-family', $out);
		self::assertStringNotContainsString('alert', $out);
	}

	public function testDecodePartHonorsDeclaredCharset(): void
	{
		// ISO-8859-15 euro sign
		$out = MailToText::decodePart("Preis " . chr(0xA4), 1, 'ISO-8859-15');
		self::assertSame('Preis €', $out);
	}

	public function testDecodePartBase64Utf8(): void
	{
		self::assertSame('Grüße', MailToText::decodePart(base64_encode('Grüße'), 3, 'utf-8'));
	}

	public function testDecodePartQuotedPrintable(): void
	{
		self::assertSame('Grüße', MailToText::decodePart(quoted_printable_encode('Grüße'), 4, 'utf-8'));
	}

	public function testLooksLikeHtml(): void
	{
		self::assertTrue(MailToText::looksLikeHtml('<div>x</div>'));
		self::assertFalse(MailToText::looksLikeHtml('a < b'));
	}

	public function testTextLeavesPicksPlainAndSkipsAttachments(): void
	{
		$svc = $this->newService();
		$plain = (object)['type' => 0, 'subtype' => 'PLAIN', 'encoding' => 0, 'parameters' => []];
		$html = (object)['type' => 0, 'subtype' => 'HTML', 'encoding' => 0, 'parameters' => []];
		$attach = (object)['type' => 0, 'subtype' => 'HTML', 'encoding' => 3, 'ifdisposition' => 1, 'disposition' => 'attachment', 'dparameters' => [(object)['attribute' => 'filename', 'value' => 'x.html']], 'parameters' => []];
		$alt = (object)['type' => 1, 'subtype' => 'ALTERNATIVE', 'parts' => [$plain, $html]];
		$root = (object)['type' => 1, 'subtype' => 'MIXED', 'parts' => [$alt, $attach]];

		$leaves = $this->invokeLeaves($svc, $root);
		self::assertCount(2, $leaves);
		self::assertSame('1.1', $leaves[0][0]);
		self::assertSame('1.2', $leaves[1][0]);
	}

	public function testTextLeavesSinglePart(): void
	{
		$svc = $this->newService();
		$root = (object)['type' => 0, 'subtype' => 'PLAIN', 'encoding' => 0, 'parameters' => []];
		$leaves = $this->invokeLeaves($svc, $root);
		self::assertCount(1, $leaves);
		self::assertSame('', $leaves[0][0]);
	}

	private function newService(): IntakeMailService
	{
		$ref = new \ReflectionClass(IntakeMailService::class);
		return $ref->newInstanceWithoutConstructor();
	}

	/** @return list<array{0:string,1:object}> */
	private function invokeLeaves(IntakeMailService $svc, object $structure): array
	{
		$m = new \ReflectionMethod(IntakeMailService::class, 'textLeaves');
		return $m->invoke($svc, $structure, '');
	}
}
