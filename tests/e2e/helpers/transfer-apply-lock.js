/**
 * Cross-worker mutex for the transfer-apply legs (settings-transfer +
 * craft-transfer). Both specs queue real TransferApplyJob runs against
 * ONE shared dev instance — the apply holds an exclusive server mutex
 * and the job/receipt store is a single slot, so parallel apply legs
 * clobber each other (loser gets TRANSFER_IN_PROGRESS, its progress
 * dialog flash-closes before a poll can see it). mkdir is the atomic
 * acquire; the lock lives in the OS tmpdir so a wiped test-results/
 * never strands it.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');

const LOCK_DIR = path.join(os.tmpdir(), 'kzc-e2e-transfer-apply.lock');
const STALE_MS = 300_000; // apply leg incl. job + reload must fit inside

async function acquireTransferApplyLock(timeoutMs = STALE_MS) {
	const deadline = Date.now() + timeoutMs;
	for (;;) {
		try {
			fs.mkdirSync(LOCK_DIR);
			return () => releaseTransferApplyLock();
		} catch (e) {
			if (e.code !== 'EEXIST') throw e;
			try {
				if (Date.now() - fs.statSync(LOCK_DIR).mtimeMs > STALE_MS) {
					fs.rmdirSync(LOCK_DIR); // stale holder — reclaim
					continue;
				}
			} catch { /* raced away — retry */ }
			if (Date.now() >= deadline) {
				throw new Error(`transfer-apply lock wait exceeded ${timeoutMs}ms`);
			}
			await new Promise((r) => setTimeout(r, 500));
		}
	}
}

function releaseTransferApplyLock() {
	try { fs.rmdirSync(LOCK_DIR); } catch { /* already gone */ }
}

module.exports = { acquireTransferApplyLock, releaseTransferApplyLock };
