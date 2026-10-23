/**
 * Visitor Insights — form analytics module.
 *
 * Per form on the page: started (first field focused), each field's first
 * focus and its position, sent (the submit event — AJAX forms included), and,
 * when the visitor leaves the page with a started form unsent, the last field
 * they were in. Values are never read. Search forms are skipped.
 */
(function (w, d) {
	'use strict';

	var VI = w.UnysonPlusVI;
	var M = (w.UPVI && w.UPVI.mods) || {};

	if (!VI || !M.forms) {
		return;
	}

	function clean(s, max) {
		return String(s || '').replace(/[^A-Za-z0-9_.:\[\]-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, max);
	}

	function isSearch(f) {
		return f.getAttribute('role') === 'search' || !!f.querySelector('input[type="search"],input[name="s"]');
	}

	function keyOf(f, i) {
		var item = f.closest('[data-fw-item-id]');
		var id = item && item.getAttribute('data-fw-item-id');

		if (id && /^[A-Za-z0-9_-]{6,32}$/.test(id)) {
			return id;
		}

		return clean(f.id ? 'id-' + f.id : (f.getAttribute('name') ? 'name-' + f.getAttribute('name') : 'form-' + (i + 1)), 40) || 'form-' + (i + 1);
	}

	function labelOf(f) {
		var aria = f.getAttribute('aria-label');
		if (aria) {
			return aria;
		}
		var legend = f.querySelector('legend,h2,h3,h4');
		if (legend && legend.textContent.trim()) {
			return legend.textContent.replace(/\s+/g, ' ').trim();
		}
		// The nearest heading with text that comes before the form on the page.
		var best = '';
		[].forEach.call(d.querySelectorAll('h1,h2,h3,h4'), function (h) {
			var t = (h.textContent || '').replace(/\s+/g, ' ').trim();
			if (t && (h.compareDocumentPosition(f) & 4)) {
				best = t;
			}
		});
		return best;
	}

	function fieldsOf(f) {
		return [].filter.call(f.elements, function (el) {
			var t = (el.type || '').toLowerCase();
			return /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName) && !/^(hidden|submit|button|image|reset)$/.test(t);
		});
	}

	function fieldKey(el, i) {
		return clean(el.getAttribute('name') || el.id || ((el.type || el.tagName).toLowerCase() + '-' + (i + 1)), 40) || 'field-' + (i + 1);
	}

	VI.module(function (api) {
		var forms = [].filter.call(d.forms, function (f) {
			return !isSearch(f) && !f.closest('#wpadminbar');
		});

		var state = forms.map(function (f, i) {
			return { key: keyOf(f, i), started: false, sent: false, done: false, last: '', seen: {} };
		});

		forms.forEach(function (f, i) {
			var s = state[i];

			f.addEventListener('focusin', function (e) {
				if (!api.on()) {
					return;
				}
				var list = fieldsOf(f);
				var pos = list.indexOf(e.target);
				if (pos < 0) {
					return;
				}
				var name = fieldKey(e.target, pos);

				if (!s.started) {
					s.started = true;
					api.push(['fs', s.key, labelOf(f).slice(0, 100)]);
				}
				if (!s.seen[name]) {
					s.seen[name] = true;
					api.push(['ff', s.key, name, pos + 1]);
				}
				s.last = name;
			});

			f.addEventListener('submit', function () {
				if (api.on() && !s.sent) {
					s.sent = true;
					api.push(['fc', s.key]);
					api.flush();
				}
			}, true);
		});

		api.onFlush(function () {
			if (!api.leaving()) {
				return;
			}
			state.forEach(function (s) {
				if (s.started && !s.sent && !s.done && s.last) {
					s.done = true;
					api.push(['fa', s.key, s.last]);
				}
			});
		});
	});
})(window, document);
