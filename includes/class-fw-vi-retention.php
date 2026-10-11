<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The nightly job: delete report days past the retention setting, delete the
 * visitor hashes of every day before today, rotate the visitor salt (which
 * makes those hashes unrecoverable), and clear buffer files a stalled rollup
 * left behind. Deletes run in batches so no single statement holds a long lock.
 */
class FW_VI_Retention {

	const CRON  = 'fw_ext_vi_daily';
	const BATCH = 5000;

	public function __construct() {
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		add_action( 'init', array( $this, '_action_schedule' ) );
	}

	/**
	 * @internal
	 */
	public function _action_schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:17' ) ?: time() + DAY_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function run() {
		global $wpdb;

		$days   = max( 30, (int) FW_VI_Settings::get()['retention_days'] );
		$cutoff = wp_date( 'Y-m-d', time() - $days * DAY_IN_SECONDS );

		foreach ( array( 'views', 'sources', 'elem', 'clicks', 'scroll', 'sections' ) as $which ) {
			self::delete_where( FW_VI_Installer::table( $which ), 'day < %s', $cutoff );
		}

		self::delete_where( FW_VI_Installer::table( 'uniq' ), 'day < %s', wp_date( 'Y-m-d' ) );

		// A new salt for the new day; the old one is overwritten, never kept.
		FW_VI_Collector::salt();
		FW_VI_Collector::maybe_write_config();

		foreach ( FW_VI_Collector::buffer_files() as $file ) {
			if ( filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				update_option( 'fw_ext_vi_stale_buffer', time(), false );
			}
		}
	}

	/**
	 * @param string $table
	 * @param string $where with one %s placeholder
	 * @param string $value
	 */
	private static function delete_where( $table, $where, $value ) {
		global $wpdb;

		do {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$where} LIMIT " . self::BATCH, $value ) ); // phpcs:ignore WordPress.DB
		} while ( $wpdb->rows_affected >= self::BATCH );
	}
}
