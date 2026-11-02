<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Tests\Unit;

use OCA\PortalCheck\Service\SettingsService;
use OCP\IConfig;
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
}
