<?php if ( ! defined( 'FW' ) && ! defined( 'FW_VI_COLLECTOR' ) ) {
	die( 'Forbidden' );
}

if ( ! class_exists( 'FW_VI_Geo' ) ) :

/**
 * Region and city of a visit — plain PHP, because the standalone collector
 * loads it without booting WordPress.
 *
 * Two sources, in order, and never a lookup service:
 *  1. headers a CDN or host already set (Cloudflare's visitor-location
 *     headers, Vercel, CloudFront, mod_geoip);
 *  2. a location database file kept on the site — any MaxMind-format city
 *     database (.mmdb): the free DB-IP "IP to City Lite" (downloadable from the
 *     Settings view, CC BY 4.0) or a GeoLite2 City file the owner provides.
 *
 * The address is used for the lookup and then dropped with the request, as
 * before. Only "Region|City" (and the country, when no header gave one) is kept.
 */
class FW_VI_Geo {

	/**
	 * @param string $ip
	 * @param string $db path of a .mmdb file, or ''
	 *
	 * @return array { cc, region, city, ll } — ll is "lat,lon" to one decimal (about 10 km), or ''
	 */
	public static function locate( $ip, $db ) {
		$out = self::from_headers();

		if ( '' === $out['city'] && '' !== (string) $db && '' !== (string) $ip && is_readable( $db ) ) {
			try {
				$rec = FW_VI_MMDB::open( $db )->get( $ip );
			} catch ( Exception $e ) {
				$rec = null;
			}

			if ( is_array( $rec ) ) {
				$out['cc']     = '' !== $out['cc'] ? $out['cc'] : strtoupper( (string) ( $rec['country']['iso_code'] ?? '' ) );
				$out['region'] = (string) ( $rec['subdivisions'][0]['names']['en'] ?? '' );
				$out['city']   = (string) ( $rec['city']['names']['en'] ?? '' );
				if ( isset( $rec['location']['latitude'], $rec['location']['longitude'] ) ) {
					$out['ll'] = round( (float) $rec['location']['latitude'], 1 ) . ',' . round( (float) $rec['location']['longitude'], 1 );
				}
			}
		}

		foreach ( array( 'region', 'city' ) as $k ) {
			$out[ $k ] = substr( trim( preg_replace( '/[\x00-\x1f<>"|]/', '', $out[ $k ] ) ), 0, 60 );
		}

		return $out;
	}

	/**
	 * @return array { cc, region, city }
	 */
	private static function from_headers() {
		$h = function ( $k ) {
			return isset( $_SERVER[ $k ] ) ? (string) $_SERVER[ $k ] : '';
		};

		$city   = $h( 'HTTP_CF_IPCITY' ) ?: rawurldecode( $h( 'HTTP_X_VERCEL_IP_CITY' ) ) ?: $h( 'HTTP_CLOUDFRONT_VIEWER_CITY' ) ?: $h( 'GEOIP_CITY' );
		$region = $h( 'HTTP_CF_REGION' ) ?: $h( 'HTTP_CLOUDFRONT_VIEWER_COUNTRY_REGION_NAME' ) ?: $h( 'GEOIP_REGION_NAME' ) ?: $h( 'HTTP_X_VERCEL_IP_COUNTRY_REGION' );

		$lat = $h( 'HTTP_CF_IPLATITUDE' ) ?: $h( 'HTTP_X_VERCEL_IP_LATITUDE' ) ?: $h( 'HTTP_CLOUDFRONT_VIEWER_LATITUDE' );
		$lon = $h( 'HTTP_CF_IPLONGITUDE' ) ?: $h( 'HTTP_X_VERCEL_IP_LONGITUDE' ) ?: $h( 'HTTP_CLOUDFRONT_VIEWER_LONGITUDE' );
		$ll  = is_numeric( $lat ) && is_numeric( $lon ) ? round( (float) $lat, 1 ) . ',' . round( (float) $lon, 1 ) : '';

		return array( 'cc' => '', 'region' => $region, 'city' => $city, 'll' => '' !== $city ? $ll : '' );
	}
}

/**
 * A minimal reader for MaxMind-format databases (.mmdb): the binary search
 * tree plus the data-section decoder, enough to answer one lookup per request.
 * Format: https://maxmind.github.io/MaxMind-DB/
 */
class FW_VI_MMDB {

	const MARKER = "\xAB\xCD\xEFMaxMind.com";

	/** @var FW_VI_MMDB[] */
	private static $open = array();

	private $fh;
	private $nodes;
	private $record;
	private $node_bytes;
	private $data;
	private $v4 = null;
	private $ip_version;

	/**
	 * @param string $file
	 *
	 * @return FW_VI_MMDB
	 */
	public static function open( $file ) {
		if ( ! isset( self::$open[ $file ] ) ) {
			self::$open[ $file ] = new self( $file );
		}

		return self::$open[ $file ];
	}

	private function __construct( $file ) {
		$this->fh = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $this->fh ) {
			throw new Exception( 'unreadable' );
		}

		$size = filesize( $file );
		$tail = min( $size, 131072 );
		fseek( $this->fh, $size - $tail );
		$buf = fread( $this->fh, $tail ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$pos = strrpos( $buf, self::MARKER );

		if ( false === $pos ) {
			throw new Exception( 'not a MaxMind database' );
		}

		$meta_start = $size - $tail + $pos + strlen( self::MARKER );
		list( $meta )     = $this->decode( $meta_start, $meta_start );

		$this->nodes      = (int) $meta['node_count'];
		$this->record     = (int) $meta['record_size'];
		$this->ip_version = (int) $meta['ip_version'];

		if ( ! in_array( $this->record, array( 24, 28, 32 ), true ) ) {
			throw new Exception( 'unsupported record size' );
		}

		$this->node_bytes = $this->record / 4;
		$this->data       = $this->nodes * $this->node_bytes + 16;
	}

