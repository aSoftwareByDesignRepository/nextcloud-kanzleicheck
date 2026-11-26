<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Service\CsrfService;
use OCA\KanzleiCheck\Service\TokenService;
use PHPUnit\Framework\TestCase;

/**
 * TokenService + CsrfService — pure crypto, no mocks. Every property an
 * attacker cares about asserted: entropy size, URL-safety, constant-time
 * compare shape, OTP range, intake binding, CSRF session binding.
 */
class TokenCsrfServiceTest extends TestCase
{
	public function testMintShapeEntropyAndUniqueness(): void
	{
		$svc = new TokenService();
		[$tok, $hash] = $svc->mint();
		// base64url: no +, /, = padding — safe in URLs/cookies
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $tok);
		$this->assertSame(43, strlen($tok)); // 32 bytes → 43 b64url chars
		$this->assertSame(hash('sha256', $tok), $hash);
		$this->assertTrue($svc->verify($tok, $hash));
		// distinct mints → distinct tokens (collision = entropy failure)
		[$t2] = $svc->mint();
		$this->assertNotSame($tok, $t2);
		// wrong token → false (the compare must discriminate)
		$this->assertFalse($svc->verify('not-the-token', $hash));
		$this->assertFalse($svc->verify($tok . 'x', $hash));
	}

	public function testMintByteParameter(): void
	{
		$svc = new TokenService();
		[$t16] = $svc->mint(16);
		$this->assertSame(22, strlen($t16)); // 16 bytes → 22 chars
		[$t1] = $svc->mint(0); // clamped to 1 byte
		$this->assertGreaterThanOrEqual(2, strlen($t1));
	}

	public function testMintOtpRangeAndPadding(): void
	{
		$svc = new TokenService();
		$seen = [];
		for ($i = 0; $i < 500; $i++) {
			$code = $svc->mintOtp();
			$this->assertMatchesRegularExpression('/^\d{6}$/', $code); // always 6 digits
			$this->assertLessThanOrEqual(999999, (int)$code);
			$seen[$code] = true;
		}
		$this->assertGreaterThan(200, count($seen)); // real entropy, not a constant
	}

	public function testIntakeTokenIsOrgBoundHmac(): void
	{
		$svc = new TokenService();
		$t7 = $svc->intakeToken('sec', 7);
		$this->assertSame(16, strlen($t7));
		$this->assertSame($t7, $svc->intakeToken('sec', 7));   // deterministic
		$this->assertNotSame($t7, $svc->intakeToken('sec', 8)); // org-bound
		$this->assertNotSame($t7, $svc->intakeToken('oth', 7)); // secret-bound
		$this->assertSame(substr(hash_hmac('sha256', 'kzc-intake:7', 'sec'), 0, 16), $t7);
	}

	public function testCsrfBoundToSessionToken(): void
	{
		$svc = new CsrfService();
		$t = $svc->tokenFor('sess-a');
		$this->assertSame(hash_hmac('sha256', 'kzc-csrf', 'sess-a'), $t);
		$this->assertTrue($svc->validate('sess-a', $t));
		// token minted for session A never validates for session B
		$this->assertFalse($svc->validate('sess-b', $t));
		// wrong/empty token → false
		$this->assertFalse($svc->validate('sess-a', 'wrong'));
		$this->assertFalse($svc->validate('sess-a', ''));
		// overlong garbage → false without compare
		$this->assertFalse($svc->validate('sess-a', str_repeat('x', 129)));
		// exact boundary: 128 chars passes the length gate, still must match
		$this->assertFalse($svc->validate('sess-a', str_repeat('x', 128)));
	}
}
