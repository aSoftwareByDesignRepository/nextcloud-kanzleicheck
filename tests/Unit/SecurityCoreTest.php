<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\DocRequest;
use OCA\KanzleiCheck\Db\DocRequestMapper;
use OCA\KanzleiCheck\Db\ReqFileMapper;
use OCA\KanzleiCheck\Db\ReqItemMapper;
use OCA\KanzleiCheck\Db\ReqRemindMapper;
use OCA\KanzleiCheck\Db\UploadMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCA\KanzleiCheck\Exception\ConflictException;
use OCA\KanzleiCheck\Exception\RateLimitException;
use OCA\KanzleiCheck\Service\AuditService;
use OCA\KanzleiCheck\Service\CsrfService;
use OCA\KanzleiCheck\Service\IntakeService;
use OCA\KanzleiCheck\Service\MailRenderer;
use OCA\KanzleiCheck\Service\RequestService;
use OCA\KanzleiCheck\Service\TokenService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/** Pure-logic coverage: token/CSRF/intake/mail-renderer/request-state-machines. */
class SecurityCoreTest extends TestCase
{
	// ── TokenService ──
	public function testMintProducesUrlSafeTokenAndStableHash(): void
	{
		$s = new TokenService();
		[$token, $hash] = $s->mint();
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{40,50}$/', $token);
		$this->assertSame(64, strlen($hash));
		$this->assertSame($hash, $s->hash($token));
	}

	public function testVerifyIsHashEquals(): void
	{
		$s = new TokenService();
		[$t, $h] = $s->mint();
		$this->assertTrue($s->verify($t, $h));
		$this->assertFalse($s->verify($t . 'x', $h));
		$this->assertFalse($s->verify('', $h));
		[$t2] = $s->mint();
		$this->assertFalse($s->verify($t2, $h));
	}

	public function testOtpIsSixDigitsRandom(): void
	{
		$s = new TokenService();
		$seen = [];
		for ($i = 0; $i < 50; $i++) {
			$seen[] = $s->mintOtp();
		}
		foreach ($seen as $c) {
			$this->assertMatchesRegularExpression('/^\d{6}$/', $c);
		}
		$this->assertGreaterThan(5, count(array_unique($seen))); // randomness sanity
	}

	public function testIntakeTokenIsDeterministicOrgBound(): void
	{
		$s = new TokenService();
		$a = $s->intakeToken('secret', 7);
		$this->assertSame($a, $s->intakeToken('secret', 7));
		$this->assertNotSame($a, $s->intakeToken('secret', 8));
		$this->assertNotSame($a, $s->intakeToken('other', 7));
		$this->assertSame(16, strlen($a));
		// exact value pins the HMAC salt + truncation (kills concat/substr mutants)
		$this->assertSame(substr(hash_hmac('sha256', 'kzc-intake:7', 'secret'), 0, 16), $a);
	}

	public function testMintEntropyIsExactly32Bytes(): void
	{
		$s = new TokenService();
		[$token] = $s->mint();
		$decoded = base64_decode(strtr($token, '-_', '+/'), true);
		$this->assertNotFalse($decoded);
		$this->assertSame(32, strlen($decoded));
		$this->assertSame(43, strlen($token)); // 32B → 43 url-safe chars
	}

	public function testMintFloorIsOneByte(): void
	{
		$s = new TokenService();
		[$t0] = $s->mint(0);
		$this->assertNotSame('', $t0); // zero bytes must still mint a usable token
		[$t1] = $s->mint(1);
		$decoded = base64_decode(strtr($t1, '-_', '+/'), true);
		$this->assertNotFalse($decoded);
		$this->assertSame(1, strlen($decoded));
		[$t2] = $s->mint(2);
		$this->assertSame(2, strlen(base64_decode(strtr($t2, '-_', '+/'), true)));
	}

	// ── CsrfService ──
	public function testCsrfBoundToSessionToken(): void
	{
		$c = new CsrfService();
		$t1 = $c->tokenFor('session-A');
		$t2 = $c->tokenFor('session-B');
		$this->assertNotSame($t1, $t2);
		$this->assertTrue($c->validate('session-A', $t1));
		$this->assertFalse($c->validate('session-A', $t2));
		$this->assertFalse($c->validate('session-A', ''));
		$this->assertFalse($c->validate('session-A', str_repeat('x', 200)));
	}

	// ── IntakeService::parseAddress ──
	public function testIntakeAddressParsing(): void
	{
		$this->assertSame(['orgId' => 7, 'token' => 'abcdef0123456789'],
			IntakeService::parseAddress('portal+in-7-abcdef0123456789@cloud.example.de'));
		$this->assertNull(IntakeService::parseAddress('portal@cloud.example.de'));
		$this->assertNull(IntakeService::parseAddress('portal+in-7-short@x.de'));
		$this->assertNull(IntakeService::parseAddress('not-an-email'));
	}

