<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck {
	// Fixture controller in the app namespace — the middlewares only gate
	// OCA\KanzleiCheck\* controllers, everything else passes untouched.
	class AuthzDummyController extends \OCP\AppFramework\Controller
		implements \OCA\KanzleiCheck\Contracts\StaffContextAware,
			\OCA\KanzleiCheck\Contracts\CompanionContextAware,
			\OCA\KanzleiCheck\Contracts\PortalSessionAware
	{
		public ?string $staffUid = null;
		public ?bool $staffAdmin = null;
		public mixed $identity = null;
		public mixed $device = null;
		public ?string $sessionToken = null;

		public function setStaffContext(string $uid, bool $isAppAdmin): void
		{
			$this->staffUid = $uid;
			$this->staffAdmin = $isAppAdmin;
		}

		public function setCompanionContext(\OCA\KanzleiCheck\Db\Identity $identity, \OCA\KanzleiCheck\Db\Device $device): void
		{
			$this->identity = $identity;
			$this->device = $device;
		}

		public function setPortalIdentity(\OCA\KanzleiCheck\Db\Identity $identity, string $sessionToken): void
		{
			$this->identity = $identity;
			$this->sessionToken = $sessionToken;
		}
	}

	// App-namespaced controller WITHOUT the context contracts — the
	// instanceof guards must not fire on it (InstanceOf_ mutant bait).
	class AuthzPlainController extends \OCP\AppFramework\Controller
	{
	}
}

namespace OCA\KanzleiCheck\Tests\Unit {

	use OCA\KanzleiCheck\AuthzDummyController;
	use OCA\KanzleiCheck\Db\Device;
	use OCA\KanzleiCheck\Db\Identity;
	use OCA\KanzleiCheck\Db\PortalSession;
	use OCA\KanzleiCheck\Exception\DeviceRevokedException;
	use OCA\KanzleiCheck\Exception\ForbiddenException;
	use OCA\KanzleiCheck\Exception\UnauthorizedException;
	use OCA\KanzleiCheck\Middleware\DeviceAuthMiddleware;
	use OCA\KanzleiCheck\Middleware\PortalSessionMiddleware;
	use OCA\KanzleiCheck\Middleware\StaffAccessMiddleware;
	use OCA\KanzleiCheck\Service\AccessDoorService;
	use OCA\KanzleiCheck\Service\CsrfService;
	use OCA\KanzleiCheck\Service\DeviceService;
	use OCA\KanzleiCheck\Service\IdentityService;
	use OCA\KanzleiCheck\Service\PortalSessionService;
	use OCP\AppFramework\Controller;
	use OCP\IRequest;
	use OCP\IUser;
	use OCP\IUserSession;
	use PHPUnit\Framework\MockObject\MockObject;
	use PHPUnit\Framework\TestCase;

	/**
	 * The three authz gates in one file — StaffAccess (door), DeviceAuth
	 * (companion tokens), PortalSession (cookie session + CSRF). Fail-closed
	 * on every leg: missing principal, dead identity, bogus token, missing
	 * CSRF — never a silent pass.
	 */
	class AuthzMiddlewareChainTest extends TestCase
	{
		private const PREFIX = '/index.php/apps/kanzleicheck';

		private function request(string $rest, string $method = 'GET', string $prefix = self::PREFIX): IRequest&MockObject
		{
			$r = $this->createMock(IRequest::class);
			$r->method('getPathInfo')->willReturn($prefix . $rest);
			$r->method('getMethod')->willReturn($method);
			return $r;
		}

		private function controller(): AuthzDummyController
		{
			return new AuthzDummyController('kanzleicheck', $this->createMock(IRequest::class));
		}

		private function foreign(): Controller&MockObject
		{
			return $this->createMock(Controller::class);
		}

		// ── StaffAccessMiddleware ──

		private function staffMw(IRequest $r, AccessDoorService&MockObject $door, ?IUser $user): StaffAccessMiddleware
		{
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($user);
			return new StaffAccessMiddleware($r, $session, $door);
		}

