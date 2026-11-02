// @ts-check
/**
 * ATLAS_BTN_VARIANT_ARMOR — button_variant_specificity_collapse oracle.
 * NC core inputs.scss repaints every button:not(.button-vue) at 0-1-1 with
 * the pale --color-primary-element-light fill; a lone .ptl-btn--primary
 * (0-1-0) loses on real <button> elements while anchors keep the true paint.
 * Oracle: inject <a class="ptl-btn ptl-btn--primary"> (never core-repainted)
 * beside a <button class="ptl-btn ptl-btn--primary"> and a body-mounted
 * .ptl-dialog <button> — all three must carry the same solid fill.
 */
const { test, expect } = require('@playwright/test');

const APP = '/index.php/apps/portalcheck';

function paintOf(locator) {
	return locator.first().evaluate((el) => {
		const cs = getComputedStyle(el);
		return { bg: cs.backgroundColor, border: cs.borderTopColor, ink: cs.color };
	});
}

test.describe('Button-variant armor', () => {
	test('ATLAS_BTN_VARIANT_ARMOR primary parity button-vs-anchor vs dialog (guest login)', async ({ page }) => {
		await page.goto(`${APP}/portal/login`, { waitUntil: 'domcontentloaded' });
		await page.locator('#ptl-send-form').waitFor({ state: 'visible', timeout: 15000 });
		await page.evaluate(() => {
			document.querySelectorAll('.ptl-variant-oracle').forEach((n) => n.remove());
			const host = document.querySelector('.ptl-portal') || document.body;
			const zone = document.createElement('div');
			zone.className = 'ptl-variant-oracle';
			const a = document.createElement('a');
			a.className = 'ptl-btn ptl-btn--primary ptl-oracle-anchor';
			a.href = '#';
			a.textContent = 'oracle';
			const b = document.createElement('button');
			b.className = 'ptl-btn ptl-btn--primary ptl-oracle-button';
			b.textContent = 'oracle';
			zone.appendChild(a);
			zone.appendChild(b);
			host.appendChild(zone);
			// body-mounted dialog twin — the overlay scope that hid the collapse
			const dlg = document.createElement('dialog');
			dlg.className = 'ptl-dialog';
			const db = document.createElement('button');
			db.className = 'ptl-btn ptl-btn--primary ptl-oracle-dialog-button';
			db.textContent = 'oracle';
			dlg.appendChild(db);
			document.body.appendChild(dlg);
			dlg.showModal();
		});
		// dialog autofocus lands on the first button — focused paint is
		// legitimately the :focus-visible fill, not base; blur before sampling.
		await page.evaluate(() => { if (document.activeElement instanceof HTMLElement) document.activeElement.blur(); });
		await page.waitForTimeout(250);
		const anchor = await paintOf(page.locator('.ptl-oracle-anchor'));
		const button = await paintOf(page.locator('.ptl-oracle-button'));
		const dialogBtn = await paintOf(page.locator('.ptl-oracle-dialog-button'));
		expect(button.bg).toBe(anchor.bg);
		expect(dialogBtn.bg).toBe(anchor.bg);
		expect(button.ink).toBe(anchor.ink);
		// pale core fill is not the variant paint
		expect(button.bg).not.toBe('rgba(0, 0, 0, 0)');
	});
});
