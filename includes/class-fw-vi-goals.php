<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Goals: the things a visit can achieve, counted without writing code.
 *
 * Four kinds, one key space (`[a-z0-9_-]`, ≤ 40):
 *  - element     — "Track as goal" in any builder element's Advanced tab. The
 *                  element is stamped `data-fw-vi-goal="<key>"` on tracked pages
 *                  and static/js/modules/goals.js counts its clicks. A key is
 *                  registered the first time a page carrying it is rendered.
 *  - page        — reaching a path (`*` wildcard), matched by the rollup on
 *                  each page view, so it needs nothing on the page itself.
 *  - event       — `UnysonPlusVI.goal( 'key', value )` from the site's own JS.
 *  - integration — conversions other extensions and the shop already
 *                  announce (see FW_VI_Integrations), recorded server-side.
 *
 * Only known, enabled keys are counted (the rollup checks), so a forged batch
 * cannot invent goals. Completions are counted per occurrence, not per
 * visitor; the rate shown is completions per page view.
 */
class FW_VI_Goals {

	const OPT_GOALS    = 'fw_ext_vi_goals';          // user-defined page / event goals
	const OPT_ELEMENTS = 'fw_ext_vi_goal_elements';  // element goal keys seen on pages
	const OPT_INTEG    = 'fw_ext_vi_goal_integrations'; // integration key => enabled

