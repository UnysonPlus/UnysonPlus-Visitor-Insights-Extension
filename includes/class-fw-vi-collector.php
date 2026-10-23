<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Everything the standalone collector needs, prepared from inside WordPress:
 *
 *  - the data folder (uploads/unysonplus/visitor-insights), locked down;
 *  - `config.php` in it — the secret that signs page tokens, today's visitor
 *    salt, the site's hosts, exclusions — rewritten whenever any of it changes;
 *  - the page token for the current request;
 *  - which endpoint the tracker posts to: the direct collector when a
 *    self-test proves it works on this server, otherwise the REST route.
 *
 * The visitor salt is replaced every day and the old one is not kept, so a
 * visitor hash from yesterday can never be recomputed (see FW_VI_Retention).
 */
class FW_VI_Collector {

	const OPT_SECRET   = 'fw_ext_vi_secret';
	const OPT_SALT     = 'fw_ext_vi_salt';
	const OPT_KEY      = 'fw_ext_vi_buffer_key';
	const OPT_HASH     = 'fw_ext_vi_config_hash';
	const OPT_ENDPOINT = 'fw_ext_vi_endpoint';
	const CAP_BYTES    = 20971520; // 20 MB of buffer per minute, then drop

	/** @var FW_Extension_Visitor_Insights */
	private static $ext;

	/**
	 * @param FW_Extension_Visitor_Insights $ext
	 */
	public function __construct( $ext ) {
		self::$ext = $ext;

		// Cheap on every admin load: one autoloaded option compared to a hash.
		add_action( 'admin_init', array( __CLASS__, 'maybe_write_config' ) );
	}

