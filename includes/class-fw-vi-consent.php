<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The consent gate. The decision is made in the browser (vi-core.js), never
 * here: full-page caches serve one copy of the HTML to everyone, so a PHP-side
 * consent check would be wrong for most visitors. PHP only describes the
 * rules to the tracker, declares compliance to the WordPress Consent API, and
 * offers the opt-out control.
 *
 * Rules, in order (vi-core.js `allowed()`):
 *   1. a visitor who opted out is never tracked;
 *   2. Global Privacy Control, when honoured, means never;
 *   3. a consent plugin speaking the WP Consent API decides through
 *      wp_has_consent( 'statistics-anonymous' ), and its changes apply live;
 *   4. with no consent plugin: "auto" tracks anonymously (opt-out available),
 *      "require" waits for window.UnysonPlusVI.grant() from your own banner.
 */
class FW_VI_Consent {

	const CATEGORY = 'statistics-anonymous';
	const OPT_OUT  = 'upvi_optout'; // localStorage key; strictly necessary

	/** @var FW_Extension_Visitor_Insights */
	private $ext;

	/**
	 * @param FW_Extension_Visitor_Insights $ext
	 */
	public function __construct( $ext ) {
		$this->ext = $ext;

		$plugin = plugin_basename( dirname( fw_get_framework_directory() ) . '/unysonplus.php' );
		add_filter( 'wp_consent_api_registered_' . $plugin, '__return_true' );

		add_action( 'init', array( $this, '_action_cookie_info' ) );
		add_shortcode( 'visitor_insights_optout', array( $this, '_shortcode_optout' ) );
	}

	/**
	 * What the tracker needs to apply the rules above.
	 *
	 * @return array
	 */
	public static function js_config() {
		$s = FW_VI_Settings::get();

		return array(
			'category'       => self::CATEGORY,
			'requireConsent' => 'require' === $s['consent_mode'],
			'gpc'            => (bool) $s['honor_gpc'],
			'consentApi'     => function_exists( 'wp_has_consent' ),
			'optOutKey'      => self::OPT_OUT,
		);
	}

	/**
	 * List the opt-out flag in consent plugins' cookie tables.
	 *
	 * @internal
	 */
	public function _action_cookie_info() {
		if ( function_exists( 'wp_add_cookie_info' ) ) {
			wp_add_cookie_info(
				self::OPT_OUT,
				__( 'Visitor Insights', 'fw' ),
				'functional',
				__( 'Until you opt back in', 'fw' ),
				__( 'Remembers that you opted out of anonymous visitor statistics. Stored in your browser only.', 'fw' ),
				false,
				false,
				'localstorage'
			);
		}
	}

	/**
	 * [visitor_insights_optout] — a line of text and a button that opts this
	 * browser out of (or back into) anonymous statistics.
	 *
	 * @internal
	 *
	 * @return string
	 */
	public function _shortcode_optout() {
		$id  = 'upvi-optout-' . wp_rand( 1000, 9999 );
		$key = self::OPT_OUT;
		$in  = esc_js( __( 'This site counts visits anonymously, without cookies.', 'fw' ) );
		$out = esc_js( __( 'You have opted out: your visits are not counted.', 'fw' ) );
		$bo  = esc_js( __( 'Opt out', 'fw' ) );
		$bi  = esc_js( __( 'Opt back in', 'fw' ) );

		return '<p class="upvi-optout" id="' . esc_attr( $id ) . '"><span></span> <button type="button"></button></p>'
			. "<script>(function(){var r=document.getElementById('{$id}'),t=r.querySelector('span'),b=r.querySelector('button'),k='{$key}';"
			. "function o(){try{return localStorage.getItem(k)==='1'}catch(e){return false}}"
			. "function p(){var x=o();t.textContent=x?'{$out}':'{$in}';b.textContent=x?'{$bi}':'{$bo}'}"
			. "b.addEventListener('click',function(){try{o()?localStorage.removeItem(k):localStorage.setItem(k,'1')}catch(e){}p()});p()})();</script>";
	}
}
