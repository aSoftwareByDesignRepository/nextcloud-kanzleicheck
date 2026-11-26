<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

require_once __DIR__ . '/Support/FakeForeignController.php';

use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Middleware\CompanionSeatMiddleware;
use OCA\KanzleiCheck\Service\LicenseService;
use OCP\AppFramework\Controller;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CompanionSeatMiddleware discrimination matrix (P6-08, NN-P06):
 * - only /api/portal/** staff routes are gated (companion device-auth and
 *   web pages never see a seat check)
 * - the discriminator is the Authorization header (client credential),
 *   never a client-sent flag
 * - cookie sessions pass through ungated
 * - unauthenticated requests defer to the door middleware
 */
class CompanionSeatMiddlewareTest extends TestCase
{
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private LicenseService&MockObject $license;
	private CompanionSeatMiddleware $mw;
	private Controller $controller;

	protected function setUp(): void
	{
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->license = $this->createMock(LicenseService::class);
		$this->mw = new CompanionSeatMiddleware($this->request, $this->userSession, $this->license);
		// beforeController gates on get_class() prefix — a PHPUnit mock's
		// generated class name drops the namespace, so use the real named
		// fixture below: OCA\KanzleiCheck\Tests\Unit\* still matches.
		$this->controller = new FakeKzcController('kanzleicheck', $this->createMock(IRequest::class));
	}

	private function path(string $p): void
	{
		$this->request->method('getPathInfo')->willReturn('/index.php/apps/kanzleicheck' . $p);
	}

	private function authed(string $uid = 'alice'): void
	{
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($u);
	}

	public function testCookieSessionNeverGated(): void
	{
		$this->path('/api/portal/inbox');
		$this->request->method('getHeader')->with('Authorization')->willReturn('');
		$this->license->expects($this->never())->method('seatGate');
		$this->mw->beforeController($this->controller, 'inbox');
	}

	public function testCompanionDeviceRoutesNeverGated(): void
	{
		$this->path('/api/companion/v1/bootstrap');
		$this->request->method('getHeader')->with('Authorization')->willReturn('Basic abc');
		$this->license->expects($this->never())->method('seatGate');
		$this->mw->beforeController($this->controller, 'bootstrap');
	}

	public function testAppPasswordHitsSeatGateOk(): void
	{
		$this->path('/api/portal/inbox');
		$this->request->method('getHeader')->with('Authorization')->willReturn('Basic dXNlcjpwYXNz');
		$this->authed('alice');
		$this->license->expects($this->once())->method('seatGate')->with('alice')->willReturn('ok');
		$this->mw->beforeController($this->controller, 'inbox');
	}

	public function testBearerTokenAlsoGated(): void
	{
		$this->path('/api/portal/uploads');
		$this->request->method('getHeader')->with('Authorization')->willReturn('Bearer tok');
		$this->authed();
		$this->license->expects($this->once())->method('seatGate')->willReturn('ok');
		$this->mw->beforeController($this->controller, 'uploads');
	}

	public function testNoSeatThrows402(): void
	{
		$this->path('/api/portal/inbox');
		$this->request->method('getHeader')->willReturn('Basic x');
		$this->authed();
		$this->license->method('seatGate')->willReturn('NO_MOBILE_SEAT');
		try {
			$this->mw->beforeController($this->controller, 'inbox');
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame(402, $e->getStatus());
			$this->assertSame('NO_MOBILE_SEAT', $e->getErrorCode());
		}
	}

	public function testLicenseExpiredThrows402(): void
	{
		$this->path('/api/portal/inbox');
		$this->request->method('getHeader')->willReturn('Basic x');
		$this->authed();
		$this->license->method('seatGate')->willReturn('LICENSE_EXPIRED');
		try {
			$this->mw->beforeController($this->controller, 'inbox');
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame(402, $e->getStatus());
			$this->assertSame('LICENSE_EXPIRED', $e->getErrorCode());
		}
	}

	public function testForgedHeaderWithoutSessionDefersToDoor(): void
	{
		$this->path('/api/portal/inbox');
		$this->request->method('getHeader')->willReturn('Basic garbage');
		$this->userSession->method('getUser')->willReturn(null);
		$this->license->expects($this->never())->method('seatGate');
		$this->mw->beforeController($this->controller, 'inbox');
	}

	public function testForeignAppUntouched(): void
	{
		// A controller outside the app namespace must never be seat-gated —
		// the foreign fixture lives in the OCP\ namespace.
		$foreign = new \OCP\AppFramework\FakeForeignController('otherapp', $this->createMock(IRequest::class));
		$this->request->method('getPathInfo')->willReturn('/index.php/apps/kanzleicheck/api/portal/inbox');
		$this->request->method('getHeader')->willReturn('Basic x');
		$this->license->expects($this->never())->method('seatGate');
		$this->mw->beforeController($foreign, 'anything');
	}

	public function testNonIndexPathStillMatches(): void
	{
		// pretty-URL installs serve /apps/<id>/... without index.php
		$this->request->method('getPathInfo')->willReturn('/apps/kanzleicheck/api/portal/inbox');
		$this->request->method('getHeader')->with('Authorization')->willReturn('Basic x');
		$this->authed();
		$this->license->expects($this->once())->method('seatGate')->willReturn('ok');
		$this->mw->beforeController($this->controller, 'inbox');
	}
}


/** Fixture: namespaced under OCA\KanzleiCheck\Tests\Unit so the
 *  middleware's class-prefix check takes the app branch. */
class FakeKzcController extends Controller
{
	public function __construct(string $appName, IRequest $request)
	{
		parent::__construct($appName, $request);
	}
}
