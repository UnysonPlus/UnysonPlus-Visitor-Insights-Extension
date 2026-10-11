/**
 * Visitor Insights — badges in the backend page builder.
 *
 * Each item's control bar shows its share of the page's clicks over the last
 * 30 days, and each section how many page views reached it, so the numbers
 * sit next to the thing you would change. Items are matched on the builder
 * `unique_id` the tracker records. Nothing is shown for items with no data.
 */
(function (w, $) {
	'use strict';

	var B = w.UPVIB;

	if (!B || !w.fwEvents || !B.items) {
		return;
	}

	var L = B.l10n || {};

	function badge(data) {
		var atts = data.model && data.model.get('atts');
		var id = atts && atts.unique_id;
		var row = id && B.items[id];

		if (!row || !data.$controls || data.$controls.find('.fw-vi-badge').length) {
			return;
		}

		var parts = [];
		var tips = [];

		if (row[1] !== null && row[1] !== undefined) {
			parts.push(row[1] + '%');
			tips.push(String(L.reach).replace('%s', row[1] + '%'));
		}

		if (row[0]) {
			parts.push(row[0] + '% ' + L.clicksShort);
			tips.push(String(L.clicks).replace('%s', row[0] + '%'));
		}

		if (!parts.length) {
			return;
		}

		$('<span class="fw-vi-badge"></span>')
			.text(parts.join(' · '))
			.attr('title', tips.join('\n') + '\n' + L.period)
			.attr('data-hover-tip', tips.join(' · '))
			.prependTo(data.$controls);
	}

	w.fwEvents.on([
		'fw:page-builder:shortcode:item-simple:controls',
		'fw:page-builder:shortcode:section:controls',
		'fw:page-builder:shortcode:column:controls',
		'fw:page-builder:shortcode:innercolumn:controls',
		'fw:page-builder:shortcode:flexbox:controls',
		'fw:page-builder:shortcode:contact-form:controls'
	].join(' '), badge);
})(window, jQuery);