	public function __construct() {
		add_filter( 'sc_advanced_tab_options', array( $this, '_filter_advanced_tab' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Definitions
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $name
	 *
	 * @return string
	 */
	public static function key( $name ) {
		return substr( trim( preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( remove_accents( (string) $name ) ) ), '-' ), 0, 40 );
	}

	/**
	 * Every goal, enabled or not.
	 *
	 * @return array key => { name, type, pattern, enabled }
	 */
	public static function all() {
		$out = array();

		foreach ( FW_VI_Integrations::available() as $key => $name ) {
			$on          = (array) get_option( self::OPT_INTEG, array() );
			$out[ $key ] = array( 'name' => $name, 'type' => 'integration', 'pattern' => '', 'enabled' => ! isset( $on[ $key ] ) || $on[ $key ] );
		}

		foreach ( (array) get_option( self::OPT_ELEMENTS, array() ) as $key => $name ) {
			$out[ $key ] = array( 'name' => $name, 'type' => 'element', 'pattern' => '', 'enabled' => true );
		}

		foreach ( (array) get_option( self::OPT_GOALS, array() ) as $key => $g ) {
			$out[ $key ] = array( 'name' => $g['name'], 'type' => $g['type'], 'pattern' => $g['pattern'] ?? '', 'enabled' => true );
		}

		return $out;
	}

	/**
	 * @return array key => true, enabled goals only
	 */
	public static function known_keys() {
		$keys = array();

		foreach ( self::all() as $key => $g ) {
			if ( $g['enabled'] ) {
				$keys[ $key ] = true;
			}
		}

		return $keys;
	}

	/**
	 * @return array key => path pattern
	 */
	public static function page_goals() {
		$out = array();

		foreach ( (array) get_option( self::OPT_GOALS, array() ) as $key => $g ) {
			if ( 'page' === $g['type'] && '' !== ( $g['pattern'] ?? '' ) ) {
				$out[ $key ] = $g['pattern'];
			}
		}

		return $out;
	}

	/**
	 * A pattern is written relative to the site ("/thank-you/"), but stored
	 * paths include the folder a site lives in ("/shop/thank-you/"); both are
	 * tried. Trailing slashes are optional on both sides.
	 *
	 * @param string $pattern
	 * @param string $path
	 *
	 * @return bool
	 */
	public static function path_matches( $pattern, $path ) {
		static $base = null;

		if ( null === $base ) {
			$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		}

		$path = rtrim( $path, '/' ) . '/';

		foreach ( array( $pattern, $base . '/' . ltrim( $pattern, '/' ) ) as $p ) {
			if ( fnmatch( rtrim( $p, '/' ) . '/', $path, FNM_CASEFOLD ) || fnmatch( $p, $path, FNM_CASEFOLD ) ) {
				return true;
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------- *
	 * Element goals
	 * ---------------------------------------------------------------------- */

	/**
	 * "Track as goal" at the end of every element's Advanced tab.
	 *
	 * @internal
	 */
	public function _filter_advanced_tab( $tab ) {
		$tab['group_vi_goal'] = array(
			'type'    => 'group',
			'options' => array(
				'vi_goal' => array(
					'type'  => 'text',
					'label' => __( 'Track as goal', 'fw' ),
					'desc'  => __( 'Name a goal (for example "Book appointment") and every click on this element counts as a conversion in Visitor Insights. Elements with the same name share one goal. Leave empty for none.', 'fw' ),
					'value' => '',
				),
			),
		);

		return $tab;
	}

	/**
	 * The goal key an element's attributes ask for, registering it on first
	 * sight so the rollup will count it.
	 *
	 * @param array $atts
	 *
	 * @return string '' when none
	 */
	public static function element_key( $atts ) {
		$name = is_array( $atts ) && isset( $atts['vi_goal'] ) && is_string( $atts['vi_goal'] ) ? trim( $atts['vi_goal'] ) : '';

		if ( '' === $name ) {
			return '';
		}

		$key = self::key( $name );

		if ( '' === $key ) {
			return '';
		}

		$seen = (array) get_option( self::OPT_ELEMENTS, array() );

		if ( ! isset( $seen[ $key ] ) && count( $seen ) < 200 ) {
			$seen[ $key ] = mb_substr( $name, 0, 80 );
			update_option( self::OPT_ELEMENTS, $seen, true );
		}

		return $key;
	}

	/* ---------------------------------------------------------------------- *
	 * Reports
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $days
	 *
	 * @return array key => { n, value }
	 */
	public static function totals( $days ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT goal, SUM(n) n, SUM(value) value FROM ' . FW_VI_Installer::table( 'goals' ) . ' WHERE day BETWEEN %s AND %s GROUP BY goal',
			$from, $to
		), OBJECT_K ); // phpcs:ignore WordPress.DB

		return array_map( function ( $r ) {
			return array( 'n' => (int) $r->n, 'value' => (int) $r->value );
		}, $rows );
	}

	/**
	 * @param int $days
	 *
	 * @return array [ {path, n} ] pages where goals were completed most
	 */
	public static function top_pages( $days, $limit = 10 ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );

		return $wpdb->get_results( $wpdb->prepare(
			'SELECT p.path, g.goal, SUM(g.n) n FROM ' . FW_VI_Installer::table( 'goals' ) . ' g JOIN ' . FW_VI_Installer::table( 'pages' ) . ' p ON p.id = g.page_id
			WHERE g.day BETWEEN %s AND %s GROUP BY p.path, g.goal ORDER BY n DESC LIMIT %d',
			$from, $to, $limit
		) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @param int $cents
	 *
	 * @return string
	 */
	public static function money( $cents ) {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $cents / 100 ) ), ENT_QUOTES, 'UTF-8' );
		}

		return number_format_i18n( $cents / 100, 2 );
	}

	/* ---------------------------------------------------------------------- *
	 * Admin tab
	 * ---------------------------------------------------------------------- */

