<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck {
	// Minimal controller inside the app namespace — the middleware only
	// handles OCA\KanzleiCheck\* exceptions, everything else rethrows.
	class ApiExceptionDummyController extends \OCP\AppFramework\Controller {
	}
}

namespace OCA\KanzleiCheck\Tests\Unit {

	use OCA\KanzleiCheck\ApiExceptionDummyController;
	use OCA\KanzleiCheck\Exception\NotFoundException;
	use OCA\KanzleiCheck\Exception\RateLimitException;
	use OCA\KanzleiCheck\Middleware\ApiExceptionMiddleware;
	use OCP\AppFramework\Http;
	use OCP\AppFramework\Http\JSONResponse;
	use OCP\AppFramework\Http\TemplateResponse;
	use OCP\IRequest;
	use OCP\IURLGenerator;
	use OCP\L10N\IFactory;
	use OCP\IL10N;
	use PHPUnit\Framework\TestCase;
	use Psr\Log\LoggerInterface;

	/**
	 * Page routes (non-/api/ GET) must render the access-denied template —
	 * including the rate-limit gate: rethrowing RateLimitException to core
	 * turns a throttled upload-link page into a generic 500 for the user
	 * that just refreshed it.
	 */
	class ApiExceptionMiddlewareTest extends TestCase
	{
		private const PREFIX = '/index.php/apps/kanzleicheck';

		private function middlewareFor(string $rest, string $method): ApiExceptionMiddleware {
			$req = $this->createMock(IRequest::class);
			$req->method('getPathInfo')->willReturn(self::PREFIX . $rest);
			$req->method('getMethod')->willReturn($method);
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(fn(string $t): string => $t);
			$l10nFactory = $this->createMock(IFactory::class);
			$l10nFactory->method('get')->willReturn($l10n);
			$url = $this->createMock(IURLGenerator::class);
			$url->method('linkToDefaultPageUrl')->willReturn('/');
			return new ApiExceptionMiddleware(
				$this->createMock(LoggerInterface::class), $req, $l10nFactory, $url
			);
		}

		private function controller(): ApiExceptionDummyController {
			return new ApiExceptionDummyController('kanzleicheck', $this->createMock(IRequest::class));
		}

		/** Response::getHeaders() needs \OC::$server — read the raw map. */
		private function headerOf(\OCP\AppFramework\Http\Response $r, string $name): ?string {
			$p = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
			$h = $p->getValue($r);
			return $h[$name] ?? null;
		}

		public function testPageRateLimitRendersTemplateWithRetryAfter(): void
		{
			$mw = $this->middlewareFor('/portal/u/abc123', 'GET');
			$r = $mw->afterException($this->controller(), 'ulinkPage', new RateLimitException(60));
			self::assertInstanceOf(TemplateResponse::class, $r);
			self::assertSame(Http::STATUS_TOO_MANY_REQUESTS, $r->getStatus());
			self::assertSame('access-denied', $r->getTemplateName());
			self::assertSame('60', $this->headerOf($r, 'Retry-After'));
			self::assertSame('Too many requests', $r->getParams()['title'] ?? null);
		}

		public function testApiRateLimitReturnsJson429WithRetryAfter(): void
		{
			$mw = $this->middlewareFor('/portal/api/auth/verify', 'POST');
			$r = $mw->afterException($this->controller(), 'verify', new RateLimitException(45));
			self::assertInstanceOf(JSONResponse::class, $r);
			self::assertSame(429, $r->getStatus());
			self::assertSame('45', $this->headerOf($r, 'Retry-After'));
			self::assertSame('RATE_LIMITED', json_decode(json_encode($r->getData()), true)['error'] ?? null);
		}

		public function testRateLimitOnNonGetNonApiFallsToJson(): void
		{
			// Only GET counts as a page request — a POST to a non-/api/ route
			// (form target) still gets the JSON envelope, never core's 500.
			$mw = $this->middlewareFor('/portal/u/abc123', 'POST');
			$r = $mw->afterException($this->controller(), 'ulinkPost', new RateLimitException(60));
			self::assertInstanceOf(JSONResponse::class, $r);
			self::assertSame(429, $r->getStatus());
		}

		public function testPageNotFoundStillRendersTemplate(): void
		{
			$mw = $this->middlewareFor('/portal/u/abc123', 'GET');
			$r = $mw->afterException($this->controller(), 'ulinkPage', new NotFoundException());
			self::assertInstanceOf(TemplateResponse::class, $r);
			self::assertSame(Http::STATUS_NOT_FOUND, $r->getStatus());
		}

		// ── exception ladder on /api/ — status + code + envelope ──

		private function apiError(string $rest, string $method, \Exception $e): array
		{
			$mw = $this->middlewareFor($rest, $method);
			$r = $mw->afterException($this->controller(), 'm', $e);
			self::assertInstanceOf(JSONResponse::class, $r);
			return [$r->getStatus(), json_decode(json_encode($r->getData()), true)];
		}

