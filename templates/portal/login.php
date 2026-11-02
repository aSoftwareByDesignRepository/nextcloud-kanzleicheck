<?php
/** PortalCheck client login — e-mail + code (US-P01). */
$appId = 'portalcheck';
style($appId, 'portal');
script($appId, 'portal-login');
?>
<div id="ptl-portal" class="ptl-portal">
	<main class="ptl-card" aria-labelledby="ptl-login-title">
		<h1 id="ptl-login-title" class="ptl-title"><?php p($l->t('Anmelden')); ?></h1>
		<p class="ptl-muted"><?php p($l->t('Geben Sie Ihre E-Mail-Adresse ein. Wir schicken Ihnen einen Code — kein Passwort nötig.')); ?></p>
		<form id="ptl-send-form" method="post" novalidate>
			<label for="ptl-email" class="ptl-label"><?php p($l->t('E-Mail-Adresse')); ?></label>
			<input id="ptl-email" name="email" type="email" autocomplete="email" required autofocus class="ptl-input" />
			<span class="ptl-field-error" data-for="ptl-email" hidden></span>
			<button type="submit" class="ptl-btn ptl-btn--primary" id="ptl-send-btn"><?php p($l->t('Code senden')); ?></button>
		</form>
		<form id="ptl-verify-form" method="post" hidden novalidate>
			<p class="ptl-muted" id="ptl-sent-note"><?php p($l->t('Wir haben Ihnen einen Code geschickt.')); ?></p>
			<label for="ptl-code" class="ptl-label"><?php p($l->t('6-stelliger Code')); ?></label>
			<input id="ptl-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required class="ptl-input ptl-input--code" />
			<span class="ptl-field-error" data-for="ptl-code" hidden></span>
			<button type="submit" class="ptl-btn ptl-btn--primary" id="ptl-verify-btn"><?php p($l->t('Anmelden')); ?></button>
			<button type="button" class="ptl-btn" id="ptl-resend-btn" disabled><?php p($l->t('Neuen Code anfordern')); ?></button>
		</form>
		<div id="ptl-login-status" role="status" aria-live="polite" class="ptl-status"></div>
	</main>
</div>
