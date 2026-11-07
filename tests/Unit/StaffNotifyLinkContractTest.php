<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * INotification::setLink() rejects relative URLs — every staffNotify call
 * site must hand it an absolute linkToRouteAbsolute() URL, else the
 * notification throws inside the best-effort catch and the fan-out is
 * silently dead (portal_message regression 2026-11-03..07).
 */
final class StaffNotifyLinkContractTest extends TestCase
{
	private function src(string $rel): string
	{
		return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
	}

	/** Every staffNotify( call site must use linkToRouteAbsolute for the link arg. */
	public function testStaffNotifyCallSitesUseAbsoluteLinks(): void
	{
		foreach (['lib/Controller/PortalApiController.php', 'lib/Controller/CompanionController.php', 'lib/Service/SyncBindingService.php'] as $rel) {
			$src = $this->src($rel);
			$calls = preg_match_all('/staffNotify\s*\([^;]*;/', $src, $m);
			foreach ($m[0] as $call) {
				if (str_contains($call, '->urls->linkToRoute(')) {
					$this->fail("$rel passes a relative linkToRoute() URL to staffNotify/setLink: $call");
				}
			}
			if ($rel === 'lib/Service/SyncBindingService.php') {
				continue; // builds $link on its own line — checked below
			}
			$this->assertGreaterThan(0, $calls, "$rel must contain staffNotify call sites to pin");
		}
		$this->assertStringContainsString(
			'linkToRouteAbsolute(\'portalcheck.page.index\')',
			$this->src('lib/Service/SyncBindingService.php'),
			'portal_sync_break link must stay absolute'
		);
	}

	/** No new call site may smuggle a relative link back in (repo-wide pin). */
	public function testNoRelativeLinkToRouteReachesSetLink(): void
	{
		foreach (['lib/Controller/PortalApiController.php', 'lib/Controller/CompanionController.php'] as $rel) {
			$this->assertMatchesRegularExpression(
				'/staffNotify\([^)]*linkToRouteAbsolute\(/',
				$this->src($rel),
				"$rel: portal_message notify link must be absolute"
			);
			$this->assertDoesNotMatchRegularExpression(
				'/staffNotify\([^)]*linkToRoute\(/',
				$this->src($rel),
				"$rel: relative linkToRoute reaches setLink — invalid"
			);
		}
	}
}
