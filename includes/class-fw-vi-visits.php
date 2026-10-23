<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Visits: page views of one visitor joined into a path, from the page the
 * visit entered on to the page — or the link or download — it left by.
 *
 * A visit is a run of page views by today's visitor hash with no gap longer
 * than 30 minutes. No cookie is involved, and nothing in a row says who the
 * visitor is: the hash is blanked when its day ends, there is no address and
 * no exact user agent, only what the reports already hold (channel, referring
 * page, search words when passed, country / region / city, device, browser
 * family, new or returning when consented). Rows are kept for the "Keep visit
 * paths" setting.
 */
class FW_VI_Visits {

	const GAP       = 1800;
	const MAX_STEPS = 200;
	const PER_PAGE  = 25;

	/**
	 * Fold one rollup run's steps into the visits table.
	 *
	 * @param array $items [ ts, vh, vid, p|o, day, data ], sorted by time
	 */
	public static function process( array $items ) {
		global $wpdb;

		$t    = FW_VI_Installer::table( 'visits' );
		$open = array();
		$dirt = array();
		$save = function ( array $v ) use ( $wpdb, $t ) {
			$wpdb->update( $t, array( // phpcs:ignore WordPress.DB
				'last_seen' => (int) $v['last_seen'],
				'pages'     => (int) $v['pages'],
				'exit_page' => (int) $v['exit_page'],
				'steps'     => wp_json_encode( $v['steps'] ),
			), array( 'id' => (int) $v['id'] ) );
		};

		foreach ( $items as $it ) {
			list( $ts, $vh, , $kind, $day, $data ) = $it;

			if ( '' === (string) $vh ) {
				continue;
			}

			if ( ! array_key_exists( $vh, $open ) ) {
				$row         = $wpdb->get_row( $wpdb->prepare( "SELECT id, started, last_seen, pages, exit_page, steps FROM {$t} WHERE vh = %s ORDER BY last_seen DESC LIMIT 1", $vh ), ARRAY_A ); // phpcs:ignore WordPress.DB
				$open[ $vh ] = $row ? array_merge( $row, array( 'steps' => (array) json_decode( (string) $row['steps'], true ) ) ) : null;
			}

			$v = $open[ $vh ];

			if ( $v && $ts - (int) $v['last_seen'] <= self::GAP && $ts >= (int) $v['started'] ) {
				if ( count( $v['steps'] ) < self::MAX_STEPS ) {
					$v['steps'][] = 'p' === $kind ? array( $ts - (int) $v['started'], (int) $data['page'] ) : array( $ts - (int) $v['started'], 'o', $data['url'], $data['kind'] );
				}
				if ( 'p' === $kind ) {
					$v['pages']     = (int) $v['pages'] + 1;
					$v['exit_page'] = (int) $data['page'];
				}
				$v['last_seen']        = max( (int) $v['last_seen'], $ts );
				$open[ $vh ]           = $v;
				$dirt[ (int) $v['id'] ] = $vh;
				continue;
			}

			if ( 'p' !== $kind ) {
				continue; // a click with no visit to belong to
			}

			if ( $v && isset( $dirt[ (int) $v['id'] ] ) ) {
				$save( $v ); // the visit this one follows, as it stood
				unset( $dirt[ (int) $v['id'] ] );
			}

			$d     = $data['dims'];
			$words = (string) $data['kw'];
			$words = false !== strpos( $words, '|' ) ? substr( $words, strpos( $words, '|' ) + 1 ) : $words;
			$place = explode( '|', (string) $data['city'], 2 );

			$wpdb->insert( $t, array( // phpcs:ignore WordPress.DB
				'day'       => $day,
				'vh'        => $vh,
				'started'   => $ts,
				'last_seen' => $ts,
				'entry'     => (int) $data['page'],
				'exit_page' => (int) $data['page'],
				'pages'     => 1,
				'ch'        => (string) ( $d['ch'] ?? '' ),
				'ref'       => substr( (string) ( $d['ref'] ?? '' ), 0, 120 ),
				'rp'        => substr( (string) ( $d['rp'] ?? '' ), 0, 191 ),
				'kw'        => substr( $words, 0, 100 ),
				'cc'        => (string) ( $d['cc'] ?? '' ),
				'region'    => substr( (string) ( $place[0] ?? '' ), 0, 60 ),
				'city'      => substr( (string) ( $place[1] ?? '' ), 0, 60 ),
				'dev'       => (string) ( $d['dev'] ?? 'd' ),
				'br'        => substr( (string) ( $d['br'] ?? '' ), 0, 20 ),
				'os'        => substr( (string) ( $d['os'] ?? '' ), 0, 20 ),
				'ret'       => (string) ( $d['rt'] ?? '' ),
				'steps'     => wp_json_encode( array( array( 0, (int) $data['page'] ) ) ),
			) );

			$open[ $vh ] = array(
				'id'        => (int) $wpdb->insert_id,
				'started'   => $ts,
				'last_seen' => $ts,
				'pages'     => 1,
				'exit_page' => (int) $data['page'],
				'steps'     => array( array( 0, (int) $data['page'] ) ),
			);
		}

		foreach ( $dirt as $vh ) {
			$save( $open[ $vh ] );
		}
	}

