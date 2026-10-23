<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The only DDL.
 *
 * Every report table is pre-aggregated and keyed `(page_id, day, …)`, so a
 * report is one range scan on the primary key and the size of a table follows
 * pages × days, not traffic. Writes are `INSERT … ON DUPLICATE KEY UPDATE`
 * from the rollup; nothing writes a row per event.
 *
 * `page_id` 0 holds site-wide totals where a per-page sum would be wrong
 * (unique visitors). Same dbDelta() contract as the other installers: two
 * spaces after PRIMARY KEY, one field per line, `KEY` not `INDEX`.
 */
class FW_VI_Installer {

	const DB_VERSION        = '1.6.0';
	const DB_VERSION_OPTION = 'fw_ext_visitor_insights_db_version';

	const TABLES = array(
		'pages'    => 'fw_vi_pages',
		'views'    => 'fw_vi_views',
		'sources'  => 'fw_vi_sources',
		'elem'     => 'fw_vi_elem',
		'clicks'   => 'fw_vi_clicks',
		'scroll'   => 'fw_vi_scroll',
		'sections' => 'fw_vi_sections',
		'uniq'     => 'fw_vi_uniq',
		'live'     => 'fw_vi_live',
		// 1.1.0 — Phase 2
		'goals'     => 'fw_vi_goals',
		'fprogress' => 'fw_vi_funnel_progress',
		'fdaily'    => 'fw_vi_funnel_daily',
		'friction'  => 'fw_vi_friction',
		'errors'    => 'fw_vi_errors',
		'errmsg'    => 'fw_vi_error_msgs',
		'notfound'  => 'fw_vi_404',
		'vitals'    => 'fw_vi_vitals',
		// 1.2.0 — Phase 3
		'forms'     => 'fw_vi_forms',
		'fields'    => 'fw_vi_form_fields',
		'search'    => 'fw_vi_search',
		'xassign'   => 'fw_vi_exp_assign',
		'xdaily'    => 'fw_vi_exp_daily',
		// 1.3.0 — Phase 4
		'recs'      => 'fw_vi_recordings',
		// 1.4.0 — Phase 5
		'moves'     => 'fw_vi_moves',
		// 1.5.0 — Phase 6
		'chdaily'   => 'fw_vi_chdaily',
		// 1.6.0 — Phase 7
		'hmch'      => 'fw_vi_hmch',
	);

	/**
	 * @param string $which
	 *
	 * @return string
	 */
	public static function table( $which ) {
		global $wpdb;

		return $wpdb->prefix . self::TABLES[ $which ];
	}

	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$c = $wpdb->get_charset_collate();
		$t = array();

		foreach ( array_keys( self::TABLES ) as $k ) {
			$t[ $k ] = self::table( $k );
		}

		// One row per tracked URL path. `path` is the request path without the
		// query string; `post_id` is 0 for archives, search and the like.
		dbDelta( "CREATE TABLE {$t['pages']} (
	id bigint(20) unsigned NOT NULL auto_increment,
	post_id bigint(20) unsigned NOT NULL default 0,
	path varchar(191) NOT NULL default '',
	created date NOT NULL default '1970-01-01',
	PRIMARY KEY  (id),
	UNIQUE KEY path (path),
	KEY post_id (post_id)
) {$c};" );

		// device: d / t / m. engaged_ms = visible time while the tab was in front.
		dbDelta( "CREATE TABLE {$t['views']} (
	page_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '1970-01-01',
	device char(1) NOT NULL default 'd',
	views int(10) unsigned NOT NULL default 0,
	uniques int(10) unsigned NOT NULL default 0,
	engaged_ms bigint(20) unsigned NOT NULL default 0,
	PRIMARY KEY  (page_id,day,device),
	KEY day (day)
) {$c};" );

