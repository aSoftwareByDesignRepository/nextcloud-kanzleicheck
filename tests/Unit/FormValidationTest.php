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

	public function testSingleFieldAccepted(): void
	{
		$out = FormService::validateFields([['key' => 'only', 'type' => 'short_text', 'label' => 'a']]);
		$this->assertCount(1, $out);
		$this->assertSame('only', $out[0]['key']);
	}

	public function testExactlyMaxFieldsAccepted(): void
	{
		$fields = [];
		for ($i = 0; $i < FormService::MAX_FIELDS; $i++) {
			$fields[] = ['key' => 'f' . $i, 'type' => 'short_text', 'label' => 'L' . $i];
		}
		$this->assertCount(FormService::MAX_FIELDS, FormService::validateFields($fields));
		$fields[] = ['key' => 'one_too_many', 'type' => 'short_text', 'label' => 'x'];
		try {
			FormService::validateFields($fields);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(['fields' => 'count'], $e->getFields());
		}
	}

	public function testKeyAnchorViolationsRejected(): void
	{
		foreach (['abc!', '!abc', 'ab c', 'ABC'] as $bad) {
			try {
				FormService::validateFields([['key' => $bad, 'type' => 'short_text', 'label' => 'a']]);
				$this->fail("key '$bad' must be rejected");
			} catch (ValidationException $e) {
				$this->assertSame(['fields' => 'key:0'], $e->getFields());
			}
		}
	}

	public function testLabelTrimAndBounds(): void
	{
		$out = FormService::validateFields([['key' => 'k', 'type' => 'short_text', 'label' => '  Name  ']]);
		$this->assertSame('Name', $out[0]['label']);
		$this->assertCount(1, FormService::validateFields([['key' => 'k', 'type' => 'short_text', 'label' => str_repeat('a', 190)]]));
		$this->assertCount(1, FormService::validateFields([['key' => 'k', 'type' => 'short_text', 'label' => str_repeat('ä', 190)]]));
		foreach (['   ', str_repeat('a', 191), str_repeat('ä', 191)] as $bad) {
			try {
				FormService::validateFields([['key' => 'k', 'type' => 'short_text', 'label' => $bad]]);
				$this->fail("label must be rejected");
			} catch (ValidationException $e) {
				$this->assertSame(['fields' => 'label:k'], $e->getFields());
			}
		}
	}

	public function testUnknownTypeRejected(): void
	{
		try {
			FormService::validateFields([['key' => 'k', 'type' => 'evil', 'label' => 'a']]);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(['fields' => 'type:k'], $e->getFields());
		}
	}

	public function testOptionsNormalizedCappedFiltered(): void
	{
		// >30 options are capped at 30; falsy entries and non-strings normalize away
		$many = array_map('strval', range(1, 35));
		$out = FormService::validateFields([['key' => 'k', 'type' => 'single_choice', 'label' => 'a', 'options' => $many]]);
		$this->assertCount(30, $out[0]['options']);
		$this->assertSame('1', $out[0]['options'][0]);
		$this->assertSame('30', $out[0]['options'][29]);

		$out = FormService::validateFields([['key' => 'k', 'type' => 'multi_choice', 'label' => 'a', 'options' => [0, 'a', '', 'b', 7]]]);
		$this->assertSame(['a', 'b', '7'], array_values($out[0]['options']));

		// non-choice types always get empty options
		$out = FormService::validateFields([['key' => 'k', 'type' => 'short_text', 'label' => 'a', 'options' => ['x']]]);
		$this->assertSame([], $out[0]['options']);
	}

	public function testShowIfNormalization(): void
	{
		$out = FormService::validateFields([['key' => 'k', 'type' => 'short_text', 'label' => 'a',
			'showIf' => ['field' => 'kind', 'equals' => 42]]]);
		$this->assertSame(['field' => 'kind', 'equals' => '42'], $out[0]['showIf']);
		$out = FormService::validateFields([['key' => 'k', 'type' => 'short_text', 'label' => 'a',
			'showIf' => ['field' => 7, 'equals' => 'x']]]);
		$this->assertSame(['field' => '7', 'equals' => 'x'], $out[0]['showIf']);
		foreach ([['field' => 'kind'], 'not-an-array', ['equals' => 'x']] as $bad) {
			$out = FormService::validateFields([['key' => 'k', 'type' => 'short_text', 'label' => 'a', 'showIf' => $bad]]);
			$this->assertNull($out[0]['showIf']);
		}
	}

	public function testMinMaxCastToFloat(): void
	{
		$out = FormService::validateFields([['key' => 'k', 'type' => 'number', 'label' => 'a', 'min' => '2.5', 'max' => 10]]);
		$this->assertSame(2.5, $out[0]['min']);
		$this->assertSame(10.0, $out[0]['max']);
		$out = FormService::validateFields([['key' => 'k', 'type' => 'number', 'label' => 'a']]);
		$this->assertNull($out[0]['min']);
		$this->assertNull($out[0]['max']);
	}

	public function testNormalizedShapeComplete(): void
	{
		$out = FormService::validateFields([['key' => 'k', 'type' => 'number', 'label' => 'L', 'required' => 1, 'showIf' => ['field' => 'a', 'equals' => 'b'], 'min' => 1, 'max' => 9]]);
		$this->assertSame([
			'key' => 'k', 'type' => 'number', 'label' => 'L', 'required' => true,
			'options' => [], 'showIf' => ['field' => 'a', 'equals' => 'b'],
			'min' => 1.0, 'max' => 9.0,
		], $out[0]);
		$out = FormService::validateFields([['key' => 'k', 'type' => 'short_text', 'label' => 'L', 'required' => 0]]);
		$this->assertFalse($out[0]['required']);
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
		$this->assertSame('', FormService::validateValue($f, ''));
		$this->assertSame('no', FormService::validateValue($f, 'no'));
		$this->expectException(ValidationException::class);
		FormService::validateValue($f, 'maybe');
	}

	public function testValueTrimmedOnReturn(): void
	{
		$f = ['key' => 'k', 'type' => 'short_text', 'label' => 'l', 'required' => false, 'options' => [], 'showIf' => null, 'min' => null, 'max' => null];
		$this->assertSame('hi', FormService::validateValue($f, "  hi  "));
	}

	public function testShortTextLengthBoundary(): void
	{
		$f = ['key' => 'k', 'type' => 'short_text', 'label' => 'l', 'required' => false, 'options' => [], 'showIf' => null, 'min' => null, 'max' => null];
		$this->assertSame(str_repeat('a', 500), FormService::validateValue($f, str_repeat('a', 500)));
		$this->assertSame(str_repeat('ä', 500), FormService::validateValue($f, str_repeat('ä', 500)));
		try {
			FormService::validateValue($f, str_repeat('a', 501));
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(['k' => 'too_long'], $e->getFields());
		}
		try {
			FormService::validateValue($f, str_repeat('ä', 501));
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(['k' => 'too_long'], $e->getFields());
		}
	}

	public function testLongTextLengthBoundary(): void
	{
		$f = ['key' => 'k', 'type' => 'long_text', 'label' => 'l', 'required' => false, 'options' => [], 'showIf' => null, 'min' => null, 'max' => null];
		$this->assertSame(str_repeat('a', 10000), FormService::validateValue($f, str_repeat('a', 10000)));
		$this->assertSame(str_repeat('ä', 10000), FormService::validateValue($f, str_repeat('ä', 10000)));
		try {
			FormService::validateValue($f, str_repeat('a', 10001));
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(['k' => 'too_long'], $e->getFields());
		}
	}

	public function testChoiceWithoutOptionsFieldPayload(): void
	{
		try {
			FormService::validateFields([['key' => 'k', 'type' => 'single_choice', 'label' => 'a', 'options' => []]]);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(['fields' => 'options:k'], $e->getFields());
		}
	}

	public function testOptionsNonArrayAndAssocNormalized(): void
	{
		$out = FormService::validateFields([['key' => 'k', 'type' => 'single_choice', 'label' => 'a', 'options' => 'only']]);
		$this->assertSame(['only'], $out[0]['options']);
		$out = FormService::validateFields([['key' => 'k', 'type' => 'single_choice', 'label' => 'a', 'options' => ['z' => 'a', 'y' => 'b']]]);
		$this->assertSame(['a', 'b'], $out[0]['options']);
	}

	public function testNumberNonNumericRejected(): void
	{
		$f = ['key' => 'k', 'type' => 'number', 'label' => 'l', 'required' => false, 'options' => [], 'showIf' => null, 'min' => null, 'max' => null];
		$this->assertSame('', FormService::validateValue($f, ''));
		try {
			FormService::validateValue($f, 'abc');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(['k' => 'number'], $e->getFields());
		}
	}

	public function testNumberBoundariesExact(): void
	{
		$f = ['key' => 'k', 'type' => 'number', 'label' => 'l', 'required' => false, 'options' => [], 'showIf' => null, 'min' => 1.0, 'max' => 10.0];
		$this->assertSame('1', FormService::validateValue($f, '1'));
		$this->assertSame('10', FormService::validateValue($f, '10'));
		$this->assertSame('', FormService::validateValue($f, '')); // empty skips bounds
		foreach (['0.9', '10.1'] as $bad) {
			try {
				FormService::validateValue($f, $bad);
				$this->fail("value $bad must be rejected");
			} catch (ValidationException $e) {
				$this->assertSame('k', array_key_first($e->getFields()));
			}
		}
		try {
			FormService::validateValue($f, '0.5');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(['k' => 'min'], $e->getFields());
		}
		try {
			FormService::validateValue($f, '11');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(['k' => 'max'], $e->getFields());
		}
	}

	public function testDateAnchors(): void
	{
		$f = ['key' => 'k', 'type' => 'date', 'label' => 'l', 'required' => false, 'options' => [], 'showIf' => null, 'min' => null, 'max' => null];
		$this->assertSame('', FormService::validateValue($f, ''));
		foreach (['x2026-01-01', '2026-01-01x', '2026-1-1'] as $bad) {
			try {
				FormService::validateValue($f, $bad);
				$this->fail("date '$bad' must be rejected");
			} catch (ValidationException $e) {
				$this->assertSame(['k' => 'date'], $e->getFields());
			}
		}
	}

	public function testYesNoCaseSensitive(): void
	{
		$f = ['key' => 'k', 'type' => 'yes_no', 'label' => 'l', 'required' => false, 'options' => [], 'showIf' => null, 'min' => null, 'max' => null];
		try {
			FormService::validateValue($f, 'YES');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(['k' => 'choice'], $e->getFields());
		}
	}

	public function testMultiChoiceList(): void
	{
		$f = ['key' => 'k', 'type' => 'multi_choice', 'label' => 'l', 'required' => false, 'options' => ['a', 'b', 'c'], 'showIf' => null, 'min' => null, 'max' => null];
		$this->assertSame('a,b', FormService::validateValue($f, 'a,b'));
		$this->assertSame('a, b', FormService::validateValue($f, 'a, b'));
		$this->assertSame('', FormService::validateValue($f, ''));
		try {
			FormService::validateValue($f, 'a,zz');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(['k' => 'choice'], $e->getFields());
		}
	}

	public function testSingleChoiceStrictMatch(): void
	{
		$f = ['key' => 'k', 'type' => 'single_choice', 'label' => 'l', 'required' => false, 'options' => ['a', 'b'], 'showIf' => null, 'min' => null, 'max' => null];
		$this->assertSame('', FormService::validateValue($f, ''));
		try {
			FormService::validateValue($f, 'A');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(['k' => 'choice'], $e->getFields());
		}
	}
}
