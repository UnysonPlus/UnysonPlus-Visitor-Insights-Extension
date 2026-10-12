<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Real-visitor performance: Core Web Vitals measured in visitors' browsers
 * (static/js/modules/vitals.js), stored as a histogram per page, day, device
 * and metric so the 75th percentile — the figure search engines judge a page
 * by — can be read back for any period.
 *
 *   metric  bucket   top bucket     good / poor
 *   lcp     100 ms   ≥ 10 s         ≤ 2.5 s / > 4 s
 *   inp     25 ms    ≥ 2 s          ≤ 200 ms / > 500 ms
 *   cls     0.01     ≥ 1.0          ≤ 0.1 / > 0.25
 *   ttfb    100 ms   ≥ 10 s         ≤ 0.8 s / > 1.8 s
 *
 * A percentile is reported as the upper edge of its bucket, so it is never
 * flattering by more than one bucket.
 */
class FW_VI_Vitals {

	const METRICS = array(
		'lcp'  => array( 'step' => 100, 'cap' => 100, 'good' => 2500, 'poor' => 4000 ),
		'inp'  => array( 'step' => 25, 'cap' => 80, 'good' => 200, 'poor' => 500 ),
		'cls'  => array( 'step' => 10, 'cap' => 100, 'good' => 100, 'poor' => 250 ),
		'ttfb' => array( 'step' => 100, 'cap' => 100, 'good' => 800, 'poor' => 1800 ),
	);

	/**
	 * @param string $metric
	 * @param int    $value ms, or CLS × 1000
	 *
	 * @return int
	 */
	public static function bucket( $metric, $value ) {
		$m = self::METRICS[ $metric ] ?? self::METRICS['lcp'];

		return (int) min( $m['cap'], floor( max( 0, $value ) / $m['step'] ) );
	}

	/**
	 * @param string $metric
	 * @param array  $hist bucket => n
	 * @param float  $p    0–1
	 *
	 * @return int|null value at the percentile (ms or CLS × 1000), null without data
	 */
	public static function percentile( $metric, array $hist, $p = 0.75 ) {
		$total = array_sum( $hist );

		if ( ! $total ) {
			return null;
		}

		ksort( $hist );
		$need = $p * $total;
		$run  = 0;

		foreach ( $hist as $b => $n ) {
			$run += $n;
			if ( $run >= $need ) {
				return ( (int) $b + 1 ) * self::METRICS[ $metric ]['step'];
			}
		}

		return ( self::METRICS[ $metric ]['cap'] + 1 ) * self::METRICS[ $metric ]['step'];
	}

	/**
	 * @return string good|ni|poor
	 */
	public static function rating( $metric, $value ) {
		$m = self::METRICS[ $metric ];

		return $value <= $m['good'] ? 'good' : ( $value <= $m['poor'] ? 'ni' : 'poor' );
	}

	/**
	 * @return string
	 */
	public static function format( $metric, $value ) {
		if ( null === $value ) {
			return '—';
		}

		if ( 'cls' === $metric ) {
			return number_format_i18n( $value / 1000, 2 );
		}

		return 'inp' === $metric ? $value . ' ms' : number_format_i18n( $value / 1000, 1 ) . ' s';
	}

