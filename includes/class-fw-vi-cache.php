<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Clears full-page caches when something that is baked into cached pages
 * changes: the tracker settings (modules, sampling, consent rules) and A/B
 * tests (the variant CSS sits in <head>). Without it a cached page keeps the
 * old tracker config — or keeps hiding a section — until its cache expires.
 *
 * The common page-cache plugins each expose a "clear everything" function or
 * action; the ones present are called. Sites with another cache can hook
 * fw_ext_visitor_insights_purge_cache, which always fires.
 */
class FW_VI_Cache {

	/**
	 * @return bool whether a known page cache was found and cleared
	 */
	public static function purge() {
		$found = false;

		foreach ( array( 'rocket_clean_domain', 'w3tc_flush_all', 'wp_cache_clear_cache', 'sg_cachepress_purge_cache', 'wpfc_clear_all_cache' ) as $fn ) {
			if ( function_exists( $fn ) ) {
				call_user_func( $fn );
				$found = true;
			}
		}

		foreach ( array( 'litespeed_purge_all', 'breeze_clear_all_cache', 'cache_enabler_clear_complete_cache', 'rt_nginx_helper_purge_all', 'swcfpc_purge_cache' ) as $action ) {
			if ( has_action( $action ) ) {
				do_action( $action );
				$found = true;
			}
		}

		/** Fires when Visitor Insights needs cached pages refreshed; hook your own cache here. */
		do_action( 'fw_ext_visitor_insights_purge_cache' );

		return $found;
	}
}