		public function testApiNotFoundCollapsesTo404(): void
		{
			foreach ([new NotFoundException(), new \OCP\AppFramework\Db\DoesNotExistException('x')] as $e) {
				[$status, $body] = $this->apiError('/api/portal/x', 'GET', $e);
				self::assertSame(404, $status);
				self::assertSame('NOT_FOUND', $body['error']);
				self::assertSame('Not found.', $body['message']);
				self::assertFalse($body['success']);
				self::assertSame([], (array)$body['fields']);
			}
		}

		public function testApiDeviceRevokedAndUnauthorizedAre401(): void
		{
			[$s1, $b1] = $this->apiError('/api/x', 'GET', new \OCA\KanzleiCheck\Exception\DeviceRevokedException());
			self::assertSame(401, $s1);
			self::assertSame('DEVICE_REVOKED', $b1['error']);
			self::assertSame('This device was signed out.', $b1['message']);
			[$s2, $b2] = $this->apiError('/api/x', 'GET', new \OCA\KanzleiCheck\Exception\UnauthorizedException());
			self::assertSame(401, $s2);
			self::assertSame('UNAUTHORIZED', $b2['error']);
		}

		public function testApiForbidden403(): void
		{
			[$s, $b] = $this->apiError('/api/x', 'GET', new \OCA\KanzleiCheck\Exception\ForbiddenException());
			self::assertSame(403, $s);
			self::assertSame('FORBIDDEN', $b['error']);
			self::assertSame('No access.', $b['message']);
		}

		public function testApiConflictCarriesCurrentVersion(): void
		{
			$raw = $this->middlewareFor('/api/x', 'POST')
				->afterException($this->controller(), 'm', new \OCA\KanzleiCheck\Exception\ConflictException());
			$d = $raw->getData();
			self::assertFalse($d['success']);
			self::assertSame('Someone just changed this.', $d['message']);
			self::assertEquals((object)[], $d['fields']); // fields serialises as {} not []
			[$s, $b] = $this->apiError('/api/x', 'POST', new \OCA\KanzleiCheck\Exception\ConflictException());
			self::assertSame(409, $s);
			self::assertSame('CONFLICT', $b['error']);
			self::assertArrayNotHasKey('data', $b); // no current → key absent
			$withCurrent = new \OCA\KanzleiCheck\Exception\ConflictException('CONFLICT', ['v' => 2]);
			[$s2, $b2] = $this->apiError('/api/x', 'POST', $withCurrent);
			self::assertSame(['v' => 2], $b2['data']);
		}

		public function testApiValidationFailedCarriesFields(): void
		{
			[$s, $b] = $this->apiError('/api/x', 'POST',
				new \OCA\KanzleiCheck\Exception\ValidationException('VALIDATION_FAILED', ['email' => 'bad']));
			self::assertSame(400, $s);
			self::assertSame('VALIDATION_FAILED', $b['error']);
			self::assertSame(['email' => 'bad'], (array)$b['fields']);
		}

		public function testApiExceptionStatusCodeFieldsAndRetryAfter(): void
		{
			$e = new \OCA\KanzleiCheck\Exception\ApiException(402, 'NO_MOBILE_SEAT', 'seat needed', ['seat' => 1], 30);
			$mw = $this->middlewareFor('/api/portal/orgs', 'GET');
			$r = $mw->afterException($this->controller(), 'orgs', $e);
			self::assertSame(402, $r->getStatus());
			self::assertSame('30', $this->headerOf($r, 'Retry-After'));
			$b = json_decode(json_encode($r->getData()), true);
			self::assertSame('NO_MOBILE_SEAT', $b['error']);
			self::assertSame('seat needed', $b['message']);
			self::assertSame(['seat' => 1], (array)$b['fields']);
			// no retryAfter → no header
			$e2 = new \OCA\KanzleiCheck\Exception\ApiException(403, 'NO_SHARE_PERM', 'x');
			$r2 = $this->middlewareFor('/api/x', 'GET')->afterException($this->controller(), 'm', $e2);
			self::assertNull($this->headerOf($r2, 'Retry-After'));
		}

		public function testUnknownExceptionLogsAndReturns500(): void
		{
			$req = $this->createMock(IRequest::class);
			$req->method('getPathInfo')->willReturn(self::PREFIX . '/api/x');
			$req->method('getMethod')->willReturn('GET');
			$l10nF = $this->createMock(IFactory::class);
			$l10nF->method('get')->willReturn($this->createMock(IL10N::class));
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('error')
				->with('kanzleicheck unhandled exception', $this->callback(fn($c) => $c['exception'] === 'boom'));
			$mw = new ApiExceptionMiddleware($logger, $req, $l10nF, $this->createMock(IURLGenerator::class));
			$r = $mw->afterException($this->controller(), 'm', new \RuntimeException('boom'));
			self::assertSame(500, $r->getStatus());
			self::assertSame('INTERNAL', json_decode(json_encode($r->getData()), true)['error']);
		}

