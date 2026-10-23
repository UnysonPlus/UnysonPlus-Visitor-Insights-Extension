<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site search insights: what visitors look for on the site, and which
 * searches find nothing — the clearest list there is of content people want
 * and cannot find.
 *
 * Recorded server-side when WordPress renders a search (first results page
 * only, so paging does not count twice), with the number of results. Terms
 * are lower-cased and pass the same masking as script errors — email
 * addresses and long numbers are removed — before they are stored. The usual
 * exclusions apply (bots, editors, Global Privacy Control).
 */
class FW_VI_Search {

	public function __construct() {
		if ( ! is_admin() ) {
			add_action( 'template_redirect', array( $this, '_action_search' ), 98 );
		}
	}

	/**
	 * @internal
	 */
	public function _action_search() {
		global $wp_query;

		$s = FW_VI_Settings::get();

		if ( ! is_search() || ! $s['enabled'] || ! $s['track_search'] || (int) get_query_var( 'paged' ) > 1 ) {
			return;
		}

		$term = mb_strtolower( FW_VI_Ingest::mask( get_search_query( false ), 80 ) );

		if ( '' === $term ) {
			return;
		}

		FW_VI_Collector::server_line( array( array( 'q', $term, (int) $wp_query->found_posts ) ), '', false );
	}

	/**
	 * @param int $days
	 */
	public static function render( $days ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );
		$t = FW_VI_Installer::table( 'search' );

		$tot  = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(n),0) n, COALESCE(SUM(zero),0) z, COUNT(DISTINCT term) terms FROM {$t} WHERE day BETWEEN %s AND %s", $from, $to ) ); // phpcs:ignore WordPress.DB
		$top  = $wpdb->get_results( $wpdb->prepare( "SELECT term, SUM(n) n, SUM(zero) z FROM {$t} WHERE day BETWEEN %s AND %s GROUP BY term ORDER BY n DESC LIMIT 30", $from, $to ) ); // phpcs:ignore WordPress.DB
		$none = $wpdb->get_results( $wpdb->prepare( "SELECT term, SUM(zero) z FROM {$t} WHERE day BETWEEN %s AND %s GROUP BY term HAVING z > 0 ORDER BY z DESC LIMIT 30", $from, $to ) ); // phpcs:ignore WordPress.DB

		if ( ! (int) $tot->n ) {
			echo '<div class="fw-vi-empty"><h2>' . esc_html__( 'No searches yet', 'fw' ) . '</h2><p>' . esc_html__( 'When visitors use your site\'s search, their search terms appear here — and the ones that find nothing are listed separately, so you know what content to add.', 'fw' ) . '</p></div>';
			return;
		}
		?>
		<div class="fw-vi-tiles">
			<div class="fw-vi-tile"><span><?php esc_html_e( 'Searches', 'fw' ); ?></span><strong><?php echo esc_html( number_format_i18n( $tot->n ) ); ?></strong></div>
			<div class="fw-vi-tile"><span><?php esc_html_e( 'Different terms', 'fw' ); ?></span><strong><?php echo esc_html( number_format_i18n( $tot->terms ) ); ?></strong></div>
			<div class="fw-vi-tile"><span><?php esc_html_e( 'Found nothing', 'fw' ); ?></span><strong><?php echo esc_html( number_format_i18n( 100 * $tot->z / $tot->n, 0 ) . '%' ); ?></strong></div>
		</div>
		<div class="fw-vi-grid fw-vi-grid--2">
			<div class="fw-vi-card">
				<h2><?php esc_html_e( 'Top searches', 'fw' ); ?></h2>
				<?php $max = max( 1, (int) $top[0]->n ); ?>
				<?php foreach ( $top as $r ) : ?>
					<div class="fw-vi-bar"><span class="fw-vi-bar__fill" style="width:<?php echo esc_attr( round( 100 * $r->n / $max ) ); ?>%"></span>
						<span class="fw-vi-bar__label"><?php echo esc_html( $r->term ); ?><?php echo (int) $r->z === (int) $r->n ? ' <em class="fw-vi-muted">' . esc_html__( '(nothing found)', 'fw' ) . '</em>' : ''; ?></span>
						<span class="fw-vi-bar__n"><?php echo esc_html( number_format_i18n( $r->n ) ); ?></span></div>
				<?php endforeach; ?>
			</div>
			<div class="fw-vi-card">
				<h2><?php esc_html_e( 'Searches that found nothing', 'fw' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Content visitors are asking for that the site does not have — or has under other words.', 'fw' ); ?></p>
				<?php if ( ! $none ) : ?>
					<p><?php esc_html_e( 'Every search found something.', 'fw' ); ?></p>
				<?php else : ?>
					<?php $max = max( 1, (int) $none[0]->z ); ?>
					<?php foreach ( $none as $r ) : ?>
						<div class="fw-vi-bar"><span class="fw-vi-bar__fill fw-vi-bar__fill--warn" style="width:<?php echo esc_attr( round( 100 * $r->z / $max ) ); ?>%"></span>
							<span class="fw-vi-bar__label"><?php echo esc_html( $r->term ); ?></span>
							<span class="fw-vi-bar__n"><?php echo esc_html( number_format_i18n( $r->z ) ); ?></span></div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
