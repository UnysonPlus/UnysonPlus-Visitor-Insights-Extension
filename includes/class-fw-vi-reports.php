<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Reads for the traffic screens. Every query is a range scan on a table's
 * `(page_id, day, …)` primary key or its `day` index.
 */
class FW_VI_Reports {

	/**
	 * @param int $days
	 *
	 * @return array [from, to] as Y-m-d in the site's timezone, inclusive
	 */
	public static function range( $days ) {
		$days = max( 1, (int) $days );

		return array( wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS ), wp_date( 'Y-m-d' ) );
	}

	/**
	 * @param int $days
	 *
	 * @return array views, uniques, engaged (avg seconds per view), pages
	 */
	public static function totals( $days ) {
		global $wpdb;

		list( $from, $to ) = self::range( $days );
		$t = FW_VI_Installer::table( 'views' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(views),0) v, COALESCE(SUM(uniques),0) u, COALESCE(SUM(engaged_ms),0) e FROM {$t} WHERE page_id = 0 AND day BETWEEN %s AND %s", $from, $to ) ); // phpcs:ignore WordPress.DB
		$pages = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT page_id) FROM {$t} WHERE page_id > 0 AND day BETWEEN %s AND %s AND views > 0", $from, $to ) ); // phpcs:ignore WordPress.DB

		return array(
			'views'   => (int) $row->v,
			'uniques' => (int) $row->u,
			'engaged' => $row->v ? round( $row->e / $row->v / 1000, 1 ) : 0,
			'pages'   => $pages,
		);
	}

	/**
	 * One entry per day of the range, zero-filled.
	 *
	 * @param int $days
	 *
	 * @return array [ {date, views, uniques} ]
	 */
	public static function series( $days ) {
		global $wpdb;

		list( $from, $to ) = self::range( $days );
		$t    = FW_VI_Installer::table( 'views' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(views) v, SUM(uniques) u FROM {$t} WHERE page_id = 0 AND day BETWEEN %s AND %s GROUP BY day", $from, $to ), OBJECT_K ); // phpcs:ignore WordPress.DB

		$out = array();
		for ( $ts = strtotime( $from . ' 12:00:00' ), $end = strtotime( $to . ' 12:00:00' ); $ts <= $end; $ts += DAY_IN_SECONDS ) {
			$d     = gmdate( 'Y-m-d', $ts );
			$out[] = array(
				'date'    => $d,
				'views'   => isset( $rows[ $d ] ) ? (int) $rows[ $d ]->v : 0,
				'uniques' => isset( $rows[ $d ] ) ? (int) $rows[ $d ]->u : 0,
			);
		}

		return $out;
	}

	/**
	 * Pages by views, with how many reached half-way down the page.
	 *
	 * @param int $days
	 * @param int $limit
	 *
	 * @return array
	 */
	public static function top_pages( $days, $limit = 20 ) {
		global $wpdb;

		list( $from, $to ) = self::range( $days );
		$v = FW_VI_Installer::table( 'views' );
		$p = FW_VI_Installer::table( 'pages' );
		$s = FW_VI_Installer::table( 'scroll' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.id, p.path, p.post_id, SUM(v.views) views, SUM(v.uniques) uniques, SUM(v.engaged_ms) engaged
			FROM {$v} v JOIN {$p} p ON p.id = v.page_id
			WHERE v.page_id > 0 AND v.day BETWEEN %s AND %s
			GROUP BY p.id ORDER BY views DESC LIMIT %d",
			$from, $to, $limit
		) ); // phpcs:ignore WordPress.DB

		if ( ! $rows ) {
			return array();
		}

		$ids  = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
		$half = $wpdb->get_results( $wpdb->prepare( "SELECT page_id, SUM(n) n FROM {$s} WHERE page_id IN ({$ids}) AND band = 50 AND day BETWEEN %s AND %s GROUP BY page_id", $from, $to ), OBJECT_K ); // phpcs:ignore WordPress.DB

		foreach ( $rows as $r ) {
			$r->views   = (int) $r->views;
			$r->uniques = (int) $r->uniques;
			$r->engaged = $r->views ? round( $r->engaged / $r->views / 1000, 1 ) : 0;
			$r->half    = $r->views && isset( $half[ $r->id ] ) ? min( 100, round( 100 * $half[ $r->id ]->n / $r->views ) ) : null;
			$r->title   = $r->post_id ? get_the_title( (int) $r->post_id ) : '';
		}

		return $rows;
	}

	/**
	 * Site-wide counts for one dimension.
	 *
	 * @param string $dim ref|us|um|uc|cc|br|os|dev
	 * @param int    $days
	 * @param int    $limit
	 *
	 * @return array [ val => n ]
	 */
	public static function breakdown( $dim, $days, $limit = 10 ) {
		global $wpdb;

		list( $from, $to ) = self::range( $days );
		$t = FW_VI_Installer::table( 'sources' );

		return wp_list_pluck( $wpdb->get_results( $wpdb->prepare(
			"SELECT val, SUM(n) n FROM {$t} WHERE dim = %s AND day BETWEEN %s AND %s GROUP BY val ORDER BY n DESC LIMIT %d",
			$dim, $from, $to, $limit
		) ), 'n', 'val' ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Page views seen in the last five minutes. Batches arrive at most a
	 * minute or two late (the rollup runs once a minute), so this trails real
	 * time by about that much.
	 *
	 * @return array visitors, pages [ {path, n} ], devices [ d => n ]
	 */
	public static function live_visitors() {
		global $wpdb;

		$l     = FW_VI_Installer::table( 'live' );
		$p     = FW_VI_Installer::table( 'pages' );
		$since = time() - 300;

		$pages = $wpdb->get_results( $wpdb->prepare( "SELECT p.path, COUNT(*) n FROM {$l} l JOIN {$p} p ON p.id = l.page_id WHERE l.seen >= %d GROUP BY p.path ORDER BY n DESC LIMIT 20", $since ) ); // phpcs:ignore WordPress.DB
		$devs  = $wpdb->get_results( $wpdb->prepare( "SELECT device, COUNT(*) n FROM {$l} WHERE seen >= %d GROUP BY device", $since ), OBJECT_K ); // phpcs:ignore WordPress.DB

		return array(
			'visitors' => array_sum( array_map( 'intval', wp_list_pluck( $pages, 'n' ) ) ),
			'pages'    => array_map( function ( $r ) {
				return array( 'path' => $r->path, 'n' => (int) $r->n );
			}, $pages ),
			'devices'  => array(
				'd' => isset( $devs['d'] ) ? (int) $devs['d']->n : 0,
				't' => isset( $devs['t'] ) ? (int) $devs['t']->n : 0,
				'm' => isset( $devs['m'] ) ? (int) $devs['m']->n : 0,
			),
			'at'       => time(),
		);
	}

	/**
	 * A page's public URL from its stored path (which already includes any
	 * subdirectory the site lives in).
	 *
	 * @param string $path
	 *
	 * @return string
	 */
	public static function url( $path ) {
		$home = wp_parse_url( home_url() );
		$port = isset( $home['port'] ) ? ':' . $home['port'] : '';

		return $home['scheme'] . '://' . $home['host'] . $port . $path;
	}
}
