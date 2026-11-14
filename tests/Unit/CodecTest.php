<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Config\VendorPublicKey;
use OCA\KanzleiCheck\License\Kzc2Codec;
use PHPUnit\Framework\TestCase;

/**
 * KZC2 licence codec — the gate for companion seats + portal-client slots.
 * Covers format, signature, payload schema, canonical encoding, expiry.
 */
class CodecTest extends TestCase
{
	private const TEST_PRIVATE_B64 = ''; // filled from sbdlicenseops fixture or generated

	private static string $secretKey;
	private static string $publicKeyB64;

	public static function setUpBeforeClass(): void
	{
		// generate a fresh Ed25519 keypair and point the codec at its public half
		$kp = sodium_crypto_sign_keypair();
		self::$secretKey = sodium_crypto_sign_secretkey($kp);
		self::$publicKeyB64 = VendorPublicKey::base64urlEncode(sodium_crypto_sign_publickey($kp));
		putenv('KZC_VENDOR_PUBLIC_KEY_B64=' . self::$publicKeyB64);
	}

	private function sign(array $payload): string
	{
		$canonical = Kzc2Codec::canonicalJson($payload);
		$sig = sodium_crypto_sign_detached($canonical, self::$secretKey);
		return 'KZC2.' . VendorPublicKey::base64urlEncode($canonical) . '.' . VendorPublicKey::base64urlEncode($sig);
	}

	private function validPayload(array $overrides = []): array
	{
		return array_merge([
			'v' => 2, 'product' => 'kanzleicheck', 'customerId' => 'demo-cust',
			'issuedAt' => '2026-01-01', 'validUntil' => '2027-01-01',
			'mobileSeats' => 5, 'portalClients' => 25,
		], $overrides);
	}

	public function testValidKeyParses(): void
	{
		$parsed = Kzc2Codec::parseAndVerify($this->sign($this->validPayload()));
		$this->assertNotNull($parsed);
		$this->assertSame('demo-cust', $parsed['payload']['customerId']);
		$this->assertSame(25, $parsed['payload']['portalClients']);
	}

	public function testWrongPrefixRejected(): void
	{
		$this->assertSame(Kzc2Codec::ERROR_INVALID_FORMAT, Kzc2Codec::classifyError('TKC2.abc.def'));
	}

	public function testTamperedSignatureRejected(): void
	{
		$k = $this->sign($this->validPayload());
		$sig = sodium_crypto_sign_detached('{}', self::$secretKey);
		$parts = explode('.', $k);
		$bad = 'KZC2.' . $parts[1] . '.' . VendorPublicKey::base64urlEncode($sig);
		$this->assertSame(Kzc2Codec::ERROR_INVALID_SIGNATURE, Kzc2Codec::classifyError($bad));
	}

	public function testWrongProductRejected(): void
	{
		$p = $this->validPayload(['product' => 'ticketcheck']);
		// canonicalJson() coerces product — sign a hand-built wrong-product payload instead
		$ordered = ['v' => 2, 'product' => 'ticketcheck', 'customerId' => 'demo-cust', 'issuedAt' => '2026-01-01', 'validUntil' => '2027-01-01', 'mobileSeats' => 5, 'portalClients' => 25];
		$json = json_encode($ordered, JSON_UNESCAPED_SLASHES);
		$sig = sodium_crypto_sign_detached($json, self::$secretKey);
		$k = 'KZC2.' . VendorPublicKey::base64urlEncode($json) . '.' . VendorPublicKey::base64urlEncode($sig);
		$this->assertSame(Kzc2Codec::ERROR_INVALID_PAYLOAD, Kzc2Codec::classifyError($k));
	}

