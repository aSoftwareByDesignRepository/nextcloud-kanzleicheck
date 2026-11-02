<?php

declare(strict_types=1);

namespace OCA\PortalCheck\BackgroundJob;

use OCA\PortalCheck\Db\UploadChunkMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\AppData\IAppDataFactory;

/** Chunk temp cleanup — sessions idle >24h (ZF-05). */
class ChunkCleanupJob extends TimedJob
{
	public function __construct(ITimeFactory $time, private readonly UploadChunkMapper $chunks, private readonly IAppDataFactory $appData)
	{
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void
	{
		$cutoff = (new \DateTimeImmutable())->modify('-24 hours');
		$dir = null;
		foreach ($this->chunks->findIdle($cutoff) as $row) {
			try {
				$dir ??= $this->appData->get('portalcheck')->getFolder('chunks');
				$name = 'c_' . preg_replace('/[^a-zA-Z0-9-]/', '', (string)$row['upload_id']);
				if ($dir->fileExists($name)) {
					$dir->getFile($name)->delete();
				}
			} catch (\Throwable) {
			}
			$this->chunks->delete((string)$row['upload_id']);
		}
	}
}
