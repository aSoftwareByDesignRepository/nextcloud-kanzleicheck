<?php
/** Approval proof — hash-bound decision record (US-P23). Printable. */
$appId = 'kanzleicheck';
style($appId, 'app');
style($appId, 'portal');
?>
<div id="app-content">
	<div id="app-content-wrapper">
		<div class="kzc-portal">
			<main class="kzc-card kzc-proof">
				<h1 class="kzc-title"><?php p($l->t('Approval proof')); ?></h1>
				<table class="kzc-table">
					<tr><th><?php p($l->t('Document')); ?></th><td><?php p($_['fileName'] ?? ''); ?></td></tr>
					<tr><th><?php p($l->t('SHA-256')); ?></th><td><code class="kzc-mono"><?php p($_['sha'] ?? ''); ?></code></td></tr>
					<tr><th><?php p($l->t('Status')); ?></th><td><b><?php p($_['state'] ?? ''); ?></b></td></tr>
					<tr><th><?php p($l->t('Requested')); ?></th><td><?php p(($_['requestedBy'] ?? '') . ' · ' . ($_['requestedAt'] ?? '')); ?></td></tr>
					<?php if (!empty($_['decidedBy'])): ?>
					<tr><th><?php p($l->t('Decided')); ?></th><td><?php p($_['decidedBy'] . ' · ' . ($_['decidedAt'] ?? '') . ' · ' . ($_['method'] ?? '')); ?></td></tr>
					<?php endif; ?>
					<?php if (!empty($_['reason'])): ?>
					<tr><th><?php p($l->t('Reason')); ?></th><td><?php p($_['reason']); ?></td></tr>
					<?php endif; ?>
				</table>
				<p class="kzc-muted" style="margin-top:16px"><?php p($l->t('The hash binds the decision to the exact document state at the time of approval.')); ?></p>
			</main>
		</div>
	</div>
</div>