		// dim: ref (referrer host), us / um / uc (UTM source / medium / campaign),
		// cc (country), br (browser), os, dev.
		dbDelta( "CREATE TABLE {$t['sources']} (
	page_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '1970-01-01',
	dim varchar(8) NOT NULL default '',
	val varchar(150) NOT NULL default '',
	n int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (page_id,day,dim,val),
	KEY day_dim (day,dim)
) {$c};" );

		// Clicks inside a builder item: ox / oy are 10% cells of the item's box.
		dbDelta( "CREATE TABLE {$t['elem']} (
	page_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '1970-01-01',
	device char(1) NOT NULL default 'd',
	item char(32) NOT NULL default '',
	ox tinyint(3) unsigned NOT NULL default 0,
	oy tinyint(3) unsigned NOT NULL default 0,
	n int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (page_id,day,device,item,ox,oy)
) {$c};" );

		// Clicks outside any builder item: cx = % of page width, cy = 20px rows.
		dbDelta( "CREATE TABLE {$t['clicks']} (
	page_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '1970-01-01',
	device char(1) NOT NULL default 'd',
	cx tinyint(3) unsigned NOT NULL default 0,
	cy smallint(5) unsigned NOT NULL default 0,
	n int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (page_id,day,device,cx,cy)
) {$c};" );

		// band = 0, 5 … 100: page views that reached at least this % of the page.
		dbDelta( "CREATE TABLE {$t['scroll']} (
	page_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '1970-01-01',
	device char(1) NOT NULL default 'd',
	band tinyint(3) unsigned NOT NULL default 0,
	n int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (page_id,day,device,band)
) {$c};" );

		dbDelta( "CREATE TABLE {$t['sections']} (
	page_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '1970-01-01',
	device char(1) NOT NULL default 'd',
	item char(32) NOT NULL default '',
	reached int(10) unsigned NOT NULL default 0,
	visible_ms bigint(20) unsigned NOT NULL default 0,
	PRIMARY KEY  (page_id,day,device,item)
) {$c};" );

		// Today's visitor hashes, only to count each visitor once a day. Rows
		// older than today are deleted nightly, together with the salt that
		// made them, so yesterday's hashes cannot be recomputed or linked.
		dbDelta( "CREATE TABLE {$t['uniq']} (
	day date NOT NULL default '1970-01-01',
	page_id bigint(20) unsigned NOT NULL default 0,
	vh char(16) NOT NULL default '',
	PRIMARY KEY  (day,page_id,vh)
) {$c};" );

		// Page views seen in the last few minutes, for the live view. `vid` is a
		// random id made per page view in the browser; it is never stored again.
		dbDelta( "CREATE TABLE {$t['live']} (
	vid char(16) NOT NULL default '',
	page_id bigint(20) unsigned NOT NULL default 0,
	device char(1) NOT NULL default 'd',
	vh char(16) NOT NULL default '',
	ch varchar(10) NOT NULL default '',
	seen int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (vid),
	KEY seen (seen)
) {$c};" );

		// --- 1.1.0: goals, funnels, frustration, errors, 404s, Web Vitals ------

		// Goal completions. `value` is in the smallest currency unit (cents) for
		// goals that carry one (orders); 0 otherwise.
		dbDelta( "CREATE TABLE {$t['goals']} (
	page_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '1970-01-01',
	device char(1) NOT NULL default 'd',
	goal varchar(40) NOT NULL default '',
	n int(10) unsigned NOT NULL default 0,
	value bigint(20) unsigned NOT NULL default 0,
	PRIMARY KEY  (page_id,day,device,goal),
	KEY day_goal (day,goal)
) {$c};" );

		// How far each of today's visitor hashes got through each funnel. Same
		// life as fw_vi_uniq: deleted with the salt the next day.
		dbDelta( "CREATE TABLE {$t['fprogress']} (
	day date NOT NULL default '1970-01-01',
	funnel varchar(20) NOT NULL default '',
	vh char(16) NOT NULL default '',
	step tinyint(3) unsigned NOT NULL default 0,
	PRIMARY KEY  (day,funnel,vh)
) {$c};" );

