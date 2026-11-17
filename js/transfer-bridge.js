/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * exposes the window.KanzleiCheckApi / KanzleiCheckMessaging contract the
 * synced js/transfer.js expects, in KanzleiCheck's own API envelope
 * ({success,data} / {success:false,error,context}). App-specific — not synced.
 */
(function () {
	'use strict';
	function requestToken() {
		return (window.OC && OC.requestToken) || '';
	}
	async function parse(resp) {
		const j = await resp.json().catch(() => null);
		if (!resp.ok || (j && (j.ok === false || j.success === false))) {
			const errObj = j && j.error;
			throw {
				code: (errObj && (errObj.code || errObj)) || 'SERVER_ERROR',
				context: (j && j.context) || {},
			};
		}
		return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
	}
	window.KanzleiCheckApi = {
		url(path) {
			return OC.generateUrl('/apps/kanzleicheck' + path);
		},
		async get(path, params) {
			let u = this.url(path);
			if (params) {
				const q = Object.keys(params)
					.filter((k) => params[k] !== undefined && params[k] !== null)
					.map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
					.join('&');
				if (q) u += '?' + q;
			}
			return parse(await fetch(u, { credentials: 'same-origin' }));
		},
		async post(path, body) {
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}));
		},
		async upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}));
		},
	};
	// Messaging: the synced transfer.js asks for .alert/.polite — map both
	// onto the app-owned toast announcer (visible .kzc-toast + live regions)
	// and always mirror into the kzc-transfer-result-live status region the
	// synced part renders. OC.Notification is gone from NC≥31 bundles — it
	// may only ever be a fallback behind the app-owned channel, never the
	// only write.
	const t = (s) => (window.OC && OC.L10N && OC.L10N.translate)
		? OC.L10N.translate('kanzleicheck', s) : s;
	let toastContainer = null;
	function ensureToastContainer() {
		if (toastContainer && document.body.contains(toastContainer)) {
			return toastContainer;
		}
		toastContainer = document.createElement('div');
		toastContainer.className = 'kzc-toasts';
		toastContainer.id = 'kzc-toasts';
		document.body.appendChild(toastContainer);
		return toastContainer;
	}
	function toast(text, kind) {
		const k = kind === 'error' ? 'error' : (kind === 'warning' ? 'warning' : 'success');
		const container = ensureToastContainer();
		// Dedup on kind+text with timer reset — identical toasts must not
		// stack (rapid retries, repeated API failures).
		const dedupKey = k + '|' + String(text);
		const existing = typeof container.querySelectorAll === 'function'
			? container.querySelectorAll('.kzc-toast[data-kzc-toast-key]')
			: Array.prototype.slice.call(container.childNodes || []);
		for (const prev of existing) {
			const prevKey = typeof prev.getAttribute === 'function'
				? prev.getAttribute('data-kzc-toast-key') : prev._ptlToastKey;
			if (prevKey === dedupKey || prev._ptlToastKey === dedupKey) {
				if (prev._ptlDismissTimer) window.clearTimeout(prev._ptlDismissTimer);
				prev._ptlDismissTimer = window.setTimeout(() => {
					if (prev.parentNode) prev.parentNode.removeChild(prev);
				}, k === 'error' ? 7000 : 4000);
				return;
			}
		}
		const toastEl = document.createElement('div');
		toastEl.className = 'kzc-toast kzc-toast--' + k;
		toastEl.setAttribute('role', k === 'error' ? 'alert' : 'status');
		toastEl.setAttribute('data-kzc-toast-key', dedupKey);
		toastEl._ptlToastKey = dedupKey;
		const msgText = document.createElement('span');
		msgText.className = 'kzc-toast__text';
		msgText.textContent = String(text);
		toastEl.appendChild(msgText);
		if (k === 'error') {
			// §13 family contract: error toasts offer a "Report this
			// problem" mailto when the shared feedback pack is loaded —
			// canonical .kzc-toast__feedback styling, flex gap separates it
			// from the message (same anatomy as js/common/toasts.js).
			try {
				const feedback = window.SbdAppFeedback;
				if (feedback && typeof feedback.buildMailto === 'function') {
					const link = document.createElement('a');
					link.className = 'kzc-toast__feedback';
					link.href = feedback.buildMailto('problem', {});
					link.textContent = t('Report this problem');
					toastEl.appendChild(link);
				}
			} catch (e) { /* never break the toast */ }
		}
		const close = document.createElement('button');
		close.type = 'button';
		close.className = 'kzc-toast__close';
		close.setAttribute('aria-label', t('Dismiss'));
		close.textContent = '✕';
		close.addEventListener('click', () => toastEl.remove());
		toastEl.appendChild(close);
		container.appendChild(toastEl);
		toastEl._ptlDismissTimer = window.setTimeout(() => {
			if (toastEl.parentNode) toastEl.parentNode.removeChild(toastEl);
		}, k === 'error' ? 7000 : 4000);
	}
	function live(text) {
		const el = document.getElementById('kzc-transfer-result-live');
		if (el) el.textContent = text;
	}
	function announce(text, kind) {
		try {
			toast(text, kind);
			return;
		} catch (e) { /* fall through to the platform fallback */ }
		if (window.OC && OC.Notification && OC.Notification.showTemporary) {
			OC.Notification.showTemporary(text);
		}
	}
	const msg = window.KanzleiCheckMessaging || {};
	if (typeof msg.announce !== 'function') {
		msg.announce = announce;
	}
	if (typeof msg.alert !== 'function') {
		msg.alert = function (text) { msg.announce(text, 'error'); live(text); };
	}
	if (typeof msg.polite !== 'function') {
		msg.polite = function (text) { msg.announce(text, 'success'); live(text); };
	}
	window.KanzleiCheckMessaging = msg;
})();
