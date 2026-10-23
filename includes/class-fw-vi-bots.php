<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The bot report: automated visitors that were filtered out, by kind and name
 * (table fw_vi_bots), against the human page views.
 *
 * Counted in two places: page requests WordPress serves to a bot (most
 * crawlers never run JavaScript, so this is where they show), and tracker
 * batches the collector refuses as automated (headless browsers and the like).
 * A page served from a full-page cache never reaches WordPress, so on a cached
 * site the crawler numbers are a floor.
 */
class FW_VI_Bots {

	public function __construct() {
		add_action( 'template_redirect', array( $this, '_count' ), 1 );
	}

	/**
	 * @internal
	 */
	public function _count() {
		$s  = FW_VI_Settings::get();
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! $s['enabled'] || ! $s['track_bots'] || is_admin() || wp_doing_ajax() || wp_doing_cron()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) )
			|| ! FW_VI_Bot_Filter::is_bot( $ua ) ) {
			return;
		}

		$bot = FW_VI_Bot_Filter::classify( $ua );

		FW_VI_Ingest::append( FW_VI_Collector::config(), wp_json_encode( array( time(), 0, '', 'd', '', -1, '', '', '', '', null, array( array( 'b', $bot[0], $bot[1] ) ) ) ) );
	}

	/**
	 * @return array kind => label
	 */
	public static function kinds() {
		return array(
			'search'  => __( 'Search engine crawlers', 'fw' ),
			'ai'      => __( 'AI crawlers and assistants', 'fw' ),
			'seo'     => __( 'SEO tools', 'fw' ),
			'social'  => __( 'Link previews', 'fw' ),
			'monitor' => __( 'Monitors and speed tests', 'fw' ),
			'tool'    => __( 'Scripts and headless browsers', 'fw' ),
			'other'   => __( 'Other bots', 'fw' ),
		);
	}

	/**
	 * @param int $days
	 */
	public static function render( $days ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );
		$t      = FW_VI_Installer::table( 'bots' );
		$kinds  = $wpdb->get_results( $wpdb->prepare( "SELECT kind, SUM(n) n FROM {$t} WHERE day BETWEEN %s AND %s GROUP BY kind ORDER BY n DESC", $from, $to ), OBJECT_K ); // phpcs:ignore WordPress.DB
		$names  = $wpdb->get_results( $wpdb->prepare( "SELECT kind, name, SUM(n) n FROM {$t} WHERE day BETWEEN %s AND %s GROUP BY kind, name ORDER BY n DESC LIMIT 40", $from, $to ) ); // phpcs:ignore WordPress.DB
		$bots   = (int) array_sum( wp_list_pluck( $kinds, 'n' ) );
		$humans = (int) FW_VI_Reports::totals( $days )['views'];
		$all    = max( 1, $bots + $humans );
		$labels = self::kinds();
		?>
		<p class="description fw-vi-lead"><?php esc_html_e( 'Automated visitors are never counted as visits. This is how many there were, and who they were.', 'fw' ); ?></p>

		<?php if ( ! FW_VI_Settings::get()['track_bots'] ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'The bot report is switched off under Settings → Modules, so nothing new is being counted.', 'fw' ); ?></p></div>
		<?php endif; ?>

		<div class="fw-vi-tiles">
			<div class="fw-vi-tile"><span><?php esc_html_e( 'Human page views', 'fw' ); ?></span><strong><?php echo esc_html( number_format_i18n( $humans ) ); ?></strong><em><?php echo esc_html( number_format_i18n( 100 * $humans / $all ) . '%' ); ?></em></div>
			<div class="fw-vi-tile"><span><?php esc_html_e( 'Bot requests', 'fw' ); ?></span><strong><?php echo esc_html( number_format_i18n( $bots ) ); ?></strong><em><?php echo esc_html( number_format_i18n( 100 * $bots / $all ) . '%' ); ?></em></div>
		</div>

		<?php if ( ! $bots ) : ?>
			<div class="fw-vi-empty"><h2><?php esc_html_e( 'No bots counted yet', 'fw' ); ?></h2><p><?php esc_html_e( 'Bots are counted from this version on.', 'fw' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="fw-vi-grid fw-vi-grid--2">
			<div class="fw-vi-card">
				<h2><?php esc_html_e( 'By kind', 'fw' ); ?></h2>
				<?php foreach ( $kinds as $k => $r ) : ?>
					<div class="fw-vi-bar"><span class="fw-vi-bar__fill" style="width:<?php echo esc_attr( round( 100 * $r->n / $bots, 1 ) ); ?>%"></span><span class="fw-vi-bar__label"><?php echo esc_html( $labels[ $k ] ?? $k ); ?></span><span class="fw-vi-bar__n"><?php echo esc_html( number_format_i18n( $r->n ) ); ?></span></div>
				<?php endforeach; ?>
			</div>
			<div class="fw-vi-card">
				<h2><?php esc_html_e( 'Top bots', 'fw' ); ?></h2>
				<table class="widefat striped fw-vi-table">
					<tbody>
					<?php foreach ( $names as $r ) : ?>
						<tr><td><?php echo esc_html( $r->name ); ?></td><td class="fw-vi-muted"><?php echo esc_html( $labels[ $r->kind ] ?? $r->kind ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $r->n ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<p class="description"><?php esc_html_e( 'Pages served from a full-page cache never reach WordPress, so on a cached site crawler numbers are a minimum.', 'fw' ); ?></p>
		<?php
	}
}
