<?php
$appId = 'kanzleicheck';
style($appId, 'common/tokens');
style($appId, 'app');
style($appId, 'portal');
?>
<div class="kzc-portal">
	<main class="kzc-card">
		<h1 class="kzc-title"><?php p($l->t('Not found')); ?></h1>
		<p class="kzc-muted"><?php p($l->t('There is nothing here (any more).')); ?></p>
	</main>
</div>