		public function testStaffForeignAndPublicPathsPass(): void
		{
			$door = $this->createMock(AccessDoorService::class);
			$door->expects($this->never())->method('isStaff');
			// foreign controller → untouched
			$this->staffMw($this->request('/x'), $door, null)
				->beforeController($this->foreign(), 'm');
			// non-app path → untouched
			$req = $this->createMock(IRequest::class);
			$req->method('getPathInfo')->willReturn('/apps/otherapp/x');
			$this->staffMw($req, $door, null)->beforeController($this->controller(), 'm');
			// /portal + /api/companion are public client surfaces — not staff-gated
			$this->staffMw($this->request('/portal/api/auth/verify'), $door, null)
				->beforeController($this->controller(), 'm');
			$this->staffMw($this->request('/api/companion/v1/pair'), $door, null)
				->beforeController($this->controller(), 'm');
		}

		public function testStaffAnonymousDenied(): void
		{
			$door = $this->createMock(AccessDoorService::class);
			$door->expects($this->never())->method('isStaff'); // uid '' short-circuits
			$this->expectException(ForbiddenException::class);
			$this->staffMw($this->request('/settings/access'), $door, null)
				->beforeController($this->controller(), 'm');
		}

		public function testStaffNonStaffDeniedNoContext(): void
		{
			$door = $this->createMock(AccessDoorService::class);
			$door->method('isStaff')->with('mallory')->willReturn(false);
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('mallory');
			$c = $this->controller();
			try {
				$this->staffMw($this->request('/settings/access'), $door, $user)
					->beforeController($c, 'm');
				$this->fail('expected ForbiddenException');
			} catch (ForbiddenException) {
				$this->assertNull($c->staffUid); // context never injected on deny
			}
		}

		public function testStaffAdmittedInjectsContext(): void
		{
			$door = $this->createMock(AccessDoorService::class);
			$door->method('isStaff')->with('alice')->willReturn(true);
			$door->method('isAppAdmin')->with('alice')->willReturn(true);
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$c = $this->controller();
			$this->staffMw($this->request('/settings/access'), $door, $user)
				->beforeController($c, 'm');
			$this->assertSame('alice', $c->staffUid);
			$this->assertTrue($c->staffAdmin);
		}

		// ── DeviceAuthMiddleware ──

		private function deviceMw(IRequest $r, DeviceService&MockObject $dev, IdentityService&MockObject $ids): DeviceAuthMiddleware
		{
			return new DeviceAuthMiddleware($r, $dev, $ids);
		}

		public function testDeviceNonCompanionPathPasses(): void
		{
			$dev = $this->createMock(DeviceService::class);
			$dev->expects($this->never())->method('resolve');
			$this->deviceMw($this->request('/api/portal/orgs'), $dev, $this->createMock(IdentityService::class))
				->beforeController($this->controller(), 'm');
		}

		public function testDeviceOpenRoutesSoftAuth(): void
		{
			$dev = $this->createMock(DeviceService::class);
			$dev->method('resolve')->willReturn(null); // bogus token → anonymous, not 401
			$req = $this->request('/api/companion/v1/bootstrap');
			$req->method('getHeader')->with(DeviceAuthMiddleware::HEADER)->willReturn('bogus');
			// open route + unresolvable token → pass through, no exception
			$this->deviceMw($req, $dev, $this->createMock(IdentityService::class))
				->beforeController($this->controller(), 'm');
			// open route + no token at all → same
			$req2 = $this->request('/api/companion/v1/pair');
			$req2->method('getHeader')->willReturn('');
			$c = $this->controller();
			$this->deviceMw($req2, $this->createMock(DeviceService::class), $this->createMock(IdentityService::class))
				->beforeController($c, 'm');
			$this->assertNull($c->device); // soft-auth injects nothing
		}

		public function testDeviceProtectedMissingTokenRevoked(): void
		{
			$req = $this->request('/api/companion/v1/todo');
			$req->method('getHeader')->willReturn('');
			$this->expectException(DeviceRevokedException::class);
			$this->deviceMw($req, $this->createMock(DeviceService::class), $this->createMock(IdentityService::class))
				->beforeController($this->controller(), 'm');
		}