	// ── MailRenderer: content-free (NN-P04) ──
	public function testAllMailsHaveSubjectAndBody(): void
	{
		foreach (['otp', 'approve', 'invite', 'remind', 'device', 'notice'] as $kind) {
			foreach (['de', 'en'] as $lang) {
				$m = MailRenderer::render($kind, ['code' => '123456', 'provider' => 'TestCo', 'portalUrl' => 'https://x', 'deadline' => '01.02.2026', 'deviceName' => 'Pixel', 'platform' => 'android', 'what' => 'message'], $lang);
				$this->assertNotEmpty($m['subject'], "$kind/$lang subject");
				$this->assertNotEmpty($m['text'], "$kind/$lang text");
				$this->assertStringContainsString('TestCo', $m['subject'] . $m['text']);
			}
		}
	}

	public function testOtpMailContainsCodeAndWarnings(): void
	{
		$m = MailRenderer::render('otp', ['code' => '998877', 'provider' => 'Co', 'portalUrl' => ''], 'de');
		$this->assertStringContainsString('998877', $m['text']);
		$this->assertStringContainsString('10 Minuten', $m['text']);
		$this->assertStringContainsString('nicht weiter', $m['text']); // never-forward warning (NN-P13)
	}

	public function testNoticeMailIsContentFree(): void
	{
		// no file names, no message text, no org names — only provider + what-kind
		$m = MailRenderer::render('notice', ['what' => 'document', 'provider' => 'Co'], 'de');
		$this->assertStringNotContainsString('invoice.pdf', $m['text']);
		$this->assertStringNotContainsString('Acme GmbH', $m['subject']);
	}

	// ── exceptions ──
	public function testApiExceptionContract(): void
	{
		$e = new ApiException(402, 'NO_PORTAL_SLOT', 'no slot', ['f' => 'x'], 30);
		$this->assertSame(402, $e->getStatus());
		$this->assertSame('NO_PORTAL_SLOT', $e->getErrorCode());
		$this->assertSame(30, $e->getRetryAfter());
		$this->assertSame(['f' => 'x'], $e->getFields());
	}

	public function testRateLimitAndConflict(): void
	{
		$this->assertSame(45, (new RateLimitException(45))->getRetryAfter());
		$c = new ConflictException('CONFLICT', ['rev' => 3]);
		$this->assertSame(['rev' => 3], $c->getCurrent());
	}

	// ── RequestService state helpers (real entity, no DB) ──
	public function testOverdueOnlyOpenWithPastDeadline(): void
	{
		$svc = new RequestService(
			$this->createMock(DocRequestMapper::class),
			$this->createMock(ReqItemMapper::class),
			$this->createMock(ReqRemindMapper::class),
			$this->createMock(AuditService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(UploadMapper::class),
			$this->createMock(ReqFileMapper::class),
		);
		$r = new DocRequest();
		$r->setState('open');
		$r->setDeadline(new \DateTimeImmutable('yesterday'));
		$this->assertTrue($svc->overdue($r));
		$r->setState('complete');
		$this->assertFalse($svc->overdue($r));
		$r->setState('open');
		$r->setDeadline(new \DateTimeImmutable('+3 days'));
		$this->assertFalse($svc->overdue($r));
		$r->setDeadline(null);
		$this->assertFalse($svc->overdue($r));
	}

	public function testDueRemindersPolicyExpansion(): void
	{
		$svc = new RequestService(
			$this->createMock(DocRequestMapper::class),
			$this->createMock(ReqItemMapper::class),
			$this->createMock(ReqRemindMapper::class),
			$this->createMock(AuditService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(UploadMapper::class),
			$this->createMock(ReqFileMapper::class),
		);
		$r = new DocRequest();
		$r->setState('open');
		$r->setDeadline(\DateTimeImmutable::createFromFormat('!Y-m-d', '2026-03-10'));
		$r->setReminderPolicy('-3,0,+3x5');
		// offsets -3,0 → 03-07, 03-10; then +3 step × remaining 3 → 03-13,16,19
		$due = $svc->dueReminders($r, new \DateTimeImmutable('2026-03-19'));
		$this->assertSame(['2026-03-07', '2026-03-10', '2026-03-13', '2026-03-16', '2026-03-19'], $due);
		// earlier "today" cuts the tail
		$due = $svc->dueReminders($r, new \DateTimeImmutable('2026-03-14'));
		$this->assertSame(['2026-03-07', '2026-03-10', '2026-03-13'], $due);
		// no deadline → no reminders
		$r->setDeadline(null);
		$this->assertSame([], $svc->dueReminders($r, new \DateTimeImmutable('2026-03-19')));
	}
}
