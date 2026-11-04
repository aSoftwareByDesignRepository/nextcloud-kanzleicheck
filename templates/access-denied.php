<?php
/**
 * Access-denied / page-GET error surface for HTML routes (staff door +
 * page-route misses). Rendered via 'guest' chrome — never a JSON envelope.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$appId = 'portalcheck';
$title = (string)($_['title'] ?? $l->t('Access denied'));
$message = (string)($_['message'] ?? $l->t('You do not have access to PortalCheck.'));
$hint = (string)($_['hint'] ?? '');
$homeUrl = (string)($_['homeUrl'] ?? '/');
style($appId, 'app');
style($appId, 'portal');
?>
<div class="ptl-portal">
	<main class="ptl-card" role="alert" aria-labelledby="ptl-denied-title">
		<h1 id="ptl-denied-title" class="ptl-title"><?php p($title); ?></h1>
		<p class="ptl-muted"><?php p($message); ?></p>
		<?php if ($hint !== ''): ?>
			<p class="ptl-muted"><?php p($hint); ?></p>
		<?php endif; ?>
		<p><a class="ptl-btn ptl-btn--primary" href="<?php p($homeUrl); ?>"><?php p($l->t('Back to Nextcloud')); ?></a></p>
	</main>
</div>
