/**
 * Visitor Insights — heat overlay inside the Live Editor canvas.
 *
 * The canvas already stamps every builder item with `data-fw-item-id`, which
 * is exactly what the heatmap viewer anchors to. This adds a small "Heatmap"
 * button to the canvas; pressing it loads the viewer (and its renderer) on
 * demand in embedded mode, so the overlay costs nothing until it is asked for
 * and closing it removes it without reloading the canvas. The overlay never
 * takes pointer events, so selecting and editing items keeps working under it.
 */
(function (w, d) {
	'use strict';

	var C = w.UPVILE;

	if (!C || !d.body) {
		return;
	}

	var btn = d.createElement('button');
	var loaded = false;

	btn.type = 'button';
	btn.className = 'upvi-le-toggle';
	btn.textContent = C.label;
	btn.title = C.title;
	btn.style.cssText = 'position:fixed;left:16px;bottom:16px;z-index:2147483647;display:flex;gap:6px;align-items:center;padding:8px 12px;border:0;border-radius:999px;background:#0f172a;color:#fff;font:600 12px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.3);cursor:pointer';

	function script(src) {
		return new Promise(function (resolve, reject) {
			var s = d.createElement('script');
			s.src = src;
			s.onload = resolve;
			s.onerror = reject;
			d.head.appendChild(s);
		});
	}

	function open() {
		btn.style.display = 'none';

		w.UPVIV = {
			rest: C.rest,
			nonce: C.nonce,
			path: C.path,
			framed: false,
			embedded: true,
			l10n: C.l10n,
			onClose: function () {
				btn.style.display = 'flex';
			}
		};

		if (loaded) {
			// The viewer boots once per load; run it again on a fresh copy.
			script(C.viewer + (C.viewer.indexOf('?') < 0 ? '?' : '&') + 'r=' + Date.now());
			return;
		}

		loaded = true;
		script(C.simpleheat).then(function () {
			return script(C.viewer);
		}).catch(function () {
			btn.style.display = 'flex';
		});
	}

	btn.addEventListener('click', open);
	d.body.appendChild(btn);
})(window, document);
