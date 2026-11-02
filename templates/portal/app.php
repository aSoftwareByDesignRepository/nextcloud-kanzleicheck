<?php
/** PortalCheck client shell: Zu erledigen · Chat · Dokumente · Mehr (US-P31). */
$appId = 'portalcheck';
style($appId, 'portal');
script($appId, 'portal-app');
?>
<div id="ptl-app" class="ptl-portal" data-csrf-cookie="<?php p($_['csrfCookie'] ?? 'ptl_csrf'); ?>">
	<main id="ptl-view" class="ptl-view" tabindex="-1">
		<noscript><p><?php p($l->t('Dieses Portal benötigt JavaScript.')); ?></p></noscript>
	</main>
	<nav class="ptl-tabbar" aria-label="<?php p($l->t('Portalbereiche')); ?>">
		<button class="ptl-tab" data-view="start" aria-current="page">🏠<span><?php p($l->t('Start')); ?></span></button>
		<button class="ptl-tab" data-view="chat">💬<span><?php p($l->t('Chat')); ?></span><span class="ptl-badge" hidden></span></button>
		<button class="ptl-tab" data-view="docs">📄<span><?php p($l->t('Dokumente')); ?></span></button>
		<button class="ptl-tab" data-view="more">⋯<span><?php p($l->t('Mehr')); ?></span></button>
	</nav>
</div>
