<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Tests\Unit;

use OCA\PortalCheck\Db\IntakeHold;
use OCA\PortalCheck\Db\IntakeHoldMapper;
use OCA\PortalCheck\Db\IntakeMapper;
use OCA\PortalCheck\Service\AuditService;
use OCA\PortalCheck\Service\IntakeService;
use OCA\PortalCheck\Service\SettingsService;
use OCA\PortalCheck\Service\TokenService;
use PHPUnit\Framework\TestCase;

/**
 * Intake address token lifecycle (US-P24 AC-6): the token must be bound to
 * a per-org nonce so rotation actually kills the old address — the earlier
 * deterministic HMAC made rotate() a no-op on the wire token.
 */
class IntakeAndNotifyTest extends TestCase
{
	private function svc(?array $row): array
	{
		$state = new \ArrayObject($row ?? []);
		$intake = $this->createMock(IntakeMapper::class);
		$holds = $this->createMock(IntakeHoldMapper::class);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturnCallback(static fn(string $k) => $k === 'inbound_secret' ? 's3cr3t' : '');
		$intake->method('find')->willReturnCallback(static fn() => $state->getArrayCopy() !== [] ? $state->getArrayCopy() : null);
		$upserts = new \ArrayObject();
		$intake->method('upsert')->willReturnCallback(function (int $o, bool $e, string $t) use ($upserts, $state): void {
			$upserts->append([$o, $e, $t]);
			$state->exchangeArray(['enabled' => $e ? 1 : 0, 'token_hash' => $t]);
		});
		$audit = $this->createMock(AuditService::class);
		$svc = new IntakeService($intake, $holds, $settings, new TokenService(), $audit);
		return [$svc, $upserts, $state, $audit];
	}

	private function assertUpserted(\ArrayObject $upserts): void
	{
		$this->assertGreaterThan(0, count($upserts), 'expected at least one intake upsert');
	}