	/**
	 * POST actions from the Goals tab. Nonce and capability are checked by the
	 * admin page before this runs.
	 *
	 * @param string $action
	 *
	 * @return string|null notice text, or null when the action is not ours
	 */
	public static function handle( $action ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by FW_VI_Admin_Page
		if ( 'goal_add' === $action ) {
			$name    = isset( $_POST['goal_name'] ) ? sanitize_text_field( wp_unslash( $_POST['goal_name'] ) ) : '';
			$type    = isset( $_POST['goal_type'] ) && 'event' === $_POST['goal_type'] ? 'event' : 'page';
			$pattern = isset( $_POST['goal_pattern'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['goal_pattern'] ) ) ) : '';
			$key     = self::key( $name );

			if ( '' === $key || ( 'page' === $type && '' === $pattern ) ) {
				return __( 'A goal needs a name, and a page goal needs an address.', 'fw' );
			}

			if ( 'page' === $type ) {
				$path    = wp_parse_url( $pattern, PHP_URL_PATH );
				$pattern = '/' . ltrim( (string) ( false !== strpos( $pattern, '://' ) ? $path : $pattern ), '/' );
			}

			$goals         = (array) get_option( self::OPT_GOALS, array() );
			$goals[ $key ] = array( 'name' => $name, 'type' => $type, 'pattern' => 'page' === $type ? $pattern : '' );
			update_option( self::OPT_GOALS, $goals, false );

			return 'event' === $type
				/* translators: %s: JavaScript call */
				? sprintf( __( 'Goal added. Count it from your own script with %s.', 'fw' ), "UnysonPlusVI.goal('" . $key . "')" )
				: __( 'Goal added.', 'fw' );
		}

		$key = isset( $_POST['goal'] ) ? self::key( wp_unslash( $_POST['goal'] ) ) : '';
		// phpcs:enable

		if ( 'goal_delete' === $action ) {
			foreach ( array( self::OPT_GOALS, self::OPT_ELEMENTS ) as $opt ) {
				$all = (array) get_option( $opt, array() );
				unset( $all[ $key ] );
				update_option( $opt, $all, self::OPT_ELEMENTS === $opt );
			}

			return __( 'Goal removed. Its past completions stay in the reports.', 'fw' );
		}

		if ( 'goal_toggle' === $action ) {
			$on         = (array) get_option( self::OPT_INTEG, array() );
			$on[ $key ] = isset( $on[ $key ] ) ? ! $on[ $key ] : false;
			update_option( self::OPT_INTEG, $on, true );

			return $on[ $key ] ? __( 'Goal switched on.', 'fw' ) : __( 'Goal switched off.', 'fw' );
		}

