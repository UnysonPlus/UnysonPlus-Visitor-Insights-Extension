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
	var st = { mode: 'clicks', device: deviceOf(w.innerWidth), days: 30, ch: '' };
	// Offset from viewport to target-canvas coordinates: 0 on screen, the scroll
	// position while drawing the whole page for an image export.
	var OX = 0;
	var OY = 0;
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

	/** An element's box in target-canvas coordinates. */
	function box(node) {
		var r = node.getBoundingClientRect();
		return { left: r.left + OX, top: r.top + OY, right: r.right + OX, bottom: r.bottom + OY, width: r.width, height: r.height };
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
		var url = V.rest + (V.rest.indexOf('?') < 0 ? '?' : '&') + 'path=' + encodeURIComponent(V.path) + '&device=' + st.device + '&days=' + st.days + '&ch=' + encodeURIComponent(st.ch || '');

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
		renderInto(w.innerWidth, w.innerHeight);
	}

	function renderInto(W, H) {
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

		({ clicks: drawClicks, scroll: drawScroll, attention: drawAttention, elements: drawElements, frustration: drawFrustration, moves: drawMoves }[st.mode] || drawClicks)(W, H);
	}

	function drawClicks(W, H) {
		drawHeat(W, H, data.elem || [], data.grid || []);
	}

	/** Pointer movement: the same drawing as clicks, from the movement bins. */
	function drawMoves(W, H) {
		var elem = [];
		var grid = [];
		(data.moves || []).forEach(function (m) {
			if (m[0]) {
				elem.push(m);
			} else {
				grid.push([m[1], m[2], m[3]]);
			}
		});
		drawHeat(W, H, elem, grid);
	}

	function drawHeat(W, H, elemList, gridList) {
		bctx.fillStyle = 'rgba(15,23,42,.45)';
		bctx.fillRect(0, 0, W, H);

		var pts = [];
		var max = 1;
		var sx = w.pageXOffset - OX;
		var sy = w.pageYOffset - OY;

		elemList.forEach(function (e) {
			max = Math.max(max, e[3]);
			var node = el(e[0]);
			if (!shown(node)) {
				return;
			}
			var r = box(node);
			var x = r.left + (e[1] + 0.5) / 10 * r.width;
			var y = r.top + (e[2] + 0.5) / 10 * r.height;
			if (x > -40 && x < W + 40 && y > -40 && y < H + 40) {
				pts.push([x * dpr, y * dpr, e[3]]);
			}
		});

		var dw = docW();
		gridList.forEach(function (g) {
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
		var sy = w.pageYOffset - OY;
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
			var r = box(node);
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
			var r = box(node);
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
			var r = box(node);
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

	/* ------------------------------------------------------------------ export */

	function script(src) {
		return new Promise(function (resolve, reject) {
			var sc = d.createElement('script');
			sc.src = src;
			sc.onload = resolve;
			sc.onerror = reject;
			d.head.appendChild(sc);
		});
	}

	/**
	 * The whole page with the current map on top, as a PNG download. The page is
	 * rendered by html2canvas (loaded on first use), then the map is drawn again
	 * at page scale on its own layers, and a caption strip says what it shows.
	 */
	function exportImage() {
		if (!data) {
			return;
		}

		var ready = w.html2canvas ? Promise.resolve() : script(V.h2c);
		// The library exposes a function, or an object holding it (UMD build).
		var h2c = function () {
			var lib = w.html2canvas;
			return typeof lib === 'function' ? lib : (lib && (lib['default'] || lib.html2canvas));
		};

		ready.then(function () {
			var dw = docW();
			var dh = Math.min(docH(), 12000);
			var hidden = [base, heatCanvas, toolbar].filter(Boolean);

			hidden.forEach(function (n) {
				n.style.visibility = 'hidden';
			});

			return h2c()(d.body, {
				x: 0,
				y: 0,
				width: dw,
				height: dh,
				windowWidth: dw,
				windowHeight: dh,
				scrollX: -w.pageXOffset,
				scrollY: -w.pageYOffset,
				scale: 1,
				useCORS: true,
				logging: false,
				backgroundColor: '#ffffff',
				ignoreElements: function (n) {
					return n === base || n === heatCanvas || n === toolbar || n.id === 'wpadminbar';
				}
			}).then(function (pageCanvas) {
				hidden.forEach(function (n) {
					n.style.visibility = '';
				});

				var head = 44;
				var out = d.createElement('canvas');
				out.width = pageCanvas.width;
				out.height = pageCanvas.height + head;
				var o = out.getContext('2d');

				o.fillStyle = '#0f172a';
				o.fillRect(0, 0, out.width, head);
				o.fillStyle = '#fff';
				o.font = '600 15px -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif';
				var dev = { d: L.desktop, t: L.tablet, m: L.mobile }[st.device] || '';
				var chn = st.ch && V.channels ? ' · ' + (V.channels[st.ch] || st.ch) : '';
				o.fillText([L[st.mode] || st.mode, V.path, dev, fmt(L.days, st.days) + chn, fmt(L.views, (data.views || 0).toLocaleString())].join('  ·  '), 16, 28);
				o.drawImage(pageCanvas, 0, head);

				// Draw the map again at page scale, on layers of its own.
				var lb = d.createElement('canvas');
				var lh = d.createElement('canvas');
				lb.width = lh.width = pageCanvas.width;
				lb.height = lh.height = pageCanvas.height;

				var saved = { bctx: bctx, heat: heat, dpr: dpr, OX: OX, OY: OY };
				bctx = lb.getContext('2d');
				heat = w.simpleheat(lh);
				dpr = pageCanvas.width / dw;
				OX = w.pageXOffset;
				OY = w.pageYOffset;

				try {
					renderInto(dw, dh);
				} finally {
					bctx = saved.bctx;
					heat = saved.heat;
					dpr = saved.dpr;
					OX = saved.OX;
					OY = saved.OY;
				}

				o.drawImage(lb, 0, head);
				o.drawImage(lh, 0, head);

				out.toBlob(function (blob) {
					var slug = (V.path || '/').replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'home';
					var name = 'heatmap-' + slug + '-' + st.mode + '-' + st.device + (st.ch ? '-' + st.ch : '') + '.png';

					// Browsers may refuse a download started from a frame after an
					// asynchronous render, so a framed viewer hands the image to the
					// admin screen, which offers it; on its own, the toolbar does.
					if (V.framed && w.parent !== w) {
						w.parent.postMessage({ type: 'upvi:image', blob: blob, name: name }, w.location.origin);
						return;
					}

					offer(blob, name);
				}, 'image/png');

				draw();
			});
		}).catch(function (err) {
			[base, heatCanvas, toolbar].filter(Boolean).forEach(function (n) {
				n.style.visibility = '';
			});
			if (w.console) {
				w.console.error('Visitor Insights: heatmap image export failed', err);
			}
		});
	}

	/** Start the download, and leave a "Save image" link in the toolbar for browsers that held it back. */
	function offer(blob, name) {
		var a = d.createElement('a');
		a.href = w.URL.createObjectURL(blob);
		a.download = name;
		a.textContent = L.save || 'Save image';
		a.style.cssText = 'color:#93c5fd;font-weight:600;text-decoration:underline';
		if (toolbar) {
			var old = toolbar.querySelector('.upvi-tb__save');
			if (old) {
				old.remove();
			}
			a.className = 'upvi-tb__save';
			toolbar.insertBefore(a, toolbar.lastChild);
		} else {
			d.body.appendChild(a);
		}
		a.click();
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

		toolbar.appendChild(select('mode', [['clicks', L.clicks], ['scroll', L.scroll], ['attention', L.attention], ['elements', L.elements], ['frustration', L.frustration], ['moves', L.moves]], st.mode));
		toolbar.appendChild(select('device', [['d', L.desktop], ['t', L.tablet], ['m', L.mobile]], st.device));
		toolbar.appendChild(select('days', [[7, fmt(L.days, 7)], [30, fmt(L.days, 30)], [90, fmt(L.days, 90)], [365, fmt(L.days, 365)]], st.days));

		if (V.channels) {
			toolbar.appendChild(select('ch', Object.keys(V.channels).map(function (k) {
				return [k, V.channels[k]];
			}), st.ch));
		}

		var dl = d.createElement('button');
		dl.type = 'button';
		dl.textContent = '⤓';
		dl.title = L.download || 'Download image';
		dl.setAttribute('aria-label', dl.title);
		dl.style.cssText = 'color:#fff;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:3px 8px;cursor:pointer;font:inherit';
		dl.addEventListener('click', exportImage);
		toolbar.appendChild(dl);

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
				if (e.origin !== w.location.origin || !e.data) {
					return;
				}
				if (e.data.type === 'upvi:export') {
					exportImage();
					return;
				}
				if (e.data.type !== 'upvi:show') {
					return;
				}
				var reload = e.data.device !== st.device || e.data.days !== st.days || (e.data.ch || '') !== st.ch || !data;
				st.mode = e.data.mode || st.mode;
				st.device = e.data.device || st.device;
				st.days = e.data.days || st.days;
				st.ch = e.data.ch || '';
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
