<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Decides, once per request, whether the page is tracked — and if so loads the
 * tracker with the page's token.
 *
 * Never tracked: admin, AJAX, REST, feeds, previews, the Customizer, the Live
 * Editor, cart / checkout / account pages, excluded paths, and logged-in
 * editors unless the setting says otherwise. A heatmap view
 * (`?fw_vi_view=<nonce>`) is never tracked either; FW_VI_Heatmaps owns it.
 *
 * Everything here must be cache-safe: the same HTML may be served to every
 * visitor for days. So the page carries only a token bound to its path, and
 * every per-visitor decision (consent, sampling, GPC) happens in the browser.
 */
class FW_VI_Front {

	const VIEW_ARG = 'fw_vi_view';

	/** @var string '' | track | view */
	private static $mode = null;

	/** @var FW_Extension_Visitor_Insights */
	private $ext;

	/**
	 * @param FW_Extension_Visitor_Insights $ext
	 */
	public function __construct( $ext ) {
		$this->ext = $ext;

		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', array( $this, '_action_enqueue' ) );
			add_filter( 'fw:ext:asset-optimizer:js_exclude_handles', array( $this, '_filter_ao_exclude' ) );
		}

		add_filter( 'fw_ao_editor_query_vars', array( $this, '_filter_ao_query_vars' ) );
	}

	/**
	 * '' (nothing), 'track' or 'view'. Resolved on first call after the main
	 * query has run; earlier calls return '' without caching.
	 *
	 * @return string
	 */
	public static function mode() {
		if ( null !== self::$mode ) {
			return self::$mode;
		}

		if ( ! did_action( 'wp' ) ) {
			return '';
		}

		self::$mode = self::resolve();

		return self::$mode;
	}

	/**
	 * @return string
	 */
	private static function resolve() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| is_feed() || is_preview() || is_customize_preview() || is_robots() || is_trackback() || is_404() ) {
			return '';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing
		if ( isset( $_GET['fw-live-editor'] ) || isset( $_GET['fw-live-editor-frame'] ) ) {
			return '';
		}

		if ( isset( $_GET[ self::VIEW_ARG ] ) ) {
			return ( is_user_logged_in() && FW_VI_Settings::can_view()
				&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET[ self::VIEW_ARG ] ) ), 'fw_vi_view' ) ) ? 'view' : '';
		}
		// phpcs:enable

		$s = FW_VI_Settings::get();

		if ( ! $s['enabled'] ) {
			return '';
		}

		if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
			return '';
		}

		if ( FW_VI_Settings::skip_current_user() ) {
			return '';
		}

		$path = self::path();

		foreach ( FW_VI_Settings::lines( $s['excluded_paths'] ) as $pattern ) {
			if ( fnmatch( $pattern, $path, FNM_CASEFOLD ) || fnmatch( rtrim( $pattern, '/' ) . '/', $path, FNM_CASEFOLD ) ) {
				return '';
			}
		}

		return 'track';
	}

	/**
	 * This request's path, without query string, at most 191 bytes.
	 *
	 * @return string
	 */
	public static function path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = '/' . ltrim( preg_replace( '#/{2,}#', '/', $path ), '/' );

		// The query string is dropped (it can carry anything), except what
		// identifies the page itself: the post on a site without pretty
		// permalinks, and a search — whose terms are not kept here, so every
		// search results page is one page, "?s=".
		$args = array();
		parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $q );

		foreach ( array( 'p', 'page_id', 'cat', 'tag', 'author', 'post_type', 'product' ) as $k ) {
			if ( isset( $q[ $k ] ) && is_scalar( $q[ $k ] ) && preg_match( '/^[A-Za-z0-9_-]{1,40}$/', (string) $q[ $k ] ) ) {
				$args[] = $k . '=' . $q[ $k ];
			}
		}

		if ( isset( $q['s'] ) ) {
			$args[] = 's=';
		}

		if ( $args ) {
			$path .= '?' . implode( '&', $args );
		}

		return substr( preg_replace( '/[^\x21-\x7e]/', '', $path ), 0, 191 );
	}

	/**
	 * @return int the post this page shows, 0 for archives and the like
	 */
	public static function post_id() {
		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}

		if ( is_front_page() && 'page' === get_option( 'show_on_front' ) ) {
			return (int) get_option( 'page_on_front' );
		}

		return 0;
	}

	/**
	 * @internal
	 */
	public function _action_enqueue() {
		if ( 'track' !== self::mode() ) {
			return;
		}

		$ver  = $this->ext->manifest->get_version();
		$args = array( 'in_footer' => true, 'strategy' => 'defer' );
		$path = self::path();
		$pid  = self::post_id();

		wp_enqueue_script( 'fw-vi-core', $this->ext->get_uri( '/static/js/vi-core.js' ), array(), $ver, $args );
		wp_enqueue_script( 'fw-vi-heatmaps', $this->ext->get_uri( '/static/js/modules/heatmaps.js' ), array( 'fw-vi-core' ), $ver, $args );
		wp_enqueue_script( 'fw-vi-sections', $this->ext->get_uri( '/static/js/modules/sections.js' ), array( 'fw-vi-core' ), $ver, $args );
		wp_enqueue_script( 'fw-vi-goals', $this->ext->get_uri( '/static/js/modules/goals.js' ), array( 'fw-vi-core' ), $ver, $args );

		$s    = FW_VI_Settings::get();
		$mods = array(
			'frustration' => (bool) $s['track_frustration'],
			'errors'      => (bool) $s['track_errors'],
			'vitals'      => (bool) $s['track_vitals'],
			'forms'       => (bool) $s['track_forms'],
			'moves'       => (bool) $s['track_moves'],
		);

		if ( $mods['frustration'] || $mods['errors'] ) {
			wp_enqueue_script( 'fw-vi-frustration', $this->ext->get_uri( '/static/js/modules/frustration.js' ), array( 'fw-vi-core' ), $ver, $args );
		}

		if ( $mods['forms'] ) {
			wp_enqueue_script( 'fw-vi-forms', $this->ext->get_uri( '/static/js/modules/forms.js' ), array( 'fw-vi-core' ), $ver, $args );
		}

		if ( $mods['moves'] ) {
			wp_enqueue_script( 'fw-vi-moves', $this->ext->get_uri( '/static/js/modules/moves.js' ), array( 'fw-vi-core' ), $ver, $args );
		}

		if ( FW_VI_Experiments::here() ) {
			wp_enqueue_script( 'fw-vi-experiments', $this->ext->get_uri( '/static/js/modules/experiments.js' ), array( 'fw-vi-core' ), $ver, $args );
		}

		if ( $mods['vitals'] ) {
			wp_enqueue_script( 'fw-vi-vitals', $this->ext->get_uri( '/static/js/modules/vitals.js' ), array( 'fw-vi-core' ), $ver, $args );
		}

		wp_localize_script( 'fw-vi-core', 'UPVI', array(
			'ep'      => FW_VI_Collector::endpoint(),
			'p'       => $pid,
			'u'       => $path,
			'k'       => FW_VI_Collector::token( $pid, $path ),
			'sample'  => (int) FW_VI_Settings::get()['sample_rate'],
			'consent' => FW_VI_Consent::js_config(),
			'mods'    => $mods,
		) );
	}

	/**
	 * The tracker is never folded into a combined bundle: it must load on its
	 * own, deferred, and its config travels with it.
	 *
	 * @internal
	 */
	public function _filter_ao_exclude( $handles ) {
		return array_merge( (array) $handles, array( 'fw-vi-core', 'fw-vi-heatmaps', 'fw-vi-sections', 'fw-vi-goals', 'fw-vi-frustration', 'fw-vi-vitals', 'fw-vi-forms', 'fw-vi-experiments', 'fw-vi-recorder', 'fw-vi-moves', 'fw-vi-viewer', 'fw-vi-simpleheat' ) );
	}

	/**
	 * A heatmap view skips the asset optimizer entirely, so its CSS purge
	 * cannot strip styles the overlay needs.
	 *
	 * @internal
	 */
	public function _filter_ao_query_vars( $vars ) {
		$vars   = (array) $vars;
		$vars[] = self::VIEW_ARG;

		return $vars;
	}
}
