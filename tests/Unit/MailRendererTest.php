<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Tests\Unit;

use OCA\PortalCheck\Service\MailRenderer;
use PHPUnit\Framework\TestCase;

/**
 * MailRenderer branch matrix: every kind × de/en × optional params.
 * Asserts the meaningful contract — right template, right language,
 * token interpolation, content-free guarantees (NN-P04).
 */
class MailRendererTest extends TestCase
{
	// ── locale resolution ──

	public function testDeLocaleVariants(): void
	{
		foreach (['de', 'de_DE', 'de-AT', 'DE'] as $loc) {
			$r = MailRenderer::render('invite', [], $loc);
			$this->assertStringContainsString('Zugang', $r['subject'], "locale $loc must be German");
		}
	}

	public function testNonDeFallsBackToEnglish(): void
	{
		foreach (['en', 'fr', 'pl', '', 'xyz'] as $loc) {
			$r = MailRenderer::render('invite', [], $loc);
			$this->assertStringContainsString('access to your portal', $r['subject'], "locale $loc must fall back to English");
		}
	}

	// ── provider label ──

	public function testProviderDefaultPerLocale(): void
	{
		$this->assertStringStartsWith('Ihr Anbieter: ', MailRenderer::render('invite', [], 'de')['subject']);
		$this->assertStringStartsWith('your provider: ', MailRenderer::render('invite', [], 'en')['subject']);
	}

	public function testCustomProviderUsed(): void
	{
		$this->assertStringStartsWith('Acme GmbH: ', MailRenderer::render('invite', ['provider' => 'Acme GmbH'], 'de')['subject']);
	}

	public function testEmptyProviderFallsBack(): void
	{
		$this->assertStringStartsWith('Ihr Anbieter: ', MailRenderer::render('invite', ['provider' => '   '], 'de')['subject']);
	}

	// ── otp ──

	public function testOtpDeContainsCode(): void
	{
		$r = MailRenderer::render('otp', ['code' => '123456', 'provider' => 'P'], 'de');
		$this->assertStringContainsString('Anmeldecode', $r['subject']);
		$this->assertStringContainsString('Ihr Anmeldecode: 123456', $r['text']);
		$this->assertStringContainsString('10 Minuten', $r['text']);
		$this->assertStringContainsString('nicht weiter', $r['text']);
	}

	public function testOtpEnContainsCode(): void
	{
		$r = MailRenderer::render('otp', ['code' => '654321'], 'en');
		$this->assertStringContainsString('Your sign-in code: 654321', $r['text']);
		$this->assertStringContainsString('10 minutes', $r['text']);
	}

	public function testOtpPortalUrlRenderedWhenGiven(): void
	{
		$with = MailRenderer::render('otp', ['code' => '1', 'portalUrl' => 'https://p.example'], 'en');
		$this->assertStringContainsString('Portal: https://p.example', $with['text']);
		$without = MailRenderer::render('otp', ['code' => '1'], 'en');
		$this->assertStringNotContainsString('Portal:', $without['text']);
	}

	// ── approve ──

	public function testApproveDe(): void
	{
		$r = MailRenderer::render('approve', ['code' => '777'], 'de');
		$this->assertStringContainsString('Freigabe', $r['subject']);
		$this->assertStringContainsString('Ihr Bestätigungscode: 777', $r['text']);
	}

	public function testApproveEn(): void
	{
		$r = MailRenderer::render('approve', ['code' => '999'], 'en');
		$this->assertStringContainsString('Your confirmation code: 999', $r['text']);
	}

	// ── invite ──

	public function testInviteDe(): void
	{
		$r = MailRenderer::render('invite', ['portalUrl' => 'https://p'], 'de');
		$this->assertStringContainsString('Kundenportal', $r['text']);
		$this->assertStringContainsString('Portal: https://p', $r['text']);
	}

	public function testInviteEn(): void
	{
		$r = MailRenderer::render('invite', [], 'en');
		$this->assertStringContainsString('client portal', $r['text']);
	}

	// ── remind ──

	public function testRemindDeWithDeadline(): void
	{
		$r = MailRenderer::render('remind', ['provider' => 'P', 'deadline' => '2026-12-01'], 'de');
		$this->assertStringContainsString('fehlen noch Unterlagen', $r['subject']);
		$this->assertStringContainsString('(Frist: 2026-12-01)', $r['text']);
	}

