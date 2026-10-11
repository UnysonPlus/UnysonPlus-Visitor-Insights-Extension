<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The heatmap view: the live page itself, loaded with `?fw_vi_view=<nonce>`,
 * with an overlay drawn on top by static/js/admin/heatmap-viewer.js.
 *
 * Two ways in:
 *  - the Heatmaps tab frames the page at a device width (`fw_vi_frame=1`) and
 *    drives the overlay with postMessage;
 *  - "Heatmap" in the admin bar opens the same view at full size, where the
 *    overlay shows its own small toolbar — the fallback for sites whose
 *    security headers refuse to be framed.
 *
 * The view is admin-only and nonce-checked, so it is never cached and never
 * tracked; builder ids are stamped on it exactly as on a tracked page.
 */
class FW_VI_Heatmaps {

	/** @var FW_Extension_Visitor_Insights */
	private $ext;

	/**
	 * @param FW_Extension_Visitor_Insights $ext
	 */
	public function __construct( $ext ) {
		$this->ext = $ext;

		if ( ! is_admin() ) {
			// On `wp`, not `template_redirect`: the admin bar initialises at
			// template_redirect priority 0, and hiding it after that leaves its
			// 32px offset on <html> inside the frame.
			add_action( 'wp', array( $this, '_action_view_headers' ), 99 );
			add_action( 'wp_enqueue_scripts', array( $this, '_action_enqueue' ) );
			add_action( 'admin_bar_menu', array( $this, '_action_admin_bar' ), 90 );
		}
	}

	/**
	 * @param string $path
	 * @param bool   $framed
	 *
	 * @return string
	 */
	public static function view_url( $path, $framed = true ) {
		$args = array( FW_VI_Front::VIEW_ARG => wp_create_nonce( 'fw_vi_view' ) );

		if ( $framed ) {
			$args['fw_vi_frame'] = 1;
		}

		return add_query_arg( $args, FW_VI_Reports::url( $path ) );
	}

	/**
	 * @return bool
	 */
	private static function framed() {
		return isset( $_GET['fw_vi_frame'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * @internal
	 */
	public function _action_view_headers() {
		if ( 'view' !== FW_VI_Front::mode() ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();

		if ( self::framed() ) {
			show_admin_bar( false );
		}
	}

	/**
	 * @internal
	 */
	public function _action_enqueue() {
		if ( 'view' !== FW_VI_Front::mode() ) {
			return;
		}

		$ver = $this->ext->manifest->get_version();

		wp_enqueue_script( 'fw-vi-simpleheat', $this->ext->get_uri( '/static/js/vendor/simpleheat.js' ), array(), '0.4.0', true );
		wp_enqueue_script( 'fw-vi-viewer', $this->ext->get_uri( '/static/js/admin/heatmap-viewer.js' ), array( 'fw-vi-simpleheat' ), $ver, true );
		wp_localize_script( 'fw-vi-viewer', 'UPVIV', array(
			'rest'   => esc_url_raw( rest_url( FW_VI_REST::NS . '/heatmap' ) ),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'path'   => FW_VI_Front::path(),
			'framed' => self::framed(),
			'admin'  => admin_url( 'admin.php?page=fw-visitor-insights&tab=heatmaps' ),
			'l10n'   => self::l10n(),
		) );
	}

	/**
	 * The viewer's strings; shared with the Live Editor overlay.
	 *
	 * @return array
	 */
	public static function l10n() {
		return array(
			'clicks'    => __( 'Clicks', 'fw' ),
			'scroll'    => __( 'Scroll reach', 'fw' ),
			'attention' => __( 'Attention', 'fw' ),
			'elements'  => __( 'Elements', 'fw' ),
			'desktop'   => __( 'Desktop data', 'fw' ),
			'tablet'    => __( 'Tablet data', 'fw' ),
			'mobile'    => __( 'Mobile data', 'fw' ),
			'views'     => __( '%s page views', 'fw' ),
			'none'      => __( 'No data for this page, device and period yet.', 'fw' ),
			'reached'   => __( 'Reached by %s', 'fw' ),
			'avg'       => __( 'avg %ss in view', 'fw' ),
			'ofClicks'  => __( '%s of clicks', 'fw' ),
			'missing'   => __( '%s clicks landed on elements no longer on this page.', 'fw' ),
			'close'     => __( 'Close heatmap', 'fw' ),
			'days'      => __( 'Last %s days', 'fw' ),
		);
	}

	/**
	 * "Heatmap" in the admin bar on any front-end page.
	 *
	 * @internal
	 *
	 * @param WP_Admin_Bar $bar
	 */
	public function _action_admin_bar( $bar ) {
		if ( ! is_admin_bar_showing() || ! FW_VI_Settings::can_view() || 'view' === FW_VI_Front::mode() ) {
			return;
		}

		$bar->add_node( array(
			'id'    => 'fw-vi-heatmap',
			'title' => '<span class="ab-icon dashicons dashicons-visibility" style="top:2px"></span>' . esc_html__( 'Heatmap', 'fw' ),
			'href'  => self::view_url( FW_VI_Front::path(), false ),
			'meta'  => array( 'title' => __( 'Show this page\'s Visitor Insights heatmap', 'fw' ) ),
		) );
	}

	/**
	 * Everything the overlay draws, for one page, device and period.
	 *
	 * @param string $path
	 * @param string $device d|t|m
	 * @param int    $days
	 *
	 * @return array
	 */
	public static function data( $path, $device, $days ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );

		$page = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . FW_VI_Installer::table( 'pages' ) . ' WHERE path = %s', $path ) ); // phpcs:ignore WordPress.DB
		$out  = array(
			'path'     => $path,
			'device'   => $device,
			'from'     => $from,
			'to'       => $to,
			'views'    => 0,
			'elem'     => array(),
			'grid'     => array(),
			'scroll'   => array(),
			'sections' => array(),
		);

		if ( ! $page ) {
			return $out;
		}

		$where = array( $page, $device, $from, $to );
		$sql   = function ( $cols, $table, $group ) {
			return "SELECT {$cols} FROM " . FW_VI_Installer::table( $table ) . " WHERE page_id = %d AND device = %s AND day BETWEEN %s AND %s GROUP BY {$group}";
		};

		$out['views'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(views),0) FROM ' . FW_VI_Installer::table( 'views' ) . ' WHERE page_id = %d AND device = %s AND day BETWEEN %s AND %s', $where ) ); // phpcs:ignore WordPress.DB

		foreach ( $wpdb->get_results( $wpdb->prepare( $sql( 'item, ox, oy, SUM(n) n', 'elem', 'item, ox, oy' ), $where ), ARRAY_N ) as $r ) { // phpcs:ignore WordPress.DB
			$out['elem'][] = array( $r[0], (int) $r[1], (int) $r[2], (int) $r[3] );
		}

		foreach ( $wpdb->get_results( $wpdb->prepare( $sql( 'cx, cy, SUM(n) n', 'clicks', 'cx, cy' ), $where ), ARRAY_N ) as $r ) { // phpcs:ignore WordPress.DB
			$out['grid'][] = array( (int) $r[0], (int) $r[1], (int) $r[2] );
		}

		foreach ( $wpdb->get_results( $wpdb->prepare( $sql( 'band, SUM(n) n', 'scroll', 'band' ), $where ), ARRAY_N ) as $r ) { // phpcs:ignore WordPress.DB
			$out['scroll'][] = array( (int) $r[0], (int) $r[1] );
		}

		$out['sections'] = FW_VI_Sections::for_page( $page, $device, $from, $to );

		return $out;
	}
}
