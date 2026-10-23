<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Session recording — replays of individual visits. Off by default and the
 * one part of Visitor Insights that records a single visitor, so it is held
 * to the strictest rules:
 *
 *  - prior opt-in consent, always: a WP Consent API plugin's "statistics"
 *    answer, or UnysonPlusVI.grantRecording() from the site's own banner —
 *    without either, nothing is recorded, ever. Withdrawing consent stops the
 *    recorder at once and deletes the current recording;
 *  - Global Privacy Control means never; logged-in users follow the
 *    "Logged-in users" setting; cart / checkout / account / login pages are
 *    never recorded (they are never tracked at all), plus the site's own list;
 *  - every form field is masked IN THE BROWSER (rrweb maskAllInputs) before
 *    anything is sent; "Hide all text" masks every text node; the classes
 *    up-vi-mask / up-vi-block mask or leave out single elements;
 *  - sampled (default 1 in 5 consenting visits), optionally kept only when
 *    the visit had a problem, deleted after the retention period;
 *  - stored on this site only (files under uploads/unysonplus/visitor-insights/
 *    rec/<random>/, gzip, folder locked down), never sent anywhere else;
 *  - played back only by users with the recordings capability, and every
 *    playback is logged;
 *  - a visitor can delete their own recording with [visitor_insights_recording].
 *
 * A "session" is one browser tab: the recorder keeps a random id in
 * sessionStorage and continues it across page views until 30 minutes pass
 * without activity.
 */
class FW_VI_Recordings {

	const NS           = 'fw-visitor-insights/v1';
	const OPT_KEY      = 'fw_ext_vi_rec_key';
	const OPT_LOG      = 'fw_ext_vi_rec_log';
	const CHUNK_MAX    = 2097152;  // 2 MB compressed per request
	const RAW_MAX      = 8388608;  // 8 MB once decompressed
	const SESSION_MAX  = 15728640; // 15 MB per recording
	const SESSION_IDLE = 1800;

	/** @var FW_Extension_Visitor_Insights */
	private $ext;

