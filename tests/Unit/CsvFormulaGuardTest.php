<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Tests\Unit;

use OCA\PortalCheck\Service\CsvFormulaGuard;
use PHPUnit\Framework\TestCase;

/** CSV formula injection — every lead char that spreadsheets interpret. */
class CsvFormulaGuardTest extends TestCase
{
	public function testDangerousLeadsEscaped(): void
	{
		foreach (['=cmd', '+1', '-1', "@SUM", "\tfoo", "\rbar", "\x0Bbaz"] as $in) {
			$this->assertSame("'" . $in, CsvFormulaGuard::escape($in), $in);
		}
	}

	public function testNormalValuesUntouched(): void
	{
		$this->assertSame('Müller', CsvFormulaGuard::escape('Müller'));
		$this->assertSame('', CsvFormulaGuard::escape(''));
		$this->assertSame('', CsvFormulaGuard::escape(null));
		$this->assertSame('a=b', CsvFormulaGuard::escape('a=b'));
	}
}
