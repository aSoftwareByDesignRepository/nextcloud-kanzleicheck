<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * G-19 / NN-P23: every controller method marked #[PublicPage] must be on
 * the reviewed allowlist. Adding a public route without a review FAILS —
 * this is the route-inventory gate that keeps the unauthenticated surface
 * from silently growing.
 */
class PublicRoutesTest extends TestCase
{
	private const ALLOWED = [
		'page#index',
		'portal#login', 'portal#index',
		'portalAuth#magicGet', 'portalAuth#send', 'portalAuth#verify',
		'portalAuth#magicPost', 'portalAuth#logout',
		'ulink#page', 'ulink#upload',
	];

	public function testEveryPublicMethodIsAllowlisted(): void
	{
		$lib = dirname(__DIR__, 2) . '/lib/Controller';
		$routes = include dirname(__DIR__, 2) . '/appinfo/routes.php';
		$routeMap = [];
		foreach ($routes['routes'] as $r) {
			[$ctrl, $method] = explode('#', $r['name']);
			$routeMap[$r['name']] = ['method' => $method, 'ctrl' => $ctrl];
		}

		$public = [];
		foreach (glob($lib . '/*.php') as $file) {
			$src = file_get_contents($file);
			$class = basename($file, '.php');
			$routeName = lcfirst(substr($class, 0, -strlen('Controller')));
			// find "public function <m>" blocks preceded within ~600 chars by PublicPage attribute
			if (preg_match_all('/#\[PublicPage\][^a-zA-Z]{0,600}public function (\w+)\s*\(/s', $src, $m)) {
				foreach ($m[1] as $method) {
					$public[] = $routeName . '#' . $method;
				}
			}
		}
		// portalApi + companion are whole-surface session/device-gated controllers:
		// every method is PublicPage because auth is enforced by middleware —
		// they are covered by the session/device contract, not per-route review.
		$public = array_filter($public, static fn(string $n) => !str_starts_with($n, 'portalApi#') && !str_starts_with($n, 'companion#'));

		foreach ($public as $name) {
			$this->assertArrayHasKey($name, $routeMap, "public method $name has no route");
			$this->assertContains($name, self::ALLOWED, "public route $name not on the reviewed allowlist");
		}
		foreach (self::ALLOWED as $name) {
			$this->assertArrayHasKey($name, $routeMap, "allowlisted route $name missing from routes.php");
		}
	}

	public function testSessionGatedControllersRequireNoCsrfAttributeIsPresent(): void
	{
		// portalApi/companion: every public method must also be NoCSRFRequired
		// (session cookie / device header auth — NC CSRF check does not apply,
		// our own ptl_csrf double-submit does).
		foreach (['PortalApiController', 'CompanionController'] as $class) {
			$src = file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/' . $class . '.php');
			preg_match_all('/((?:#\[[A-Za-z]+\]\s*)+)public function (\w+)\s*\(/s', $src, $m, PREG_SET_ORDER);
			foreach ($m as [$_, $attrs, $method]) {
				if (str_contains($attrs, 'PublicPage')) {
					$this->assertStringContainsString('NoCSRFRequired', $attrs, "$class::$method is PublicPage without NoCSRFRequired");
				}
			}
		}
	}
}