		public function testDeviceProtectedUnknownTokenRevoked(): void
		{
			$req = $this->request('/api/companion/v1/todo');
			$req->method('getHeader')->willReturn('tok-bogus');
			$dev = $this->createMock(DeviceService::class);
			$dev->method('resolve')->with('tok-bogus')->willReturn(null);
			$this->expectException(DeviceRevokedException::class);
			$this->deviceMw($req, $dev, $this->createMock(IdentityService::class))
				->beforeController($this->controller(), 'm');
		}

		public function testDeviceDeadIdentityRevoked(): void
		{
			$device = new Device();
			$device->setIdentityId(7);
			$req = $this->request('/api/companion/v1/todo');
			$req->method('getHeader')->willReturn('tok-ok');
			$dev = $this->createMock(DeviceService::class);
			$dev->method('resolve')->willReturn($device);
			$ids = $this->createMock(IdentityService::class);
			$ids->method('activeById')->with(7)->willReturn(null); // identity deleted/archived
			$this->expectException(DeviceRevokedException::class);
			$this->deviceMw($req, $dev, $ids)->beforeController($this->controller(), 'm');
		}

		public function testDeviceValidInjectsContext(): void
		{
			$device = new Device();
			$device->setIdentityId(7);
			$identity = new Identity();
			$req = $this->request('/api/companion/v1/todo');
			$req->method('getHeader')->willReturn('tok-ok');
			$dev = $this->createMock(DeviceService::class);
			$dev->method('resolve')->willReturn($device);
			$ids = $this->createMock(IdentityService::class);
			$ids->method('activeById')->willReturn($identity);
			$c = $this->controller();
			$this->deviceMw($req, $dev, $ids)->beforeController($c, 'm');
			$this->assertSame($identity, $c->identity);
			$this->assertSame($device, $c->device);
		}

		// ── PortalSessionMiddleware ──

		private function portalMw(IRequest $r, PortalSessionService&MockObject $sess, IdentityService&MockObject $ids, CsrfService&MockObject $csrf): PortalSessionMiddleware
		{
			return new PortalSessionMiddleware($r, $sess, $ids, $csrf);
		}

		public function testPortalOpenPathsPass(): void
		{
			$sess = $this->createMock(PortalSessionService::class);
			$sess->expects($this->never())->method('resolve');
			$ids = $this->createMock(IdentityService::class);
			$csrf = $this->createMock(CsrfService::class);
			foreach (['/portal', '/portal/', '/portal/api/auth/send', '/portal/api/auth/verify',
				'/portal/auth/magic/abc', '/portal/login', '/portal/u/tok', '/portal/api/u/tok',
				'/settings/access' /* not portal */, ] as $p) {
				$this->portalMw($this->request($p), $sess, $ids, $csrf)
					->beforeController($this->controller(), 'm');
			}
		}

		public function testPortalMissingSessionCookie401(): void
		{
			$req = $this->request('/portal/api/todo');
			$req->method('getCookie')->willReturn(null);
			$this->expectException(UnauthorizedException::class);
			$this->portalMw($req, $this->createMock(PortalSessionService::class),
				$this->createMock(IdentityService::class), $this->createMock(CsrfService::class))
				->beforeController($this->controller(), 'm');
		}

		public function testPortalBadSessionAndDeadIdentity401(): void
		{
			$req = $this->request('/portal/api/todo');
			$req->method('getCookie')->willReturn('sess-x');
			$sess = $this->createMock(PortalSessionService::class);
			$sess->method('resolve')->willReturn(null); // expired/bogus
			try {
				$this->portalMw($req, $sess, $this->createMock(IdentityService::class),
					$this->createMock(CsrfService::class))->beforeController($this->controller(), 'm');
				$this->fail('expected');
			} catch (UnauthorizedException) {
			}
			// valid session row but identity gone → still 401
			$session = new PortalSession();
			$session->setIdentityId(7);
			$sess2 = $this->createMock(PortalSessionService::class);
			$sess2->method('resolve')->willReturn($session);
			$ids = $this->createMock(IdentityService::class);
			$ids->method('activeById')->willReturn(null);
			$this->expectException(UnauthorizedException::class);
			$this->portalMw($req, $sess2, $ids, $this->createMock(CsrfService::class))
				->beforeController($this->controller(), 'm');
		}

