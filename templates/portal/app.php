<?php
/** KanzleiCheck client shell: Home · Chat · Documents · More (US-P31). */
$appId = 'kanzleicheck';
style($appId, 'app');
style($appId, 'portal');
script($appId, 'portal-app');
?>
<div id="kzc-app" class="kzc-portal" data-csrf-cookie="<?php p($_['csrfCookie'] ?? 'kzc_csrf'); ?>">
	<main id="kzc-view" class="kzc-view" tabindex="-1">
		<noscript><p><?php p($l->t('This portal needs JavaScript.')); ?></p></noscript>
	</main>
	<nav class="kzc-tabbar" aria-label="<?php p($l->t('Portal sections')); ?>">
		<button class="kzc-tab" data-view="start" aria-current="page">🏠<span><?php p($l->t('Home')); ?></span></button>
		<button class="kzc-tab" data-view="chat">💬<span><?php p($l->t('Chat')); ?></span><span class="kzc-badge" hidden></span></button>
		<button class="kzc-tab" data-view="docs">📄<span><?php p($l->t('Documents')); ?></span></button>
		<button class="kzc-tab" data-view="more">⋯<span><?php p($l->t('More')); ?></span></button>
	</nav>
</div>
