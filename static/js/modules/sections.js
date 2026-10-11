/**
 * Visitor Insights — section analytics module.
 *
 * For every top-level builder section (`section[data-fw-item-id]`): whether
 * this page view reached it, and how long it was in view. "In view" means at
 * least half the section is visible, or it fills at least half the screen
 * (a tall section never shows half of itself at once), and the tab is in
 * front. Time is sent as deltas with each batch.
 */
(function (w, d) {
	'use strict';

	var VI = w.UnysonPlusVI;

	if (!VI || !('IntersectionObserver' in w)) {
		return;
	}

	VI.module(function (api) {
		var state = {};
		var list = [].slice.call(d.querySelectorAll('section[data-fw-item-id]')).filter(function (s) {
			return api.itemOk(s.getAttribute('data-fw-item-id')) && !(s.parentElement && s.parentElement.closest('section[data-fw-item-id]'));
		});

		if (!list.length) {
			return;
		}

		function account(s, t) {
			if (s.on && s.since) {
				s.ms += t - s.since;
				s.since = api.visible() ? t : 0;
			}
		}

		var thresholds = [];
		for (var i = 0; i <= 20; i++) {
			thresholds.push(i / 20);
		}

		var io = new IntersectionObserver(function (entries) {
			var t = Date.now();
			var vh = w.innerHeight || 1;

			entries.forEach(function (en) {
				var s = state[en.target.getAttribute('data-fw-item-id')];

				if (!s || !api.on()) {
					return;
				}

				if (en.isIntersecting && !s.reached) {
					s.reached = true;
					api.push(['r', s.id]);
				}

				var on = en.isIntersecting && (en.intersectionRatio >= 0.5 || en.intersectionRect.height / vh >= 0.5);

				if (on && !s.on) {
					s.on = true;
					s.since = api.visible() ? t : 0;
				} else if (!on && s.on) {
					account(s, t);
					s.on = false;
					s.since = 0;
				}
			});
		}, { threshold: thresholds });

		list.forEach(function (el) {
			var id = el.getAttribute('data-fw-item-id');

			if (!state[id]) {
				state[id] = { id: id, reached: false, on: false, since: 0, ms: 0 };
				io.observe(el);
			}
		});

		d.addEventListener('visibilitychange', function () {
			var t = Date.now();

			for (var id in state) {
				if (api.visible()) {
					if (state[id].on) {
						state[id].since = t;
					}
				} else {
					account(state[id], t);
					state[id].since = 0;
				}
			}
		});

		api.onFlush(function () {
			var t = Date.now();

			for (var id in state) {
				var s = state[id];

				account(s, t);

				if (s.ms >= 100) {
					api.push(['a', s.id, Math.min(600000, Math.round(s.ms))]);
				}

				s.ms = 0;
			}
		});
	});
})(window, document);
