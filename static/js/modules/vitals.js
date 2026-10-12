/**
 * Visitor Insights — real-visitor performance (Core Web Vitals).
 *
 * Measured with the browser's own PerformanceObserver entries, the way the
 * public Web Vitals definitions describe them:
 *  - LCP: the last largest-contentful-paint entry (not reported for a page
 *    that loaded in a background tab);
 *  - CLS: the largest session window of layout shifts not caused by input
 *    (gaps < 1 s, windows ≤ 5 s);
 *  - INP: the slowest interaction, or for pages with 50+ interactions the
 *    one at the 98th percentile;
 *  - TTFB: navigation responseStart (minus activationStart when prerendered).
 *
 * Observers attach as soon as this file runs (with `buffered`, so nothing
 * earlier is missed). Values are sent once, when the page is first hidden —
 * that is when they are final — and only if tracking has started.
 */
(function (w, d) {
	'use strict';

	var VI = w.UnysonPlusVI;
	var M = (w.UPVI && w.UPVI.mods) || {};

	if (!VI || !M.vitals || !('PerformanceObserver' in w)) {
		return;
	}

	var hiddenAtLoad = d.visibilityState === 'hidden';
	var lcp = 0;
	var cls = 0;
	var sess = 0;
	var sessStart = 0;
	var sessLast = 0;
	var inter = {};
	var sent = false;

	function observe(type, fn, extra) {
		try {
			var o = new PerformanceObserver(function (list) {
				list.getEntries().forEach(fn);
			});
			var opts = { type: type, buffered: true };
			for (var k in extra || {}) {
				opts[k] = extra[k];
			}
			o.observe(opts);
		} catch (e) {}
	}

	observe('largest-contentful-paint', function (en) {
		lcp = en.renderTime || en.startTime || lcp;
	});

	observe('layout-shift', function (en) {
		if (en.hadRecentInput) {
			return;
		}
		if (sess && en.startTime - sessLast < 1000 && en.startTime - sessStart < 5000) {
			sess += en.value;
		} else {
			sess = en.value;
			sessStart = en.startTime;
		}
		sessLast = en.startTime;
		cls = Math.max(cls, sess);
	});

	function interaction(en) {
		if (en.interactionId) {
			inter[en.interactionId] = Math.max(inter[en.interactionId] || 0, en.duration);
		}
	}

	observe('event', interaction, { durationThreshold: 40 });
	observe('first-input', interaction);

	function ttfb() {
		try {
			var nav = w.performance.getEntriesByType('navigation')[0];
			return nav ? Math.max(0, nav.responseStart - (nav.activationStart || 0)) : null;
		} catch (e) {
			return null;
		}
	}

	VI.module(function (api) {
		api.onFlush(function () {
			if (sent || d.visibilityState !== 'hidden') {
				return;
			}
			sent = true;

			if (lcp > 0 && !hiddenAtLoad) {
				api.push(['v', 'lcp', Math.round(lcp)]);
			}

			api.push(['v', 'cls', Math.round(cls * 1000)]);

			var all = Object.keys(inter).map(function (k) {
				return inter[k];
			}).sort(function (a, b) {
				return b - a;
			});

			if (all.length) {
				api.push(['v', 'inp', Math.round(all[Math.min(all.length - 1, Math.floor(all.length / 50))])]);
			}

			var t = ttfb();
			if (t !== null) {
				api.push(['v', 'ttfb', Math.round(t)]);
			}
		});
	});
})(window, document);
