<?php

declare(strict_types=1);

/**
 * In-page mobile menu control. Core #app-navigation-toggle is unreliable when
 * #content uses overflow:hidden (arbeitszeitcheck#33 / Atlas mobile-nav contract).
 *
 * @var \OCP\IL10N $l
 */

use OCA\KanzleiCheck\Service\IconCatalog;

if (!isset($l)) {
	return;
}
?>
<button type="button"
	class="kzc-nav-toggle"
	id="kzc-nav-toggle"
	aria-label="<?php p($l->t('Toggle navigation menu')); ?>"
	aria-expanded="false"
	aria-controls="app-navigation"
	data-kzc-nav-toggle
	data-aria-label-open="<?php p($l->t('Toggle navigation menu')); ?>"
	data-aria-label-close="<?php p($l->t('Close navigation menu')); ?>">
	<?php print_unescaped(IconCatalog::render('menu', 'kzc-nav-toggle__icon')); ?>
	<span class="kzc-nav-toggle__label"><?php p($l->t('Menu')); ?></span>
</button>
