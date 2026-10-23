<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Import / Export: one file that carries a Visitor Insights setup, and
 * optionally its history, from one site to another (or back into the same one).
 *
 *   setup  settings, goals, automatic-goal switches, element goal names,
 *          funnels, A/B tests, annotations
 *   data   the daily summary tables (traffic, heatmaps, sections, goals,
 *          funnels, frustration, errors, missing pages, vitals, forms, search,
 *          A/B results, movement, channels)
 *
 * Left out on purpose: the collector secrets and the daily salt (a copy must
 * mint its own), anything keyed by a visitor hash (unique-visitor sets, funnel
 * progress, A/B assignments, the live list — they only mean something on the
 * day they were written) and session recordings (consent was given to this
 * site, not to whoever holds a file).
 *
 * Page paths are stored relative to the site's home, so history from
 * example.com/ lands on the right pages of example.com/blog/ and back; page
 * rows are matched by path and created when missing.
 *
 * The file is JSON, gzipped when the server can. Import reads either.
 *
 *   admin-post.php?action=fw_vi_transfer_export&data=1&days=0&_wpnonce=…
 */
class FW_VI_Transfer {

	const ACTION  = 'fw_vi_transfer_export';
	const FORMAT  = 'unysonplus-visitor-insights';
	const VERSION = 1;
	const CHUNK   = 5000;
	const BATCH   = 500;

	/** Summary tables that travel; `pages` first, it is what the others point at. */
	const TABLES = array(
		'pages', 'views', 'sources', 'elem', 'clicks', 'scroll', 'sections',
		'goals', 'fdaily', 'friction', 'errors', 'errmsg', 'notfound', 'vitals',
		'forms', 'fields', 'search', 'xdaily', 'moves', 'chdaily', 'hmch',
	);

	/** Tables without a `day` column: exported whole whatever the period. */
	const UNDATED = array( 'pages', 'errmsg' );

	public function __construct() {
		add_action( 'admin_post_' . self::ACTION, array( $this, '_action_export' ) );
	}

	/**
	 * @return array option name => what it holds
	 */
	private static function setup_options() {
		return array(
			FW_VI_Goals::OPT_GOALS       => 'goals',
			FW_VI_Goals::OPT_INTEG       => 'integrations',
			FW_VI_Goals::OPT_ELEMENTS    => 'element_goals',
			FW_VI_Funnels::OPT           => 'funnels',
			FW_VI_Experiments::OPT       => 'experiments',
			FW_VI_Annotations::OPT       => 'annotations',
		);
	}

	/**
	 * The path of the site's home, without the trailing slash ('' at the root).
	 *
	 * @return string
	 */
	private static function base() {
		return rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	}

	private static function relative( $path ) {
		$base = self::base();

		return '' !== $base && 0 === strpos( (string) $path, $base . '/' ) ? substr( $path, strlen( $base ) ) : (string) $path;
	}

	private static function absolute( $path ) {
		return self::base() . '/' . ltrim( (string) $path, '/' );
	}

	/* ---------------------------------------------------------------------- *
	 * Export
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _action_export() {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export Visitor Insights.', 'fw' ), 403 );
		}

		$data = ! empty( $_GET['data'] );
		$days = isset( $_GET['days'] ) ? max( 0, min( 3650, (int) $_GET['days'] ) ) : 0;

		FW_VI_Rollup::run();

		$gz   = function_exists( 'gzopen' );
		$tmp  = wp_tempnam( 'fw-vi-export' );
		$name = 'visitor-insights-' . sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . wp_date( 'Y-m-d' ) . ( $data ? '' : '-setup' ) . '.json' . ( $gz ? '.gz' : '' );

		// Written to a file a chunk at a time, so a large history never has to
		// sit in memory as one string.
		$fh = $gz ? gzopen( $tmp, 'wb6' ) : fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$w  = function ( $s ) use ( $fh, $gz ) {
			$gz ? gzwrite( $fh, $s ) : fwrite( $fh, $s ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		};

		$head = array(
			'format'   => self::FORMAT,
			'version'  => self::VERSION,
			'schema'   => FW_VI_Installer::DB_VERSION,
			'plugin'   => fw_ext( 'visitor-insights' ) ? fw_ext( 'visitor-insights' )->manifest->get_version() : '',
			'site'     => home_url( '/' ),
			'exported' => gmdate( 'c' ),
			'days'     => $days,
			'setup'    => self::export_setup(),
		);
		$json = wp_json_encode( $head );
		$w( substr( $json, 0, -1 ) . ',"tables":{' );

		if ( $data ) {
			$first = true;
			foreach ( self::TABLES as $key ) {
				$w( ( $first ? '' : ',' ) . wp_json_encode( $key ) . ':' );
				self::export_table( $key, $days, $w );
				$first = false;
			}
		}

		$w( '}}' );
		$gz ? gzclose( $fh ) : fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		nocache_headers();
		header( 'Content-Type: ' . ( $gz ? 'application/gzip' : 'application/json; charset=utf-8' ) );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * @return array
	 */
	private static function export_setup() {
		$out = array( 'settings' => (array) fw_get_db_ext_settings_option( 'visitor-insights' ) );

		foreach ( self::setup_options() as $opt => $key ) {
			$out[ $key ] = get_option( $opt, array() );
		}

		return $out;
	}

