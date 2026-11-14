<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copied from ticketcheck@eb42a0f lib/Service/SafeFilenameService.php
 * (COPIED-SOURCES.md) — static variant for upload paths.
 */

namespace OCA\KanzleiCheck\Service;

/** Filename sanitising for storage + Content-Disposition. */
class SafeFilenameService
{
	private const DEFAULT_FILENAME = 'upload';
	private const MAX_FILENAME_LENGTH = 255;

	public static function sanitize(?string $filename): string
	{
		if ($filename === null || trim($filename) === '') {
			return self::DEFAULT_FILENAME;
		}
		$safe = preg_replace('/[\r\n\x00]/', '', $filename) ?? '';
		// normalize Windows separators before basename — basename() only
		// strips '/' on POSIX (..\..\evil.php would slip through as dots)
		$safe = str_replace('\\', '/', $safe);
		$safe = basename($safe);
		$safe = str_replace(['"', '\\', "'"], '', $safe);
		$safe = preg_replace('/[^\p{L}\p{N}._ -]/u', '_', $safe) ?? '';
		$safe = preg_replace('/_+/', '_', $safe) ?? '';
		$safe = trim($safe, '_ ');
		if ($safe === '' || $safe === '.') {
			return self::DEFAULT_FILENAME;
		}
		return mb_substr($safe, 0, self::MAX_FILENAME_LENGTH) ?: self::DEFAULT_FILENAME;
	}

	public static function sanitizeForContentDisposition(?string $filename): string
	{
		$safe = self::sanitize($filename);
		return str_replace(['"', '\\'], '', $safe);
	}
}
