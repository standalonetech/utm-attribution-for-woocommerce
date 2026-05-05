<?php
/**
 * Conversions list table.
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
 * WP_List_Table for the Conversions admin page.
 */
class Utm_Attribution_Conversions_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( array(
			'singular' => 'conversion',
			'plural'   => 'conversions',
			'ajax'     => false,
		) );
	}

	public function get_columns() {
		return array(
			'id'           => __( 'ID', 'utm-attribution-for-woocommerce' ),
			'visit_id'     => __( 'Visit ID', 'utm-attribution-for-woocommerce' ),
			'order_id'     => __( 'Order ID', 'utm-attribution-for-woocommerce' ),
			'order_total'  => __( 'Total', 'utm-attribution-for-woocommerce' ),
			'converted_at' => __( 'Converted At', 'utm-attribution-for-woocommerce' ),
		);
	}

	public function prepare_items() {
		global $wpdb;

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_items = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$wpdb->prefix}utm_attribution_conversions" );

		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}utm_attribution_conversions ORDER BY converted_at DESC LIMIT %d OFFSET %d",
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
			case 'visit_id':
				return '<a href="' . esc_url( admin_url( 'admin.php?page=utm-attribution-visits&id=' . absint( $item[ $column_name ] ) ) ) . '">' . esc_html( $item[ $column_name ] ) . '</a>';
			case 'order_id':
				return '<a href="' . esc_url( admin_url( 'post.php?post=' . absint( $item[ $column_name ] ) . '&action=edit' ) ) . '">' . esc_html( $item[ $column_name ] ) . '</a>';
			case 'order_total':
				return wp_kses_post( wc_price( $item[ $column_name ], array( 'currency' => $item['currency'] ) ) );
			case 'converted_at':
				return esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item[ $column_name ] ) ) );
			default:
				return esc_html( $item[ $column_name ] );
		}
	}
}