		dbDelta( "CREATE TABLE {$t['fdaily']} (
	day date NOT NULL default '1970-01-01',
	funnel varchar(20) NOT NULL default '',
	step tinyint(3) unsigned NOT NULL default 0,
	n int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (day,funnel,step)
) {$c};" );

		// kind: r = rage click, d = dead click, e = click followed by a script error.
		// item '' = outside any builder item.
		dbDelta( "CREATE TABLE {$t['friction']} (
	page_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '1970-01-01',
	device char(1) NOT NULL default 'd',
	item varchar(32) NOT NULL default '',
	kind char(1) NOT NULL default 'r',
	n int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (page_id,day,device,item,kind)
) {$c};" );

		dbDelta( "CREATE TABLE {$t['errors']} (
	page_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '1970-01-01',
	hash char(16) NOT NULL default '',
	n int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (page_id,day,hash),
	KEY day (day)
) {$c};" );

		dbDelta( "CREATE TABLE {$t['errmsg']} (
	hash char(16) NOT NULL default '',
	message varchar(191) NOT NULL default '',
	source varchar(191) NOT NULL default '',
	line int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (hash)
) {$c};" );

		// ref: the referring host, 'internal' for a link on this site, '' for none.
		dbDelta( "CREATE TABLE {$t['notfound']} (
	day date NOT NULL default '1970-01-01',
	path varchar(191) NOT NULL default '',
	ref varchar(100) NOT NULL default '',
	n int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (day,path,ref)
) {$c};" );

		// A histogram per metric, so the 75th percentile can be read back for any
		// range. metric: lcp / inp / ttfb (bucket = 100 / 25 / 100 ms), cls
		// (bucket = 0.01). The top bucket holds everything above it.
		dbDelta( "CREATE TABLE {$t['vitals']} (
	page_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '1970-01-01',
	device char(1) NOT NULL default 'd',
	metric varchar(4) NOT NULL default '',
	bucket smallint(5) unsigned NOT NULL default 0,
	n int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (page_id,day,device,metric,bucket)
) {$c};" );

		// --- 1.2.0: forms, search, experiments ----------------------------------

		// One row per form per page and day. `form` is the builder item id of
		// the form (or its id / name attribute); `label` is the nearest heading.
		dbDelta( "CREATE TABLE {$t['forms']} (
	page_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '1970-01-01',
	form varchar(40) NOT NULL default '',
	label varchar(100) NOT NULL default '',
	starts int(10) unsigned NOT NULL default 0,
	submits int(10) unsigned NOT NULL default 0,
	abandons int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (page_id,day,form)
) {$c};" );

		// touched = page views that focused the field; lastn = abandoned forms
		// whose last focused field was this one. pos = the field's order.
		dbDelta( "CREATE TABLE {$t['fields']} (
	page_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '1970-01-01',
	form varchar(40) NOT NULL default '',
	field varchar(40) NOT NULL default '',
	pos smallint(5) unsigned NOT NULL default 0,
	touched int(10) unsigned NOT NULL default 0,
	lastn int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (page_id,day,form,field)
) {$c};" );

		dbDelta( "CREATE TABLE {$t['search']} (
	day date NOT NULL default '1970-01-01',
	term varchar(80) NOT NULL default '',
	n int(10) unsigned NOT NULL default 0,
	zero int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (day,term),
	KEY day (day)
) {$c};" );

		// Which variant each of today's visitor hashes saw last ('a' / 'b'),
		// which ones it saw at all, and whether it converted. Deleted with the
		// salt, like funnel progress.
		dbDelta( "CREATE TABLE {$t['xassign']} (
	day date NOT NULL default '1970-01-01',
	exp varchar(20) NOT NULL default '',
	vh char(16) NOT NULL default '',
	last char(1) NOT NULL default 'a',
	seen varchar(2) NOT NULL default '',
	converted tinyint(1) unsigned NOT NULL default 0,
	PRIMARY KEY  (day,exp,vh)
) {$c};" );