	public function testRemindDeWithoutDeadlineOmitsParens(): void
	{
		$r = MailRenderer::render('remind', ['provider' => 'P'], 'de');
		$this->assertStringNotContainsString('Frist:', $r['text']);
	}

	public function testRemindEnDeadline(): void
	{
		$r = MailRenderer::render('remind', ['provider' => 'P', 'deadline' => '2026-01-01'], 'en');
		$this->assertStringContainsString('(deadline: 2026-01-01)', $r['text']);
	}

	// ── device ──

	public function testDeviceDeAllParams(): void
	{
		$r = MailRenderer::render('device', ['deviceName' => 'Pixel 8', 'platform' => 'Android', 'time' => '2026-11-05 08:00'], 'de');
		$this->assertStringContainsString('Neues Gerät', $r['subject']);
		$this->assertStringContainsString('Gerät: Pixel 8 (Android) — 2026-11-05 08:00', $r['text']);
	}

	public function testDeviceEnNoTime(): void
	{
		$r = MailRenderer::render('device', ['deviceName' => 'iPhone', 'platform' => 'iOS'], 'en');
		$this->assertStringContainsString('Device: iPhone (iOS)', $r['text']);
		$this->assertStringNotContainsString(' — ', $r['text']);
	}

	// ── notice ──

	public function testNoticeWhatLabels(): void
	{
		$this->assertStringContainsString('eine neue Nachricht', MailRenderer::render('notice', ['what' => 'message'], 'de')['text']);
		$this->assertStringContainsString('ein neues Dokument', MailRenderer::render('notice', ['what' => 'document'], 'de')['text']);
		$this->assertStringContainsString('eine Aktualisierung', MailRenderer::render('notice', ['what' => 'update'], 'de')['text']);
		$this->assertStringContainsString('a new message', MailRenderer::render('notice', ['what' => 'message'], 'en')['text']);
		$this->assertStringContainsString('a new document', MailRenderer::render('notice', ['what' => 'document'], 'en')['text']);
		$this->assertStringContainsString('an update', MailRenderer::render('notice', ['what' => 'update'], 'en')['text']);
	}

	public function testNoticeUnknownWhatFallsToUpdate(): void
	{
		$r = MailRenderer::render('notice', ['what' => 'bogus'], 'en');
		$this->assertStringContainsString('an update', $r['text']);
		$this->assertStringContainsString('an update', $r['subject']);
	}

	public function testUnknownKindFallsToNoticeBranch(): void
	{
		// 'default' arm of the switch IS notice — unknown kinds render a notice
		$r = MailRenderer::render('totally_unknown', [], 'en');
		$this->assertStringContainsString('an update', $r['text']);
	}

	// ── portalUrl ternary — every kind, both states ──

	public function testPortalUrlTernaryEveryKind(): void
	{
		// the `$portalUrl !== '' ? 'Portal: '.$url : ''` ternary exists in every
		// kind — each arm must be asserted or concat/ternary mutants slip.
		// approve has no portalUrl line by design (code-only mail)
		$kinds = [
			'otp' => ['code' => '1'],
			'invite' => [],
			'remind' => [],
			'device' => ['deviceName' => 'd', 'platform' => 'p'],
			'notice' => [],
		];
		foreach ($kinds as $kind => $params) {
			foreach (['de', 'en'] as $loc) {
				$with = MailRenderer::render($kind, $params + ['portalUrl' => 'https://p.example'], $loc);
				$this->assertStringContainsString('Portal: https://p.example', $with['text'], "$kind/$loc must render the portal url");
				$without = MailRenderer::render($kind, $params, $loc);
				$this->assertStringNotContainsString('Portal:', $without['text'], "$kind/$loc must omit the portal url line");
			}
		}
	}

	// ── content-free guarantee (NN-P04) ──

	public function testNoClientContentInSubjects(): void
	{
		// org names, message text, file names must NEVER reach a subject —
		// only the configured provider label is permitted.
		foreach (['otp', 'approve', 'invite', 'remind', 'device', 'notice'] as $kind) {
			$r = MailRenderer::render($kind, ['provider' => 'P', 'what' => 'message', 'code' => '1'], 'en');
			$this->assertStringNotContainsString('Muster', $r['subject'], "$kind leaks client content");
			$this->assertMatchesRegularExpression('/^P: .+/', $r['subject'], "$kind subject must start with provider label");
		}
	}
}
