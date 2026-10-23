<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Automatic goals: conversions other parts of the site already announce,
 * counted server-side the moment they happen — so they work even when the
 * thank-you step is a redirect, an AJAX response or another page.
 *
 *   form_submit        Forms extension        fw_ext_forms_frontend_submit
 *   newsletter_signup  CRM         unysonplus_crm_subscriber_added
 *   woo_order          WooCommerce            woocommerce_checkout_order_processed
 *                                             woocommerce_store_api_checkout_order_processed
 *                                             (value = order total, in cents)
 *   short_link_click   Short Links            fw_ext_short_links_click
 *
 * Each is offered only while its source is active, and can be switched off on
 * the Goals tab. The page a conversion is credited to is the page on this site
 * that sent the request (the form's page, the checkout).
 */
class FW_VI_Integrations {

	public function __construct() {
		add_action( 'fw_ext_forms_frontend_submit', array( $this, '_form' ) );
		add_action( 'unysonplus_crm_subscriber_added', array( $this, '_newsletter' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, '_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, '_order' ), 10, 1 );
		add_action( 'fw_ext_short_links_click', array( $this, '_short_link' ), 10, 1 );
	}

	/**
	 * @return array key => label, for the sources active on this site
	 */
	public static function available() {
		$out = array();
		$ext = function ( $name ) {
			return function_exists( 'fw_ext' ) && fw_ext( $name );
		};

		if ( $ext( 'forms' ) ) {
			$out['form_submit'] = __( 'Form submitted', 'fw' );
		}
		if ( $ext( 'crm' ) ) {
			$out['newsletter_signup'] = __( 'Newsletter sign-up', 'fw' );
		}
		if ( class_exists( 'WooCommerce' ) ) {
			$out['woo_order'] = __( 'Order placed', 'fw' );
		}
		if ( $ext( 'short-links' ) ) {
			$out['short_link_click'] = __( 'Short link clicked', 'fw' );
		}

		return $out;
	}

	/**
	 * @param string $key
	 *
	 * @return bool
	 */
	private static function on( $key ) {
		$on = (array) get_option( FW_VI_Goals::OPT_INTEG, array() );

		return ! isset( $on[ $key ] ) || $on[ $key ];
	}

	/**
	 * @param string $key
	 * @param int    $value
	 * @param string $path
	 */
	private static function record( $key, $value = 0, $path = '' ) {
		if ( self::on( $key ) ) {
			FW_VI_Collector::server_line( array( array( 'g', $key, max( 0, (int) $value ) ) ), $path );
		}
	}

	/**
	 * @internal
	 */
	public function _form() {
		self::record( 'form_submit' );
	}

	/**
	 * Sign-ups from the site's own forms only — not imports or admin edits.
	 *
	 * @internal
	 */
	public function _newsletter( $subscriber = null, $context = null ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$ctx = is_array( $context ) ? (string) ( $context['context'] ?? '' ) : (string) $context;

		if ( in_array( $ctx, array( 'import', 'admin', 'membership', 'api' ), true ) ) {
			return;
		}

		self::record( 'newsletter_signup' );
	}

	/**
	 * Classic and block checkout both land here; the order is counted once.
	 *
	 * @internal
	 *
	 * @param int|WC_Order $order
	 */
	public function _order( $order ) {
		$order = is_numeric( $order ) && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order ) : $order;

		if ( ! is_object( $order ) || ! method_exists( $order, 'get_total' ) || $order->get_meta( '_fw_vi_goal' ) ) {
			return;
		}

		$order->update_meta_data( '_fw_vi_goal', 1 );
		$order->save_meta_data();

		self::record( 'woo_order', (int) round( (float) $order->get_total() * 100 ) );
	}

	/**
	 * @internal
	 */
	public function _short_link() {
		self::record( 'short_link_click' );
	}
}
