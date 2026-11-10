<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Tests\Unit;

use OCA\PortalCheck\Exception\ValidationException;
use OCA\PortalCheck\Service\ImportService;
use PHPUnit\Framework\TestCase;

class ImportServiceTest extends TestCase
{
	public function testSemicolonAutodetectAndBom(): void
	{
		$csv = "\xEF\xBB\xBForg_name;org_ref;email;display_name\nMuster GmbH;M-1;MAX@example.de;Max\n";
		$rows = ImportService::parse($csv);
		$this->assertCount(1, $rows);
		$this->assertSame('Muster GmbH', $rows[0]['org_name']);
		$this->assertSame('MAX@example.de', $rows[0]['email']);
	}

	public function testCommaDelimiter(): void
	{
		$rows = ImportService::parse("org_name,org_ref,email\nFirma,R-2,a@b.de\n");
		$this->assertSame('Firma', $rows[0]['org_name']);
	}

	public function testTooLargeRejected(): void
	{
		$csv = "org_name,email\n" . str_repeat("Org,a@b.de\n", ImportService::MAX_ROWS + 1);
		try {
			ImportService::parse($csv);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(['file' => 'IMPORT_TOO_LARGE'], $e->getFields());
		}
	}

	public function testExactlyMaxRowsAccepted(): void
	{
		$csv = "org_name,email\n" . str_repeat("Org,a@b.de\n", ImportService::MAX_ROWS);
		$rows = ImportService::parse($csv);
		$this->assertCount(ImportService::MAX_ROWS, $rows);
	}

	public function testAllDataRowsReturned(): void
	{
		$rows = ImportService::parse("org_name,email\nA,a@b.de\nB,b@b.de\nC,c@b.de\n");
		$this->assertCount(3, $rows);
		$this->assertSame(['A', 'B', 'C'], array_column($rows, 'org_name'));
	}

	public function testEqualDelimiterCountsPreferComma(): void
	{
		// one ';' and one ',' in the header → tie → ',' must win
		$rows = ImportService::parse("org_name;email,display_name\nMuster;m@x.de,Max\n");
		$this->assertCount(1, $rows);
		$this->assertSame('Muster;m@x.de', $rows[0]['org_name;email']);
		$this->assertSame('Max', $rows[0]['display_name']);
	}

	public function testHeaderNormalizedTrimLowercase(): void
	{
		$rows = ImportService::parse(" Org_Name , EMAIL \nFirma,x@y.de\n");
		$this->assertSame('Firma', $rows[0]['org_name']);
		$this->assertSame('x@y.de', $rows[0]['email']);
	}

	public function testDocumentedAliasHeadersResolveToCanonical(): void
	{
		// The UI documents "CSV (email;name;org;kind;ref)" — the shorthand
		// header row must produce the same canonical keys plan/apply read,
		// otherwise a documented file previews green then silently skips.
		$rows = ImportService::parse("email;name;org;kind;ref\nmax@example.de;Max;Muster GmbH;private;M-1\n");
		$this->assertCount(1, $rows);
		$this->assertSame('Muster GmbH', $rows[0]['org_name']);
		$this->assertSame('M-1', $rows[0]['org_ref']);
		$this->assertSame('Max', $rows[0]['display_name']);
		$this->assertSame('max@example.de', $rows[0]['email']);
		$this->assertSame('private', $rows[0]['kind']);
	}

	public function testRaggedRowsAligned(): void
	{
		// row shorter than header → missing keys padded with ''
		$rows = ImportService::parse("org_name,org_ref,email\nFirma\n");
		$this->assertSame('Firma', $rows[0]['org_name']);
		$this->assertSame('', $rows[0]['org_ref']);
		$this->assertSame('', $rows[0]['email']);
		// row longer than header → extra cells land on '' keys
		$rows = ImportService::parse("org_name,email\nFirma,x@y.de,EXTRA\n");
		$this->assertSame('Firma', $rows[0]['org_name']);
		$this->assertSame('x@y.de', $rows[0]['email']);
		$this->assertContains('EXTRA', $rows[0]);
	}
}
