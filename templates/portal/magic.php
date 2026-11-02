<?php
/** Magic-link confirm page — GET renders, POST consumes (AF-01). */
$appId = 'portalcheck';
style($appId, 'app');
style($appId, 'portal');
script($appId, 'portal-magic');
?>
<div class="ptl-portal">
	<main class="ptl-card">
		<h1 class="ptl-title"><?php p($l->t('Confirm sign-in')); ?></h1>
		<p class="ptl-muted"><?php p($l->t('Click "Sign in" to continue.')); ?></p>
		<button id="ptl-magic-btn" class="ptl-btn ptl-btn--primary" data-token="<?php p($_['token'] ?? ''); ?>"><?php p($l->t('Sign in')); ?></button>
		<div id="ptl-magic-status" role="status" aria-live="polite" class="ptl-status"></div>
	</main>
</div>
