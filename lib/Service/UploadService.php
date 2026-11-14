<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Upload validation rules follow ticketcheck AttachmentUploadService
 * semantics (copied per D-11): content-typed by finfo, executable/script/
 * macro blocklist, size caps, collision-safe naming.
 */

namespace OCA\KanzleiCheck\Service;

use OCA\KanzleiCheck\Db\Upload;
use OCA\KanzleiCheck\Db\UploadMapper;
use OCA\KanzleiCheck\Exception\ApiException;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Client/third-party uploads into <org>/Eingang/. Validation by CONTENT
 * (finfo), never by name: executables, scripts, macro-enabled Office —
 * FILE_TYPE_BLOCKED before storage. Server-side SHA-256 for duplicate
 * detection (US-P26) — a client-sent hash is never trusted.
 *
 * unique-conflict:ok every write site here targets kzc_upload, which carries
 * no unique index (file_id is a fresh node per upload — duplicates are
 * flagged, not blocked). kzc_sync is read-only in this file
 * (countActiveRwByOrg for the US-S14 integrity gate).
 */
class UploadService
{
	public const DEFAULT_MAX_MB = 100;
	public const ADMIN_MAX_MB = 512;
	public const DUP_WINDOW_DAYS = 365;

	private const BLOCKED_EXT = ['exe','dll','com','bat','cmd','scr','pif','msi','msp','cpl','jar','sh','bash','ps1','psm1','vbs','vbe','js','jse','wsf','wsh','hta','php','phar','pl','py','rb','cgi','docm','xlsm','pptm','dotm','xltm','potm','xlam','app','dmg','iso','apk'];

	private const BLOCKED_MIME_PREFIX = ['application/x-dosexec','application/x-executable','application/x-sh','application/x-shellscript','application/x-msdownload','application/x-msdos-program','text/x-php','text/x-python','text/x-shellscript','application/x-perl','application/java-archive','application/x-java','application/vnd.ms-word.document.macroenabled','application/vnd.ms-excel.sheet.macroenabled','application/vnd.ms-powerpoint.presentation.macroenabled','application/vnd.ms-office'];

	public function __construct(
		private readonly UploadMapper $uploads,
		private readonly SettingsService $settings,
		private readonly DocumentService $docs,
		private readonly ?\OCA\KanzleiCheck\Db\SyncBindingMapper $syncBindings = null,
	) {
	}

	public function maxBytes(): int
	{
		$mb = min(self::ADMIN_MAX_MB, max(1, $this->settings->getInt('upload_max_mb', self::DEFAULT_MAX_MB)));
		return $mb * 1024 * 1024;
	}

	/**
	 * Validate a stored temp file: size + content type. Throws ApiException
	 * with FILE_TOO_LARGE / FILE_TYPE_BLOCKED — before any storage.
	 */
	public function validateFile(string $tmpPath, string $fileName, int $maxBytes): void
	{
		$size = filesize($tmpPath);
		if ($size === false || $size === 0) {
			throw new ApiException(400, 'VALIDATION_FAILED', 'empty file');
		}
		if ($size > $maxBytes) {
			throw new ApiException(413, 'FILE_TOO_LARGE', 'file exceeds limit');
		}
		$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		if (in_array($ext, self::BLOCKED_EXT, true)) {
			throw new ApiException(415, 'FILE_TYPE_BLOCKED', 'file type blocked');
		}
		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$mime = $finfo->file($tmpPath) ?: 'application/octet-stream';
		foreach (self::BLOCKED_MIME_PREFIX as $p) {
			if (str_starts_with($mime, $p)) {
				throw new ApiException(415, 'FILE_TYPE_BLOCKED', 'file type blocked');
			}
		}
	}

