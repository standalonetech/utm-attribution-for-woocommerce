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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UTM capture from URL params.
		if ( empty( $_GET['utm_source'] ) ) {
			return;
		}

		$params   = $this->get_sanitized_params();
		$visit_id = $this->store_visit( $params );

		if ( $visit_id ) {
			utm_attribution_set_visit_cookie( $visit_id );
		}
	}

	private function get_sanitized_params() {
		$keys = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_site_id' );

		$params = array();
		foreach ( $keys as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UTM params from URL.
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
