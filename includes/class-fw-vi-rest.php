<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * fw-visitor-insights/v1
 *
 *   POST /collect  — public. The fallback ingest, used when the standalone
 *                    collector is unreachable on this server. Same validation
 *                    (FW_VI_Ingest), plus the shared per-client rate limit.
 *   GET  /heatmap  — reports; the heatmap viewer's data for one page.
 *   GET  /live     — reports; visitors in the last five minutes.
 */
class FW_VI_REST {

	const NS = 'fw-visitor-insights/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, '_action_routes' ) );
	}

	/**
	 * @internal
	 */
	public function _action_routes() {
		register_rest_route( self::NS, '/collect', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'collect' ),
			'permission_callback' => '__return_true', // public by design; checked in FW_VI_Ingest
		) );

		register_rest_route( self::NS, '/heatmap', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'heatmap' ),
			'permission_callback' => array( 'FW_VI_Settings', 'can_view' ),
			'args'                => array(
				'path'   => array( 'type' => 'string', 'required' => true ),
				'device' => array( 'type' => 'string', 'enum' => array( 'd', 't', 'm' ), 'default' => 'd' ),
				'days'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 730, 'default' => 30 ),
				'ch'     => array( 'type' => 'string', 'default' => '' ),
			),
		) );

		register_rest_route( self::NS, '/live', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'live' ),
			'permission_callback' => array( 'FW_VI_Settings', 'can_view' ),
		) );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function collect( $request ) {
		if ( function_exists( 'fw_rate_limit_exceeded' ) && fw_rate_limit_exceeded( 'fw_vi_collect', 120, 60 ) ) {
			return new WP_REST_Response( null, 429 );
		}

		$status = FW_VI_Ingest::handle( FW_VI_Collector::config(), $request->get_body() );

		$res = new WP_REST_Response( null, $status );
		$res->header( 'Cache-Control', 'no-store' );

		return $res;
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function heatmap( $request ) {
		FW_VI_Rollup::run();

		$ch = sanitize_key( (string) $request['ch'] );

		return rest_ensure_response( FW_VI_Heatmaps::data(
			(string) $request['path'],
			(string) $request['device'],
			(int) $request['days'],
			isset( FW_VI_Channels::labels()[ $ch ] ) ? $ch : ''
		) );
	}

	/**
	 * @return WP_REST_Response
	 */
	public function live() {
		FW_VI_Rollup::run();

		return rest_ensure_response( FW_VI_Reports::live_visitors() );
	}
}
