/**
 * Visitor Insights — heatmap viewer. Runs INSIDE the page being inspected
 * (`?fw_vi_view=<nonce>`), so every builder item's live box is at hand.
 *
 * Two fixed, viewport-sized canvases over the page: `base` for dimming, bands,
 * outlines and labels; `heat` for the simpleheat layer. Everything is drawn in
 * viewport coordinates and redrawn on scroll / resize, so a 20,000px page
 * costs no more memory than one screen.
 *
 * Framed in the admin (Heatmaps tab): the parent sends `upvi:show` with the
 * mode, device and period, and receives `upvi:info` (views, sections) back.
 * Opened from the admin bar: a small toolbar of its own does the same.
 */
(function (w, d) {
	'use strict';

	var V = w.UPVIV;

	if (!V || !d.body) {
		return;
	}

	var L = V.l10n || {};
	var st = { mode: 'clicks', device: deviceOf(w.innerWidth), days: 30 };
	var data = null;
	var base, heatCanvas, heat, bctx, raf = 0, req = 0, cache = {}, missing = 0;
	var dpr = Math.max(1, Math.min(2, w.devicePixelRatio || 1));

	function deviceOf(width) {
		return width < 768 ? 'm' : (width < 1025 ? 't' : 'd');
	}

	function fmt(s, v) {
		return String(s || '').replace('%s', v);
	}

	function canvas(z) {
		var c = d.createElement('canvas');
		c.setAttribute('aria-hidden', 'true');
		c.style.cssText = 'position:fixed;left:0;top:0;width:100vw;height:100vh;pointer-events:none;z-index:' + z;
		d.documentElement.appendChild(c);
		return c;
	}

	function size() {
		[base, heatCanvas].forEach(function (c) {
			c.width = Math.round(w.innerWidth * dpr);
			c.height = Math.round(w.innerHeight * dpr);
		});
		if (heat) {
			heat.resize();
		}
	}

	function el(item) {
		if (!(item in cache)) {
			cache[item] = d.querySelector('[data-fw-item-id="' + item + '"]');
		}
		return cache[item];
	}

	function shown(node) {
		if (!node) {
			return false;
		}
		var r = node.getBoundingClientRect();
		return r.width > 0 && r.height > 0;
	}

	function docH() {
		return Math.max(d.body.scrollHeight, d.documentElement.scrollHeight);
	}

	function docW() {
		return Math.max(d.documentElement.scrollWidth, 1);
	}

	/* ------------------------------------------------------------------ data */

	function load() {
		var id = ++req;
		var url = V.rest + (V.rest.indexOf('?') < 0 ? '?' : '&') + 'path=' + encodeURIComponent(V.path) + '&device=' + st.device + '&days=' + st.days;

		w.fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': V.nonce } })
			.then(function (r) {
				return r.json();
			})
			.then(function (json) {
				if (id !== req) {
					return;
				}
				data = json;
				cache = {};
				report();
				draw();
			})
			.catch(function () {});
	}

	function totalClicks() {
		var n = 0;
		(data.elem || []).forEach(function (e) {
			n += e[3];
		});
		(data.grid || []).forEach(function (g) {
			n += g[2];
		});
		return n;
	}

	function sectionLabel(node, i) {
		var h = node.querySelector('h1,h2,h3,h4,h5,h6');
		var t = h ? (h.textContent || '').replace(/\s+/g, ' ').trim() : '';
		return t ? t.slice(0, 70) : 'Section ' + (i + 1);
	}

	/** Tell the admin screen what it needs for the table under the frame. */
	function report() {
		var rows = {};
		(data.sections || []).forEach(function (s) {
			rows[s[0]] = s;
		});

		missing = 0;
		(data.elem || []).forEach(function (e) {
			if (!el(e[0])) {
				missing += e[3];
			}
		});

		var secs = [].slice.call(d.querySelectorAll('section[data-fw-item-id]')).filter(function (s) {
			return !(s.parentElement && s.parentElement.closest('section[data-fw-item-id]'));
		}).map(function (s, i) {
			var id = s.getAttribute('data-fw-item-id');
			var r = rows[id] || [id, 0, 0];
			return { id: id, label: sectionLabel(s, i), reached: r[1], ms: r[2] };
		});

		var info = { type: 'upvi:info', views: data.views, clicks: totalClicks(), missing: missing, sections: secs };

		if (w.parent && w.parent !== w) {
			w.parent.postMessage(info, w.location.origin);
		}

		if (toolbar) {
			toolbar.querySelector('.upvi-tb__info').textContent = data.views
				? fmt(L.views, data.views.toLocaleString()) + (missing ? ' · ' + fmt(L.missing, missing.toLocaleString()) : '')
				: L.none;
		}
	}

	/* ------------------------------------------------------------------ draw */

	function draw() {
		if (raf) {
			return;
		}
		raf = w.requestAnimationFrame(function () {
			raf = 0;
			render();
		});
	}

	function render() {
		var W = w.innerWidth;
		var H = w.innerHeight;

		bctx.setTransform(dpr, 0, 0, dpr, 0, 0);
		bctx.clearRect(0, 0, W, H);
		heat.clear();
		heat.draw();

		if (!data) {
			return;
		}

		if (!data.views) {
			bctx.fillStyle = 'rgba(15,23,42,.35)';
			bctx.fillRect(0, 0, W, H);
			return;
		}

		({ clicks: drawClicks, scroll: drawScroll, attention: drawAttention, elements: drawElements, frustration: drawFrustration }[st.mode] || drawClicks)(W, H);
	}

	function drawClicks(W, H) {
		bctx.fillStyle = 'rgba(15,23,42,.45)';
		bctx.fillRect(0, 0, W, H);

		var pts = [];
		var max = 1;
		var sx = w.pageXOffset;
		var sy = w.pageYOffset;

		(data.elem || []).forEach(function (e) {
			max = Math.max(max, e[3]);
			var node = el(e[0]);
			if (!shown(node)) {
				return;
			}
			var r = node.getBoundingClientRect();
			var x = r.left + (e[1] + 0.5) / 10 * r.width;
			var y = r.top + (e[2] + 0.5) / 10 * r.height;
			if (x > -40 && x < W + 40 && y > -40 && y < H + 40) {
				pts.push([x * dpr, y * dpr, e[3]]);
			}
		});

		var dw = docW();
		(data.grid || []).forEach(function (g) {
			max = Math.max(max, g[2]);
			var x = g[0] / 100 * dw - sx;
			var y = g[1] * 20 + 10 - sy;
			if (x > -40 && x < W + 40 && y > -40 && y < H + 40) {
				pts.push([x * dpr, y * dpr, g[2]]);
			}
		});

		heat.radius(22 * dpr, 16 * dpr).max(max).data(pts).draw(0.08);
	}

	function reachColor(p, a) {
		// 100% red → 50% yellow → 0% blue
		var hue = Math.round(240 - 240 * p);
		return 'hsla(' + hue + ',90%,50%,' + a + ')';
	}

	function label(text, x, y, opts) {
		opts = opts || {};
		bctx.font = '600 12px -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif';
		var tw = bctx.measureText(text).width;
		var px = opts.right ? x - tw - 16 : x;
		bctx.fillStyle = opts.bg || 'rgba(15,23,42,.85)';
		bctx.beginPath();
		if (bctx.roundRect) {
			bctx.roundRect(px, y, tw + 16, 22, 4);
		} else {
			bctx.rect(px, y, tw + 16, 22);
		}
		bctx.fill();
		bctx.fillStyle = '#fff';
		bctx.fillText(text, px + 8, y + 15);
	}

	function drawScroll(W, H) {
		var bands = {};
		(data.scroll || []).forEach(function (s) {
			bands[s[0]] = s[1];
		});

		var views = Math.max(1, data.views);
		var dh = docH();
		var sy = w.pageYOffset;
		var marks = { 75: false, 50: false, 25: false };

		for (var b = 0; b < 100; b += 5) {
			// Views that reached the END of this band = those reaching band b+5.
			var p = Math.min(1, (bands[b + 5] !== undefined ? bands[b + 5] : (b === 0 ? views : 0)) / views);
			var y0 = b / 100 * dh - sy;
			var y1 = (b + 5) / 100 * dh - sy;

			if (y1 < 0 || y0 > H) {
				continue;
			}

			bctx.fillStyle = reachColor(p, 0.42);
			bctx.fillRect(0, y0, W, y1 - y0);

			if (b % 10 === 0) {
				label(Math.round(p * 100) + '%', 12, Math.max(4, y0 + 4));
			}
		}

		// Where 75 / 50 / 25 % of page views stopped.
		for (var t in marks) {
			for (var k = 5; k <= 100; k += 5) {
				var pk = (bands[k] || 0) / views;
				if (pk < t / 100) {
					var y = k / 100 * dh - sy;
					if (y > 0 && y < H) {
						bctx.strokeStyle = '#fff';
						bctx.setLineDash([6, 4]);
						bctx.lineWidth = 2;
						bctx.beginPath();
						bctx.moveTo(0, y);
						bctx.lineTo(W, y);
						bctx.stroke();
						bctx.setLineDash([]);
						label(fmt(L.reached, t + '%'), W - 12, y - 26, { right: true });
					}
					break;
				}
			}
		}
	}

	function drawAttention(W, H) {
		bctx.fillStyle = 'rgba(15,23,42,.55)';
		bctx.fillRect(0, 0, W, H);

		var views = Math.max(1, data.views);
		var rows = {};
		var maxAvg = 1;

		(data.sections || []).forEach(function (s) {
			rows[s[0]] = s;
			if (s[1]) {
				maxAvg = Math.max(maxAvg, s[2] / s[1]);
			}
		});

		[].slice.call(d.querySelectorAll('section[data-fw-item-id]')).forEach(function (node) {
			if (node.parentElement && node.parentElement.closest('section[data-fw-item-id]')) {
				return;
			}
			var r = node.getBoundingClientRect();
			if (r.bottom < 0 || r.top > H || !r.height) {
				return;
			}
			var row = rows[node.getAttribute('data-fw-item-id')] || [0, 0, 0];
			var avg = row[1] ? row[2] / row[1] : 0;
			var norm = avg / maxAvg;

			bctx.clearRect(r.left, r.top, r.width, r.height);
			bctx.fillStyle = 'rgba(255,' + Math.round(170 - 120 * norm) + ',0,' + (0.12 + 0.5 * norm).toFixed(2) + ')';
			bctx.fillRect(r.left, r.top, r.width, r.height);

			label(
				fmt(L.reached, Math.round(Math.min(1, row[1] / views) * 100) + '%') + ' · ' + fmt(L.avg, (avg / 1000).toFixed(1)),
				Math.max(8, r.left + 12),
				Math.max(8, r.top + 12)
			);
		});
	}

	function drawElements(W, H) {
		bctx.fillStyle = 'rgba(15,23,42,.35)';
		bctx.fillRect(0, 0, W, H);

		var total = Math.max(1, totalClicks());
		var per = {};

		(data.elem || []).forEach(function (e) {
			per[e[0]] = (per[e[0]] || 0) + e[3];
		});

		Object.keys(per).sort(function (a, b) {
			return per[b] - per[a];
		}).slice(0, 25).forEach(function (item) {
			var node = el(item);
			if (!shown(node)) {
				return;
			}
			var r = node.getBoundingClientRect();
			if (r.bottom < 0 || r.top > H) {
				return;
			}
			var share = per[item] / total;

			bctx.strokeStyle = reachColor(Math.min(1, share * 4), 1);
			bctx.lineWidth = 2;
			bctx.strokeRect(r.left + 1, r.top + 1, r.width - 2, r.height - 2);
			label(fmt(L.ofClicks, (share * 100).toFixed(1) + '%'), Math.min(W - 8, r.right - 4), Math.max(4, r.top + 4), { right: true, bg: 'rgba(194,65,12,.95)' });
		});
	}

	function drawFrustration(W, H) {
		bctx.fillStyle = 'rgba(15,23,42,.45)';
		bctx.fillRect(0, 0, W, H);

		var per = {};
		var page = 0;

		(data.friction || []).forEach(function (f) {
			if (!f[0]) {
				page += f[2];
				return;
			}
			per[f[0]] = per[f[0]] || { r: 0, d: 0, e: 0 };
			per[f[0]][f[1]] += f[2];
		});

		Object.keys(per).forEach(function (item) {
			var node = el(item);
			if (!shown(node)) {
				return;
			}
			var r = node.getBoundingClientRect();
			if (r.bottom < 0 || r.top > H) {
				return;
			}
			var c = per[item];
			var parts = [];
			if (c.r) {
				parts.push(fmt(L.rage, c.r));
			}
			if (c.d) {
				parts.push(fmt(L.dead, c.d));
			}
			if (c.e) {
				parts.push(fmt(L.errorc, c.e));
			}

			bctx.clearRect(r.left, r.top, r.width, r.height);
			bctx.strokeStyle = '#ef4444';
			bctx.lineWidth = 2;
			bctx.strokeRect(r.left + 1, r.top + 1, r.width - 2, r.height - 2);
			label(parts.join(' · '), Math.min(W - 8, r.right - 4), Math.max(4, r.top + 4), { right: true, bg: 'rgba(185,28,28,.95)' });
		});

		if (page) {
			label(fmt(L.pageLevel, page), 12, 12, { bg: 'rgba(185,28,28,.95)' });
		}
	}

	/* ------------------------------------------------------------------ chrome */

	var toolbar = null;

	function select(name, options, value) {
		var s = d.createElement('select');
		s.name = name;
		options.forEach(function (o) {
			var op = d.createElement('option');
			op.value = o[0];
			op.textContent = o[1];
			op.selected = String(o[0]) === String(value);
			s.appendChild(op);
		});
		s.addEventListener('change', function () {
			st[name] = name === 'days' ? parseInt(s.value, 10) : s.value;
			if (name === 'mode') {
				draw();
			} else {
				load();
			}
		});
		return s;
	}

	function buildToolbar() {
		toolbar = d.createElement('div');
		toolbar.className = 'upvi-tb';
		toolbar.style.cssText = 'position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:2147483647;display:flex;gap:8px;align-items:center;padding:8px 10px;background:#0f172a;color:#fff;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,.35);font:13px/1.3 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif';

		toolbar.appendChild(select('mode', [['clicks', L.clicks], ['scroll', L.scroll], ['attention', L.attention], ['elements', L.elements], ['frustration', L.frustration]], st.mode));
		toolbar.appendChild(select('device', [['d', L.desktop], ['t', L.tablet], ['m', L.mobile]], st.device));
		toolbar.appendChild(select('days', [[7, fmt(L.days, 7)], [30, fmt(L.days, 30)], [90, fmt(L.days, 90)], [365, fmt(L.days, 365)]], st.days));

		var info = d.createElement('span');
		info.className = 'upvi-tb__info';
		info.style.cssText = 'opacity:.8;max-width:340px';
		toolbar.appendChild(info);

		// Embedded (Live Editor): closing removes the overlay. Standalone: it
		// leaves the heatmap view for the plain page.
		var close = d.createElement(V.embedded ? 'button' : 'a');
		if (V.embedded) {
			close.type = 'button';
			close.addEventListener('click', destroy);
		} else {
			close.href = w.location.href.replace(/([?&])fw_vi_view=[^&]*&?/, '$1').replace(/[?&]$/, '');
		}
		close.textContent = '×';
		close.title = L.close;
		close.setAttribute('aria-label', L.close);
		close.style.cssText = 'color:#fff;text-decoration:none;font-size:20px;padding:0 4px;background:none;border:0;cursor:pointer';
		toolbar.appendChild(close);

		[].forEach.call(toolbar.querySelectorAll('select'), function (s) {
			s.style.cssText = 'background:#1e293b;color:#fff;border:1px solid #334155;border-radius:6px;padding:4px 6px;font:inherit';
		});

		d.body.appendChild(toolbar);
	}

	/* ------------------------------------------------------------------ boot */

	function onResize() {
		size();
		draw();
	}

	/** Remove the overlay entirely (embedded use); the page is left as it was. */
	function destroy() {
		req++;
		w.removeEventListener('scroll', draw);
		w.removeEventListener('resize', onResize);
		[base, heatCanvas, toolbar].forEach(function (n) {
			if (n && n.parentNode) {
				n.parentNode.removeChild(n);
			}
		});
		data = null;
		if (typeof V.onClose === 'function') {
			V.onClose();
		}
	}

	function boot() {
		base = canvas(2147483645);
		heatCanvas = canvas(2147483646);
		bctx = base.getContext('2d');
		size();
		heat = w.simpleheat(heatCanvas);

		w.addEventListener('scroll', draw, { passive: true });
		w.addEventListener('resize', onResize);

		// Images and fonts settle after load and move things; redraw a few times.
		[600, 1500, 3000].forEach(function (t) {
			w.setTimeout(draw, t);
		});

		if (V.framed) {
			// The frame is for looking, not navigating away from.
			d.addEventListener('click', function (e) {
				var a = e.target && e.target.closest && e.target.closest('a[href],form button,input[type=submit]');
				if (a) {
					e.preventDefault();
				}
			}, true);

			w.addEventListener('message', function (e) {
				if (e.origin !== w.location.origin || !e.data || e.data.type !== 'upvi:show') {
					return;
				}
				var reload = e.data.device !== st.device || e.data.days !== st.days || !data;
				st.mode = e.data.mode || st.mode;
				st.device = e.data.device || st.device;
				st.days = e.data.days || st.days;
				if (reload) {
					load();
				} else {
					draw();
				}
			});

			w.parent.postMessage({ type: 'upvi:ready' }, w.location.origin);
		} else {
			buildToolbar();
			load();
		}
	}

	if (d.readyState === 'complete') {
		boot();
	} else {
		w.addEventListener('load', boot);
	}
})(window, document);
