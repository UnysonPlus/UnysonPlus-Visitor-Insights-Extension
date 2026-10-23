<?php if ( ! defined( 'FW' ) && ! defined( 'FW_VI_COLLECTOR' ) ) {
	die( 'Forbidden' );
}

if ( ! class_exists( 'FW_VI_Ingest' ) ) :

/**
 * Validates one batch from the tracker and appends it to the current minute's
 * buffer file. Plain PHP, no WordPress calls: the standalone collector runs it
 * without booting WordPress, and the REST fallback runs the same code inside
 * WordPress, so both paths accept exactly the same input.
 *
 * Protection, in layers — there is no nonce, because a nonce baked into a
 * cached page goes stale:
 *   - POST only, body ≤ 16 KB, strict schema with clamped ranges;
 *   - the Origin header, when sent, must be one of the site's own hosts;
 *   - every batch carries an HMAC page token minted when the page was built,
 *     bound to the post id + path + day and accepted for 8 days, so it outlives
 *     a page cache but cannot be reused for another page;
 *   - bots, excluded addresses and a per-minute size cap are dropped here, and
 *     the rollup applies a per-page daily cap.
 * The worst a forged batch can do is increment counters inside a fixed grid.
 *
 * Buffer line: [ts, post_id, path, device, vid, seq, visitor_hash, country,
 *               browser, os, pageview|null, events]
 * seq -1 marks a line WordPress wrote itself (a server-side goal, a 404);
 * the collector never accepts a negative seq, so a browser cannot forge one.
 */
class FW_VI_Ingest {

	const MAX_BODY   = 16384;
	const MAX_EVENTS = 300;
	// A page token is accepted for 45 days, so a page that a cache keeps for
	// weeks is still counted; it only allows reporting visits to that page.
	const TOKEN_DAYS = 45;

	/**
	 * The page token. Shared by the page that mints it and the collector that
	 * checks it.
	 *
	 * @param string $secret
	 * @param int    $post_id
	 * @param string $path
	 * @param string $day Ymd (UTC)
	 *
	 * @return string 16 hex
	 */
	public static function token( $secret, $post_id, $path, $day ) {
		return substr( hash_hmac( 'sha256', (int) $post_id . '|' . $path . '|' . $day, (string) $secret ), 0, 16 );
	}

