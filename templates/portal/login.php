<?php
/** KanzleiCheck client login — e-mail + code (US-P01). */
$appId = 'kanzleicheck';
style($appId, 'common/tokens');
style($appId, 'app');
style($appId, 'portal');
script($appId, 'portal-login');
?>
<div id="kzc-portal" class="kzc-portal">
	<main class="kzc-card" aria-labelledby="kzc-login-title">
		<h1 id="kzc-login-title" class="kzc-title"><?php p($l->t('Sign in')); ?></h1>
		<p class="kzc-muted"><?php p($l->t('Enter your e-mail address. We will send you a code — no password needed.')); ?></p>
		<form id="kzc-send-form" method="post" class="kzc-form" novalidate>
			<label for="kzc-email" class="kzc-label"><?php p($l->t('E-mail address')); ?></label>
			<input id="kzc-email" name="email" type="email" autocomplete="email" required autofocus class="kzc-input" />
			<span class="kzc-field-error" data-for="kzc-email" role="alert" hidden></span>
			<button type="submit" class="kzc-btn kzc-btn--primary" id="kzc-send-btn"><?php p($l->t('Send code')); ?></button>
		</form>
		<form id="kzc-verify-form" method="post" class="kzc-form" hidden novalidate>
			<p class="kzc-muted" id="kzc-sent-note"><?php p($l->t('We have sent you a code.')); ?></p>
			<label for="kzc-code" class="kzc-label"><?php p($l->t('6-digit code')); ?></label>
			<input id="kzc-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required class="kzc-input kzc-input--code" />
			<span class="kzc-field-error" data-for="kzc-code" role="alert" hidden></span>
			<button type="submit" class="kzc-btn kzc-btn--primary" id="kzc-verify-btn"><?php p($l->t('Sign in')); ?></button>
			<button type="button" class="kzc-btn" id="kzc-resend-btn" disabled><?php p($l->t('Request a new code')); ?></button>
		</form>
		<div id="kzc-login-status" role="status" aria-live="polite" class="kzc-status"></div>
	</main>
</div>
