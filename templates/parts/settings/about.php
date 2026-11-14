<?php
/**
 * Settings sub-page: About this project.
 *
 * Sponsor acknowledgement (first customer who made this product possible)
 * and the open-source notice with repository links. Rendered inside the
 * staff shell via templates/about.php — all staff, not only app admins.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$newTab = $l->t('(opens in a new tab)');
$sponsorUrl = 'https://steuerberater-eimer.de';
$repositoryUrl = 'https://github.com/aSoftwareByDesignRepository/nextcloud-kanzleicheck';
$issuesUrl = $repositoryUrl . '/issues';
$licenseUrl = 'https://www.gnu.org/licenses/agpl-3.0.html';
?>
<section class="kzc-card kzc-section" aria-labelledby="kzc-about-sponsor-h" id="kzc-about-sponsor-card">
	<header class="kzc-section__header">
		<div>
			<h2 id="kzc-about-sponsor-h"><?php p($l->t('Sponsor')); ?></h2>
			<p class="kzc-section__sub"><?php p($l->t('The people who made this app possible.')); ?></p>
		</div>
	</header>
	<p><?php p($l->t('KanzleiCheck was developed in close cooperation with its first sponsor — Steuerberater Martin Eimer (Bad Soden am Taunus). His requirements and feedback from everyday tax-office practice shaped this product.')); ?></p>
	<div class="kzc-actions">
		<a class="kzc-btn" href="<?php p($sponsorUrl); ?>" target="_blank" rel="noopener noreferrer"><?php p($l->t('steuerberater-eimer.de')); ?><span class="kzc-about__new-tab"> <?php p($newTab); ?></span></a>
	</div>
</section>
<section class="kzc-card kzc-section" aria-labelledby="kzc-about-oss-h" id="kzc-about-oss-card">
	<header class="kzc-section__header">
		<div>
			<h2 id="kzc-about-oss-h"><?php p($l->t('Open source')); ?></h2>
			<p class="kzc-section__sub"><?php p($l->t('Free software — inspect, adapt, contribute.')); ?></p>
		</div>
	</header>
	<p><?php p($l->t(
		'KanzleiCheck is free and open-source software, released under the %s license. The complete source code is public.',
		['AGPL-3.0-or-later']
	)); ?></p>
	<ul>
		<li><a href="<?php p($repositoryUrl); ?>" target="_blank" rel="noopener noreferrer"><?php p($l->t('Source code on GitHub')); ?><span class="kzc-about__new-tab"> <?php p($newTab); ?></span></a></li>
		<li><a href="<?php p($issuesUrl); ?>" target="_blank" rel="noopener noreferrer"><?php p($l->t('Issue tracker')); ?><span class="kzc-about__new-tab"> <?php p($newTab); ?></span></a></li>
		<li><a href="<?php p($licenseUrl); ?>" target="_blank" rel="noopener noreferrer"><?php p($l->t('License text')); ?><span class="kzc-about__new-tab"> <?php p($newTab); ?></span></a></li>
	</ul>
</section>
