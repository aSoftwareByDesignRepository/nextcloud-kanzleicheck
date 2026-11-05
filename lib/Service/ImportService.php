<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\Exception\ValidationException;

/**
 * CSV client import (US-P06): UTF-8, `,`/`;` autodetect, BOM-safe,
 * dry-run per-row plan, ≤5,000 rows, formula-escaped error report.
 */
class ImportService
{
	public const MAX_ROWS = 5000;

	/** Parse CSV text → rows of [org_name, org_ref, email, display_name]. */
	public static function parse(string $csv): array
	{
		$csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
		$first = strtok($csv, "\r\n") ?: '';
		$delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
		$rows = [];
		$fh = fopen('php://memory', 'r+');
		if (!is_resource($fh)) {
			throw new ValidationException('VALIDATION_FAILED', ['file' => 'IMPORT_PARSE_ERROR']);
		}
		fwrite($fh, $csv);
		rewind($fh);
		$header = null;
		while (($r = fgetcsv($fh, 0, $delim)) !== false) {
			if ($header === null) {
				$header = array_map(static fn($h) => strtolower(trim((string)$h)), $r);
				continue;
			}
			$row = array_combine(array_pad($header, count($r), ''), array_pad($r, count($header), ''));
			$rows[] = $row;
		}
		fclose($fh);
		if (count($rows) > self::MAX_ROWS) {
			throw new ValidationException('VALIDATION_FAILED', ['file' => 'IMPORT_TOO_LARGE']);
		}
		return $rows;
	}

	/**
	 * Dry-run classification per row. Optional $ncUidClassifier maps
	 * (ncUid, email) → 'ok'|blocked-bucket for the nc_uid column (US-S08):
	 * every row lands in exactly one bucket — never a silent skip (NN-06).
	 */
	public function plan(array $rows, OrgService $orgs, IdentityService $identities, ?callable $ncUidClassifier = null): array
	{
		$out = [];
		$seenUids = [];
		foreach ($rows as $n => $r) {
			$orgName = trim((string)($r['org_name'] ?? $r['org'] ?? ''));
			$ref = trim((string)($r['org_ref'] ?? $r['ref'] ?? ''));
			$email = IdentityService::normalizeEmail((string)($r['email'] ?? ''));
			$name = trim((string)($r['display_name'] ?? $r['name'] ?? ''));
			$ncUid = trim((string)($r['nc_uid'] ?? ''));
			$entry = ['line' => $n + 2, 'org' => $orgName, 'email' => $email, 'action' => 'error', 'code' => null];
			if ($orgName === '' || !IdentityService::isValidEmail($email)) {
				$entry['code'] = 'invalid_row';
			} else {
				$byRef = $ref !== '' ? $orgs->findByRefPublic($ref) : null;
				$entry['action'] = ($byRef !== null ? 'reuse_org' : 'create_org')
					. '+' . ($identities->activeByEmail($email) !== null || $this->identityExists($identities, $email) ? 'reuse_identity' : 'create_identity');
			}
			if ($ncUid !== '') {
				$entry['ncUid'] = $ncUid;
				if (isset($seenUids[$ncUid])) {
					// same uid in two CSV rows → duplicate bucket (MH-07)
					$entry['sync'] = 'uid_duplicate';
				} elseif ($ncUidClassifier !== null) {
					$entry['sync'] = $ncUidClassifier($ncUid, $email);
				} else {
					$entry['sync'] = 'ok';
				}
				$seenUids[$ncUid] = true;
			}
			$out[] = $entry;
		}
		return $out;
	}

	private function identityExists(IdentityService $ids, string $email): bool
	{
		return $ids->activeByEmail($email) !== null;
	}
}
