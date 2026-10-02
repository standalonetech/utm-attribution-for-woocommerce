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
		// Deferred to init so filters added by themes and later-loading plugins apply.
		add_action( 'init', array( $this, 'register_status_hooks' ) );

		// Checkout runs in the customer's request, where the cookie exists. Status changes from
		// gateway webhooks, admin or cron do not, so the visit is pinned to the order here.
		add_action( 'woocommerce_checkout_order_created', array( $this, 'store_order_visit' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'store_order_visit' ) );

		// Keep status and net total current so refunds and cancellations leave the reports.
		add_action( 'woocommerce_order_status_changed', array( $this, 'sync_conversion' ) );
		add_action( 'woocommerce_order_refunded', array( $this, 'sync_conversion' ) );
	}

	public function register_status_hooks() {
		$statuses = apply_filters( 'utm_attribution_conversion_order_statuses', array( 'processing', 'completed' ) );
		foreach ( (array) $statuses as $status ) {
			add_action( "woocommerce_order_status_{$status}", array( $this, 'record_conversion' ), 10, 2 );
		}
	}

	/**
	 * @param int $order_id
	 */
	public function sync_conversion( $order_id ) {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}utm_attribution_conversions SET status = %s, order_total = %f WHERE order_id = %d",
				$order->get_status(),
				(float) $order->get_total() - (float) $order->get_total_refunded(),
				absint( $order_id )
			)
		);
	}

	/**
	 * @param WC_Order $order
	 */
	public function store_order_visit( $order ) {
		$visit_id = utm_attribution_get_visit_id();
		if ( ! $visit_id || ! $order instanceof WC_Order ) {
			return;
		}

		$order->update_meta_data( '_utm_attribution_visit_id', $visit_id );
		$order->save();
	}

	/**
	 * @param int      $order_id
	 * @param WC_Order $order
	 */
	public function record_conversion( $order_id, $order = null ) {
		global $wpdb;

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}
		}

		// Order meta first: in admin/webhook requests the cookie is absent or belongs to someone else.
		$visit_id = absint( $order->get_meta( '_utm_attribution_visit_id' ) );

		if ( ! $visit_id ) {
			$visit_id = utm_attribution_get_visit_id();
		}

		if ( ! $visit_id && apply_filters( 'utm_attribution_enable_user_stitching', (bool) utm_attribution_get_settings( 'user_stitching' ) ) ) {
			$visit_id = $this->stitch_visit( $order );
		}

		if ( ! $visit_id ) {
			return;
		}

		$product_ids = array();
		foreach ( $order->get_items() as $item ) {
			$product_ids[] = $item->get_product_id();
		}

		// Fits varchar(500) by dropping whole trailing ids, never cutting one in half.
		$product_ids = implode( ',', array_unique( $product_ids ) );
		if ( strlen( $product_ids ) > 500 ) {
			$product_ids = substr( $product_ids, 0, (int) strrpos( substr( $product_ids, 0, 501 ), ',' ) );
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
				$product_ids,
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
			// Only visits inside the cookie window before the order was placed can claim it.
			$created = $order->get_date_created();
			$placed  = $created ? $created->getTimestamp() : time();
			$days    = (int) apply_filters( 'utm_attribution_cookie_lifetime_days', (int) utm_attribution_get_settings( 'attribution_window_days' ) );

			$visit_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}utm_attribution_visits WHERE user_id = %d AND visited_at BETWEEN %s AND %s ORDER BY visited_at DESC LIMIT 1",
					$user_id,
					gmdate( 'Y-m-d H:i:s', $placed - DAY_IN_SECONDS * $days ),
					gmdate( 'Y-m-d H:i:s', $placed )
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
