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
