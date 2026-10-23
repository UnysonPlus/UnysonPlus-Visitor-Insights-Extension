<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Suggested privacy-policy text (Settings → Privacy → Policy Guide).
 *
 * There is no personal-data exporter or eraser on purpose: Visitor Insights
 * stores totals per page and day, and the one per-visitor value — a hash used
 * only to count each visitor once a day — is deleted with its salt the next
 * day. Nothing it keeps can be found by a person's email or name, so there is
 * nothing to export or erase. Session recording (a later, consent-only module)
 * will register both.
 */
class FW_VI_Privacy {

	public function __construct() {
		add_action( 'admin_init', array( $this, '_action_policy' ) );
	}

	/**
	 * @internal
	 */
	public function _action_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for sites using Unyson+ Visitor Insights. Adjust it to the consent settings you chose.', 'fw' ) . '</p>'
			. '<strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'fw' ) . ' </strong>'
			. '<p>' . esc_html__( 'We measure how our website is used so we can improve it: which pages are visited, where on a page visitors click, how far they scroll, and which sections they read. This is done by our own website software; the data stays on our server and is not shared with anyone.', 'fw' ) . '</p>'
			. '<p>' . esc_html__( 'We do not use cookies for this and do not store your IP address. To count each visitor only once a day, your browser details are turned into a code that is reset every day and cannot be traced back to you. We keep only totals — for example "120 visits to this page on Monday" — never a record of your visit.', 'fw' ) . '</p>'
			. '<p>' . esc_html__( 'If your browser sends the Global Privacy Control signal, or you have declined statistics in our cookie banner, you are not counted. You can also opt out at any time on this page.', 'fw' ) . '</p>'
			. '<p class="privacy-policy-tutorial">' . esc_html__( 'Place the [visitor_insights_optout] shortcode next to the last sentence so visitors can opt out.', 'fw' ) . '</p>';

		if ( FW_VI_Settings::get()['recording'] ) {
			$text .= '<p>' . esc_html__( 'If you agree to it in our cookie banner, we may record your visit — how the pages moved and where you clicked and scrolled — to find and fix problems on the site. What you type into forms is never recorded: it is hidden in your browser before anything is sent. Recordings stay on our own server, are seen only by our site administrators and are deleted automatically after a few weeks. You can withdraw your consent at any time, which stops the recording and deletes it, or delete it yourself with the button on this page.', 'fw' ) . '</p>'
				. '<p class="privacy-policy-tutorial">' . esc_html__( 'Place the [visitor_insights_recording] shortcode next to this paragraph so visitors can delete their own recording.', 'fw' ) . '</p>';
		}

		wp_add_privacy_policy_content( __( 'Unyson+ Visitor Insights', 'fw' ), wp_kses_post( $text ) );
	}
}
