<?php
/**
 * Visits list table.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for the Visits admin page.
 */
class Utm_Attribution_Visits_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( array(
			'singular' => 'visit',
			'plural'   => 'visits',
			'ajax'     => false,
		) );
	}

	public function get_columns() {
		return array(
			'id'           => __( 'ID', 'utm-attribution-for-woocommerce' ),
			'site_id'      => __( 'Site ID', 'utm-attribution-for-woocommerce' ),
			'utm_source'   => __( 'Source', 'utm-attribution-for-woocommerce' ),
			'utm_medium'   => __( 'Medium', 'utm-attribution-for-woocommerce' ),
			'utm_campaign' => __( 'Campaign', 'utm-attribution-for-woocommerce' ),
			'landing_url'  => __( 'Landing URL', 'utm-attribution-for-woocommerce' ),
			'visited_at'   => __( 'Visited At', 'utm-attribution-for-woocommerce' ),
		);
	}

	public function prepare_items() {
		global $wpdb;

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// Optional ?id= filter, used by the Visit ID links on the Conversions screen. Read-only view, no nonce needed.
		$visit_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$total_items = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(id) FROM {$wpdb->prefix}utm_attribution_visits WHERE ( %d = 0 OR id = %d )", $visit_id, $visit_id )
		);

		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}utm_attribution_visits WHERE ( %d = 0 OR id = %d ) ORDER BY visited_at DESC LIMIT %d OFFSET %d",
				$visit_id,
				$visit_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->set_pagination_args( array(
			'total_items' => $total_items,
			'per_page'    => $per_page,
		) );
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'landing_url':
				return '<code>' . esc_html( $item[ $column_name ] ) . '</code>';
			case 'site_id':
				return '<code>' . esc_html( $item[ $column_name ] ?: '-' ) . '</code>';
			case 'visited_at':
				return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item[ $column_name ] . ' UTC' ) ) );
			default:
				return esc_html( $item[ $column_name ] );
		}
	}
}
