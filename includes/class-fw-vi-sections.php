<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Builder-section analytics: for each section of a page, how many page views
 * reached it and how long it was in view. A section counts as "in view" while
 * at least half of it is visible, or it fills at least half the screen (tall
 * sections never show half of themselves at once), and only while the tab is
 * in front — see static/js/modules/sections.js.
 *
 * Also puts those numbers where pages are edited:
 *  - the backend page builder: a badge on each item's control bar (share of
 *    the page's clicks; reach for sections) — static/js/admin/builder-badges.js;
 *  - the Live Editor canvas: a "Heatmap" button that overlays the full heatmap
 *    viewer in place — static/js/live-editor-overlay.js.
 */
class FW_VI_Sections {

	const BADGE_DAYS = 30;

	/** @var FW_Extension_Visitor_Insights */
	private $ext;

	/**
	 * @param FW_Extension_Visitor_Insights $ext
	 */
	public function __construct( $ext ) {
		$this->ext = $ext;

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, '_action_builder_badges' ), 20 );
		} else {
			add_action( 'wp_enqueue_scripts', array( $this, '_action_live_editor' ), 20 );
		}
	}

	/**
	 * @param int    $page_id
	 * @param string $device d|t|m
	 * @param string $from   Y-m-d
	 * @param string $to     Y-m-d
	 *
	 * @return array [ [item, reached, visible_ms] ], highest reach first
	 */
	public static function for_page( $page_id, $device, $from, $to ) {
		global $wpdb;

		$t = FW_VI_Installer::table( 'sections' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT item, SUM(reached) reached, SUM(visible_ms) ms FROM {$t}
			WHERE page_id = %d AND device = %s AND day BETWEEN %s AND %s
			GROUP BY item ORDER BY reached DESC",
			$page_id, $device, $from, $to
		) ); // phpcs:ignore WordPress.DB

		return array_map( function ( $r ) {
			return array( $r->item, (int) $r->reached, (int) $r->ms );
		}, $rows );
	}

	/**
	 * Every builder item of a post, read from its builder data:
	 * unique_id => { type, label, section (bool, top level), parent_section }.
	 * The label is the item's own text (title, heading, label, text …) or, for
	 * a section, the first such text inside it — so reports and the AI can say
	 * "the Pricing section" instead of an id.
	 *
	 * @param int $post_id
	 *
	 * @return array
	 */
	public static function items_of( $post_id ) {
		static $cache = array();

		if ( isset( $cache[ $post_id ] ) ) {
			return $cache[ $post_id ];
		}

		$json  = get_post_meta( $post_id, 'fw:opt:ext:pb:page-builder:json', true );
		$tree  = is_string( $json ) ? json_decode( $json, true ) : null;
		$items = array();

		$text = function ( $atts ) {
			foreach ( array( 'title', 'heading', 'label', 'text', 'button_text', 'content', 'subtitle' ) as $k ) {
				if ( isset( $atts[ $k ] ) && is_string( $atts[ $k ] ) ) {
					$t = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $atts[ $k ] ), ENT_QUOTES, 'UTF-8' ) ) );
					if ( '' !== $t ) {
						return mb_substr( $t, 0, 70 );
					}
				}
			}
			return '';
		};

		$walk = function ( $list, $section, $depth ) use ( &$walk, &$items, $text ) {
			$first = '';
			foreach ( (array) $list as $it ) {
				if ( ! is_array( $it ) ) {
					continue;
				}
				$atts  = isset( $it['atts'] ) && is_array( $it['atts'] ) ? $it['atts'] : array();
				$id    = isset( $atts['unique_id'] ) ? (string) $atts['unique_id'] : '';
				$type  = (string) ( $it['shortcode'] ?? $it['type'] ?? '' );
				$is_s  = 'section' === ( $it['type'] ?? '' ) && 0 === $depth;
				$own   = $text( $atts );
				$inner = ! empty( $it['_items'] ) ? $walk( $it['_items'], $is_s ? $id : $section, $depth + 1 ) : '';

				if ( '' !== $id ) {
					$items[ $id ] = array(
						'type'    => $type,
						'label'   => '' !== $own ? $own : $inner,
						'section' => $is_s,
						'parent'  => $is_s ? '' : $section,
					);
				}

				if ( '' === $first ) {
					$first = '' !== $own ? $own : $inner;
				}
			}
			return $first;
		};

		if ( is_array( $tree ) ) {
			$walk( $tree, '', 0 );
		}

		return $cache[ $post_id ] = $items;
	}

	/**
	 * Per builder item of a post, all devices, the last 30 days:
	 * [ share of the page's clicks in %, reach in % (sections) or null ].
	 *
	 * @param int $post_id
	 *
	 * @return array { views, items }
	 */
	public static function builder_summary( $post_id ) {
		global $wpdb;

		$url  = get_permalink( $post_id );
		$path = $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
		$page = '' !== $path ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . FW_VI_Installer::table( 'pages' ) . ' WHERE path = %s', $path ) ) : 0; // phpcs:ignore WordPress.DB

		if ( ! $page ) {
			return array( 'views' => 0, 'items' => array() );
		}

		list( $from, $to ) = FW_VI_Reports::range( self::BADGE_DAYS );
		$args = array( $page, $from, $to );
		$cond = 'WHERE page_id = %d AND day BETWEEN %s AND %s';

		$views  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(views),0) FROM ' . FW_VI_Installer::table( 'views' ) . " {$cond}", $args ) ); // phpcs:ignore WordPress.DB
		$clicks = $wpdb->get_results( $wpdb->prepare( 'SELECT item, SUM(n) n FROM ' . FW_VI_Installer::table( 'elem' ) . " {$cond} GROUP BY item", $args ), OBJECT_K ); // phpcs:ignore WordPress.DB
		$grid   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(n),0) FROM ' . FW_VI_Installer::table( 'clicks' ) . " {$cond}", $args ) ); // phpcs:ignore WordPress.DB
		$reach  = $wpdb->get_results( $wpdb->prepare( 'SELECT item, SUM(reached) r FROM ' . FW_VI_Installer::table( 'sections' ) . " {$cond} GROUP BY item", $args ), OBJECT_K ); // phpcs:ignore WordPress.DB

		$total = max( 1, $grid + array_sum( array_map( 'intval', wp_list_pluck( $clicks, 'n' ) ) ) );
		$items = array();

		foreach ( $clicks as $id => $r ) {
			$items[ $id ] = array( round( 100 * $r->n / $total, 1 ), null );
		}

		foreach ( $reach as $id => $r ) {
			$share        = isset( $items[ $id ] ) ? $items[ $id ][0] : 0;
			$items[ $id ] = array( $share, $views ? min( 100, (int) round( 100 * $r->r / $views ) ) : null );
		}

		return array( 'views' => $views, 'items' => $items );
	}

	/**
	 * @internal
	 */
	public function _action_builder_badges( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! FW_VI_Settings::can_view() ) {
			return;
		}

		$post = get_post();

		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		$summary = self::builder_summary( $post->ID );

		if ( ! $summary['items'] ) {
			return;
		}

		wp_enqueue_script( 'fw-vi-builder', $this->ext->get_uri( '/static/js/admin/builder-badges.js' ), array( 'jquery', 'fw-events' ), $this->ext->manifest->get_version(), true );
		wp_localize_script( 'fw-vi-builder', 'UPVIB', array(
			'items' => $summary['items'],
			'l10n'  => array(
				'reach'       => __( 'Reached by %s of page views', 'fw' ),
				'clicks'      => __( '%s of the page\'s clicks', 'fw' ),
				'clicksShort' => __( 'clicks', 'fw' ),
				'period'      => sprintf( __( 'Visitor Insights, last %d days', 'fw' ), self::BADGE_DAYS ),
			),
		) );

		wp_register_style( 'fw-vi-builder', false, array(), $this->ext->manifest->get_version() );
		wp_enqueue_style( 'fw-vi-builder' );
		wp_add_inline_style( 'fw-vi-builder', '.fw-vi-badge{display:inline-flex;align-items:center;height:18px;padding:0 6px;margin:0 4px 0 0;border-radius:9px;background:#fff4e5;color:#9a3412;font:600 10px/18px -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;white-space:nowrap;vertical-align:middle;cursor:default}' );
	}

	/**
	 * @internal
	 */
	public function _action_live_editor() {
		if ( ! isset( $_GET['fw-live-editor-frame'] ) || ! is_user_logged_in() || ! FW_VI_Settings::can_view() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$ver = $this->ext->manifest->get_version();

		wp_enqueue_script( 'fw-vi-live-editor', $this->ext->get_uri( '/static/js/live-editor-overlay.js' ), array(), $ver, true );
		wp_localize_script( 'fw-vi-live-editor', 'UPVILE', array(
			'rest'       => esc_url_raw( rest_url( FW_VI_REST::NS . '/heatmap' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'path'       => FW_VI_Front::path(),
			'simpleheat' => $this->ext->get_uri( '/static/js/vendor/simpleheat.js' ) . '?ver=0.4.0',
			'viewer'     => $this->ext->get_uri( '/static/js/admin/heatmap-viewer.js' ) . '?ver=' . $ver,
			'label'      => __( 'Heatmap', 'fw' ),
			'title'      => __( 'Show this page\'s Visitor Insights heatmap', 'fw' ),
			'l10n'       => FW_VI_Heatmaps::l10n(),
			'channels'   => FW_VI_Heatmaps::channel_choices(),
			'h2c'        => $this->ext->get_uri( '/static/js/vendor/html2canvas.min.js' ) . '?ver=2.4.5',
		) );
	}
}
