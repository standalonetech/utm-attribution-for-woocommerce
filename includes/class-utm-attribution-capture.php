<?php
/**
 * Capture UTM parameters from URL.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures UTM parameters from inbound URLs.
 */
class Utm_Attribution_Capture {

	/**
	 * Window for treating the same tags, or the same cookieless browser, as one visit (GA's session default).
	 */
	const REPEAT_WINDOW = 30 * MINUTE_IN_SECONDS;

	/**
	 * Compiled classifier regexes, keyed by type.
	 *
	 * @var array
	 */
	private static $patterns = array();

	public function __construct() {
		add_action( 'wp', array( $this, 'maybe_capture' ), 1 );
	}

	public function maybe_capture() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || $this->is_noise_request() ) {
			return;
		}

		$existing_visit_id = utm_attribution_get_visit_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$has_utm = ! empty( $_GET['utm_source'] );

		/**
		 * Logic:
		 * 1. If UTM parameters are present, we ALWAYS record a new visit (new campaign click).
		 * 2. If no UTMs are present, we only record a visit if the user doesn't already have an active attribution session.
		 *    This prevents recording every internal page refresh as a 'direct' or 'referral' visit.
		 */
		if ( ! $has_utm && $existing_visit_id ) {
			return;
		}

		$params = $this->get_attribution_params();

		// A reload, Back button or redirect of the same tagged URL is not a new campaign click.
		if ( $has_utm && $existing_visit_id && $this->is_recent_same_visit( $existing_visit_id, $params ) ) {
			return;
		}

		// If it's an internal referral and we don't have UTMs, ignore it.
		if ( ! $has_utm && $this->is_internal_referral() ) {
			return;
		}

		// A client that never returns the cookie (monitor, scraper) would otherwise log a visit per request.
		if ( ! $has_utm && $this->is_cookieless_repeat( $params ) ) {
			return;
		}

		$visit_id = $this->store_visit( $params );

		if ( $visit_id ) {
			utm_attribution_set_visit_cookie( $visit_id );
		}
	}

	/**
	 * Whether the cookie's visit carries the same tags and is under 30 minutes old.
	 *
	 * @param int   $visit_id Visit ID from the cookie.
	 * @param array $params   Sanitized UTM params of this request.
	 * @return bool
	 */
	private function is_recent_same_visit( $visit_id, $params ) {
		global $wpdb;

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}utm_attribution_visits
				WHERE id = %d AND utm_source = %s AND utm_medium <=> %s AND utm_campaign <=> %s AND utm_term <=> %s AND utm_content <=> %s AND visited_at >= %s",
				$visit_id,
				$params['utm_source'],
				$params['utm_medium'],
				$params['utm_campaign'],
				$params['utm_term'],
				$params['utm_content'],
				gmdate( 'Y-m-d H:i:s', time() - self::REPEAT_WINDOW )
			)
		);
	}

	/**
	 * Whether this browser (IP hash + User-Agent) already logged a visit from the same source within the window.
	 * No cookie is handed out on a match: behind carrier NAT it may be a different person. The source must
	 * match too, so another visitor arriving from a different source is still recorded.
	 *
	 * @param array $params Sanitized attribution params of this request.
	 * @return bool
	 */
	private function is_cookieless_repeat( $params ) {
		global $wpdb;

		if ( ! utm_attribution_get_settings( 'merge_repeat_visits' ) ) {
			return false;
		}

		$ip_hash = utm_attribution_get_ip_hash();
		if ( null === $ip_hash ) {
			return false;
		}

		// Same truncation as store_visit(), so the stored value matches.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '';

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}utm_attribution_visits WHERE ip_hash = %s AND visited_at >= %s AND user_agent = %s AND utm_source = %s AND utm_medium <=> %s LIMIT 1",
				$ip_hash,
				gmdate( 'Y-m-d H:i:s', time() - self::REPEAT_WINDOW ),
				$ua,
				$params['utm_source'],
				$params['utm_medium']
			)
		);
	}

	/**
	 * Requests that are not a person landing on a page: bots, monitors, staff, non-page URLs, non-GET, wc-ajax, 404s, feeds.
	 *
	 * @return bool
	 */
	private function is_noise_request() {
		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$path    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$purpose = '';
		foreach ( array( 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE' ) as $header ) {
			if ( isset( $_SERVER[ $header ] ) ) {
				$purpose .= sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$noise = 'GET' !== $method || isset( $_GET['wc-ajax'] ) || is_404() || is_feed() || is_robots() || is_trackback()
			|| ( utm_attribution_get_settings( 'exclude_non_page' ) && ( is_favicon() || self::is_noise_path( $path ) ) )
			|| '' !== self::classify_user_agent( $ua )
			|| ( utm_attribution_get_settings( 'ignore_prefetch' ) && false !== stripos( $purpose, 'prefetch' ) )
			|| self::is_excluded_user( get_current_user_id() );

		/**
		 * Filters whether the current request is skipped by visit capture.
		 *
		 * @param bool   $noise True to skip recording a visit.
		 * @param string $ua    Sanitized User-Agent.
		 */
		return (bool) apply_filters( 'utm_attribution_skip_capture', $noise, $ua );
	}

	/**
	 * Classify a User-Agent. Shared by live capture and the noise cleanup.
	 *
	 * @param string|null $ua User-Agent; null means anonymised (kept).
	 * @return string 'monitor', 'bot' or '' for a likely person.
	 */
	public static function classify_user_agent( $ua ) {
		if ( null === $ua || ! utm_attribution_get_settings( 'exclude_bots' ) ) {
			return '';
		}

		if ( '' === $ua ) {
			return 'bot';
		}

		if ( self::matches( 'monitor', $ua ) ) {
			return 'monitor';
		}

		return self::matches( 'bot', $ua ) ? 'bot' : '';
	}

	/**
	 * Whether a URL path is never a page a person lands on (sitemaps, /.well-known/, robots.txt, feeds).
	 *
	 * @param string $path URL path.
	 * @return bool
	 */
	public static function is_noise_path( $path ) {
		return utm_attribution_get_settings( 'exclude_non_page' ) && self::matches( 'path', (string) $path );
	}

	/**
	 * Fingerprint of everything that decides what the classifiers drop, so a cleanup scan can tell its rules changed.
	 *
	 * @return string
	 */
	public static function rules_hash() {
		$flags = array_intersect_key( utm_attribution_get_settings(), array_flip( array( 'exclude_bots', 'exclude_non_page', 'exclude_staff', 'merge_repeat_visits', 'ip_hashing' ) ) );

		return md5( wp_json_encode( array( $flags, self::compile( 'monitor' ), self::compile( 'bot' ), self::compile( 'path' ) ) ) );
	}

	/**
	 * Whether a logged-in user is shop staff whose browsing should not count as visits.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_excluded_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return false;
		}

		$exclude = utm_attribution_get_settings( 'exclude_staff' ) && user_can( $user_id, 'manage_woocommerce' );

		/**
		 * Filters whether a logged-in user's visits are skipped.
		 *
		 * @param bool $exclude True to skip. Default: user can manage_woocommerce and staff exclusion is on.
		 * @param int  $user_id User ID.
		 */
		return (bool) apply_filters( 'utm_attribution_exclude_user', $exclude, $user_id );
	}

	/**
	 * Whether a host is the site itself (exact, as in 1.3.0) or a listed internal domain or its subdomain.
	 * Subdomains of the site are not assumed internal: a blog or newsletter subdomain can be a real traffic source.
	 *
	 * @param string $host Host name.
	 * @return bool
	 */
	public static function is_internal_host( $host ) {
		$host = preg_replace( '/^www\./', '', strtolower( (string) $host ) );
		if ( '' === $host ) {
			return false;
		}

		$site_host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		if ( $host === $site_host ) {
			return true;
		}

		/**
		 * Filters the domains whose links count as internal (their subdomains too).
		 *
		 * @param string[] $domains Domains entered in Settings.
		 */
		$domains = (array) apply_filters( 'utm_attribution_internal_domains', (array) utm_attribution_get_settings( 'internal_domains' ) );

		foreach ( $domains as $domain ) {
			$domain = strtolower( (string) $domain );
			if ( '' !== $domain && '.' !== substr( $domain, -1 ) && self::host_matches( $host, $domain ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match a subject against a compiled classifier regex. A broken filtered pattern fails open.
	 *
	 * @param string $type    'monitor', 'bot' or 'path'.
	 * @param string $subject User-Agent or path.
	 * @return bool
	 */
	private static function matches( $type, $subject ) {
		if ( ! isset( self::$patterns[ $type ] ) ) {
			self::$patterns[ $type ] = self::compile( $type );
		}

		return '' !== self::$patterns[ $type ] && 1 === preg_match( self::$patterns[ $type ], $subject );
	}

	/**
	 * Build one case-insensitive regex from the built-in fragments, admin entries and filters.
	 *
	 * @param string $type 'monitor', 'bot' or 'path'.
	 * @return string Regex, or '' when there is nothing to match.
	 */
	private static function compile( $type ) {
		$quote = function ( $value ) {
			return preg_quote( (string) $value, '#' );
		};
		// Admin paths match the start of the path, after the subdirectory of a subdirectory install.
		$quote_path = function ( $value ) use ( $quote ) {
			return '^' . $quote( untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) ) ) . $quote( $value );
		};

		if ( 'monitor' === $type ) {
			$fragments = array( 'jetmon', 'uptime', 'pingdom', 'statuscake', 'site24x7' );
		} elseif ( 'bot' === $type ) {
			$fragments = array_merge(
				// 1.3.0 set, kept verbatim.
				array( 'bot', 'crawl', 'spider', 'slurp', 'preview', 'headless', 'lighthouse', 'curl', 'wget', 'python-', 'go-http', 'facebookexternalhit' ),
				// Fetchers without "bot" in the name, HTTP libraries, link previews, loopbacks.
				array( 'mediapartners-google', 'googleother', 'google-', 'nexus 5x build/mmb29p', 'scrapy', 'okhttp', 'axios', 'node-fetch', 'undici', '^node$', '^java/', 'apache-httpclient', 'python/', 'aiohttp', 'httpx', 'guzzlehttp', 'libwww', '^whatsapp/', '^wordpress/', 'meta-external' ),
				array( 'geedoshop', 'terracotta', '^mozilla/5\.0$', 'cms-checker', 'privacy preserving prefetch proxy' ),
				array_map( $quote, (array) utm_attribution_get_settings( 'extra_bot_patterns' ) )
			);

			/**
			 * Filters the User-Agent fragments treated as bots. Fragments are case-insensitive
			 * regex pieces joined with "|" inside "#…#i", so escape any "#".
			 *
			 * @param string[] $fragments Regex fragments.
			 */
			$fragments = apply_filters( 'utm_attribution_bot_ua_patterns', $fragments );
		} else {
			$fragments = array_merge(
				array( '/\.well-known/', 'sitemap[^/]*\.xml(?:\.gz)?$', '\.(?:txt|xml|json|ico|webmanifest)$', '/feed/?$' ),
				array_map( $quote_path, (array) utm_attribution_get_settings( 'extra_noise_paths' ) )
			);

			/**
			 * Filters the URL path fragments that never count as a landing page. Same format as
			 * utm_attribution_bot_ua_patterns, matched against the path only.
			 *
			 * @param string[] $fragments Regex fragments.
			 */
			$fragments = apply_filters( 'utm_attribution_noise_path_patterns', $fragments );
		}

		$fragments = array_filter( array_map( 'strval', (array) $fragments ), 'strlen' );

		return $fragments ? '#(?:' . implode( '|', $fragments ) . ')#i' : '';
	}

	/**
	 * Consolidates UTM, Referrer, and Direct traffic logic.
	 *
	 * @return array
	 */
	private function get_attribution_params() {
		// 1. Try UTM parameters first.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['utm_source'] ) ) {
			return $this->get_sanitized_utm_params();
		}

		// 2. Try Referrer analysis.
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! empty( $referrer ) ) {
			return $this->parse_referrer( $referrer );
		}

		// 3. Fallback to Direct traffic.
		return array(
			'utm_source'   => '(direct)',
			'utm_medium'   => '(none)',
			'utm_campaign' => '(none)',
			'utm_term'     => '',
			'utm_content'  => '',
			'utm_site_id'  => '',
		);
	}

	/**
	 * Basic referrer parser for Organic and Social sources.
	 *
	 * @param string $referrer
	 * @return array
	 */
	private function parse_referrer( $referrer ) {
		$host = wp_parse_url( $referrer, PHP_URL_HOST );
		$host = preg_replace( '/^www\./', '', strtolower( (string) $host ) );

		$params = array(
			'utm_source'   => $host,
			'utm_medium'   => 'referral',
			'utm_campaign' => '(none)',
			'utm_term'     => '',
			'utm_content'  => '',
			'utm_site_id'  => '',
		);

		// Organic Search detection.
		$organic_map = array(
			'google.' => 'google',
			'bing.com' => 'bing',
			'yahoo.com' => 'yahoo',
			'duckduckgo.com' => 'duckduckgo',
			'baidu.com' => 'baidu',
			'yandex.' => 'yandex',
		);

		foreach ( $organic_map as $pattern => $source ) {
			if ( self::host_matches( $host, $pattern ) ) {
				$params['utm_source'] = $source;
				$params['utm_medium'] = 'organic';
				break;
			}
		}

		// Social Media detection.
		$social_map = array(
			'facebook.com' => 'facebook',
			'fb.me'        => 'facebook',
			't.co'         => 'twitter',
			'twitter.com'  => 'twitter',
			'x.com'        => 'twitter',
			'instagram.com' => 'instagram',
			'linkedin.com' => 'linkedin',
			'pinterest.'   => 'pinterest',
			'reddit.com'   => 'reddit',
			't.me'         => 'telegram',
		);

		foreach ( $social_map as $pattern => $source ) {
			if ( self::host_matches( $host, $pattern ) ) {
				$params['utm_source'] = $source;
				$params['utm_medium'] = 'social';
				break;
			}
		}

		return $params;
	}

	/**
	 * Match a host against a domain ("x.com" also matches "m.x.com", never "netflix.com") or a
	 * brand with any country TLD ("google." matches "google.co.in", never "google.evil.com").
	 *
	 * @param string $host    Lowercased host, www. stripped.
	 * @param string $pattern Domain, or brand ending in a dot.
	 * @return bool
	 */
	private static function host_matches( $host, $pattern ) {
		if ( '.' === substr( $pattern, -1 ) ) {
			// ponytail: TLD shape is [2-3 letters] plus optional 2-letter country; covers .com, .de, .co.uk, .com.au.
			return (bool) preg_match( '/(^|\.)' . preg_quote( $pattern, '/' ) . '[a-z]{2,3}(\.[a-z]{2})?$/', $host );
		}

		return $host === $pattern || substr( $host, -strlen( $pattern ) - 1 ) === '.' . $pattern;
	}

	/**
	 * Check if the referrer is from the same domain.
	 *
	 * @return bool
	 */
	private function is_internal_referral() {
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( empty( $referrer ) ) {
			return false;
		}

		return self::is_internal_host( wp_parse_url( $referrer, PHP_URL_HOST ) );
	}

	private function get_sanitized_utm_params() {
		$keys = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_site_id' );

		$params = array();
		foreach ( $keys as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$value          = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
			$params[ $key ] = mb_substr( $value, 0, 191 );
		}

		// Matches the site_id varchar(64) column; longer values would truncate into UNIQUE collisions.
		$params['utm_site_id'] = mb_substr( $params['utm_site_id'], 0, 64 );

		return $params;
	}

	private function store_visit( $params ) {
		global $wpdb;

		$site_id = $params['utm_site_id'];
		$ip_hash = utm_attribution_get_ip_hash();

		if ( ! empty( $site_id ) ) {
			$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT id, ip_hash FROM {$wpdb->prefix}utm_attribution_visits WHERE site_id = %s",
					$site_id
				)
			);

			if ( $existing ) {
				// Only the visitor who created the row may resume it. Anyone else replaying or
				// pre-claiming the id gets their own visit, without the site_id.
				// ponytail: with IP hashing disabled there is nothing to bind to, so dedupe wins over takeover protection.
				if ( null === $ip_hash || hash_equals( (string) $existing->ip_hash, $ip_hash ) ) {
					return absint( $existing->id );
				}
				$site_id = '';
			}
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$data = array(
			'site_id'      => ! empty( $site_id ) ? $site_id : null,
			'utm_source'   => $params['utm_source'],
			'utm_medium'   => $params['utm_medium'],
			'utm_campaign' => $params['utm_campaign'],
			'utm_term'     => $params['utm_term'],
			'utm_content'  => $params['utm_content'],
			'landing_url'  => substr( esc_url_raw( $this->strip_landing_query( $request_uri ) ), 0, 500 ),
			'referrer'     => isset( $_SERVER['HTTP_REFERER'] ) ? substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 500 ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'ip_hash'      => $ip_hash,
			'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '',
			'user_id'      => get_current_user_id() ?: null,
			'visited_at'   => current_time( 'mysql', true ),
		);

		$format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

		$wpdb->insert( "{$wpdb->prefix}utm_attribution_visits", $data, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( $wpdb->insert_id ) {
			return $wpdb->insert_id;
		}

		// Fallback for site_id race condition on concurrent requests.
		if ( ! empty( $site_id ) ) {
			$existing_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}utm_attribution_visits WHERE site_id = %s",
					$site_id
				)
			);
			return absint( $existing_id );
		}

		return false;
	}

	/**
	 * Keep the path and utm_* keys only; other query args can carry order keys, tokens or emails.
	 *
	 * @param string $uri Raw request URI.
	 * @return string
	 */
	private function strip_landing_query( $uri ) {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$args = array();
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $args );

		$keep = array_intersect_key( $args, array_flip( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_site_id' ) ) );

		return $keep ? $path . '?' . http_build_query( $keep ) : $path;
	}
}

new Utm_Attribution_Capture();
