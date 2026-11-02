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
		$this->expectException(ValidationException::class);
		ImportService::parse($csv);
	}
}
