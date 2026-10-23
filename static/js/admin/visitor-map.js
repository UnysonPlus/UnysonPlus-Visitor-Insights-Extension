/**
 * Visitor Insights — the visitor map's tooltip. The map itself is inline SVG
 * drawn on the server; each country and city carries its text in data-tip.
 */
(function (d) {
	'use strict';

	var box = d.querySelector('.fw-vi-map');

	if (!box) {
		return;
	}

	var tip = box.querySelector('.fw-vi-map__tip');
	var current = null;

	function show(el, x, y) {
		if (current && current !== el) {
			current.classList.remove('is-hover');
		}
		current = el;
		el.classList.add('is-hover');
		tip.textContent = el.getAttribute('data-tip');
		tip.hidden = false;

		var r = box.getBoundingClientRect();
		var left = Math.min(x - r.left + 14, r.width - tip.offsetWidth - 8);
		tip.style.left = Math.max(8, left) + 'px';
		tip.style.top = (y - r.top + 14) + 'px';
	}

	function hide() {
		if (current) {
			current.classList.remove('is-hover');
			current = null;
		}
		tip.hidden = true;
	}

	box.addEventListener('mousemove', function (e) {
		var el = e.target.closest ? e.target.closest('[data-tip]') : null;
		if (el) {
			show(el, e.clientX, e.clientY);
		} else {
			hide();
		}
	});
	box.addEventListener('mouseleave', hide);
})(document);
