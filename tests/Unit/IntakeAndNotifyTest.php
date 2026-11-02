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
		$svc = new IntakeService($intake, $holds, $settings, new TokenService(), $this->createMock(AuditService::class));
		return [$svc, $upserts, $state];
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
}