		public function testForeignControllerRethrows(): void
		{
			$mw = $this->middlewareFor('/api/x', 'GET');
			$this->expectException(\RuntimeException::class);
			$mw->afterException($this->createMock(\OCP\AppFramework\Controller::class), 'm', new \RuntimeException('x'));
		}

		public function testNonAppPathIsNotAPageRequest(): void
		{
			// path outside /apps/kanzleicheck → restPath null → JSON envelope
			$req = $this->createMock(IRequest::class);
			$req->method('getPathInfo')->willReturn('/apps/otherapp/x');
			$req->method('getMethod')->willReturn('GET');
			$mw = new ApiExceptionMiddleware(
				$this->createMock(LoggerInterface::class), $req,
				$this->createMock(IFactory::class), $this->createMock(IURLGenerator::class));
			$r = $mw->afterException($this->controller(), 'm', new NotFoundException());
			self::assertInstanceOf(JSONResponse::class, $r);
			self::assertSame(404, $r->getStatus());
		}

		// ── pageError branches ──

		public function testPageForbiddenRendersDenied403(): void
		{
			$mw = $this->middlewareFor('/settings/access', 'GET');
			$r = $mw->afterException($this->controller(), 'settings',
				new \OCA\KanzleiCheck\Exception\ForbiddenException());
			self::assertInstanceOf(TemplateResponse::class, $r);
			self::assertSame(403, $r->getStatus());
			self::assertSame('Access denied', $r->getParams()['title']);
			self::assertSame('You do not have access to KanzleiCheck.', $r->getParams()['message']);
			self::assertStringContainsString('Access (door)', $r->getParams()['hint']);
			self::assertSame('/', $r->getParams()['homeUrl']);
		}

		public function testPageNotFoundParams(): void
		{
			$mw = $this->middlewareFor('/portal/u/abc', 'GET');
			$r = $mw->afterException($this->controller(), 'm', new NotFoundException());
			self::assertSame('Not found', $r->getParams()['title']);
			self::assertSame('Not found.', $r->getParams()['message']);
			self::assertSame('', $r->getParams()['hint']);
		}

		public function testAlternatePathPrefixAndAppRoot(): void
		{
			// /apps/kanzleicheck (no index.php) is also a page route
			$req = $this->createMock(IRequest::class);
			$req->method('getPathInfo')->willReturn('/apps/kanzleicheck/settings/access');
			$req->method('getMethod')->willReturn('GET');
			$mw = new ApiExceptionMiddleware(
				$this->createMock(LoggerInterface::class), $req,
				$this->l10n(), $this->urlGen());
			$r = $mw->afterException($this->controller(), 'm',
				new \OCA\KanzleiCheck\Exception\ForbiddenException());
			self::assertInstanceOf(TemplateResponse::class, $r);
			self::assertSame(403, $r->getStatus());

			// app root itself (rest='') is a page too
			$req2 = $this->createMock(IRequest::class);
			$req2->method('getPathInfo')->willReturn('/apps/kanzleicheck');
			$req2->method('getMethod')->willReturn('GET');
			$mw2 = new ApiExceptionMiddleware(
				$this->createMock(LoggerInterface::class), $req2,
				$this->l10n(), $this->urlGen());
			$r2 = $mw2->afterException($this->controller(), 'm', new NotFoundException());
			self::assertInstanceOf(TemplateResponse::class, $r2);
		}

		private function l10n(): IFactory&\PHPUnit\Framework\MockObject\MockObject
		{
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(fn(string $t): string => $t);
			$f = $this->createMock(IFactory::class);
			$f->method('get')->willReturn($l10n);
			return $f;
		}

		private function urlGen(): IURLGenerator&\PHPUnit\Framework\MockObject\MockObject
		{
			$u = $this->createMock(IURLGenerator::class);
			$u->method('linkToDefaultPageUrl')->willReturn('/');
			return $u;
		}

		public function testPageUnauthorizedRenders401(): void
		{
			$mw = $this->middlewareFor('/portal/u/abc', 'GET');
			$r = $mw->afterException($this->controller(), 'm',
				new \OCA\KanzleiCheck\Exception\UnauthorizedException());
			self::assertSame(401, $r->getStatus());
			self::assertSame('Please sign in to continue.', $r->getParams()['message']);
		}

		public function testPageUnhandledRethrows(): void
		{
			$mw = $this->middlewareFor('/portal/u/abc', 'GET');
			$this->expectException(\RuntimeException::class);
			$mw->afterException($this->controller(), 'm', new \RuntimeException('db down'));
		}
	}
}
