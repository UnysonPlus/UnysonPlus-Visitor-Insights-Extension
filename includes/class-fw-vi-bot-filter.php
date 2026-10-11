<?php if ( ! defined( 'FW' ) && ! defined( 'FW_VI_COLLECTOR' ) ) {
	die( 'Forbidden' );
}

if ( ! class_exists( 'FW_VI_Bot_Filter' ) ) :

/**
 * Bots, user-agent families and client addresses — plain PHP with no WordPress
 * calls, because the standalone collector loads it without booting WordPress.
 *
 * The user-agent list is the same family list the Short Links tracker uses;
 * the tracker also refuses to start under `navigator.webdriver`, and the
 * collector drops batches with no user agent at all.
 */
class FW_VI_Bot_Filter {

	/** Crawlers, link unfurlers, monitors, HTTP libraries, headless browsers, AI fetchers. */
	const BOT_REGEX = '~bot\b|bot/|crawl|spider|slurp|mediapartners|facebookexternalhit|facebookcatalog|embedly|quora link preview|outbrain|pinterest|vkshare|w3c_validator|whatsapp|telegrambot|discordbot|slackbot|skypeuripreview|bitlybot|tumblr|nuzzel|redditbot|applebot|yandex|baiduspider|bingpreview|duckduckgo|petalbot|semrush|ahrefs|mj12|dotbot|rogerbot|screaming frog|uptimerobot|pingdom|statuscake|site24x7|newrelic|datadog|headlesschrome|phantomjs|puppeteer|playwright|selenium|lighthouse|pagespeed|gtmetrix|curl/|wget/|python-requests|python-urllib|aiohttp|httpx|go-http-client|okhttp|java/|libwww|httpclient|axios/|node-fetch|undici|guzzle|wordpress/|gptbot|chatgpt|oai-searchbot|claudebot|claude-web|anthropic|perplexity|ccbot|bytespider|amazonbot|google-extended|cohere|diffbot|scrapy~i';

	/**
	 * @param string $ua
	 *
	 * @return bool
	 */
	public static function is_bot( $ua ) {
		$ua = (string) $ua;

		if ( '' === trim( $ua ) ) {
			return true;
		}

		// A prefetch or prerender that was never shown to anyone.
		$purpose = isset( $_SERVER['HTTP_SEC_PURPOSE'] ) ? (string) $_SERVER['HTTP_SEC_PURPOSE'] : ( isset( $_SERVER['HTTP_PURPOSE'] ) ? (string) $_SERVER['HTTP_PURPOSE'] : '' );
		if ( false !== stripos( $purpose, 'prefetch' ) ) {
			return true;
		}

		return 1 === preg_match( self::BOT_REGEX, $ua );
	}

	/**
	 * Browser and OS families. The raw user agent is never stored.
	 *
	 * @param string $ua
	 *
	 * @return array { browser, os }
	 */
	public static function families( $ua ) {
		$os = 'Other';
		foreach ( array(
			'iOS'      => '/iPhone|iPad|iPod/i',
			'Android'  => '/Android/i',
			'Windows'  => '/Windows/i',
			'macOS'    => '/Mac OS X|Macintosh/i',
			'ChromeOS' => '/CrOS/i',
			'Linux'    => '/Linux/i',
		) as $name => $re ) {
			if ( preg_match( $re, $ua ) ) {
				$os = $name;
				break;
			}
		}

		$browser = 'Other';
		foreach ( array(
			'Edge'             => '/Edg(e|A|iOS)?\//',
			'Opera'            => '/OPR\/|Opera/',
			'Samsung Internet' => '/SamsungBrowser/',
			'Firefox'          => '/Firefox\/|FxiOS/',
			'Chrome'           => '/Chrome\/|CriOS/',
			'Safari'           => '/Safari\//',
		) as $name => $re ) {
			if ( preg_match( $re, $ua ) ) {
				$browser = $name;
				break;
			}
		}

		return array( 'browser' => $browser, 'os' => $os );
	}

	/**
	 * Country from headers a CDN or host already set. Never a remote lookup.
	 *
	 * @return string ISO-3166 alpha-2 or ''
	 */
	public static function country() {
		foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_X_VERCEL_IP_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_APPENGINE_COUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'GEOIP_COUNTRY_CODE' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$c = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $_SERVER[ $h ] ), 0, 2 ) );

				if ( 2 === strlen( $c ) && ! in_array( $c, array( 'XX', 'T1', 'ZZ' ), true ) ) {
					return $c;
				}
			}
		}

		return '';
	}

	/**
	 * The client address. Forwarded headers are believed only when the direct
	 * peer is a configured trusted proxy.
	 *
	 * @param string[] $proxies
	 *
	 * @return string '' when unknown
	 */
	public static function client_ip( array $proxies ) {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		$ip     = $remote;

		if ( $proxies && self::ip_in_list( $remote, $proxies ) ) {
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$ip = trim( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] );
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				foreach ( array_reverse( array_map( 'trim', explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) as $hop ) {
					if ( filter_var( $hop, FILTER_VALIDATE_IP ) && ! self::ip_in_list( $hop, $proxies ) ) {
						$ip = $hop;
						break;
					}
				}
			} elseif ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
				$ip = trim( (string) $_SERVER['HTTP_X_REAL_IP'] );
			}
		}

		if ( 0 === stripos( $ip, '::ffff:' ) && filter_var( substr( $ip, 7 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$ip = substr( $ip, 7 );
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Exact addresses and CIDR ranges, IPv4 and IPv6.
	 *
	 * @param string   $ip
	 * @param string[] $list
	 *
	 * @return bool
	 */
	public static function ip_in_list( $ip, array $list ) {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $bin ) {
			return false;
		}

		foreach ( $list as $entry ) {
			if ( false === strpos( $entry, '/' ) ) {
				if ( @inet_pton( $entry ) === $bin ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					return true;
				}
				continue;
			}

			list( $net, $bits ) = explode( '/', $entry, 2 );
			$net_bin = @inet_pton( trim( $net ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$bits    = (int) $bits;

			if ( false === $net_bin || strlen( $net_bin ) !== strlen( $bin ) ) {
				continue;
			}

			$bytes = intdiv( $bits, 8 );
			$rem   = $bits % 8;

			if ( 0 !== strncmp( $bin, $net_bin, $bytes ) ) {
				continue;
			}

			if ( 0 === $rem ) {
				return true;
			}

			$mask = ( 0xff << ( 8 - $rem ) ) & 0xff;

			if ( ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $net_bin[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}

		return false;
	}
}

endif;