		public function testPortalGetSkipsCsrfPostRequires(): void
		{
			$identity = new Identity();
			$session = new PortalSession();
			$session->setIdentityId(7);
			$sess = $this->createMock(PortalSessionService::class);
			$sess->method('resolve')->willReturn($session);
			$ids = $this->createMock(IdentityService::class);
			$ids->method('activeById')->willReturn($identity);
			$csrf = $this->createMock(CsrfService::class);

			// GET → no CSRF check at all
			$csrf->expects($this->never())->method('validate');
			$req = $this->request('/portal/api/todo', 'GET');
			$req->method('getCookie')->willReturn('sess-ok');
			$c = $this->controller();
			$this->portalMw($req, $sess, $ids, $csrf)->beforeController($c, 'm');
			$this->assertSame($identity, $c->identity);
			$this->assertSame('sess-ok', $c->sessionToken);
		}

		public function testPortalPostCsrfEnforcedBothChannels(): void
		{
			$identity = new Identity();
			$session = new PortalSession();
			$session->setIdentityId(7);
			$sess = $this->createMock(PortalSessionService::class);
			$sess->method('resolve')->willReturn($session);
			$ids = $this->createMock(IdentityService::class);
			$ids->method('activeById')->willReturn($identity);

			// header channel
			$csrf = $this->createMock(CsrfService::class);
			$csrf->method('validate')->with('sess-ok', 'csrf-hdr')->willReturn(true);
			$req = $this->request('/portal/api/todo', 'POST');
			$req->method('getCookie')->willReturn('sess-ok');
			$req->method('getHeader')->willReturnMap([[CsrfService::HEADER, 'csrf-hdr']]);
			$this->portalMw($req, $sess, $ids, $csrf)->beforeController($this->controller(), 'm');

			// body param fallback
			$csrf2 = $this->createMock(CsrfService::class);
			$csrf2->method('validate')->with('sess-ok', 'csrf-body')->willReturn(true);
			$req2 = $this->request('/portal/api/todo', 'POST');
			$req2->method('getCookie')->willReturn('sess-ok');
			$req2->method('getHeader')->willReturn('');
			$req2->method('getParam')->with('kzc_csrf', '')->willReturn('csrf-body');
			$this->portalMw($req2, $sess, $ids, $csrf2)->beforeController($this->controller(), 'm');

			// invalid token → Unauthorized CSRF
			$csrf3 = $this->createMock(CsrfService::class);
			$csrf3->method('validate')->willReturn(false);
			$req3 = $this->request('/portal/api/todo', 'POST');
			$req3->method('getCookie')->willReturn('sess-ok');
			$req3->method('getHeader')->willReturn('bad');
			$this->expectException(UnauthorizedException::class);
			$this->portalMw($req3, $sess, $ids, $csrf3)->beforeController($this->controller(), 'm');
		}

		// ── mutation-hunter round: prefix variants, casts, instanceof ──

		public function testShortPrefixVariantRecognised(): void
		{
			// /apps/kanzleicheck/... (no /index.php) must gate identically
			$door = $this->createMock(AccessDoorService::class);
			$door->method('isStaff')->willReturn(false);
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('x');
			try {
				$this->staffMw($this->request('/settings/access', 'GET', '/apps/kanzleicheck'), $door, $user)
					->beforeController($this->controller(), 'm');
				$this->fail('expected ForbiddenException');
			} catch (ForbiddenException) {
			}

			$req = $this->request('/portal/api/todo', 'GET', '/apps/kanzleicheck');
			$req->method('getCookie')->willReturn(null);
			try {
				$this->portalMw($req, $this->createMock(PortalSessionService::class),
					$this->createMock(IdentityService::class), $this->createMock(CsrfService::class))
					->beforeController($this->controller(), 'm');
				$this->fail('expected UnauthorizedException');
			} catch (UnauthorizedException) {
			}

			$req2 = $this->request('/api/companion/v1/todo', 'GET', '/apps/kanzleicheck');
			$req2->method('getHeader')->willReturn('');
			$this->expectException(DeviceRevokedException::class);
			$this->deviceMw($req2, $this->createMock(DeviceService::class), $this->createMock(IdentityService::class))
				->beforeController($this->controller(), 'm');
		}

