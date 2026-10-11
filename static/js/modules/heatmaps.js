/**
 * Visitor Insights — heatmaps module: clicks and scroll reach.
 *
 * A click is stored against the innermost builder item it landed in
 * (`data-fw-item-id`), as the 10% cell of that item's box — so the heatmap
 * re-projects it onto the item at whatever size it has when viewed. A click
 * outside every item falls back to the page grid: % of page width, 20px rows.
 *
 * Scroll reach is sent as each 5% band of the page is first reached, so every
 * band counts a page view at most once.
 */
(function (w, d) {
	'use strict';

	var VI = w.UnysonPlusVI;

	if (!VI) {
		return;
	}

	function cell(v) {
		return Math.max(0, Math.min(9, Math.floor(v * 10)));
	}

	VI.module(function (api) {
		d.addEventListener('click', function (e) {
			if (!e.isTrusted || !api.on()) {
				return;
			}

			var t = e.target && e.target.nodeType === 1 ? e.target : (e.target && e.target.parentElement);
			var el = t && t.closest ? t.closest('[data-fw-item-id]') : null;

			if (el && api.itemOk(el.getAttribute('data-fw-item-id'))) {
				var r = el.getBoundingClientRect();

				if (r.width > 0 && r.height > 0) {
					api.push(['c', el.getAttribute('data-fw-item-id'), cell((e.clientX - r.left) / r.width), cell((e.clientY - r.top) / r.height)]);
					return;
				}
			}

			var dw = Math.max(d.documentElement.scrollWidth, 1);

			api.push(['c', '', Math.max(0, Math.min(100, Math.round(e.pageX / dw * 100))), Math.max(0, Math.min(5000, Math.floor(e.pageY / 20)))]);
		}, { capture: true, passive: true });

		var max = -5;
		var pending = false;

		function measure() {
			pending = false;

			if (!api.on()) {
				return;
			}

			var h = api.docHeight();

			if (!h) {
				return;
			}

			var band = Math.floor(Math.min(100, (w.pageYOffset + w.innerHeight) / h * 100) / 5) * 5;

			while (max < band) {
				max += 5;
				api.push(['s', max]);
			}
		}

		w.addEventListener('scroll', function () {
			if (!pending) {
				pending = true;
				w.setTimeout(measure, 250);
			}
		}, { passive: true });

		measure();
	});
})(window, document);