	/**
	 * @param string $ip
	 *
	 * @return array|null
	 */
	public function get( $ip ) {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $bin ) {
			return null;
		}

		$bits = strlen( $bin ) * 8;
		$node = 0;

		if ( 32 === $bits && 6 === $this->ip_version ) {
			$node = $this->v4_start();
		}

		for ( $i = 0; $i < $bits && $node < $this->nodes; $i++ ) {
			$bit  = ( ord( $bin[ $i >> 3 ] ) >> ( 7 - ( $i & 7 ) ) ) & 1;
			$node = $this->read_node( $node, $bit );
		}

		if ( $node <= $this->nodes ) {
			return null;
		}

		list( $value ) = $this->decode( $this->data + ( $node - $this->nodes - 16 ), $this->data );

		return is_array( $value ) ? $value : null;
	}

	private function v4_start() {
		if ( null === $this->v4 ) {
			$node = 0;
			for ( $i = 0; $i < 96 && $node < $this->nodes; $i++ ) {
				$node = $this->read_node( $node, 0 );
			}
			$this->v4 = $node;
		}

		return $this->v4;
	}

	private function read( $offset, $len ) {
		fseek( $this->fh, $offset );

		return $len > 0 ? (string) fread( $this->fh, $len ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	private function read_node( $node, $index ) {
		$base = $node * $this->node_bytes;

		switch ( $this->record ) {
			case 24:
				return unpack( 'N', "\0" . $this->read( $base + $index * 3, 3 ) )[1];
			case 28:
				$b = $this->read( $base, 7 );
				return 0 === $index
					? ( ( ord( $b[3] ) & 0xF0 ) << 20 ) | ( ord( $b[0] ) << 16 ) | ( ord( $b[1] ) << 8 ) | ord( $b[2] )
					: ( ( ord( $b[3] ) & 0x0F ) << 24 ) | ( ord( $b[4] ) << 16 ) | ( ord( $b[5] ) << 8 ) | ord( $b[6] );
			default:
				return unpack( 'N', $this->read( $base + $index * 4, 4 ) )[1];
		}
	}

	private static function uint( $bytes ) {
		$n = 0;
		for ( $i = 0, $l = strlen( $bytes ); $i < $l; $i++ ) {
			$n = $n * 256 + ord( $bytes[ $i ] );
		}

		return $n;
	}

	/**
	 * @param int $offset absolute file offset
	 * @param int $base   start of the section pointers are relative to
	 *
	 * @return array [ value, next offset ]
	 */
	private function decode( $offset, $base ) {
		$ctrl = ord( $this->read( $offset++, 1 ) );
		$type = $ctrl >> 5;

		if ( 1 === $type ) {
			$size = ( $ctrl >> 3 ) & 3;
			$v    = $ctrl & 7;
			$b    = $this->read( $offset, $size + 1 );
			switch ( $size ) {
				case 0:
					$p = ( $v << 8 ) | ord( $b[0] );
					break;
				case 1:
					$p = ( ( $v << 16 ) | self::uint( $b ) ) + 2048;
					break;
				case 2:
					$p = ( ( $v << 24 ) | self::uint( $b ) ) + 526336;
					break;
				default:
					$p = self::uint( $b );
			}
			list( $value ) = $this->decode( $base + $p, $base );

			return array( $value, $offset + $size + 1 );
		}

		if ( 0 === $type ) {
			$type = 7 + ord( $this->read( $offset++, 1 ) );
		}

		$size = $ctrl & 0x1f;
		if ( $size >= 29 ) {
			$extra   = $size - 28;
			$n       = self::uint( $this->read( $offset, $extra ) );
			$offset += $extra;
			$size    = 29 === $size ? 29 + $n : ( 30 === $size ? 285 + $n : 65821 + $n );
		}

		switch ( $type ) {
			case 2: // utf-8 string
			case 4: // bytes
				return array( $this->read( $offset, $size ), $offset + $size );
			case 3: // double
				return array( unpack( 'E', $this->read( $offset, 8 ) )[1], $offset + 8 );
			case 15: // float
				return array( unpack( 'G', $this->read( $offset, 4 ) )[1], $offset + 4 );
			case 5:
			case 6:
			case 9:
			case 10:
				return array( self::uint( $this->read( $offset, $size ) ), $offset + $size );
			case 8: // int32
				$n = self::uint( $this->read( $offset, $size ) );
				return array( $size >= 4 && $n >= 2147483648 ? $n - 4294967296 : $n, $offset + $size );
			case 7: // map
				$map = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $k, $offset ) = $this->decode( $offset, $base );
					list( $v, $offset ) = $this->decode( $offset, $base );
					$map[ $k ]          = $v;
				}
				return array( $map, $offset );
			case 11: // array
				$arr = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $arr[], $offset ) = $this->decode( $offset, $base );
				}
				return array( $arr, $offset );
			case 14: // boolean
				return array( 0 !== $size, $offset );
			default: // data cache container, end marker
				return array( null, $offset );
		}
	}
}

endif;
