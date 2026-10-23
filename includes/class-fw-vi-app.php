<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Visitor Insights screens' navigation.
 *
 * The frame — the folded WordPress menu, the sidebar, the top bar, focus mode —
 * is the framework's `FW_Admin_App`, the same one SEO, the CRM and Membership
 * render inside. What is left here is the part that is about Visitor Insights:
 * which views exist and how they are grouped.
 *
 * Fourteen views as horizontal tabs wrapped onto two rows; grouped in a sidebar
 * by the question each answers, the one you want is always in sight.
 */
class FW_VI_App {

	/** @var FW_Admin_App|null */
	protected static $app = null;

	/**
	 * @return FW_Admin_App
	 */
	public static function app() {
		if ( self::$app ) {
			return self::$app;
		}

		if ( ! class_exists( 'FW_Admin_App' ) ) {
			require_once fw_get_framework_directory( '/includes/admin-app/class-fw-admin-app.php' );
		}

		self::$app = new FW_Admin_App( 'visitor-insights', array(
			'brand'      => array(
				'label' => __( 'Visitor Insights', 'fw' ),
				'icon'  => 'dashicons-chart-area',
			),
			'page'       => FW_VI_Admin_Page::PAGE_SLUG,
			'arg'        => 'tab',
			'default'    => 'overview',
			'capability' => (string) apply_filters( 'fw_ext_visitor_insights_view_cap', 'manage_options' ),
			'nav'        => array( __CLASS__, 'nav' ),
		) );

		return self::$app;
	}

	/**
	 * The sidebar, in groups. Setup is for administrators only; everything else
	 * follows the view capability.
	 *
	 * @return array group => { label, items: view => { label, icon, cap? } }
	 */
	public static function nav() {
		$nav = array(
			'main'       => array(
				'label' => '',
				'items' => array(
					'overview' => array( 'label' => __( 'Overview', 'fw' ), 'icon' => 'dashicons-chart-area' ),
					'live'     => array( 'label' => __( 'Live', 'fw' ), 'icon' => 'dashicons-visibility' ),
				),
			),
			'behaviour'  => array(
				'label' => __( 'Behaviour', 'fw' ),
				'items' => array(
					'pages'      => array( 'label' => __( 'Pages', 'fw' ), 'icon' => 'dashicons-admin-page' ),
					'heatmaps'   => array( 'label' => __( 'Heatmaps', 'fw' ), 'icon' => 'dashicons-location' ),
					'recordings' => array( 'label' => __( 'Recordings', 'fw' ), 'icon' => 'dashicons-video-alt3' ),
					'forms'      => array( 'label' => __( 'Forms', 'fw' ), 'icon' => 'dashicons-feedback' ),
					'search'     => array( 'label' => __( 'Search', 'fw' ), 'icon' => 'dashicons-search' ),
				),
			),
			'conversion' => array(
				'label' => __( 'Conversions', 'fw' ),
				'items' => array(
					'goals'       => array( 'label' => __( 'Goals', 'fw' ), 'icon' => 'dashicons-flag' ),
					'funnels'     => array( 'label' => __( 'Funnels', 'fw' ), 'icon' => 'dashicons-filter' ),
					'experiments' => array( 'label' => __( 'A/B tests', 'fw' ), 'icon' => 'dashicons-image-flip-horizontal' ),
				),
			),
			'health'     => array(
				'label' => __( 'Site health', 'fw' ),
				'items' => array(
					'frustration' => array( 'label' => __( 'Frustration', 'fw' ), 'icon' => 'dashicons-warning' ),
					'performance' => array( 'label' => __( 'Performance', 'fw' ), 'icon' => 'dashicons-performance' ),
				),
			),
			'setup'      => array(
				'label' => __( 'Setup', 'fw' ),
				'items' => array(
					'transfer' => array( 'label' => __( 'Import / Export', 'fw' ), 'icon' => 'dashicons-database-import', 'cap' => 'manage_options' ),
					'settings' => array( 'label' => __( 'Settings', 'fw' ), 'icon' => 'dashicons-admin-generic', 'cap' => 'manage_options' ),
				),
			),
		);

		/**
		 * Filters the Visitor Insights sidebar:
		 * group => { label, items: view => { label, icon, cap? } }.
		 */
		return (array) apply_filters( 'fw_ext_visitor_insights_nav', $nav );
	}

	/**
	 * @return string[] Every view the current person may open.
	 */
	public static function views() {
		$views = array();

		foreach ( self::app()->nav() as $group ) {
			$views = array_merge( $views, array_keys( (array) $group['items'] ) );
		}

		return $views;
	}
}
