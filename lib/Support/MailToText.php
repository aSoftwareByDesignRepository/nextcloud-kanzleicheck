<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Support;

/**
 * Mail body → readable UTF-8 plain text for intake chat posts.
 * Adapted from ticketcheck lib/Support/MailToText.php (same bug class:
 * raw MIME soup, <style>/<script> source, glued lines, mojibake).
 *
 * Pure functions — no I/O, fully unit-testable.
 */
final class MailToText
{
	/**
	 * Decode a MIME leaf part by its transfer encoding + declared charset.
	 * ext-imap encoding ids: 0=7bit 1=8bit 2=binary 3=base64 4=quoted-printable.
	 */
	public static function decodePart(string $raw, int $encoding, string $charset = ''): string
	{
		if ($raw === '') {
			return '';
		}
		if ($encoding === 3) {
			$decoded = base64_decode(preg_replace('/\s+/', '', $raw) ?? '', true);
			$raw = $decoded !== false ? $decoded : '';
		} elseif ($encoding === 4) {
			$raw = quoted_printable_decode($raw);
		}

		return self::toUtf8($raw, $charset);
	}

	/**
	 * Interpret bytes under the declared charset; fall back to UTF-8 repair
	 * when the declaration is missing or unusable.
	 */
	public static function toUtf8(string $raw, string $charset = ''): string
	{
		if ($raw === '') {
			return '';
		}
		// Declared charset wins; the name is wire data so both converters are
		// guarded (iconv → false, mb_convert_encoding → ValueError on PHP 8).
		// iconv first: it covers charsets mbstring lacks (e.g. windows-1250).
		$charset = strtolower(trim($charset, " \t\"'"));
		$charset = preg_replace('/^charset\s*=\s*/i', '', $charset) ?? $charset;
		if ($charset !== '' && !in_array($charset, ['utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
			$converted = false;
			if (function_exists('iconv')) {
				$converted = @iconv($charset, 'UTF-8//IGNORE', $raw);
			}
			if (!is_string($converted) || $converted === '') {
				try {
					$converted = @mb_convert_encoding($raw, 'UTF-8', $charset);
				} catch (\Throwable) {
					$converted = false;
				}
			}
			if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
				return $converted;
			}
		}

		return self::ensureUtf8($raw);
	}

	/**
	 * Guarantee valid UTF-8 — chat post bodies never reach storage/UI with
	 * broken byte sequences. Prefer dropping invalid sequences; if that
	 * yields empty content, reinterpret as Windows-1252 (common DE mail).
	 */
	public static function ensureUtf8(string $value): string
	{
		if ($value === '') {
			return '';
		}
		if (mb_check_encoding($value, 'UTF-8')) {
			return $value;
		}
		if (function_exists('iconv')) {
			$ignored = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
			if (is_string($ignored) && mb_check_encoding($ignored, 'UTF-8') && ($ignored !== '' || trim($value) === '')) {
				return $ignored;
			}
		}
		$as1252 = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
		if (is_string($as1252) && mb_check_encoding($as1252, 'UTF-8')) {
			return $as1252;
		}
		$prev = null;
		if (function_exists('mb_substitute_character')) {
			$prev = mb_substitute_character();
			mb_substitute_character(0xFFFD);
		}
		$substituted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
		if ($prev !== null) {
			mb_substitute_character($prev);
		}
		if (is_string($substituted) && mb_check_encoding($substituted, 'UTF-8')) {
			return $substituted;
		}
		$ascii = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $value);

		return is_string($ascii) ? $ascii : '';
	}

	/**
	 * HTML mail → plain text: non-rendered subtrees dropped, block-level
	 * boundaries become line breaks, entities decoded, UTF-8 guaranteed.
	 */
	public static function htmlToText(string $html): string
	{
		if (trim($html) === '') {
			return '';
		}
		$html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
		$html = preg_replace('#<(script|style|head|title|template|noscript|svg)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
		$html = preg_replace('/<br\b[^>]*>/i', "\n", $html) ?? $html;
		$html = preg_replace('/<hr\b[^>]*>/i', "\n", $html) ?? $html;
		$html = preg_replace('/<(p|blockquote|tr)\b[^>]*>/i', "\n", $html) ?? $html;
		$html = preg_replace('/<li\b[^>]*>/i', "\n- ", $html) ?? $html;
		$html = preg_replace('#</(p|div|table|tr|ul|ol|h[1-6]|blockquote|pre|section|article|header|footer|form|fieldset|dl|dt|dd|address|main|aside|nav|figure|figcaption)>#i', "\n", $html) ?? $html;
		$html = preg_replace('#</(td|th)>#i', "\t", $html) ?? $html;
		$text = strip_tags($html);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = str_replace("\xC2\xA0", ' ', $text); // &nbsp; → normal space
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$text = preg_replace('/[^\S\n]+\n/', "\n", $text) ?? $text;
		$text = preg_replace('/\n[^\S\n]+/', "\n", $text) ?? $text;
		$text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

		return self::ensureUtf8(trim($text));
	}

	/** True when a text/plain payload actually contains HTML markup. */
	public static function looksLikeHtml(string $text): bool
	{
		return preg_match('/<(html|body|div|p|br|table|span|head)\b/i', $text) === 1;
	}
}
