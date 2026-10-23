<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Traffic channels: one plain-language answer to "where did this visit come
 * from?", worked out from the referrer host and the campaign tags of the
 * page view, and stored by the rollup as the `ch` dimension of fw_vi_sources.
 *
 * Order matters — campaign tags win over the referrer, because a tagged link
 * says what the site owner meant it to be:
 *   Paid      utm_medium is a paid kind (cpc, ppc, paid…, display, cpm)
 *   Email     utm_medium / utm_source says email or newsletter
 *   Campaign  any other tagged link
 *   Direct    no referrer
 *   Search    a search engine
 *   AI        an AI assistant or answer engine
 *   Social    a social network or video site
 *   Referral  any other site
 *
 * The host lists are data, filterable through fw_ext_visitor_insights_channel_hosts.
 */
class FW_VI_Channels {

	/**
	 * @return array channel => labels shown in reports
	 */
	public static function labels() {
		return array(
			'direct'   => __( 'Direct', 'fw' ),
			'search'   => __( 'Search', 'fw' ),
			'social'   => __( 'Social', 'fw' ),
			'ai'       => __( 'AI assistants', 'fw' ),
			'email'    => __( 'Email', 'fw' ),
			'paid'     => __( 'Paid', 'fw' ),
			'campaign' => __( 'Campaign', 'fw' ),
			'referral' => __( 'Referral', 'fw' ),
		);
	}

	/**
	 * @return array channel => host fragments (matched against the referrer host)
	 */
	private static function hosts() {
		static $hosts = null;

		if ( null === $hosts ) {
			$hosts = (array) apply_filters( 'fw_ext_visitor_insights_channel_hosts', array(
				'search' => array( 'google.', 'bing.com', 'duckduckgo.com', 'search.yahoo.', 'yahoo.co', 'yandex.', 'baidu.com', 'ecosia.org', 'search.brave.com', 'startpage.com', 'qwant.com', 'naver.com', 'seznam.cz', 'sogou.com' ),
				'ai'     => array( 'chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'claude.ai', 'gemini.google.com', 'copilot.microsoft.com', 'you.com', 'phind.com', 'chat.mistral.ai', 'deepseek.com' ),
				'social' => array( 'facebook.com', 'fb.com', 'instagram.com', 'l.instagram.com', 't.co', 'twitter.com', 'x.com', 'linkedin.com', 'lnkd.in', 'pinterest.', 'reddit.com', 'youtube.com', 'youtu.be', 'tiktok.com', 'threads.net', 'bsky.app', 'mastodon.', 'tumblr.com', 'quora.com', 'vk.com', 'weibo.com', 'whatsapp.com', 'telegram.org', 'news.ycombinator.com' ),
			) );
		}

		return $hosts;
	}

	/**
	 * @param string $ref referrer host ('' = none)
	 * @param string $us  utm_source
	 * @param string $um  utm_medium
	 *
	 * @return string channel key
	 */
	public static function classify( $ref, $us, $um ) {
		$ref = strtolower( (string) $ref );
		$us  = strtolower( (string) $us );
		$um  = strtolower( (string) $um );

		if ( '' !== $um && preg_match( '/^(cpc|ppc|cpm|cpv|cpa|paid|paidsearch|paid[-_ ]?social|paid[-_ ]?search|display|banner|ads?|affiliate)$/', $um ) ) {
			return 'paid';
		}

		if ( preg_match( '/e-?mail|newsletter/', $um . ' ' . $us ) ) {
			return 'email';
		}

		if ( '' !== $us || '' !== $um ) {
			return 'campaign';
		}

		if ( '' === $ref ) {
			return 'direct';
		}

		foreach ( self::hosts() as $channel => $list ) {
			foreach ( (array) $list as $needle ) {
				if ( self::host_matches( $ref, (string) $needle ) ) {
					return $channel;
				}
			}
		}

		return 'referral';
	}

	/**
	 * Hostname matching by whole labels, so "t.co" does not match
	 * "pinterest.com". A needle ending in a dot ("google.") matches that name
	 * under any suffix ("google.com", "google.co.uk", "www.google.de"); any
	 * other needle matches itself and its subdomains.
	 *
	 * @param string $host
	 * @param string $needle
	 *
	 * @return bool
	 */
	private static function host_matches( $host, $needle ) {
		if ( '' === $needle ) {
			return false;
		}

		if ( '.' === substr( $needle, -1 ) ) {
			return 0 === strpos( $host, $needle ) || false !== strpos( $host, '.' . $needle );
		}

		return $host === $needle || substr( $host, -strlen( $needle ) - 1 ) === '.' . $needle;
	}
}
