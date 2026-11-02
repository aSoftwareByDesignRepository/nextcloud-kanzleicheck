<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Tests\Unit;

use OCA\PortalCheck\Config\VendorPublicKey;
use OCA\PortalCheck\License\Ptl2Codec;
use PHPUnit\Framework\TestCase;

/**
 * PTL2 licence codec — the gate for companion seats + portal-client slots.
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
		putenv('PTL_VENDOR_PUBLIC_KEY_B64=' . self::$publicKeyB64);
	}

	private function sign(array $payload): string
	{
		$canonical = Ptl2Codec::canonicalJson($payload);
		$sig = sodium_crypto_sign_detached($canonical, self::$secretKey);
		return 'PTL2.' . VendorPublicKey::base64urlEncode($canonical) . '.' . VendorPublicKey::base64urlEncode($sig);
	}

	private function validPayload(array $overrides = []): array
	{
		return array_merge([
			'v' => 2, 'product' => 'portalcheck', 'customerId' => 'demo-cust',
			'issuedAt' => '2026-01-01', 'validUntil' => '2027-01-01',
			'mobileSeats' => 5, 'portalClients' => 25,
		], $overrides);
	}

	public function testValidKeyParses(): void
	{
		$parsed = Ptl2Codec::parseAndVerify($this->sign($this->validPayload()));
		$this->assertNotNull($parsed);
		$this->assertSame('demo-cust', $parsed['payload']['customerId']);
		$this->assertSame(25, $parsed['payload']['portalClients']);
	}

	public function testWrongPrefixRejected(): void
	{
		$this->assertSame(Ptl2Codec::ERROR_INVALID_FORMAT, Ptl2Codec::classifyError('TKC2.abc.def'));
	}

	public function testTamperedSignatureRejected(): void
	{
		$k = $this->sign($this->validPayload());
		$sig = sodium_crypto_sign_detached('{}', self::$secretKey);
		$parts = explode('.', $k);
		$bad = 'PTL2.' . $parts[1] . '.' . VendorPublicKey::base64urlEncode($sig);
		$this->assertSame(Ptl2Codec::ERROR_INVALID_SIGNATURE, Ptl2Codec::classifyError($bad));
	}

	public function testWrongProductRejected(): void
	{
		$p = $this->validPayload(['product' => 'ticketcheck']);
		// canonicalJson() coerces product — sign a hand-built wrong-product payload instead
		$ordered = ['v' => 2, 'product' => 'ticketcheck', 'customerId' => 'demo-cust', 'issuedAt' => '2026-01-01', 'validUntil' => '2027-01-01', 'mobileSeats' => 5, 'portalClients' => 25];
		$json = json_encode($ordered, JSON_UNESCAPED_SLASHES);
		$sig = sodium_crypto_sign_detached($json, self::$secretKey);
		$k = 'PTL2.' . VendorPublicKey::base64urlEncode($json) . '.' . VendorPublicKey::base64urlEncode($sig);
		$this->assertSame(Ptl2Codec::ERROR_INVALID_PAYLOAD, Ptl2Codec::classifyError($k));
	}

	public function testNegativeSeatsRejected(): void
	{
		$this->assertFalse(Ptl2Codec::validatePayloadFields($this->validPayload(['mobileSeats' => -1])));
	}

	public function testZeroTotalSeatsRejected(): void
	{
		$this->assertFalse(Ptl2Codec::validatePayloadFields($this->validPayload(['mobileSeats' => 0, 'portalClients' => 0])));
	}

	public function testValidUntilBeforeIssuedAtRejected(): void
	{
		$this->assertFalse(Ptl2Codec::validatePayloadFields($this->validPayload(['issuedAt' => '2027-01-01', 'validUntil' => '2026-01-01'])));
	}

	public function testBundleRequiresBothSeatPools(): void
	{
		$this->assertFalse(Ptl2Codec::validatePayloadFields($this->validPayload(['bundle' => true, 'portalClients' => 0])));
		$this->assertTrue(Ptl2Codec::validatePayloadFields($this->validPayload(['bundle' => true])));
	}

	public function testExpirySemantics(): void
	{
		$this->assertTrue(Ptl2Codec::isValidOn('2027-01-01', '2027-01-01'));
		$this->assertFalse(Ptl2Codec::isValidOn('2027-01-01', '2027-01-02'));
	}

	public function testWhitespaceNormalization(): void
	{
		$k = $this->sign($this->validPayload());
		$spaced = substr($k, 0, 10) . " \n" . substr($k, 10);
		$this->assertNotNull(Ptl2Codec::parseAndVerify($spaced));
	}
}
