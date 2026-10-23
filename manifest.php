<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

$manifest['name']        = __( 'Visitor Insights', 'fw' );
$manifest['slug']        = 'unysonplus-visitor-insights';
$manifest['description'] = __(
	'Self-hosted, privacy-first visitor analytics. Traffic without cookies (views, unique visitors, top pages, referrers, campaigns, devices, countries, a live view), click / scroll / attention heatmaps drawn over the live page, and reach and attention for every page-builder section. Works with WordPress Consent API plugins and Global Privacy Control. Nothing is sent off the site.',
	'fw'
);

/**
 * Changelog ----------------------------------------------------------------
 *
 * 1.0.10 - How visitors found you, completed (Phase 9). Visitor paths: page
 *          views joined into visits (today's visitor hash, 30-minute gap) in
 *          fw_vi_visits, listed with where each came from, region and city,
 *          device, new or returning, and every page in order down to the exit
 *          link or download; the hash is blanked when its day ends and rows go
 *          after "Keep visit paths" (7-90 days). Entry and exit pages, with
 *          bounce rates, on the Pages view. Exit links and downloads
 *          (modules/outbound.js, fw_vi_outbound). New and returning visitors,
 *          measured only under statistics consent (UnysonPlusVI.grantStatistics()
 *          for a site's own banner). Regions and cities from CDN headers or a
 *          city database on the site, read by a built-in MaxMind-format reader;
 *          the free DB-IP city database downloads from Settings. A bot report:
 *          crawlers counted when WordPress serves them and when the collector
 *          refuses a batch, by kind and name, against human page views.
 *          Schema 1.7.0.
 *
 * 1.0.9 - How visitors found you (Phase 9, first part). Keywords: the search
 *         terms per landing page, gathered from where they still exist and
 *         labelled by source: Search Console (queries, clicks, impressions,
 *         position, through the SEO extension's connection, cached 12 hours),
 *         search words a referring engine still passes (sources dim kw, masked)
 *         and utm_term (dim ut). Referring pages: the exact page a visitor
 *         clicked from when the referring site shares it (dim rp, host + path,
 *         never the query string), grouped under its site with the landing
 *         page; the rest of a site's visits show as "page not shared". A new
 *         "How they found you" sidebar group holds both.
 *
 * 1.0.8 - Import / Export, and the app frame. One file (JSON, gzipped when the
 *         server can) carries the setup (settings, goals, automatic-goal
 *         switches, funnels, A/B tests, notes) and optionally the daily summary
 *         tables for all time or a recent period. Import adds to what is here
 *         (existing keys and days win) or replaces it; page paths are stored
 *         relative to the home, so history follows a site to a new domain or
 *         folder. Visitor keys, collector secrets and recordings never leave the
 *         site. The screens now render in the shared admin app frame used by
 *         SEO, the CRM and Membership: a grouped sidebar instead of thirteen
 *         tabs, with a top-level menu for people who may view the reports but
 *         not reach the Unyson+ menu (filter fw_ext_visitor_insights_nav).
 *
 * 1.0.6 - Deeper heatmaps (Phase 7). Every heatmap mode can be filtered to one
 *         traffic channel: the rollup repeats each heatmap signal (views,
 *         clicks, scroll bands, section reach and time, movement, friction)
 *         under the visit's channel in fw_vi_hmch, and the viewer, the
 *         Heatmaps tab and the Live Editor overlay get a channel menu. "Download
 *         image" renders the page (vendored html2canvas-pro 2.4.5, MIT, loaded
 *         only when used — it reads modern CSS colour functions the original
 *         does not), redraws the current map at page scale and adds a caption
 *         strip; a framed viewer hands the PNG to the admin screen, which
 *         starts the download and offers a "Save image" link. Verified end to
 *         end: a real store checkout records the order goal once with its
 *         value; the weekly email and an alert are delivered over SMTP. The
 *         weekly email now says so when a week had no visits. Schema 1.6.0.
 *
 * 1.0.5 - Analysis (Phase 6). Every Overview figure and each page now shows
 *         its change against the period before. Channel performance: views,
 *         time on page, read-half rate, goal completions and conversion per
 *         traffic channel (fw_vi_chdaily; a view's channel follows its later
 *         batches through the view id, kept on the live row), with a channel
 *         filter on the Pages tab. Annotations: dated notes drawn as markers
 *         on the Overview chart. Recordings per page, linked from the heatmap
 *         and the Pages tab. Alerts (opt-in): one email the morning after
 *         script errors, a new error, broken internal links or traffic move
 *         far from the previous week's average; "check now" on Settings.
 *         Schema 1.5.0.
 *
 * 1.0.4 -Reach & polish (Phase 5). Traffic channels: every page view is
 *         classed Direct / Search / Social / AI assistants / Email / Paid /
 *         Campaign / Referral from its referrer and UTM tags (campaign tags
 *         win; hosts matched by whole labels; lists filterable through
 *         fw_ext_visitor_insights_channel_hosts) and shown first on the
 *         Overview. A dashboard widget with the week against the last, a
 *         14-day sparkline, top pages and "right now". CSV export of pages,
 *         sources, goals and daily totals (formula-safe cells). Common page
 *         caches are cleared automatically after a settings save or an A/B
 *         change (hook fw_ext_visitor_insights_purge_cache for others).
 *         Optional mouse-movement maps, desktop only, sampled and binned in
 *         the browser, as a Movement heatmap mode. Schema 1.4.0.
 *
 * 1.0.3 -Session recording (Phase 4), off by default. A visit is recorded
 *         only after opt-in consent — a WP Consent API plugin's "statistics"
 *         answer, or UnysonPlusVI.grantRecording() from the site's own banner
 *         — never under Global Privacy Control or after an opt-out, and
 *         withdrawing consent stops the recorder and deletes the recording.
 *         The rrweb recorder (vendored, MIT) loads only then, for a sampled
 *         share of consenting visits, with every form field masked in the
 *         browser (optionally all text; up-vi-mask / up-vi-block classes). A
 *         session is one tab, continued across page views via sessionStorage
 *         until 30 idle minutes. Batches are gzipped in the browser and stored
 *         as files in a locked-down folder on this site; metadata (pages,
 *         length, device, problem flags) in a table. Recordings tab: list,
 *         "only visits with problems", player, per-recording and bulk delete,
 *         a log of who watched what; playback needs its own capability
 *         filter. Retention 7–90 days; optionally keep only visits with a
 *         rage / dead / error click or script error. Visitors can delete their
 *         own recording with [visitor_insights_recording]. Schema 1.3.1.
 *
 * 1.0.2 -Form analytics, site search insights, section A/B testing, a weekly
 *         email and AI Assistant abilities (Phase 3). Forms: starts, sends and
 *         abandons per form, each field's reach and the field visitors gave
 *         up at — values are never read. Search: terms (masked) and searches
 *         that found nothing, recorded server-side. A/B: two builder sections
 *         of one page, a 50/50 choice made in <head> before paint (cache-
 *         safe, no flicker, nothing stored on the device), visitors and goal
 *         conversions per version and a two-proportion confidence; stopping
 *         keeps the winner. Weekly email (opt-in) with week-over-week totals,
 *         problems and phone vitals. Two read-only AI abilities name sections
 *         and elements from the builder data. New "Logged-in users" setting
 *         (track them except editors / nobody logged in / everyone) replaces
 *         the editors switch. Search results and plain-permalink pages are no
 *         longer counted as the home page. Schema 1.2.0.
 *
 * 1.0.1 -Goals, funnels, frustration & errors, real-visitor performance
 *         (Phase 2). Goals are counted four ways: a "Track as goal" field in
 *         every element's Advanced tab (the element is stamped
 *         data-fw-vi-goal), reaching a page (matched by the rollup), a script
 *         calling UnysonPlusVI.goal(), and automatic server-side conversions
 *         (form submitted, newsletter sign-up, shop order with its
 *         value, short-link click) written into the same buffer as seq -1
 *         lines a browser cannot forge. Only known goal keys are counted.
 *         Funnels follow today's visitor hash through ordered steps within
 *         one day; their progress rows are deleted with the salt. The
 *         frustration module reports rage clicks, dead clicks on things that
 *         look clickable, clicks followed by a script error, and masked
 *         script errors; missing pages are recorded server-side with where
 *         the visitor came from. Core Web Vitals (LCP, INP, CLS, TTFB) are
 *         measured in the browser and stored as histograms so the 75th
 *         percentile can be read for any period. New tabs: Goals, Funnels,
 *         Frustration, Performance; a Frustration heatmap mode; module
 *         switches in Settings. Schema 1.1.0.
 *
 * 1.0.0 -First release (Phase 1 of the roadmap). A small tracker starts in
 *         idle time once consent allows it and batches clicks, scroll reach,
 *         visible time and section reach to a collector that does not boot
 *         WordPress: it validates the batch against a rotating HMAC page token
 *         (no nonces, so cached pages keep working), filters bots, and appends
 *         to a per-minute buffer file. A one-minute job folds the buffer into
 *         pre-aggregated daily tables, so storage grows with pages x days, not
 *         with traffic; reports also roll up when opened. A REST route takes
 *         over automatically when the direct collector is blocked. Clicks are
 *         stored relative to the builder element they landed in (the item's
 *         unique_id is stamped as data-fw-item-id on tracked pages), so the
 *         heatmap stays accurate at every width. Unique visitors come from a
 *         salted hash whose salt is rotated and destroyed daily; no cookies,
 *         no stored IPs. Admin: overview with a daily chart and breakdowns,
 *         a pages list, a heatmap viewer (clicks, scroll reach, attention,
 *         element ranking) per device over the live page, a section report,
 *         a live view, and an admin-bar "Heatmap" toggle on the front end.
 */

$manifest['version']    = '1.0.10';
$manifest['display']    = true;
$manifest['standalone'] = true;
$manifest['thumbnail']  = 'thumbnail.svg';

// Repository Info
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-Visitor-Insights-Extension';
$manifest['github_repo']   = 'https://github.com/UnysonPlus/UnysonPlus-Visitor-Insights-Extension';
$manifest['github_branch'] = 'master';

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';
