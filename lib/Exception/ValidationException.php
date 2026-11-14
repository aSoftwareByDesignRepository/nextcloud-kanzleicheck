<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Exception;

/**
 * Field-level input failure — carries a fields map that renders as
 * aria-invalid + inline .kzc-field-error per named control (api-security §5).
 */
class ValidationException extends \RuntimeException
{
	/** @param array<string,string> $fields field name → error message */
	public function __construct(string $message = 'VALIDATION_FAILED', private readonly array $fields = [])
	{
		parent::__construct($message);
	}

	/** @return array<string,string> */
	public function getFields(): array
	{
		return $this->fields;
	}
}
