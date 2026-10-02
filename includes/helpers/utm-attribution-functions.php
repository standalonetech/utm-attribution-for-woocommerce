<?php
/**
 * Utility functions for UTM Attribution.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'utm_attribution_get_settings' ) ) {
	/**
	 * Default values of the utm_attribution_settings option.
	 *
	 * @return array
	 */
	function utm_attribution_default_settings() {
		return array(
			'exclude_bots'            => 1,
			'extra_bot_patterns'      => array(),
			'exclude_non_page'        => 1,
			'extra_noise_paths'       => array(),
			'ignore_prefetch'         => 0,
			'merge_repeat_visits'     => 1,
			'exclude_staff'           => 1,
			'internal_domains'        => array(),
			'attribution_window_days' => 30,
			'user_stitching'          => 0,
			'ip_hashing'              => 1,
			'pii_retention_days'      => 365,
		);
	}

	/**
	 * Saved settings merged over the defaults. Code filters still override these values.
	 *
	 * @param string|null $key Optional single key.
	 * @return mixed
	 */
	function utm_attribution_get_settings( $key = null ) {
		$settings = get_option( 'utm_attribution_settings', array() );
		$settings = array_merge( utm_attribution_default_settings(), is_array( $settings ) ? $settings : array() );

		return null === $key ? $settings : ( isset( $settings[ $key ] ) ? $settings[ $key ] : null );
	}
}

if ( ! function_exists( 'utm_attribution_get_visit_id' ) ) {
	/**
	 * Get visit ID from the signed cookie.
	 *
	 * @return int|false
	 */
	function utm_attribution_get_visit_id() {
		if ( empty( $_COOKIE['utm_attribution_vid'] ) ) {
			return false;
		}

		$raw   = sanitize_text_field( wp_unslash( $_COOKIE['utm_attribution_vid'] ) );
		$parts = explode( '|', $raw );

		if ( 2 !== count( $parts ) ) {
			return false;
		}

		$id   = absint( $parts[0] );
		$hmac = $parts[1];

		if ( ! hash_equals( hash_hmac( 'sha256', $id, wp_salt( 'auth' ) ), $hmac ) ) {
			return false;
		}

		return $id;
	}
}

if ( ! function_exists( 'utm_attribution_set_visit_cookie' ) ) {
	/**
	 * Set the signed visit cookie.
	 *
	 * @param int $id Visit ID.
	 */
	function utm_attribution_set_visit_cookie( $id ) {
		$hmac  = hash_hmac( 'sha256', $id, wp_salt( 'auth' ) );
		$value = "{$id}|{$hmac}";
		$days  = (int) apply_filters( 'utm_attribution_cookie_lifetime_days', (int) utm_attribution_get_settings( 'attribution_window_days' ) );

		setcookie( 'utm_attribution_vid', $value, time() + ( DAY_IN_SECONDS * $days ), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
	}
}

if ( ! function_exists( 'utm_attribution_get_ip_hash' ) ) {
	/**
	 * Return a SHA-256 hash of the visitor's IP, or null when hashing is disabled.
	 *
	 * @return string|null
	 */
	function utm_attribution_get_ip_hash() {
		if ( ! apply_filters( 'utm_attribution_enable_ip_hashing', (bool) utm_attribution_get_settings( 'ip_hashing' ) ) ) {
			return null;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return hash( 'sha256', $ip . wp_salt() );
	}
}
