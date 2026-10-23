<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Keywords: the words people searched before they arrived, per landing page.
 *
 * The major search engines stopped passing search words to the sites they
 * send visitors to, so no tracker can read them from the visit. They are
 * gathered from where they still exist, and each is labelled with its source:
 *
 *   console   Search Console's own queries per page, through the connection
 *             the SEO extension makes (clicks, impressions, position). Fetched
 *             at most twice a day and cached; Search Console runs a few days
 *             behind.
 *   referrer  search words a referring engine did pass (sources dim `kw`,
 *             stored as "engine-host|words", masked like site search)
 *   campaign  utm_term on the landing URL (sources dim `ut`)
 *   site      the site's own search box (the Search view)
 */
class FW_VI_Keywords {

	const CACHE = 'fw_vi_gsc_';
	const LIMIT = 500;

	/**
	 * @return bool Whether Search Console is connected through the SEO extension.
	 */
	public static function console_ready() {
		return class_exists( 'FW_SEO_Console' ) && FW_SEO_Console::connected() && '' !== (string) FW_SEO_Console::property();
	}

	/**
	 * Search Console rows for the period: query, page path, clicks, impressions,
	 * position. Errors are returned, not thrown, so the view can say what happened.
	 *
	 * @param int  $days
	 * @param bool $fresh Skip the cache.
	 *
	 * @return array { ok, reason, rows: [ [ query, path, clicks, impressions, position ] ], from, to }
	 */
	public static function console( $days, $fresh = false ) {
		if ( ! self::console_ready() ) {
			return array( 'ok' => false, 'reason' => 'off', 'rows' => array() );
		}

		$window = FW_SEO_Console::window( $days );

		return self::console_range( $window['start'], $window['end'], $fresh );
	}

	/**
	 * The same, for any dates (Y-m-d).
	 *
	 * @param string $start
	 * @param string $end
	 * @param bool   $fresh
	 *
	 * @return array
	 */
	public static function console_range( $start, $end, $fresh = false ) {
		if ( ! self::console_ready() ) {
			return array( 'ok' => false, 'reason' => 'off', 'rows' => array() );
		}

		$key = self::CACHE . md5( $start . '|' . $end );
		$hit = $fresh ? false : get_transient( $key );

		if ( is_array( $hit ) ) {
			return $hit;
		}

		$result = FW_SEO_Console::query( array(
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => array( 'query', 'page' ),
			'rowLimit'   => self::LIMIT,
		) );

		$out = array( 'ok' => (bool) $result['ok'], 'reason' => (string) $result['reason'], 'rows' => array(), 'from' => $start, 'to' => $end );

		foreach ( (array) $result['rows'] as $r ) {
			$out['rows'][] = array(
				(string) ( $r['keys'][0] ?? '' ),
				(string) wp_parse_url( (string) ( $r['keys'][1] ?? '' ), PHP_URL_PATH ),
				(int) ( $r['clicks'] ?? 0 ),
				(int) ( $r['impressions'] ?? 0 ),
				round( (float) ( $r['position'] ?? 0 ), 1 ),
			);
		}

		set_transient( $key, $out, $out['ok'] ? 12 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );

		return $out;
	}

