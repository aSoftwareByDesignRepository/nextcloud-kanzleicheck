<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Controller;

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

	/**
	 * Request-boundary scalars (api-security §2): `(int)["x"]` → 1 would
	 * silently forge a revision/taskId match, `(string)["x"]` → "Array"
	 * stores junk. Absent → default; anything non-scalar or non-conforming
	 * → 400 with a field pin.
	 */
	protected function intParam(string $name, int $default = 0): int
	{
		$raw = $this->request->getParam($name, null);
		if ($raw === null) {
			return $default;
		}
		if (is_int($raw)) {
			return $raw;
		}
		if (is_string($raw) && preg_match('/^-?\d+$/', $raw)) {
			return (int)$raw;
		}
		throw new \OCA\KanzleiCheck\Exception\ValidationException('VALIDATION_FAILED', [$name => 'invalid']);
	}

	protected function strParam(string $name, string $default = ''): string
	{
		$raw = $this->request->getParam($name, null);
		if ($raw === null) {
			return $default;
		}
		if (!is_string($raw)) {
			throw new \OCA\KanzleiCheck\Exception\ValidationException('VALIDATION_FAILED', [$name => 'invalid']);
		}
		return $raw;
	}

	protected function boolParam(string $name, bool $default = false): bool
	{
		$raw = $this->request->getParam($name, null);
		if ($raw === null) {
			return $default;
		}
		if (is_bool($raw)) {
			return $raw;
		}
		if ($raw === 1 || $raw === '1' || $raw === 'true') {
			return true;
		}
		if ($raw === 0 || $raw === '0' || $raw === 'false') {
			return false;
		}
		throw new \OCA\KanzleiCheck\Exception\ValidationException('VALIDATION_FAILED', [$name => 'invalid']);
	}
}
