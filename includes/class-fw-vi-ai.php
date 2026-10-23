<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Lets the AI Assistant answer questions from the site's own visitor data —
 * "why is nobody clicking the Pricing button?", "which section loses mobile
 * visitors?" — by giving it two read-only abilities:
 *
 *   unysonplus/visitor-insights-overview  site totals, top pages, goals,
 *                                         problems, performance
 *   unysonplus/visitor-insights-page      one page in depth: scroll reach,
 *                                         every builder section's reach and
 *                                         attention, the most-clicked elements
 *                                         by name, frustration, goals, vitals
 *                                         (also offered in the builder panel)
 *
 * Elements and sections are named from the page's builder data, so the AI can
 * talk about "the 'Book now' button in the Pricing section" rather than ids.
 * Registered only when the AI Assistant is active; nothing here sends data
 * anywhere by itself — the AI Assistant decides which AI answers.
 */
class FW_VI_AI {

	public function __construct() {
		add_action( 'fw_ai_assistant_register_abilities', array( $this, '_register' ) );
	}

	/**
	 * @internal
	 */
	public function _register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) ) {
			return;
		}

		$perm = function () {
			return FW_VI_Settings::can_view();
		};

		fw_ai_register_ability( 'visitor-insights-overview', array(
			'label'       => __( 'Visitor Insights: site overview', 'fw' ),
			'description' => 'Read the site\'s own visitor analytics for the last N days: page views, unique visitors, average time on page, top pages, goal completions, frustrated clicks, script errors, broken internal links, site searches that found nothing, and Core Web Vitals (75th percentile, mobile and desktop). Use it to answer questions about traffic, what visitors do, and what is going wrong. All numbers are anonymous totals.',
			'input'       => array( 'days' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 30 ) ),
			'permission'  => $perm,
			'execute'     => array( __CLASS__, 'overview' ),
			'readonly'    => true,
		) );

		fw_ai_register_ability( 'visitor-insights-page', array(
			'label'       => __( 'Visitor Insights: one page', 'fw' ),
			'description' => 'Read visitor behaviour on ONE page (give post_id, or path such as "/pricing/"): views, how far down visitors scroll (share reaching 25/50/75/100 %), each builder section in order with the share of visitors who reach it and its average time in view, the most-clicked elements with their names, types and share of clicks, rage / dead / error clicks per element, goals completed on the page, form starts and abandon points, and Core Web Vitals. Use it to explain why a section or button underperforms and to suggest concrete builder changes. device: d (desktop), t (tablet), m (mobile) or "all".',
			'input'       => array(
				'post_id' => array( 'type' => 'integer' ),
				'path'    => array( 'type' => 'string' ),
				'days'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 30 ),
				'device'  => array( 'type' => 'string', 'enum' => array( 'all', 'd', 't', 'm' ), 'default' => 'all' ),
			),
			'permission'  => $perm,
			'execute'     => array( __CLASS__, 'page' ),
			'readonly'    => true,
			'panel'       => true,
		) );

		fw_ai_register_ability( 'visitor-insights-acquisition', array(
			'label'       => __( 'Visitor Insights: where visitors came from', 'fw' ),
			'description' => 'Read how visitors found the site (or ONE page, given path such as "/pricing/") over the last N days: visits per channel (direct, search, social, AI assistants, email, paid, campaign, referral), the search terms that brought them (from Google Search Console when connected, with clicks, impressions and average position; otherwise the words some search engines still pass), the referring sites and the exact referring pages when shared, the top cities and countries, new against returning visitors, entry and exit pages with bounce rates, and the share of bot traffic. For one page it also returns the pages visitors came from and went to next. Use it for questions like "where did this week\'s visitors come from?" or "which pages do search visitors leave from?". All anonymous totals.',
			'input'       => array(
				'days' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 30 ),
				'path' => array( 'type' => 'string' ),
			),
			'permission'  => $perm,
			'execute'     => array( __CLASS__, 'acquisition' ),
			'readonly'    => true,
		) );

		fw_ai_register_ability( 'visitor-insights-visits', array(
			'label'       => __( 'Visitor Insights: recent visits', 'fw' ),
			'description' => 'Read recent individual visits from the visit log (no cookies, nothing identifying): when, channel, referring site or page, search words when known, region and city, device, new or returning, and the pages in order ending with any exit link or download. Filter with channel, place (city, region or country code), keyword, referrer, entry (a page path the visit started on) or through (a page path it went through). Use it to describe typical journeys or to find visits that behaved a certain way.',
			'input'       => array(
				'days'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'default' => 7 ),
				'limit'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ),
				'channel' => array( 'type' => 'string' ),
				'place'   => array( 'type' => 'string' ),
				'keyword' => array( 'type' => 'string' ),
				'referrer' => array( 'type' => 'string' ),
				'entry'   => array( 'type' => 'string' ),
				'through' => array( 'type' => 'string' ),
			),
			'permission'  => $perm,
			'execute'     => array( __CLASS__, 'visits' ),
			'readonly'    => true,
		) );
	}

	/**
	 * @param string $path as given ("/pricing/")
	 *
	 * @return int the page row id, or 0
	 */
	private static function page_id_for( $path ) {
		global $wpdb;

		$path = '/' . ltrim( (string) $path, '/' );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$full = '' !== $base && 0 !== strpos( $path, $base . '/' ) ? $base . $path : $path;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . FW_VI_Installer::table( 'pages' ) . ' WHERE path IN (%s, %s, %s)', $full, trailingslashit( $full ), untrailingslashit( $full ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @param array $in
	 *
	 * @return array
	 */
	public static function acquisition( $in = array() ) {
		FW_VI_Rollup::run();

		$days = max( 1, min( 365, (int) ( $in['days'] ?? 30 ) ) );
		$pid  = ! empty( $in['path'] ) ? self::page_id_for( $in['path'] ) : 0;

		if ( ! empty( $in['path'] ) && ! $pid ) {
			return array( 'error' => 'No visits recorded for that path.' );
		}

		$labels   = FW_VI_Channels::labels();
		$channels = array();
		foreach ( FW_VI_Reports::breakdown( 'ch', $days, 10, $pid ) as $k => $n ) {
			$channels[ $labels[ $k ] ?? $k ] = (int) $n;
		}

		$path = $pid ? (string) FW_VI_Reports::page( $pid, $days )->path : '';
		$same = function ( $p ) use ( $path ) {
			return '' === $path || untrailingslashit( (string) $p ) === untrailingslashit( $path );
		};
		$gsc  = FW_VI_Keywords::console( $days );
		$kw   = array();
		foreach ( $gsc['rows'] as $r ) {
			if ( $same( $r[1] ) ) {
				$kw[] = array( 'term' => $r[0], 'page' => FW_VI_Keywords::show_path( $r[1] ), 'clicks' => $r[2], 'impressions' => $r[3], 'position' => $r[4] );
			}
		}
		$kw = array_slice( $kw, 0, 25 );
		$recorded = array();
		foreach ( FW_VI_Keywords::recorded( $days ) as $r ) {
			if ( $same( $r[1] ) ) {
				$recorded[] = array( 'term' => $r[0], 'page' => FW_VI_Keywords::show_path( $r[1] ), 'visits' => $r[2], 'source' => 'referrer' === $r[3] ? $r[4] : 'campaign link' );
			}
		}

		$places = array();
		foreach ( FW_VI_Reports::breakdown( 'ci', $days, 10, $pid ) as $k => $n ) {
			$c = explode( '|', (string) $k );
			$places[ implode( ', ', array_filter( array( $c[2] ?? '', $c[1] ?? '', $c[0] ?? '' ) ) ) ] = (int) $n;
		}

		$out = array(
			'days'               => $days,
			'page'               => '' !== $path ? FW_VI_Keywords::show_path( $path ) : null,
			'channels'           => $channels,
			'search_console'     => $gsc['ok'] ? array( 'from' => $gsc['from'], 'to' => $gsc['to'], 'terms' => $kw ) : ( 'off' === ( $gsc['reason'] ?? '' ) ? 'not connected (connect it in SEO → Search Console)' : 'unavailable: ' . $gsc['reason'] ),
			'search_words_recorded_on_visits' => array_slice( $recorded, 0, 25 ),
			'referring_sites'    => FW_VI_Reports::breakdown( 'ref', $days, 15, $pid ),
			'referring_pages'    => array_map( function ( $n ) { return (int) $n; }, FW_VI_Reports::breakdown( 'rp', $days, 15, $pid ) ),
			'countries'          => FW_VI_Reports::breakdown( 'cc', $days, 10, $pid ),
			'cities'             => $places,
			'new_vs_returning'   => array( 'note' => 'measured only for visitors who gave statistics consent' ) + FW_VI_Reports::breakdown( 'rt', $days, 2, $pid ),
		);

		if ( $pid ) {
			$flow               = FW_VI_Visits::next_pages( $pid, $days );
			$out['came_from']   = array_slice( $flow['prev'], 0, 10, true );
			$out['went_next']   = array_slice( $flow['next'], 0, 10, true );
		} else {
			$ends = function ( $rows ) {
				return array_map( function ( $r ) {
					return array( 'page' => FW_VI_Keywords::show_path( $r->path ), 'visits' => (int) $r->n, 'bounce_rate' => round( 100 * $r->bounces / max( 1, $r->n ) ) . '%' );
				}, $rows );
			};
			$out['entry_pages'] = $ends( FW_VI_Reports::entry_pages( $days, 10 ) );
			$out['exit_pages']  = $ends( FW_VI_Reports::exit_pages( $days, 10 ) );

			global $wpdb;
			list( $from, $to ) = FW_VI_Reports::range( $days );
			$bots              = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(n),0) FROM ' . FW_VI_Installer::table( 'bots' ) . ' WHERE day BETWEEN %s AND %s', $from, $to ) ); // phpcs:ignore WordPress.DB
			$human             = (int) FW_VI_Reports::totals( $days )['views'];
			$out['bots']       = array( 'bot_requests' => $bots, 'human_page_views' => $human, 'bot_share' => $bots ? round( 100 * $bots / ( $bots + $human ) ) . '%' : '0%' );
			$opp               = FW_VI_Keywords::opportunities( $days );
			if ( $opp ) {
				$out['search_opportunities'] = array(
					'terms_climbing'  => $opp['rising'],
					'terms_falling'   => $opp['falling'],
					'second_page'     => array_map( function ( $p ) {
						return array( 'page' => FW_VI_Keywords::show_path( $p['path'] ), 'main_term' => $p['query'], 'impressions' => $p['impressions'], 'clicks' => $p['clicks'], 'position' => $p['position'] );
					}, $opp['second'] ),
				);
			}
		}

		return $out;
	}

	/**
	 * @param array $in
	 *
	 * @return array
	 */
	public static function visits( $in = array() ) {
		global $wpdb;

		FW_VI_Rollup::run();

		$days = max( 1, min( 90, (int) ( $in['days'] ?? 7 ) ) );
		$f    = array(
			'entry' => ! empty( $in['entry'] ) ? self::page_id_for( $in['entry'] ) : 0,
			'ch'    => sanitize_key( (string) ( $in['channel'] ?? '' ) ),
			'place' => sanitize_text_field( (string) ( $in['place'] ?? '' ) ),
			'kw'    => sanitize_text_field( (string) ( $in['keyword'] ?? '' ) ),
			'ref'   => sanitize_text_field( (string) ( $in['referrer'] ?? '' ) ),
			'dev'   => '',
			'ret'   => '',
			'left'  => '',
			'q'     => sanitize_text_field( (string) ( $in['through'] ?? '' ) ),
		);
		if ( ! empty( $in['entry'] ) && ! $f['entry'] ) {
			return array( 'visits' => array(), 'note' => 'No visits recorded for that entry page.' );
		}
		// Channel names are accepted as shown ("AI assistants") or as keys ("ai").
		$labels = FW_VI_Channels::labels();
		if ( '' !== $f['ch'] && ! isset( $labels[ $f['ch'] ] ) ) {
			$f['ch'] = (string) array_search( (string) ( $in['channel'] ?? '' ), $labels, true );
		}

		$limit = max( 1, min( 50, (int) ( $in['limit'] ?? 20 ) ) );
		$rows  = $wpdb->get_results( 'SELECT * FROM ' . FW_VI_Installer::table( 'visits' ) . ' WHERE ' . FW_VI_Visits::where( $f, $days ) . ' ORDER BY started DESC LIMIT ' . $limit ); // phpcs:ignore WordPress.DB
		$paths = wp_list_pluck( (array) $wpdb->get_results( 'SELECT id, path FROM ' . FW_VI_Installer::table( 'pages' ) ), 'path', 'id' ); // phpcs:ignore WordPress.DB
		$dev   = array( 'd' => 'desktop', 't' => 'tablet', 'm' => 'mobile' );

		$out = array();
		foreach ( (array) $rows as $v ) {
			$steps = array();
			foreach ( (array) json_decode( (string) $v->steps, true ) as $st ) {
				$steps[] = 'o' === ( $st[1] ?? '' ) ? ( ( 'd' === ( $st[3] ?? '' ) ? 'downloaded ' : 'left by link to ' ) . $st[2] ) : FW_VI_Keywords::show_path( $paths[ $st[1] ] ?? '?' );
			}
			$out[] = array(
				'started'   => wp_date( 'Y-m-d H:i', (int) $v->started ),
				'seconds'   => (int) $v->last_seen - (int) $v->started,
				'channel'   => $labels[ $v->ch ] ?? $v->ch,
				'from'      => '' !== $v->rp ? 'https://' . $v->rp : ( '' !== $v->ref ? $v->ref : null ),
				'search'    => '' !== $v->kw ? $v->kw : null,
				'place'     => implode( ', ', array_filter( array( $v->city, $v->region, $v->cc ) ) ),
				'device'    => $dev[ $v->dev ] ?? $v->dev,
				'returning' => 'r' === $v->ret ? true : ( 'n' === $v->ret ? false : null ),
				'path'      => $steps,
			);
		}

		return array( 'days' => $days, 'kept_days' => (int) FW_VI_Settings::get()['visits_days'], 'visits' => $out );
	}

	/**
	 * @param array $in
	 *
	 * @return array
	 */
	public static function overview( $in = array() ) {
		global $wpdb;

		FW_VI_Rollup::run();

		$days = max( 1, min( 365, (int) ( $in['days'] ?? 30 ) ) );
		list( $from, $to ) = FW_VI_Reports::range( $days );
		$t = FW_VI_Reports::totals( $days );

		$goals = array();
		$all   = FW_VI_Goals::all();
		foreach ( FW_VI_Goals::totals( $days ) as $key => $g ) {
			$goals[] = array( 'goal' => $all[ $key ]['name'] ?? $key, 'completions' => $g['n'], 'value' => $g['value'] ? FW_VI_Goals::money( $g['value'] ) : null );
		}

		$vitals = array();
		foreach ( array( 'm' => 'mobile', 'd' => 'desktop' ) as $dev => $name ) {
			$hist = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT metric, bucket, SUM(n) n FROM ' . FW_VI_Installer::table( 'vitals' ) . ' WHERE device = %s AND day BETWEEN %s AND %s GROUP BY metric, bucket', $dev, $from, $to ) ) as $r ) { // phpcs:ignore WordPress.DB
				$hist[ $r->metric ][ $r->bucket ] = (int) $r->n;
			}
			foreach ( array_keys( FW_VI_Vitals::METRICS ) as $m ) {
				$v = FW_VI_Vitals::percentile( $m, $hist[ $m ] ?? array() );
				if ( null !== $v ) {
					$vitals[ $name ][ $m ] = array( 'p75' => FW_VI_Vitals::format( $m, $v ), 'rating' => FW_VI_Vitals::rating( $m, $v ) );
				}
			}
		}

		return array(
			'period'               => array( 'from' => $from, 'to' => $to ),
			'page_views'           => $t['views'],
			'unique_visitors'      => $t['uniques'],
			'avg_seconds_on_page'  => $t['engaged'],
			'top_pages'            => array_map( function ( $r ) {
				return array( 'path' => $r->path, 'title' => $r->title, 'views' => $r->views, 'avg_seconds' => $r->engaged, 'read_half_pct' => $r->half );
			}, FW_VI_Reports::top_pages( $days, 10 ) ),
			'referrers'            => FW_VI_Reports::breakdown( 'ref', $days, 5 ),
			'devices'              => FW_VI_Reports::breakdown( 'dev', $days, 3 ),
			'goals'                => $goals,
			'frustrated_pages'     => $wpdb->get_results( $wpdb->prepare( "SELECT p.path, SUM(IF(f.kind='r',f.n,0)) rage, SUM(IF(f.kind='d',f.n,0)) dead, SUM(IF(f.kind='e',f.n,0)) error FROM " . FW_VI_Installer::table( 'friction' ) . ' f JOIN ' . FW_VI_Installer::table( 'pages' ) . ' p ON p.id = f.page_id WHERE f.day BETWEEN %s AND %s GROUP BY p.path ORDER BY SUM(f.n) DESC LIMIT 5', $from, $to ), ARRAY_A ), // phpcs:ignore WordPress.DB
			'script_errors'        => $wpdb->get_results( $wpdb->prepare( 'SELECT m.message, SUM(e.n) times FROM ' . FW_VI_Installer::table( 'errors' ) . ' e JOIN ' . FW_VI_Installer::table( 'errmsg' ) . ' m ON m.hash = e.hash WHERE e.day BETWEEN %s AND %s GROUP BY e.hash ORDER BY times DESC LIMIT 5', $from, $to ), ARRAY_A ), // phpcs:ignore WordPress.DB
			'broken_internal_links' => $wpdb->get_results( $wpdb->prepare( 'SELECT path, SUM(n) times FROM ' . FW_VI_Installer::table( 'notfound' ) . " WHERE ref = 'internal' AND day BETWEEN %s AND %s GROUP BY path ORDER BY times DESC LIMIT 5", $from, $to ), ARRAY_A ), // phpcs:ignore WordPress.DB
			'searches_found_nothing' => $wpdb->get_results( $wpdb->prepare( 'SELECT term, SUM(zero) times FROM ' . FW_VI_Installer::table( 'search' ) . ' WHERE day BETWEEN %s AND %s GROUP BY term HAVING times > 0 ORDER BY times DESC LIMIT 10', $from, $to ), ARRAY_A ), // phpcs:ignore WordPress.DB
			'web_vitals_p75'       => $vitals,
		);
	}

	/**
	 * @param array $in
	 *
	 * @return array|WP_Error
	 */
	public static function page( $in = array() ) {
		global $wpdb;

		FW_VI_Rollup::run();

		$post = (int) ( $in['post_id'] ?? 0 );
		$path = isset( $in['path'] ) ? '/' . ltrim( (string) $in['path'], '/' ) : '';

		if ( $post && '' === $path ) {
			$url  = get_permalink( $post );
			$path = $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
		}

		$page = '' !== $path ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . FW_VI_Installer::table( 'pages' ) . ' WHERE path = %s', $path ) ) : null; // phpcs:ignore WordPress.DB

		if ( ! $page ) {
			return new WP_Error( 'fw_vi_no_data', 'No visitor data for this page yet (it may be new, untracked, or have had no visits in the period).' );
		}

		$post  = $post ? $post : (int) $page->post_id;
		$days  = max( 1, min( 365, (int) ( $in['days'] ?? 30 ) ) );
		$dev   = in_array( $in['device'] ?? 'all', array( 'd', 't', 'm' ), true ) ? $in['device'] : '';
		$items = $post ? FW_VI_Sections::items_of( $post ) : array();
		list( $from, $to ) = FW_VI_Reports::range( $days );

		$where = $wpdb->prepare( 'page_id = %d AND day BETWEEN %s AND %s', $page->id, $from, $to ) . ( '' !== $dev ? $wpdb->prepare( ' AND device = %s', $dev ) : '' );
		$views = (int) $wpdb->get_var( 'SELECT COALESCE(SUM(views),0) FROM ' . FW_VI_Installer::table( 'views' ) . " WHERE {$where}" ); // phpcs:ignore WordPress.DB
		$name  = function ( $id ) use ( $items ) {
			return isset( $items[ $id ] ) ? array( 'type' => $items[ $id ]['type'], 'label' => $items[ $id ]['label'] ) : array( 'type' => 'unknown', 'label' => '' );
		};
		$pct   = function ( $n ) use ( $views ) {
			return $views ? (int) min( 100, round( 100 * $n / $views ) ) : 0;
		};

		$scroll = array();
		foreach ( $wpdb->get_results( 'SELECT band, SUM(n) n FROM ' . FW_VI_Installer::table( 'scroll' ) . " WHERE {$where} AND band IN (25,50,75,100) GROUP BY band" ) as $r ) { // phpcs:ignore WordPress.DB
			$scroll[ $r->band . '%' ] = $pct( $r->n ) . '%';
		}

		$secrows = $wpdb->get_results( 'SELECT item, SUM(reached) r, SUM(visible_ms) ms FROM ' . FW_VI_Installer::table( 'sections' ) . " WHERE {$where} GROUP BY item", OBJECT_K ); // phpcs:ignore WordPress.DB
		$sections = array();
		foreach ( $items as $id => $it ) {
			if ( $it['section'] ) {
				$r          = $secrows[ $id ] ?? null;
				$sections[] = array(
					'label'          => $it['label'],
					'reached_pct'    => $r ? $pct( $r->r ) : 0,
					'avg_seconds_in_view' => $r && $r->r ? round( $r->ms / $r->r / 1000, 1 ) : 0,
				);
			}
		}

		$clicks = $wpdb->get_results( 'SELECT item, SUM(n) n FROM ' . FW_VI_Installer::table( 'elem' ) . " WHERE {$where} GROUP BY item ORDER BY n DESC" ); // phpcs:ignore WordPress.DB
		$total  = max( 1, array_sum( array_map( 'intval', wp_list_pluck( $clicks, 'n' ) ) ) + (int) $wpdb->get_var( 'SELECT COALESCE(SUM(n),0) FROM ' . FW_VI_Installer::table( 'clicks' ) . " WHERE {$where}" ) ); // phpcs:ignore WordPress.DB
		$top    = array();
		foreach ( array_slice( $clicks, 0, 15 ) as $c ) {
			$n     = $name( $c->item );
			$top[] = array(
				'element'        => $n['label'],
				'type'           => $n['type'],
				'in_section'     => isset( $items[ $c->item ] ) && $items[ $c->item ]['parent'] && isset( $items[ $items[ $c->item ]['parent'] ] ) ? $items[ $items[ $c->item ]['parent'] ]['label'] : '',
				'share_of_clicks' => round( 100 * $c->n / $total, 1 ) . '%',
			);
		}

		$friction = array();
		foreach ( $wpdb->get_results( 'SELECT item, kind, SUM(n) n FROM ' . FW_VI_Installer::table( 'friction' ) . " WHERE {$where} GROUP BY item, kind" ) as $f ) { // phpcs:ignore WordPress.DB
			$n           = $name( $f->item );
			$friction[]  = array( 'element' => '' !== $f->item ? $n['label'] : '(outside any element)', 'type' => $n['type'], 'kind' => array( 'r' => 'rage', 'd' => 'dead', 'e' => 'error' )[ $f->kind ], 'count' => (int) $f->n );
		}

		$goals = array();
		$all   = FW_VI_Goals::all();
		foreach ( $wpdb->get_results( 'SELECT goal, SUM(n) n FROM ' . FW_VI_Installer::table( 'goals' ) . " WHERE {$where} GROUP BY goal" ) as $g ) { // phpcs:ignore WordPress.DB
			$goals[] = array( 'goal' => $all[ $g->goal ]['name'] ?? $g->goal, 'completions' => (int) $g->n );
		}

		$fwhere = $wpdb->prepare( 'page_id = %d AND day BETWEEN %s AND %s', $page->id, $from, $to );
		$forms  = $wpdb->get_results( 'SELECT form, MAX(label) label, SUM(starts) starts, SUM(submits) sent, SUM(abandons) abandoned FROM ' . FW_VI_Installer::table( 'forms' ) . " WHERE {$fwhere} GROUP BY form", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $forms as &$form ) {
			$form['gave_up_most_at'] = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT field FROM ' . FW_VI_Installer::table( 'fields' ) . " WHERE {$fwhere} AND form = %s GROUP BY field ORDER BY SUM(lastn) DESC LIMIT 1", $form['form'] ) ); // phpcs:ignore WordPress.DB
			unset( $form['form'] );
		}
		unset( $form );

		$hist = array();
		foreach ( $wpdb->get_results( 'SELECT metric, bucket, SUM(n) n FROM ' . FW_VI_Installer::table( 'vitals' ) . " WHERE {$where} GROUP BY metric, bucket" ) as $r ) { // phpcs:ignore WordPress.DB
			$hist[ $r->metric ][ $r->bucket ] = (int) $r->n;
		}
		$vitals = array();
		foreach ( array_keys( FW_VI_Vitals::METRICS ) as $m ) {
			$v = FW_VI_Vitals::percentile( $m, $hist[ $m ] ?? array() );
			if ( null !== $v ) {
				$vitals[ $m ] = array( 'p75' => FW_VI_Vitals::format( $m, $v ), 'rating' => FW_VI_Vitals::rating( $m, $v ) );
			}
		}

		return array(
			'page'                   => array( 'path' => $page->path, 'title' => $post ? get_the_title( $post ) : '', 'post_id' => $post ),
			'period'                 => array( 'from' => $from, 'to' => $to, 'device' => '' !== $dev ? $dev : 'all' ),
			'page_views'             => $views,
			'scroll_reach'           => $scroll,
			'sections_in_order'      => $sections,
			'most_clicked_elements'  => $top,
			'frustration'            => $friction,
			'goals_completed_here'   => $goals,
			'forms'                  => $forms,
			'web_vitals_p75'         => $vitals,
		);
	}
}
