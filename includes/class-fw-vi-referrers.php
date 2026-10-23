<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Referring pages: the exact page a visitor clicked from, grouped under its
 * site, with the landing page it brought them to.
 *
 * The browser decides how much of the referring address to send. Most sites
 * send only their domain (the default since 2020); blogs, forums and older
 * sites often send the full page. So each site shows the pages that were
 * shared, and the rest of its visits as "page not shared". Query strings are
 * never kept.
 *
 * Sources dims: `ref` (host) and `rp` (host + path), per landing page and day.
 */
class FW_VI_Referrers {

	/**
	 * @param int $days
	 *
	 * @return array host => { n, pages: [ [ url, landing path, n ] ] }
	 */
	public static function data( $days ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );
		$s = FW_VI_Installer::table( 'sources' );
		$p = FW_VI_Installer::table( 'pages' );

		$hosts = $wpdb->get_results( $wpdb->prepare( "SELECT val, SUM(n) n FROM {$s} WHERE page_id <> 0 AND dim = 'ref' AND day BETWEEN %s AND %s GROUP BY val ORDER BY n DESC LIMIT 100", $from, $to ) ); // phpcs:ignore WordPress.DB
		$pages = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT s.val, p.path, SUM(s.n) n FROM {$s} s JOIN {$p} p ON p.id = s.page_id
			 WHERE s.dim = 'rp' AND s.day BETWEEN %s AND %s GROUP BY s.val, p.path ORDER BY n DESC LIMIT 500",
			$from,
			$to
		) );

		$out = array();
		foreach ( (array) $hosts as $h ) {
			$out[ $h->val ] = array( 'n' => (int) $h->n, 'pages' => array() );
		}
		foreach ( (array) $pages as $r ) {
			$host = (string) strtok( (string) $r->val, '/' );
			if ( ! isset( $out[ $host ] ) ) {
				$out[ $host ] = array( 'n' => 0, 'pages' => array() );
			}
			$out[ $host ]['pages'][] = array( 'https://' . $r->val, (string) $r->path, (int) $r->n );
		}

		return $out;
	}

	/**
	 * @param int $days
	 */
	public static function render( $days ) {
		$data = self::data( $days );
		?>
		<p class="description fw-vi-lead"><?php esc_html_e( 'The sites that sent visitors, and the exact page they clicked from where the referring site shares it. Many sites share only their domain, so a site can show fewer pages than visits.', 'fw' ); ?></p>
		<?php if ( ! $data ) : ?>
			<div class="fw-vi-empty"><h2><?php esc_html_e( 'No referring sites yet', 'fw' ); ?></h2><p><?php esc_html_e( 'All visits in this period came directly, or from this site. When another site links here and someone follows the link, it appears here.', 'fw' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="fw-vi-card">
			<table class="widefat fw-vi-table fw-vi-refs">
				<thead><tr><th><?php esc_html_e( 'Referring site / page', 'fw' ); ?></th><th><?php esc_html_e( 'Landing page', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Visits', 'fw' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $data as $host => $h ) : ?>
					<?php $shared = array_sum( wp_list_pluck( $h['pages'], 2 ) ); ?>
					<tr class="fw-vi-refs__host">
						<td colspan="2"><strong><?php echo esc_html( $host ); ?></strong> <span class="fw-vi-muted"><?php echo esc_html( FW_VI_Channels::labels()[ FW_VI_Channels::classify( $host, '', '' ) ] ?? '' ); ?></span></td>
						<td class="num"><strong><?php echo esc_html( number_format_i18n( max( $h['n'], $shared ) ) ); ?></strong></td>
					</tr>
					<?php foreach ( $h['pages'] as $r ) : ?>
						<tr>
							<td class="fw-vi-refs__page"><a href="<?php echo esc_url( $r[0] ); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo esc_html( $r[0] ); ?></a></td>
							<td><?php echo esc_html( FW_VI_Keywords::show_path( $r[1] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $r[2] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( $h['n'] > $shared ) : ?>
						<tr><td class="fw-vi-refs__page fw-vi-muted" colspan="2"><?php esc_html_e( 'Page not shared by the site', 'fw' ); ?></td><td class="num fw-vi-muted"><?php echo esc_html( number_format_i18n( $h['n'] - $shared ) ); ?></td></tr>
					<?php endif; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
