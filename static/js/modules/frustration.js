/**
 * Visitor Insights — frustration & errors module.
 *
 *  - Rage click: 3 clicks, each under 1 s after the previous and within 30 px
 *    of it (counted once per burst).
 *  - Dead click: a click on something that LOOKS clickable (a pointer cursor,
 *    a button, role=button) after which nothing changes within 2.5 s — no DOM
 *    mutation, no scroll or selection change within 100 ms, no navigation.
 *    Links, form fields and modifier-key clicks are never judged dead.
 *  - Error click: a script error within 1 s of a click.
 *  - Script errors: message, file and line, masked (emails, long numbers,
 *    query strings) before they leave the browser; at most 5 per page view.
 *
 * Error listeners attach as soon as this file runs, so errors raised while the
 * page loads are caught; they stay in memory and are only sent if tracking
 * starts (i.e. consent allows it).
 */
(function (w, d) {
	'use strict';

	var VI = w.UnysonPlusVI;
	var M = (w.UPVI && w.UPVI.mods) || {};

	if (!VI || !(M.frustration || M.errors)) {
		return;
	}

	var api = null;
	var early = [];
	var lastClick = null;
	var sentErrors = 0;

	function itemOf(node) {
		var t = node && node.nodeType === 1 ? node : (node && node.parentElement);
		var el = t && t.closest ? t.closest('[data-fw-item-id]') : null;
		var id = el && el.getAttribute('data-fw-item-id');
		return id && /^[A-Za-z0-9_-]{6,32}$/.test(id) ? id : '';
	}

	function mask(s, max) {
		return String(s || '')
			.replace(/[^\s@]+@[^\s@]+\.[a-z]{2,}/gi, '[email]')
			.replace(/\?[^\s]*/g, '')
			.replace(/\d[\d\s-]{5,}\d/g, '#')
			.replace(/\s+/g, ' ')
			.trim()
			.slice(0, max);
	}

	function source(file) {
		if (!file) {
			return '';
		}
		try {
			var u = new URL(file, w.location.href);
			return (u.origin === w.location.origin ? '' : u.host) + u.pathname;
		} catch (e) {
			return '';
		}
	}

	function emit(ev) {
		if (api && api.on()) {
			api.push(ev);
		} else if (!api && early.length < 10) {
			early.push(ev);
		}
	}

	function onError(message, file, line) {
		if (lastClick && Date.now() - lastClick.t < 1000 && !lastClick.erred && M.frustration) {
			lastClick.erred = true;
			emit(['f', 'e', lastClick.item]);
		}

		if (M.errors && sentErrors < 5 && message) {
			sentErrors++;
			emit(['x', mask(message, 150), mask(source(file), 150), parseInt(line, 10) || 0]);
		}
	}

	if (M.errors || M.frustration) {
		w.addEventListener('error', function (e) {
			// Resource load failures (an <img> 404) arrive here too, without a message.
			if (e && e.message) {
				onError(e.message, e.filename, e.lineno);
			}
		});

		w.addEventListener('unhandledrejection', function (e) {
			var r = e && e.reason;
			onError('Unhandled promise rejection: ' + (r && r.message ? r.message : String(r)), r && r.fileName, r && r.lineNumber);
		});
	}

	/* ---------------------------------------------------------- dead clicks */

	var watch = null;

	function looksClickable(el) {
		if (!el || el.nodeType !== 1) {
			return false;
		}
		if (el.closest('a[href],input,select,textarea,label,[contenteditable],video,audio,iframe')) {
			return false;
		}
		if (el.closest('button,[role="button"],[onclick]')) {
			return true;
		}
		try {
			return w.getComputedStyle(el).cursor === 'pointer';
		} catch (e) {
			return false;
		}
	}

	function endWatch(alive) {
		if (!watch) {
			return;
		}
		w.clearTimeout(watch.timer);
		watch.mo.disconnect();
		if (!alive) {
			emit(['f', 'd', watch.item]);
		}
		watch = null;
	}

	function startWatch(item) {
		endWatch(true);

		if (!('MutationObserver' in w)) {
			return;
		}

		var t0 = Date.now();
		var origin = lastClick && lastClick.el;
		// What counts as the page responding: nodes added or removed, text
		// changing, or a state attribute (open, aria-expanded …) anywhere; a
		// class / style change only on the clicked element or its relatives.
		// Scroll-reveal and ambient animations rewrite classes and styles all
		// the time and would otherwise make every click look answered.
		var STATE = /^(open|hidden|checked|disabled|selected|aria-(expanded|hidden|selected|pressed|checked|current))$/;
		var mo = new MutationObserver(function (list) {
			for (var i = 0; i < list.length; i++) {
				var m = list[i];
				if (m.type !== 'attributes' || STATE.test(m.attributeName)
					|| (origin && m.target.nodeType === 1 && (origin.contains(m.target) || m.target.contains(origin)))) {
					endWatch(true);
					return;
				}
			}
		});

		mo.observe(d.documentElement, { subtree: true, childList: true, attributes: true, characterData: true });
		watch = {
			item: item,
			t0: t0,
			mo: mo,
			timer: w.setTimeout(function () {
				endWatch(false);
			}, 2500)
		};
	}

	function quickAlive() {
		if (watch && Date.now() - watch.t0 < 100) {
			endWatch(true);
		}
	}

	VI.module(function (a) {
		api = a;

		for (var i = 0; i < early.length; i++) {
			api.push(early[i]);
		}
		early = [];

		if (!M.frustration) {
			return;
		}

		var burst = [];

		d.addEventListener('click', function (e) {
			if (!e.isTrusted || !api.on()) {
				return;
			}

			var now = Date.now();
			var item = itemOf(e.target);
			var prev = burst[burst.length - 1];

			lastClick = { t: now, item: item, erred: false, el: e.target && e.target.nodeType === 1 ? e.target : null };

			if (prev && now - prev.t < 1000 && Math.abs(e.clientX - prev.x) + Math.abs(e.clientY - prev.y) < 30) {
				burst.push({ t: now, x: e.clientX, y: e.clientY });
			} else {
				burst = [{ t: now, x: e.clientX, y: e.clientY }];
			}

			if (burst.length === 3) {
				api.push(['f', 'r', item]);
			}

			if (!e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey && burst.length === 1) {
				var t = e.target && e.target.nodeType === 1 ? e.target : (e.target && e.target.parentElement);
				if (looksClickable(t)) {
					startWatch(item);
				}
			}
		}, { capture: true, passive: true });

		w.addEventListener('scroll', quickAlive, { passive: true });
		// Selecting text is a response; the caret moving on a plain click is not.
		d.addEventListener('selectionchange', function () {
			var sel = w.getSelection && w.getSelection();
			if (sel && !sel.isCollapsed) {
				quickAlive();
			}
		});
		w.addEventListener('pagehide', function () {
			endWatch(true);
		});
		d.addEventListener('visibilitychange', function () {
			if (d.visibilityState === 'hidden') {
				endWatch(true);
			}
		});
	});
})(window, document);
