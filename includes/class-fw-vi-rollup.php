<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Buffer files → report tables. The only writer of the report tables.
 *
 * Runs every minute from WP-Cron, and again whenever a report is opened, so a
 * site whose cron stalls still shows current numbers. Only files from minutes
 * that are over are read (the collector may still be appending to this
 * minute's), each is summed in memory, written as a handful of multi-row
 * `INSERT … ON DUPLICATE KEY UPDATE` statements, and deleted.
 *
 * An atomic add_option() lock keeps two runs from reading the same file.
 */
class FW_VI_Rollup {

	const CRON     = 'fw_ext_vi_rollup';
	const LOCK     = 'fw_ext_vi_rollup_lock';
	const SCHEDULE = 'fw_vi_minute';
	const CHUNK    = 400;

	/** @var array resolved page ids, keyed by path */
	private static $pages = array();

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, '_filter_schedules' ) );
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		add_action( 'init', array( $this, '_action_schedule' ) );
	}

	/**
	 * @internal
	 */
	public function _filter_schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (Visitor Insights)', 'fw' ),
		);

		return $schedules;
	}

	/**
	 * @internal
	 */
	public function _action_schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::CRON );
		}
	}

	/**
	 * Fold every finished minute into the tables.
	 *
	 * @return int lines processed
	 */
	public static function run() {
		// A lock older than five minutes belongs to a run that died.
		if ( ! add_option( self::LOCK, time(), '', false ) ) {
			if ( time() - (int) get_option( self::LOCK ) < 300 ) {
				return 0;
			}
			update_option( self::LOCK, time(), false );
		}

		$lines = 0;

		try {
			FW_VI_Collector::maybe_write_config();

			$current = gmdate( 'YmdHi' );

			foreach ( FW_VI_Collector::buffer_files() as $file ) {
				if ( preg_match( '/-(\d{12})\.php$/', $file, $m ) && $m[1] >= $current ) {
					continue; // still being written
				}

				$lines += self::process( $file );
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}

			self::prune_live();
		} finally {
			delete_option( self::LOCK );
		}

		return $lines;
	}

	/**
	 * @param string $file
	 *
	 * @return int lines read
	 */
	private static function process( $file ) {
		$fh = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

		if ( ! $fh ) {
			return 0;
		}

		$cap   = max( 0, (int) FW_VI_Settings::get()['daily_cap'] );
		$agg   = array(
			'views' => array(), 'uniq' => array(), 'sources' => array(), 'elem' => array(),
			'clicks' => array(), 'scroll' => array(), 'sections' => array(), 'live' => array(),
			'goals' => array(), 'friction' => array(), 'errors' => array(), 'errmsg' => array(),
			'notfound' => array(), 'vitals' => array(),
		);
		$seen  = array(); // page|day|vid → counted (a view over the daily cap is ignored whole)
		$lines = 0;
		$known = FW_VI_Goals::known_keys();
		$pgoal = FW_VI_Goals::page_goals();
		$vidvh = array(); // view id → visitor hash, from this run's page views
		$tps   = array(); // funnel touchpoints: [ts, vh|null, vid, kind p|g, value, day]

		while ( false !== ( $raw = fgets( $fh ) ) ) {
			$row = json_decode( $raw, true );

			if ( ! is_array( $row ) || 12 !== count( $row ) ) {
				continue; // the exit header, or a torn line
			}

			list( $ts, $post_id, $path, $dev, $vid, $seq, $vh, $cc, $br, $os, $pv, $events ) = $row;

			$day = wp_date( 'Y-m-d', (int) $ts );
			$lines++;

			// A line WordPress wrote itself: a server-side goal or a 404. Not a view.
			if ( -1 === (int) $seq ) {
				foreach ( (array) $events as $e ) {
					if ( 'g' === $e[0] && isset( $known[ $e[1] ] ) ) {
						$gp = self::page_id( (int) $post_id, (string) $path );
						self::add( $agg['goals'], array( $gp, $day, $dev, $e[1] ), array( 1, (int) $e[2] ) );
						if ( '' !== $vh ) {
							$tps[] = array( (int) $ts, $vh, '', 'g', $e[1], $day );
						}
					} elseif ( 'n' === $e[0] ) {
						self::add( $agg['notfound'], array( $day, (string) $path, (string) $e[1] ), array( 1 ) );
					}
				}
				continue;
			}

			$page = self::page_id( (int) $post_id, (string) $path );
			$vk   = $page . '|' . $day . '|' . $vid;

			if ( 0 === (int) $seq ) {
				if ( $cap && self::views_so_far( $page, $day, $agg ) >= $cap ) {
					$seen[ $vk ] = false;
					continue;
				}

				$seen[ $vk ] = true;

				self::add( $agg['views'], array( $page, $day, $dev ), array( 1, 0, 0 ) );
				self::add( $agg['views'], array( 0, $day, $dev ), array( 1, 0, 0 ) );

				if ( '' !== $vh ) {
					$agg['uniq'][ $day ][ $page ][ $vh ] = true;
					$agg['uniq'][ $day ][0][ $vh ]       = true;
					$vidvh[ $vid ]                       = $vh;
					$tps[]                               = array( (int) $ts, $vh, $vid, 'p', (string) $path, $day );
				}

				// Page-visit goals.
				foreach ( $pgoal as $key => $pattern ) {
					if ( FW_VI_Goals::path_matches( $pattern, (string) $path ) ) {
						self::add( $agg['goals'], array( $page, $day, $dev, $key ), array( 1, 0 ) );
						if ( '' !== $vh ) {
							$tps[] = array( (int) $ts, $vh, $vid, 'g', $key, $day );
						}
					}
				}

				$dims = array( 'dev' => $dev, 'cc' => $cc, 'br' => $br, 'os' => $os );

				if ( is_array( $pv ) ) {
					$dims += array( 'ref' => $pv[0] ?? '', 'us' => $pv[1] ?? '', 'um' => $pv[2] ?? '', 'uc' => $pv[3] ?? '' );
				}

				foreach ( $dims as $dim => $val ) {
					if ( '' !== (string) $val ) {
						self::add( $agg['sources'], array( $page, $day, $dim, mb_substr( (string) $val, 0, 150 ) ), array( 1 ) );
					}
				}
			} elseif ( isset( $seen[ $vk ] ) && false === $seen[ $vk ] ) {
				continue; // the rest of a view that was over the cap
			}

			$agg['live'][ $vid ] = array( $page, $dev, (int) $ts, $vidvh[ $vid ] ?? '' );

			foreach ( (array) $events as $e ) {
				switch ( $e[0] ) {
					case 'c':
						if ( '' !== $e[1] ) {
							self::add( $agg['elem'], array( $page, $day, $dev, $e[1], $e[2], $e[3] ), array( 1 ) );
						} else {
							self::add( $agg['clicks'], array( $page, $day, $dev, $e[2], $e[3] ), array( 1 ) );
						}
						break;
					case 's':
						self::add( $agg['scroll'], array( $page, $day, $dev, $e[1] ), array( 1 ) );
						break;
					case 'r':
						self::add( $agg['sections'], array( $page, $day, $dev, $e[1] ), array( 1, 0 ) );
						break;
					case 'a':
						self::add( $agg['sections'], array( $page, $day, $dev, $e[1] ), array( 0, $e[2] ) );
						break;
					case 't':
						self::add( $agg['views'], array( $page, $day, $dev ), array( 0, 0, $e[1] ) );
						self::add( $agg['views'], array( 0, $day, $dev ), array( 0, 0, $e[1] ) );
						break;
					case 'g':
						if ( isset( $known[ $e[1] ] ) ) {
							self::add( $agg['goals'], array( $page, $day, $dev, $e[1] ), array( 1, (int) $e[2] ) );
							$tps[] = array( (int) $ts, $vidvh[ $vid ] ?? null, $vid, 'g', $e[1], $day );
						}
						break;
					case 'f':
						self::add( $agg['friction'], array( $page, $day, $dev, $e[2], $e[1] ), array( 1 ) );
						break;
					case 'x':
						$hash = substr( md5( $e[1] . '|' . $e[2] . '|' . $e[3] ), 0, 16 );
						self::add( $agg['errors'], array( $page, $day, $hash ), array( 1 ) );
						$agg['errmsg'][ $hash ] = array( $e[1], $e[2], (int) $e[3] );
						break;
					case 'v':
						self::add( $agg['vitals'], array( $page, $day, $dev, $e[1], FW_VI_Vitals::bucket( $e[1], (int) $e[2] ) ), array( 1 ) );
						break;
				}
			}
		}

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		self::write( $agg );

		if ( $tps ) {
			FW_VI_Funnels::process( self::resolve_visitors( $tps ) );
		}

		return $lines;
	}

	/**
	 * Sum $vals into $bucket under the composite key.
	 */
	private static function add( array &$bucket, array $key, array $vals ) {
		$k = implode( "\x1f", $key );

		if ( ! isset( $bucket[ $k ] ) ) {
			$bucket[ $k ] = array( $key, $vals );
			return;
		}

		foreach ( $vals as $i => $v ) {
			$bucket[ $k ][1][ $i ] += $v;
		}
	}

	/**
	 * Views already stored for this page and day (any device), plus this run's.
	 */
	private static function views_so_far( $page, $day, array $agg ) {
		static $stored = array();
		global $wpdb;

		$k = $page . '|' . $day;

		if ( ! isset( $stored[ $k ] ) ) {
			$stored[ $k ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(views),0) FROM ' . FW_VI_Installer::table( 'views' ) . ' WHERE page_id = %d AND day = %s', $page, $day ) ); // phpcs:ignore WordPress.DB
		}

		$run = 0;
		foreach ( array( 'd', 't', 'm' ) as $dev ) {
			$key = implode( "\x1f", array( $page, $day, $dev ) );
			$run += isset( $agg['views'][ $key ] ) ? $agg['views'][ $key ][1][0] : 0;
		}

		return $stored[ $k ] + $run;
	}

	/**
	 * The page row for a path, created on first sight.
	 *
	 * @param int    $post_id
	 * @param string $path
	 *
	 * @return int
	 */
	public static function page_id( $post_id, $path ) {
		global $wpdb;

		if ( isset( self::$pages[ $path ] ) ) {
			return self::$pages[ $path ];
		}

		$table = FW_VI_Installer::table( 'pages' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE path = %s", $path ) ); // phpcs:ignore WordPress.DB

		if ( ! $id ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (post_id, path, created) VALUES (%d, %s, %s)", $post_id, $path, wp_date( 'Y-m-d' ) ) ); // phpcs:ignore WordPress.DB
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE path = %s", $path ) ); // phpcs:ignore WordPress.DB
		}

		self::$pages[ $path ] = $id;

		return $id;
	}

	/**
	 * @param array $agg
	 */
	private static function write( array $agg ) {
		global $wpdb;

		// Unique visitors first: INSERT IGNORE tells us how many hashes are new
		// for each page and day, and that number is what goes into `uniques`.
		$utable = FW_VI_Installer::table( 'uniq' );

		foreach ( $agg['uniq'] as $day => $pages ) {
			foreach ( $pages as $page => $hashes ) {
				$new = 0;

				foreach ( array_chunk( array_keys( $hashes ), self::CHUNK ) as $chunk ) {
					$rows = array();
					foreach ( $chunk as $vh ) {
						$rows[] = $wpdb->prepare( '(%s,%d,%s)', $day, $page, $vh );
					}
					$wpdb->query( "INSERT IGNORE INTO {$utable} (day, page_id, vh) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB
					$new += (int) $wpdb->rows_affected;
				}

				if ( $new ) {
					// Credited to the desktop row only when no row exists for the day
					// yet; uniques are reported per page and day, not per device.
					$dev = self::any_device( $agg['views'], $page, $day );
					self::add( $agg['views'], array( $page, $day, $dev ), array( 0, $new, 0 ) );
				}
			}
		}

		self::upsert( 'views', array( 'page_id' => '%d', 'day' => '%s', 'device' => '%s' ), array( 'views', 'uniques', 'engaged_ms' ), $agg['views'] );
		self::upsert( 'sources', array( 'page_id' => '%d', 'day' => '%s', 'dim' => '%s', 'val' => '%s' ), array( 'n' ), $agg['sources'] );
		self::upsert( 'elem', array( 'page_id' => '%d', 'day' => '%s', 'device' => '%s', 'item' => '%s', 'ox' => '%d', 'oy' => '%d' ), array( 'n' ), $agg['elem'] );
		self::upsert( 'clicks', array( 'page_id' => '%d', 'day' => '%s', 'device' => '%s', 'cx' => '%d', 'cy' => '%d' ), array( 'n' ), $agg['clicks'] );
		self::upsert( 'scroll', array( 'page_id' => '%d', 'day' => '%s', 'device' => '%s', 'band' => '%d' ), array( 'n' ), $agg['scroll'] );
		self::upsert( 'sections', array( 'page_id' => '%d', 'day' => '%s', 'device' => '%s', 'item' => '%s' ), array( 'reached', 'visible_ms' ), $agg['sections'] );
		self::upsert( 'goals', array( 'page_id' => '%d', 'day' => '%s', 'device' => '%s', 'goal' => '%s' ), array( 'n', 'value' ), $agg['goals'] );
		self::upsert( 'friction', array( 'page_id' => '%d', 'day' => '%s', 'device' => '%s', 'item' => '%s', 'kind' => '%s' ), array( 'n' ), $agg['friction'] );
		self::upsert( 'errors', array( 'page_id' => '%d', 'day' => '%s', 'hash' => '%s' ), array( 'n' ), $agg['errors'] );
		self::upsert( 'notfound', array( 'day' => '%s', 'path' => '%s', 'ref' => '%s' ), array( 'n' ), $agg['notfound'] );
		self::upsert( 'vitals', array( 'page_id' => '%d', 'day' => '%s', 'device' => '%s', 'metric' => '%s', 'bucket' => '%d' ), array( 'n' ), $agg['vitals'] );

		if ( $agg['errmsg'] ) {
			$mtable = FW_VI_Installer::table( 'errmsg' );
			foreach ( array_chunk( $agg['errmsg'], self::CHUNK, true ) as $chunk ) {
				$rows = array();
				foreach ( $chunk as $hash => $m ) {
					$rows[] = $wpdb->prepare( '(%s,%s,%s,%d)', $hash, $m[0], $m[1], $m[2] );
				}
				$wpdb->query( "INSERT IGNORE INTO {$mtable} (hash, message, source, line) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB
			}
		}

		if ( $agg['live'] ) {
			$ltable = FW_VI_Installer::table( 'live' );
			foreach ( array_chunk( $agg['live'], self::CHUNK, true ) as $chunk ) {
				$rows = array();
				foreach ( $chunk as $vid => $v ) {
					$rows[] = $wpdb->prepare( '(%s,%d,%s,%d,%s)', $vid, $v[0], $v[1], $v[2], $v[3] );
				}
				$wpdb->query( "INSERT INTO {$ltable} (vid, page_id, device, seen, vh) VALUES " . implode( ',', $rows ) . " ON DUPLICATE KEY UPDATE seen = GREATEST(seen, VALUES(seen)), vh = IF(VALUES(vh) <> '', VALUES(vh), vh)" ); // phpcs:ignore WordPress.DB
			}
		}
	}

	/**
	 * Goal events from batches after the first carry only the view id; the
	 * visitor hash came with that view's first batch, possibly in an earlier
	 * run, and is kept on its live row for ten minutes.
	 *
	 * @param array $tps
	 *
	 * @return array touchpoints with a visitor hash, in time order
	 */
	private static function resolve_visitors( array $tps ) {
		global $wpdb;

		$missing = array();
		foreach ( $tps as $tp ) {
			if ( null === $tp[1] && '' !== $tp[2] ) {
				$missing[ $tp[2] ] = true;
			}
		}

		$map = array();
		if ( $missing ) {
			$in  = implode( ',', array_map( function ( $v ) use ( $wpdb ) {
				return $wpdb->prepare( '%s', $v );
			}, array_keys( $missing ) ) );
			$map = $wpdb->get_results( 'SELECT vid, vh FROM ' . FW_VI_Installer::table( 'live' ) . " WHERE vid IN ({$in}) AND vh <> ''", OBJECT_K ); // phpcs:ignore WordPress.DB
		}

		$out = array();
		foreach ( $tps as $tp ) {
			if ( null === $tp[1] ) {
				if ( ! isset( $map[ $tp[2] ] ) ) {
					continue;
				}
				$tp[1] = $map[ $tp[2] ]->vh;
			}
			$out[] = $tp;
		}

		usort( $out, function ( $a, $b ) {
			return $a[0] <=> $b[0];
		} );

		return $out;
	}

	/**
	 * The device of a views row already in this run for the page and day, or
	 * desktop.
	 */
	private static function any_device( array $views, $page, $day ) {
		foreach ( array( 'd', 'm', 't' ) as $dev ) {
			if ( isset( $views[ implode( "\x1f", array( $page, $day, $dev ) ) ] ) ) {
				return $dev;
			}
		}

		return 'd';
	}

	/**
	 * @param string $which  table key
	 * @param array  $keys   column => placeholder, in key order
	 * @param array  $sums   counter columns, in value order
	 * @param array  $bucket from add()
	 */
	private static function upsert( $which, array $keys, array $sums, array $bucket ) {
		global $wpdb;

		if ( ! $bucket ) {
			return;
		}

		$table  = FW_VI_Installer::table( $which );
		$cols   = array_merge( array_keys( $keys ), $sums );
		$ph     = '(' . implode( ',', array_merge( array_values( $keys ), array_fill( 0, count( $sums ), '%d' ) ) ) . ')';
		$update = implode( ', ', array_map( function ( $c ) {
			return "{$c} = {$c} + VALUES({$c})";
		}, $sums ) );

		foreach ( array_chunk( $bucket, self::CHUNK ) as $chunk ) {
			$rows = array();
			foreach ( $chunk as $entry ) {
				$rows[] = $wpdb->prepare( $ph, array_merge( $entry[0], $entry[1] ) ); // phpcs:ignore WordPress.DB
			}

			$wpdb->query( "INSERT INTO {$table} (" . implode( ',', $cols ) . ') VALUES ' . implode( ',', $rows ) . " ON DUPLICATE KEY UPDATE {$update}" ); // phpcs:ignore WordPress.DB
		}
	}

	private static function prune_live() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . FW_VI_Installer::table( 'live' ) . ' WHERE seen < %d', time() - 600 ) ); // phpcs:ignore WordPress.DB
	}
}
