<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Service\MailRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Mutation-escape hunters for MailRenderer: exact-string assertions that
 * kill Concat/ConcatOperandRemoval/ArrayItemRemoval mutants, non-string
 * params that kill CastString mutants, and the what-fallback legs that kill
 * the Coalesce/Ternary/SharedCaseRemoval mutants in `notice`.
 */
class MailRendererMutationTest extends TestCase
{
	// ── CastString kills: non-string params must still interpolate ──

	public function testNonStringParamsAreCast(): void
	{
		$r = MailRenderer::render('otp', ['code' => 123456], 'en');
		$this->assertStringContainsString('Your sign-in code: 123456', $r['text']);

		$r = MailRenderer::render('remind', ['deadline' => 20261201], 'en');
		$this->assertStringContainsString('(deadline: 20261201)', $r['text']);

		$r = MailRenderer::render('device', ['deviceName' => 7, 'platform' => 8, 'time' => 9], 'en');
		$this->assertStringContainsString('Device: 7 (8) — 9', $r['text']);
	}

	// ── Concat kills: the joiner and trailing newline are load-bearing ──

	public function testTextEndsWithTrailingNewline(): void
	{
		foreach (['otp', 'approve', 'invite', 'remind', 'device', 'notice', 'unknown'] as $kind) {
			$r = MailRenderer::render($kind, [], 'en');
			$this->assertStringEndsWith("\n", $r['text'], "kind $kind must end with a trailing newline");
			$this->assertStringContainsString("\n", $r['text']);
		}
	}

	// ── ConcatOperandRemoval/Concat kills: exact interpolated lines ──

	public function testRemindExactLinesBothLocales(): void
	{
		$r = MailRenderer::render('remind', ['provider' => 'P', 'deadline' => 'D', 'portalUrl' => 'U'], 'de');
		$this->assertStringContainsString("Bei P fehlen noch Unterlagen (Frist: D).\n", $r['text']);
		$this->assertStringContainsString("Bitte öffnen Sie Ihr Portal für Details.\n", $r['text']);
		$this->assertStringContainsString("Portal: U\n", $r['text']);

		$r = MailRenderer::render('remind', ['provider' => 'P', 'deadline' => 'D', 'portalUrl' => 'U'], 'en');
		$this->assertStringContainsString("Documents are still missing at P (deadline: D).\n", $r['text']);
		$this->assertStringContainsString("Please open your portal for details.\n", $r['text']);
		$this->assertStringContainsString("Portal: U\n", $r['text']);
	}

	public function testRemindWithoutDeadlineDropsParenthesis(): void
	{
		$de = MailRenderer::render('remind', ['provider' => 'P'], 'de')['text'];
		$en = MailRenderer::render('remind', ['provider' => 'P'], 'en')['text'];
		$this->assertStringContainsString("Bei P fehlen noch Unterlagen.\n", $de);
		$this->assertStringNotContainsString('Frist:', $de);
		$this->assertStringContainsString("Documents are still missing at P.\n", $en);
		$this->assertStringNotContainsString('deadline:', $en);
	}

	public function testDeviceExactLinesBothLocales(): void
	{
		$r = MailRenderer::render('device', ['deviceName' => 'N', 'platform' => 'P', 'time' => 'T', 'portalUrl' => 'U'], 'de');
		$this->assertStringContainsString("Ein neues Gerät wurde mit Ihrem Konto verbunden:\n", $r['text']);
		$this->assertStringContainsString("Gerät: N (P) — T\n", $r['text']);
		$this->assertStringContainsString("Portal: U\n", $r['text']);

		$r = MailRenderer::render('device', ['deviceName' => 'N', 'platform' => 'P', 'time' => 'T'], 'en');
		$this->assertStringContainsString("A new device was paired with your account:\n", $r['text']);
		$this->assertStringContainsString("Device: N (P) — T\n", $r['text']);
	}

	public function testDeviceWithoutTimeDropsDash(): void
	{
		$r = MailRenderer::render('device', ['deviceName' => 'N', 'platform' => 'P'], 'en');
		$this->assertStringContainsString("Device: N (P)\n", $r['text']);
		$this->assertStringNotContainsString(' — ', $r['text']);
	}

	public function testApproveExactLines(): void
	{
		$r = MailRenderer::render('approve', ['code' => 'C'], 'de');
		$this->assertStringContainsString("Sie wurden gebeten, ein Dokument freizugeben.\n", $r['text']);
		$this->assertStringContainsString("Ihr Bestätigungscode: C\n", $r['text']);
		$this->assertStringContainsString("Der Code ist 10 Minuten gültig und kann nur einmal verwendet werden.\n", $r['text']);

		$r = MailRenderer::render('approve', ['code' => 'C'], 'en');
		$this->assertStringContainsString("You have been asked to approve a document.\n", $r['text']);
		$this->assertStringContainsString("Your confirmation code: C\n", $r['text']);
	}

