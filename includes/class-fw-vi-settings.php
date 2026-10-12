<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Saved settings over the defaults, read lazily (never from `_init()`, which
 * runs before the options API is safe to use for extension settings).
 */
class FW_VI_Settings {

	const EXT = 'visitor-insights';

	/** @var array|null per-request cache */
	private static $settings = null;

	/**
	 * Must match settings-options.php.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'         => true,
			'sample_rate'     => '100',
			'track_editors'   => false,
			'excluded_paths'  => '',
			'track_frustration' => true,
			'track_errors'    => true,
			'track_404'       => true,
			'track_vitals'    => true,
			'consent_mode'    => 'auto',
			'honor_gpc'       => true,
			'retention_days'  => '395',
			'daily_cap'       => '5000',
			'excluded_ips'    => '',
			'trusted_proxies' => '',
		);
	}

	/**
	 * @return array
	 */
	public static function get() {
		if ( null !== self::$settings ) {
			return self::$settings;
		}

		$saved = (array) fw_get_db_ext_settings_option( self::EXT );

		self::$settings = array_merge( self::defaults(), array_filter( $saved, function ( $v ) {
			return null !== $v;
		} ) );

		return self::$settings;
	}

	/**
	 * Drop the cached copy after a save.
	 */
	public static function flush() {
		self::$settings = null;
	}

	/**
	 * A settings textarea as trimmed, non-empty lines (commas also split).
	 *
	 * @param string $text
	 *
	 * @return string[]
	 */
	public static function lines( $text ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) $text ) ), 'strlen' ) );
	}

	/**
	 * Who may read the reports. Filterable so a site can open them to editors.
	 *
	 * @return bool
	 */
	public static function can_view() {
		return current_user_can( (string) apply_filters( 'fw_ext_visitor_insights_view_cap', 'manage_options' ) );
	}
}
