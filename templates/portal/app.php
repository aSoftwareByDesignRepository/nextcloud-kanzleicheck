<?php
/** PortalCheck client shell: Home · Chat · Documents · More (US-P31). */
$appId = 'portalcheck';
style($appId, 'app');
style($appId, 'portal');
script($appId, 'portal-app');
?>
<div id="ptl-app" class="ptl-portal" data-csrf-cookie="<?php p($_['csrfCookie'] ?? 'ptl_csrf'); ?>">
	<main id="ptl-view" class="ptl-view" tabindex="-1">
		<noscript><p><?php p($l->t('This portal needs JavaScript.')); ?></p></noscript>
	</main>
	<nav class="ptl-tabbar" aria-label="<?php p($l->t('Portal sections')); ?>">
		<button class="ptl-tab" data-view="start" aria-current="page">🏠<span><?php p($l->t('Home')); ?></span></button>
		<button class="ptl-tab" data-view="chat">💬<span><?php p($l->t('Chat')); ?></span><span class="ptl-badge" hidden></span></button>
		<button class="ptl-tab" data-view="docs">📄<span><?php p($l->t('Documents')); ?></span></button>
		<button class="ptl-tab" data-view="more">⋯<span><?php p($l->t('More')); ?></span></button>
	</nav>
</div>