	public function testTokenIsStableWithinANonce(): void
	{
		$row = ['enabled' => 1, 'token_hash' => 'aabbccddee0011223344556677889900'];
		[$svc] = $this->svc($row);
		$this->assertSame($svc->currentToken(7), $svc->currentToken(7));
		$this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $svc->currentToken(7));
	}

	public function testLazyNonceGeneratedForNewOrg(): void
	{
		[$svc, $upserts] = $this->svc(null);
		$t1 = $svc->currentToken(5);
		$this->assertUpserted($upserts);
		$t2 = $svc->currentToken(5);
		$this->assertSame($t1, $t2); // nonce persisted → token stable
	}

	public function testRotationChangesAddressToken(): void
	{
		$row = ['enabled' => 1, 'token_hash' => 'aabbccddee0011223344556677889900'];
		[$svc, $upserts] = $this->svc($row);
		$before = $svc->currentToken(9);
		$svc->rotate('admin', 9);
		$after = $svc->currentToken(9);
		$this->assertNotSame($before, $after, 'rotated token must differ — old address is dead');
		$this->assertUpserted($upserts);
	}

	public function testDifferentOrgsHaveDifferentTokens(): void
	{
		$row = ['enabled' => 1, 'token_hash' => 'aabbccddee0011223344556677889900'];
		[$svc] = $this->svc($row);
		$this->assertNotSame($svc->currentToken(1), $svc->currentToken(2));
	}

	public function testParseAddressRoundTrip(): void
	{
		$row = ['enabled' => 1, 'token_hash' => 'aabbccddee0011223344556677889900'];
		[$svc] = $this->svc($row);
		$token = $svc->currentToken(42);
		$parsed = IntakeService::parseAddress('mandanten+in-42-' . $token . '@kanzlei.example');
		$this->assertSame(42, $parsed['orgId']);
		$this->assertSame($token, $parsed['token']);
		$this->assertNull(IntakeService::parseAddress('plain@example.com'));
		$this->assertNull(IntakeService::parseAddress('mandanten+in-42-XYZ@example.com')); // non-hex token
	}

	public function testIsEnabledReflectsRow(): void
	{
		[$on] = $this->svc(['enabled' => 1, 'token_hash' => 'aa']);
		[$off] = $this->svc(['enabled' => 0, 'token_hash' => 'aa']);
		[$none] = $this->svc(null);
		$this->assertTrue($on->isEnabled(3));
		$this->assertFalse($off->isEnabled(3));
		$this->assertFalse($none->isEnabled(3));
	}

	public function testSetEnabledKeepsNonceAcrossToggle(): void
	{
		$row = ['enabled' => 0, 'token_hash' => 'aabbccddee0011223344556677889900'];
		[$svc, , $state] = $this->svc($row);
		$svc->setEnabled('admin', 4, true);
		$this->assertSame('aabbccddee0011223344556677889900', $state['token_hash']);
		$this->assertSame(1, $state['enabled']);
	}

	public function testTokenMatchesExactHmacContract(): void
	{
		// pin the wire contract: HMAC(secret:nonce, 'ptl-intake:<orgId>')[:16]
		$nonce = 'aabbccddee0011223344556677889900';
		[$svc] = $this->svc(['enabled' => 1, 'token_hash' => $nonce]);
		$expected = substr(hash_hmac('sha256', 'ptl-intake:7', 's3cr3t:' . $nonce), 0, 16);
		$this->assertSame($expected, $svc->currentToken(7));
	}

	public function testFreshNonceIs32Hex(): void
	{
		[$svc, , $state] = $this->svc(null);
		$svc->currentToken(5);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string)$state['token_hash']);
	}

	public function testIsEnabledAcceptsDbStringRow(): void
	{
		// DB returns '1' as string — the strict cast must survive
		[$on] = $this->svc(['enabled' => '1', 'token_hash' => 'aa']);
		$this->assertTrue($on->isEnabled(3));
	}

	public function testParseAddressAcceptsUppercaseHexLowercased(): void
	{
		$parsed = IntakeService::parseAddress('mandanten+in-42-AABBCCDDEE001122@kanzlei.example');
		$this->assertSame(42, $parsed['orgId']);
		$this->assertSame('aabbccddee001122', $parsed['token']);
	}

	public function testRotateKeepsIntakeEnabled(): void
	{
		// rotation refreshes the nonce but must never disable intake
		[$svc, $upserts, $state] = $this->svc(['enabled' => 1, 'token_hash' => 'aabbccddee0011223344556677889900']);
		$oldNonce = (string)$state['token_hash'];
		$svc->rotate('admin', 9);
		$this->assertSame(1, $state['enabled']);
		$last = $upserts[count($upserts) - 1];
		$this->assertTrue($last[1]);
		$this->assertNotSame($oldNonce, $state['token_hash']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string)$state['token_hash']);
	}

	public function testLazyUpsertPreservesEnabledFlag(): void
	{
		// enabled row with empty nonce → lazy nonce keeps enabled=true
		[$svc, $upserts] = $this->svc(['enabled' => '1', 'token_hash' => '']);
		$svc->currentToken(5);
		$this->assertTrue($upserts[count($upserts) - 1][1]);
		// disabled row → lazy nonce keeps enabled=false
		[$svc, $upserts] = $this->svc(['enabled' => '0', 'token_hash' => '']);
		$svc->currentToken(5);
		$this->assertFalse($upserts[count($upserts) - 1][1]);
		// row missing the enabled key entirely → default false, never true
		[$svc, $upserts] = $this->svc(['token_hash' => '']);
		$svc->currentToken(5);
		$this->assertFalse($upserts[count($upserts) - 1][1]);
	}

	public function testAuditActionsLogged(): void
	{
		[$svc, , , $audit] = $this->svc(['enabled' => 0, 'token_hash' => 'aa']);
		$audit->expects($this->once())->method('log')->with('admin', 'intake.enable', 'org', 4);
		$svc->setEnabled('admin', 4, true);

		[$svc, , , $audit] = $this->svc(['enabled' => 1, 'token_hash' => 'aa']);
		$audit->expects($this->once())->method('log')->with('admin', 'intake.disable', 'org', 4);
		$svc->setEnabled('admin', 4, false);

		[$svc, , , $audit] = $this->svc(['enabled' => 1, 'token_hash' => 'aa']);
		$audit->expects($this->once())->method('log')->with('admin', 'intake.rotate', 'org', 4);
		$svc->rotate('admin', 4);
	}
}
