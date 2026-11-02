<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Controller;

use OCP\AppFramework\Http\JSONResponse;

trait ApiTrait
{
	protected function ok(array $data = [], int $status = 200): JSONResponse
	{
		return new JSONResponse(['success' => true, 'data' => $data], $status, [], JSON_INVALID_UTF8_SUBSTITUTE);
	}

	protected function strictId(mixed $raw): ?int
	{
		if (is_int($raw)) {
			return $raw > 0 ? $raw : null;
		}
		if (is_string($raw) && preg_match('/^\d+$/', $raw)) {
			$v = (int)$raw;
			return $v > 0 ? $v : null;
		}
		return null;
	}
}
