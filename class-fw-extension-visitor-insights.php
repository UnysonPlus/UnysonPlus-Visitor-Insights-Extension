<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Visitor Insights — self-hosted, privacy-first visitor analytics.
 *
 * Data path:
 *
 *   static/js/vi-core.js (+ modules)  → batches of events, sent with sendBeacon
 *   collector/collect.php             → validates, appends to a per-minute buffer
 *                                       file; never boots WordPress
 *     (REST /collect is the automatic fallback when that file is unreachable)
 *   FW_VI_Rollup (every minute)       → buffer files → pre-aggregated daily tables
 *   FW_VI_Reports / Heatmaps / Sections → reads for the admin screens
 *
 * Layering: Installer is the only DDL; Collector owns the config file the
 * standalone endpoint reads (secret, salt, exclusions) and the page tokens;
 * Rollup is the only writer of the report tables; Front decides whether a
 * request is tracked and stamps builder item ids on the pages that are.
 */
class FW_Extension_Visitor_Insights extends FW_Extension {

	/**
	 * @internal
	 */
	public function _init() {
		$dir = dirname( __FILE__ ) . '/includes/';

		require_once $dir . 'class-fw-vi-settings.php';
		require_once $dir . 'class-fw-vi-installer.php';
		require_once $dir . 'class-fw-vi-bot-filter.php';
		require_once dirname( __FILE__ ) . '/collector/class-fw-vi-ingest.php';
		require_once $dir . 'class-fw-vi-collector.php';
		require_once $dir . 'class-fw-vi-rollup.php';
		require_once $dir . 'class-fw-vi-retention.php';
		require_once $dir . 'class-fw-vi-rest.php';
		require_once $dir . 'class-fw-vi-consent.php';
		require_once $dir . 'class-fw-vi-privacy.php';
		require_once $dir . 'class-fw-vi-element-ids.php';
		require_once $dir . 'class-fw-vi-front.php';
		require_once $dir . 'class-fw-vi-reports.php';
		require_once $dir . 'class-fw-vi-heatmaps.php';
		require_once $dir . 'class-fw-vi-sections.php';

		FW_VI_Installer::maybe_install();

		new FW_VI_Collector( $this );
		new FW_VI_Rollup();
		new FW_VI_Retention();
		new FW_VI_REST();
		new FW_VI_Consent( $this );
		new FW_VI_Privacy();
		new FW_VI_Element_IDs();
		new FW_VI_Front( $this );
		new FW_VI_Heatmaps( $this );
		new FW_VI_Sections( $this );

		if ( is_admin() ) {
			require_once $dir . 'class-fw-vi-admin-page.php';

			new FW_VI_Admin_Page( $this );
		}
	}
}