	/**
	 * Writes { "cols": [...], "rows": [[...], ...] } for one table.
	 *
	 * @param string   $key
	 * @param int      $days 0 = everything
	 * @param callable $w
	 */
	private static function export_table( $key, $days, $w ) {
		global $wpdb;

		$table = FW_VI_Installer::table( $key );
		$where = $days && ! in_array( $key, self::UNDATED, true ) ? $wpdb->prepare( ' WHERE day >= %s', wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS ) ) : '';
		$cols  = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB

		$w( '{"cols":' . wp_json_encode( $cols ) . ',"rows":[' );

		$first  = true;
		$offset = 0;
		do {
			$rows = $wpdb->get_results( "SELECT * FROM {$table}{$where} LIMIT {$offset}, " . self::CHUNK, ARRAY_N ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $r ) {
				if ( 'pages' === $key ) {
					$r[2] = self::relative( $r[2] );
				} elseif ( 'notfound' === $key ) {
					$r[1] = self::relative( $r[1] );
				}
				$w( ( $first ? '' : ',' ) . wp_json_encode( $r ) );
				$first = false;
			}
			$offset += self::CHUNK;
		} while ( $rows && count( $rows ) === self::CHUNK );

		$w( ']}' );
	}

	/* ---------------------------------------------------------------------- *
	 * Import
	 * ---------------------------------------------------------------------- */

	/**
	 * Handles the import form on the Import / Export view.
	 *
	 * @param string $action
	 *
	 * @return array|null [ type, message ], or null when the action is not ours.
	 */
	public static function handle( $action ) {
		if ( 'import' !== $action ) {
			return null;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page before handlers run.
		$file = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$what = isset( $_POST['what'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['what'] ) ) : array();
		$mode = isset( $_POST['mode'] ) && 'replace' === $_POST['mode'] ? 'replace' : 'merge';
		// phpcs:enable

		if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return array( 'error', __( 'Choose an export file to import.', 'fw' ) );
		}

		$raw = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( "\x1f\x8b" === substr( $raw, 0, 2 ) ) {
			$raw = function_exists( 'gzdecode' ) ? (string) @gzdecode( $raw ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$doc = json_decode( $raw, true );
		unset( $raw );

		if ( ! is_array( $doc ) || self::FORMAT !== ( $doc['format'] ?? '' ) ) {
			return array( 'error', __( 'That file is not a Visitor Insights export.', 'fw' ) );
		}
		if ( (int) ( $doc['version'] ?? 0 ) > self::VERSION ) {
			return array( 'error', __( 'That file was made by a newer version of Visitor Insights. Update this site first.', 'fw' ) );
		}
		if ( ! $what ) {
			return array( 'error', __( 'Choose what to import: the setup, the data, or both.', 'fw' ) );
		}

		$done = array();

		if ( in_array( 'setup', $what, true ) && ! empty( $doc['setup'] ) ) {
			self::import_setup( (array) $doc['setup'], $mode );
			$done[] = __( 'setup', 'fw' );
		}

		if ( in_array( 'data', $what, true ) && ! empty( $doc['tables'] ) ) {
			$rows   = self::import_tables( (array) $doc['tables'], $mode, untrailingslashit( (string) ( $doc['site'] ?? '' ) ) === untrailingslashit( home_url( '/' ) ) );
			$done[] = sprintf( _n( '%s row of data', '%s rows of data', $rows, 'fw' ), number_format_i18n( $rows ) );
		}

		if ( ! $done ) {
			return array( 'warning', __( 'The file holds nothing of what you chose to import.', 'fw' ) );
		}

		FW_VI_Settings::flush();
		FW_VI_Collector::maybe_write_config( true );
		FW_VI_Cache::purge(); // goals and A/B tests show in cached pages

		/* translators: %s: "setup and 1,234 rows of data" */
		return array( 'success', sprintf( __( 'Imported %s.', 'fw' ), implode( ' ' . __( 'and', 'fw' ) . ' ', $done ) ) );
	}

	/**
	 * @param array  $setup
	 * @param string $mode merge: what is here wins; replace: the file wins.
	 */
	private static function import_setup( array $setup, $mode ) {
		if ( isset( $setup['settings'] ) && is_array( $setup['settings'] ) ) {
			$here = (array) fw_get_db_ext_settings_option( 'visitor-insights' );
			fw_set_db_ext_settings_option( 'visitor-insights', null, 'replace' === $mode ? array_merge( $here, $setup['settings'] ) : array_merge( $setup['settings'], $here ) );
		}

		foreach ( self::setup_options() as $opt => $key ) {
			if ( ! isset( $setup[ $key ] ) || ! is_array( $setup[ $key ] ) ) {
				continue;
			}

			$file = $setup[ $key ];
			$here = (array) get_option( $opt, array() );

			if ( 'replace' === $mode ) {
				$value = $file;
			} elseif ( $file && array_keys( $file ) === range( 0, count( $file ) - 1 ) ) {
				// A list (annotations): add the entries that are not here yet.
				$value = $here;
				foreach ( $file as $entry ) {
					if ( ! in_array( $entry, $here, true ) ) {
						$value[] = $entry;
					}
				}
			} else {
				$value = $here + $file; // keyed: a key that exists here keeps its value
			}

			update_option( $opt, $value, false );
		}
	}

	/**
	 * @param array $tables key => { cols, rows }
	 * @param string $mode  merge: rows for a key already here are skipped; replace: tables emptied first.
	 * @param bool   $same  The file came from this site, so post ids still hold.
	 *
	 * @return int rows written
	 */
	private static function import_tables( array $tables, $mode, $same ) {
		global $wpdb;

		$count = 0;

		if ( 'replace' === $mode ) {
			foreach ( self::TABLES as $key ) {
				if ( isset( $tables[ $key ] ) ) {
					$wpdb->query( 'TRUNCATE TABLE ' . FW_VI_Installer::table( $key ) ); // phpcs:ignore WordPress.DB
				}
			}
		}

		FW_VI_Rollup::forget_pages(); // its cache may name rows just emptied

		// Page ids in the file → page ids here, matched by path. 0 is the
		// site-wide row, which has no page of its own.
		$map = array( 0 => 0 );
		if ( isset( $tables['pages']['cols'], $tables['pages']['rows'] ) ) {
			$cols = array_flip( (array) $tables['pages']['cols'] );
			foreach ( (array) $tables['pages']['rows'] as $r ) {
				$path = self::absolute( $r[ $cols['path'] ] ?? '/' );
				$post = $same ? (int) ( $r[ $cols['post_id'] ] ?? 0 ) : ( false === strpos( $path, '?' ) ? (int) url_to_postid( home_url( self::relative( $path ) ) ) : 0 );
				$id   = FW_VI_Rollup::page_id( $post, $path );

				$map[ (int) $r[ $cols['id'] ] ] = $id;
				if ( ! empty( $r[ $cols['created'] ?? -1 ] ) ) {
					$wpdb->query( $wpdb->prepare( 'UPDATE ' . FW_VI_Installer::table( 'pages' ) . ' SET created = LEAST(created, %s) WHERE id = %d', $r[ $cols['created'] ], $id ) ); // phpcs:ignore WordPress.DB
				}
			}
		}

		foreach ( self::TABLES as $key ) {
			if ( 'pages' === $key || empty( $tables[ $key ]['cols'] ) || empty( $tables[ $key ]['rows'] ) ) {
				continue;
			}

			$table = FW_VI_Installer::table( $key );
			$here  = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB
			$cols  = array_values( array_intersect( (array) $tables[ $key ]['cols'], $here ) );
			$idx   = array_flip( (array) $tables[ $key ]['cols'] );

			if ( ! $cols ) {
				continue;
			}

			$sql = 'INSERT IGNORE INTO ' . $table . ' (`' . implode( '`,`', $cols ) . '`) VALUES ';
			$ph  = '(' . implode( ',', array_fill( 0, count( $cols ), '%s' ) ) . ')';

			foreach ( array_chunk( (array) $tables[ $key ]['rows'], self::BATCH ) as $chunk ) {
				$values = array();
				foreach ( $chunk as $r ) {
					$row = array();
					foreach ( $cols as $c ) {
						$v = $r[ $idx[ $c ] ] ?? '';
						if ( 'page_id' === $c ) {
							if ( ! isset( $map[ (int) $v ] ) ) {
								continue 2; // a row for a page the file does not name
							}
							$v = $map[ (int) $v ];
						} elseif ( 'path' === $c && 'notfound' === $key ) {
							$v = self::absolute( $v );
						}
						$row[] = (string) $v;
					}
					$values[] = $wpdb->prepare( $ph, $row ); // phpcs:ignore WordPress.DB.PreparedSQL
				}
				if ( $values ) {
					$wpdb->query( $sql . implode( ',', $values ) ); // phpcs:ignore WordPress.DB
					$count += max( 0, (int) $wpdb->rows_affected );
				}
			}
		}

		return $count;
	}

	/* ---------------------------------------------------------------------- *
	 * View
	 * ---------------------------------------------------------------------- */

	/**
	 * @param callable $fields Prints the nonce + action fields for a form.
	 */
	public static function render( $fields ) {
		$url = admin_url( 'admin-post.php' );
		?>
		<div class="fw-vi-transfer">
			<div class="fw-vi-card">
				<h2><?php esc_html_e( 'Export', 'fw' ); ?></h2>
				<p><?php esc_html_e( 'Download this site\'s Visitor Insights as one file: to keep a copy, to move to a new install, or to start another site with the same goals, funnels and settings.', 'fw' ); ?></p>
				<form method="get" action="<?php echo esc_url( $url ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<?php wp_nonce_field( self::ACTION, '_wpnonce', false ); ?>
					<fieldset>
						<label><input type="radio" name="data" value="1" checked> <?php esc_html_e( 'Setup and data', 'fw' ); ?></label><br>
						<label><input type="radio" name="data" value="0"> <?php esc_html_e( 'Setup only — settings, goals, funnels, A/B tests and notes', 'fw' ); ?></label>
					</fieldset>
					<p>
						<label for="fw-vi-tdays"><?php esc_html_e( 'Data from', 'fw' ); ?></label>
						<select name="days" id="fw-vi-tdays">
							<option value="0"><?php esc_html_e( 'All time', 'fw' ); ?></option>
							<option value="365"><?php esc_html_e( 'The last 12 months', 'fw' ); ?></option>
							<option value="90"><?php esc_html_e( 'The last 90 days', 'fw' ); ?></option>
							<option value="30"><?php esc_html_e( 'The last 30 days', 'fw' ); ?></option>
						</select>
					</p>
					<p><button type="submit" class="button button-primary"><span class="dashicons dashicons-download" style="vertical-align:-4px"></span> <?php esc_html_e( 'Download export file', 'fw' ); ?></button></p>
				</form>
				<p class="description"><?php esc_html_e( 'Not included: session recordings, the daily visitor keys and the collector\'s secrets. A copy makes its own, and recordings stay on the site whose visitors agreed to them.', 'fw' ); ?></p>
			</div>

			<div class="fw-vi-card">
				<h2><?php esc_html_e( 'Import', 'fw' ); ?></h2>
				<p><?php esc_html_e( 'Load a file exported from this site or another one. Pages are matched by their address, so history follows a site that moved to a new domain or folder.', 'fw' ); ?></p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( FW_VI_Admin_Page::url( 'transfer' ) ); ?>">
					<?php call_user_func( $fields, 'import' ); ?>
					<p><input type="file" name="file" accept=".json,.gz,application/json,application/gzip" required></p>
					<fieldset>
						<label><input type="checkbox" name="what[]" value="setup" checked> <?php esc_html_e( 'Setup', 'fw' ); ?></label>
						&nbsp; <label><input type="checkbox" name="what[]" value="data" checked> <?php esc_html_e( 'Data', 'fw' ); ?></label>
					</fieldset>
					<fieldset style="margin-top:.8em">
						<label><input type="radio" name="mode" value="merge" checked> <?php esc_html_e( 'Add to what is here — goals, funnels and days this site already has are kept', 'fw' ); ?></label><br>
						<label><input type="radio" name="mode" value="replace"> <?php esc_html_e( 'Replace — the file\'s setup wins, and this site\'s data is emptied first', 'fw' ); ?></label>
					</fieldset>
					<p><button type="submit" class="button"><span class="dashicons dashicons-upload" style="vertical-align:-4px"></span> <?php esc_html_e( 'Import', 'fw' ); ?></button></p>
				</form>
				<p class="description">
					<?php
					/* translators: %s: the server's upload size limit, e.g. "64 MB" */
					printf( esc_html__( 'Largest file this server accepts: %s.', 'fw' ), esc_html( size_format( wp_max_upload_size() ) ) );
					?>
				</p>
			</div>
		</div>
		<?php
	}
}
