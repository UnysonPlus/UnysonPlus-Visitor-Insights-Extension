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
				'll'        => substr( (string) ( $data['ll'] ?? '' ), 0, 20 ),
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

	/**
	 * For one page: the pages visitors opened next and the ones they came from,
	 * from the visit log. A reload of the same page is not a move. "Next" also
	 * counts leaving by an exit link or download, and leaving without either;
	 * "before" counts arriving from outside (the visit started here).
	 *
	 * @param int $page_id
	 * @param int $days
	 *
	 * @return array { next: [ label => n ], prev: [ label => n ], visits: int }
	 */
	public static function next_pages( $page_id, $days ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );
		$t    = FW_VI_Installer::table( 'visits' );
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT steps FROM {$t} WHERE day BETWEEN %s AND %s AND steps LIKE %s ORDER BY id DESC LIMIT 5000", $from, $to, '%,' . (int) $page_id . ']%' ) ); // phpcs:ignore WordPress.DB

		$next   = array();
		$prev   = array();
		$visits = 0;
		$ids    = array();

		foreach ( (array) $rows as $json ) {
			$steps = (array) json_decode( (string) $json, true );
			$seen  = false;

			foreach ( $steps as $i => $s ) {
				if ( ! isset( $s[1] ) || (int) $s[1] !== (int) $page_id || 'o' === $s[1] ) {
					continue;
				}
				// The same page again straight after itself is a reload.
				$before = null;
				for ( $j = $i - 1; $j >= 0; $j-- ) {
					if ( isset( $steps[ $j ][1] ) && 'o' !== $steps[ $j ][1] ) {
						$before = (int) $steps[ $j ][1];
						break;
					}
				}
				if ( $before === (int) $page_id ) {
					continue;
				}
				$seen = true;
				$key  = null === $before ? '@start' : 'p' . $before;
				$prev[ $key ] = ( $prev[ $key ] ?? 0 ) + 1;
				if ( null !== $before ) {
					$ids[ $before ] = true;
				}

				$after = '@left';
				for ( $j = $i + 1, $c = count( $steps ); $j < $c; $j++ ) {
					if ( 'o' === ( $steps[ $j ][1] ?? '' ) ) {
						$after = ( 'd' === ( $steps[ $j ][3] ?? '' ) ? 'd' : 'l' ) . '|' . $steps[ $j ][2];
						break;
					}
					if ( (int) $steps[ $j ][1] !== (int) $page_id ) {
						$after          = 'p' . (int) $steps[ $j ][1];
						$ids[ (int) $steps[ $j ][1] ] = true;
						break;
					}
				}
				$next[ $after ] = ( $next[ $after ] ?? 0 ) + 1;
			}

			$visits += $seen ? 1 : 0;
		}

		$paths = array();
		if ( $ids ) {
			$paths = wp_list_pluck( (array) $wpdb->get_results( 'SELECT id, path FROM ' . FW_VI_Installer::table( 'pages' ) . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_keys( $ids ) ) ) . ')' ), 'path', 'id' ); // phpcs:ignore WordPress.DB
		}

		$label = function ( array $counts ) use ( $paths ) {
			$out = array();
			foreach ( $counts as $k => $n ) {
				if ( '@start' === $k ) {
					$l = __( 'Arrived from outside (visit started here)', 'fw' );
				} elseif ( '@left' === $k ) {
					$l = __( 'Left the site', 'fw' );
				} elseif ( 'p' === $k[0] ) {
					$l = FW_VI_Keywords::show_path( $paths[ (int) substr( $k, 1 ) ] ?? '?' );
				} else {
					$l = ( 'd' === $k[0] ? __( 'Downloaded:', 'fw' ) : __( 'Exit link:', 'fw' ) ) . ' ' . substr( $k, 2 );
				}
				$out[ $l ] = ( $out[ $l ] ?? 0 ) + $n;
			}
			arsort( $out );
			return $out;
		};

		return array( 'next' => $label( $next ), 'prev' => $label( $prev ), 'visits' => $visits );
	}

	const EXPORT = 'fw_vi_visits_export';

	/**
	 * The visit-log filters in the request: entry page, channel, country or
	 * city, search words, referring site, device, new / returning, how the
	 * visit left, and a page it went through.
	 *
	 * @return array
	 */
	public static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters
		$g = function ( $k, $key = false ) {
			if ( ! isset( $_GET[ $k ] ) ) {
				return '';
			}
			$v = wp_unslash( $_GET[ $k ] );
			return $key ? sanitize_key( $v ) : sanitize_text_field( $v );
		};
		$f = array(
			'entry' => absint( $g( 'entry' ) ),
			'ch'    => $g( 'ch', true ),
			'place' => $g( 'place' ),
			'kw'    => $g( 'kw' ),
			'ref'   => $g( 'ref' ),
			'dev'   => in_array( $g( 'dev', true ), array( 'd', 't', 'm' ), true ) ? $g( 'dev', true ) : '',
			'ret'   => in_array( $g( 'ret', true ), array( 'n', 'r' ), true ) ? $g( 'ret', true ) : '',
			'left'  => in_array( $g( 'left', true ), array( 'd', 'l', 'x' ), true ) ? $g( 'left', true ) : '',
			'q'     => $g( 'q' ),
		);
		// phpcs:enable

		return $f;
	}

	/**
	 * @param array $f    from filters()
	 * @param int   $days
	 *
	 * @return string SQL condition
	 */
	public static function where( array $f, $days ) {
		global $wpdb;

		list( $from, $to ) = FW_VI_Reports::range( $days );
		$w = $wpdb->prepare( 'day BETWEEN %s AND %s', $from, $to );

		if ( $f['entry'] ) {
			$w .= $wpdb->prepare( ' AND entry = %d', $f['entry'] );
		}
		foreach ( array( 'ch', 'dev', 'ret' ) as $col ) {
			if ( '' !== $f[ $col ] ) {
				$w .= $wpdb->prepare( " AND {$col} = %s", $f[ $col ] );
			}
		}
		if ( '' !== $f['place'] ) {
			$like = '%' . $wpdb->esc_like( $f['place'] ) . '%';
			$w   .= $wpdb->prepare( ' AND ( city LIKE %s OR region LIKE %s OR cc = %s )', $like, $like, strtoupper( $f['place'] ) );
		}
		if ( '' !== $f['kw'] ) {
			$w .= $wpdb->prepare( ' AND kw LIKE %s', '%' . $wpdb->esc_like( $f['kw'] ) . '%' );
		}
		if ( '' !== $f['ref'] ) {
			$w .= $wpdb->prepare( ' AND ref LIKE %s', '%' . $wpdb->esc_like( $f['ref'] ) . '%' );
		}
		if ( 'd' === $f['left'] || 'l' === $f['left'] ) {
			$w .= $wpdb->prepare( ' AND steps LIKE %s', '%"' . $f['left'] . '"]%' );
		} elseif ( 'x' === $f['left'] ) {
			$w .= " AND steps NOT LIKE '%\"o\"%'";
		}
		if ( '' !== $f['q'] ) {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . FW_VI_Installer::table( 'pages' ) . ' WHERE path LIKE %s LIMIT 50', '%' . $wpdb->esc_like( $f['q'] ) . '%' ) ); // phpcs:ignore WordPress.DB
			$or  = array();
			foreach ( $ids as $id ) {
				$or[] = $wpdb->prepare( 'steps LIKE %s', '%,' . (int) $id . ']%' );
			}
			$w .= $or ? ' AND ( ' . implode( ' OR ', $or ) . ' )' : ' AND 0';
		}

		return $w;
	}

	/**
	 * @internal admin-post: the filtered visit log as CSV.
	 */
	public static function export() {
		check_admin_referer( self::EXPORT );

		if ( ! FW_VI_Settings::can_view() ) {
			wp_die( esc_html__( 'You are not allowed to export Visitor Insights.', 'fw' ), 403 );
		}

		global $wpdb;

		FW_VI_Rollup::run();

		$days = isset( $_GET['days'] ) ? max( 1, min( 365, absint( $_GET['days'] ) ) ) : 30;
		$t    = FW_VI_Installer::table( 'visits' );
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $t . ' WHERE ' . self::where( self::filters(), $days ) . ' ORDER BY started DESC LIMIT 50000' ); // phpcs:ignore WordPress.DB
		$path = wp_list_pluck( (array) $wpdb->get_results( 'SELECT id, path FROM ' . FW_VI_Installer::table( 'pages' ) ), 'path', 'id' ); // phpcs:ignore WordPress.DB
		$chl  = FW_VI_Channels::labels();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="visitor-paths-' . wp_date( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'started', 'seconds', 'pages', 'entry', 'exit', 'channel', 'referrer', 'referring_page', 'search_words', 'country', 'region', 'city', 'device', 'os', 'browser', 'new_or_returning', 'path' ) );
		foreach ( (array) $rows as $v ) {
			$steps = array();
			foreach ( (array) json_decode( (string) $v->steps, true ) as $st ) {
				$steps[] = 'o' === ( $st[1] ?? '' ) ? ( ( 'd' === ( $st[3] ?? '' ) ? 'download ' : 'exit ' ) . $st[2] ) : FW_VI_Keywords::show_path( $path[ $st[1] ] ?? '?' );
			}
			fputcsv( $out, array_map( array( 'FW_VI_Export', 'cell' ), array(
				wp_date( 'Y-m-d H:i:s', (int) $v->started ),
				(int) $v->last_seen - (int) $v->started,
				(int) $v->pages,
				FW_VI_Keywords::show_path( $path[ $v->entry ] ?? '' ),
				FW_VI_Keywords::show_path( $path[ $v->exit_page ] ?? '' ),
				$chl[ $v->ch ] ?? $v->ch,
				$v->ref,
				'' !== $v->rp ? 'https://' . $v->rp : '',
				$v->kw,
				$v->cc,
				$v->region,
				$v->city,
				$v->dev,
				$v->os,
				$v->br,
				'r' === $v->ret ? 'returning' : ( 'n' === $v->ret ? 'new' : '' ),
				implode( ' > ', $steps ),
			) ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ---------------------------------------------------------------------- *
	 * Visitor paths view
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $days
	 */
	public static function render( $days ) {
		global $wpdb;

		$devices = array( 'd' => __( 'Desktop', 'fw' ), 't' => __( 'Tablet', 'fw' ), 'm' => __( 'Mobile', 'fw' ) );
		$f       = self::filters();
		$entry = $f['entry'];
		$ch    = $f['ch'];
		$pg    = isset( $_GET['pg'] ) ? max( 1, absint( $_GET['pg'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$t     = FW_VI_Installer::table( 'visits' );
		$where = self::where( $f, $days );

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
			<label><?php esc_html_e( 'Device', 'fw' ); ?>
				<select name="dev" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'Any', 'fw' ); ?></option>
					<?php foreach ( $devices as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['dev'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><?php esc_html_e( 'Visitor', 'fw' ); ?>
				<select name="ret" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'Any', 'fw' ); ?></option>
					<option value="n" <?php selected( $f['ret'], 'n' ); ?>><?php esc_html_e( 'New', 'fw' ); ?></option>
					<option value="r" <?php selected( $f['ret'], 'r' ); ?>><?php esc_html_e( 'Returning', 'fw' ); ?></option>
				</select>
			</label>
			<label><?php esc_html_e( 'Left by', 'fw' ); ?>
				<select name="left" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'Any', 'fw' ); ?></option>
					<option value="l" <?php selected( $f['left'], 'l' ); ?>><?php esc_html_e( 'An exit link', 'fw' ); ?></option>
					<option value="d" <?php selected( $f['left'], 'd' ); ?>><?php esc_html_e( 'A download', 'fw' ); ?></option>
					<option value="x" <?php selected( $f['left'], 'x' ); ?>><?php esc_html_e( 'Neither', 'fw' ); ?></option>
				</select>
			</label>
			<label><?php esc_html_e( 'Place', 'fw' ); ?> <input type="search" name="place" value="<?php echo esc_attr( $f['place'] ); ?>" placeholder="<?php esc_attr_e( 'City, region or country code', 'fw' ); ?>"></label>
			<label><?php esc_html_e( 'Search words', 'fw' ); ?> <input type="search" name="kw" value="<?php echo esc_attr( $f['kw'] ); ?>"></label>
			<label><?php esc_html_e( 'Referring site', 'fw' ); ?> <input type="search" name="ref" value="<?php echo esc_attr( $f['ref'] ); ?>"></label>
			<label><?php esc_html_e( 'Went through page', 'fw' ); ?> <input type="search" name="q" value="<?php echo esc_attr( $f['q'] ); ?>" placeholder="/pricing/"></label>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'fw' ); ?></button>
			<?php if ( array_filter( $f ) ) : ?>
				<a href="<?php echo esc_url( FW_VI_Admin_Page::url( 'paths', array( 'days' => $days ) ) ); ?>"><?php esc_html_e( 'Clear', 'fw' ); ?></a>
			<?php endif; ?>
			<span class="fw-vi-muted"><?php printf( esc_html( _n( '%s visit', '%s visits', $total, 'fw' ) ), esc_html( number_format_i18n( $total ) ) ); ?></span>
			<?php if ( $total ) : ?>
				<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( array_filter( $f ), array( 'action' => self::EXPORT, 'days' => $days ) ), admin_url( 'admin-post.php' ) ), self::EXPORT ) ); ?>"><span class="dashicons dashicons-download" style="font-size:16px;width:16px;height:16px;vertical-align:-4px"></span> <?php esc_html_e( 'Download CSV', 'fw' ); ?></a>
			<?php endif; ?>
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