	/**
	 * Entry or exit pages from the visit log, with the bounce share (visits of
	 * one page).
	 *
	 * @param string $col   entry | exit_page
	 * @param int    $days
	 * @param int    $limit
	 *
	 * @return array [ { path, n, bounces } ]
	 */
	public static function ends( $col, $days, $limit = 10 ) {
		global $wpdb;

		$col = 'entry' === $col ? 'entry' : 'exit_page';
		list( $from, $to ) = FW_VI_Reports::range( $days );
		$t = FW_VI_Installer::table( 'visits' );
		$p = FW_VI_Installer::table( 'pages' );

		return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT p.id, p.path, COUNT(*) n, SUM(v.pages = 1) bounces FROM {$t} v JOIN {$p} p ON p.id = v.{$col}
			 WHERE v.day BETWEEN %s AND %s GROUP BY p.id, p.path ORDER BY n DESC LIMIT %d",
			$from,
			$to,
			$limit
		) );
	}

	/**
	 * The entry / exit cards, for the Pages view.
	 *
	 * @param int $days
	 */
	public static function render_ends( $days ) {
		$keep = (int) FW_VI_Settings::get()['visits_days'];
		?>
		<div class="fw-vi-grid fw-vi-grid--2">
			<?php foreach ( array( 'entry' => __( 'Entry pages', 'fw' ), 'exit_page' => __( 'Exit pages', 'fw' ) ) as $col => $title ) : ?>
				<?php $rows = self::ends( $col, $days ); ?>
				<div class="fw-vi-card">
					<h2><?php echo esc_html( $title ); ?></h2>
					<?php if ( ! $rows ) : ?>
						<p class="description"><?php esc_html_e( 'No visits in the visit log for this period yet.', 'fw' ); ?></p>
					<?php else : ?>
						<table class="widefat striped fw-vi-table">
							<thead><tr><th><?php esc_html_e( 'Page', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Visits', 'fw' ); ?></th><th class="num"><?php echo 'entry' === $col ? esc_html__( 'Bounce rate', 'fw' ) : esc_html__( 'Only page', 'fw' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $rows as $r ) : ?>
								<tr>
									<td><a href="<?php echo esc_url( FW_VI_Admin_Page::url( 'paths', 'entry' === $col ? array( 'entry' => $r->id, 'days' => $days ) : array( 'days' => $days ) ) ); ?>"><?php echo esc_html( FW_VI_Keywords::show_path( $r->path ) ); ?></a></td>
									<td class="num"><?php echo esc_html( number_format_i18n( $r->n ) ); ?></td>
									<td class="num"><?php echo esc_html( number_format_i18n( 100 * $r->bounces / max( 1, $r->n ) ) . '%' ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="description">
			<?php
			/* translators: %d: days */
			printf( esc_html__( 'Entry and exit pages come from the visit log, which keeps %d days (Settings → Keep visit paths).', 'fw' ), (int) $keep );
			?>
		</p>
		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * Visitor paths view
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $days
	 */
	public static function render( $days ) {
		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters
		$entry = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;
		$ch    = isset( $_GET['ch'] ) ? sanitize_key( wp_unslash( $_GET['ch'] ) ) : '';
		$pg    = isset( $_GET['pg'] ) ? max( 1, absint( $_GET['pg'] ) ) : 1;
		// phpcs:enable

		list( $from, $to ) = FW_VI_Reports::range( $days );
		$t     = FW_VI_Installer::table( 'visits' );
		$where = $wpdb->prepare( 'day BETWEEN %s AND %s', $from, $to )
			. ( $entry ? $wpdb->prepare( ' AND entry = %d', $entry ) : '' )
			. ( '' !== $ch ? $wpdb->prepare( ' AND ch = %s', $ch ) : '' );

		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$where}" ); // phpcs:ignore WordPress.DB
		$visits = $wpdb->get_results( "SELECT * FROM {$t} WHERE {$where} ORDER BY started DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . ( ( $pg - 1 ) * self::PER_PAGE ) ); // phpcs:ignore WordPress.DB

		// Every page id the rows mention, and the entry-page menu.
		$ids = array();
		foreach ( (array) $visits as $v ) {
			foreach ( (array) json_decode( (string) $v->steps, true ) as $s ) {
				if ( isset( $s[1] ) && is_int( $s[1] ) ) {
					$ids[ $s[1] ] = true;
				}
			}
		}
		$entries = self::ends( 'entry', $days, 50 );
		$paths   = array();
		foreach ( $entries as $e ) {
			$paths[ (int) $e->id ] = $e->path;
		}
		$need = array_diff( array_keys( $ids ), array_keys( $paths ) );
		if ( $need ) {
			foreach ( (array) $wpdb->get_results( 'SELECT id, path FROM ' . FW_VI_Installer::table( 'pages' ) . ' WHERE id IN (' . implode( ',', array_map( 'intval', $need ) ) . ')' ) as $r ) { // phpcs:ignore WordPress.DB
				$paths[ (int) $r->id ] = $r->path;
			}
		}

		$labels  = FW_VI_Channels::labels();
		$devices = array( 'd' => __( 'Desktop', 'fw' ), 't' => __( 'Tablet', 'fw' ), 'm' => __( 'Mobile', 'fw' ) );
		$home    = untrailingslashit( home_url() );
		$base    = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		?>
		<p class="description fw-vi-lead"><?php esc_html_e( 'Each visit as a path: where it came from, the pages in order, and the link or download it left by. No cookies, and nothing that says who the visitor is.', 'fw' ); ?></p>

		<form method="get" class="fw-vi-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( FW_VI_Admin_Page::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="paths">
			<input type="hidden" name="days" value="<?php echo esc_attr( $days ); ?>">
			<label><?php esc_html_e( 'Entry page', 'fw' ); ?>
				<select name="entry" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'Any', 'fw' ); ?></option>
					<?php foreach ( $entries as $e ) : ?>
						<option value="<?php echo esc_attr( $e->id ); ?>" <?php selected( $entry, (int) $e->id ); ?>><?php echo esc_html( FW_VI_Keywords::show_path( $e->path ) . ' (' . number_format_i18n( $e->n ) . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><?php esc_html_e( 'Channel', 'fw' ); ?>
				<select name="ch" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'Any', 'fw' ); ?></option>
					<?php foreach ( $labels as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $ch, $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<span class="fw-vi-muted"><?php printf( esc_html( _n( '%s visit', '%s visits', $total, 'fw' ) ), esc_html( number_format_i18n( $total ) ) ); ?></span>
		</form>

		<?php if ( ! $visits ) : ?>
			<div class="fw-vi-empty"><h2><?php esc_html_e( 'No visits in the log for this period', 'fw' ); ?></h2><p><?php esc_html_e( 'Visits are logged from this version on and kept for the period set under Settings → Keep visit paths.', 'fw' ); ?></p></div>
			<?php
			return;
		endif;
		?>

		<div class="fw-vi-visits">
			<?php foreach ( $visits as $v ) : ?>
				<?php
				$steps = (array) json_decode( (string) $v->steps, true );
				$place = implode( ', ', array_filter( array( $v->city, $v->region, $v->cc ) ) );
				$dur   = (int) $v->last_seen - (int) $v->started;
				?>
				<div class="fw-vi-visit">
					<div class="fw-vi-visit__head">
						<span class="fw-vi-visit__when"><?php echo esc_html( wp_date( 'M j', (int) $v->started ) ); ?></span>
						<span class="fw-vi-visit__place"><?php echo esc_html( '' !== $place ? $place : __( 'Unknown location', 'fw' ) ); ?></span>
						<span class="fw-vi-pill"><?php echo esc_html( $labels[ $v->ch ] ?? $v->ch ); ?></span>
						<?php if ( 'r' === $v->ret ) : ?>
							<span class="fw-vi-pill fw-vi-pill--ret"><?php esc_html_e( 'Returning', 'fw' ); ?></span>
						<?php elseif ( 'n' === $v->ret ) : ?>
							<span class="fw-vi-pill"><?php esc_html_e( 'New', 'fw' ); ?></span>
						<?php endif; ?>
						<span class="fw-vi-visit__sys"><?php echo esc_html( implode( ' · ', array_filter( array( $devices[ $v->dev ] ?? '', $v->os, $v->br ) ) ) ); ?></span>
						<span class="fw-vi-visit__len">
							<?php
							echo esc_html( sprintf( _n( '%s page', '%s pages', (int) $v->pages, 'fw' ), number_format_i18n( $v->pages ) ) );
							echo $dur > 0 ? ' · ' . esc_html( $dur >= 60 ? sprintf( __( '%d min', 'fw' ), round( $dur / 60 ) ) : sprintf( __( '%d s', 'fw' ), $dur ) ) : '';
							?>
						</span>
					</div>
					<div class="fw-vi-visit__from">
						<?php if ( '' !== $v->rp ) : ?>
							<a href="<?php echo esc_url( 'https://' . $v->rp ); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo esc_html( 'https://' . $v->rp ); ?></a>
						<?php elseif ( '' !== $v->ref ) : ?>
							<?php echo esc_html( $v->ref ); ?> <span class="fw-vi-muted"><?php esc_html_e( '(page not shared)', 'fw' ); ?></span>
						<?php else : ?>
							<span class="fw-vi-muted"><?php esc_html_e( '(No referring link)', 'fw' ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $v->kw ) : ?>
							<span class="fw-vi-visit__kw"><?php echo esc_html( '“' . $v->kw . '”' ); ?></span>
						<?php endif; ?>
					</div>
					<ol class="fw-vi-visit__steps">
						<?php foreach ( $steps as $s ) : ?>
							<li>
								<time><?php echo esc_html( wp_date( 'H:i:s', (int) $v->started + (int) $s[0] ) ); ?></time>
								<?php if ( isset( $s[1] ) && 'o' === $s[1] ) : ?>
									<a class="fw-vi-visit__out" href="<?php echo esc_url( $s[2] ); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo esc_html( $s[2] ); ?></a>
									<span class="fw-vi-muted"><?php echo 'd' === ( $s[3] ?? '' ) ? esc_html__( '(Download)', 'fw' ) : esc_html__( '(Exit link)', 'fw' ); ?></span>
								<?php else : ?>
									<?php $path = $paths[ (int) $s[1] ] ?? ''; ?>
									<a href="<?php echo esc_url( $home . ( '' !== $base && 0 === strpos( $path, $base ) ? substr( $path, strlen( $base ) ) : $path ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( FW_VI_Keywords::show_path( $path ) ); ?></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ol>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) :
			?>
			<p class="fw-vi-pager">
				<?php if ( $pg > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'pg', $pg - 1 ) ); ?>">&larr; <?php esc_html_e( 'Newer', 'fw' ); ?></a>
				<?php endif; ?>
				<span><?php printf( esc_html__( 'Page %1$s of %2$s', 'fw' ), esc_html( number_format_i18n( $pg ) ), esc_html( number_format_i18n( $pages ) ) ); ?></span>
				<?php if ( $pg < $pages ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'pg', $pg + 1 ) ); ?>"><?php esc_html_e( 'Older', 'fw' ); ?> &rarr;</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<?php
	}
}
