<?php
/**
 * Staff shell — synced check-shell chrome (sidebar nav, page header, live
 * regions, help footer) + either the SPA mount or a server-rendered
 * settings partial inside <main id="kzc-main-content">.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/../common/page-start.php';
if ((string)($_['settingsSection'] ?? '') !== '') {
	include __DIR__ . '/../parts/settings-nav.php';
}
if (!empty($_['pageTemplate'])) {
	include __DIR__ . '/../' . $_['pageTemplate'];
} else {
	?>
	<div id="kzc-staff" class="kzc-app-mount" data-app="<?php p((string)($_['appId'] ?? 'kanzleicheck')); ?>"
		data-kzc-view="<?php p((string)($_['pageId'] ?? 'overview')); ?>"
		data-kzc-section="<?php p((string)($_['settingsSection'] ?? '')); ?>">
		<div id="kzc-staff-view" class="kzc-view" tabindex="-1">
			<noscript><p><?php p($l->t('This view needs JavaScript.')); ?></p></noscript>
		</div>
	</div>
	<?php
}
include __DIR__ . '/../common/page-end.php';
