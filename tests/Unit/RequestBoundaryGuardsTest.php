<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck {
	// Fixture in the app namespace exposing the trait guards publicly.
	class GuardDummyController extends \OCP\AppFramework\Controller
	{
		use \OCA\KanzleiCheck\Controller\ApiTrait;

		public function pubInt(string $n, int $d = 0): int
		{
			return $this->intParam($n, $d);
		}

		public function pubStr(string $n, string $d = ''): string
		{
			return $this->strParam($n, $d);
		}

		public function pubBool(string $n, bool $d = false): bool
		{
			return $this->boolParam($n, $d);
		}

		public function pubStrictId(mixed $v): ?int
		{
			return $this->strictId($v);
		}
	}
}

namespace OCA\KanzleiCheck\Tests\Unit {

	use OCA\KanzleiCheck\Exception\ValidationException;
	use OCA\KanzleiCheck\GuardDummyController;
	use OCP\IRequest;
	use PHPUnit\Framework\MockObject\MockObject;
	use PHPUnit\Framework\TestCase;

	/**
	 * ApiTrait request-boundary guards (api-security §2 / learned class
	 * php_request_scalar_cast_silent_coercion): arrays must NEVER coerce —
	 * (int)["x"]=1 would forge revision/taskId matches, (string)["x"] stores
	 * "Array". Absent → default; non-conforming → 400 field-pinned.
	 */
	class RequestBoundaryGuardsTest extends TestCase
	{
		private function ctrl(array $params): GuardDummyController
		{
			$req = $this->createMock(IRequest::class);
			$req->method('getParam')->willReturnCallback(
				fn (string $k, $d = null) => $params[$k] ?? $d);
			return new GuardDummyController('kanzleicheck', $req);
		}

		public function testIntParam(): void
		{
			$c = $this->ctrl(['rev' => 5, 'revStr' => '7', 'neg' => -2, 'arr' => ['x'], 'float' => 1.5, 'txt' => 'abc', 'sq' => '1 OR 1']);
			$this->assertSame(5, $c->pubInt('rev'));
			$this->assertSame(7, $c->pubInt('revStr'));   // digit-string ok
			$this->assertSame(-2, $c->pubInt('neg'));
			$this->assertSame(0, $c->pubInt('missing')); // absent → default
			$this->assertSame(9, $c->pubInt('missing', 9));
			foreach (['arr', 'float', 'txt', 'sq'] as $bad) {
				try {
					$c->pubInt($bad);
					$this->fail("$bad accepted");
				} catch (ValidationException $e) {
					$this->assertSame([$bad => 'invalid'], $e->getFields());
				}
			}
			// THE killer case: an array must never become 1
			try {
				$c->pubInt('arr');
				$this->fail('array coerced');
			} catch (ValidationException) {
			}
		}

		public function testStrParam(): void
		{
			$c = $this->ctrl(['ok' => 'hello', 'arr' => ['x'], 'num' => 42]);
			$this->assertSame('hello', $c->pubStr('ok'));
			$this->assertSame('', $c->pubStr('missing'));
			$this->assertSame('d', $c->pubStr('missing', 'd'));
			foreach (['arr', 'num'] as $bad) {
				try {
					$c->pubStr($bad);
					$this->fail("$bad accepted");
				} catch (ValidationException $e) {
					$this->assertSame([$bad => 'invalid'], $e->getFields());
				}
			}
		}

		public function testBoolParam(): void
		{
			$c = $this->ctrl(['t1' => true, 't2' => '1', 't3' => 'true', 't4' => 1,
				'f1' => false, 'f2' => '0', 'f3' => 'false', 'f4' => 0,
				'arr' => ['x'], 'txt' => 'yes']);
			foreach (['t1', 't2', 't3', 't4'] as $k) {
				$this->assertTrue($c->pubBool($k), $k);
			}
			foreach (['f1', 'f2', 'f3', 'f4'] as $k) {
				$this->assertFalse($c->pubBool($k, true), $k);
			}
			$this->assertFalse($c->pubBool('missing'));
			$this->assertTrue($c->pubBool('missing', true));
			foreach (['arr', 'txt'] as $bad) {
				try {
					$c->pubBool($bad);
					$this->fail("$bad accepted");
				} catch (ValidationException) {
				}
			}
		}

		public function testStrictId(): void
		{
			$c = $this->ctrl([]);
			$this->assertSame(5, $c->pubStrictId(5));
			$this->assertSame(7, $c->pubStrictId('7'));
			$this->assertNull($c->pubStrictId('x'));
			$this->assertNull($c->pubStrictId(['5']));
			$this->assertNull($c->pubStrictId(0));   // non-positive refused
			$this->assertNull($c->pubStrictId(-3));
			$this->assertNull($c->pubStrictId(null));
			$this->assertNull($c->pubStrictId('5.5'));
		}
	}
}
