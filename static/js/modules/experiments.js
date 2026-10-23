/**
 * Visitor Insights — section A/B testing module.
 *
 * The variant is chosen before the page paints by a few bytes in <head>
 * (FW_VI_Experiments), which leave the choice in window.UPVIX. This module
 * only reports which version this page view saw, once tracking is allowed.
 */
(function (w) {
	'use strict';

	var VI = w.UnysonPlusVI;

	if (!VI || !w.UPVIX) {
		return;
	}

	VI.module(function (api) {
		for (var id in w.UPVIX) {
			if (/^[a-z0-9-]{1,20}$/.test(id) && (w.UPVIX[id] === 'a' || w.UPVIX[id] === 'b')) {
				api.push(['e', id, w.UPVIX[id]]);
			}
		}
	});
})(window);
