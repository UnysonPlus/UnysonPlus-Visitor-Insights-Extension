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

	const DB_VERSION        = '1.1.0';
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
