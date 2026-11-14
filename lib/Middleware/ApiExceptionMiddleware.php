<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Middleware;

use OCA\KanzleiCheck\AppInfo\Application;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\ConflictException;
use OCA\KanzleiCheck\Exception\DeviceRevokedException;
use OCA\KanzleiCheck\Exception\ForbiddenException;
use OCA\KanzleiCheck\Exception\NotFoundException;
use OCA\KanzleiCheck\Exception\RateLimitException;
use OCA\KanzleiCheck\Exception\UnauthorizedException;
use OCA\KanzleiCheck\Exception\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Exception → uniform envelope: {success:false,error,message,fields}.
 * NOT_FOUND collapses every miss (foreign-org id OR nonexistent) into one
 * canonical payload — status, headers and body byte-identical (G-01).
 *
 * Page routes (staff UI + portal GETs — every path without `/api/`) never get
 * the JSON envelope: gate failures render the access-denied page, anything
 * else rethrows so core can render its HTML error page. Raw JSON on a page
 * route is a dead surface for humans (kzc-intake-denied-staff).
 */
class ApiExceptionMiddleware extends Middleware
{
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly IRequest $request,
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response
	{
		if (!str_starts_with(get_class($controller), 'OCA\\KanzleiCheck\\')) {
			throw $exception;
		}
		if ($this->isPageRequest()) {
			return $this->pageError($exception);
		}
		if ($exception instanceof NotFoundException || $exception instanceof \OCP\AppFramework\Db\DoesNotExistException) {
			return self::notFound();
		}
		if ($exception instanceof DeviceRevokedException) {
			return self::fail(401, 'DEVICE_REVOKED', 'This device was signed out.');
		}
		if ($exception instanceof UnauthorizedException) {
			return self::fail(401, 'UNAUTHORIZED', 'Not authenticated.');
		}
		if ($exception instanceof ForbiddenException) {
			return self::fail(403, 'FORBIDDEN', 'No access.');
		}
		if ($exception instanceof RateLimitException) {
			$r = self::fail(429, 'RATE_LIMITED', 'Please wait and try again.');
			$r->addHeader('Retry-After', (string)$exception->getRetryAfter());
			return $r;
		}
		if ($exception instanceof ConflictException) {
			$body = ['success' => false, 'error' => 'CONFLICT', 'message' => 'Someone just changed this.', 'fields' => (object)[]];
			if ($exception->getCurrent() !== null) {
				$body['data'] = $exception->getCurrent();
			}
			return new JSONResponse($body, 409);
		}
		if ($exception instanceof ValidationException) {
			return self::fail(400, 'VALIDATION_FAILED', 'Please check the highlighted fields.', $exception->getFields());
		}
		if ($exception instanceof ApiException) {
			$r = self::fail($exception->getStatus(), $exception->getErrorCode(), $exception->getMessage(), $exception->getFields());
			if ($exception->getRetryAfter() !== null) {
				$r->addHeader('Retry-After', (string)$exception->getRetryAfter());
			}
			return $r;
		}
		$this->logger->error('kanzleicheck unhandled exception', ['exception' => $exception->getMessage()]);
		return self::fail(500, 'INTERNAL', 'Something went wrong.');
	}

	public static function notFound(): JSONResponse
	{
		return self::fail(404, 'NOT_FOUND', 'Not found.');
	}

	private static function fail(int $status, string $code, string $message, array $fields = []): JSONResponse
	{
		return new JSONResponse([
			'success' => false, 'error' => $code, 'message' => $message, 'fields' => (object)$fields,
		], $status);
	}

	/** HTML page GET (not an `/api/` route) — humans, not API clients. */
	private function isPageRequest(): bool
	{
		$rest = $this->restPath();
		return $rest !== null
			&& !str_contains($rest, '/api/')
			&& $this->request->getMethod() === 'GET';
	}

	private function restPath(): ?string
	{
		$path = $this->request->getPathInfo() ?: '';
		foreach (['/index.php/apps/' . Application::APP_ID, '/apps/' . Application::APP_ID] as $p) {
			if (str_starts_with($path, $p)) {
				return substr($path, strlen($p));
			}
		}
		return null;
	}

	private function pageError(\Exception $exception): TemplateResponse
	{
		$l = $this->l10nFactory->get(Application::APP_ID);
		if ($exception instanceof ForbiddenException) {
			return (new TemplateResponse(Application::APP_ID, 'access-denied', [
				'title' => $l->t('Access denied'),
				'message' => $l->t('You do not have access to KanzleiCheck.'),
				'hint' => $l->t('Next step: ask a Nextcloud administrator to add your account under Settings → Access (door), or to grant you the App Admin role.'),
				'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
			], 'guest'))->setStatus(Http::STATUS_FORBIDDEN);
		}
		if ($exception instanceof UnauthorizedException) {
			return (new TemplateResponse(Application::APP_ID, 'access-denied', [
				'title' => $l->t('Access denied'),
				'message' => $l->t('Please sign in to continue.'),
				'hint' => '',
				'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
			], 'guest'))->setStatus(Http::STATUS_UNAUTHORIZED);
		}
		if ($exception instanceof NotFoundException || $exception instanceof \OCP\AppFramework\Db\DoesNotExistException) {
			return (new TemplateResponse(Application::APP_ID, 'access-denied', [
				'title' => $l->t('Not found'),
				'message' => $l->t('Not found.'),
				'hint' => '',
				'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
			], 'guest'))->setStatus(Http::STATUS_NOT_FOUND);
		}
		// Non-gate failure on a page route: rethrow so core renders its HTML
		// error page — a JSON envelope is a dead surface in a browser.
		throw $exception;
	}
}