	/**
	 * @param array  $config see FW_VI_Collector::config()
	 * @param string $body   raw request body
	 *
	 * @return int HTTP status: 204 accepted (or silently dropped), 400, 403, 413
	 */
	public static function handle( array $config, $body ) {
		if ( strlen( (string) $body ) > self::MAX_BODY ) {
			return 413;
		}

		if ( ! self::origin_ok( $config ) ) {
			return 403;
		}

		$in = json_decode( (string) $body, true );

		if ( ! is_array( $in ) || 1 !== ( $in['v'] ?? null ) ) {
			return 400;
		}

		$pid  = isset( $in['p'] ) && is_int( $in['p'] ) && $in['p'] >= 0 ? $in['p'] : -1;
		$path = isset( $in['u'] ) && is_string( $in['u'] ) ? $in['u'] : '';
		$tok  = isset( $in['k'] ) && is_string( $in['k'] ) ? $in['k'] : '';
		$vid  = isset( $in['i'] ) && is_string( $in['i'] ) ? $in['i'] : '';
		$seq  = isset( $in['q'] ) && is_int( $in['q'] ) ? $in['q'] : -1;
		$dev  = isset( $in['d'] ) && is_string( $in['d'] ) ? $in['d'] : '';

		if ( $pid < 0 || '' === $path || '/' !== $path[0] || strlen( $path ) > 191
			|| ! preg_match( '/^[a-f0-9]{16}$/', $tok )
			|| ! preg_match( '/^[a-z0-9]{8,16}$/', $vid )
			|| $seq < 0 || $seq > 1000
			|| ! in_array( $dev, array( 'd', 't', 'm' ), true ) ) {
			return 400;
		}

		if ( ! self::token_ok( (string) $config['secret'], $pid, $path, $tok ) ) {
			return 403;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

		if ( FW_VI_Bot_Filter::is_bot( $ua ) ) {
			// Counted for the bot report, never as a visit.
			if ( ! empty( $config['bots'] ) ) {
				$bot = FW_VI_Bot_Filter::classify( $ua );
				self::append( $config, json_encode( array( time(), 0, '', 'd', '', -1, '', '', '', '', null, array( array( 'b', $bot[0], $bot[1] ) ) ) ) );
			}
			return 204;
		}

		$ip = FW_VI_Bot_Filter::client_ip( (array) $config['proxies'] );

		if ( '' !== $ip && $config['excluded_ips'] && FW_VI_Bot_Filter::ip_in_list( $ip, (array) $config['excluded_ips'] ) ) {
			return 204;
		}

		$events = self::events( isset( $in['e'] ) ? $in['e'] : array() );
		$pv     = null;
		$vh     = '';
		$cc     = '';
		$fam    = array( 'browser' => '', 'os' => '' );

		// The first batch of a page view carries the view itself: who (a hash
		// that only means anything today), where from, and with what.
		if ( 0 === $seq ) {
			$pv  = self::pageview( isset( $in['pv'] ) ? $in['pv'] : array(), $config );
			$vh  = substr( hash( 'sha256', $config['salt'] . '|' . $ip . '|' . $ua . '|' . $config['blog'] ), 0, 16 );
			$cc  = FW_VI_Bot_Filter::country();
			$fam = FW_VI_Bot_Filter::families( $ua );

			if ( ! empty( $config['city'] ) && class_exists( 'FW_VI_Geo' ) ) {
				$geo   = FW_VI_Geo::locate( $ip, (string) ( $config['geo_db'] ?? '' ) );
				$cc    = '' !== $cc ? $cc : $geo['cc'];
				$pv[8] = '' !== $geo['city'] ? $geo['region'] . '|' . $geo['city'] : '';
				$pv[9] = '' !== $pv[8] && preg_match( '/^-?\d{1,2}(\.\d)?,-?\d{1,3}(\.\d)?$/', (string) ( $geo['ll'] ?? '' ) ) ? $geo['ll'] : '';
			}
		} elseif ( ! $events ) {
			return 204;
		}

		$line = json_encode( array(
			time(), $pid, $path, $dev, $vid, $seq, $vh, $cc, $fam['browser'], $fam['os'], $pv, $events,
		) );

		self::append( $config, $line );

		return 204;
	}

	/**
	 * @param array $config
	 *
	 * @return bool
	 */
	private static function origin_ok( array $config ) {
		if ( empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			return true; // sendBeacon from the same origin may omit it
		}

		$host = parse_url( (string) $_SERVER['HTTP_ORIGIN'], PHP_URL_HOST );

		return is_string( $host ) && in_array( strtolower( $host ), (array) $config['hosts'], true );
	}

	/**
	 * Accept the token for today and the previous days (UTC), so a page served
	 * from a cache built last week still reports.
	 */
	public static function token_ok( $secret, $pid, $path, $tok ) {
		for ( $i = 0; $i < self::TOKEN_DAYS; $i++ ) {
			if ( hash_equals( self::token( $secret, $pid, $path, gmdate( 'Ymd', time() - $i * 86400 ) ), $tok ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $raw
	 *
	 * @return array clean events only; anything malformed is dropped
	 */
	private static function events( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out    = array();
		$id     = '/^[A-Za-z0-9_-]{6,32}$/';
		$goals  = 0;
		$errors = 0;
		$vitals = array();
		$forms  = 0;
		$moves  = 0;
		$outbound = 0;

		foreach ( array_slice( $raw, 0, self::MAX_EVENTS ) as $e ) {
			if ( ! is_array( $e ) || ! isset( $e[0] ) || ! is_string( $e[0] ) ) {
				continue;
			}

			switch ( $e[0] ) {
				case 'c': // click: [c, item, ox, oy] inside an item, or [c, '', cx, cy] on the page
					$item = isset( $e[1] ) && is_string( $e[1] ) ? $e[1] : '';
					$a    = isset( $e[2] ) && is_int( $e[2] ) ? $e[2] : -1;
					$b    = isset( $e[3] ) && is_int( $e[3] ) ? $e[3] : -1;

					if ( '' !== $item ) {
						if ( preg_match( $id, $item ) && $a >= 0 && $a <= 9 && $b >= 0 && $b <= 9 ) {
							$out[] = array( 'c', $item, $a, $b );
						}
					} elseif ( $a >= 0 && $a <= 100 && $b >= 0 && $b <= 5000 ) {
						$out[] = array( 'c', '', $a, $b );
					}
					break;

				case 's': // scroll band newly reached
					$band = isset( $e[1] ) && is_int( $e[1] ) ? $e[1] : -1;
					if ( $band >= 0 && $band <= 100 && 0 === $band % 5 ) {
						$out[] = array( 's', $band );
					}
					break;

				case 'r': // section reached
					if ( isset( $e[1] ) && is_string( $e[1] ) && preg_match( $id, $e[1] ) ) {
						$out[] = array( 'r', $e[1] );
					}
					break;

				case 'a': // section attention, ms since the last batch
					$ms = isset( $e[2] ) && is_int( $e[2] ) ? $e[2] : -1;
					if ( isset( $e[1] ) && is_string( $e[1] ) && preg_match( $id, $e[1] ) && $ms > 0 && $ms <= 600000 ) {
						$out[] = array( 'a', $e[1], $ms );
					}
					break;

				case 't': // engaged time, ms since the last batch
					$ms = isset( $e[1] ) && is_int( $e[1] ) ? $e[1] : -1;
					if ( $ms > 0 && $ms <= 1800000 ) {
						$out[] = array( 't', $ms );
					}
					break;

				case 'g': // goal completion: [g, key, value?] — keys are checked against the site's goals at rollup
					$v = isset( $e[2] ) && is_int( $e[2] ) ? $e[2] : 0;
					if ( isset( $e[1] ) && is_string( $e[1] ) && preg_match( '/^[a-z0-9_-]{1,40}$/', $e[1] ) && $v >= 0 && $v <= 1000000000 && ++$goals <= 20 ) {
						$out[] = array( 'g', $e[1], $v );
					}
					break;

				case 'f': // frustration: [f, r|d|e, item|'']
					$item = isset( $e[2] ) && is_string( $e[2] ) ? $e[2] : '';
					if ( isset( $e[1] ) && in_array( $e[1], array( 'r', 'd', 'e' ), true ) && ( '' === $item || preg_match( $id, $item ) ) ) {
						$out[] = array( 'f', $e[1], $item );
					}
					break;

				case 'x': // script error: [x, message, source, line]
					if ( ++$errors <= 5 && isset( $e[1] ) && is_string( $e[1] ) && '' !== trim( $e[1] ) ) {
						$out[] = array(
							'x',
							self::mask( $e[1], 150 ),
							isset( $e[2] ) && is_string( $e[2] ) ? self::mask( $e[2], 150 ) : '',
							isset( $e[3] ) && is_int( $e[3] ) ? max( 0, min( 1000000, $e[3] ) ) : 0,
						);
					}
					break;

				case 'fs': // form started: [fs, form, label]
				case 'fc': // form submitted: [fc, form]
				case 'fa': // form abandoned: [fa, form, last field]
				case 'ff': // field focused first time: [ff, form, field, position]
					$form = isset( $e[1] ) && is_string( $e[1] ) ? $e[1] : '';
					if ( ! preg_match( '/^[A-Za-z0-9_.:-]{1,40}$/', $form ) || ++$forms > 60 ) {
						break;
					}
					if ( 'fs' === $e[0] ) {
						$out[] = array( 'fs', $form, isset( $e[2] ) && is_string( $e[2] ) ? self::mask( $e[2], 100 ) : '' );
					} elseif ( 'fc' === $e[0] ) {
						$out[] = array( 'fc', $form );
					} else {
						$field = isset( $e[2] ) && is_string( $e[2] ) ? $e[2] : '';
						if ( preg_match( '/^[A-Za-z0-9_.:\[\]-]{1,40}$/', $field ) ) {
							$out[] = 'ff' === $e[0]
								? array( 'ff', $form, $field, isset( $e[3] ) && is_int( $e[3] ) ? max( 0, min( 500, $e[3] ) ) : 0 )
								: array( 'fa', $form, $field );
						}
					}
					break;

				case 'm': // pointer movement bin: [m, item|'', a, b, n]
					$item = isset( $e[1] ) && is_string( $e[1] ) ? $e[1] : '';
					$a    = isset( $e[2] ) && is_int( $e[2] ) ? $e[2] : -1;
					$b    = isset( $e[3] ) && is_int( $e[3] ) ? $e[3] : -1;
					$n    = isset( $e[4] ) && is_int( $e[4] ) ? $e[4] : 0;
					if ( ++$moves > 200 || $n < 1 || $n > 400 ) {
						break;
					}
					if ( '' !== $item ? ( preg_match( $id, $item ) && $a >= 0 && $a <= 9 && $b >= 0 && $b <= 9 ) : ( $a >= 0 && $a <= 100 && $b >= 0 && $b <= 5000 ) ) {
						$out[] = array( 'm', $item, $a, $b, $n );
					}
					break;

				case 'e': // experiment exposure: [e, experiment, a|b]
					if ( isset( $e[1], $e[2] ) && is_string( $e[1] ) && preg_match( '/^[a-z0-9-]{1,20}$/', $e[1] ) && in_array( $e[2], array( 'a', 'b' ), true ) ) {
						$out[] = array( 'e', $e[1], $e[2] );
					}
					break;

				case 'o': // left by a link or a download: [o, l|d, url]
					$url = isset( $e[2] ) && is_string( $e[2] ) ? preg_replace( '/[?#].*$/', '', $e[2] ) : '';
					if ( ++$outbound <= 10 && isset( $e[1] ) && in_array( $e[1], array( 'l', 'd' ), true ) && preg_match( '~^https?://[^\s<>"]+$~i', $url ) ) {
						$out[] = array( 'o', $e[1], substr( $url, 0, 190 ) );
					}
					break;

				case 'v': // Web Vital: [v, lcp|inp|cls|ttfb, value] (cls × 1000)
					$v = isset( $e[2] ) && is_int( $e[2] ) ? $e[2] : -1;
					if ( isset( $e[1] ) && in_array( $e[1], array( 'lcp', 'inp', 'cls', 'ttfb' ), true ) && $v >= 0 && $v <= 600000 && empty( $vitals[ $e[1] ] ) ) {
						$vitals[ $e[1] ] = true;
						$out[]           = array( 'v', $e[1], $v );
					}
					break;
			}
		}

		return $out;
	}

	/**
	 * Strip what could identify someone from free text before it is stored:
	 * email addresses, long digit runs (phone, card, order numbers), query
	 * strings, control characters.
	 *
	 * @param string $text
	 * @param int    $max
	 *
	 * @return string
	 */
	public static function mask( $text, $max ) {
		$text = preg_replace( '/[\x00-\x1f<>"]/', ' ', (string) $text );
		$text = preg_replace( '/[^\s@]+@[^\s@]+\.[a-z]{2,}/i', '[email]', $text );
		$text = preg_replace( '/\?[^\s]*/', '', $text );
		$text = preg_replace( '/\d[\d\s-]{5,}\d/', '#', $text );

		return substr( trim( preg_replace( '/\s+/', ' ', $text ) ), 0, $max );
	}

	/**
	 * Referrer host (external only), the three common UTM values, the search
	 * words a referring search engine passed (if any) and utm_term — both
	 * masked like site-search terms.
	 *
	 * @param mixed $raw
	 * @param array $config
	 *
	 * The referring page's path, when the browser sent it, rides along too.
	 *
	 * So does "n" / "r" (new / returning), sent only under statistics consent.
	 * The collector adds "Region|City" at index 8 when it can tell.
	 *
	 * @return array [ref, us, um, uc, kw, ut, rp, rt]
	 */
	private static function pageview( $raw, array $config ) {
		$raw = is_array( $raw ) ? $raw : array();
		$s   = function ( $k, $max ) use ( $raw ) {
			if ( ! isset( $raw[ $k ] ) || ! is_string( $raw[ $k ] ) ) {
				return '';
			}

			return substr( trim( preg_replace( '/[\x00-\x1f<>"]/', '', $raw[ $k ] ) ), 0, $max );
		};

		$ref = strtolower( $s( 'r', 120 ) );
		$ref = preg_replace( '/^www\./', '', $ref );

		if ( '' !== $ref && ( ! preg_match( '/^[a-z0-9.-]+$/', $ref ) || in_array( $ref, (array) $config['hosts'], true ) ) ) {
			$ref = '';
		}

		$rt = in_array( $s( 'rt', 1 ), array( 'n', 'r' ), true ) ? $s( 'rt', 1 ) : '';
		$kw = '' !== $ref ? strtolower( self::mask( $s( 'k', 100 ), 80 ) ) : '';

		return array( $ref, strtolower( $s( 'us', 80 ) ), strtolower( $s( 'um', 80 ) ), $s( 'uc', 120 ), $kw, strtolower( self::mask( $s( 'ut', 100 ), 80 ) ), '' !== $ref ? preg_replace( '/[^\/@]+@[^\/@]+/', '[email]', str_replace( ' ', '%20', $s( 'rp', 140 ) ) ) : '', $rt );
	}

	/**
	 * Append one line to this minute's buffer. The file starts with an exit
	 * line, so even a server that ignores .htaccess never serves its contents.
	 *
	 * @param array  $config
	 * @param string $line
	 *
	 * @return bool
	 */
	public static function append( array $config, $line ) {
		$dir = rtrim( (string) $config['buffer_dir'], '/\\' );

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return false;
		}

		$file = $dir . '/buf-' . $config['buffer_key'] . '-' . gmdate( 'YmdHi' ) . '.php';

		clearstatcache( true, $file );
		if ( is_file( $file ) && filesize( $file ) > (int) $config['cap_bytes'] ) {
			return false;
		}

		$fh = @fopen( $file, 'ab' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

		if ( ! $fh ) {
			return false;
		}

		flock( $fh, LOCK_EX );
		if ( 0 === (int) fstat( $fh )['size'] ) {
			fwrite( $fh, "<?php exit; ?>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		fwrite( $fh, $line . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		flock( $fh, LOCK_UN );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return true;
	}
}

endif;
