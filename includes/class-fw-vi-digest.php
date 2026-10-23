<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The weekly email: last week compared with the week before, and the
 * problems worth a look — sent Monday morning (site time) when switched on in
 * Settings. "Send a test email now" on the Settings tab sends it at once.
 *
 * Built from the same tables as the screens; nothing about any one visitor is
 * in it.
 */
class FW_VI_Digest {

	const CRON = 'fw_ext_vi_digest';

	public function __construct() {
		add_action( self::CRON, array( __CLASS__, 'send' ) );
		add_action( 'init', array( $this, '_action_schedule' ) );
	}

	/**
	 * Keep the weekly event in step with the setting.
	 *
	 * @internal
	 */
	public function _action_schedule() {
		$on   = (bool) FW_VI_Settings::get()['digest'];
		$next = wp_next_scheduled( self::CRON );

		if ( $on && ! $next ) {
			$monday = new DateTime( 'next monday 08:00', wp_timezone() );
			wp_schedule_event( $monday->getTimestamp(), 'weekly', self::CRON );
		} elseif ( ! $on && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/**
	 * @return string[]
	 */
	public static function recipients() {
		$to = array_filter( FW_VI_Settings::lines( FW_VI_Settings::get()['digest_to'] ), 'is_email' );

		return $to ? $to : array( get_option( 'admin_email' ) );
	}

	/**
	 * Totals for a closed range of days.
	 */
	private static function week( $from, $to ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COALESCE(SUM(views),0) v, COALESCE(SUM(uniques),0) u FROM ' . FW_VI_Installer::table( 'views' ) . ' WHERE page_id = 0 AND day BETWEEN %s AND %s', $from, $to ) ); // phpcs:ignore WordPress.DB
		$g   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(n),0) FROM ' . FW_VI_Installer::table( 'goals' ) . ' WHERE day BETWEEN %s AND %s', $from, $to ) ); // phpcs:ignore WordPress.DB

		return array( 'views' => (int) $row->v, 'uniques' => (int) $row->u, 'goals' => $g );
	}

	/**
	 * @return array { subject, html }
	 */
	public static function compose() {
		global $wpdb;

		$to    = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
		$from  = wp_date( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );
		$pto   = wp_date( 'Y-m-d', time() - 8 * DAY_IN_SECONDS );
		$pfrom = wp_date( 'Y-m-d', time() - 14 * DAY_IN_SECONDS );
		$now   = self::week( $from, $to );
		$prev  = self::week( $pfrom, $pto );

		$delta = function ( $a, $b ) {
			if ( ! $b ) {
				return $a ? '<span style="color:#00a32a">new</span>' : '';
			}
			$d = 100 * ( $a - $b ) / $b;
			return sprintf( '<span style="color:%s">%s%s%%</span>', $d >= 0 ? '#00a32a' : '#d63638', $d >= 0 ? '+' : '', number_format_i18n( $d, 0 ) );
		};

		$top = $wpdb->get_results( $wpdb->prepare(
			'SELECT p.path, SUM(v.views) n FROM ' . FW_VI_Installer::table( 'views' ) . ' v JOIN ' . FW_VI_Installer::table( 'pages' ) . ' p ON p.id = v.page_id
			WHERE v.page_id > 0 AND v.day BETWEEN %s AND %s GROUP BY p.path ORDER BY n DESC LIMIT 5',
			$from, $to
		) ); // phpcs:ignore WordPress.DB

		$friction = $wpdb->get_results( $wpdb->prepare(
			'SELECT p.path, SUM(f.n) n FROM ' . FW_VI_Installer::table( 'friction' ) . ' f JOIN ' . FW_VI_Installer::table( 'pages' ) . ' p ON p.id = f.page_id
			WHERE f.day BETWEEN %s AND %s GROUP BY p.path ORDER BY n DESC LIMIT 3',
			$from, $to
		) ); // phpcs:ignore WordPress.DB

		$errors = $wpdb->get_results( $wpdb->prepare(
			'SELECT m.message, SUM(e.n) n FROM ' . FW_VI_Installer::table( 'errors' ) . ' e JOIN ' . FW_VI_Installer::table( 'errmsg' ) . ' m ON m.hash = e.hash
			WHERE e.day BETWEEN %s AND %s GROUP BY e.hash ORDER BY n DESC LIMIT 3',
			$from, $to
		) ); // phpcs:ignore WordPress.DB

		$broken = $wpdb->get_results( $wpdb->prepare(
			'SELECT path, SUM(n) n FROM ' . FW_VI_Installer::table( 'notfound' ) . " WHERE ref = 'internal' AND day BETWEEN %s AND %s GROUP BY path ORDER BY n DESC LIMIT 5",
			$from, $to
		) ); // phpcs:ignore WordPress.DB

		$vitals = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT metric, bucket, SUM(n) n FROM ' . FW_VI_Installer::table( 'vitals' ) . " WHERE device = 'm' AND day BETWEEN %s AND %s GROUP BY metric, bucket", $from, $to ) ) as $r ) { // phpcs:ignore WordPress.DB
			$vitals[ $r->metric ][ $r->bucket ] = (int) $r->n;
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$h2   = 'style="font:600 15px/1.3 -apple-system,Segoe UI,Roboto,sans-serif;margin:24px 0 8px"';
		$td   = 'style="padding:6px 0;border-bottom:1px solid #eee;font:14px/1.4 -apple-system,Segoe UI,Roboto,sans-serif"';
		$list = function ( $rows, $label ) use ( $td ) {
			$out = '<table width="100%" cellpadding="0" cellspacing="0">';
			foreach ( $rows as $r ) {
				$out .= '<tr><td ' . $td . '>' . esc_html( $r->$label ) . '</td><td ' . $td . ' align="right">' . esc_html( number_format_i18n( $r->n ) ) . '</td></tr>';
			}
			return $out . '</table>';
		};

		$html  = '<div style="max-width:600px;margin:0 auto;color:#1d2327">';
		$html .= '<h1 style="font:600 20px/1.3 -apple-system,Segoe UI,Roboto,sans-serif">' . esc_html( sprintf( __( '%s — your week in visitors', 'fw' ), $site ) ) . '</h1>';
		$html .= '<p style="color:#646970;font:13px sans-serif">' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $from ) ) . ' – ' . date_i18n( get_option( 'date_format' ), strtotime( $to ) ) ) . '</p>';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0"><tr>';
		foreach ( array( 'views' => __( 'Page views', 'fw' ), 'uniques' => __( 'Visitors', 'fw' ), 'goals' => __( 'Goal completions', 'fw' ) ) as $k => $l ) {
			$html .= '<td style="padding:12px;border:1px solid #eee;font:13px sans-serif"><div style="color:#646970">' . esc_html( $l ) . '</div><div style="font:600 24px sans-serif">' . esc_html( number_format_i18n( $now[ $k ] ) ) . '</div>' . $delta( $now[ $k ], $prev[ $k ] ) . '</td>';
		}
		$html .= '</tr></table>';

		if ( ! $now['views'] ) {
			$html .= '<p style="background:#fcf9e8;border-left:4px solid #dba617;padding:10px 12px;font:14px sans-serif">' . esc_html__( 'No visits were recorded last week. If the site had visitors, check that Visitor Insights is collecting (Settings → Status) and that a page cache or security plugin is not blocking it.', 'fw' ) . '</p>';
		}

		if ( $top ) {
			$html .= '<h2 ' . $h2 . '>' . esc_html__( 'Most visited', 'fw' ) . '</h2>' . $list( $top, 'path' );
		}

		$found = self::found( $from, $to, $pfrom, $pto );
		if ( $found['kw'] || $found['new_refs'] || $found['cities'] || $found['bots'] ) {
			$html .= '<h2 ' . $h2 . '>' . esc_html__( 'How visitors found you', 'fw' ) . '</h2>';
			if ( $found['kw'] ) {
				$html .= '<p style="font:13px sans-serif;color:#646970">' . esc_html( $found['kw_source'] ) . '</p>' . $list( $found['kw'], 'label' );
			}
			if ( $found['new_refs'] ) {
				$html .= '<p style="font:13px sans-serif;color:#646970">' . esc_html__( 'Pages and sites that sent visitors for the first time:', 'fw' ) . '</p>' . $list( $found['new_refs'], 'label' );
			}
			if ( $found['cities'] ) {
				$html .= '<p style="font:13px sans-serif;color:#646970">' . esc_html__( 'Places that grew most (page views, against the week before):', 'fw' ) . '</p>' . $list( $found['cities'], 'label' );
			}
			if ( $found['bots'] ) {
				/* translators: 1: bot requests, 2: share of all requests */
				$html .= '<p style="font:13px sans-serif;color:#646970">' . esc_html( sprintf( __( 'Bots: %1$s requests, %2$s%% of all traffic, filtered out of the figures above.', 'fw' ), number_format_i18n( $found['bots'] ), number_format_i18n( $found['bot_share'] ) ) ) . '</p>';
			}
		}

		if ( $friction || $errors || $broken ) {
			$html .= '<h2 ' . $h2 . '>' . esc_html__( 'Worth a look', 'fw' ) . '</h2>';
			if ( $broken ) {
				$html .= '<p style="font:13px sans-serif;color:#646970">' . esc_html__( 'Links on your site that lead nowhere:', 'fw' ) . '</p>' . $list( $broken, 'path' );
			}
			if ( $friction ) {
				$html .= '<p style="font:13px sans-serif;color:#646970">' . esc_html__( 'Pages where visitors clicked in frustration:', 'fw' ) . '</p>' . $list( $friction, 'path' );
			}
			if ( $errors ) {
				$html .= '<p style="font:13px sans-serif;color:#646970">' . esc_html__( 'Script errors visitors ran into:', 'fw' ) . '</p>' . $list( $errors, 'message' );
			}
		}

		if ( $vitals ) {
			$html .= '<h2 ' . $h2 . '>' . esc_html__( 'Speed on phones (75th percentile)', 'fw' ) . '</h2><p style="font:14px sans-serif">';
			foreach ( array( 'lcp' => __( 'Loading', 'fw' ), 'inp' => __( 'Responsiveness', 'fw' ), 'cls' => __( 'Layout stability', 'fw' ) ) as $m => $l ) {
				$v = FW_VI_Vitals::percentile( $m, $vitals[ $m ] ?? array() );
				if ( null !== $v ) {
					$c     = array( 'good' => '#00a32a', 'ni' => '#996800', 'poor' => '#d63638' )[ FW_VI_Vitals::rating( $m, $v ) ];
					$html .= esc_html( $l ) . ': <strong style="color:' . $c . '">' . esc_html( FW_VI_Vitals::format( $m, $v ) ) . '</strong> &nbsp; ';
				}
			}
			$html .= '</p>';
		}

		$html .= '<p style="margin-top:28px"><a href="' . esc_url( admin_url( 'admin.php?page=fw-visitor-insights' ) ) . '" style="background:#2271b1;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;font:600 14px sans-serif">' . esc_html__( 'Open Visitor Insights', 'fw' ) . '</a></p>';
		$html .= '<p style="color:#8c8f94;font:12px sans-serif;margin-top:24px">' . esc_html__( 'Sent by Unyson+ Visitor Insights. Switch it off under Visitor Insights → Settings → Weekly email & alerts.', 'fw' ) . '</p></div>';

		return array(
			'subject' => sprintf( __( '[%1$s] Your week: %2$s page views', 'fw' ), $site, number_format_i18n( $now['views'] ) ),
			'html'    => $html,
		);
	}

	/**
	 * The "How visitors found you" part: the week's top search terms (Search
	 * Console when connected, else the words recorded on visits), referring
	 * pages and sites not seen in the four weeks before, the places that grew,
	 * and the bot share.
	 *
	 * @return array
	 */
	public static function found( $from, $to, $pfrom, $pto ) {
		global $wpdb;

		$s   = FW_VI_Installer::table( 'sources' );
		$row = function ( $label, $n ) {
			return (object) array( 'label' => $label, 'n' => (int) $n );
		};
		$out = array( 'kw' => array(), 'kw_source' => '', 'new_refs' => array(), 'cities' => array(), 'bots' => 0, 'bot_share' => 0 );

		$gsc = FW_VI_Keywords::console_range( $from, $to );
		if ( $gsc['ok'] && $gsc['rows'] ) {
			$q = array();
			foreach ( $gsc['rows'] as $r ) {
				$q[ $r[0] ] = ( $q[ $r[0] ] ?? 0 ) + $r[2];
			}
			arsort( $q );
			foreach ( array_slice( array_filter( $q ), 0, 5, true ) as $k => $n ) {
				$out['kw'][] = $row( $k, $n );
			}
			$out['kw_source'] = __( 'Top search terms (Search Console clicks; it runs a few days behind):', 'fw' );
		}
		if ( ! $out['kw'] ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT dim, val, SUM(n) n FROM {$s} WHERE dim IN ('kw','ut') AND day BETWEEN %s AND %s GROUP BY dim, val ORDER BY n DESC LIMIT 5", $from, $to ) ) as $r ) { // phpcs:ignore WordPress.DB
				$parts       = explode( '|', (string) $r->val, 2 );
				$out['kw'][] = $row( 'kw' === $r->dim ? ( $parts[1] ?? $parts[0] ) : $r->val, $r->n );
			}
			$out['kw_source'] = __( 'Search words recorded on visits:', 'fw' );
		}

		$before = wp_date( 'Y-m-d', strtotime( $from ) - 28 * DAY_IN_SECONDS );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT a.val, SUM(a.n) n FROM {$s} a WHERE a.dim = %s AND a.day BETWEEN %s AND %s
			 AND NOT EXISTS ( SELECT 1 FROM {$s} b WHERE b.dim = a.dim AND b.val = a.val AND b.day BETWEEN %s AND %s )
			 GROUP BY a.val ORDER BY n DESC LIMIT 5",
			'rp', $from, $to, $before, wp_date( 'Y-m-d', strtotime( $from ) - DAY_IN_SECONDS )
		) ) as $r ) {
			$out['new_refs'][] = $row( 'https://' . $r->val, $r->n );
		}
		if ( ! $out['new_refs'] ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT a.val, SUM(a.n) n FROM {$s} a WHERE a.dim = 'ref' AND a.day BETWEEN %s AND %s
				 AND NOT EXISTS ( SELECT 1 FROM {$s} b WHERE b.dim = 'ref' AND b.val = a.val AND b.day BETWEEN %s AND %s )
				 GROUP BY a.val ORDER BY n DESC LIMIT 5",
				$from, $to, $before, wp_date( 'Y-m-d', strtotime( $from ) - DAY_IN_SECONDS )
			) ) as $r ) {
				$out['new_refs'][] = $row( $r->val, $r->n );
			}
		}

		$now  = wp_list_pluck( (array) $wpdb->get_results( $wpdb->prepare( "SELECT val, SUM(n) n FROM {$s} WHERE dim = 'ci' AND day BETWEEN %s AND %s GROUP BY val", $from, $to ) ), 'n', 'val' ); // phpcs:ignore WordPress.DB
		$prev = wp_list_pluck( (array) $wpdb->get_results( $wpdb->prepare( "SELECT val, SUM(n) n FROM {$s} WHERE dim = 'ci' AND day BETWEEN %s AND %s GROUP BY val", $pfrom, $pto ) ), 'n', 'val' ); // phpcs:ignore WordPress.DB
		$grow = array();
		foreach ( $now as $k => $n ) {
			$d = (int) $n - (int) ( $prev[ $k ] ?? 0 );
			if ( $d > 0 ) {
				$grow[ $k ] = $d;
			}
		}
		arsort( $grow );
		foreach ( array_slice( $grow, 0, 3, true ) as $k => $d ) {
			$c               = explode( '|', (string) $k );
			$out['cities'][] = $row( implode( ', ', array_filter( array( $c[2] ?? '', $c[1] ?? '', $c[0] ?? '' ) ) ) . ' (+' . number_format_i18n( $d ) . ')', $now[ $k ] );
		}

		$out['bots'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(n),0) FROM ' . FW_VI_Installer::table( 'bots' ) . ' WHERE day BETWEEN %s AND %s', $from, $to ) ); // phpcs:ignore WordPress.DB
		$views       = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(views),0) FROM ' . FW_VI_Installer::table( 'views' ) . ' WHERE page_id = 0 AND day BETWEEN %s AND %s', $from, $to ) ); // phpcs:ignore WordPress.DB
		$out['bot_share'] = $out['bots'] ? round( 100 * $out['bots'] / ( $out['bots'] + $views ) ) : 0;

		return $out;
	}

	/**
	 * @param string[]|null $to
	 *
	 * @return bool
	 */
	public static function send( $to = null ) {
		FW_VI_Rollup::run();

		$mail = self::compose();

		return wp_mail( $to ? $to : self::recipients(), $mail['subject'], $mail['html'], array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