		public function testNonContractControllersSkipContextInjection(): void
		{
			// instanceof guards: plain app controllers get gated but never
			// get set*Context called (they don't have the methods).
			$door = $this->createMock(AccessDoorService::class);
			$door->method('isStaff')->willReturn(true);
			$door->method('isAppAdmin')->willReturn(false);
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$plain = new \OCA\KanzleiCheck\AuthzPlainController('kanzleicheck', $this->createMock(IRequest::class));
			$this->staffMw($this->request('/settings/access'), $door, $user)
				->beforeController($plain, 'm');

			$device = new Device();
			$device->setIdentityId(7);
			$dev = $this->createMock(DeviceService::class);
			$dev->method('resolve')->willReturn($device);
			$ids = $this->createMock(IdentityService::class);
			$ids->method('activeById')->willReturn(new Identity());
			$req = $this->request('/api/companion/v1/todo');
			$req->method('getHeader')->willReturn('tok');
			$this->deviceMw($req, $dev, $ids)->beforeController($plain, 'm');

			$session = new PortalSession();
			$session->setIdentityId(7);
			$sess = $this->createMock(PortalSessionService::class);
			$sess->method('resolve')->willReturn($session);
			$req2 = $this->request('/portal/api/todo');
			$req2->method('getCookie')->willReturn('sess');
			$this->portalMw($req2, $sess, $ids, $this->createMock(CsrfService::class))
				->beforeController($plain, 'm');
			$this->assertTrue(true); // no Error thrown — guards respected
		}

		public function testDeviceOpenRouteWithValidTokenGetsContext(): void
		{
			// open route + VALID token → device resolves, context injected
			// ($open && $token==='' only skips when no token presented)
			$device = new Device();
			$device->setIdentityId(7);
			$identity = new Identity();
			$dev = $this->createMock(DeviceService::class);
			$dev->method('resolve')->with('tok')->willReturn($device);
			$ids = $this->createMock(IdentityService::class);
			$ids->method('activeById')->willReturn($identity);
			$req = $this->request('/api/companion/v1/bootstrap');
			$req->method('getHeader')->willReturn('tok');
			$c = $this->controller();
			$this->deviceMw($req, $dev, $ids)->beforeController($c, 'm');
			$this->assertSame($identity, $c->identity);
			$this->assertSame($device, $c->device);
		}

		public function testDeviceOpenRouteResolvedDeviceDeadIdentityStillSoft(): void
		{
			$device = new Device();
			$device->setIdentityId(7);
			$dev = $this->createMock(DeviceService::class);
			$dev->method('resolve')->willReturn($device);
			$ids = $this->createMock(IdentityService::class);
			$ids->method('activeById')->willReturn(null); // dead identity
			$req = $this->request('/api/companion/v1/pair');
			$req->method('getHeader')->willReturn('tok');
			$c = $this->controller();
			$this->deviceMw($req, $dev, $ids)->beforeController($c, 'm'); // soft: no throw
			$this->assertNull($c->identity);
		}

		public function testStringIdentityIdCastsInMiddleware(): void
		{
			// DB rows come back stringly-typed — the (int) casts are load-bearing
			$device = new Device();
			$device->setIdentityId('7');
			$dev = $this->createMock(DeviceService::class);
			$dev->method('resolve')->willReturn($device);
			$ids = $this->createMock(IdentityService::class);
			$ids->method('activeById')->with(7)->willReturn(new Identity()); // strict int
			$req = $this->request('/api/companion/v1/todo');
			$req->method('getHeader')->willReturn('tok');
			$this->deviceMw($req, $dev, $ids)->beforeController($this->controller(), 'm');

			$session = new PortalSession();
			$session->setIdentityId('9');
			$sess = $this->createMock(PortalSessionService::class);
			$sess->method('resolve')->willReturn($session);
			$ids2 = $this->createMock(IdentityService::class);
			$ids2->method('activeById')->with(9)->willReturn(new Identity());
			$req2 = $this->request('/portal/api/todo');
			$req2->method('getCookie')->willReturn('sess');
			$this->portalMw($req2, $sess, $ids2, $this->createMock(CsrfService::class))
				->beforeController($this->controller(), 'm');
			$this->assertTrue(true);
		}
	}
}
