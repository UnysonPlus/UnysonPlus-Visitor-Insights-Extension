/**
 * Visitor Insights — exit links and downloads.
 *
 * A click on a link that leaves the site, or on a file (PDF, ZIP, documents,
 * media, installers), is sent as [o, l|d, url] — the address without its query
 * string — and the batch goes at once, because the page is about to change.
 */
(function (w, d) {
	'use strict';

	var VI = w.UnysonPlusVI;

	if (!VI) {
		return;
	}

	var FILE = /\.(pdf|zip|rar|7z|gz|tar|docx?|xlsx?|pptx?|odt|ods|csv|txt|rtf|epub|mp3|wav|mp4|mov|avi|dmg|exe|msi|apk|pkg|iso|psd|ai|eps)$/i;

	VI.module(function (api) {
		d.addEventListener('click', function (e) {
			if (!e.isTrusted || !api.on()) {
				return;
			}

			var t = e.target && e.target.nodeType === 1 ? e.target : (e.target && e.target.parentElement);
			var a = t && t.closest ? t.closest('a[href]') : null;

			if (!a || !/^https?:$/.test(a.protocol)) {
				return;
			}

			var url = a.protocol + '//' + a.host + a.pathname;
			var kind = FILE.test(a.pathname) || a.hasAttribute('download') ? 'd' : (a.host !== w.location.host ? 'l' : '');

			if (kind) {
				api.push(['o', kind, url.slice(0, 190)]);
				api.flush();
			}
		}, { capture: true, passive: true });
	});
})(window, document);
