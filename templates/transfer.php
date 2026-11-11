<?php
/**
 * PortalCheck Settings → Transfer configuration (test → production).
 *
 * Dedicated shell: the synced settings part renders inside the staff app
 * chrome — not inside the settings SPA. App admins only (PageController
 * gates); the export needs no extra right, replace needs a system admin.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$appId = 'portalcheck';
style($appId, 'app');
style($appId, 'staff');
style($appId, 'common/transfer');
script($appId, 'transfer-bridge');
script($appId, 'transfer');
?>
<div id="app-content">
	<div id="app-content-wrapper">
		<div id="ptl-staff" class="ptl-app" data-app="<?php p($appId); ?>">
			<main id="ptl-staff-view" class="ptl-view" tabindex="-1">
				<?php include __DIR__ . '/parts/settings/transfer.php'; ?>
			</main>
		</div>
	</div>
</div>
