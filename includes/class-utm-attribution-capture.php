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

		// ponytail: fixed 30-minute window (GA's session default); make it a filter if anyone asks.
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
				gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS )
			)
		);
	}

	/**
	 * Requests that are not a person landing on a page: bots, non-GET, wc-ajax, 404s, feeds.
	 *
	 * @return bool
	 */
	private function is_noise_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$noise = 'GET' !== $method || isset( $_GET['wc-ajax'] ) || is_404() || is_feed() || is_robots() || is_trackback()
			|| '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|preview|headless|lighthouse|curl|wget|python-|go-http|facebookexternalhit/i', $ua );

		/**
		 * Filters whether the current request is skipped by visit capture.
		 *
		 * @param bool   $noise True to skip recording a visit.
		 * @param string $ua    Sanitized User-Agent.
		 */
		return (bool) apply_filters( 'utm_attribution_skip_capture', $noise, $ua );
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
			if ( $this->host_matches( $host, $pattern ) ) {
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
			if ( $this->host_matches( $host, $pattern ) ) {
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
	private function host_matches( $host, $pattern ) {
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

		$ref_host  = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) ) );
		$site_host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );

		return $ref_host === $site_host;
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
