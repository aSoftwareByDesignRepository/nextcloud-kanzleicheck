<?php

declare(strict_types=1);

namespace OCA\PortalCheck\BackgroundJob;

use OCA\PortalCheck\Db\JobCursorMapper;
use OCA\PortalCheck\Service\SyncBindingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;

/**
 * Binding reconcile (nc-user-binding §6): refreshes the ptl_sync state cache
 * for every non-terminal binding — ≤200 per tick, cursor-resumable, single
 * run lock, never overlaps itself. Strictly read-only on shares (NN-11):
 * it computes state; share lifecycle only runs through staff actions.
 */
class SyncReconcileJob extends TimedJob
{
	public const BATCH = 200;
	public const INTERVAL = 4 * 3600; // ≤ 6 h requirement

	public function __construct(
		ITimeFactory $time,
		private readonly ILockingProvider $lock,
		private readonly SyncBindingService $sync,
		private readonly JobCursorMapper $cursors,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
	}

	protected function run($argument): void
	{
		try {
			$this->lock->acquireLock('portalcheck_sync_reconcile', ILockingProvider::LOCK_EXCLUSIVE, 'portalcheck sync reconcile');
		} catch (\Throwable) {
			return; // another node owns it
		}
		try {
			$cursor = (int)$this->cursors->get('sync_reconcile');
			$res = $this->sync->reconcile($cursor, self::BATCH);
			// wrap the cursor when a short page means the end of the table
			$this->cursors->set('sync_reconcile', (string)($res['processed'] < self::BATCH ? 0 : $res['lastId']));
		} finally {
			$this->lock->releaseLock('portalcheck_sync_reconcile', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
