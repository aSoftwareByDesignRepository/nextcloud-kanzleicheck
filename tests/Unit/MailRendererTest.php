<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Service\MailRenderer;
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

	// ── exact-body contract (kills concat/array-item mutants) ──

	/** @return array<string,array{0:string,1:array,2:string,3:string,4:string}> */
	public static function exactBodies(): array
	{
		return [
			'otp en +portalUrl' => ['otp', ['code' => '123456', 'provider' => 'P', 'portalUrl' => 'https://p'], 'en',
				'P: your sign-in code',
				"Your sign-in code: 123456\n\nThe code is valid for 10 minutes and can only be used once.\nYou can also use the link in this e-mail to sign in.\n\nPlease do not forward this e-mail — the code grants access to your portal.\nPortal: https://p\n\nIf you did not request this code, you can ignore this e-mail.\n"],
			'otp de -portalUrl' => ['otp', ['code' => '654321'], 'de',
				'Ihr Anbieter: Ihr Anmeldecode',
				"Ihr Anmeldecode: 654321\n\nDer Code ist 10 Minuten gültig und kann nur einmal verwendet werden.\nAlternativ können Sie den Link in dieser E-Mail zum Anmelden nutzen.\n\nBitte leiten Sie diese E-Mail nicht weiter — der Code gewährt Zugang zu Ihrem Portal.\n\n\nFalls Sie diesen Code nicht angefordert haben, können Sie diese E-Mail ignorieren.\n"],
			'approve en' => ['approve', ['code' => '777', 'provider' => 'P'], 'en',
				'P: confirmation code for an approval',
				"You have been asked to approve a document.\nYour confirmation code: 777\n\nThe code is valid for 10 minutes and can only be used once.\nPlease do not forward this e-mail.\n"],
			'approve de' => ['approve', ['code' => '777', 'provider' => 'P'], 'de',
				'P: Bestätigungscode für eine Freigabe',
				"Sie wurden gebeten, ein Dokument freizugeben.\nIhr Bestätigungscode: 777\n\nDer Code ist 10 Minuten gültig und kann nur einmal verwendet werden.\nBitte leiten Sie diese E-Mail nicht weiter.\n"],
			'invite de +portalUrl' => ['invite', ['provider' => 'P', 'portalUrl' => 'https://p'], 'de',
				'P: Zugang zu Ihrem Portal',
				"Sie haben Zugang zu einem Kundenportal erhalten.\nMelden Sie sich mit Ihrer E-Mail-Adresse an — Sie erhalten dann einen Code per E-Mail.\nPortal: https://p\n"],
			'remind de +deadline -url' => ['remind', ['provider' => 'P', 'deadline' => '2026-12-01'], 'de',
				'P: Es fehlen noch Unterlagen',
				"Bei P fehlen noch Unterlagen (Frist: 2026-12-01).\nBitte öffnen Sie Ihr Portal für Details.\n\n"],
			'remind en -deadline +url' => ['remind', ['provider' => 'P', 'portalUrl' => 'https://p'], 'en',
				'P: documents still missing',
				"Documents are still missing at P.\nPlease open your portal for details.\nPortal: https://p\n"],
			'device en +time' => ['device', ['deviceName' => 'Pixel 8', 'platform' => 'Android', 'time' => '2026-11-05 08:00', 'provider' => 'P'], 'en',
				'P: new device paired',
				"A new device was paired with your account:\nDevice: Pixel 8 (Android) — 2026-11-05 08:00\n\nIf this was not you, open your portal and remove the device under \"Devices\".\n\n"],
			'device de -time' => ['device', ['deviceName' => 'iPhone', 'platform' => 'iOS', 'provider' => 'P'], 'de',
				'P: Neues Gerät angemeldet',
				"Ein neues Gerät wurde mit Ihrem Konto verbunden:\nGerät: iPhone (iOS)\n\nFalls Sie das nicht waren, öffnen Sie Ihr Portal und entfernen Sie das Gerät unter \"Geräte\".\n\n"],
			'notice en message' => ['notice', ['what' => 'message', 'provider' => 'P'], 'en',
				'P: a new message',
				"You have a new message at P.\nOpen your portal for details.\n\n\nDo not want these e-mails? You can switch them off in the portal.\n"],
			'notice de document +url' => ['notice', ['what' => 'document', 'provider' => 'P', 'portalUrl' => 'https://p'], 'de',
				'P: ein neues Dokument',
				"Sie haben ein neues Dokument bei P.\nÖffnen Sie Ihr Portal für Details.\nPortal: https://p\n\nMöchten Sie diese E-Mails nicht mehr erhalten? Sie können sie im Portal abstellen.\n"],
			'notice en fallback what' => ['notice', ['what' => 'bogus', 'provider' => 'P'], 'en',
				'P: an update',
				"You have an update at P.\nOpen your portal for details.\n\n\nDo not want these e-mails? You can switch them off in the portal.\n"],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('exactBodies')]
	public function testExactBody(string $kind, array $params, string $locale, string $subject, string $text): void
	{
		$r = MailRenderer::render($kind, $params, $locale);
		$this->assertSame($subject, $r['subject']);
		$this->assertSame($text, $r['text']);
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
