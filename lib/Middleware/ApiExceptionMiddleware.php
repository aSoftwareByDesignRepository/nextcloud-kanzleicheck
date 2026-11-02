<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Middleware;

use OCA\PortalCheck\Exception\ApiException;
use OCA\PortalCheck\Exception\ConflictException;
use OCA\PortalCheck\Exception\DeviceRevokedException;
use OCA\PortalCheck\Exception\ForbiddenException;
use OCA\PortalCheck\Exception\NotFoundException;
use OCA\PortalCheck\Exception\RateLimitException;
use OCA\PortalCheck\Exception\UnauthorizedException;
use OCA\PortalCheck\Exception\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use Psr\Log\LoggerInterface;

/**
 * Exception → uniform envelope: {success:false,error,message,fields}.
 * NOT_FOUND collapses every miss (foreign-org id OR nonexistent) into one
 * canonical payload — status, headers and body byte-identical (G-01).
 */
class ApiExceptionMiddleware extends Middleware
{
	public function __construct(private readonly LoggerInterface $logger)
	{
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): JSONResponse
	{
		if (!str_starts_with(get_class($controller), 'OCA\\PortalCheck\\')) {
			throw $exception;
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
		$this->logger->error('portalcheck unhandled exception', ['exception' => $exception->getMessage()]);
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
}
