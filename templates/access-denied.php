<?php
/**
 * Access-denied / page-GET error surface for HTML routes (staff door +
 * page-route misses). Rendered via 'guest' chrome — never a JSON envelope.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$appId = 'kanzleicheck';
$title = (string)($_['title'] ?? $l->t('Access denied'));
$message = (string)($_['message'] ?? $l->t('You do not have access to KanzleiCheck.'));
$hint = (string)($_['hint'] ?? '');
$homeUrl = (string)($_['homeUrl'] ?? '/');
style($appId, 'common/tokens');
style($appId, 'app');
style($appId, 'portal');
?>
<div class="kzc-portal">
	<main class="kzc-card" role="alert" aria-labelledby="kzc-denied-title">
		<h1 id="kzc-denied-title" class="kzc-title"><?php p($title); ?></h1>
		<p class="kzc-muted"><?php p($message); ?></p>
		<?php if ($hint !== ''): ?>
			<p class="kzc-muted"><?php p($hint); ?></p>
		<?php endif; ?>
		<p><a class="kzc-btn kzc-btn--primary" href="<?php p($homeUrl); ?>"><?php p($l->t('Back to Nextcloud')); ?></a></p>
	</main>
</div>
