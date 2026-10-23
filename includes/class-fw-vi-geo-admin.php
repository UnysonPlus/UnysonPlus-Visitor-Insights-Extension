<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The city database on this site: where it lives, fetching the free one, and
 * the card on the Settings view.
 *
 * The free database is DB-IP "IP to City Lite" (CC BY 4.0 — the attribution is
 * shown wherever the data is). It is about 60 MB to download and 130 MB on
 * disk, refreshed monthly by DB-IP; "Download again" picks up the newest. A
 * GeoLite2 City file (or any MaxMind-format city database) can be put in the
 * same folder instead, named city.mmdb.
 *
 *   uploads/unysonplus/geo/city.mmdb
 */
class FW_VI_Geo_Admin {

	const SOURCE = 'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz';
	const OPT    = 'fw_ext_vi_geo_db'; // { source, month, at }

	/**
	 * @return string The database path when the file exists, else ''.
	 */
	public static function file() {
		$file = self::path();

		return is_readable( $file ) ? $file : '';
	}

	private static function path() {
		return function_exists( 'fw_upw_uploads_dir' ) ? fw_upw_uploads_dir( 'geo' )['path'] . '/city.mmdb' : WP_CONTENT_DIR . '/uploads/unysonplus/geo/city.mmdb';
	}

	/**
	 * Fetches this month's free database (last month's when this month's is
	 * not out yet), unpacking it as it arrives.
	 *
	 * @return true|string true, or what went wrong
	 */
	public static function download() {
		if ( ! function_exists( 'gzopen' ) ) {
			return __( 'This server cannot unpack .gz files (the PHP zlib extension is missing).', 'fw' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		@set_time_limit( 900 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$tmp = null;
		foreach ( array( gmdate( 'Y-m' ), gmdate( 'Y-m', strtotime( 'first day of last month' ) ) ) as $month ) {
			$tmp = download_url( sprintf( self::SOURCE, $month ), 900 );
			if ( ! is_wp_error( $tmp ) ) {
				break;
			}
		}

		if ( is_wp_error( $tmp ) ) {
			return $tmp->get_error_message();
		}

		$path = self::path();
		wp_mkdir_p( dirname( $path ) );
		$part = $path . '.part';

		$in  = gzopen( $tmp, 'rb' );
		$out = fopen( $part, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		while ( $in && $out && ! gzeof( $in ) ) {
			fwrite( $out, gzread( $in, 1048576 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$in && gzclose( $in );
		$out && fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

		try {
			FW_VI_MMDB::open( $part )->get( '8.8.8.8' );
		} catch ( Exception $e ) {
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return __( 'The downloaded file is not a readable location database.', 'fw' );
		}

		rename( $part, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		update_option( self::OPT, array( 'source' => 'dbip', 'month' => $month, 'at' => time() ), false );

		// A folder of public data, but no reason to list it.
		if ( ! file_exists( dirname( $path ) . '/index.php' ) ) {
			file_put_contents( dirname( $path ) . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return true;
	}

	public static function remove() {
		@unlink( self::path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		delete_option( self::OPT );
	}

	/**
	 * Settings-view actions.
	 *
	 * @param string $action
	 *
	 * @return array|null [ type, message ]
	 */
	public static function handle( $action ) {
		if ( 'geo_download' === $action ) {
			$r = self::download();
			FW_VI_Collector::maybe_write_config( true );
			return true === $r ? array( 'success', __( 'City database installed. Visits from now on get a region and city.', 'fw' ) ) : array( 'error', $r );
		}
		if ( 'geo_remove' === $action ) {
			self::remove();
			FW_VI_Collector::maybe_write_config( true );
			return array( 'success', __( 'City database removed.', 'fw' ) );
		}

		return null;
	}

	/**
	 * The "City database" card.
	 *
	 * @param callable $fields
	 */
	public static function render( $fields ) {
		$file = self::file();
		$meta = (array) get_option( self::OPT, array() );
		?>
		<div class="fw-vi-card">
			<h2><?php esc_html_e( 'City database', 'fw' ); ?></h2>
			<?php if ( $file ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: file size, 2: date */
						esc_html__( 'Installed (%1$s, %2$s). Regions and cities are looked up on this site; the visitor\'s address is never sent anywhere.', 'fw' ),
						esc_html( size_format( filesize( $file ) ) ),
						esc_html( ! empty( $meta['at'] ) ? sprintf( __( 'downloaded %s', 'fw' ), date_i18n( get_option( 'date_format' ), $meta['at'] ) ) : __( 'added by hand', 'fw' ) )
					);
					?>
				</p>
				<?php if ( 'dbip' === ( $meta['source'] ?? '' ) ) : ?>
					<p class="description"><?php echo wp_kses( __( 'IP geolocation by <a href="https://db-ip.com" target="_blank" rel="noopener">DB-IP</a>, licensed under CC BY 4.0. DB-IP updates it monthly.', 'fw' ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'Without one, a city is known only when your CDN adds location headers. Download the free DB-IP city database (about 60 MB to download, 130 MB on disk), or put a GeoLite2 City file in the folder below, named city.mmdb.', 'fw' ); ?></p>
			<?php endif; ?>
			<p class="description"><code><?php echo esc_html( self::path() ); ?></code></p>
			<form method="post" style="display:inline">
				<?php call_user_func( $fields, 'geo_download' ); ?>
				<button type="submit" class="button<?php echo $file ? '' : ' button-primary'; ?>" onclick="this.disabled=true;this.form.submit();this.textContent='<?php echo esc_js( __( 'Downloading… this can take a minute', 'fw' ) ); ?>'"><?php echo $file ? esc_html__( 'Download again', 'fw' ) : esc_html__( 'Download the free city database', 'fw' ); ?></button>
			</form>
			<?php if ( $file ) : ?>
				<form method="post" style="display:inline">
					<?php call_user_func( $fields, 'geo_remove' ); ?>
					<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Remove', 'fw' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
