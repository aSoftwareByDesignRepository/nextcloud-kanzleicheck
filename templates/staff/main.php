<?php
/** Staff shell — overview · inbox · board · requests · orgs · uploads · settings. */
$appId = 'portalcheck';
style($appId, 'app');
style($appId, 'staff');
script($appId, 'staff-app');
?>
<div id="app-content">
	<div id="app-content-wrapper">
		<div id="ptl-staff" class="ptl-app" data-app="<?php p($appId); ?>">
			<main id="ptl-staff-view" class="ptl-view" tabindex="-1">
				<noscript><p><?php p($l->t('This view needs JavaScript.')); ?></p></noscript>
			</main>
		</div>
	</div>
</div>
