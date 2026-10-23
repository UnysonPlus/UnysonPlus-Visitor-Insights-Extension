/**
 * Visitor Insights — recording player (Recordings tab). Loads one
 * recording's events from the REST API (admin-only, every playback logged)
 * and plays them with rrweb-player.
 */
(function (w, d) {
	'use strict';

	var el = d.getElementById('fw-vi-player');
	var P = w.UPVIP || {};

	if (!el) {
		return;
	}

	function say(msg) {
		el.innerHTML = '';
		var p = d.createElement('p');
		p.className = 'description';
		p.textContent = msg;
		el.appendChild(p);
	}

	w.fetch(el.getAttribute('data-src'), { credentials: 'same-origin', headers: { 'X-WP-Nonce': P.nonce } })
		.then(function (r) {
			if (!r.ok) {
				throw new Error(r.status);
			}
			return r.json();
		})
		.then(function (events) {
			if (!events || events.length < 2) {
				say(P.empty);
				return;
			}

			var lib = w.rrwebPlayer;
			var Player = lib && (lib['default'] || lib.Player || lib);

			el.innerHTML = '';
			var width = Math.min(el.clientWidth || 1000, 1280);

			new Player({
				target: el,
				props: {
					events: events,
					width: width,
					height: Math.round(width * 0.56),
					autoPlay: true,
					showController: true,
					skipInactive: true,
					mouseTail: true
				}
			});
		})
		.catch(function () {
			say(P.error);
		});
})(window, document);