	public function testNegativeSeatsRejected(): void
	{
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['mobileSeats' => -1])));
	}

	public function testZeroTotalSeatsRejected(): void
	{
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['mobileSeats' => 0, 'portalClients' => 0])));
	}

	public function testValidUntilBeforeIssuedAtRejected(): void
	{
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['issuedAt' => '2027-01-01', 'validUntil' => '2026-01-01'])));
	}

	public function testBundleRequiresBothSeatPools(): void
	{
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['bundle' => true, 'portalClients' => 0])));
		$this->assertTrue(Kzc2Codec::validatePayloadFields($this->validPayload(['bundle' => true])));
	}

	public function testExpirySemantics(): void
	{
		$this->assertTrue(Kzc2Codec::isValidOn('2027-01-01', '2027-01-01'));
		$this->assertFalse(Kzc2Codec::isValidOn('2027-01-01', '2027-01-02'));
	}

	public function testWhitespaceNormalization(): void
	{
		$k = $this->sign($this->validPayload());
		$spaced = substr($k, 0, 10) . " \n" . substr($k, 10);
		$this->assertNotNull(Kzc2Codec::parseAndVerify($spaced));
	}

	public function testPartCountPinnedToThree(): void
	{
		$this->assertSame(Kzc2Codec::ERROR_INVALID_FORMAT, Kzc2Codec::classifyError('KZC2.abc'));
		$this->assertSame(Kzc2Codec::ERROR_INVALID_FORMAT, Kzc2Codec::classifyError('KZC2.a.b.c.d'));
		$this->assertSame(Kzc2Codec::ERROR_INVALID_FORMAT, Kzc2Codec::classifyError(''));
	}

	public function testUndecodableSegmentsRejected(): void
	{
		$k = $this->sign($this->validPayload());
		$parts = explode('.', $k);
		// valid-length signature but payload segment that base64url cannot decode
		$this->assertSame(Kzc2Codec::ERROR_INVALID_FORMAT,
			Kzc2Codec::classifyError('KZC2.***.' . $parts[2]));
		// decodable payload but signature segment undecodable
		$this->assertSame(Kzc2Codec::ERROR_INVALID_FORMAT,
			Kzc2Codec::classifyError('KZC2.' . $parts[1] . '.***'));
		// wrong-length signature (decodable but ≠ 64 bytes)
		$this->assertSame(Kzc2Codec::ERROR_INVALID_FORMAT,
			Kzc2Codec::classifyError('KZC2.' . $parts[1] . '.' . VendorPublicKey::base64urlEncode('short')));
	}

	public function testNonCanonicalSignedPayloadRejected(): void
	{
		// a correctly-signed payload whose JSON is NOT the canonical encoding
		// (extra key + different order) must fail the canonicality check.
		$reordered = json_encode([
			'customerId' => 'demo-cust', 'v' => 2, 'product' => 'kanzleicheck',
			'issuedAt' => '2026-01-01', 'validUntil' => '2027-01-01',
			'portalClients' => 25, 'mobileSeats' => 5,
		], JSON_UNESCAPED_SLASHES);
		$sig = sodium_crypto_sign_detached($reordered, self::$secretKey);
		$k = 'KZC2.' . VendorPublicKey::base64urlEncode($reordered) . '.' . VendorPublicKey::base64urlEncode($sig);
		$this->assertSame(Kzc2Codec::ERROR_INVALID_PAYLOAD, Kzc2Codec::classifyError($k));
	}

	public function testVersionAndProductPinned(): void
	{
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['v' => 1])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['v' => 3])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['product' => 'other'])));
		$this->assertTrue(Kzc2Codec::validatePayloadFields($this->validPayload()));
	}

	public function testCustomerIdAnchorsAndLength(): void
	{
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['customerId' => '!!abc-def'])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['customerId' => 'abc-def!!'])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['customerId' => 'ab'])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['customerId' => 'ABC-DEF'])));
		$this->assertTrue(Kzc2Codec::validatePayloadFields($this->validPayload(['customerId' => str_repeat('a', 64)])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['customerId' => str_repeat('a', 65)])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['customerId' => 12345])));
	}

	public function testSeatBoundsExact(): void
	{
		$this->assertTrue(Kzc2Codec::validatePayloadFields($this->validPayload(['portalClients' => 100000])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['portalClients' => 100001])));
		$this->assertTrue(Kzc2Codec::validatePayloadFields($this->validPayload(['mobileSeats' => 10000, 'portalClients' => 1])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['mobileSeats' => 10001, 'portalClients' => 25])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['mobileSeats' => '5'])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['portalClients' => -1])));
	}

	public function testBundleMustBeLiteralTrue(): void
	{
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['bundle' => false])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['bundle' => 'true'])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['bundle' => 1])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['bundle' => true, 'mobileSeats' => 0])));
	}

	public function testDateAnchorsAndRealCalendar(): void
	{
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['issuedAt' => 'x2026-01-01'])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['issuedAt' => '2026-01-01x'])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['issuedAt' => '2026-13-01'])));
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['issuedAt' => '2026-02-30'])));
		// prefix junk that sorts BEFORE validUntil — the ordering check must
		// not mask a missing ^ anchor in the date validator
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['issuedAt' => '12026-01-01'])));
		// validUntil junk sorts after issuedAt — no ordering mask either way
		$this->assertFalse(Kzc2Codec::validatePayloadFields($this->validPayload(['validUntil' => 'x2027-01-01'])));
		// 2000-02-29 is a real leap day; in year 2 it is not — distinguishes
		// the year/month/day argument order in checkdate()
		$this->assertTrue(Kzc2Codec::validatePayloadFields($this->validPayload(['issuedAt' => '2000-02-29'])));
		// same-day boundary is allowed
		$this->assertTrue(Kzc2Codec::validatePayloadFields($this->validPayload(['issuedAt' => '2026-01-01', 'validUntil' => '2026-01-01'])));
		$this->assertTrue(Kzc2Codec::validatePayloadFields($this->validPayload(['issuedAt' => '2026-01-20', 'validUntil' => '2026-01-20'])));
	}

	public function testZeroInOneSeatPoolIsValid(): void
	{
		// seats are bounded individually (0..100000); only the SUM must be ≥1
		$this->assertTrue(Kzc2Codec::validatePayloadFields($this->validPayload(['mobileSeats' => 0, 'portalClients' => 25])));
		$this->assertTrue(Kzc2Codec::validatePayloadFields($this->validPayload(['portalClients' => 0, 'mobileSeats' => 5])));
	}

	public function testSeatSumExactlyOneIsValid(): void
	{
		$this->assertTrue(Kzc2Codec::validatePayloadFields($this->validPayload(['mobileSeats' => 0, 'portalClients' => 1])));
	}

	public function testBundleBoundaryExactlyOneEach(): void
	{
		$this->assertTrue(Kzc2Codec::validatePayloadFields($this->validPayload(['bundle' => true, 'mobileSeats' => 1, 'portalClients' => 1])));
	}

	public function testCanonicalJsonOmitsBundleWhenAbsent(): void
	{
		$json = Kzc2Codec::canonicalJson($this->validPayload());
		$this->assertStringNotContainsString('bundle', $json);
		$withBundle = Kzc2Codec::canonicalJson($this->validPayload(['bundle' => true]));
		$this->assertStringContainsString('"bundle":true', $withBundle);
	}

	public function testCanonicalJsonCoercesScalarTypes(): void
	{
		$json = Kzc2Codec::canonicalJson([
			'v' => '2', 'product' => 456, 'customerId' => 123,
			'issuedAt' => 20260101, 'validUntil' => 20270101,
			'mobileSeats' => '5', 'portalClients' => '25',
		]);
		$this->assertStringContainsString('"v":2', $json);
		$this->assertStringContainsString('"product":"456"', $json);
		$this->assertStringContainsString('"customerId":"123"', $json);
		$this->assertStringContainsString('"issuedAt":"20260101"', $json);
		$this->assertStringContainsString('"validUntil":"20270101"', $json);
		$this->assertStringContainsString('"mobileSeats":5', $json);
		$this->assertStringContainsString('"portalClients":25', $json);
	}

	public function testCanonicalJsonLeavesSlashesAndUnicodeBare(): void
	{
		$json = Kzc2Codec::canonicalJson($this->validPayload([
			'customerId' => 'müller-x',
			'issuedAt' => '2026/01/01',
		]));
		$this->assertStringContainsString('müller-x', $json);
		$this->assertStringContainsString('2026/01/01', $json);
	}

	public function testParseReturnsWireSegments(): void
	{
		$k = $this->sign($this->validPayload());
		$parts = explode('.', $k);
		$parsed = Kzc2Codec::parseAndVerify($k);
		$this->assertNotNull($parsed);
		$this->assertSame($parts[1], $parsed['payloadB64']);
		$this->assertSame($parts[2], $parsed['signatureB64']);
	}
}
