<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Builder item ids on the public page — only on requests Visitor Insights
 * tracks or shows a heatmap for, so every other page keeps its clean DOM.
 *
 * Each builder item's wrapper gets `data-fw-item-id="<unique_id>"`, the same
 * attribute the Live Editor stamps in its canvas. The tracker attributes a
 * click to the innermost stamped element it landed in, and stores where inside
 * that element's box it was — so the heatmap re-projects it onto the element's
 * current size at any screen width.
 *
 * Two paths, as in the Live Editor: the wrapper-attribute filter for the
 * plugin's own views, and a stamp on the shortcode's output for views a theme
 * overrides without calling sc_build_wrapper_attr().
 */
class FW_VI_Element_IDs {

	public function __construct() {
		add_filter( 'sc_build_wrapper_attr', array( $this, '_filter_wrapper_attr' ), 998, 2 );
		add_filter( 'do_shortcode_tag', array( $this, '_filter_output' ), 11, 3 );
	}

	/**
	 * @return bool
	 */
	private function active() {
		return '' !== FW_VI_Front::mode();
	}

	/**
	 * @internal
	 */
	public function _filter_wrapper_attr( $attr, $atts ) {
		if ( $this->active() && ! empty( $atts['unique_id'] ) && empty( $attr['data-fw-item-id'] ) ) {
			$attr['data-fw-item-id'] = (string) $atts['unique_id'];
		}

		if ( $this->active() && empty( $attr['data-fw-vi-goal'] ) ) {
			$goal = FW_VI_Goals::element_key( $atts );
			if ( '' !== $goal ) {
				$attr['data-fw-vi-goal'] = $goal;
			}
		}

		return $attr;
	}

	/**
	 * @internal
	 */
	public function _filter_output( $output, $tag, $attr ) {
		if ( ! $this->active() || ! is_string( $output ) || '' === $output ) {
			return $output;
		}

		$uid = is_array( $attr ) && ! empty( $attr['unique_id'] ) ? (string) $attr['unique_id'] : '';

		if ( '' === $uid || ! preg_match( '/^[A-Za-z0-9_-]{6,32}$/', $uid ) || preg_match( '/^\s*<[a-zA-Z][^>]*\bdata-fw-item-id=/', $output ) ) {
			return $output;
		}

		$goal    = FW_VI_Goals::element_key( $attr );
		$extra   = '' !== $goal ? ' data-fw-vi-goal="' . esc_attr( $goal ) . '"' : '';
		$stamped = preg_replace( '/<([a-zA-Z][a-zA-Z0-9-]*)/', '<$1 data-fw-item-id="' . esc_attr( $uid ) . '"' . $extra, $output, 1 );

		return is_string( $stamped ) ? $stamped : $output;
	}
}
