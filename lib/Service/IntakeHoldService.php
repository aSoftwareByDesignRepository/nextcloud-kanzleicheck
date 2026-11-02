<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\Db\IntakeHold;
use OCA\PortalCheck\Db\IntakeHoldMapper;
use OCA\PortalCheck\Db\OrgMapper;
use OCA\PortalCheck\Exception\NotFoundException;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;

/**
 * Quarantined intake mails (US-P24 AC-3). Attachments of held mails are
 * buffered in appdata under holds/<id>/ so staff can still accept → file
 * them into the org's Eingang (or discard and wipe the buffer). Nothing is
 * auto-filed before a human accepts (NN-P18).
 */
class IntakeHoldService
{
	public function __construct(
		private readonly IntakeHoldMapper $holds,
		private readonly OrgMapper $orgs,
		private readonly UploadService $uploads,
		private readonly DocumentService $docs,
		private readonly AuditService $audit,
		private readonly IAppData $appData,
		private readonly LoggerInterface $logger,
	) {
	}

	private function bufferDir(int $holdId, bool $create): ?ISimpleFolder
	{
		try {
			$root = $this->appData->getRootFolder();
			if ($create) {
				try {
					$root->getFolder('holds');
				} catch (\Throwable) {
					$root->newFolder('holds');
				}
			}
			$holds = $root->getFolder('holds');
			if ($create) {
				try {
					return $holds->getFolder((string)$holdId);
				} catch (\Throwable) {
					return $holds->newFolder((string)$holdId);
				}
			}
			try {
				return $holds->getFolder((string)$holdId);
			} catch (\Throwable) {
				return null;
			}
		} catch (\Throwable) {
			return null;
		}
	}

	/** Persist one extracted attachment into the hold's quarantine dir. */
	public function bufferFile(int $holdId, string $name, string $tmpPath): void
	{
		$dir = $this->bufferDir($holdId, true);
		if ($dir === null) {
			return;
		}
		$safe = SafeFilenameService::sanitize($name);
		$f = $dir->newFile($safe);
		$in = fopen($tmpPath, 'rb');
		$out = $f->open('w');
		if ($in !== false && $out !== false) {
			stream_copy_to_stream($in, $out);
		}
		if (is_resource($in)) {
			fclose($in);
		}
		if (is_resource($out)) {
			fclose($out);
		}
	}

	/**
	 * Staff accept: validate + file every buffered attachment into Eingang,
	 * then drop the quarantine dir and mark the hold accepted.
	 * @return array{stored:int,failed:int}
	 */
	public function accept(string $actor, int $holdId): array
	{
		$hold = $this->holds->find($holdId);
		if ($hold === null || $hold->getState() !== 'held') {
			throw new NotFoundException();
		}
		$orgId = (int)$hold->getOrgId();
		$org = $this->orgs->find($orgId);
		$stored = 0;
		$failed = 0;
		$dir = $this->bufferDir($holdId, false);
		if ($org !== null && $dir !== null && $this->docs->rootHealthy()) {
			$orgFolder = $this->docs->orgFolder($orgId, $org->getName(), $org->getFolderId(), $org->getFolderPath());
			$eingang = $orgFolder !== null ? $this->docs->ensureSubFolder($orgFolder, DocumentService::DIR_INBOX) : null;
			foreach ($dir->getDirectoryListing() as $node) {
				$tmp = tempnam(sys_get_temp_dir(), 'ptl_hold_');
				$out = fopen($tmp, 'wb');
				$in = $node->open('r');
				if ($in !== false && $out !== false) {
					stream_copy_to_stream($in, $out);
				}
				if (is_resource($in)) {
					fclose($in);
				}
				if (is_resource($out)) {
					fclose($out);
				}
				try {
					if ($eingang === null) {
						throw new \RuntimeException('no eingang');
					}
					$this->uploads->validateFile($tmp, $node->getName(), $this->uploads->maxBytes());
					$this->uploads->store($eingang, $orgId, 'intake:' . $hold->getSenderHash(), $tmp, $node->getName(), 'intake', 'per E-Mail (freigegeben)');
					$stored++;
				} catch (\Throwable $e) {
					$failed++;
					$this->logger->warning('portalcheck hold accept file failed', ['e' => $e->getMessage()]);
				}
				@unlink($tmp);
			}
		}
		$hold->setState('accepted');
		$hold->setReviewedBy($actor);
		$hold->setReviewedAt(new \DateTimeImmutable());
		$this->holds->update($hold);
		$this->clearBuffer($holdId);
		$this->audit->log($actor, 'intake_hold.accept', 'intake_hold', $holdId, ['stored' => $stored, 'failed' => $failed]);
		return ['stored' => $stored, 'failed' => $failed];
	}

	public function discard(string $actor, int $holdId): void
	{
		$hold = $this->holds->find($holdId);
		if ($hold === null || $hold->getState() !== 'held') {
			throw new NotFoundException();
		}
		$hold->setState('discarded');
		$hold->setReviewedBy($actor);
		$hold->setReviewedAt(new \DateTimeImmutable());
		$this->holds->update($hold);
		$this->clearBuffer($holdId);
		$this->audit->log($actor, 'intake_hold.discard', 'intake_hold', $holdId);
	}

	public function clearBuffer(int $holdId): void
	{
		$dir = $this->bufferDir($holdId, false);
		try {
			$dir?->delete();
		} catch (\Throwable) {
		}
	}
}