	/**
	 * What Search Console says is worth acting on:
	 *  - rising / falling: search terms whose clicks moved most against the
	 *    period before (at least 3 clicks in one of the two);
	 *  - second page: pages shown at an average position of 8 to 20 with at
	 *    least 10 impressions — seen, mostly on page two, where a better title,
	 *    description or more content tends to lift them.
	 *
	 * @param int $days
	 *
	 * @return array|null { rising, falling: [ [ query, now, before ] ], second: [ { path, query, impressions, position, clicks } ] }, null without Search Console
	 */
	public static function opportunities( $days ) {
		$now = self::console( $days );

		if ( ! $now['ok'] ) {
			return null;
		}

		$days   = max( 1, (int) $days );
		$before = self::console_range(
			gmdate( 'Y-m-d', strtotime( $now['from'] ) - $days * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d', strtotime( $now['from'] ) - DAY_IN_SECONDS )
		);

		$sum = function ( array $rows ) {
			$out = array();
			foreach ( $rows as $r ) {
				$out[ $r[0] ] = ( $out[ $r[0] ] ?? 0 ) + $r[2];
			}
			return $out;
		};
		$a     = $sum( $now['rows'] );
		$b     = $before['ok'] ? $sum( $before['rows'] ) : array();
		$moves = array();
		foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $q ) {
			$n = $a[ $q ] ?? 0;
			$p = $b[ $q ] ?? 0;
			if ( max( $n, $p ) >= 3 && $n !== $p ) {
				$moves[] = array( $q, $n, $p );
			}
		}
		usort( $moves, function ( $x, $y ) {
			return ( $y[1] - $y[2] ) <=> ( $x[1] - $x[2] );
		} );

		$pages = array();
		foreach ( $now['rows'] as $r ) {
			$k = $r[1];
			if ( ! isset( $pages[ $k ] ) ) {
				$pages[ $k ] = array( 'path' => $k, 'query' => $r[0], 'top' => 0, 'impressions' => 0, 'clicks' => 0, 'pos' => 0 );
			}
			$pages[ $k ]['impressions'] += $r[3];
			$pages[ $k ]['clicks']      += $r[2];
			$pages[ $k ]['pos']         += $r[4] * $r[3];
			if ( $r[3] > $pages[ $k ]['top'] ) {
				$pages[ $k ]['top']   = $r[3];
				$pages[ $k ]['query'] = $r[0];
			}
		}
		$second = array();
		foreach ( $pages as $pg ) {
			$pg['position'] = $pg['impressions'] ? round( $pg['pos'] / $pg['impressions'], 1 ) : 0;
			if ( $pg['impressions'] >= 10 && $pg['position'] >= 8 && $pg['position'] <= 20 ) {
				$second[] = $pg;
			}
		}
		usort( $second, function ( $x, $y ) {
			return $y['impressions'] <=> $x['impressions'];
		} );

		return array(
			'rising'  => array_slice( array_filter( $moves, function ( $m ) { return $m[1] > $m[2]; } ), 0, 10 ),
			'falling' => array_slice( array_reverse( array_filter( $moves, function ( $m ) { return $m[1] < $m[2]; } ) ), 0, 10 ),
			'second'  => array_slice( $second, 0, 15 ),
		);
	}

