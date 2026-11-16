<?php
/** KanzleiCheck client shell: Home · Chat · Documents · More (US-P31). */
$appId = 'kanzleicheck';
style($appId, 'common/tokens');
style($appId, 'app');
style($appId, 'portal');
script($appId, 'portal-app');
$svg = static function (string $paths): string {
	return '<svg class="kzc-icon" aria-hidden="true" focusable="false" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
};
?>
<div id="kzc-app" class="kzc-portal" data-csrf-cookie="<?php p($_['csrfCookie'] ?? 'kzc_csrf'); ?>">
	<header class="kzc-portal-brand" aria-hidden="true">
		<?php print_unescaped($svg('<rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>')); ?>
		<span class="kzc-portal-brand__name">KanzleiCheck · <?php p($l->t('Client portal')); ?></span>
	</header>
	<nav class="kzc-tabbar" aria-label="<?php p($l->t('Portal sections')); ?>">
		<button class="kzc-tab" data-view="start" aria-current="page"><?php print_unescaped($svg('<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>')); ?><span><?php p($l->t('Home')); ?></span></button>
		<button class="kzc-tab" data-view="chat"><?php print_unescaped($svg('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>')); ?><span><?php p($l->t('Chat')); ?></span><span class="kzc-tab-badge" hidden></span></button>
		<button class="kzc-tab" data-view="docs"><?php print_unescaped($svg('<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>')); ?><span><?php p($l->t('Documents')); ?></span></button>
		<button class="kzc-tab" data-view="more"><?php print_unescaped($svg('<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>')); ?><span><?php p($l->t('More')); ?></span></button>
	</nav>
	<main id="kzc-view" class="kzc-view" tabindex="-1">
		<noscript><p><?php p($l->t('This portal needs JavaScript.')); ?></p></noscript>
	</main>
</div>
