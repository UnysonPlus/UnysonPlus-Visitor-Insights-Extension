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
		require_once $dir . 'class-fw-vi-geo.php';
		require_once $dir . 'class-fw-vi-geo-admin.php';
		require_once $dir . 'class-fw-vi-visits.php';
		require_once $dir . 'class-fw-vi-outbound.php';
		require_once $dir . 'class-fw-vi-bots.php';
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
		require_once $dir . 'class-fw-vi-goals.php';
		require_once $dir . 'integrations/class-fw-vi-integrations.php';
		require_once $dir . 'class-fw-vi-funnels.php';
		require_once $dir . 'class-fw-vi-errors.php';
		require_once $dir . 'class-fw-vi-vitals.php';
		require_once $dir . 'class-fw-vi-form-analytics.php';
		require_once $dir . 'class-fw-vi-search.php';
		require_once $dir . 'class-fw-vi-experiments.php';
		require_once $dir . 'class-fw-vi-digest.php';
		require_once $dir . 'class-fw-vi-ai.php';
		require_once $dir . 'class-fw-vi-recordings.php';
		require_once $dir . 'class-fw-vi-channels.php';
		require_once $dir . 'class-fw-vi-cache.php';
		require_once $dir . 'class-fw-vi-dashboard.php';
		require_once $dir . 'class-fw-vi-export.php';
		require_once $dir . 'class-fw-vi-annotations.php';
		require_once $dir . 'class-fw-vi-segments.php';
		require_once $dir . 'class-fw-vi-alerts.php';

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
		new FW_VI_Goals();
		new FW_VI_Integrations();
		new FW_VI_Errors();
		new FW_VI_Search();
		new FW_VI_Experiments();
		new FW_VI_Digest();
		new FW_VI_AI();
		new FW_VI_Recordings( $this );
		new FW_VI_Alerts();
		new FW_VI_Bots();

		if ( is_admin() ) {
			require_once $dir . 'class-fw-vi-admin-page.php';
			require_once $dir . 'class-fw-vi-app.php';
			require_once $dir . 'class-fw-vi-transfer.php';
			require_once $dir . 'class-fw-vi-keywords.php';
			require_once $dir . 'class-fw-vi-referrers.php';

			new FW_VI_Dashboard();
			new FW_VI_Export();
			new FW_VI_Transfer();

			new FW_VI_Admin_Page( $this );
		}
	}
}
