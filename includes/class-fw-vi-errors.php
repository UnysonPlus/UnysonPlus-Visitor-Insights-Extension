<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Frustration & errors: where the site lets visitors down.
 *
 *  - Rage, dead and error clicks, and script errors, come from the browser
 *    (static/js/modules/frustration.js) through the normal batches.
 *  - Missing pages are recorded here, server-side, when WordPress answers 404,
 *    with where the visitor came from ('internal' = a broken link on this site,
 *    the most useful kind to fix). Automated probes for files a WordPress site
 *    never has (.env, .php, backups …) are left out so the list stays useful.
 */
class FW_VI_Errors {

	const NOISE = '~\.(php\d?|env|ini|sql|bak|old|swp|asp|aspx|jsp|cgi|git|ya?ml|log|tar|gz|zip|rar|7z)$|/\.|wp-admin|wp-login|xmlrpc|wp-includes|cgi-bin~i';

	public function __construct() {
		if ( ! is_admin() ) {
			add_action( 'template_redirect', array( $this, '_action_404' ), 99 );
		}
	}

	/**
	 * @internal
	 */
	public function _action_404() {
		if ( ! is_404() || ! FW_VI_Settings::get()['track_404'] || ! FW_VI_Settings::get()['enabled'] ) {
			return;
		}

		$path = FW_VI_Front::path();

		if ( preg_match( self::NOISE, $path ) ) {
			return;
		}

		$raw = wp_get_raw_referer();
		$ref = '';

		if ( $raw ) {
			$host = strtolower( (string) wp_parse_url( $raw, PHP_URL_HOST ) );
			$ref  = in_array( $host, FW_VI_Collector::hosts(), true ) ? 'internal' : substr( preg_replace( '/^www\./', '', $host ), 0, 100 );
		}

		FW_VI_Collector::server_line( array( array( 'n', $ref ) ), $path, false );
	}

	/* ---------------------------------------------------------------------- *
	 * Admin tab
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $days
	 */
	public static function render( $days ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );
		$f = FW_VI_Installer::table( 'friction' );
		$p = FW_VI_Installer::table( 'pages' );

		$pages = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.path, SUM(IF(f.kind='r',f.n,0)) r, SUM(IF(f.kind='d',f.n,0)) d, SUM(IF(f.kind='e',f.n,0)) e, SUM(f.n) t
			FROM {$f} f JOIN {$p} p ON p.id = f.page_id WHERE f.day BETWEEN %s AND %s
			GROUP BY p.path ORDER BY t DESC LIMIT 20",
			$from, $to
		) ); // phpcs:ignore WordPress.DB

		$errors = $wpdb->get_results( $wpdb->prepare(
			'SELECT m.message, m.source, m.line, SUM(e.n) n, COUNT(DISTINCT e.page_id) pages FROM ' . FW_VI_Installer::table( 'errors' ) . ' e
			JOIN ' . FW_VI_Installer::table( 'errmsg' ) . ' m ON m.hash = e.hash WHERE e.day BETWEEN %s AND %s
			GROUP BY e.hash ORDER BY n DESC LIMIT 20',
			$from, $to
		) ); // phpcs:ignore WordPress.DB

		$missing = $wpdb->get_results( $wpdb->prepare(
			'SELECT path, ref, SUM(n) n FROM ' . FW_VI_Installer::table( 'notfound' ) . ' WHERE day BETWEEN %s AND %s
			GROUP BY path, ref ORDER BY (ref = %s) DESC, n DESC LIMIT 30',
			$from, $to, 'internal'
		) ); // phpcs:ignore WordPress.DB
		?>
		<div class="fw-vi-card">
			<h2><?php esc_html_e( 'Frustrated clicks by page', 'fw' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Rage: three or more quick clicks in the same spot. Dead: a click after which nothing on the page changed. Error: a click followed by a script error. Open a page\'s heatmap in Frustration mode to see exactly where.', 'fw' ); ?></p>
			<?php if ( ! $pages ) : ?>
				<p><?php esc_html_e( 'No frustrated clicks recorded in this period.', 'fw' ); ?></p>
			<?php else : ?>
				<table class="widefat striped fw-vi-table">
					<thead><tr><th><?php esc_html_e( 'Page', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Rage', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Dead', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Error', 'fw' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $pages as $r ) : ?>
						<tr>
							<td><code><?php echo esc_html( $r->path ); ?></code></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $r->r ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $r->d ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $r->e ) ); ?></td>
							<td class="num"><a class="button button-small" href="<?php echo esc_url( FW_VI_Admin_Page::url( 'heatmaps', array( 'path' => $r->path, 'mode' => 'frustration' ) ) ); ?>"><?php esc_html_e( 'Heatmap', 'fw' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="fw-vi-card">
			<h2><?php esc_html_e( 'Script errors', 'fw' ); ?></h2>
			<?php if ( ! $errors ) : ?>
				<p><?php esc_html_e( 'No script errors recorded in this period.', 'fw' ); ?></p>
			<?php else : ?>
				<table class="widefat striped fw-vi-table">
					<thead><tr><th><?php esc_html_e( 'Error', 'fw' ); ?></th><th><?php esc_html_e( 'Where', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Times', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Pages', 'fw' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $errors as $r ) : ?>
						<tr>
							<td><code><?php echo esc_html( $r->message ); ?></code></td>
							<td><?php echo '' !== $r->source ? '<code>' . esc_html( $r->source . ( $r->line ? ':' . $r->line : '' ) ) . '</code>' : '—'; ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $r->n ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $r->pages ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="fw-vi-card">
			<h2><?php esc_html_e( 'Missing pages (404)', 'fw' ); ?></h2>
			<p class="description"><?php esc_html_e( '"This site" means a link on your own site points at a page that does not exist — fix those first.', 'fw' ); ?></p>
			<?php if ( ! $missing ) : ?>
				<p><?php esc_html_e( 'No missing pages recorded in this period.', 'fw' ); ?></p>
			<?php else : ?>
				<table class="widefat striped fw-vi-table">
					<thead><tr><th><?php esc_html_e( 'Address', 'fw' ); ?></th><th><?php esc_html_e( 'Came from', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Times', 'fw' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $missing as $r ) : ?>
						<tr>
							<td><code><?php echo esc_html( $r->path ); ?></code></td>
							<td><?php echo 'internal' === $r->ref ? '<strong>' . esc_html__( 'This site', 'fw' ) . '</strong>' : ( '' !== $r->ref ? esc_html( $r->ref ) : esc_html__( 'Direct / unknown', 'fw' ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $r->n ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
