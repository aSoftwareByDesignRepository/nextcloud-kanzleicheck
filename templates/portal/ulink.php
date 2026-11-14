<?php
/** Third-party upload page (US-P29): branding + label + upload only. */
$appId = 'kanzleicheck';
style($appId, 'app');
style($appId, 'portal');
script($appId, 'portal-ulink');
?>
<div class="kzc-portal">
	<main class="kzc-card">
		<h1 class="kzc-title"><?php p($l->t('Upload a file')); ?></h1>
		<p class="kzc-muted"><?php p($_['label'] ?? ''); ?><?php if (!empty($_['orgName'])) { p(' — ' . $_['orgName']); } ?></p>
		<form id="kzc-ulink-form" enctype="multipart/form-data">
			<input type="file" id="kzc-ulink-file" class="kzc-input" required />
			<span class="kzc-field-error" data-for="kzc-ulink-file" hidden></span>
			<button type="submit" class="kzc-btn kzc-btn--primary"><?php p($l->t('Upload')); ?></button>
		</form>
		<div id="kzc-ulink-status" role="status" aria-live="polite" class="kzc-status"></div>
	</main>
</div>
