<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Service;

/**
 * Outbound mail bodies — content-free per NN-P04: no message text, file
 * names, org names (except the configured provider label), or person
 * names. Subjects are fixed localized strings.
 *
 * Kinds: otp (login/pair code), invite, notice (message|document|update
 * batched), remind, approve (approval code), device (new device paired).
 */
class MailRenderer
{
	/** @return array{subject:string,text:string} */
	public static function render(string $kind, array $params, string $locale): array
	{
		$de = str_starts_with(strtolower($locale), 'de');
		$provider = trim((string)($params['provider'] ?? '')) ?: ($de ? 'Ihr Anbieter' : 'your provider');
		$portalUrl = (string)($params['portalUrl'] ?? '');
		$lines = static fn(array $l): string => implode("\n", $l) . "\n";
		switch ($kind) {
			case 'otp':
				$code = (string)($params['code'] ?? '');
				return [
					'subject' => $de ? "$provider: Ihr Anmeldecode" : "$provider: your sign-in code",
					'text' => $lines($de ? [
						'Ihr Anmeldecode: ' . $code,
						'',
						'Der Code ist 10 Minuten gültig und kann nur einmal verwendet werden.',
						'Alternativ können Sie den Link in dieser E-Mail zum Anmelden nutzen.',
						'',
						'Bitte leiten Sie diese E-Mail nicht weiter — der Code gewährt Zugang zu Ihrem Portal.',
						$portalUrl !== '' ? 'Portal: ' . $portalUrl : '',
						'',
						'Falls Sie diesen Code nicht angefordert haben, können Sie diese E-Mail ignorieren.',
					] : [
						'Your sign-in code: ' . $code,
						'',
						'The code is valid for 10 minutes and can only be used once.',
						'You can also use the link in this e-mail to sign in.',
						'',
						'Please do not forward this e-mail — the code grants access to your portal.',
						$portalUrl !== '' ? 'Portal: ' . $portalUrl : '',
						'',
						'If you did not request this code, you can ignore this e-mail.',
					]),
				];
			case 'approve':
				$code = (string)($params['code'] ?? '');
				return [
					'subject' => $de ? "$provider: Bestätigungscode für eine Freigabe" : "$provider: confirmation code for an approval",
					'text' => $lines($de ? [
						'Sie wurden gebeten, ein Dokument freizugeben.',
						'Ihr Bestätigungscode: ' . $code,
						'',
						'Der Code ist 10 Minuten gültig und kann nur einmal verwendet werden.',
						'Bitte leiten Sie diese E-Mail nicht weiter.',
					] : [
						'You have been asked to approve a document.',
						'Your confirmation code: ' . $code,
						'',
						'The code is valid for 10 minutes and can only be used once.',
						'Please do not forward this e-mail.',
					]),
				];
			case 'invite':
				return [
					'subject' => $de ? "$provider: Zugang zu Ihrem Portal" : "$provider: access to your portal",
					'text' => $lines($de ? [
						'Sie haben Zugang zu einem Kundenportal erhalten.',
						'Melden Sie sich mit Ihrer E-Mail-Adresse an — Sie erhalten dann einen Code per E-Mail.',
						$portalUrl !== '' ? 'Portal: ' . $portalUrl : '',
					] : [
						'You have been given access to a client portal.',
						'Sign in with your e-mail address — you will then receive a code by e-mail.',
						$portalUrl !== '' ? 'Portal: ' . $portalUrl : '',
					]),
				];
			case 'remind':
				$deadline = (string)($params['deadline'] ?? '');
				return [
					'subject' => $de ? "$provider: Es fehlen noch Unterlagen" : "$provider: documents still missing",
					'text' => $lines($de ? [
						'Bei ' . $provider . ' fehlen noch Unterlagen' . ($deadline !== '' ? ' (Frist: ' . $deadline . ')' : '') . '.',
						'Bitte öffnen Sie Ihr Portal für Details.',
						$portalUrl !== '' ? 'Portal: ' . $portalUrl : '',
					] : [
						'Documents are still missing at ' . $provider . ($deadline !== '' ? ' (deadline: ' . $deadline . ')' : '') . '.',
						'Please open your portal for details.',
						$portalUrl !== '' ? 'Portal: ' . $portalUrl : '',
					]),
				];
			case 'device':
				$deviceName = (string)($params['deviceName'] ?? '');
				$platform = (string)($params['platform'] ?? '');
				$time = (string)($params['time'] ?? '');
				return [
					'subject' => $de ? "$provider: Neues Gerät angemeldet" : "$provider: new device paired",
					'text' => $lines($de ? [
						'Ein neues Gerät wurde mit Ihrem Konto verbunden:',
						'Gerät: ' . $deviceName . ' (' . $platform . ')' . ($time !== '' ? ' — ' . $time : ''),
						'',
						'Falls Sie das nicht waren, öffnen Sie Ihr Portal und entfernen Sie das Gerät unter "Geräte".',
						$portalUrl !== '' ? 'Portal: ' . $portalUrl : '',
					] : [
						'A new device was paired with your account:',
						'Device: ' . $deviceName . ' (' . $platform . ')' . ($time !== '' ? ' — ' . $time : ''),
						'',
						'If this was not you, open your portal and remove the device under "Devices".',
						$portalUrl !== '' ? 'Portal: ' . $portalUrl : '',
					]),
				];
			case 'notice':
			default:
				$what = (string)($params['what'] ?? 'update');
				$labels = [
					'message' => $de ? 'eine neue Nachricht' : 'a new message',
					'document' => $de ? 'ein neues Dokument' : 'a new document',
					'update' => $de ? 'eine Aktualisierung' : 'an update',
				];
				return [
					'subject' => $de ? "$provider: " . ($labels[$what] ?? $labels['update']) : "$provider: " . ($labels[$what] ?? $labels['update']),
					'text' => $lines($de ? [
						'Sie haben ' . ($labels[$what] ?? $labels['update']) . ' bei ' . $provider . '.',
						'Öffnen Sie Ihr Portal für Details.',
						$portalUrl !== '' ? 'Portal: ' . $portalUrl : '',
						'',
						'Möchten Sie diese E-Mails nicht mehr erhalten? Sie können sie im Portal abstellen.',
					] : [
						'You have ' . ($labels[$what] ?? $labels['update']) . ' at ' . $provider . '.',
						'Open your portal for details.',
						$portalUrl !== '' ? 'Portal: ' . $portalUrl : '',
						'',
						'Do not want these e-mails? You can switch them off in the portal.',
					]),
				];
		}
	}
}
