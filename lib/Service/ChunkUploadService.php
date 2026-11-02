<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\Db\UploadChunkMapper;
use OCA\PortalCheck\Exception\ApiException;
use OCA\PortalCheck\Exception\NotFoundException;
use OCA\PortalCheck\Exception\ValidationException;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException as NcNotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Chunked upload protocol (AF-17/ZF-05): files >5MB arrive as
 * content-defined chunks (≤10MB each, ≤512 chunks) under a client-chosen
 * upload_id. Assembly is claimed CAS-style — concurrent finish calls can
 * never produce two stored files or double-deduct quota.
 */
class ChunkUploadService
{
	public const MAX_CHUNK = 10 * 1024 * 1024;
	public const MAX_CHUNKS = 512;

	public function __construct(
		private readonly UploadChunkMapper $chunks,
		private readonly IAppDataFactory $appData,
	) {
	}

	private function dir(): ISimpleFolder
	{
		$appData = $this->appData->get('portalcheck');
		try {
			return $appData->getFolder('chunks');
		} catch (NcNotFoundException) {
			return $appData->newFolder('chunks');
		}
	}

	private static function safeId(string $uploadId): string
	{
		if (!preg_match('/^[a-zA-Z0-9-]{8,64}$/', $uploadId)) {
			throw new ValidationException('VALIDATION_FAILED', ['uploadId' => 'invalid']);
		}
		return 'c_' . $uploadId;
	}

	public function find(string $uploadId): ?array
	{
		return $this->chunks->find($uploadId);
	}

	/** Start a chunked session — idempotent per upload_id (resume support). */
	public function start(string $uploadId, int $orgId, string $actor, string $fileName, int $totalBytes): void
	{
		if ($totalBytes <= 0) {
			throw new ValidationException('VALIDATION_FAILED', ['size' => 'invalid']);
		}
		if ($this->chunks->find($uploadId) === null) {
			$this->chunks->create($uploadId, $orgId, $actor, $fileName, $totalBytes);
		}
	}

	/** Write one chunk — counted against total; over-total or wrong-owner fails. */
	public function putChunk(string $uploadId, int $orgId, string $actor, int $index, string $tmpPath): int
	{
		$row = $this->chunks->find($uploadId);
		if ($row === null || (int)$row['org_id'] !== $orgId || $row['actor'] !== $actor) {
			throw new NotFoundException();
		}
		if ($row['state'] !== 'open') {
			throw new ApiException(409, 'VALIDATION_FAILED', 'upload finished');
		}
		if ($index < 0 || $index >= self::MAX_CHUNKS) {
			throw new ValidationException('VALIDATION_FAILED', ['index' => 'range']);
		}
		$size = filesize($tmpPath);
		if ($size === false || $size === 0 || $size > self::MAX_CHUNK) {
			throw new ApiException(400, 'VALIDATION_FAILED', 'chunk size invalid');
		}
		if ((int)$row['received_bytes'] + $size > (int)$row['total_bytes']) {
			throw new ApiException(413, 'FILE_TOO_LARGE', 'chunks exceed declared size');
		}
		$dir = $this->dir();
		$part = self::safeId($uploadId) . '.part' . $index;
		if ($dir->fileExists($part)) {
			$dir->getFile($part)->delete(); // re-sent chunk replaces
		}
		$f = $dir->newFile($part);
		$in = fopen($tmpPath, 'r');
		$out = $f->write();
		stream_copy_to_stream($in, $out);
		fclose($in);
		fclose($out);
		$this->chunks->addChunk($uploadId, $size);
		return $size;
	}

	/**
	 * Finalize: exactly one caller wins claimAssembly — assemble parts in
	 * order into a temp file for the caller's store+validate step.
	 * Returns the assembled tmp path + row.
	 */
	public function finish(string $uploadId, int $orgId, string $actor): array
	{
		$row = $this->chunks->find($uploadId);
		if ($row === null || (int)$row['org_id'] !== $orgId || $row['actor'] !== $actor) {
			throw new NotFoundException();
		}
		if ($row['state'] === 'done') {
			throw new ApiException(409, 'VALIDATION_FAILED', 'already stored');
		}
		if (!$this->chunks->claimAssembly($uploadId)) {
			throw new ApiException(409, 'VALIDATION_FAILED', 'assembly in progress');
		}
		if ((int)$row['received_bytes'] !== (int)$row['total_bytes']) {
			$this->chunks->setState($uploadId, 'open');
			throw new ApiException(400, 'VALIDATION_FAILED', 'incomplete upload');
		}
		$dir = $this->dir();
		$tmp = tempnam(sys_get_temp_dir(), 'ptl_');
		$out = fopen($tmp, 'w');
		for ($i = 0; $i < self::MAX_CHUNKS; $i++) {
			$part = self::safeId($uploadId) . '.part' . $i;
			if (!$dir->fileExists($part)) {
				break; // contiguous assembly — a mid-file gap shrinks the result and the byte check rejects
			}
			$in = $dir->getFile($part)->read();
			stream_copy_to_stream($in, $out);
			fclose($in);
		}
		fclose($out);
		if (filesize($tmp) !== (int)$row['total_bytes']) {
			@unlink($tmp);
			$this->chunks->setState($uploadId, 'open');
			throw new ApiException(400, 'VALIDATION_FAILED', 'chunk set incomplete');
		}
		return ['tmp' => $tmp, 'row' => $row];
	}

/** Cleanup after a successful store. */
	public function complete(string $uploadId): void
	{
		$dir = $this->dir();
		for ($i = 0; $i < self::MAX_CHUNKS; $i++) {
			$part = self::safeId($uploadId) . '.part' . $i;
			try {
				if ($dir->fileExists($part)) {
					$dir->getFile($part)->delete();
				}
			} catch (\Throwable) {
			}
		}
		$this->chunks->setState($uploadId, 'done');
	}
}
