<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Alerts: one email the morning after something jumps, compared with the
 * usual — the average of the seven days before.
 *
 *   Script errors   yesterday ≥ 10 and ≥ 3 × the usual, or a new error
 *                   message seen ≥ 5 times
 *   Broken links    internal 404s yesterday ≥ 5 and ≥ 3 × the usual
 *   Traffic drop    page views yesterday < half the usual (usual ≥ 50/day)
 *   Traffic spike   page views yesterday > 3 × the usual (usual ≥ 20/day)
 *
 * Off by default; sent to the weekly-email recipients. At most one email a
 * day; nothing is sent when nothing jumped. "Check now" on the Settings tab
 * runs the same check against yesterday and shows the result.
 */
class FW_VI_Alerts {

	const CRON     = 'fw_ext_vi_alerts';
	const OPT_LAST = 'fw_ext_vi_alerts_last';

	public function __construct() {
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		add_action( 'init', array( $this, '_action_schedule' ) );
	}

	/**
	 * @internal
	 */
	public function _action_schedule() {
		$on   = (bool) FW_VI_Settings::get()['alerts'];
		$next = wp_next_scheduled( self::CRON );

		if ( $on && ! $next ) {
			$at = new DateTime( 'tomorrow 07:30', wp_timezone() );
			wp_schedule_event( $at->getTimestamp(), 'daily', self::CRON );
		} elseif ( ! $on && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/**
	 * @return array [ { kind, title, detail } ] for yesterday
	 */
	public static function check() {
		global $wpdb;

		FW_VI_Rollup::run();

		$y     = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
		$from  = wp_date( 'Y-m-d', time() - 8 * DAY_IN_SECONDS );
		$to    = wp_date( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );
		$out   = array();
		$views = FW_VI_Installer::table( 'views' );
		$err   = FW_VI_Installer::table( 'errors' );
		$nf    = FW_VI_Installer::table( 'notfound' );

		$vy = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(views),0) FROM {$views} WHERE page_id = 0 AND day = %s", $y ) ); // phpcs:ignore WordPress.DB
		$va = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(views),0) / 7 FROM {$views} WHERE page_id = 0 AND day BETWEEN %s AND %s", $from, $to ) ); // phpcs:ignore WordPress.DB

		if ( $va >= 50 && $vy < 0.5 * $va ) {
			$out[] = array(
				'kind'   => 'drop',
				'title'  => __( 'Traffic dropped', 'fw' ),
				'detail' => sprintf( __( '%1$s page views yesterday against about %2$s on a usual day. Check that the site is up and the tracker loads (a cache or security change can block it).', 'fw' ), number_format_i18n( $vy ), number_format_i18n( $va ) ),
			);
		} elseif ( $va >= 20 && $vy > 3 * $va ) {
			$out[] = array(
				'kind'   => 'spike',
				'title'  => __( 'Traffic spiked', 'fw' ),
				'detail' => sprintf( __( '%1$s page views yesterday against about %2$s on a usual day — see Channels on the Overview for where they came from.', 'fw' ), number_format_i18n( $vy ), number_format_i18n( $va ) ),
			);
		}

		$ey = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(n),0) FROM {$err} WHERE day = %s", $y ) ); // phpcs:ignore WordPress.DB
		$ea = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(n),0) / 7 FROM {$err} WHERE day BETWEEN %s AND %s", $from, $to ) ); // phpcs:ignore WordPress.DB

		$new = $wpdb->get_results( $wpdb->prepare(
			'SELECT m.message, SUM(e.n) n FROM ' . $err . ' e JOIN ' . FW_VI_Installer::table( 'errmsg' ) . " m ON m.hash = e.hash
			WHERE e.day = %s AND e.hash NOT IN (SELECT hash FROM {$err} WHERE day < %s) GROUP BY e.hash HAVING n >= 5 ORDER BY n DESC LIMIT 3",
			$y, $y
		) ); // phpcs:ignore WordPress.DB

		if ( $ey >= 10 && $ey >= 3 * max( 1, $ea ) ) {
			$out[] = array(
				'kind'   => 'errors',
				'title'  => __( 'Script errors jumped', 'fw' ),
				'detail' => sprintf( __( '%1$s script errors yesterday against about %2$s on a usual day.', 'fw' ), number_format_i18n( $ey ), number_format_i18n( $ea, 1 ) ),
			);
		}

		foreach ( $new as $n ) {
			$out[] = array(
				'kind'   => 'new-error',
				'title'  => __( 'A new script error', 'fw' ),
				'detail' => sprintf( __( '"%1$s" — %2$s times yesterday, never before.', 'fw' ), $n->message, number_format_i18n( $n->n ) ),
			);
		}

		$ny = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(n),0) FROM {$nf} WHERE ref = 'internal' AND day = %s", $y ) ); // phpcs:ignore WordPress.DB
		$na = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(n),0) / 7 FROM {$nf} WHERE ref = 'internal' AND day BETWEEN %s AND %s", $from, $to ) ); // phpcs:ignore WordPress.DB

		if ( $ny >= 5 && $ny >= 3 * max( 1, $na ) ) {
			$paths = $wpdb->get_col( $wpdb->prepare( "SELECT path FROM {$nf} WHERE ref = 'internal' AND day = %s GROUP BY path ORDER BY SUM(n) DESC LIMIT 3", $y ) ); // phpcs:ignore WordPress.DB
			$out[] = array(
				'kind'   => 'broken',
				'title'  => __( 'Broken links on your site', 'fw' ),
				'detail' => sprintf( __( 'Visitors followed links on your own site to missing pages %1$s times yesterday: %2$s', 'fw' ), number_format_i18n( $ny ), implode( ', ', $paths ) ),
			);
		}

		return $out;
	}

	/**
	 * The daily job: check, and email when something jumped.
	 *
	 * @return array what was found
	 */
	public static function run() {
		$found = self::check();
		$day   = wp_date( 'Y-m-d' );

		if ( ! $found || get_option( self::OPT_LAST ) === $day ) {
			return $found;
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$html = '<div style="max-width:600px;font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#1d2327">'
			. '<h1 style="font-size:18px">' . esc_html( sprintf( __( '%s: something changed yesterday', 'fw' ), $site ) ) . '</h1>';

		foreach ( $found as $f ) {
			$html .= '<h2 style="font-size:15px;margin:18px 0 4px">' . esc_html( $f['title'] ) . '</h2><p style="margin:0">' . esc_html( $f['detail'] ) . '</p>';
		}

		$html .= '<p style="margin-top:24px"><a href="' . esc_url( admin_url( 'admin.php?page=fw-visitor-insights' ) ) . '">' . esc_html__( 'Open Visitor Insights', 'fw' ) . '</a></p>'
			. '<p style="color:#8c8f94;font-size:12px">' . esc_html__( 'Visitor Insights alerts — switch them off under Settings → Weekly email & alerts.', 'fw' ) . '</p></div>';

		wp_mail( FW_VI_Digest::recipients(), sprintf( __( '[%1$s] %2$s', 'fw' ), $site, $found[0]['title'] ), $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		update_option( self::OPT_LAST, $day, false );

		return $found;
	}
}