	/**
	 * @param int $days
	 */
	private static function render_opportunities( $days ) {
		$o = self::opportunities( $days );

		if ( null === $o ) {
			return;
		}
		?>
		<h2 class="fw-vi-section"><?php esc_html_e( 'Opportunities', 'fw' ); ?></h2>
		<div class="fw-vi-grid fw-vi-grid--2">
			<?php foreach ( array( 'rising' => __( 'Search terms climbing', 'fw' ), 'falling' => __( 'Search terms falling', 'fw' ) ) as $k => $title ) : ?>
				<div class="fw-vi-card">
					<h2><?php echo esc_html( $title ); ?></h2>
					<?php if ( ! $o[ $k ] ) : ?>
						<p class="description"><?php esc_html_e( 'Nothing moved noticeably against the period before.', 'fw' ); ?></p>
					<?php else : ?>
						<table class="widefat striped fw-vi-table">
							<thead><tr><th><?php esc_html_e( 'Search term', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Clicks', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Before', 'fw' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $o[ $k ] as $m ) : ?>
								<tr><td><?php echo esc_html( $m[0] ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $m[1] ) ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $m[2] ) ); ?></td></tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<div class="fw-vi-card">
			<h2><?php esc_html_e( 'Almost on the first page', 'fw' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Pages Google shows at an average position of 8 to 20 — mostly on the second page of results. A clearer title and description, or more on the search term below, is often what lifts them.', 'fw' ); ?></p>
			<?php if ( ! $o['second'] ) : ?>
				<p><?php esc_html_e( 'No page is sitting on the second page with enough impressions right now.', 'fw' ); ?></p>
			<?php else : ?>
				<table class="widefat striped fw-vi-table">
					<thead><tr><th><?php esc_html_e( 'Page', 'fw' ); ?></th><th><?php esc_html_e( 'Main search term', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Shown', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Clicks', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Position', 'fw' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $o['second'] as $pg ) : ?>
						<?php $post = url_to_postid( home_url( self::show_path( $pg['path'] ) ) ); ?>
						<tr>
							<td><?php echo esc_html( self::show_path( $pg['path'] ) ); ?></td>
							<td><?php echo esc_html( $pg['query'] ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $pg['impressions'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $pg['clicks'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $pg['position'], 1 ) ); ?></td>
							<td class="num">
								<?php if ( $post && current_user_can( 'edit_post', $post ) ) : ?>
									<a class="button button-small" href="<?php echo esc_url( (string) get_edit_post_link( $post ) ); ?>"><?php esc_html_e( 'Edit title & description', 'fw' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Words recorded on this site: referrer search words and campaign terms.
	 *
	 * @param int $days
	 *
	 * @return array [ [ term, path, visits, source, engine ] ]
	 */
	public static function recorded( $days ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );
		$s = FW_VI_Installer::table( 'sources' );
		$p = FW_VI_Installer::table( 'pages' );

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT s.dim, s.val, p.path, SUM(s.n) n FROM {$s} s JOIN {$p} p ON p.id = s.page_id
			 WHERE s.day BETWEEN %s AND %s AND s.dim IN ('kw','ut') GROUP BY s.dim, s.val, p.path ORDER BY n DESC LIMIT 300",
			$from,
			$to
		) );

		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( 'kw' === $r->dim ) {
				$parts = explode( '|', (string) $r->val, 2 );
				$out[] = array( $parts[1] ?? $parts[0], $r->path, (int) $r->n, 'referrer', $parts[0] );
			} else {
				$out[] = array( (string) $r->val, $r->path, (int) $r->n, 'campaign', '' );
			}
		}

		return $out;
	}

	/**
	 * @param string $path
	 *
	 * @return string The path as shown: relative to the site's home.
	 */
	public static function show_path( $path ) {
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		return '' !== $base && 0 === strpos( (string) $path, $base . '/' ) ? substr( $path, strlen( $base ) ) : (string) $path;
	}

	/**
	 * @param int    $days
	 * @param string $filter A page path to narrow to, or ''.
	 */
	public static function render( $days, $filter = '' ) {
		$gsc   = self::console( $days, isset( $_GET['refresh'] ) && current_user_can( 'manage_options' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only refetch
		$local = self::recorded( $days );

		if ( '' !== $filter ) {
			$match = function ( $row ) use ( $filter ) {
				return untrailingslashit( $row[1] ) === untrailingslashit( $filter );
			};
			$gsc['rows'] = array_values( array_filter( $gsc['rows'], $match ) );
			$local       = array_values( array_filter( $local, $match ) );
		}

		$clicks = array_sum( wp_list_pluck( $gsc['rows'], 2 ) );
		$impr   = array_sum( wp_list_pluck( $gsc['rows'], 3 ) );
		$terms  = count( array_unique( array_merge( wp_list_pluck( $gsc['rows'], 0 ), wp_list_pluck( $local, 0 ) ) ) );
		?>
		<p class="description fw-vi-lead"><?php esc_html_e( 'The words people searched before they arrived, for each page they landed on. Search engines no longer pass these to the site, so they come from Search Console and from the few visits that still carry them — each row says which.', 'fw' ); ?></p>

		<?php if ( '' !== $filter ) : ?>
			<p class="fw-vi-filter"><?php printf( esc_html__( 'Landing page: %s', 'fw' ), '<code>' . esc_html( self::show_path( $filter ) ) . '</code>' ); ?> <a href="<?php echo esc_url( remove_query_arg( 'path' ) ); ?>"><?php esc_html_e( 'Show every page', 'fw' ); ?></a></p>
		<?php endif; ?>

		<div class="fw-vi-tiles">
			<div class="fw-vi-tile"><span><?php esc_html_e( 'Search terms', 'fw' ); ?></span><strong><?php echo esc_html( number_format_i18n( $terms ) ); ?></strong></div>
			<div class="fw-vi-tile"><span><?php esc_html_e( 'Clicks from search', 'fw' ); ?></span><strong><?php echo $gsc['ok'] ? esc_html( number_format_i18n( $clicks ) ) : '—'; ?></strong></div>
			<div class="fw-vi-tile"><span><?php esc_html_e( 'Shown in results', 'fw' ); ?></span><strong><?php echo $gsc['ok'] ? esc_html( number_format_i18n( $impr ) ) : '—'; ?></strong></div>
			<div class="fw-vi-tile"><span><?php esc_html_e( 'Click rate', 'fw' ); ?></span><strong><?php echo $gsc['ok'] && $impr ? esc_html( number_format_i18n( 100 * $clicks / $impr, 1 ) . '%' ) : '—'; ?></strong></div>
		</div>

		<div class="fw-vi-card">
			<h2><?php esc_html_e( 'From Search Console', 'fw' ); ?></h2>
			<?php self::render_console( $gsc ); ?>
		</div>

		<?php if ( '' === $filter ) { self::render_opportunities( $days ); } ?>

		<div class="fw-vi-card">
			<h2><?php esc_html_e( 'Recorded on visits', 'fw' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Search words a referring search engine passed along, and campaign terms (utm_term) on the links people followed. Recorded from this version on.', 'fw' ); ?></p>
			<?php if ( ! $local ) : ?>
				<p><?php esc_html_e( 'None in this period. Most search engines no longer pass search words, so this list is usually short; Search Console above is the fuller picture.', 'fw' ); ?></p>
			<?php else : ?>
				<table class="widefat striped fw-vi-table">
					<thead><tr><th><?php esc_html_e( 'Search term', 'fw' ); ?></th><th><?php esc_html_e( 'Landing page', 'fw' ); ?></th><th><?php esc_html_e( 'Source', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Visits', 'fw' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $local as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r[0] ); ?></td>
							<td><a href="<?php echo esc_url( add_query_arg( 'path', $r[1] ) ); ?>"><?php echo esc_html( self::show_path( $r[1] ) ); ?></a></td>
							<td><?php echo 'referrer' === $r[3] ? esc_html( $r[4] ) : esc_html__( 'Campaign link', 'fw' ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $r[2] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<p class="description">
			<?php
			printf(
				/* translators: %s: link to the Search view */
				esc_html__( 'What visitors search for once they are on the site is on the %s view.', 'fw' ),
				'<a href="' . esc_url( FW_VI_Admin_Page::url( 'search' ) ) . '">' . esc_html__( 'Search', 'fw' ) . '</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * @param array $gsc
	 */
	private static function render_console( array $gsc ) {
		if ( 'off' === ( $gsc['reason'] ?? '' ) ) {
			$seo = function_exists( 'fw_ext' ) && fw_ext( 'seo' ) && class_exists( 'FW_SEO_Settings_Page' );
			echo '<p>' . esc_html__( 'Connect Google Search Console to see the search terms that brought visitors to each page: the clicks, how often the page was shown, and its average position.', 'fw' ) . '</p>';
			if ( $seo ) {
				printf( '<p><a class="button button-primary" href="%s">%s</a></p>', esc_url( FW_SEO_Settings_Page::url( 'console_tab' ) ), esc_html__( 'Connect in SEO → Search Console', 'fw' ) );
			} else {
				echo '<p class="description">' . esc_html__( 'The connection is made by the SEO extension. Switch it on under Unyson+ → Extensions, then connect Search Console there.', 'fw' ) . '</p>';
			}
			return;
		}

		if ( ! $gsc['ok'] ) {
			/* translators: %s: an error code from Search Console */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'Search Console did not answer (%s). Try again later, or reconnect it in SEO → Search Console.', 'fw' ), $gsc['reason'] ) ) . '</p></div>';
			return;
		}

		if ( ! $gsc['rows'] ) {
			echo '<p>' . esc_html__( 'Search Console has no searches for this period yet.', 'fw' ) . '</p>';
			return;
		}
		?>
		<p class="description">
			<?php
			/* translators: 1: start date, 2: end date */
			printf( esc_html__( '%1$s to %2$s — Search Console runs a few days behind. Position is the average across everyone who saw the result, not a fixed ranking.', 'fw' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $gsc['from'] ) ) ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $gsc['to'] ) ) ) );
			if ( current_user_can( 'manage_options' ) ) {
				echo ' <a href="' . esc_url( add_query_arg( 'refresh', 1 ) ) . '">' . esc_html__( 'Fetch again', 'fw' ) . '</a>';
			}
			?>
		</p>
		<table class="widefat striped fw-vi-table">
			<thead><tr><th><?php esc_html_e( 'Search term', 'fw' ); ?></th><th><?php esc_html_e( 'Landing page', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Clicks', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Shown', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Click rate', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Position', 'fw' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( $gsc['rows'], 0, 200 ) as $r ) : ?>
				<tr>
					<td><?php echo esc_html( $r[0] ); ?></td>
					<td><a href="<?php echo esc_url( add_query_arg( 'path', $r[1] ) ); ?>"><?php echo esc_html( self::show_path( $r[1] ) ); ?></a></td>
					<td class="num"><?php echo esc_html( number_format_i18n( $r[2] ) ); ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( $r[3] ) ); ?></td>
					<td class="num"><?php echo esc_html( $r[3] ? number_format_i18n( 100 * $r[2] / $r[3], 1 ) . '%' : '—' ); ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( $r[4], 1 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
