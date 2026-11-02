<?php
/** Approval proof — hash-bound decision record (US-P23). Printable. */
$appId = 'portalcheck';
style($appId, 'portal');
?>
<div class="ptl-portal">
	<main class="ptl-card ptl-proof">
		<h1 class="ptl-title"><?php p($l->t('Freigabe-Nachweis')); ?></h1>
		<table class="ptl-table">
			<tr><th><?php p($l->t('Dokument')); ?></th><td><?php p($_['fileName'] ?? ''); ?></td></tr>
			<tr><th><?php p($l->t('SHA-256')); ?></th><td><code class="ptl-mono"><?php p($_['sha'] ?? ''); ?></code></td></tr>
			<tr><th><?php p($l->t('Status')); ?></th><td><b><?php p($_['state'] ?? ''); ?></b></td></tr>
			<tr><th><?php p($l->t('Angefordert')); ?></th><td><?php p(($_['requestedBy'] ?? '') . ' · ' . ($_['requestedAt'] ?? '')); ?></td></tr>
			<?php if (!empty($_['decidedBy'])): ?>
			<tr><th><?php p($l->t('Entschieden')); ?></th><td><?php p($_['decidedBy'] . ' · ' . ($_['decidedAt'] ?? '') . ' · ' . ($_['method'] ?? '')); ?></td></tr>
			<?php endif; ?>
			<?php if (!empty($_['reason'])): ?>
			<tr><th><?php p($l->t('Grund')); ?></th><td><?php p($_['reason']); ?></td></tr>
			<?php endif; ?>
		</table>
		<p class="ptl-muted" style="margin-top:16px"><?php p($l->t('Der Hash bindet die Entscheidung an den exakten Dokumentenstand zum Freigabezeitpunkt.')); ?></p>
	</main>
</div>
