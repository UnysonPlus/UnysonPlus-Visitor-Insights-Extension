/**
 * Visitor Insights — goals module.
 *
 * Counts clicks on elements marked "Track as goal" in the builder (stamped
 * `data-fw-vi-goal="<key>"` on tracked pages) and sends the batch at once,
 * because a goal click often leaves the page. Page-visit goals need nothing
 * here (the server matches them), and scripts can call
 * `UnysonPlusVI.goal( 'key', value )` themselves.
 */
(function (w, d) {
	'use strict';

	var VI = w.UnysonPlusVI;

	if (!VI) {
		return;
	}

	VI.module(function (api) {
		d.addEventListener('click', function (e) {
			if (!e.isTrusted || !api.on()) {
				return;
			}

			var t = e.target && e.target.nodeType === 1 ? e.target : (e.target && e.target.parentElement);
			var el = t && t.closest ? t.closest('[data-fw-vi-goal]') : null;
			var key = el && el.getAttribute('data-fw-vi-goal');

			if (key && /^[a-z0-9_-]{1,40}$/.test(key)) {
				api.push(['g', key, 0]);
				api.flush();
			}
		}, { capture: true, passive: true });
	});
})(window, document);