	/**
	 * @param FW_Extension_Visitor_Insights $ext
	 */
	public function __construct( $ext ) {
		$this->ext = $ext;

		add_action( 'rest_api_init', array( $this, '_action_routes' ) );
		add_shortcode( 'visitor_insights_recording', array( $this, '_shortcode' ) );
		add_action( FW_VI_Retention::CRON, array( __CLASS__, 'prune' ) );

		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', array( $this, '_action_enqueue' ), 20 );
		}
	}

	/**
	 * @return bool
	 */
	public static function can_view() {
		return current_user_can( (string) apply_filters( 'fw_ext_visitor_insights_recordings_cap', 'manage_options' ) );
	}

	/**
	 * @return string folder holding every recording (created and locked down)
	 */
	public static function dir() {
		$key = get_option( self::OPT_KEY );

		if ( ! $key ) {
			$key = strtolower( wp_generate_password( 16, false, false ) );
			update_option( self::OPT_KEY, $key, false );
		}

		$dir = FW_VI_Collector::data_dir()['path'] . '/rec/' . $key;

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			foreach ( array( dirname( $dir ), $dir ) as $d ) {
				file_put_contents( $d . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				file_put_contents( $d . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}

		return $dir;
	}

	/* ---------------------------------------------------------------------- *
	 * Front end
	 * ---------------------------------------------------------------------- */

	/**
	 * The recorder's small loader rides with the tracker when recording is on
	 * and the page may be recorded. The 24 KB recorder library itself is
	 * fetched only after consent and sampling say yes.
	 *
	 * @internal
	 */
	public function _action_enqueue() {
		$s = FW_VI_Settings::get();

		if ( ! $s['recording'] || 'track' !== FW_VI_Front::mode() ) {
			return;
		}

		$path = FW_VI_Front::path();

		foreach ( FW_VI_Settings::lines( $s['rec_exclude'] ) as $pattern ) {
			if ( FW_VI_Goals::path_matches( $pattern, $path ) ) {
				return;
			}
		}

		$ver = $this->ext->manifest->get_version();

		wp_enqueue_script( 'fw-vi-recorder', $this->ext->get_uri( '/static/js/recorder/recorder.js' ), array( 'fw-vi-core' ), $ver, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script( 'fw-vi-recorder', 'UPVIR', array(
			'ep'       => esc_url_raw( rest_url( self::NS . '/rec' ) ),
			'forget'   => esc_url_raw( rest_url( self::NS . '/rec/forget' ) ),
			'lib'      => $this->ext->get_uri( '/static/js/vendor/rrweb/record.min.js' ) . '?ver=2.1.7',
			'consent'  => $s['rec_consent'],
			'sample'   => (int) $s['rec_sample'],
			'maskText' => (bool) $s['rec_mask_text'],
		) );
	}

	/**
	 * [visitor_insights_recording] — lets a visitor delete the recording of
	 * their current visit, when there is one.
	 *
	 * @internal
	 */
	public function _shortcode() {
		$url  = esc_url( rest_url( self::NS . '/rec/forget' ) );
		$none = esc_js( __( 'This visit is not being recorded.', 'fw' ) );
		$has  = esc_js( __( 'This visit is being recorded to help improve the site.', 'fw' ) );
		$btn  = esc_js( __( 'Delete my recording', 'fw' ) );
		$done = esc_js( __( 'Your recording was deleted and this visit is no longer recorded.', 'fw' ) );
		$id   = 'upvi-rec-' . wp_rand( 1000, 9999 );

		return '<p class="upvi-recording" id="' . esc_attr( $id ) . '"><span></span> <button type="button" hidden></button></p>'
			. "<script>(function(){var r=document.getElementById('{$id}'),t=r.querySelector('span'),b=r.querySelector('button'),s=null;"
			. "try{s=JSON.parse(sessionStorage.getItem('upvi_rec')||'null')}catch(e){}"
			. "if(!s||!s.on){t.textContent='{$none}';return}t.textContent='{$has}';b.textContent='{$btn}';b.hidden=false;"
			. "b.addEventListener('click',function(){if(window.UnysonPlusVI&&UnysonPlusVI.stopRecording){UnysonPlusVI.stopRecording(true)}else{fetch('{$url}',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({sid:s.sid})})}"
			. "try{sessionStorage.setItem('upvi_rec',JSON.stringify({sid:s.sid,on:false,optout:true}))}catch(e){}t.textContent='{$done}';b.hidden=true})})();</script>";
	}

	/* ---------------------------------------------------------------------- *
	 * REST
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _action_routes() {
		register_rest_route( self::NS, '/rec', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'ingest' ),
			'permission_callback' => '__return_true', // checked below: token, consent proof is the visitor's own browser
		) );

		register_rest_route( self::NS, '/rec/forget', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'forget' ),
			'permission_callback' => '__return_true', // the random session id is the proof
		) );

		register_rest_route( self::NS, '/rec/(?P<sid>[a-z0-9]{24})', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'events' ),
			'permission_callback' => array( __CLASS__, 'can_view' ),
		) );
	}

	/**
	 * One batch of recorder events.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function ingest( $request ) {
		$s    = FW_VI_Settings::get();
		$body = $request->get_body();
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! $s['enabled'] || ! $s['recording'] || strlen( $body ) > self::CHUNK_MAX
			|| FW_VI_Bot_Filter::is_bot( $ua ) || FW_VI_Settings::skip_current_user()
			|| ( isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === $_SERVER['HTTP_SEC_GPC'] ) ) {
			return new WP_REST_Response( null, 204 );
		}

		if ( function_exists( 'fw_rate_limit_exceeded' ) && fw_rate_limit_exceeded( 'fw_vi_rec', 60, 60 ) ) {
			return new WP_REST_Response( null, 429 );
		}

		if ( 'gzip' === strtolower( (string) $request->get_header( 'x_upvi_enc' ) ) ) {
			$body = function_exists( 'gzdecode' ) ? @gzdecode( $body, self::RAW_MAX ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $body ) {
				return new WP_REST_Response( null, 400 );
			}
		}

		$in = json_decode( (string) $body, true );

		if ( ! is_array( $in ) || 1 !== ( $in['v'] ?? null ) || ! isset( $in['sid'], $in['seq'], $in['u'], $in['k'], $in['ev'] )
			|| ! is_string( $in['sid'] ) || ! preg_match( '/^[a-z0-9]{24}$/', $in['sid'] )
			|| ! is_int( $in['seq'] ) || $in['seq'] < 0 || $in['seq'] > 5000
			|| ! is_string( $in['u'] ) || '' === $in['u'] || '/' !== $in['u'][0] || strlen( $in['u'] ) > 191
			|| ! is_array( $in['ev'] ) || ! $in['ev'] ) {
			return new WP_REST_Response( null, 400 );
		}

		if ( ! FW_VI_Ingest::token_ok( FW_VI_Collector::secret(), (int) ( $in['p'] ?? 0 ), $in['u'], (string) $in['k'] ) ) {
			return new WP_REST_Response( null, 403 );
		}

		global $wpdb;

		$sid = $in['sid'];
		$t   = FW_VI_Installer::table( 'recs' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE sid = %s", $sid ) ); // phpcs:ignore WordPress.DB

		if ( $row && ( (int) $row->bytes > self::SESSION_MAX || (int) $row->started < time() - 2 * HOUR_IN_SECONDS ) ) {
			return new WP_REST_Response( null, 204 ); // big enough / long enough
		}

		$gz  = gzencode( wp_json_encode( $in['ev'] ), 6 );
		$dir = self::dir() . '/' . $sid;

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		file_put_contents( $dir . '/' . sprintf( '%05d', $in['seq'] ) . '.gz', $gz, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$flags = isset( $in['f'] ) && is_string( $in['f'] ) ? preg_replace( '/[^rdexg]/', '', $in['f'] ) : '';
		$ts    = array_filter( array_map( function ( $e ) {
			return is_array( $e ) && isset( $e['timestamp'] ) && is_numeric( $e['timestamp'] ) ? (int) $e['timestamp'] : 0;
		}, $in['ev'] ) );
		$first = $ts ? min( $ts ) : 0;
		$last  = $ts ? max( $ts ) : 0;
		$dev   = in_array( $in['d'] ?? '', array( 'd', 't', 'm' ), true ) ? $in['d'] : 'd';

		if ( ! $row ) {
			$wpdb->insert( $t, array( // phpcs:ignore WordPress.DB
				'sid'        => $sid,
				'started'    => time(),
				'last_seen'  => time(),
				'device'     => $dev,
				'country'    => FW_VI_Bot_Filter::country(),
				'first_path' => $in['u'],
				'paths'      => wp_json_encode( array( $in['u'] ) ),
				'pages'      => 1,
				'chunks'     => 1,
				'bytes'      => strlen( $gz ),
				'flags'      => count_chars( $flags, 3 ),
				'ev_first'   => $first,
				'ev_last'    => $last,
			) );
		} else {
			$paths = (array) json_decode( (string) $row->paths, true );
			if ( end( $paths ) !== $in['u'] && count( $paths ) < 50 ) {
				$paths[] = $in['u'];
			}
			$wpdb->update( $t, array( // phpcs:ignore WordPress.DB
				'last_seen' => time(),
				'paths'     => wp_json_encode( $paths ),
				'pages'     => count( $paths ),
				'chunks'    => (int) $row->chunks + 1,
				'bytes'     => (int) $row->bytes + strlen( $gz ),
				'flags'     => count_chars( $row->flags . $flags, 3 ),
				'ev_first'  => $first && ( ! (int) $row->ev_first || $first < (int) $row->ev_first ) ? $first : (int) $row->ev_first,
				'ev_last'   => max( $last, (int) $row->ev_last ),
			), array( 'sid' => $sid ) );
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * A visitor deleting their own recording (or withdrawing consent).
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function forget( $request ) {
		$in  = json_decode( (string) $request->get_body(), true );
		$sid = is_array( $in ) && isset( $in['sid'] ) && is_string( $in['sid'] ) ? $in['sid'] : '';

		if ( preg_match( '/^[a-z0-9]{24}$/', $sid ) ) {
			self::delete( $sid );
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Every event of one recording, for the player. Logged.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function events( $request ) {
		$sid = (string) $request['sid'];
		$dir = self::dir() . '/' . $sid;

		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'fw_vi_no_recording', __( 'This recording no longer exists.', 'fw' ), array( 'status' => 404 ) );
		}

		$files = glob( $dir . '/*.gz' );
		sort( $files );
		$events = array();

		foreach ( $files as $f ) {
			$chunk = json_decode( (string) gzdecode( (string) file_get_contents( $f ) ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_array( $chunk ) ) {
				foreach ( $chunk as $e ) {
					$events[] = $e;
				}
			}
		}

		usort( $events, function ( $a, $b ) {
			return ( $a['timestamp'] ?? 0 ) <=> ( $b['timestamp'] ?? 0 );
		} );

		$log = (array) get_option( self::OPT_LOG, array() );
		array_unshift( $log, array( 'user' => get_current_user_id(), 'sid' => $sid, 'at' => time() ) );
		update_option( self::OPT_LOG, array_slice( $log, 0, 200 ), false );

		$res = rest_ensure_response( $events );
		$res->header( 'Cache-Control', 'no-store' );

		return $res;
	}

	/* ---------------------------------------------------------------------- *
	 * Housekeeping
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $sid
	 */
	public static function delete( $sid ) {
		global $wpdb;

		$dir = self::dir() . '/' . $sid;

		foreach ( (array) glob( $dir . '/*' ) as $f ) {
			@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

		$wpdb->delete( FW_VI_Installer::table( 'recs' ), array( 'sid' => $sid ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Nightly: recordings past the retention period; and, with "keep only
	 * visits with problems", finished visits that had none. Also run when the
	 * Recordings tab opens.
	 */
	public static function prune() {
		global $wpdb;

		$s    = FW_VI_Settings::get();
		$t    = FW_VI_Installer::table( 'recs' );
		$old  = time() - max( 1, (int) $s['rec_retention'] ) * DAY_IN_SECONDS;
		$sids = $wpdb->get_col( $wpdb->prepare( "SELECT sid FROM {$t} WHERE last_seen < %d LIMIT 2000", $old ) ); // phpcs:ignore WordPress.DB

		if ( $s['rec_only_problems'] ) {
			$sids = array_merge( $sids, $wpdb->get_col( $wpdb->prepare( "SELECT sid FROM {$t} WHERE last_seen < %d AND flags NOT REGEXP '[rdex]' LIMIT 2000", time() - self::SESSION_IDLE - HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		}

		foreach ( array_unique( $sids ) as $sid ) {
			self::delete( $sid );
		}
	}

	/**
	 * "Remove all data": every recording and the folder.
	 */
	public static function delete_all() {
		global $wpdb;

		foreach ( (array) glob( self::dir() . '/*', GLOB_ONLYDIR ) as $d ) {
			self::delete( basename( $d ) );
		}

		$wpdb->query( 'TRUNCATE TABLE ' . FW_VI_Installer::table( 'recs' ) ); // phpcs:ignore WordPress.DB
	}

	/* ---------------------------------------------------------------------- *
	 * Admin tab
	 * ---------------------------------------------------------------------- */

	/**
	 * @return array path => number of recordings that include it
	 */
	public static function counts_by_path() {
		global $wpdb;

		if ( ! self::can_view() ) {
			return array();
		}

		$out = array();
		foreach ( $wpdb->get_col( 'SELECT paths FROM ' . FW_VI_Installer::table( 'recs' ) ) as $json ) { // phpcs:ignore WordPress.DB
			foreach ( array_unique( (array) json_decode( (string) $json, true ) ) as $p ) {
				$out[ $p ] = ( $out[ $p ] ?? 0 ) + 1;
			}
		}

		return $out;
	}

	/**
	 * @param string $path
	 *
	 * @return int recordings that include the page
	 */
	public static function count_for( $path ) {
		return self::counts_by_path()[ $path ] ?? 0;
	}

	/**
	 * @param string $action
	 *
	 * @return string|null
	 */
	public static function handle( $action ) {
		if ( ! self::can_view() ) {
			return null;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by FW_VI_Admin_Page
		if ( 'rec_delete' === $action && isset( $_POST['sid'] ) && preg_match( '/^[a-z0-9]{24}$/', (string) $_POST['sid'] ) ) {
			self::delete( (string) $_POST['sid'] );

			return __( 'Recording deleted.', 'fw' );
		}

		if ( 'rec_delete_all' === $action ) {
			self::delete_all();

			return __( 'All recordings deleted.', 'fw' );
		}
		// phpcs:enable

		return null;
	}

	/**
	 * @param callable $form_open
	 */
	public static function render( $form_open ) {
		global $wpdb;

		if ( ! self::can_view() ) {
			echo '<p>' . esc_html__( 'You are not allowed to watch recordings.', 'fw' ) . '</p>';
			return;
		}

		self::prune();

		$s   = FW_VI_Settings::get();
		$sid = isset( $_GET['sid'] ) && preg_match( '/^[a-z0-9]{24}$/', (string) $_GET['sid'] ) ? (string) $_GET['sid'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $sid ) {
			self::render_player( $sid );
			return;
		}

		if ( ! $s['recording'] ) {
			echo '<div class="fw-vi-empty"><h2>' . esc_html__( 'Session recording is off', 'fw' ) . '</h2><p>'
				. esc_html__( 'Switch it on under Settings → Session recording. Visits are recorded only after the visitor consents, form fields are always masked, and recordings stay on this site.', 'fw' )
				. '</p></div>';
		}

		$problems = isset( $_GET['problems'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rec_path = isset( $_GET['rec_path'] ) ? '/' . ltrim( sanitize_text_field( wp_unslash( $_GET['rec_path'] ) ), '/' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$t        = FW_VI_Installer::table( 'recs' );
		$where    = array();
		if ( $problems ) {
			$where[] = "flags REGEXP '[rdex]'";
		}
		if ( '' !== $rec_path ) {
			$where[] = $wpdb->prepare( 'paths LIKE %s', '%' . $wpdb->esc_like( wp_json_encode( $rec_path ) ) . '%' );
		}
		$rows = $wpdb->get_results( "SELECT * FROM {$t}" . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ' ORDER BY started DESC LIMIT 200' ); // phpcs:ignore WordPress.DB
		$total    = (int) $wpdb->get_var( "SELECT COALESCE(SUM(bytes),0) FROM {$t}" ); // phpcs:ignore WordPress.DB
		$flagname = array( 'r' => __( 'rage', 'fw' ), 'd' => __( 'dead click', 'fw' ), 'e' => __( 'error click', 'fw' ), 'x' => __( 'script error', 'fw' ), 'g' => __( 'goal', 'fw' ) );
		$devs     = array( 'd' => 'dashicons-desktop', 't' => 'dashicons-tablet', 'm' => 'dashicons-smartphone' );
		?>
		<div class="fw-vi-card">
			<h2><?php esc_html_e( 'Recordings', 'fw' ); ?></h2>
			<p class="description">
				<?php echo esc_html( sprintf( _n( '%1$s recording, %2$s on disk.', '%1$s recordings, %2$s on disk.', count( $rows ), 'fw' ), number_format_i18n( count( $rows ) ), size_format( $total ) ) ); ?>
				<?php echo esc_html( sprintf( __( 'Kept for %s days.', 'fw' ), (int) $s['rec_retention'] ) ); ?>
				<a href="<?php echo esc_url( FW_VI_Admin_Page::url( 'recordings', array_filter( array( 'problems' => $problems ? null : 1, 'rec_path' => $rec_path ?: null ) ) ) ); ?>"><?php echo $problems ? esc_html__( 'Show all', 'fw' ) : esc_html__( 'Only visits with problems', 'fw' ); ?></a>
				<?php if ( '' !== $rec_path ) : ?>
					· <?php echo wp_kses( sprintf( __( 'Visits that included <code>%s</code>', 'fw' ), esc_html( $rec_path ) ), array( 'code' => array() ) ); ?> <a href="<?php echo esc_url( FW_VI_Admin_Page::url( 'recordings' ) ); ?>"><?php esc_html_e( '(all pages)', 'fw' ); ?></a>
				<?php endif; ?>
			</p>
			<?php if ( $rows ) : ?>
				<table class="widefat striped fw-vi-table">
					<thead><tr><th><?php esc_html_e( 'When', 'fw' ); ?></th><th><?php esc_html_e( 'Pages', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Length', 'fw' ); ?></th><th><?php esc_html_e( 'What happened', 'fw' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<?php
						$paths = (array) json_decode( (string) $r->paths, true );
						// Length from the recorded events (the visitor's clock), not from
						// when batches happened to arrive.
						$len   = (int) $r->ev_last > (int) $r->ev_first ? (int) round( ( $r->ev_last - $r->ev_first ) / 1000 ) : max( 1, (int) $r->last_seen - (int) $r->started );
						?>
						<tr>
							<td><span class="dashicons <?php echo esc_attr( $devs[ $r->device ] ?? 'dashicons-desktop' ); ?>"></span> <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $r->started ) ); ?><?php echo $r->country ? ' · ' . esc_html( $r->country ) : ''; ?></td>
							<td><code><?php echo esc_html( $r->first_path ); ?></code><?php echo count( $paths ) > 1 ? ' ' . esc_html( sprintf( __( '+ %d more', 'fw' ), count( $paths ) - 1 ) ) : ''; ?></td>
							<td class="num"><?php echo esc_html( $len >= 60 ? floor( $len / 60 ) . 'm ' . ( $len % 60 ) . 's' : $len . 's' ); ?></td>
							<td><?php foreach ( str_split( (string) $r->flags ) as $f ) { if ( isset( $flagname[ $f ] ) ) { echo '<span class="fw-vi-chip is-' . ( 'g' === $f ? 'good' : 'poor' ) . '">' . esc_html( $flagname[ $f ] ) . '</span> '; } } ?></td>
							<td class="num">
								<a class="button button-small button-primary" href="<?php echo esc_url( FW_VI_Admin_Page::url( 'recordings', array( 'sid' => $r->sid ) ) ); ?>"><?php esc_html_e( 'Play', 'fw' ); ?></a>
								<form method="post" style="display:inline"><?php call_user_func( $form_open, 'rec_delete' ); ?><input type="hidden" name="sid" value="<?php echo esc_attr( $r->sid ); ?>"><button class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'fw' ); ?></button></form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" style="margin-top:12px" onsubmit="return confirm('<?php echo esc_js( __( 'Delete every recording?', 'fw' ) ); ?>')">
					<?php call_user_func( $form_open, 'rec_delete_all' ); ?>
					<button class="button"><?php esc_html_e( 'Delete all recordings', 'fw' ); ?></button>
				</form>
			<?php elseif ( $s['recording'] ) : ?>
				<p><?php esc_html_e( 'No recordings yet. A visit is recorded only after the visitor consents, and only a sample of them.', 'fw' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		$log = array_slice( (array) get_option( self::OPT_LOG, array() ), 0, 10 );
		if ( $log ) :
			?>
			<div class="fw-vi-card">
				<h2><?php esc_html_e( 'Recently watched', 'fw' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Every playback is logged.', 'fw' ); ?></p>
				<ul>
					<?php foreach ( $log as $l ) : ?>
						<?php $u = get_userdata( (int) $l['user'] ); ?>
						<li><?php echo esc_html( sprintf( __( '%1$s watched %2$s on %3$s', 'fw' ), $u ? $u->display_name : '#' . $l['user'], substr( $l['sid'], 0, 8 ) . '…', wp_date( get_option( 'date_format' ) . ' H:i', (int) $l['at'] ) ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		endif;
	}

	/**
	 * @param string $sid
	 */
	private static function render_player( $sid ) {
		global $wpdb;

		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . FW_VI_Installer::table( 'recs' ) . ' WHERE sid = %s', $sid ) ); // phpcs:ignore WordPress.DB

		if ( ! $r ) {
			echo '<p>' . esc_html__( 'This recording no longer exists.', 'fw' ) . '</p>';
			return;
		}

		$paths = (array) json_decode( (string) $r->paths, true );
		?>
		<p><a href="<?php echo esc_url( FW_VI_Admin_Page::url( 'recordings' ) ); ?>">← <?php esc_html_e( 'All recordings', 'fw' ); ?></a></p>
		<div class="fw-vi-card">
			<h2><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $r->started ) ); ?></h2>
			<p class="description"><?php echo esc_html( implode( ' → ', $paths ) ); ?></p>
			<div id="fw-vi-player" data-src="<?php echo esc_url( rest_url( self::NS . '/rec/' . $sid ) ); ?>"><p class="description"><?php esc_html_e( 'Loading…', 'fw' ); ?></p></div>
			<p class="description"><?php esc_html_e( 'Form fields are masked: what visitors typed was never recorded.', 'fw' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Player assets on the Recordings tab.
	 *
	 * @param FW_Extension_Visitor_Insights $ext
	 */
	public static function enqueue_player( $ext ) {
		wp_enqueue_style( 'fw-vi-rrweb-player', $ext->get_uri( '/static/js/vendor/rrweb/rrweb-player.min.css' ), array(), '2.1.7' );
		wp_enqueue_script( 'fw-vi-rrweb-player', $ext->get_uri( '/static/js/vendor/rrweb/rrweb-player.min.js' ), array(), '2.1.7', true );
		wp_enqueue_script( 'fw-vi-player', $ext->get_uri( '/static/js/admin/player.js' ), array( 'fw-vi-rrweb-player' ), $ext->manifest->get_version(), true );
		wp_localize_script( 'fw-vi-player', 'UPVIP', array(
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'empty' => __( 'This recording has nothing to play yet.', 'fw' ),
			'error' => __( 'The recording could not be loaded.', 'fw' ),
		) );
	}
}
