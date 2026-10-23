<?php
/**
 * Visitor Insights — the standalone collector.
 *
 * Receives the tracker's batches WITHOUT booting WordPress (no plugins, no
 * database connection), which is what lets it absorb traffic peaks on modest
 * hosting: one file read for the config, one locked append to this minute's
 * buffer file, 204. FW_VI_Rollup folds the buffer into the report tables.
 *
 * It finds its config by walking up to the content directory (the folder that
 * holds both `uploads` and `plugins` / `themes`). When that fails — a moved
 * uploads folder, a host that refuses PHP here — the self-test run from
 * WordPress fails too, and the tracker is pointed at the REST route instead.
 */

define( 'FW_VI_COLLECTOR', true );

header( 'Cache-Control: no-store, max-age=0' );
header( 'X-Robots-Tag: noindex, nofollow' );
header( 'X-Content-Type-Options: nosniff' );

if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '' ) ) {
	http_response_code( 405 );
	exit;
}

require __DIR__ . '/class-fw-vi-ingest.php';
require dirname( __DIR__ ) . '/includes/class-fw-vi-bot-filter.php';
require dirname( __DIR__ ) . '/includes/class-fw-vi-geo.php';

/**
 * @return string|null the content directory (wp-content), no trailing slash
 */
function fw_vi_collector_content_dir() {
	$dir = __DIR__;

	for ( $i = 0; $i < 12; $i++ ) {
		$parent = dirname( $dir );

		if ( $parent === $dir ) {
			return null;
		}

		$dir = $parent;

		if ( is_dir( $dir . '/uploads' ) && ( is_dir( $dir . '/plugins' ) || is_dir( $dir . '/themes' ) ) ) {
			return $dir;
		}
	}

	return null;
}

$fw_vi_blog    = isset( $_GET['b'] ) ? max( 1, (int) $_GET['b'] ) : 1; // phpcs:ignore WordPress.Security
$fw_vi_content = fw_vi_collector_content_dir();
$fw_vi_config  = null;

if ( $fw_vi_content ) {
	$fw_vi_file = $fw_vi_content . '/uploads' . ( $fw_vi_blog > 1 ? '/sites/' . $fw_vi_blog : '' ) . '/unysonplus/visitor-insights/config.php';

	if ( is_file( $fw_vi_file ) ) {
		$fw_vi_config = include $fw_vi_file;
	}
}

if ( ! is_array( $fw_vi_config ) || empty( $fw_vi_config['secret'] ) ) {
	http_response_code( 503 );
	exit;
}

$fw_vi_body = (string) file_get_contents( 'php://input', false, null, 0, FW_VI_Ingest::MAX_BODY + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions

// The self-test WordPress runs to decide between this file and the REST route.
if ( '{"test":1}' === $fw_vi_body ) {
	http_response_code( 200 );
	header( 'Content-Type: text/plain' );
	echo 'upvi-ok';
	exit;
}

http_response_code( FW_VI_Ingest::handle( $fw_vi_config, $fw_vi_body ) );
