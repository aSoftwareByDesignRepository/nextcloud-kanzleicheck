<?php

declare(strict_types=1);

/*
 * Copied from bookingcheck@31c2669e70 lib/Middleware/RateLimitMiddleware.php
 * (COPIED-SOURCES.md) — KanzleiCheck rule table (kzc_* actions).
 */

namespace OCA\KanzleiCheck\Middleware;

use OCA\KanzleiCheck\Exception\RateLimitException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;

/** Per-IP throttling via NC bruteforce on public write endpoints. */
class RateLimitMiddleware extends Middleware
{
	private const RULES = [
		['needles' => ['/portal/api/auth/send'], 'verbs' => ['POST'], 'action' => 'kzc_otp_send', 'sleep' => 250],
		['needles' => ['/portal/api/auth/verify'], 'verbs' => ['POST'], 'action' => 'kzc_otp_verify', 'sleep' => 400],
		['needles' => ['/portal/api/u/'], 'verbs' => ['POST'], 'action' => 'kzc_upload_link', 'sleep' => 300],
		['needles' => ['/portal/u/'], 'verbs' => ['GET'], 'action' => 'kzc_ulink_page', 'sleep' => 200],
		['needles' => ['/api/companion/v1/pair'], 'verbs' => ['POST'], 'action' => 'kzc_device_pair', 'sleep' => 400],
		['needles' => ['/approvals/', '/start'], 'verbs' => ['POST'], 'action' => 'kzc_otp_send', 'sleep' => 250],
		['needles' => ['/approvals/', '/decide'], 'verbs' => ['POST'], 'action' => 'kzc_otp_verify', 'sleep' => 400],
		// authenticated write surfaces — bound abusive sessions/companions (L-02)
		['needles' => ['/portal/api/orgs/', '/messages'], 'verbs' => ['POST'], 'action' => 'kzc_portal_write', 'sleep' => 150],
		['needles' => ['/portal/api/orgs/', '/uploads'], 'verbs' => ['POST'], 'action' => 'kzc_portal_write', 'sleep' => 150],
		['needles' => ['/portal/api/orgs/', '/chunks'], 'verbs' => ['POST'], 'action' => 'kzc_portal_write', 'sleep' => 150],
		['needles' => ['/portal/api/chunks/'], 'verbs' => ['POST'], 'action' => 'kzc_portal_write', 'sleep' => 100],
		['needles' => ['/portal/api/orgs/', '/notify'], 'verbs' => ['PUT'], 'action' => 'kzc_portal_write', 'sleep' => 150],
		['needles' => ['/portal/api/forms/'], 'verbs' => ['PUT', 'POST'], 'action' => 'kzc_portal_write', 'sleep' => 150],
		['needles' => ['/portal/api/requests/'], 'verbs' => ['POST'], 'action' => 'kzc_portal_write', 'sleep' => 150],
		['needles' => ['/api/companion/v1/orgs/', '/messages'], 'verbs' => ['POST'], 'action' => 'kzc_portal_write', 'sleep' => 150],
		['needles' => ['/api/companion/v1/orgs/', '/uploads'], 'verbs' => ['POST'], 'action' => 'kzc_portal_write', 'sleep' => 150],
		['needles' => ['/api/companion/v1/uploads/'], 'verbs' => ['PUT', 'POST'], 'action' => 'kzc_portal_write', 'sleep' => 100],
		['needles' => ['/api/companion/v1/forms/'], 'verbs' => ['PUT', 'POST'], 'action' => 'kzc_portal_write', 'sleep' => 150],
		['needles' => ['/api/companion/v1/requests/'], 'verbs' => ['POST'], 'action' => 'kzc_portal_write', 'sleep' => 150],
	];

	public function __construct(
		private readonly IRequest $request,
		private readonly IThrottler $throttler,
		private readonly \OCP\IConfig $config,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		if (!str_starts_with(get_class($controller), 'OCA\\KanzleiCheck\\')) {
			return;
		}
		$path = $this->request->getPathInfo() ?: '';
		$method = strtoupper((string)$this->request->getMethod());
		foreach (self::RULES as $rule) {
			if (!in_array($method, $rule['verbs'], true)) {
				continue;
			}
			$hit = true;
			foreach ($rule['needles'] as $needle) {
				if (!str_contains($path, $needle)) {
					$hit = false;
					break;
				}
			}
			if (!$hit) {
				continue;
			}
			$ip = $this->request->getRemoteAddress() ?: 'unknown';
			try {
				$delay = $this->throttler->sleepDelayOrThrowOnMax($ip, $rule['action']);
			} catch (\Exception) {
				throw new RateLimitException(60);
			}
			if ($delay > 0 && !$this->config->getSystemValueBool('auth.bruteforce.protection.testing')) {
				usleep($delay * 1000);
			}
			$this->throttler->registerAttempt($rule['action'], $ip, ['route' => $rule['needles'][0]]);
			return;
		}
	}
}
