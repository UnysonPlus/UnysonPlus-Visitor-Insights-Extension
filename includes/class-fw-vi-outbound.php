<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Exit links and downloads: clicks on links that leave the site and on files,
 * per link and per page (table fw_vi_outbound; collected by modules/outbound.js).
 */
class FW_VI_Outbound {

	/**
	 * @param int    $days
	 * @param string $kind l | d
	 *
	 * @return array [ { url, n, pages } ]
	 */
	public static function top( $days, $kind ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );
		$t = FW_VI_Installer::table( 'outbound' );

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT url, SUM(n) n, COUNT(DISTINCT page_id) pages FROM {$t} WHERE kind = %s AND day BETWEEN %s AND %s GROUP BY url ORDER BY n DESC LIMIT 100", $kind, $from, $to ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @param int $days
	 */
	public static function render( $days ) {
		if ( ! FW_VI_Settings::get()['track_outbound'] ) {
			echo '<div class="fw-vi-empty"><h2>' . esc_html__( 'Exit links and downloads are switched off', 'fw' ) . '</h2><p>' . esc_html__( 'Switch them on under Settings → Modules.', 'fw' ) . '</p></div>';
			return;
		}
		?>
		<p class="description fw-vi-lead"><?php esc_html_e( 'Links visitors followed off the site, and files they downloaded, counted per address (without its query string). Each visit\'s own exit is also on Visitor paths.', 'fw' ); ?></p>
		<div class="fw-vi-grid fw-vi-grid--2">
			<?php foreach ( array( 'l' => __( 'Exit links', 'fw' ), 'd' => __( 'Downloads', 'fw' ) ) as $kind => $title ) : ?>
				<?php $rows = self::top( $days, $kind ); ?>
				<div class="fw-vi-card">
					<h2><?php echo esc_html( $title ); ?></h2>
					<?php if ( ! $rows ) : ?>
						<p class="description"><?php echo 'l' === $kind ? esc_html__( 'No clicks on links to other sites in this period.', 'fw' ) : esc_html__( 'No downloads in this period. When visitors download files such as PDFs, they appear here.', 'fw' ); ?></p>
					<?php else : ?>
						<table class="widefat striped fw-vi-table">
							<thead><tr><th><?php esc_html_e( 'Address', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Clicks', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'From pages', 'fw' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $rows as $r ) : ?>
								<tr>
									<td class="fw-vi-wrap"><a href="<?php echo esc_url( $r->url ); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo esc_html( $r->url ); ?></a></td>
									<td class="num"><?php echo esc_html( number_format_i18n( $r->n ) ); ?></td>
									<td class="num"><?php echo esc_html( number_format_i18n( $r->pages ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
