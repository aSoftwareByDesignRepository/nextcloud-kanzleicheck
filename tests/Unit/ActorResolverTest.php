<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Service\ActorResolver;
use PHPUnit\Framework\TestCase;

/** Actor strings: portal:<id> can never collide with an NC uid (':' illegal). */
class ActorResolverTest extends TestCase
{
	public function testPortalActor(): void
	{
		$this->assertSame('portal:42', ActorResolver::portal(42));
		$this->assertTrue(ActorResolver::isPortalActor('portal:42'));
		$this->assertFalse(ActorResolver::isPortalActor('alice'));
	}

	public function testPortalIdExtraction(): void
	{
		$this->assertSame(42, ActorResolver::portalId('portal:42'));
		$this->assertNull(ActorResolver::portalId('alice'));
		$this->assertNull(ActorResolver::portalId('portal:'));
		$this->assertNull(ActorResolver::portalId('portal:x12'));
		$this->assertNull(ActorResolver::portalId('portal:12x'));
		$this->assertNull(ActorResolver::portalId('portal:42 evil'));
	}

	public function testSystemAndUlink(): void
	{
		$this->assertSame('system:scheduler', ActorResolver::system('scheduler'));
		$this->assertSame('ulink:7', ActorResolver::ulink(7));
	}
}
