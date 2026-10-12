<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Funnels: ordered steps (a page reached, a goal completed) and how many
 * visitors get from each step to the next.
 *
 * Following one visitor from step to step needs to recognise them across page
 * views. Visitor Insights keeps no identifier on the device, so it uses the
 * same daily visitor hash that counts unique visitors: a hash of today's salt,
 * address and browser, kept in fw_vi_funnel_progress only until the salt is
 * destroyed the next night. Consequences, by design:
 *  - a funnel is completed within one day (site time);
 *  - steps must happen in order, but other pages may come between them;
 *  - visitors who share an address and browser count as one.
 */
class FW_VI_Funnels {

	const OPT       = 'fw_ext_vi_funnels';
	const MAX_STEPS = 6;
	const MAX       = 10;

	/**
	 * @return array id => { name, steps: [ [page|goal, value] ] }
	 */
	public static function all() {
		return (array) get_option( self::OPT, array() );
	}

	/**
	 * Advance visitors through every funnel. Called by the rollup with this
	 * run's touchpoints, oldest first: [ts, vh, vid, p|g, path|goal, day].
	 *
	 * @param array $tps
	 */
	public static function process( array $tps ) {
		global $wpdb;

		$funnels = self::all();

		if ( ! $funnels || ! $tps ) {
			return;
		}

		$ptable = FW_VI_Installer::table( 'fprogress' );
		$days   = array_unique( wp_list_pluck( $tps, 5 ) );
		$vhs    = array_unique( wp_list_pluck( $tps, 1 ) );
		$prog   = array();

		foreach ( array_chunk( $vhs, 500 ) as $chunk ) {
			$in_vh  = implode( ',', array_map( function ( $v ) use ( $wpdb ) {
				return $wpdb->prepare( '%s', $v );
			}, $chunk ) );
			$in_day = implode( ',', array_map( function ( $v ) use ( $wpdb ) {
				return $wpdb->prepare( '%s', $v );
			}, $days ) );

			foreach ( $wpdb->get_results( "SELECT day, funnel, vh, step FROM {$ptable} WHERE day IN ({$in_day}) AND vh IN ({$in_vh})" ) as $r ) { // phpcs:ignore WordPress.DB
				$prog[ $r->day ][ $r->funnel ][ $r->vh ] = (int) $r->step;
			}
		}

		$changed = array();
		$daily   = array();

		foreach ( $tps as $tp ) {
			list( , $vh, , $kind, $value, $day ) = $tp;

			foreach ( $funnels as $fid => $f ) {
				$cur   = $prog[ $day ][ $fid ][ $vh ] ?? 0;
				$steps = $f['steps'];

				if ( $cur >= count( $steps ) ) {
					continue;
				}

				list( $type, $match ) = $steps[ $cur ];

				$hit = ( 'p' === $kind && 'page' === $type && FW_VI_Goals::path_matches( $match, $value ) )
					|| ( 'g' === $kind && 'goal' === $type && $match === $value );

				if ( $hit ) {
					$prog[ $day ][ $fid ][ $vh ]       = $cur + 1;
					$changed[ "$day|$fid|$vh" ]        = array( $day, $fid, $vh, $cur + 1 );
					$daily[ "$day|$fid|" . ( $cur + 1 ) ] = ( $daily[ "$day|$fid|" . ( $cur + 1 ) ] ?? 0 ) + 1;
				}
			}
		}

		foreach ( array_chunk( array_values( $changed ), 400 ) as $chunk ) {
			$rows = array();
			foreach ( $chunk as $c ) {
				$rows[] = $wpdb->prepare( '(%s,%s,%s,%d)', $c[0], $c[1], $c[2], $c[3] );
			}
			$wpdb->query( "REPLACE INTO {$ptable} (day, funnel, vh, step) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB
		}

		if ( $daily ) {
			$rows = array();
			foreach ( $daily as $k => $n ) {
				list( $day, $fid, $step ) = explode( '|', $k );
				$rows[] = $wpdb->prepare( '(%s,%s,%d,%d)', $day, $fid, $step, $n );
			}
			$wpdb->query( 'INSERT INTO ' . FW_VI_Installer::table( 'fdaily' ) . ' (day, funnel, step, n) VALUES ' . implode( ',', $rows ) . ' ON DUPLICATE KEY UPDATE n = n + VALUES(n)' ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * @param string $fid
	 * @param int    $days
	 *
	 * @return int[] visitors per step (1-based)
	 */
	public static function report( $fid, $days ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT step, SUM(n) n FROM ' . FW_VI_Installer::table( 'fdaily' ) . ' WHERE funnel = %s AND day BETWEEN %s AND %s GROUP BY step',
			$fid, $from, $to
		), OBJECT_K ); // phpcs:ignore WordPress.DB

		$out = array();
		$f   = self::all()[ $fid ] ?? null;

		if ( $f ) {
			foreach ( array_keys( $f['steps'] ) as $i ) {
				$out[ $i + 1 ] = isset( $rows[ $i + 1 ] ) ? (int) $rows[ $i + 1 ]->n : 0;
			}
		}

		return $out;
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

		if ( 'funnel_add' === $action ) {
			$name  = isset( $_POST['funnel_name'] ) ? sanitize_text_field( wp_unslash( $_POST['funnel_name'] ) ) : '';
			$types = isset( $_POST['step_type'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['step_type'] ) ) : array();
			$vals  = isset( $_POST['step_value'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['step_value'] ) ) : array();
			$steps = array();

			foreach ( $types as $i => $type ) {
				$val = trim( (string) ( $vals[ $i ] ?? '' ) );

				if ( '' === $val || ! in_array( $type, array( 'page', 'goal' ), true ) ) {
					continue;
				}

				if ( 'page' === $type ) {
					$val = '/' . ltrim( (string) ( false !== strpos( $val, '://' ) ? wp_parse_url( $val, PHP_URL_PATH ) : $val ), '/' );
				} else {
					$val = FW_VI_Goals::key( $val );
				}

				$steps[] = array( $type, $val );
			}

			$steps = array_slice( $steps, 0, self::MAX_STEPS );

			if ( '' === $name || count( $steps ) < 2 ) {
				return __( 'A funnel needs a name and at least two steps.', 'fw' );
			}

			if ( count( $all ) >= self::MAX ) {
				return sprintf( __( 'Up to %d funnels. Remove one first.', 'fw' ), self::MAX );
			}

			$fid         = substr( FW_VI_Goals::key( $name ), 0, 14 ) . '-' . strtolower( wp_generate_password( 4, false, false ) );
			$all[ $fid ] = array( 'name' => $name, 'steps' => $steps );
			update_option( self::OPT, $all, false );

			return __( 'Funnel added. It counts visits from now on.', 'fw' );
		}

		if ( 'funnel_delete' === $action ) {
			$fid = isset( $_POST['funnel'] ) ? sanitize_text_field( wp_unslash( $_POST['funnel'] ) ) : '';
			unset( $all[ $fid ] );
			update_option( self::OPT, $all, false );

			return __( 'Funnel removed.', 'fw' );
		}
		// phpcs:enable

		return null;
	}

	/**
	 * @param int      $days
	 * @param callable $form_open
	 */
	public static function render( $days, $form_open ) {
		$all   = self::all();
		$goals = FW_VI_Goals::all();

		foreach ( $all as $fid => $f ) :
			$counts = self::report( $fid, $days );
			$first  = max( 1, $counts[1] ?? 0 );
			?>
			<div class="fw-vi-card fw-vi-funnel">
				<form method="post" class="fw-vi-funnel__del">
					<?php call_user_func( $form_open, 'funnel_delete' ); ?>
					<input type="hidden" name="funnel" value="<?php echo esc_attr( $fid ); ?>">
					<button class="button-link button-link-delete"><?php esc_html_e( 'Remove', 'fw' ); ?></button>
				</form>
				<h2><?php echo esc_html( $f['name'] ); ?></h2>
				<?php foreach ( $f['steps'] as $i => $step ) : ?>
					<?php
					$n    = $counts[ $i + 1 ] ?? 0;
					$prev = $i ? ( $counts[ $i ] ?? 0 ) : 0;
					$what = 'page' === $step[0]
						? sprintf( __( 'Reached %s', 'fw' ), $step[1] )
						: sprintf( __( 'Goal: %s', 'fw' ), $goals[ $step[1] ]['name'] ?? $step[1] );
					?>
					<div class="fw-vi-step">
						<span class="fw-vi-num"><?php echo (int) ( $i + 1 ); ?></span>
						<div class="fw-vi-bar"><span class="fw-vi-bar__fill" style="width:<?php echo esc_attr( round( 100 * $n / $first ) ); ?>%"></span>
							<span class="fw-vi-bar__label"><?php echo esc_html( $what ); ?></span>
							<span class="fw-vi-bar__n"><?php echo esc_html( number_format_i18n( $n ) ); ?></span></div>
						<span class="fw-vi-step__drop"><?php echo $i && $prev ? esc_html( sprintf( __( '%s%% continued', 'fw' ), number_format_i18n( 100 * $n / $prev, 0 ) ) ) : ''; ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		endforeach;
		?>
		<div class="fw-vi-card">
			<h2><?php esc_html_e( 'Add a funnel', 'fw' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Steps in order. A visitor counts at a step once they have done every step before it, on the same day; other pages may come in between.', 'fw' ); ?></p>
			<form method="post" class="fw-vi-funnel-form">
				<?php call_user_func( $form_open, 'funnel_add' ); ?>
				<p><label><?php esc_html_e( 'Name', 'fw' ); ?> <input type="text" name="funnel_name" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. Pricing to booking', 'fw' ); ?>"></label></p>
				<datalist id="fw-vi-goal-keys">
					<?php foreach ( $goals as $key => $g ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $g['name'] ); ?></option>
					<?php endforeach; ?>
				</datalist>
				<?php for ( $i = 0; $i < self::MAX_STEPS; $i++ ) : ?>
					<p class="fw-vi-step-row">
						<span class="fw-vi-num"><?php echo (int) ( $i + 1 ); ?></span>
						<select name="step_type[]">
							<option value="page"><?php esc_html_e( 'Reaches page', 'fw' ); ?></option>
							<option value="goal"><?php esc_html_e( 'Completes goal', 'fw' ); ?></option>
						</select>
						<input type="text" name="step_value[]" class="regular-text" list="fw-vi-goal-keys" placeholder="<?php echo 0 === $i ? esc_attr__( '/pricing/', 'fw' ) : ''; ?>">
					</p>
				<?php endfor; ?>
				<button class="button button-primary"><?php esc_html_e( 'Add funnel', 'fw' ); ?></button>
			</form>
		</div>
		<?php
	}
}
