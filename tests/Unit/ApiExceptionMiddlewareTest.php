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
	}
}
