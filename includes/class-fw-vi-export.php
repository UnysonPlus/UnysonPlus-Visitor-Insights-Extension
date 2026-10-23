<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CSV downloads of the reports: pages, sources (every dimension: channel,
 * referrer, campaign, device, browser, OS, country), goals and daily totals,
 * for 7, 30, 90 or 365 days. Cells that a spreadsheet would read as a formula
 * are prefixed with an apostrophe.
 *
 *   admin-post.php?action=fw_vi_export&type=pages&days=30&_wpnonce=…
 */
class FW_VI_Export {

	const ACTION = 'fw_vi_export';
	const TYPES  = array( 'pages', 'sources', 'goals', 'daily' );

	public function __construct() {
		add_action( 'admin_post_' . self::ACTION, array( $this, '_action_export' ) );
	}

	/**
	 * @param string $type
	 * @param int    $days
	 *
	 * @return string
	 */
	public static function url( $type, $days ) {
		return wp_nonce_url( add_query_arg( array( 'action' => self::ACTION, 'type' => $type, 'days' => (int) $days ), admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	/**
	 * @param int $days
	 */
	public static function buttons( $days ) {
		$labels = array(
			'pages'   => __( 'Pages', 'fw' ),
			'sources' => __( 'Sources', 'fw' ),
			'goals'   => __( 'Goals', 'fw' ),
			'daily'   => __( 'Daily totals', 'fw' ),
		);

		echo '<p class="fw-vi-export"><span class="dashicons dashicons-download"></span> ' . esc_html__( 'Download CSV:', 'fw' ) . ' ';
		foreach ( $labels as $type => $label ) {
			printf( '<a class="button button-small" href="%s">%s</a> ', esc_url( self::url( $type, $days ) ), esc_html( $label ) );
		}
		echo '</p>';
	}

	/**
	 * @internal
	 */
	public function _action_export() {
		check_admin_referer( self::ACTION );

		if ( ! FW_VI_Settings::can_view() ) {
			wp_die( esc_html__( 'You are not allowed to export Visitor Insights.', 'fw' ), 403 );
		}

		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'pages';
		$days = isset( $_GET['days'] ) ? max( 1, min( 730, (int) $_GET['days'] ) ) : 30;
		$type = in_array( $type, self::TYPES, true ) ? $type : 'pages';

		FW_VI_Rollup::run();

		list( $head, $rows ) = self::data( $type, $days );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="visitor-insights-' . $type . '-' . wp_date( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- BOM, so spreadsheet apps read UTF-8
		fputcsv( $out, $head );
		foreach ( $rows as $r ) {
			fputcsv( $out, array_map( array( __CLASS__, 'cell' ), $r ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * @param mixed $v
	 *
	 * @return string
	 */
	public static function cell( $v ) {
		$v = (string) $v;

		return '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $v ) ? "'" . $v : $v;
	}

	/**
	 * @param string $type
	 * @param int    $days
	 *
	 * @return array [ header, rows ]
	 */
	public static function data( $type, $days ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );

		switch ( $type ) {
			case 'sources':
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT dim, val, SUM(n) n FROM ' . FW_VI_Installer::table( 'sources' ) . ' WHERE day BETWEEN %s AND %s GROUP BY dim, val ORDER BY dim, n DESC', $from, $to ), ARRAY_N ); // phpcs:ignore WordPress.DB
				$dims = array( 'ch' => 'channel', 'ref' => 'referrer', 'us' => 'utm_source', 'um' => 'utm_medium', 'uc' => 'utm_campaign', 'dev' => 'device', 'br' => 'browser', 'os' => 'os', 'cc' => 'country' );
				foreach ( $rows as &$r ) {
					$r[0] = $dims[ $r[0] ] ?? $r[0];
				}
				return array( array( 'dimension', 'value', 'page_views' ), $rows );

			case 'goals':
				$all  = FW_VI_Goals::all();
				$rows = array();
				foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT g.goal, p.path, SUM(g.n) n, SUM(g.value) v FROM ' . FW_VI_Installer::table( 'goals' ) . ' g JOIN ' . FW_VI_Installer::table( 'pages' ) . ' p ON p.id = g.page_id WHERE g.day BETWEEN %s AND %s GROUP BY g.goal, p.path ORDER BY n DESC', $from, $to ) ) as $r ) { // phpcs:ignore WordPress.DB
					$rows[] = array( $r->goal, $all[ $r->goal ]['name'] ?? '', $r->path, (int) $r->n, number_format( $r->v / 100, 2, '.', '' ) );
				}
				return array( array( 'goal_key', 'goal', 'page', 'completions', 'value' ), $rows );

			case 'daily':
				$rows = array();
				foreach ( FW_VI_Reports::series( $days ) as $d ) {
					$rows[] = array( $d['date'], $d['views'], $d['uniques'] );
				}
				return array( array( 'date', 'page_views', 'unique_visitors' ), $rows );

			default:
				$rows = array();
				foreach ( FW_VI_Reports::top_pages( $days, 5000 ) as $r ) {
					$rows[] = array( $r->path, $r->title, $r->views, $r->uniques, $r->engaged, null === $r->half ? '' : $r->half );
				}
				return array( array( 'page', 'title', 'page_views', 'unique_visitors', 'avg_seconds', 'read_half_pct' ), $rows );
		}
	}
}
