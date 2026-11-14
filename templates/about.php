<?php
/**
 * KanzleiCheck Settings → About this project.
 *
 * Dedicated shell like the transfer page: renders inside the staff app
 * chrome — sponsor acknowledgement and open-source notice. Available to
 * all staff users (PageController gates the staff door, not app admins).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$appId = 'kanzleicheck';
style($appId, 'app');
style($appId, 'staff');
?>
<div id="app-content">
	<div id="app-content-wrapper">
		<div id="kzc-staff" class="kzc-app" data-app="<?php p($appId); ?>">
			<main id="kzc-staff-view" class="kzc-view" tabindex="-1">
				<?php include __DIR__ . '/parts/settings/about.php'; ?>
			</main>
		</div>
	</div>
</div>
