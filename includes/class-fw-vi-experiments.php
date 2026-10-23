<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Section A/B testing: two versions of a section on the same page, each shown
 * to half the page views, with the winner decided by a goal.
 *
 * Both sections are built normally in the page builder. While an experiment
 * runs, a few bytes in <head> pick a variant for this page view (at random,
 * 50/50, before the page paints, so there is no flicker) and CSS hides the
 * other section. The choice is made in the browser, so it works behind page
 * caches. Nothing is stored on the device: a visitor who reloads may see the
 * other variant, and results credit a conversion to the variant the visitor
 * saw last.
 *
 * Results count visitors (today's visitor hash, like funnels) per variant and
 * how many of them completed the goal the same day. Confidence is a two-sided
 * test of the difference between the two conversion rates; at 95 % the result
 * is called. Without JavaScript only version A is shown.
 *
 * Builder ids are stamped on pages with an experiment even when the page is
 * not otherwise tracked (FW_VI_Element_IDs), because the CSS needs them.
 */
class FW_VI_Experiments {

	const OPT = 'fw_ext_vi_experiments';

	/** @var array|null experiments for the current request */
	private static $here = null;

	public function __construct() {
		if ( ! is_admin() ) {
			add_action( 'wp_head', array( $this, '_action_head' ), 1 );
		}
	}

	/**
	 * @return array id => { name, post_id, a, b, goal, status running|stopped, winner ''|a|b, created }
	 */
	public static function all() {
		return (array) get_option( self::OPT, array() );
	}

	/**
	 * Experiments that change what the current page shows (running, or
	 * stopped with a winner). Empty in the admin, the Live Editor and a
	 * heatmap view, where both sections should stay visible.
	 *
	 * @return array
	 */
	public static function here() {
		if ( null !== self::$here ) {
			return self::$here;
		}

		if ( is_admin() || ! did_action( 'wp' ) || 'view' === FW_VI_Front::mode()
			|| isset( $_GET['fw-live-editor'] ) || isset( $_GET['fw-live-editor-frame'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return array();
		}

		$post  = FW_VI_Front::post_id();
		$found = array();

		if ( $post ) {
			foreach ( self::all() as $id => $x ) {
				if ( (int) $x['post_id'] === $post && ( 'running' === $x['status'] || '' !== $x['winner'] ) ) {
					$found[ $id ] = $x;
				}
			}
		}

		return self::$here = $found;
	}

	/**
	 * @internal
	 */
	public function _action_head() {
		$here = self::here();

		if ( ! $here ) {
			return;
		}

		$css = '';
		$run = array();

		foreach ( $here as $id => $x ) {
			$a = esc_attr( $x['a'] );
			$b = esc_attr( $x['b'] );

			if ( 'running' === $x['status'] ) {
				$run[] = $id;
				// No class yet (no JS) → A. Class b → B.
				$css .= "html:not(.upvi-x-{$id}-b) [data-fw-item-id=\"{$b}\"],html.upvi-x-{$id}-b [data-fw-item-id=\"{$a}\"]{display:none!important}";
			} else {
				$css .= '[data-fw-item-id="' . ( 'a' === $x['winner'] ? $b : $a ) . '"]{display:none!important}';
			}
		}

		echo '<style id="upvi-x">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- ids are sanitized keys and esc_attr'd item ids

		if ( $run ) {
			echo "<script id=\"upvi-x-js\">(function(e,r){var c=[],x={};for(var i=0;i<e.length;i++){x[e[i]]=Math.random()<.5?'a':'b';c.push('upvi-x-'+e[i]+'-'+x[e[i]]);}r.className+=' '+c.join(' ');window.UPVIX=x;})("
				. wp_json_encode( $run ) . ",document.documentElement);</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Rollup
	 * ---------------------------------------------------------------------- */

	/**
	 * Credit exposures and conversions. Touchpoints come from the rollup in
	 * time order: [ts, vh, vid, kind, value, day]; kind 'e' carries "id|a".
	 *
	 * @param array $tps
	 */
	public static function process( array $tps ) {
		global $wpdb;

		$all  = self::all();
		$live = array();

		foreach ( $all as $id => $x ) {
			if ( 'running' === $x['status'] ) {
				$live[ $id ] = $x['goal'];
			}
		}

		$tps = array_values( array_filter( $tps, function ( $tp ) {
			return 'e' === $tp[3] || 'g' === $tp[3];
		} ) );

		if ( ! $live || ! $tps ) {
			return;
		}

		$t      = FW_VI_Installer::table( 'xassign' );
		$assign = array();
		$q      = function ( $v ) use ( $wpdb ) {
			return $wpdb->prepare( '%s', $v );
		};
		$days   = implode( ',', array_map( $q, array_unique( wp_list_pluck( $tps, 5 ) ) ) );

		foreach ( array_chunk( array_unique( wp_list_pluck( $tps, 1 ) ), 500 ) as $chunk ) {
			foreach ( $wpdb->get_results( "SELECT * FROM {$t} WHERE day IN ({$days}) AND vh IN (" . implode( ',', array_map( $q, $chunk ) ) . ')' ) as $r ) { // phpcs:ignore WordPress.DB
				$assign[ "{$r->day}|{$r->exp}|{$r->vh}" ] = array( 'last' => $r->last, 'seen' => $r->seen, 'converted' => (int) $r->converted, 'changed' => false );
			}
		}

		$daily = array();

		foreach ( $tps as $tp ) {
			list( , $vh, , $kind, $value, $day ) = $tp;

			if ( 'e' === $kind ) {
				list( $id, $v ) = explode( '|', $value );

				if ( ! isset( $live[ $id ] ) ) {
					continue;
				}

				$k   = "$day|$id|$vh";
				$row = $assign[ $k ] ?? array( 'last' => $v, 'seen' => '', 'converted' => 0, 'changed' => true );

				if ( false === strpos( $row['seen'], $v ) ) {
					$row['seen']                   .= $v;
					$daily[ "$day|$id|$v" ]['vis'] = ( $daily[ "$day|$id|$v" ]['vis'] ?? 0 ) + 1;
				}

				$row['last']    = $v;
				$row['changed'] = true;
				$assign[ $k ]   = $row;
				continue;
			}

			foreach ( $live as $id => $goal ) {
				$k = "$day|$id|$vh";

				if ( $goal === $value && isset( $assign[ $k ] ) && ! $assign[ $k ]['converted'] ) {
					$assign[ $k ]['converted'] = 1;
					$assign[ $k ]['changed']   = true;
					$v                         = $assign[ $k ]['last'];
					$daily[ "$day|$id|$v" ]['conv'] = ( $daily[ "$day|$id|$v" ]['conv'] ?? 0 ) + 1;
				}
			}
		}

		$rows = array();
		foreach ( $assign as $k => $r ) {
			if ( $r['changed'] ) {
				list( $day, $id, $vh ) = explode( '|', $k );
				$rows[]                = $wpdb->prepare( '(%s,%s,%s,%s,%s,%d)', $day, $id, $vh, $r['last'], $r['seen'], $r['converted'] );
			}
		}
		foreach ( array_chunk( $rows, 400 ) as $chunk ) {
			$wpdb->query( "REPLACE INTO {$t} (day, exp, vh, last, seen, converted) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore WordPress.DB
		}

		if ( $daily ) {
			$rows = array();
			foreach ( $daily as $k => $n ) {
				list( $day, $id, $v ) = explode( '|', $k );
				$rows[]               = $wpdb->prepare( '(%s,%s,%s,%d,%d)', $day, $id, $v, $n['vis'] ?? 0, $n['conv'] ?? 0 );
			}
			$wpdb->query( 'INSERT INTO ' . FW_VI_Installer::table( 'xdaily' ) . ' (day, exp, variant, visitors, conversions) VALUES ' . implode( ',', $rows )
				. ' ON DUPLICATE KEY UPDATE visitors = visitors + VALUES(visitors), conversions = conversions + VALUES(conversions)' ); // phpcs:ignore WordPress.DB
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Results
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $id
	 *
	 * @return array { a: {visitors, conversions, rate}, b: …, uplift, confidence, winner }
	 */
	public static function results( $id ) {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT variant, SUM(visitors) v, SUM(conversions) c FROM ' . FW_VI_Installer::table( 'xdaily' ) . ' WHERE exp = %s GROUP BY variant', $id ), OBJECT_K ); // phpcs:ignore WordPress.DB
		$out  = array();

		foreach ( array( 'a', 'b' ) as $v ) {
			$n         = isset( $rows[ $v ] ) ? (int) $rows[ $v ]->v : 0;
			$c         = isset( $rows[ $v ] ) ? min( $n, (int) $rows[ $v ]->c ) : 0;
			$out[ $v ] = array( 'visitors' => $n, 'conversions' => $c, 'rate' => $n ? $c / $n : 0 );
		}

		$out['uplift']     = $out['a']['rate'] > 0 ? ( $out['b']['rate'] - $out['a']['rate'] ) / $out['a']['rate'] : null;
		$out['confidence'] = self::confidence( $out['a']['conversions'], $out['a']['visitors'], $out['b']['conversions'], $out['b']['visitors'] );
		$out['winner']     = $out['confidence'] >= 0.95 ? ( $out['b']['rate'] > $out['a']['rate'] ? 'b' : 'a' ) : '';

		return $out;
	}

	/**
	 * Two-proportion z-test, two-sided: the confidence that the two rates
	 * really differ (0–1).
	 */
	public static function confidence( $c1, $n1, $c2, $n2 ) {
		if ( $n1 < 1 || $n2 < 1 ) {
			return 0.0;
		}

		$p  = ( $c1 + $c2 ) / ( $n1 + $n2 );
		$se = sqrt( $p * ( 1 - $p ) * ( 1 / $n1 + 1 / $n2 ) );

		if ( $se <= 0 ) {
			return 0.0;
		}

		$z = abs( $c2 / $n2 - $c1 / $n1 ) / $se;

		return max( 0.0, min( 1.0, self::erf( $z / M_SQRT2 ) ) );
	}

	/**
	 * Abramowitz–Stegun 7.1.26 (|error| < 1.5e-7).
	 */
	private static function erf( $x ) {
		$t = 1 / ( 1 + 0.3275911 * $x );
		$y = 1 - ( ( ( ( ( 1.061405429 * $t - 1.453152027 ) * $t ) + 1.421413741 ) * $t - 0.284496736 ) * $t + 0.254829592 ) * $t * exp( -$x * $x );

		return $y;
	}

	/* ---------------------------------------------------------------------- *
	 * Admin tab
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $action
	 *
	 * @return string|null
	 */
	public static function handle( $action ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by FW_VI_Admin_Page
		$all = self::all();

		if ( 'exp_add' === $action ) {
			$name = isset( $_POST['exp_name'] ) ? sanitize_text_field( wp_unslash( $_POST['exp_name'] ) ) : '';
			$post = isset( $_POST['exp_post'] ) ? (int) $_POST['exp_post'] : 0;
			$a    = isset( $_POST['exp_a'] ) ? sanitize_key( wp_unslash( $_POST['exp_a'] ) ) : '';
			$b    = isset( $_POST['exp_b'] ) ? sanitize_key( wp_unslash( $_POST['exp_b'] ) ) : '';
			$goal = isset( $_POST['exp_goal'] ) ? FW_VI_Goals::key( wp_unslash( $_POST['exp_goal'] ) ) : '';
			$secs = FW_VI_Sections::items_of( $post );

			if ( '' === $name || ! $post || ! isset( $secs[ $a ], $secs[ $b ] ) || $a === $b || '' === $goal ) {
				return __( 'An experiment needs a name, a page, two different sections of that page and a goal.', 'fw' );
			}

			foreach ( $all as $x ) {
				if ( 'running' === $x['status'] && (int) $x['post_id'] === $post && array_intersect( array( $a, $b ), array( $x['a'], $x['b'] ) ) ) {
					return __( 'One of these sections is already in a running experiment.', 'fw' );
				}
			}

			$id         = substr( FW_VI_Goals::key( $name ), 0, 14 ) . '-' . strtolower( wp_generate_password( 4, false, false ) );
			$id         = preg_replace( '/[^a-z0-9-]/', '', $id );
			$all[ $id ] = array(
				'name'    => $name,
				'post_id' => $post,
				'a'       => $a,
				'b'       => $b,
				'goal'    => $goal,
				'status'  => 'running',
				'winner'  => '',
				'created' => wp_date( 'Y-m-d' ),
			);
			update_option( self::OPT, $all, true );

			return __( 'Experiment started.', 'fw' );
		}

		$id = isset( $_POST['exp'] ) ? sanitize_text_field( wp_unslash( $_POST['exp'] ) ) : '';

		if ( ! isset( $all[ $id ] ) ) {
			return null;
		}

		if ( 'exp_stop' === $action ) {
			$winner                = isset( $_POST['winner'] ) && in_array( $_POST['winner'], array( 'a', 'b' ), true ) ? sanitize_key( $_POST['winner'] ) : '';
			$all[ $id ]['status']  = 'stopped';
			$all[ $id ]['winner']  = $winner;
			update_option( self::OPT, $all, true );

			return $winner
				? __( 'Experiment stopped. Only the winning section is shown now; delete the other one in the builder when you are ready.', 'fw' )
				: __( 'Experiment stopped. Both sections are visible again.', 'fw' );
		}

		if ( 'exp_delete' === $action ) {
			unset( $all[ $id ] );
			update_option( self::OPT, $all, true );

			return __( 'Experiment removed.', 'fw' );
		}
		// phpcs:enable

		return null;
	}

	/**
	 * @param int      $days (unused: an experiment reports its whole run)
	 * @param callable $form_open
	 */
	public static function render( $days, $form_open ) {
		$all   = self::all();
		$goals = FW_VI_Goals::all();

		foreach ( $all as $id => $x ) :
			$r     = self::results( $id );
			$items = FW_VI_Sections::items_of( (int) $x['post_id'] );
			$name  = function ( $item ) use ( $items ) {
				return isset( $items[ $item ] ) && '' !== $items[ $item ]['label'] ? $items[ $item ]['label'] : __( '(section removed)', 'fw' );
			};
			$conf = round( 100 * $r['confidence'] );
			?>
			<div class="fw-vi-card fw-vi-exp">
				<h2><?php echo esc_html( $x['name'] ); ?>
					<span class="fw-vi-chip is-<?php echo 'running' === $x['status'] ? 'good' : 'none'; ?>"><?php echo 'running' === $x['status'] ? esc_html__( 'Running', 'fw' ) : esc_html__( 'Stopped', 'fw' ); ?></span>
				</h2>
				<p class="description">
					<?php echo esc_html( get_the_title( (int) $x['post_id'] ) ); ?> ·
					<?php echo esc_html( sprintf( __( 'Goal: %s', 'fw' ), $goals[ $x['goal'] ]['name'] ?? $x['goal'] ) ); ?> ·
					<?php echo esc_html( sprintf( __( 'since %s', 'fw' ), $x['created'] ) ); ?>
				</p>
				<table class="widefat fw-vi-table">
					<thead><tr><th><?php esc_html_e( 'Version', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Visitors', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Completed the goal', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Rate', 'fw' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array( 'a', 'b' ) as $v ) : ?>
						<tr class="<?php echo $r['winner'] === $v || $x['winner'] === $v ? 'fw-vi-win' : ''; ?>">
							<td><strong><?php echo esc_html( strtoupper( $v ) ); ?></strong> · <?php echo esc_html( $name( $x[ $v ] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $r[ $v ]['visitors'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $r[ $v ]['conversions'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( 100 * $r[ $v ]['rate'], 1 ) . '%' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="fw-vi-verdict">
					<?php
					if ( $r['a']['visitors'] + $r['b']['visitors'] < 100 ) {
						esc_html_e( 'Too early to tell — wait for at least 100 visitors.', 'fw' );
					} elseif ( $r['winner'] ) {
						echo esc_html( sprintf( __( 'Version %1$s is better, with %2$s%% confidence.', 'fw' ), strtoupper( $r['winner'] ), $conf ) );
						if ( null !== $r['uplift'] ) {
							echo ' ' . esc_html( $r['uplift'] >= 0
								? sprintf( __( 'B converts %s%% better than A.', 'fw' ), number_format_i18n( 100 * $r['uplift'], 0 ) )
								: sprintf( __( 'B converts %s%% worse than A.', 'fw' ), number_format_i18n( -100 * $r['uplift'], 0 ) ) );
						}
					} else {
						echo esc_html( sprintf( __( 'No clear winner yet (%s%% confidence; 95%% is needed).', 'fw' ), $conf ) );
					}
					?>
				</p>
				<div class="fw-vi-exp__actions">
					<?php if ( 'running' === $x['status'] ) : ?>
						<form method="post">
							<?php call_user_func( $form_open, 'exp_stop' ); ?>
							<input type="hidden" name="exp" value="<?php echo esc_attr( $id ); ?>">
							<label><?php esc_html_e( 'Stop and keep', 'fw' ); ?>
								<select name="winner">
									<option value="a" <?php selected( 'b' !== $r['winner'] ); ?>><?php esc_html_e( 'Version A', 'fw' ); ?></option>
									<option value="b" <?php selected( 'b' === $r['winner'] ); ?>><?php esc_html_e( 'Version B', 'fw' ); ?></option>
									<option value=""><?php esc_html_e( 'Both (end the test)', 'fw' ); ?></option>
								</select>
							</label>
							<button class="button"><?php esc_html_e( 'Stop', 'fw' ); ?></button>
						</form>
					<?php endif; ?>
					<form method="post">
						<?php call_user_func( $form_open, 'exp_delete' ); ?>
						<input type="hidden" name="exp" value="<?php echo esc_attr( $id ); ?>">
						<button class="button-link button-link-delete"><?php esc_html_e( 'Remove', 'fw' ); ?></button>
					</form>
				</div>
			</div>
			<?php
		endforeach;

		self::render_form( $form_open, $goals );
	}

	/**
	 * @param callable $form_open
	 * @param array    $goals
	 */
	private static function render_form( $form_open, array $goals ) {
		$post  = isset( $_GET['exp_post'] ) ? (int) $_GET['exp_post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pages = get_posts( array(
			'post_type'      => 'any',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'meta_key'       => 'fw:opt:ext:pb:page-builder:json', // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		$secs  = $post ? array_filter( FW_VI_Sections::items_of( $post ), function ( $i ) {
			return $i['section'];
		} ) : array();
		?>
		<div class="fw-vi-card">
			<h2><?php esc_html_e( 'Start an experiment', 'fw' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Build both versions of the section on the page in the builder — one under the other — then pick them here. Half the visitors see A, half see B; the goal decides which works better.', 'fw' ); ?></p>
			<form method="get" class="fw-vi-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( FW_VI_Admin_Page::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="experiments">
				<label><?php esc_html_e( 'Page', 'fw' ); ?>
					<select name="exp_post" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'Choose a page…', 'fw' ); ?></option>
						<?php foreach ( $pages as $p ) : ?>
							<option value="<?php echo (int) $p->ID; ?>" <?php selected( $post, $p->ID ); ?>><?php echo esc_html( get_the_title( $p ) . ' (' . $p->post_type . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</form>
			<?php if ( $post && count( $secs ) >= 2 ) : ?>
				<form method="post" class="fw-vi-form">
					<?php call_user_func( $form_open, 'exp_add' ); ?>
					<input type="hidden" name="exp_post" value="<?php echo (int) $post; ?>">
					<label><?php esc_html_e( 'Name', 'fw' ); ?> <input type="text" name="exp_name" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. Hero headline', 'fw' ); ?>"></label>
					<?php foreach ( array( 'a' => __( 'Version A (the current one)', 'fw' ), 'b' => __( 'Version B (the challenger)', 'fw' ) ) as $v => $label ) : ?>
						<label><?php echo esc_html( $label ); ?>
							<select name="exp_<?php echo esc_attr( $v ); ?>" required>
								<?php $i = 0; foreach ( $secs as $sid => $s ) : $i++; ?>
									<option value="<?php echo esc_attr( $sid ); ?>"><?php echo esc_html( $i . '. ' . ( '' !== $s['label'] ? $s['label'] : __( 'Section', 'fw' ) ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endforeach; ?>
					<label><?php esc_html_e( 'Goal', 'fw' ); ?>
						<select name="exp_goal" required>
							<?php foreach ( $goals as $key => $g ) : ?>
								<?php if ( $g['enabled'] ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $g['name'] ); ?></option><?php endif; ?>
							<?php endforeach; ?>
						</select>
					</label>
					<button class="button button-primary"><?php esc_html_e( 'Start', 'fw' ); ?></button>
				</form>
			<?php elseif ( $post ) : ?>
				<p><?php esc_html_e( 'This page needs at least two sections.', 'fw' ); ?></p>
			<?php endif; ?>
			<?php if ( ! $goals ) : ?>
				<p class="description"><?php esc_html_e( 'You need a goal first — add one on the Goals tab.', 'fw' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
