<?php
/**
 * Settings sub-page: Transfer configuration (test → production).
 * Two cards — Export (passphrase → .nctransfer download) and Import
 * (mode → file → passphrase → mandatory dry-run → confirm) — plus the
 * "Last transfer" receipt line. The report dialog is a native <dialog>.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$isSystemAdmin = !empty($_['isSystemAdmin']);
$lastReceipt = is_array($_['transferLastReceipt'] ?? null) ? $_['transferLastReceipt'] : null;
?>
<section class="kzc-card kzc-section" aria-labelledby="kzc-xfer-export-h" id="kzc-transfer-export-card">
	<header class="kzc-section__header">
		<div>
			<h2 id="kzc-xfer-export-h"><?php p($l->t('Export configuration')); ?></h2>
			<p class="kzc-section__sub"><?php p(!empty($_['transferExportBlurb']) ? (string)$_['transferExportBlurb'] : $l->t('Download all settings, catalogues and business data as one encrypted file — then import it on your production instance.')); ?></p>
		</div>
	</header>
	<div class="kzc-callout kzc-callout--info" role="note">
		<p><strong><?php p($l->t('Secrets are never included.')); ?></strong>
		<?php p($l->t('Passwords, tokens and salts stay on this instance — the file is encrypted and still does not carry them. It does contain user and group names: handle it like personal data.')); ?></p>
	</div>
	<form id="kzc-transfer-export-form" class="kzc-form" novalidate>
		<div class="kzc-form-row">
			<label for="kzc-transfer-passphrase"><?php p($l->t('Encryption passphrase')); ?></label>
			<div class="kzc-input-group">
				<input type="password" id="kzc-transfer-passphrase" name="passphrase" autocomplete="new-password" required minlength="12" aria-describedby="kzc-transfer-passphrase-hint kzc-transfer-passphrase-strength">
				<button type="button" class="kzc-btn kzc-btn--ghost" id="kzc-transfer-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
				<button type="button" class="kzc-btn kzc-btn--secondary" id="kzc-transfer-passphrase-generate"><?php p($l->t('Generate')); ?></button>
			</div>
			<p class="kzc-form-row__hint" id="kzc-transfer-passphrase-hint"><?php p($l->t('At least 12 characters. Without this passphrase the file cannot be restored — store it safely.')); ?></p>
			<p class="kzc-form-row__hint kzc-transfer-strength" id="kzc-transfer-passphrase-strength" role="status" aria-live="polite"></p>
		</div>
		<div class="kzc-actions">
			<button type="submit" class="kzc-btn kzc-btn--primary" id="kzc-transfer-export-btn">
				<?php p($l->t('Download encrypted transfer file')); ?>
			</button>
		</div>
	</form>
</section>

<section class="kzc-card kzc-section" aria-labelledby="kzc-xfer-import-h" id="kzc-transfer-import-card">
	<header class="kzc-section__header">
		<div>
			<h2 id="kzc-xfer-import-h"><?php p($l->t('Import configuration')); ?></h2>
			<p class="kzc-section__sub"><?php p($l->t('Pick a transfer file, enter its passphrase, review the preview — then confirm. Nothing is written before you confirm.')); ?></p>
		</div>
	</header>
	<?php if ($lastReceipt !== null): ?>
	<p class="kzc-transfer-last" id="kzc-transfer-last">
		<?php p($l->t('Last transfer')); ?>:
		<strong><?php p((string)($lastReceipt['at'] ?? '—')); ?></strong>
		· <?php
			$transferMode = (string)($lastReceipt['mode'] ?? '');
			if ($transferMode === 'merge') {
				p($l->t('Merge'));
			} elseif ($transferMode === 'replace') {
				p($l->t('Replace'));
			} else {
				p($transferMode !== '' ? $transferMode : '—');
			}
		?>
		· <?php p(($lastReceipt['result'] ?? '') === 'applied' ? $l->t('applied') : $l->t('failed')); ?>
		<?php if (($lastReceipt['snapshotId'] ?? null) !== null): ?>
			· <?php p($l->t('undo via snapshot')); ?> <code><?php p((string)$lastReceipt['snapshotId']); ?></code>
		<?php endif; ?>
	</p>
	<?php endif; ?>
	<form id="kzc-transfer-import-form" class="kzc-form" novalidate>
		<fieldset class="kzc-form-row kzc-fieldset" aria-describedby="kzc-transfer-replace-hint">
			<legend><?php p($l->t('Mode')); ?></legend>
			<div class="kzc-choice-cards">
				<label class="kzc-choice-card">
					<input type="radio" name="mode" value="merge" checked>
					<span class="kzc-choice-card__copy">
						<span class="kzc-choice-card__title"><?php p($l->t('Merge')); ?></span>
						<span class="kzc-choice-card__hint"><?php p($l->t('add and update; nothing is deleted')); ?></span>
					</span>
				</label>
				<label class="kzc-choice-card kzc-choice-card--danger">
					<input type="radio" name="mode" value="replace" <?php if (!$isSystemAdmin): ?>disabled<?php endif; ?>>
					<span class="kzc-choice-card__copy">
						<span class="kzc-choice-card__title"><?php p($l->t('Replace')); ?></span>
						<span class="kzc-choice-card__hint"><?php p($l->t('make identical to the file; prod-only rows are deleted')); ?></span>
					</span>
				</label>
			</div>
			<p class="kzc-form-row__hint" id="kzc-transfer-replace-hint">
				<?php if ($isSystemAdmin): ?>
					<?php p($l->t('Replace needs maintenance mode ON (occ maintenance:mode --on) — you will type REPLACE in the preview to confirm.')); ?>
				<?php else: ?>
					<?php p($l->t('Replace is available to Nextcloud system administrators only.')); ?>
				<?php endif; ?>
			</p>
		</fieldset>
		<div class="kzc-form-row">
			<label for="kzc-transfer-file"><?php p($l->t('Transfer file')); ?></label>
			<input type="file" id="kzc-transfer-file" name="file" accept=".nctransfer,application/json" class="kzc-file-input">
		</div>
		<div class="kzc-form-row">
			<label for="kzc-transfer-import-passphrase"><?php p($l->t('File passphrase')); ?></label>
			<div class="kzc-input-group">
				<input type="password" id="kzc-transfer-import-passphrase" name="import_passphrase" autocomplete="off">
				<button type="button" class="kzc-btn kzc-btn--ghost" id="kzc-transfer-import-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
			</div>
		</div>
		<div id="kzc-transfer-import-status" class="kzc-form-row__hint" role="status" aria-live="polite"></div>
	</form>
</section>

<dialog id="kzc-transfer-report" class="kzc-dialog kzc-transfer-report" aria-labelledby="kzc-transfer-report-h">
	<div class="kzc-dialog__body">
		<h3 id="kzc-transfer-report-h"><?php p($l->t('Import preview')); ?></h3>
		<p id="kzc-transfer-report-verdict" class="kzc-transfer-report__verdict"></p>
		<p id="kzc-transfer-report-file" class="kzc-form-row__hint"></p>
		<div id="kzc-transfer-report-groups"></div>
	</div>
	<footer class="kzc-dialog__footer">
		<div id="kzc-transfer-replace-confirm" class="kzc-form-row" hidden>
			<label for="kzc-transfer-replace-token"><?php p($l->t('Type REPLACE to confirm')); ?></label>
			<input type="text" id="kzc-transfer-replace-token" autocomplete="off" spellcheck="false" placeholder="REPLACE">
		</div>
		<div class="kzc-actions">
			<button type="button" class="kzc-btn kzc-btn--secondary" id="kzc-transfer-cancel" value="cancel"><?php p($l->t('Cancel')); ?></button>
			<button type="button" class="kzc-btn kzc-btn--primary" id="kzc-transfer-confirm"><?php p($l->t('Apply import')); ?></button>
		</div>
	</footer>
</dialog>

<dialog id="kzc-transfer-progress" class="kzc-dialog" aria-labelledby="kzc-transfer-progress-h" aria-live="polite">
	<div class="kzc-dialog__body">
		<h3 id="kzc-transfer-progress-h"><?php p($l->t('Applying import')); ?></h3>
		<progress id="kzc-transfer-progress-bar" max="100" value="0"></progress>
		<p id="kzc-transfer-progress-text"><?php p($l->t('Starting…')); ?></p>
		<p class="kzc-form-row__hint"><?php p($l->t('You can close this — the result is saved and shown under “Last transfer”.')); ?></p>
	</div>
</dialog>

<div id="kzc-transfer-result-live" class="kzc-visually-hidden" role="status" aria-live="polite"></div>
