/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * exposes the window.PortalCheckApi / PortalCheckMessaging contract the
 * synced js/transfer.js expects, in PortalCheck's own API envelope
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
	window.PortalCheckApi = {
		url(path) {
			return OC.generateUrl('/apps/portalcheck' + path);
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
	// onto the app-owned toast announcer (visible .ptl-toast + live regions)
	// and always mirror into the ptl-transfer-result-live status region the
	// synced part renders. OC.Notification is gone from NC≥31 bundles — it
	// may only ever be a fallback behind the app-owned channel, never the
	// only write.
	const t = (s) => (window.OC && OC.L10N && OC.L10N.translate)
		? OC.L10N.translate('portalcheck', s) : s;
	let toastContainer = null;
	function ensureToastContainer() {
		if (toastContainer && document.body.contains(toastContainer)) {
			return toastContainer;
		}
		toastContainer = document.createElement('div');
		toastContainer.className = 'ptl-toasts';
		toastContainer.id = 'ptl-toasts';
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
			? container.querySelectorAll('.ptl-toast[data-ptl-toast-key]')
			: Array.prototype.slice.call(container.childNodes || []);
		for (const prev of existing) {
			const prevKey = typeof prev.getAttribute === 'function'
				? prev.getAttribute('data-ptl-toast-key') : prev._ptlToastKey;
			if (prevKey === dedupKey || prev._ptlToastKey === dedupKey) {
				if (prev._ptlDismissTimer) window.clearTimeout(prev._ptlDismissTimer);
				prev._ptlDismissTimer = window.setTimeout(() => {
					if (prev.parentNode) prev.parentNode.removeChild(prev);
				}, k === 'error' ? 7000 : 4000);
				return;
			}
		}
		const toastEl = document.createElement('div');
		toastEl.className = 'ptl-toast ptl-toast--' + k;
		toastEl.setAttribute('role', k === 'error' ? 'alert' : 'status');
		toastEl.setAttribute('data-ptl-toast-key', dedupKey);
		toastEl._ptlToastKey = dedupKey;
		const content = document.createElement('span');
		content.className = 'ptl-toast__content';
		const msgText = document.createElement('span');
		msgText.className = 'ptl-toast__text';
		msgText.textContent = String(text);
		content.appendChild(msgText);
		if (k === 'error') {
			// §13 family contract: error toasts offer a "Report this
			// problem" mailto when the shared feedback pack is loaded.
			try {
				const feedback = window.SbdAppFeedback;
				if (feedback && typeof feedback.buildMailto === 'function') {
					const link = document.createElement('a');
					link.className = 'ptl-toast__link';
					link.href = feedback.buildMailto('problem', {});
					link.textContent = t('Report this problem');
					content.appendChild(link);
				}
			} catch (e) { /* never break the toast */ }
		}
		const close = document.createElement('button');
		close.type = 'button';
		close.className = 'ptl-toast__close';
		close.setAttribute('aria-label', t('Dismiss'));
		close.textContent = '✕';
		close.addEventListener('click', () => toastEl.remove());
		toastEl.appendChild(content);
		toastEl.appendChild(close);
		container.appendChild(toastEl);
		toastEl._ptlDismissTimer = window.setTimeout(() => {
			if (toastEl.parentNode) toastEl.parentNode.removeChild(toastEl);
		}, k === 'error' ? 7000 : 4000);
	}
	function live(text) {
		const el = document.getElementById('ptl-transfer-result-live');
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
	const msg = window.PortalCheckMessaging || {};
	if (typeof msg.announce !== 'function') {
		msg.announce = announce;
	}
	if (typeof msg.alert !== 'function') {
		msg.alert = function (text) { msg.announce(text, 'error'); live(text); };
	}
	if (typeof msg.polite !== 'function') {
		msg.polite = function (text) { msg.announce(text, 'success'); live(text); };
	}
	window.PortalCheckMessaging = msg;
})();
