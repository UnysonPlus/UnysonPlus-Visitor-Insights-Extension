/**
 * Visitor Insights — tracker core.
 *
 * Starts in idle time after `load`, and only when allowed():
 *   opted out → never; Global Privacy Control (when honoured) → never;
 *   a WP Consent API plugin → its "statistics-anonymous" answer, live;
 *   no consent plugin → per the site setting ("auto" tracks, "require" waits
 *   for UnysonPlusVI.grant()).
 *
 * Stores nothing on the device (the opt-out flag excepted). Events are pushed
 * by modules, batched, and sent every 10 s and when the page is hidden, with
 * sendBeacon (fetch keepalive as the fallback). Handlers only push numbers;
 * nothing here forces a layout on the hot path.
 */
(function (w, d) {
	'use strict';

	var C = w.UPVI;

	if (!C || !w.JSON || !d.addEventListener || (w.navigator && w.navigator.webdriver)) {
		return;
	}

	// wp_localize_script() hands top-level numbers over as strings.
	C.p = parseInt(C.p, 10) || 0;
	C.sample = parseInt(C.sample, 10) || 100;

	var K = C.consent || {};
	var MAX_PART = 120;
	var MAX_ENGAGED = 1800000;

	var started = false;
	var granted = false;
	var sampled = !C.sample || C.sample >= 100 || Math.random() * 100 < C.sample;
	var seq = 0;
	var buf = [];
	var mods = [];
	var flushers = [];
	var vid = rid();
	var dev = device();
	var timer = null;
	var engaged = 0;
	var engagedTotal = 0;
	var visSince = 0;
	var firstPv = null;

	function rid() {
		var a = 'abcdefghijklmnopqrstuvwxyz0123456789';
		var s = '';
		var r = null;

		try {
			r = new Uint8Array(12);
			w.crypto.getRandomValues(r);
		} catch (e) {
			r = null;
		}

		for (var i = 0; i < 12; i++) {
			s += a[(r ? r[i] : Math.floor(Math.random() * 256)) % 36];
		}

		return s;
	}

	function device() {
		var v = w.innerWidth || d.documentElement.clientWidth;

		return v < 768 ? 'm' : (v < 1025 ? 't' : 'd');
	}

	function optedOut() {
		try {
			return w.localStorage.getItem(K.optOutKey || 'upvi_optout') === '1';
		} catch (e) {
			return false;
		}
	}

	function allowed() {
		if (optedOut()) {
			return false;
		}

		if (K.gpc && w.navigator.globalPrivacyControl) {
			return false;
		}

		// A consent plugin that speaks the WP Consent API decides.
		if (typeof w.wp_has_consent === 'function' && w.wp_consent_type) {
			return !!w.wp_has_consent(K.category || 'statistics-anonymous');
		}

		return granted || !K.requireConsent;
	}

	function now() {
		return Date.now();
	}

	function isVisible() {
		return d.visibilityState !== 'hidden';
	}

	function tickEngaged() {
		if (visSince) {
			var t = now();
			engaged += t - visSince;
			visSince = isVisible() ? t : 0;
		}
	}

	function onVisibility() {
		if (isVisible()) {
			visSince = visSince || now();
		} else {
			tickEngaged();
			visSince = 0;
			flush();
		}
	}

	function send(body) {
		var ok = false;

		try {
			if (w.navigator.sendBeacon) {
				ok = w.navigator.sendBeacon(C.ep, new Blob([body], { type: 'text/plain' }));
			}
		} catch (e) {
			ok = false;
		}

		if (!ok && w.fetch) {
			try {
				w.fetch(C.ep, {
					method: 'POST',
					body: body,
					keepalive: true,
					credentials: 'omit',
					headers: { 'Content-Type': 'text/plain' }
				});
			} catch (e) {}
		}
	}

	function flush() {
		if (!started) {
			return;
		}

		tickEngaged();

		for (var i = 0; i < flushers.length; i++) {
			try {
				flushers[i]();
			} catch (e) {}
		}

		var ms = Math.min(engaged, MAX_ENGAGED - engagedTotal);
		if (ms >= 1) {
			buf.push(['t', Math.round(ms)]);
			engagedTotal += ms;
		}
		engaged = 0;

		if (seq > 0 && !buf.length) {
			return;
		}

		var events = buf;
		buf = [];

		do {
			var body = { v: 1, p: C.p, u: C.u, k: C.k, i: vid, q: seq, d: dev, e: events.splice(0, MAX_PART) };

			if (seq === 0) {
				body.pv = firstPv || {};
			}

			seq++;
			send(JSON.stringify(body));
		} while (events.length);
	}

	var api = {
		push: function (ev) {
			if (started) {
				buf.push(ev);
			}
		},
		on: function () {
			return started;
		},
		onFlush: function (fn) {
			flushers.push(fn);
		},
		/** Send what is buffered now (a goal click that is about to leave the page). */
		flush: function () {
			flush();
		},
		docHeight: function () {
			var b = d.body;
			var e = d.documentElement;

			return Math.max(b ? b.scrollHeight : 0, e.scrollHeight, e.offsetHeight);
		},
		visible: isVisible,
		itemOk: function (id) {
			return typeof id === 'string' && /^[A-Za-z0-9_-]{6,32}$/.test(id);
		}
	};

	function pageview() {
		var pv = {};

		try {
			if (d.referrer) {
				var h = new URL(d.referrer).hostname;
				if (h && h !== w.location.hostname) {
					pv.r = h;
				}
			}
		} catch (e) {}

		try {
			var q = new URLSearchParams(w.location.search);
			var map = { utm_source: 'us', utm_medium: 'um', utm_campaign: 'uc' };

			for (var k in map) {
				if (q.get(k)) {
					pv[map[k]] = q.get(k).slice(0, 120);
				}
			}
		} catch (e) {}

		return pv;
	}

	function start() {
		if (started || !sampled || !allowed()) {
			return;
		}

		started = true;
		firstPv = firstPv || pageview();
		visSince = isVisible() ? now() : 0;

		d.addEventListener('visibilitychange', onVisibility);
		w.addEventListener('pagehide', flush);

		var queued = mods;
		mods = [];
		for (var i = 0; i < queued.length; i++) {
			run(queued[i]);
		}

		flush(); // the page view goes out now, not in ten seconds
		// A second batch soon after, so what the first screen showed (sections
		// reached, scroll bands) arrives even from a visitor who leaves quickly
		// and whose browser drops the page-hide beacon.
		w.setTimeout(flush, 3000);
		timer = w.setInterval(flush, 10000);
	}

	function stop() {
		if (!started) {
			return;
		}

		started = false;
		buf = [];
		w.clearInterval(timer);
		d.removeEventListener('visibilitychange', onVisibility);
		w.removeEventListener('pagehide', flush);
	}

	function run(fn) {
		try {
			fn(api);
		} catch (e) {}
	}

	function boot() {
		var idle = w.requestIdleCallback || function (f) {
			return w.setTimeout(f, 1);
		};

		idle(start, { timeout: 2000 });
	}

	w.UnysonPlusVI = {
		/** Register a module; it runs once tracking has started. */
		module: function (fn) {
			if (started) {
				run(fn);
			} else {
				mods.push(fn);
			}
		},
		/** For sites with their own banner and "Wait for consent everywhere". */
		grant: function () {
			granted = true;
			start();
		},
		/** Count a goal from the site's own script: UnysonPlusVI.goal( 'signup', 0 ). */
		goal: function (key, value) {
			if (started && typeof key === 'string' && /^[a-z0-9_-]{1,40}$/.test(key)) {
				buf.push(['g', key, Math.max(0, Math.round(+value || 0))]);
				flush();
			}
		},
		revoke: function () {
			granted = false;
			stop();
		},
		optOut: function () {
			try {
				w.localStorage.setItem(K.optOutKey || 'upvi_optout', '1');
			} catch (e) {}
			stop();
		},
		optIn: function () {
			try {
				w.localStorage.removeItem(K.optOutKey || 'upvi_optout');
			} catch (e) {}
			start();
		}
	};

	function recheck() {
		if (allowed()) {
			start();
		} else {
			stop();
		}
	}

	d.addEventListener('wp_listen_for_consent_change', recheck);
	d.addEventListener('wp_consent_type_defined', recheck);

	if (d.readyState === 'complete') {
		boot();
	} else {
		w.addEventListener('load', boot);
	}
})(window, document);
