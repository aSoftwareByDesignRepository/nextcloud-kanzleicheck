<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Tests\Unit;

use OCA\PortalCheck\Exception\ValidationException;
use OCA\PortalCheck\Service\FormService;
use PHPUnit\Framework\TestCase;

/** Field-schema + per-type value validation (US-P28). */
class FormValidationTest extends TestCase
{
	public function testValidSchemaPasses(): void
	{
		$out = FormService::validateFields([
			['key' => 'name', 'type' => 'short_text', 'label' => 'Name', 'required' => true],
			['key' => 'kind', 'type' => 'single_choice', 'label' => 'Art', 'options' => ['a', 'b']],
		]);
		$this->assertCount(2, $out);
		$this->assertTrue($out[0]['required']);
	}

	public function testDuplicateKeyRejected(): void
	{
		$this->expectException(ValidationException::class);
		FormService::validateFields([
			['key' => 'x', 'type' => 'short_text', 'label' => 'a'],
			['key' => 'x', 'type' => 'short_text', 'label' => 'b'],
		]);
	}

	public function testInvalidKeyRejected(): void
	{
		$this->expectException(ValidationException::class);
		FormService::validateFields([['key' => 'Has Space', 'type' => 'short_text', 'label' => 'a']]);
	}

	public function testChoiceRequiresOptions(): void
	{
		$this->expectException(ValidationException::class);
		FormService::validateFields([['key' => 'k', 'type' => 'single_choice', 'label' => 'a', 'options' => []]]);
	}

	public function testEmptyFieldListRejected(): void
	{
		$this->expectException(ValidationException::class);
		FormService::validateFields([]);
	}

	public function testValueValidationByType(): void
	{
		$f = static fn(string $type, array $extra = []): array => array_merge(['key' => 'k', 'type' => $type, 'label' => 'l', 'required' => false, 'options' => ['a', 'b'], 'showIf' => null, 'min' => null, 'max' => null], $extra);
		$this->assertSame('hi', FormService::validateValue($f('short_text'), 'hi'));
		$this->assertSame('42', FormService::validateValue($f('number'), '42'));
		$this->assertSame('2026-01-01', FormService::validateValue($f('date'), '2026-01-01'));
		$this->assertSame('a', FormService::validateValue($f('single_choice'), 'a'));
		$this->expectException(ValidationException::class);
		FormService::validateValue($f('single_choice'), 'not-an-option');
	}

	public function testNumberBounds(): void
	{
		$f = ['key' => 'k', 'type' => 'number', 'label' => 'l', 'required' => false, 'options' => [], 'showIf' => null, 'min' => 1.0, 'max' => 10.0];
		$this->assertSame('5', FormService::validateValue($f, '5'));
		$this->expectException(ValidationException::class);
		FormService::validateValue($f, '11');
	}

	public function testDateFormat(): void
	{
		$f = ['key' => 'k', 'type' => 'date', 'label' => 'l', 'required' => false, 'options' => [], 'showIf' => null, 'min' => null, 'max' => null];
		$this->expectException(ValidationException::class);
		FormService::validateValue($f, '01.01.2026');
	}

	public function testYesNo(): void
	{
		$f = ['key' => 'k', 'type' => 'yes_no', 'label' => 'l', 'required' => false, 'options' => [], 'showIf' => null, 'min' => null, 'max' => null];
		$this->assertSame('yes', FormService::validateValue($f, 'yes'));
		$this->expectException(ValidationException::class);
		FormService::validateValue($f, 'maybe');
	}
}
