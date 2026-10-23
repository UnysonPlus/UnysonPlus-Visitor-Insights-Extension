<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The week at a glance on the WordPress dashboard: page views and visitors
 * against the week before, a 14-day sparkline, the top pages, and how many
 * page views arrived in the last five minutes.
 */
class FW_VI_Dashboard {

	public function __construct() {
		add_action( 'wp_dashboard_setup', array( $this, '_action_setup' ) );
	}

	/**
	 * @internal
	 */
	public function _action_setup() {
		if ( FW_VI_Settings::can_view() ) {
			wp_add_dashboard_widget( 'fw_vi_dashboard', __( 'Visitor Insights', 'fw' ), array( $this, 'render' ) );
		}
	}

	public function render() {
		global $wpdb;

		FW_VI_Rollup::run();

		$series = FW_VI_Reports::series( 14 );
		$now    = array_slice( $series, 7 );
		$prev   = array_slice( $series, 0, 7 );
		$sum    = function ( $rows, $k ) {
			return array_sum( wp_list_pluck( $rows, $k ) );
		};
		$delta  = function ( $a, $b ) {
			if ( ! $b ) {
				return '';
			}
			$d = 100 * ( $a - $b ) / $b;
			return sprintf( '<span style="color:%s;font-size:12px;margin-left:4px">%s%s%%</span>', $d >= 0 ? '#00a32a' : '#d63638', $d >= 0 ? '+' : '', esc_html( number_format_i18n( $d, 0 ) ) );
		};

		$views = $sum( $now, 'views' );
		$max   = max( 1, max( wp_list_pluck( $series, 'views' ) ) );
		$w     = 280;
		$h     = 40;
		$pts   = array();
		foreach ( $series as $i => $d ) {
			$pts[] = round( $i * $w / 13, 1 ) . ',' . round( $h - 2 - ( $h - 4 ) * $d['views'] / $max, 1 );
		}
		$live = FW_VI_Reports::live_visitors();
		$top  = FW_VI_Reports::top_pages( 7, 5 );
		?>
		<div class="fw-vi-dash" style="display:flex;gap:16px;align-items:flex-end;margin-bottom:12px">
			<div><div style="color:#646970;font-size:12px"><?php esc_html_e( 'Page views, 7 days', 'fw' ); ?></div><strong style="font-size:24px"><?php echo esc_html( number_format_i18n( $views ) ); ?></strong><?php echo $delta( $views, $sum( $prev, 'views' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<div><div style="color:#646970;font-size:12px"><?php esc_html_e( 'Visitors', 'fw' ); ?></div><strong style="font-size:24px"><?php echo esc_html( number_format_i18n( $sum( $now, 'uniques' ) ) ); ?></strong><?php echo $delta( $sum( $now, 'uniques' ), $sum( $prev, 'uniques' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<div><div style="color:#646970;font-size:12px"><?php esc_html_e( 'Right now', 'fw' ); ?></div><strong style="font-size:24px"><?php echo esc_html( number_format_i18n( $live['visitors'] ) ); ?></strong></div>
		</div>
		<svg viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" width="100%" height="<?php echo (int) $h; ?>" role="img" aria-label="<?php esc_attr_e( 'Page views per day, last 14 days', 'fw' ); ?>" preserveAspectRatio="none">
			<polyline fill="none" stroke="#2271b1" stroke-width="2" vector-effect="non-scaling-stroke" points="<?php echo esc_attr( implode( ' ', $pts ) ); ?>" />
		</svg>
		<?php if ( $top ) : ?>
			<table class="widefat striped" style="margin-top:10px">
				<tbody>
				<?php foreach ( $top as $r ) : ?>
					<tr><td><?php echo esc_html( '' !== $r->title ? $r->title : $r->path ); ?></td><td style="text-align:right"><?php echo esc_html( number_format_i18n( $r->views ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<p style="margin:10px 0 0"><a href="<?php echo esc_url( admin_url( 'admin.php?page=fw-visitor-insights' ) ); ?>"><?php esc_html_e( 'Open Visitor Insights →', 'fw' ); ?></a></p>
		<?php
	}
}