	/* ---------------------------------------------------------------------- *
	 * Admin tab
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int    $days
	 * @param string $device d|t|m
	 */
	public static function render( $days, $device ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );
		$t = FW_VI_Installer::table( 'vitals' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT page_id, metric, bucket, SUM(n) n FROM {$t} WHERE device = %s AND day BETWEEN %s AND %s GROUP BY page_id, metric, bucket",
			$device, $from, $to
		) ); // phpcs:ignore WordPress.DB

		$site  = array();
		$pages = array();
		foreach ( $rows as $r ) {
			$site[ $r->metric ][ $r->bucket ]                 = ( $site[ $r->metric ][ $r->bucket ] ?? 0 ) + (int) $r->n;
			$pages[ $r->page_id ][ $r->metric ][ $r->bucket ] = (int) $r->n;
		}

		$labels = array(
			'lcp'  => array( __( 'Loading (LCP)', 'fw' ), __( 'Time until the largest thing on screen has appeared.', 'fw' ) ),
			'inp'  => array( __( 'Responsiveness (INP)', 'fw' ), __( 'How long the page takes to react to a click, tap or key.', 'fw' ) ),
			'cls'  => array( __( 'Visual stability (CLS)', 'fw' ), __( 'How much the layout jumps around while the page is used.', 'fw' ) ),
			'ttfb' => array( __( 'Server response (TTFB)', 'fw' ), __( 'Time until the first byte of the page arrived.', 'fw' ) ),
		);
		$devs   = array( 'd' => __( 'Desktop', 'fw' ), 't' => __( 'Tablet', 'fw' ), 'm' => __( 'Mobile', 'fw' ) );
		?>
		<p class="fw-vi-seg fw-vi-seg--links">
			<?php foreach ( $devs as $k => $label ) : ?>
				<a href="<?php echo esc_url( FW_VI_Admin_Page::url( 'performance', array( 'days' => $days, 'device' => $k ) ) ); ?>" class="<?php echo $k === $device ? 'is-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</p>

		<?php if ( ! $site ) : ?>
			<div class="fw-vi-empty"><p><?php esc_html_e( 'No measurements for this device and period yet. Each page view reports once, when the visitor leaves the page.', 'fw' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="fw-vi-tiles">
			<?php foreach ( $labels as $metric => $l ) : ?>
				<?php
				$hist = $site[ $metric ] ?? array();
				$p75  = self::percentile( $metric, $hist );
				$tot  = array_sum( $hist );
				$good = 0;
				$poor = 0;
				foreach ( $hist as $b => $n ) {
					$v = ( (int) $b + 1 ) * self::METRICS[ $metric ]['step'];
					$good += $v <= self::METRICS[ $metric ]['good'] ? $n : 0;
					$poor += $v > self::METRICS[ $metric ]['poor'] ? $n : 0;
				}
				?>
				<div class="fw-vi-tile fw-vi-vital is-<?php echo esc_attr( null === $p75 ? 'none' : self::rating( $metric, $p75 ) ); ?>" title="<?php echo esc_attr( $l[1] ); ?>">
					<span><?php echo esc_html( $l[0] ); ?></span>
					<strong><?php echo esc_html( self::format( $metric, $p75 ) ); ?></strong>
					<?php if ( $tot ) : ?>
						<div class="fw-vi-dist" role="img" aria-label="<?php echo esc_attr( sprintf( __( '%1$s%% good, %2$s%% poor', 'fw' ), round( 100 * $good / $tot ), round( 100 * $poor / $tot ) ) ); ?>">
							<i class="is-good" style="width:<?php echo esc_attr( 100 * $good / $tot ); ?>%"></i><i class="is-ni" style="width:<?php echo esc_attr( 100 * ( $tot - $good - $poor ) / $tot ); ?>%"></i><i class="is-poor" style="width:<?php echo esc_attr( 100 * $poor / $tot ); ?>%"></i>
						</div>
						<small><?php echo esc_html( sprintf( _n( '75th percentile of %s page view', '75th percentile of %s page views', $tot, 'fw' ), number_format_i18n( $tot ) ) ); ?></small>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		$ids   = array_filter( array_map( 'intval', array_keys( $pages ) ) );
		$paths = $ids ? $wpdb->get_results( 'SELECT id, path, post_id FROM ' . FW_VI_Installer::table( 'pages' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')', OBJECT_K ) : array(); // phpcs:ignore WordPress.DB
		$list  = array();
		$types = array();

		foreach ( $pages as $pid => $metrics ) {
			if ( ! isset( $paths[ $pid ] ) ) {
				continue;
			}
			$row = array( 'path' => $paths[ $pid ]->path, 'n' => array_sum( $metrics['lcp'] ?? array() ) );
			foreach ( array_keys( self::METRICS ) as $m ) {
				$row[ $m ] = self::percentile( $m, $metrics[ $m ] ?? array() );
			}
			$list[] = $row;

			$pt = $paths[ $pid ]->post_id ? get_post_type( (int) $paths[ $pid ]->post_id ) : 'archive';
			foreach ( $metrics as $m => $hist ) {
				foreach ( $hist as $b => $n ) {
					$types[ $pt ?: 'archive' ][ $m ][ $b ] = ( $types[ $pt ?: 'archive' ][ $m ][ $b ] ?? 0 ) + $n;
				}
			}
		}

		usort( $list, function ( $a, $b ) {
			return (int) $b['lcp'] <=> (int) $a['lcp'];
		} );

		$cell = function ( $m, $v ) {
			return '<td class="num"><span class="fw-vi-chip is-' . esc_attr( null === $v ? 'none' : self::rating( $m, $v ) ) . '">' . esc_html( self::format( $m, $v ) ) . '</span></td>';
		};
		?>
		<div class="fw-vi-card">
			<h2><?php esc_html_e( 'Pages, slowest first', 'fw' ); ?></h2>
			<table class="widefat striped fw-vi-table">
				<thead><tr><th><?php esc_html_e( 'Page', 'fw' ); ?></th><th class="num">LCP</th><th class="num">INP</th><th class="num">CLS</th><th class="num">TTFB</th><th class="num"><?php esc_html_e( 'Views measured', 'fw' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $list, 0, 50 ) as $r ) : ?>
					<tr><td><code><?php echo esc_html( $r['path'] ); ?></code></td>
						<?php foreach ( array_keys( self::METRICS ) as $m ) { echo $cell( $m, $r[ $m ] ); } // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<td class="num"><?php echo esc_html( number_format_i18n( $r['n'] ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( count( $types ) > 1 ) : ?>
			<div class="fw-vi-card">
				<h2><?php esc_html_e( 'By content type', 'fw' ); ?></h2>
				<table class="widefat striped fw-vi-table">
					<thead><tr><th><?php esc_html_e( 'Type', 'fw' ); ?></th><th class="num">LCP</th><th class="num">INP</th><th class="num">CLS</th><th class="num">TTFB</th></tr></thead>
					<tbody>
					<?php foreach ( $types as $pt => $metrics ) : ?>
						<?php $obj = get_post_type_object( $pt ); ?>
						<tr><td><?php echo esc_html( $obj ? $obj->labels->name : __( 'Archives and other pages', 'fw' ) ); ?></td>
							<?php foreach ( array_keys( self::METRICS ) as $m ) { echo $cell( $m, self::percentile( $m, $metrics[ $m ] ?? array() ) ); } // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		<?php
	}
}
