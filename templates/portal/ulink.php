<?php
/** Third-party upload page (US-P29): branding + label + upload only. */
$appId = 'portalcheck';
style($appId, 'app');
style($appId, 'portal');
script($appId, 'portal-ulink');
?>
<div class="ptl-portal">
	<main class="ptl-card">
		<h1 class="ptl-title"><?php p($l->t('Upload a file')); ?></h1>
		<p class="ptl-muted"><?php p($_['label'] ?? ''); ?><?php if (!empty($_['orgName'])) { p(' — ' . $_['orgName']); } ?></p>
		<form id="ptl-ulink-form" enctype="multipart/form-data">
			<input type="file" id="ptl-ulink-file" class="ptl-input" required />
			<span class="ptl-field-error" data-for="ptl-ulink-file" hidden></span>
			<button type="submit" class="ptl-btn ptl-btn--primary"><?php p($l->t('Upload')); ?></button>
		</form>
		<div id="ptl-ulink-status" role="status" aria-live="polite" class="ptl-status"></div>
	</main>
</div>
