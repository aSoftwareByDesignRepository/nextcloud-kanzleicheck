<?php
/**
 * In-page settings sub-navigation (DutyCheck/CustomerCheck parity).
 *
 * Complements the sidebar: Nextcloud collapses #app-navigation below
 * ~1024px, so without this chip bar staff cannot reach sibling settings
 * pages on phones/tablets. Labels and URLs come from PageController —
 * never hardcoded here. Only VISIBLE sections are present.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var string $kzcRequestedSection
 */

$kzcNavLabels = (array)($_['settingsSectionLabels'] ?? []);
$kzcNavUrls = (array)(($_['urls']['settingsSections'] ?? []) ?: []);
if ($kzcNavLabels === []) {
	return;
}
?>
<nav class="kzc-settings-nav" id="kzc-settings-pages" aria-label="<?php p($l->t('Settings pages')); ?>">
	<?php foreach ($kzcNavLabels as $sectionId => $sectionLabel):
		$sectionId = (string)$sectionId;
		$href = (string)($kzcNavUrls[$sectionId] ?? '');
		if ($href === '' || $href === '#') {
			continue;
		}
		$active = ($kzcRequestedSection ?? '') === $sectionId;
		?>
		<a class="kzc-settings-nav__link<?php p($active ? ' is-active' : ''); ?>"
			href="<?php p($href); ?>"
			<?php if ($active): ?>aria-current="page"<?php endif; ?>>
			<?php p((string)$sectionLabel); ?>
		</a>
	<?php endforeach; ?>
</nav>