		return null;
	}

	/**
	 * @param int      $days
	 * @param callable $form_open function( $action ) printing the form's hidden fields
	 */
	public static function render( $days, $form_open ) {
		$goals  = self::all();
		$totals = self::totals( $days );
		$views  = max( 1, FW_VI_Reports::totals( $days )['views'] );
		$types  = array(
			'integration' => __( 'Automatic', 'fw' ),
			'element'     => __( 'Element click', 'fw' ),
			'page'        => __( 'Page visit', 'fw' ),
			'event'       => __( 'Custom event', 'fw' ),
		);
		?>
		<div class="fw-vi-card">
			<h2><?php esc_html_e( 'Goals', 'fw' ); ?></h2>
			<?php if ( ! $goals ) : ?>
				<p class="description"><?php esc_html_e( 'No goals yet. Add one below, or name a goal in any element\'s Advanced tab ("Track as goal").', 'fw' ); ?></p>
			<?php else : ?>
				<table class="widefat striped fw-vi-table">
					<thead><tr>
						<th><?php esc_html_e( 'Goal', 'fw' ); ?></th>
						<th><?php esc_html_e( 'Counted when', 'fw' ); ?></th>
						<th class="num"><?php esc_html_e( 'Completions', 'fw' ); ?></th>
						<th class="num" title="<?php esc_attr_e( 'Completions per 100 page views', 'fw' ); ?>"><?php esc_html_e( 'Rate', 'fw' ); ?></th>
						<th class="num"><?php esc_html_e( 'Value', 'fw' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $goals as $key => $g ) : ?>
						<?php $t = $totals[ $key ] ?? array( 'n' => 0, 'value' => 0 ); ?>
						<tr class="<?php echo $g['enabled'] ? '' : 'fw-vi-off'; ?>">
							<td><strong><?php echo esc_html( $g['name'] ); ?></strong><br><code><?php echo esc_html( $key ); ?></code></td>
							<td>
								<?php echo esc_html( $types[ $g['type'] ] ?? $g['type'] ); ?>
								<?php if ( 'page' === $g['type'] ) : ?><br><code><?php echo esc_html( $g['pattern'] ); ?></code><?php endif; ?>
								<?php if ( 'event' === $g['type'] ) : ?><br><code>UnysonPlusVI.goal('<?php echo esc_html( $key ); ?>')</code><?php endif; ?>
							</td>
							<td class="num"><?php echo esc_html( number_format_i18n( $t['n'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( 100 * $t['n'] / $views, 1 ) . '%' ); ?></td>
							<td class="num"><?php echo $t['value'] ? esc_html( self::money( $t['value'] ) ) : '—'; ?></td>
							<td class="num">
								<form method="post" style="display:inline">
									<?php call_user_func( $form_open, 'integration' === $g['type'] ? 'goal_toggle' : 'goal_delete' ); ?>
									<input type="hidden" name="goal" value="<?php echo esc_attr( $key ); ?>">
									<?php if ( 'integration' === $g['type'] ) : ?>
										<button class="button button-small"><?php echo $g['enabled'] ? esc_html__( 'Switch off', 'fw' ) : esc_html__( 'Switch on', 'fw' ); ?></button>
									<?php else : ?>
										<button class="button button-small button-link-delete" title="<?php echo 'element' === $g['type'] ? esc_attr__( 'Forget this goal. It comes back if an element still names it.', 'fw' ) : ''; ?>"><?php esc_html_e( 'Remove', 'fw' ); ?></button>
									<?php endif; ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<?php $top = self::top_pages( $days ); ?>
		<?php if ( $top ) : ?>
			<div class="fw-vi-card">
				<h2><?php esc_html_e( 'Where goals are completed', 'fw' ); ?></h2>
				<?php $max = max( 1, (int) $top[0]->n ); ?>
				<?php foreach ( $top as $r ) : ?>
					<div class="fw-vi-bar"><span class="fw-vi-bar__fill" style="width:<?php echo esc_attr( round( 100 * $r->n / $max ) ); ?>%"></span>
						<span class="fw-vi-bar__label"><code><?php echo esc_html( $r->path ); ?></code> · <?php echo esc_html( $goals[ $r->goal ]['name'] ?? $r->goal ); ?></span>
						<span class="fw-vi-bar__n"><?php echo esc_html( number_format_i18n( $r->n ) ); ?></span></div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="fw-vi-card">
			<h2><?php esc_html_e( 'Add a goal', 'fw' ); ?></h2>
			<form method="post" class="fw-vi-form">
				<?php call_user_func( $form_open, 'goal_add' ); ?>
				<label><?php esc_html_e( 'Name', 'fw' ); ?> <input type="text" name="goal_name" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. Thank-you page', 'fw' ); ?>"></label>
				<label><?php esc_html_e( 'Counted when', 'fw' ); ?>
					<select name="goal_type">
						<option value="page"><?php esc_html_e( 'A visitor reaches a page', 'fw' ); ?></option>
						<option value="event"><?php esc_html_e( 'Your own script calls it', 'fw' ); ?></option>
					</select>
				</label>
				<label><?php esc_html_e( 'Page address', 'fw' ); ?> <input type="text" name="goal_pattern" class="regular-text" placeholder="/thank-you/ or /order-received/*"></label>
				<button class="button button-primary"><?php esc_html_e( 'Add goal', 'fw' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'To count clicks on a button or any element, open it in the builder and fill in "Track as goal" in its Advanced tab. Form submissions, newsletter sign-ups, shop orders and short-link clicks are counted automatically when those features are in use.', 'fw' ); ?></p>
		</div>
		<?php
	}
}