	/**
	 * Store a validated temp file in the org's Eingang + queue row.
	 * Duplicate detection uses the server-computed hash (US-P26 AC-3).
	 * @return array{upload:Upload,duplicate:?Upload}
	 */
	public function store(Folder $eingang, int $orgId, string $actor, string $tmpPath, string $fileName, string $source, ?string $note): array
	{
		$sha = hash_file('sha256', $tmpPath);
		if ($sha === false) {
			throw new ApiException(500, 'UPLOAD_FAILED', 'cannot hash upload');
		}
		$safe = SafeFilenameService::sanitize($fileName);
		$name = $this->docs->uniqueName($eingang, $safe);
		$node = $eingang->newFile($name);
		$in = fopen($tmpPath, 'r');
		$out = $node->fopen('w');
		if (!is_resource($in) || !is_resource($out)) {
			throw new ApiException(500, 'UPLOAD_FAILED', 'cannot write upload');
		}
		stream_copy_to_stream($in, $out);
		fclose($in);
		fclose($out);
		$size = filesize($tmpPath) ?: 0;
		$dup = $this->uploads->findDuplicate($orgId, $sha, (new \DateTimeImmutable())->modify('-' . self::DUP_WINDOW_DAYS . ' days'));
		$u = new Upload();
		$u->setOrgId($orgId);
		$u->setUploader($actor);
		$u->setFileId((int)$node->getId());
		$u->setFileName($name);
		$u->setSizeBytes($size);
		$u->setSha256($sha);
		$u->setSource(in_array($source, ['portal','app','intake','ulink','form'], true) ? $source : 'portal');
		$u->setIsDuplicate($dup !== null);
		$u->setNote($note !== null ? mb_substr($note, 0, 500) : null);
		$u->setState('neu');
		$u->setCreatedAt(new \DateTimeImmutable());
		$u = $this->uploads->insert($u);
		return ['upload' => $u, 'duplicate' => $dup];
	}

	/**
	 * US-S14 integrity re-verify: on orgs with an ACTIVE rw sync binding the
	 * client can write into Eingang via desktop sync — the ingest sha256 is
	 * therefore re-checked at every staff review transition and open/download.
	 * ro bindings and unbound orgs pay no re-hash cost (AC-4).
	 */
	public function verifyIntegrity(Upload $u): Upload
	{
		if ($this->syncBindings === null || $this->syncBindings->countActiveRwByOrg((int)$u->getOrgId()) === 0) {
			return $u;
		}
		$node = $this->docs->findNodeById((int)$u->getFileId());
		$new = 'ok';
		if (!$node instanceof File) {
			$new = 'deleted_after_upload';
		} else {
			try {
				$new = $this->docs->hashFile($node) === $u->getSha256() ? 'ok' : 'modified_after_upload';
			} catch (\Throwable) {
				$new = 'deleted_after_upload';
			}
		}
		if ($new !== $u->getIntegrity()) {
			$u->setIntegrity($new);
			$u = $this->uploads->update($u);
		}
		return $u;
	}

	/** Staff queue review: neu → eingesehen → abgelegt (never backwards). */
	public function review(int $uploadId, string $actor, string $state, bool $reack = false): Upload
	{
		$u = $this->uploads->find($uploadId);
		if ($u === null) {
			throw new \OCA\KanzleiCheck\Exception\NotFoundException();
		}
		$u = $this->verifyIntegrity($u);
		if ($u->getIntegrity() === 'deleted_after_upload') {
			throw new ApiException(409, 'UPLOAD_DELETED', 'file was deleted after upload');
		}
		if ($u->getIntegrity() === 'modified_after_upload' && !$reack) {
			// blocked until explicit re-acknowledgement — never a silent pass (NN-09)
			throw new ApiException(409, 'UPLOAD_MODIFIED', 'file was modified after upload — re-acknowledge to continue');
		}
		$order = ['neu' => 0, 'eingesehen' => 1, 'abgelegt' => 2];
		if (!isset($order[$state]) || $order[$state] <= $order[$u->getState()]) {
			throw new ApiException(409, 'VALIDATION_FAILED', 'invalid state transition');
		}
		$u->setState($state);
		$u->setReviewedBy($actor);
		$u->setReviewedAt(new \DateTimeImmutable());
		return $this->uploads->update($u);
	}
}
