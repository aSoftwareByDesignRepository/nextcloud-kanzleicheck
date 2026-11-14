<?php

declare(strict_types=1);

/*
 * Copied from ticketcheck@eb42a0f lib/Service/Export/CsvFormulaGuard.php
 * (COPIED-SOURCES.md).
 */

namespace OCA\KanzleiCheck\Service;

/** CSV formula-injection guard (api-security §7): prefix ' on = + - @ TAB CR VT. */
class CsvFormulaGuard
{
	public static function escape(?string $value): string
	{
		$v = (string)$value;
		if ($v === '') {
			return $v;
		}
		$first = $v[0];
		if (in_array($first, ['=', '+', '-', '@', "\t", "\r", "\x0B"], true)) {
			return "'" . $v;
		}
		return $v;
	}
}
