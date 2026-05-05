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
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
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
		$host = preg_replace( '/^www\./', '', strtolower( $host ) );

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
			if ( false !== strpos( $host, $pattern ) ) {
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
			if ( false !== strpos( $host, $pattern ) ) {
				$params['utm_source'] = $source;
				$params['utm_medium'] = 'social';
				break;
			}
		}

		return $params;
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

		$ref_host  = wp_parse_url( $referrer, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		return $ref_host === $site_host;
	}

	private function get_sanitized_utm_params() {
		$keys = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_site_id' );

		$params = array();
		foreach ( $keys as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$value          = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
			$params[ $key ] = substr( $value, 0, 191 );
		}

		return $params;
	}

	private function store_visit( $params ) {
		global $wpdb;

		$site_id = $params['utm_site_id'];

		if ( ! empty( $site_id ) ) {
			$existing_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}utm_attribution_visits WHERE site_id = %s",
					$site_id
				)
			);

			if ( $existing_id ) {
				return absint( $existing_id );
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
			'landing_url'  => substr( esc_url_raw( $request_uri ), 0, 500 ),
			'referrer'     => isset( $_SERVER['HTTP_REFERER'] ) ? substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 500 ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'ip_hash'      => utm_attribution_get_ip_hash(),
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
}

new Utm_Attribution_Capture();
