<?php
/**
 * Record conversions from WooCommerce orders.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records WooCommerce orders as conversions.
 */
class Utm_Attribution_Conversion {

	public function __construct() {
		$statuses = apply_filters( 'utm_attribution_conversion_order_statuses', array( 'processing', 'completed' ) );
		foreach ( $statuses as $status ) {
			add_action( "woocommerce_order_status_{$status}", array( $this, 'record_conversion' ), 10, 2 );
		}
	}

	/**
	 * @param int      $order_id
	 * @param WC_Order $order
	 */
	public function record_conversion( $order_id, $order ) {
		global $wpdb;

		$visit_id = utm_attribution_get_visit_id();

		if ( ! $visit_id && apply_filters( 'utm_attribution_enable_user_stitching', false ) ) {
			$visit_id = $this->stitch_visit( $order );
		}

		if ( ! $visit_id ) {
			return;
		}

		$product_ids = array();
		foreach ( $order->get_items() as $item ) {
			$product_ids[] = $item->get_product_id();
		}

		// INSERT IGNORE prevents duplicate records when the order fires the hook multiple times.
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}utm_attribution_conversions
				(visit_id, order_id, order_total, currency, product_ids, status, converted_at)
				VALUES (%d, %d, %f, %s, %s, %s, %s)",
				absint( $visit_id ),
				absint( $order_id ),
				$order->get_total(),
				$order->get_currency(),
				implode( ',', $product_ids ),
				$order->get_status(),
				current_time( 'mysql', true )
			)
		);

		if ( $result ) {
			do_action( 'utm_attribution_conversion_recorded', $wpdb->insert_id, $visit_id, $order );
		}
	}

	/**
	 * @param WC_Order $order
	 * @return int|false
	 */
	private function stitch_visit( $order ) {
		global $wpdb;

		$user_id = $order->get_user_id();
		if ( $user_id ) {
			$visit_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}utm_attribution_visits WHERE user_id = %d ORDER BY visited_at DESC LIMIT 1",
					$user_id
				)
			);
			if ( $visit_id ) {
				return absint( $visit_id );
			}
		}

		return false;
	}
}

new Utm_Attribution_Conversion();
