<?php
/** Magic-link confirm page — GET renders, POST consumes (AF-01). */
$appId = 'portalcheck';
style($appId, 'portal');
script($appId, 'portal-magic');
?>
<div class="ptl-portal">
	<main class="ptl-card">
		<h1 class="ptl-title"><?php p($l->t('Anmeldung bestätigen')); ?></h1>
		<p class="ptl-muted"><?php p($l->t('Klicken Sie auf „Anmelden“, um fortzufahren.')); ?></p>
		<button id="ptl-magic-btn" class="ptl-btn ptl-btn--primary" data-token="<?php p($_['token'] ?? ''); ?>"><?php p($l->t('Anmelden')); ?></button>
		<div id="ptl-magic-status" role="status" aria-live="polite" class="ptl-status"></div>
	</main>
</div>
