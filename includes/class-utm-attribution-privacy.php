<?php
/**
 * Personal data: WordPress privacy exporter/eraser and PII retention.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes visit data to the WordPress privacy tools and strips PII from old visits.
 *
 * Erasure and retention anonymize rows (user_id, ip_hash, user_agent, referrer) instead of
 * deleting them, so campaign totals and order attribution stay intact.
 */
class Utm_Attribution_Privacy {

	const CRON_HOOK = 'utm_attribution_purge_pii';

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( self::CRON_HOOK, array( $this, 'purge_pii' ) );
		add_action( 'init', array( $this, 'schedule' ) );
	}

	public function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Anonymize visits older than the retention window.
	 */
	public function purge_pii() {
		global $wpdb;

		/**
		 * Filters how many days visitor PII (IP hash, user agent, referrer, user ID) is kept.
		 * Return 0 to keep it indefinitely.
		 *
		 * @param int $days Default 365.
		 */
		$days = (int) apply_filters( 'utm_attribution_pii_retention_days', 365 );
		if ( $days <= 0 ) {
			return;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}utm_attribution_visits
				SET user_id = NULL, ip_hash = NULL, user_agent = NULL, referrer = NULL
				WHERE visited_at < %s AND ( user_id IS NOT NULL OR ip_hash IS NOT NULL OR user_agent IS NOT NULL OR referrer IS NOT NULL )",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * $days )
			)
		);
	}

	/**
	 * @param array $exporters
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['utm-attribution-for-woocommerce'] = array(
			'exporter_friendly_name' => __( 'UTM Attribution Visits', 'utm-attribution-for-woocommerce' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array $erasers
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['utm-attribution-for-woocommerce'] = array(
			'eraser_friendly_name' => __( 'UTM Attribution Visits', 'utm-attribution-for-woocommerce' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @param string $email
	 * @param int    $page
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		global $wpdb;

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}

		$per_page = 100;
		$rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, utm_source, utm_medium, utm_campaign, landing_url, referrer, user_agent, visited_at
				FROM {$wpdb->prefix}utm_attribution_visits WHERE user_id = %d ORDER BY id LIMIT %d OFFSET %d",
				$user->ID,
				$per_page,
				( max( 1, (int) $page ) - 1 ) * $per_page
			),
			ARRAY_A
		);

		$labels = array(
			'utm_source'   => __( 'Source', 'utm-attribution-for-woocommerce' ),
			'utm_medium'   => __( 'Medium', 'utm-attribution-for-woocommerce' ),
			'utm_campaign' => __( 'Campaign', 'utm-attribution-for-woocommerce' ),
			'landing_url'  => __( 'Landing URL', 'utm-attribution-for-woocommerce' ),
			'referrer'     => __( 'Referrer', 'utm-attribution-for-woocommerce' ),
			'user_agent'   => __( 'User Agent', 'utm-attribution-for-woocommerce' ),
			'visited_at'   => __( 'Visited At (UTC)', 'utm-attribution-for-woocommerce' ),
		);

		$data = array();
		foreach ( $rows as $row ) {
			$item = array();
			foreach ( $labels as $key => $name ) {
				$item[] = array( 'name' => $name, 'value' => (string) $row[ $key ] );
			}
			$data[] = array(
				'group_id'    => 'utm-attribution-visits',
				'group_label' => __( 'Marketing Visits', 'utm-attribution-for-woocommerce' ),
				'item_id'     => 'utm-visit-' . $row['id'],
				'data'        => $item,
			);
		}

		return array( 'data' => $data, 'done' => count( $rows ) < $per_page );
	}

	/**
	 * @param string $email
	 * @return array
	 */
	public function erase( $email ) {
		global $wpdb;

		$user    = get_user_by( 'email', $email );
		$updated = 0;
		if ( $user ) {
			$updated = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}utm_attribution_visits SET user_id = NULL, ip_hash = NULL, user_agent = NULL, referrer = NULL WHERE user_id = %d",
					$user->ID
				)
			);
		}

		return array(
			'items_removed'  => $updated > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}

new Utm_Attribution_Privacy();
