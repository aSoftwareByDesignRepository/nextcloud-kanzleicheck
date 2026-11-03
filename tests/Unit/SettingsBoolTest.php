<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Tests\Unit;

use OCA\PortalCheck\Service\SettingsService;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/** Boolean parse: 'false'/'0' must be false — form-encoded truthiness hole. */
class SettingsBoolTest extends TestCase
{
	public function testBoolParsing(): void
	{
		foreach ([['0', false], ['1', true], ['false', false], ['true', false], ['', false]] as [$raw, $expect]) {
			$config = $this->createMock(IConfig::class);
			$config->method('getAppValue')->willReturn($raw);
			$s = new SettingsService($config);
			$this->assertSame($expect, $s->getBool('x'), "raw='$raw'");
		}
	}

	public function testDefaultsReachTheCaller(): void
	{
		// unset key → IConfig returns the passed default; the DEFAULTS map
		// must actually feed it (not '' — the coalesce-order hole).
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn(string $app, string $key, string $default) => $default
		);
		$s = new SettingsService($config);
		$this->assertSame('generic', $s->get('preset'));
		$this->assertSame('10', $s->get('otp_ttl_min'));
		$this->assertSame('993', $s->get('inbound_port'));
		$this->assertSame('', $s->get('nonexistent_key'));
	}

	public function testSecretRoundTripEncryptedAtRest(): void
	{
		$store = new \ArrayObject();
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use ($store): void {
				$store[$key] = $value;
			}
		);
		$config->method('getAppValue')->willReturnCallback(
			static fn(string $app, string $key, string $default) => $store[$key] ?? $default
		);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn(string $v) => 'enc(' . $v . ')');
		$crypto->method('decrypt')->willReturnCallback(static function (string $v) {
			if (!str_starts_with($v, 'enc(')) {
				throw new \RuntimeException('bad ciphertext');
			}
			return substr($v, 4, -1);
		});

		$s = new SettingsService($config, $crypto);
		$s->setSecret('inbound_pass_enc', 'p@ssw0rd');
		// at rest it is wrapped + prefixed, never plaintext
		$this->assertSame('v2:enc(p@ssw0rd)', $store['inbound_pass_enc']);
		$this->assertSame('p@ssw0rd', $s->getSecret('inbound_pass_enc'));
		// legacy plaintext rows still read back (migration-safe)
		$store['inbound_pass_enc'] = 'legacy-plain';
		$this->assertSame('legacy-plain', $s->getSecret('inbound_pass_enc'));
		// corrupted ciphertext fails closed to ''
		$store['inbound_pass_enc'] = 'v2:corrupt';
		$this->assertSame('', $s->getSecret('inbound_pass_enc'));
	}

	public function testSecretWithoutCryptoStoresPlaintext(): void
	{
		$store = new \ArrayObject();
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use ($store): void {
				$store[$key] = $value;
			}
		);
		$config->method('getAppValue')->willReturnCallback(
			static fn(string $app, string $key, string $default) => $store[$key] ?? $default
		);
		$s = new SettingsService($config); // no crypto on this instance
		$s->setSecret('inbound_pass_enc', 'plain-secret');
		$this->assertSame('plain-secret', $store['inbound_pass_enc']);
		$this->assertSame('plain-secret', $s->getSecret('inbound_pass_enc'));
	}
}
