<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Service\IdentityService;
use PHPUnit\Framework\TestCase;

class IdentityValidationTest extends TestCase
{
	public function testEmailNormalization(): void
	{
		$this->assertSame('max@example.de', IdentityService::normalizeEmail('  MAX@Example.DE '));
	}

	public function testEmailValidation(): void
	{
		$this->assertTrue(IdentityService::isValidEmail('a@b.de'));
		$this->assertFalse(IdentityService::isValidEmail('not-an-email'));
		$this->assertFalse(IdentityService::isValidEmail(str_repeat('a', 250) . '@x.de'));
	}
}
