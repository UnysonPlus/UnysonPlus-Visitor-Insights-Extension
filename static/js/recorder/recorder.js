/**
 * Visitor Insights — session recorder (loader).
 *
 * Records a visit ONLY with prior opt-in consent: a WP Consent API plugin's
 * "statistics" answer, or UnysonPlusVI.grantRecording() from the site's own
 * banner. Never under Global Privacy Control or after an opt-out. Until then
 * nothing is loaded and nothing is buffered.
 *
 * Once allowed and sampled in, it loads the rrweb recorder (~24 KB gzipped)
 * with every form field masked in the browser (maskAllInputs), optional
 * masking of all text, and the up-vi-mask / up-vi-block classes honoured. The
 * session id lives in sessionStorage (this tab only) and continues across page
 * views until 30 idle minutes. Withdrawing consent — or the visitor pressing
 * "Delete my recording" — stops it at once and deletes the recording.
 */
(function (w, d) {
	'use strict';

	var VI = w.UnysonPlusVI;
	var R = w.UPVIR;
	var C = w.UPVI;

	if (!VI || !R || !C || !w.JSON || !w.fetch) {
		return;
	}

	var KEY = 'upvi_rec';
	var IDLE = 30 * 60 * 1000;
	var MAX_BATCH = 300000;

	function load() {
		try {
			return JSON.parse(w.sessionStorage.getItem(KEY) || 'null');
		} catch (e) {
			return null;
		}
	}

	function save(s) {
		try {
			w.sessionStorage.setItem(KEY, JSON.stringify(s));
		} catch (e) {}
	}

	function rid() {
		var a = 'abcdefghijklmnopqrstuvwxyz0123456789';
		var out = '';
		var r = new Uint8Array(24);
		w.crypto.getRandomValues(r);
		for (var i = 0; i < 24; i++) {
			out += a[r[i] % 36];
		}
		return out;
	}

	function device() {
		var v = w.innerWidth || d.documentElement.clientWidth;
		return v < 768 ? 'm' : (v < 1025 ? 't' : 'd');
	}

	var sess = load();

	if (sess && sess.optout) {
		return; // the visitor deleted their recording in this tab
	}

	if (!sess || Date.now() - (sess.t || 0) > IDLE) {
		sess = { sid: rid(), seq: 0, t: Date.now(), on: false, sampled: Math.random() * 100 < (parseInt(R.sample, 10) || 20) };
		save(sess);
	}

	var granted = false;
	var stopFn = null;
	var starting = false;
	var buf = [];
	var bytes = 0;
	var flags = '';
	var timer = null;

	function consent() {
		if (w.navigator.globalPrivacyControl) {
			return false;
		}
		try {
			if (w.localStorage.getItem((C.consent && C.consent.optOutKey) || 'upvi_optout') === '1') {
				return false;
			}
		} catch (e) {}
		if (R.consent === 'api') {
			return typeof w.wp_has_consent === 'function' && !!w.wp_consent_type && !!w.wp_has_consent('statistics');
		}
		return granted;
	}

	function payload() {
		var ev = buf;
		buf = [];
		bytes = 0;
		sess.seq = (sess.seq || 0) + 1;
		sess.t = Date.now();
		save(sess);
		return JSON.stringify({ v: 1, sid: sess.sid, seq: sess.seq, p: parseInt(C.p, 10) || 0, u: C.u, k: C.k, d: device(), f: flags, ev: ev });
	}

	function send(final) {
		if (!buf.length) {
			return;
		}

		var body = payload();

		// The last batch must leave with the page: uncompressed, keepalive.
		if (final || !w.CompressionStream) {
			w.fetch(R.ep, { method: 'POST', body: body, keepalive: body.length < 60000, credentials: 'omit', headers: { 'Content-Type': 'application/json' } }).catch(function () {});
			return;
		}

		var gz = new Blob([body]).stream().pipeThrough(new w.CompressionStream('gzip'));
		new Response(gz).blob().then(function (b) {
			return w.fetch(R.ep, { method: 'POST', body: b, credentials: 'omit', headers: { 'Content-Type': 'application/octet-stream', 'X-UPVI-Enc': 'gzip' } });
		}).catch(function () {});
	}

	function start() {
		if (stopFn || starting || !sess.sampled || !consent()) {
			return;
		}

		starting = true;

		var s = d.createElement('script');
		s.src = R.lib;
		s.async = true;
		s.onerror = function () {
			starting = false;
		};
		s.onload = function () {
			starting = false;

			var lib = w.rrwebRecord;
			var record = lib && (lib.record || (lib['default'] && lib['default'].record));

			if (!record || !consent()) {
				return;
			}

			sess.on = true;
			save(sess);

			stopFn = record({
				emit: function (ev) {
					buf.push(ev);
					bytes += ev.type === 2 ? 200000 : 400; // a full snapshot is big; others are small
					if (bytes > MAX_BATCH) {
						send(false);
					}
				},
				maskAllInputs: true,
				maskTextSelector: R.maskText ? '*' : null,
				maskTextClass: 'up-vi-mask',
				blockClass: 'up-vi-block',
				recordCanvas: false,
				collectFonts: false,
				inlineImages: false,
				sampling: { mousemove: 50, scroll: 150, input: 'last', media: 800 },
				slimDOMOptions: { script: true, comment: true, headFavicon: true, headWhitespace: true, headMetaSocial: true, headMetaRobots: true, headMetaHttpEquiv: true, headMetaAuthorship: true, headMetaVerification: true }
			});

			timer = w.setInterval(function () {
				send(false);
			}, 10000);
		};

		d.head.appendChild(s);
	}

	function stop(forget) {
		if (stopFn) {
			stopFn();
			stopFn = null;
		}
		w.clearInterval(timer);
		buf = [];
		sess.on = false;

		if (forget) {
			sess.optout = true;
			w.fetch(R.forget, { method: 'POST', body: JSON.stringify({ sid: sess.sid }), keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'application/json' } }).catch(function () {});
		}

		save(sess);
	}

	/** For sites with their own banner ("Consent comes from: my own banner"). */
	VI.grantRecording = function () {
		granted = true;
		start();
	};

	/** Stop recording; by default also delete what was recorded in this tab. */
	VI.stopRecording = function (forget) {
		granted = false;
		stop(forget !== false);
	};

	function recheck() {
		if (consent()) {
			start();
		} else if (stopFn || sess.on) {
			stop(true); // consent withdrawn: stop and delete
		}
	}

	d.addEventListener('wp_listen_for_consent_change', recheck);
	d.addEventListener('wp_consent_type_defined', recheck);

	w.addEventListener('pagehide', function () {
		send(true);
	});
	d.addEventListener('visibilitychange', function () {
		if (d.visibilityState === 'hidden') {
			send(true);
		}
	});

	// Mark the recording when the visit runs into a problem or reaches a goal,
	// so problem visits can be found (and, if set, the rest dropped).
	VI.module(function (api) {
		if (api.onPush) {
			api.onPush(function (ev) {
				var c = ev[0] === 'f' ? ev[1] : (ev[0] === 'x' ? 'x' : (ev[0] === 'g' ? 'g' : ''));
				if (c && flags.indexOf(c) < 0) {
					flags += c;
				}
			});
		}
	});

	function boot() {
		(w.requestIdleCallback || function (f) {
			return w.setTimeout(f, 1);
		})(start, { timeout: 2500 });
	}

	if (d.readyState === 'complete') {
		boot();
	} else {
		w.addEventListener('load', boot);
	}
})(window, document);
