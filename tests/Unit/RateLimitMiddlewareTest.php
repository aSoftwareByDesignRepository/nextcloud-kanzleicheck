<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck {
	// Minimal controller inside the app namespace — the middleware only
	// inspects OCA\KanzleiCheck\* controllers, everything else is skipped.
	class RateLimitDummyController extends \OCP\AppFramework\Controller {
	}
}

namespace OCA\KanzleiCheck\Tests\Unit {

	use OCA\KanzleiCheck\Middleware\RateLimitMiddleware;
	use OCA\KanzleiCheck\RateLimitDummyController;
	use OCP\AppFramework\Controller;
	use OCP\IConfig;
	use OCP\IRequest;
	use OCP\Security\Bruteforce\IThrottler;
	use PHPUnit\Framework\TestCase;

	/**
	 * Proves every RULES entry actually fires on its intended route+verb with
	 * the intended throttle action — and that unrelated requests register no
	 * attempt. A rule that silently never matches is worse than no rule.
	 */
	class RateLimitMiddlewareTest extends TestCase
	{
		private const PREFIX = '/index.php/apps/kanzleicheck';

		private function requestFor(string $rest, string $method): IRequest {
			$req = $this->createMock(IRequest::class);
			$req->method('getPathInfo')->willReturn(self::PREFIX . $rest);
			$req->method('getMethod')->willReturn($method);
			$req->method('getRemoteAddress')->willReturn('10.0.0.7');
			return $req;
		}

		private function controller(IRequest $req): Controller {
			return new RateLimitDummyController('kanzleicheck', $req);
		}

		public static function matchedCases(): array {
			return [
				'otp send' => ['POST', '/portal/api/auth/send', 'kzc_otp_send'],
				'otp verify' => ['POST', '/portal/api/auth/verify', 'kzc_otp_verify'],
				'ulink upload post' => ['POST', '/portal/api/u/abc123/files', 'kzc_upload_link'],
				'ulink page get' => ['GET', '/portal/u/abc123', 'kzc_ulink_page'],
				'device pair' => ['POST', '/api/companion/v1/pair', 'kzc_device_pair'],
				'approval start (otp send)' => ['POST', '/portal/api/approvals/7/start', 'kzc_otp_send'],
				'approval decide portal (otp verify)' => ['POST', '/portal/api/approvals/7/decide', 'kzc_otp_verify'],
				'approval decide companion (otp verify)' => ['POST', '/api/companion/v1/approvals/7/decide', 'kzc_otp_verify'],
				'portal message' => ['POST', '/portal/api/orgs/9/messages', 'kzc_portal_write'],
				'portal upload init' => ['POST', '/portal/api/orgs/9/uploads', 'kzc_portal_write'],
				'portal chunk start' => ['POST', '/portal/api/orgs/9/chunks', 'kzc_portal_write'],
				'portal chunk put' => ['POST', '/portal/api/chunks/key-1/0', 'kzc_portal_write'],
				'portal chunk finish' => ['POST', '/portal/api/chunks/key-1/finish', 'kzc_portal_write'],
				'portal notify put' => ['PUT', '/portal/api/orgs/9/notify', 'kzc_portal_write'],
				'portal form submit' => ['POST', '/portal/api/forms/3/submit', 'kzc_portal_write'],
				'portal form answer' => ['PUT', '/portal/api/forms/3/answers', 'kzc_portal_write'],
				'portal request deliver' => ['POST', '/portal/api/requests/5/items/1/deliver', 'kzc_portal_write'],
				'portal request confirm' => ['POST', '/portal/api/requests/5/items/1/confirm', 'kzc_portal_write'],
				'companion message' => ['POST', '/api/companion/v1/orgs/9/messages', 'kzc_portal_write'],
				'companion upload init' => ['POST', '/api/companion/v1/orgs/9/uploads/init', 'kzc_portal_write'],
				'companion chunk put' => ['PUT', '/api/companion/v1/uploads/key-1/chunks/0', 'kzc_portal_write'],
				'companion chunk finish' => ['POST', '/api/companion/v1/uploads/key-1/finish', 'kzc_portal_write'],
				'companion form submit' => ['POST', '/api/companion/v1/forms/3/submit', 'kzc_portal_write'],
				'companion request confirm' => ['POST', '/api/companion/v1/requests/5/items/1/confirm', 'kzc_portal_write'],
			];
		}

		/** @dataProvider matchedCases */
		public function testRuleFires(string $method, string $rest, string $action): void
		{
			$req = $this->requestFor($rest, $method);
			$throttler = $this->createMock(IThrottler::class);
			$throttler->method('sleepDelayOrThrowOnMax')->willReturn(0);
			$throttler->expects(self::once())->method('registerAttempt')
				->with($action, '10.0.0.7', self::anything());
			$config = $this->createMock(IConfig::class);
			$mw = new RateLimitMiddleware($req, $throttler, $config);
			$mw->beforeController($this->controller($req), 'anyMethod');
		}

		public static function unmatchedCases(): array {
			return [
				'GET portal messages (reads not throttled)' => ['GET', '/portal/api/orgs/9/messages'],
				'staff api write' => ['POST', '/api/portal/orgs'],
				'staff api settings' => ['PUT', '/api/portal/settings'],
				'companion read' => ['GET', '/api/companion/v1/orgs/9/messages'],
				'portal session get' => ['GET', '/portal/api/session'],
				'login page' => ['GET', '/portal/login'],
			];
		}

		/** @dataProvider unmatchedCases */
		public function testNoAttemptRegistered(string $method, string $rest): void
		{
			$req = $this->requestFor($rest, $method);
			$throttler = $this->createMock(IThrottler::class);
			$throttler->expects(self::never())->method('registerAttempt');
			$throttler->expects(self::never())->method('sleepDelayOrThrowOnMax');
			$config = $this->createMock(IConfig::class);
			$mw = new RateLimitMiddleware($req, $throttler, $config);
			$mw->beforeController($this->controller($req), 'anyMethod');
		}

		public function testForeignControllerSkipped(): void
		{
			$req = $this->requestFor('/portal/api/auth/send', 'POST');
			$throttler = $this->createMock(IThrottler::class);
			$throttler->expects(self::never())->method('registerAttempt');
			$config = $this->createMock(IConfig::class);
			$mw = new RateLimitMiddleware($req, $throttler, $config);
			$foreign = $this->createMock(Controller::class); // not OCA\KanzleiCheck\*
			$mw->beforeController($foreign, 'anyMethod');
		}
	}
}
