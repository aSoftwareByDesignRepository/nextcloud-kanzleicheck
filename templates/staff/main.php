<?php
/** Staff shell — overview · inbox · board · requests · orgs · uploads · settings. */
$appId = 'portalcheck';
style($appId, 'staff');
script($appId, 'staff-app');
?>
<div id="ptl-staff" class="ptl-app" data-app="<?php p($appId); ?>">
	<main id="ptl-staff-view" class="ptl-view" tabindex="-1">
		<noscript><p><?php p($l->t('Diese Ansicht benötigt JavaScript.')); ?></p></noscript>
	</main>
</div>