	/**
	 * @return array{path:string,url:string}
	 */
	public static function data_dir() {
		$dir = fw_upw_uploads_dir( 'visitor-insights' );

		if ( ! is_dir( $dir['path'] ) ) {
			wp_mkdir_p( $dir['path'] );
		}

		if ( ! is_file( $dir['path'] . '/.htaccess' ) ) {
			file_put_contents( $dir['path'] . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir['path'] . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}

	/**
	 * @return string
	 */
	public static function secret() {
		$secret = get_option( self::OPT_SECRET );

		if ( ! $secret ) {
			$secret = wp_generate_password( 64, false, false );
			update_option( self::OPT_SECRET, $secret, true );
		}

		return $secret;
	}

	/**
	 * Today's visitor salt. A new day gets a new random salt; the old one is
	 * overwritten, not archived.
	 *
	 * @return string
	 */
	public static function salt() {
		$today = gmdate( 'Ymd' );
		$salt  = get_option( self::OPT_SALT );

		if ( ! is_array( $salt ) || ( $salt['day'] ?? '' ) !== $today ) {
			$salt = array( 'day' => $today, 'salt' => wp_generate_password( 32, false, false ) );
			update_option( self::OPT_SALT, $salt, true );
		}

		return $salt['salt'];
	}

	/**
	 * @return string random, so buffer file names cannot be guessed
	 */
	public static function buffer_key() {
		$key = get_option( self::OPT_KEY );

		if ( ! $key ) {
			$key = strtolower( wp_generate_password( 12, false, false ) );
			update_option( self::OPT_KEY, $key, true );
		}

		return $key;
	}

	/**
	 * The site's own hosts, with and without www.
	 *
	 * @return string[]
	 */
	public static function hosts() {
		$hosts = array();

		foreach ( array( home_url(), site_url() ) as $url ) {
			$h = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

			if ( '' !== $h ) {
				$hosts[] = $h;
				$hosts[] = 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : 'www.' . $h;
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * What FW_VI_Ingest::handle() receives, from either endpoint.
	 *
	 * @return array
	 */
	public static function config() {
		$s = FW_VI_Settings::get();

		return array(
			'secret'       => self::secret(),
			'salt'         => self::salt(),
			'hosts'        => self::hosts(),
			'proxies'      => FW_VI_Settings::lines( $s['trusted_proxies'] ),
			'excluded_ips' => FW_VI_Settings::lines( $s['excluded_ips'] ),
			'buffer_dir'   => self::data_dir()['path'],
			'buffer_key'   => self::buffer_key(),
			'cap_bytes'    => self::CAP_BYTES,
			'blog'         => get_current_blog_id(),
			'bots'         => (bool) $s['track_bots'],
			'city'         => (bool) $s['track_city'],
			'geo_db'       => FW_VI_Geo_Admin::file(),
		);
	}

	/**
	 * Rewrite config.php when anything in it changed (or it is missing), then
	 * re-run the endpoint self-test.
	 *
	 * @param bool $force
	 */
	public static function maybe_write_config( $force = false ) {
		$config = self::config();
		$hash   = md5( wp_json_encode( $config ) );
		$file   = $config['buffer_dir'] . '/config.php';

		if ( ! $force && get_option( self::OPT_HASH ) === $hash && is_file( $file ) ) {
			return;
		}

		$php = "<?php\n// Visitor Insights collector config. Generated; edits are overwritten.\n"
			. "if ( ! defined( 'FW_VI_COLLECTOR' ) && ! defined( 'ABSPATH' ) ) { exit; }\n"
			. 'return ' . var_export( $config, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions

		file_put_contents( $file, $php, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		update_option( self::OPT_HASH, $hash, true );

		self::self_test();
	}

	/**
	 * @return string
	 */
	public static function direct_url() {
		$url = self::$ext->get_uri( '/collector/collect.php' );

		return is_multisite() && get_current_blog_id() > 1 ? add_query_arg( 'b', get_current_blog_id(), $url ) : $url;
	}

	/**
	 * @return string
	 */
	public static function rest_url() {
		return rest_url( FW_VI_REST::NS . '/collect' );
	}

	/**
	 * Post a test batch to the direct collector; remember which endpoint works.
	 *
	 * @return string direct|rest
	 */
	public static function self_test() {
		$res = wp_remote_post( self::direct_url(), array(
			'body'      => '{"test":1}',
			'timeout'   => 5,
			'sslverify' => false,
			'headers'   => array( 'Content-Type' => 'text/plain' ),
		) );

		$mode = ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) && 'upvi-ok' === trim( (string) wp_remote_retrieve_body( $res ) ) )
			? 'direct'
			: 'rest';

		update_option( self::OPT_ENDPOINT, $mode, true );

		return $mode;
	}

	/**
	 * @return string the URL the tracker posts to
	 */
	public static function endpoint() {
		return 'direct' === get_option( self::OPT_ENDPOINT ) ? self::direct_url() : self::rest_url();
	}

	/**
	 * @param int    $post_id
	 * @param string $path
	 *
	 * @return string
	 */
	public static function token( $post_id, $path ) {
		return FW_VI_Ingest::token( self::secret(), $post_id, $path, gmdate( 'Ymd' ) );
	}

	/**
	 * Write a line from WordPress itself — a conversion that happens on the
	 * server (an order, a form submission) or a 404 — into the same buffer the
	 * collector fills, so the rollup is still the only writer of the tables.
	 * `seq` -1 marks it; the collector never accepts a negative seq from a
	 * browser. The same exclusions apply as to page views: bots, excluded
	 * addresses, editors, Global Privacy Control.
	 *
	 * @param array  $events       e.g. [ ['g', 'woo_order', 4599] ]
	 * @param string $path         where it happened; '' = the referring page on this site
	 * @param bool   $with_visitor include today's visitor hash (lets funnels follow it)
	 *
	 * @return bool
	 */
	public static function server_line( array $events, $path = '', $with_visitor = true ) {
		$s  = FW_VI_Settings::get();
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! $s['enabled'] || FW_VI_Bot_Filter::is_bot( $ua )
			|| FW_VI_Settings::skip_current_user()
			|| ( $s['honor_gpc'] && isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === $_SERVER['HTTP_SEC_GPC'] ) ) {
			return false;
		}

		$config = self::config();
		$ip     = FW_VI_Bot_Filter::client_ip( $config['proxies'] );

		if ( '' !== $ip && $config['excluded_ips'] && FW_VI_Bot_Filter::ip_in_list( $ip, $config['excluded_ips'] ) ) {
			return false;
		}

		// With "wait for consent everywhere" the server cannot know the answer,
		// so the conversion is counted but never joined to a visitor.
		$vh = $with_visitor && 'require' !== $s['consent_mode']
			? substr( hash( 'sha256', $config['salt'] . '|' . $ip . '|' . $ua . '|' . $config['blog'] ), 0, 16 )
			: '';

		if ( '' === $path ) {
			$path = self::referer_path();
		}

		$dev = preg_match( '/iPad|Tablet|PlayBook|Silk|Kindle|(Android(?!.*Mobile))/i', $ua ) ? 't'
			: ( preg_match( '/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|Opera Mini/i', $ua ) ? 'm' : 'd' );

		return FW_VI_Ingest::append( $config, wp_json_encode( array(
			time(), 0, substr( $path, 0, 191 ), $dev, '', -1, $vh, '', '', '', null, $events,
		) ) );
	}

	/**
	 * The path of the page on this site that sent the current request (the
	 * form's page for a form post, the checkout for an order), or '/'.
	 *
	 * @return string
	 */
	public static function referer_path() {
		$ref = wp_get_raw_referer();

		if ( $ref && in_array( strtolower( (string) wp_parse_url( $ref, PHP_URL_HOST ) ), self::hosts(), true ) ) {
			$path = (string) wp_parse_url( $ref, PHP_URL_PATH );

			return '' !== $path ? '/' . ltrim( preg_replace( '#/{2,}#', '/', $path ), '/' ) : '/';
		}

		return '/';
	}

	/**
	 * @return array paths of buffer files, oldest first
	 */
	public static function buffer_files() {
		$files = glob( self::data_dir()['path'] . '/buf-' . self::buffer_key() . '-*.php' );

		if ( ! $files ) {
			return array();
		}

		sort( $files );

		return $files;
	}
}
