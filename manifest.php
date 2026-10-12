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
 * 1.0.1 - Goals, funnels, frustration & errors, real-visitor performance
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

$manifest['version']    = '1.0.1';
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