	public function testApproveSubjectExact(): void
	{
		$this->assertSame('P: Bestätigungscode für eine Freigabe',
			MailRenderer::render('approve', ['provider' => 'P'], 'de')['subject']);
		$this->assertSame('P: confirmation code for an approval',
			MailRenderer::render('approve', ['provider' => 'P'], 'en')['subject']);
	}

	public function testOtpSubjectExact(): void
	{
		$this->assertSame('P: Ihr Anmeldecode', MailRenderer::render('otp', ['provider' => 'P'], 'de')['subject']);
		$this->assertSame('P: your sign-in code', MailRenderer::render('otp', ['provider' => 'P'], 'en')['subject']);
	}

	// ── notice legs: Coalesce/Ternary/SharedCaseRemoval kills ──

	public function testNoticeExplicitKindUsesLabelTable(): void
	{
		// 'notice' is a real case label shared with default — removing it
		// (SharedCaseRemoval) must not change output.
		$r = MailRenderer::render('notice', ['what' => 'message', 'provider' => 'P'], 'en');
		$this->assertSame('P: a new message', $r['subject']);
		$this->assertStringContainsString('You have a new message at P.', $r['text']);
	}

	public function testNoticeUnknownWhatFallsBackInBothPlaces(): void
	{
		// The `?? $labels['update']` coalesce sits in subject AND text —
		// assert both or a one-sided mutant escapes.
		$r = MailRenderer::render('notice', ['what' => 'bogus', 'provider' => 'P'], 'en');
		$this->assertSame('P: an update', $r['subject']);
		$this->assertStringContainsString('You have an update at P.', $r['text']);

		$r = MailRenderer::render('notice', ['what' => 'bogus', 'provider' => 'P'], 'de');
		$this->assertSame('P: eine Aktualisierung', $r['subject']);
		$this->assertStringContainsString('Sie haben eine Aktualisierung bei P.', $r['text']);
	}

	public function testNoticeWhatLabelsMapExactly(): void
	{
		foreach (['message' => 'a new message', 'document' => 'a new document', 'update' => 'an update'] as $what => $label) {
			$r = MailRenderer::render('notice', ['what' => $what, 'provider' => 'P'], 'en');
			$this->assertSame("P: $label", $r['subject'], "what=$what");
		}
	}

	public function testNoticeDeLabels(): void
	{
		$r = MailRenderer::render('notice', ['what' => 'document', 'provider' => 'P'], 'de');
		$this->assertSame('P: ein neues Dokument', $r['subject']);
		$this->assertStringContainsString('Sie haben ein neues Dokument bei P.', $r['text']);
	}

	// ── portalUrl ternary legs ──

	public function testEmptyPortalUrlAddsNoPortalLine(): void
	{
		foreach (['otp', 'invite', 'remind', 'device', 'notice'] as $kind) {
			$r = MailRenderer::render($kind, ['portalUrl' => ''], 'en');
			$this->assertStringNotContainsString('Portal:', $r['text'], "kind $kind must not render a Portal line");
		}
	}

	public function testPortalUrlRendersPortalLine(): void
	{
		foreach (['otp', 'invite', 'remind', 'device', 'notice'] as $kind) {
			$r = MailRenderer::render($kind, ['portalUrl' => 'https://p.example'], 'en');
			$this->assertStringContainsString("Portal: https://p.example\n", $r['text'], "kind $kind must render the Portal line");
		}
	}

	// ── invite/remind/otp/notice subject exactness ──

	public function testInviteRemindNoticeSubjectsExact(): void
	{
		$this->assertSame('P: Zugang zu Ihrem Portal', MailRenderer::render('invite', ['provider' => 'P'], 'de')['subject']);
		$this->assertSame('P: access to your portal', MailRenderer::render('invite', ['provider' => 'P'], 'en')['subject']);
		$this->assertSame('P: Es fehlen noch Unterlagen', MailRenderer::render('remind', ['provider' => 'P'], 'de')['subject']);
		$this->assertSame('P: documents still missing', MailRenderer::render('remind', ['provider' => 'P'], 'en')['subject']);
		$this->assertSame('P: Neues Gerät angemeldet', MailRenderer::render('device', ['provider' => 'P'], 'de')['subject']);
		$this->assertSame('P: new device paired', MailRenderer::render('device', ['provider' => 'P'], 'en')['subject']);
	}
}