		dbDelta( "CREATE TABLE {$t['xdaily']} (
	day date NOT NULL default '1970-01-01',
	exp varchar(20) NOT NULL default '',
	variant char(1) NOT NULL default 'a',
	visitors int(10) unsigned NOT NULL default 0,
	conversions int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (day,exp,variant)
) {$c};" );

		// --- 1.3.0: session recordings (metadata; the events are files) -------

		// One row per recorded session (a browser tab, ends after 30 idle
		// minutes). flags: r rage, d dead, e error click, x script error, g goal.
		dbDelta( "CREATE TABLE {$t['recs']} (
	sid char(24) NOT NULL default '',
	started int(10) unsigned NOT NULL default 0,
	last_seen int(10) unsigned NOT NULL default 0,
	device char(1) NOT NULL default 'd',
	country char(2) NOT NULL default '',
	first_path varchar(191) NOT NULL default '',
	paths text NULL,
	pages smallint(5) unsigned NOT NULL default 0,
	chunks smallint(5) unsigned NOT NULL default 0,
	bytes int(10) unsigned NOT NULL default 0,
	flags varchar(10) NOT NULL default '',
	ev_first bigint(20) unsigned NOT NULL default 0,
	ev_last bigint(20) unsigned NOT NULL default 0,
	PRIMARY KEY  (sid),
	KEY last_seen (last_seen)
) {$c};" );

		// --- 1.4.0: pointer movement --------------------------------------------

		// item = builder item (a, b = its 10% cell) or '' (a = % of page width,
		// b = 20px row). n = sampled positions.
		dbDelta( "CREATE TABLE {$t['moves']} (
	page_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '1970-01-01',
	device char(1) NOT NULL default 'd',
	item varchar(32) NOT NULL default '',
	a smallint(5) unsigned NOT NULL default 0,
	b smallint(5) unsigned NOT NULL default 0,
	n int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (page_id,day,device,item,a,b)
) {$c};" );

		// --- 1.5.0: channel performance ------------------------------------------

		// half = page views that scrolled at least half-way; goals = goal
		// completions credited to the channel of the view they happened in.
		dbDelta( "CREATE TABLE {$t['chdaily']} (
	page_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '1970-01-01',
	ch varchar(10) NOT NULL default '',
	views int(10) unsigned NOT NULL default 0,
	engaged_ms bigint(20) unsigned NOT NULL default 0,
	half int(10) unsigned NOT NULL default 0,
	goals int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (page_id,day,ch)
) {$c};" );

		// --- 1.6.0: heatmaps by channel --------------------------------------------

		// Every heatmap signal again, keyed by the visit's channel, so a map can
		// be drawn for one channel. kind: v view · e click in item (a, b = cell)
		// · c click on page grid (a = %, b = row) · s scroll band (a) · r section
		// reached · t section in view (n = ms) · m movement · f frustration
		// (a = 1 rage, 2 dead, 3 error).
		dbDelta( "CREATE TABLE {$t['hmch']} (
	page_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '1970-01-01',
	device char(1) NOT NULL default 'd',
	ch varchar(10) NOT NULL default '',
	kind char(1) NOT NULL default 'v',
	item varchar(32) NOT NULL default '',
	a smallint(5) unsigned NOT NULL default 0,
	b smallint(5) unsigned NOT NULL default 0,
	n bigint(20) unsigned NOT NULL default 0,
	PRIMARY KEY  (page_id,day,device,ch,kind,item,a,b)
) {$c};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * "Remove all data" on the Settings tab. Deactivation never calls this.
	 */
	public static function uninstall() {
		global $wpdb;

		foreach ( array_keys( self::TABLES ) as $k ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $k ) ); // phpcs:ignore WordPress.DB
		}

		delete_option( self::DB_VERSION_OPTION );
	}
}
