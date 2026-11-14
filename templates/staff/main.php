<?php
/** Staff shell — overview · inbox · board · requests · orgs · uploads · settings. */
$appId = 'kanzleicheck';
style($appId, 'app');
style($appId, 'staff');
script($appId, 'staff-app');
?>
<div id="app-content">
	<div id="app-content-wrapper">
		<div id="kzc-staff" class="kzc-app" data-app="<?php p($appId); ?>">
			<main id="kzc-staff-view" class="kzc-view" tabindex="-1">
				<noscript><p><?php p($l->t('This view needs JavaScript.')); ?></p></noscript>
			</main>
		</div>
	</div>
</div>
