<?php
/** Magic-link confirm page — GET renders, POST consumes (AF-01). */
$appId = 'kanzleicheck';
style($appId, 'app');
style($appId, 'portal');
script($appId, 'portal-magic');
?>
<div class="kzc-portal">
	<main class="kzc-card">
		<h1 class="kzc-title"><?php p($l->t('Confirm sign-in')); ?></h1>
		<p class="kzc-muted"><?php p($l->t('Click "Sign in" to continue.')); ?></p>
		<button id="kzc-magic-btn" class="kzc-btn kzc-btn--primary" data-token="<?php p($_['token'] ?? ''); ?>"><?php p($l->t('Sign in')); ?></button>
		<div id="kzc-magic-status" role="status" aria-live="polite" class="kzc-status"></div>
	</main>
</div>
