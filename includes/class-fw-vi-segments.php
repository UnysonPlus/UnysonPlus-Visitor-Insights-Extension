<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Channel performance: how visits from each traffic channel behave, not just
 * how many there are — time on page, how many read half the page, goal
 * completions and the conversion rate per channel, for the site and per page.
 *
 * The rollup keeps fw_vi_chdaily `(page_id, day, ch)` → views, engaged_ms,
 * half, goals; page_id 0 holds site totals. A page view's channel is decided
 * from its first batch; later batches (engaged time, scroll, goals) find it
 * through the view id, in the same run or on the view's live row.
 */
class FW_VI_Segments {

	/**
	 * @param int $days
	 * @param int $page_id 0 = whole site
	 *
	 * @return array ch => { views, engaged, half, goals, rate }
	 */
	public static function by_channel( $days, $page_id = 0 ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT ch, SUM(views) v, SUM(engaged_ms) e, SUM(half) h, SUM(goals) g FROM ' . FW_VI_Installer::table( 'chdaily' ) . ' WHERE page_id = %d AND day BETWEEN %s AND %s GROUP BY ch ORDER BY v DESC',
			$page_id, $from, $to
		) ); // phpcs:ignore WordPress.DB

		$out = array();
		foreach ( $rows as $r ) {
			$v             = (int) $r->v;
			$out[ $r->ch ] = array(
				'views'   => $v,
				'engaged' => $v ? round( $r->e / $v / 1000, 1 ) : 0,
				'half'    => $v ? min( 100, round( 100 * $r->h / $v ) ) : 0,
				'goals'   => (int) $r->g,
				'rate'    => $v ? round( 100 * $r->g / $v, 1 ) : 0,
			);
		}

		return $out;
	}

	/**
	 * Pages ranked by views from one channel.
	 *
	 * @param string $ch
	 * @param int    $days
	 *
	 * @return array page_id => views
	 */
	public static function pages_for( $ch, $days ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );

		return wp_list_pluck( $wpdb->get_results( $wpdb->prepare(
			'SELECT page_id, SUM(views) v FROM ' . FW_VI_Installer::table( 'chdaily' ) . ' WHERE page_id > 0 AND ch = %s AND day BETWEEN %s AND %s GROUP BY page_id',
			$ch, $from, $to
		) ), 'v', 'page_id' ); // phpcs:ignore WordPress.DB
	}

	/**
	 * The "How each channel performs" card on the Overview.
	 *
	 * @param int $days
	 */
	public static function render( $days ) {
		$rows = self::by_channel( $days );

		if ( ! $rows ) {
			return;
		}

		$labels = FW_VI_Channels::labels();
		$best   = '';
		$max    = -1;
		foreach ( $rows as $ch => $r ) {
			if ( $r['views'] >= 10 && $r['rate'] > $max ) {
				$max  = $r['rate'];
				$best = $ch;
			}
		}
		?>
		<div class="fw-vi-card">
			<h2><?php esc_html_e( 'How each channel performs', 'fw' ); ?></h2>
			<table class="widefat striped fw-vi-table">
				<thead><tr>
					<th><?php esc_html_e( 'Channel', 'fw' ); ?></th>
					<th class="num"><?php esc_html_e( 'Views', 'fw' ); ?></th>
					<th class="num"><?php esc_html_e( 'Avg time', 'fw' ); ?></th>
					<th class="num"><?php esc_html_e( 'Read half', 'fw' ); ?></th>
					<th class="num"><?php esc_html_e( 'Goal completions', 'fw' ); ?></th>
					<th class="num" title="<?php esc_attr_e( 'Goal completions per 100 page views', 'fw' ); ?>"><?php esc_html_e( 'Conversion', 'fw' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $ch => $r ) : ?>
					<tr class="<?php echo $ch === $best && $max > 0 ? 'fw-vi-win' : ''; ?>">
						<td><strong><?php echo esc_html( $labels[ $ch ] ?? $ch ); ?></strong></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $r['views'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $r['engaged'] . 's' ); ?></td>
						<td class="num"><?php echo esc_html( $r['half'] . '%' ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $r['goals'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $r['rate'], 1 ) . '%' ); ?></td>
						<td class="num"><a href="<?php echo esc_url( FW_VI_Admin_Page::url( 'pages', array( 'days' => $days, 'ch' => $ch ) ) ); ?>"><?php esc_html_e( 'Pages', 'fw' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $best && $max > 0 ) : ?>
				<p class="description"><?php echo esc_html( sprintf( __( '%1$s converts best: %2$s goal completions per 100 page views.', 'fw' ), $labels[ $best ] ?? $best, number_format_i18n( $max, 1 ) ) ); ?></p>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Counted from Visitor Insights 1.0.5 on; earlier visits have no channel.', 'fw' ); ?></p>
		</div>
		<?php
	}
}
