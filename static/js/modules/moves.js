/**
 * Visitor Insights — mouse-movement maps (optional, off by default).
 *
 * Desktop only (a fine pointer; touch screens have no hover). The pointer
 * position is sampled every 150 ms when it moved at least 10 px, binned on the
 * spot — into the 10% cells of the builder item under it, or the page grid —
 * and only the per-cell counts are sent with each batch. No trail, no order,
 * no timing leaves the browser; at most 400 samples per page view.
 */
(function (w, d) {
	'use strict';

	var VI = w.UnysonPlusVI;
	var M = (w.UPVI && w.UPVI.mods) || {};

	if (!VI || !M.moves || !w.matchMedia || !w.matchMedia('(pointer: fine)').matches) {
		return;
	}

	VI.module(function (api) {
		var last = null;
		var px = -999;
		var py = -999;
		var samples = 0;
		var bins = {};

		d.addEventListener('pointermove', function (e) {
			last = e;
		}, { passive: true });

		function cell(v) {
			return Math.max(0, Math.min(9, Math.floor(v * 10)));
		}

		var timer = w.setInterval(function () {
			if (!last || !api.on() || !api.visible() || samples >= 400) {
				return;
			}

			var e = last;
			if (Math.abs(e.clientX - px) + Math.abs(e.clientY - py) < 10) {
				return;
			}
			px = e.clientX;
			py = e.clientY;
			samples++;

			var t = e.target && e.target.nodeType === 1 ? e.target : null;
			var el = t && t.closest ? t.closest('[data-fw-item-id]') : null;
			var id = el && el.getAttribute('data-fw-item-id');
			var key;

			if (id && api.itemOk(id)) {
				var r = el.getBoundingClientRect();
				if (!r.width || !r.height) {
					return;
				}
				key = id + '|' + cell((e.clientX - r.left) / r.width) + '|' + cell((e.clientY - r.top) / r.height);
			} else {
				var dw = Math.max(d.documentElement.scrollWidth, 1);
				key = '|' + Math.max(0, Math.min(100, Math.round((e.clientX + w.pageXOffset) / dw * 100))) + '|' + Math.max(0, Math.min(5000, Math.floor((e.clientY + w.pageYOffset) / 20)));
			}

			bins[key] = (bins[key] || 0) + 1;

			if (samples >= 400) {
				w.clearInterval(timer);
			}
		}, 150);

		api.onFlush(function () {
			var n = 0;
			for (var k in bins) {
				if (n++ >= 200) {
					break;
				}
				var p = k.split('|');
				api.push(['m', p[0], parseInt(p[1], 10), parseInt(p[2], 10), bins[k]]);
			}
			bins = {};
		});
	});
})(window, document);
