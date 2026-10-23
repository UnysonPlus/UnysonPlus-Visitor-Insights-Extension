/**
 * Visitor Insights — admin screen.
 *
 * Heatmaps tab: frames the page at the chosen device width, scales it to fit,
 * and drives heatmap-viewer.js inside the frame over postMessage. The frame
 * answers with the page-view count and its builder sections, which become the
 * section table under it.
 *
 * Live tab: polls the live endpoint every 15 seconds.
 */
(function (w, d) {
	'use strict';

	var A = w.UPVIA || {};
	var L = A.l10n || {};

	function fmt(s, v) {
		return String(s || '').replace('%s', v);
	}

	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function seconds(ms) {
		var s = Math.round(ms / 1000);
		return s >= 60 ? Math.floor(s / 60) + 'm ' + ('0' + (s % 60)).slice(-2) + 's' : s + 's';
	}

	/* ---------------------------------------------------------------- heatmaps */

	var hm = d.querySelector('.fw-vi-hm');

	if (hm) {
		var WIDTHS = { d: 1366, t: 820, m: 390 };
		var stage = hm.querySelector('.fw-vi-hm__stage');
		var frame = stage.querySelector('iframe');
		var info = hm.querySelector('.fw-vi-hm__info');
		var table = hm.querySelector('.fw-vi-hm__table');
		var st = { mode: hm.getAttribute('data-mode') || 'clicks', device: 'd', days: 30, ch: '' };

		[].forEach.call(hm.querySelectorAll('.fw-vi-seg[data-name="mode"] button'), function (b) {
			b.classList.toggle('is-active', b.getAttribute('data-value') === st.mode);
		});
		var ready = false;

		function layout() {
			var fw = WIDTHS[st.device];
			var avail = stage.clientWidth;
			var scale = Math.min(1, avail / fw);
			var shown = Math.max(480, Math.round(w.innerHeight * 0.72));

			frame.style.width = fw + 'px';
			frame.style.height = Math.round(shown / scale) + 'px';
			frame.style.transform = 'scale(' + scale + ')';
			stage.style.height = shown + 'px';
		}

		function show() {
			if (ready && frame.contentWindow) {
				frame.contentWindow.postMessage({ type: 'upvi:show', mode: st.mode, device: st.device, days: st.days, ch: st.ch }, w.location.origin);
			}
		}

		[].forEach.call(hm.querySelectorAll('.fw-vi-seg'), function (seg) {
			seg.addEventListener('click', function (e) {
				var b = e.target.closest('button');
				if (!b) {
					return;
				}
				[].forEach.call(seg.querySelectorAll('button'), function (x) {
					x.classList.toggle('is-active', x === b);
				});
				st[seg.getAttribute('data-name')] = b.getAttribute('data-value');
				layout();
				show();
			});
		});

		hm.querySelector('[data-name="days"]').addEventListener('change', function (e) {
			st.days = parseInt(e.target.value, 10);
			show();
		});

		hm.querySelector('[data-name="ch"]').addEventListener('change', function (e) {
			st.ch = e.target.value;
			show();
		});

		var exp = hm.querySelector('[data-export]');
		if (exp) {
			exp.addEventListener('click', function () {
				if (ready && frame.contentWindow) {
					exp.disabled = true;
					frame.contentWindow.postMessage({ type: 'upvi:export' }, w.location.origin);
					w.setTimeout(function () {
						exp.disabled = false;
					}, 8000);
				}
			});
		}

		w.addEventListener('message', function (e) {
			if (e.origin !== w.location.origin || !e.data || e.source !== frame.contentWindow) {
				return;
			}

			if (e.data.type === 'upvi:image' && e.data.blob) {
				var link = hm.querySelector('.fw-vi-hm__save') || d.createElement('a');
				if (link.href) {
					w.URL.revokeObjectURL(link.href);
				}
				link.className = 'button button-primary fw-vi-hm__save';
				link.href = w.URL.createObjectURL(e.data.blob);
				link.download = e.data.name;
				link.textContent = L.saveImage + ' (' + Math.round(e.data.blob.size / 1024) + ' KB)';
				if (!link.parentNode) {
					hm.querySelector('[data-export]').insertAdjacentElement('afterend', link);
				}
				if (exp) {
					exp.disabled = false;
				}
				link.click();
				return;
			}

			if (e.data.type === 'upvi:ready') {
				ready = true;
				show();
			}

			if (e.data.type === 'upvi:info') {
				var m = e.data;

				info.textContent = m.views
					? fmt(L.views, m.views.toLocaleString()) + (m.missing ? ' · ' + fmt(L.missing, m.missing.toLocaleString()) : '')
					: L.none;

				if (!m.sections.length) {
					table.innerHTML = '<p class="description">' + esc(L.noSection) + '</p>';
					return;
				}

				var views = Math.max(1, m.views);
				var html = '<table class="widefat striped fw-vi-table"><thead><tr><th>' + esc(L.section) + '</th><th>' + esc(L.reach) + '</th><th class="num">' + esc(L.time) + '</th></tr></thead><tbody>';

				m.sections.forEach(function (s, i) {
					var p = Math.min(100, Math.round(100 * s.reached / views));
					html += '<tr><td><span class="fw-vi-num">' + (i + 1) + '</span> ' + esc(s.label) + '</td>'
						+ '<td><div class="fw-vi-bar"><span class="fw-vi-bar__fill" style="width:' + p + '%"></span><span class="fw-vi-bar__label">' + (m.views ? p + '%' : '—') + '</span></div></td>'
						+ '<td class="num">' + (s.reached ? seconds(s.ms / s.reached) : '—') + '</td></tr>';
				});

				table.innerHTML = html + '</tbody></table>';
			}
		});

		w.addEventListener('resize', layout);
		layout();
		frame.src = hm.getAttribute('data-src');
	}

	/* ---------------------------------------------------------------- live */

	var live = d.querySelector('[data-live]');

	if (live && A.live) {
		var count = live.querySelector('.fw-vi-live__count strong');
		var list = live.querySelector('.fw-vi-live__pages');

		var poll = function () {
			w.fetch(A.live, { credentials: 'same-origin', headers: { 'X-WP-Nonce': A.nonce } })
				.then(function (r) {
					return r.json();
				})
				.then(function (j) {
					count.textContent = j.visitors;

					if (!j.pages.length) {
						list.innerHTML = '<p class="description">' + esc(L.quiet) + '</p>';
						return;
					}

					var max = Math.max(1, j.pages[0].n);
					list.innerHTML = j.pages.map(function (p) {
						return '<div class="fw-vi-bar"><span class="fw-vi-bar__fill" style="width:' + Math.round(100 * p.n / max) + '%"></span><span class="fw-vi-bar__label"><code>' + esc(p.path) + '</code></span><span class="fw-vi-bar__n">' + p.n + '</span></div>';
					}).join('');
				})
				.catch(function () {});
		};

		poll();
		w.setInterval(poll, 15000);
	}
})(window, document);
