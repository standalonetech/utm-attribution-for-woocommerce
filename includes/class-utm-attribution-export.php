<?php
/**
 * CSV export handler.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles CSV export requests for visits, conversions, and campaigns data.
 */
class Utm_Attribution_Export {

	public function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_export' ) );
	}

	/**
	 * Intercept CSV export requests.
	 */
	public function maybe_export() {
		if ( ! isset( $_GET['utm-export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'utm_attribution_export' ) ) {
			wp_die( esc_html__( 'Invalid export request.', 'utm-attribution-for-woocommerce' ), 403 );
		}

		$capability = apply_filters( 'utm_attribution_user_capability', 'manage_options' );
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have permission to export data.', 'utm-attribution-for-woocommerce' ), 403 );
		}

		$type = sanitize_text_field( wp_unslash( $_GET['utm-export'] ) );

		switch ( $type ) {
			case 'visits':
				$this->export_visits();
				break;
			case 'conversions':
				$this->export_conversions();
				break;
			case 'campaigns':
				$this->export_campaigns();
				break;
			default:
				wp_die( esc_html__( 'Invalid export type.', 'utm-attribution-for-woocommerce' ), 400 );
		}
	}

	/**
	 * Export all visits as CSV.
	 */
	private function export_visits() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, site_id, utm_source, utm_medium, utm_campaign, landing_url, visited_at
			 FROM {$wpdb->prefix}utm_attribution_visits
			 ORDER BY visited_at DESC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$headers = array(
			__( 'ID', 'utm-attribution-for-woocommerce' ),
			__( 'Site ID', 'utm-attribution-for-woocommerce' ),
			__( 'Source', 'utm-attribution-for-woocommerce' ),
			__( 'Medium', 'utm-attribution-for-woocommerce' ),
			__( 'Campaign', 'utm-attribution-for-woocommerce' ),
			__( 'Landing URL', 'utm-attribution-for-woocommerce' ),
			__( 'Visited At', 'utm-attribution-for-woocommerce' ),
		);

		$csv_rows = array();
		foreach ( $rows as $row ) {
			$csv_rows[] = array(
				$row['id'],
				$row['site_id'] ?? '',
				$row['utm_source'] ?? '',
				$row['utm_medium'] ?? '',
				$row['utm_campaign'] ?? '',
				$row['landing_url'] ?? '',
				$this->format_date( $row['visited_at'] ),
			);
		}

		$this->output_csv( 'utm-visits', $headers, $csv_rows );
	}

	/**
	 * Export all conversions as CSV.
	 */
	private function export_conversions() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, visit_id, order_id, order_total, currency, converted_at
			 FROM {$wpdb->prefix}utm_attribution_conversions
			 ORDER BY converted_at DESC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$headers = array(
			__( 'ID', 'utm-attribution-for-woocommerce' ),
			__( 'Visit ID', 'utm-attribution-for-woocommerce' ),
			__( 'Order ID', 'utm-attribution-for-woocommerce' ),
			__( 'Total', 'utm-attribution-for-woocommerce' ),
			__( 'Currency', 'utm-attribution-for-woocommerce' ),
			__( 'Converted At', 'utm-attribution-for-woocommerce' ),
		);

		$csv_rows = array();
		foreach ( $rows as $row ) {
			$csv_rows[] = array(
				$row['id'],
				$row['visit_id'],
				$row['order_id'],
				$row['order_total'],
				$row['currency'] ?? '',
				$this->format_date( $row['converted_at'] ),
			);
		}

		$this->output_csv( 'utm-conversions', $headers, $csv_rows );
	}

	/**
	 * Export top campaigns as CSV, respecting date range filters.
	 */
	private function export_campaigns() {
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';     // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$today = current_time( 'Y-m-d' );
			$from  = gmdate( 'Y-m-d', strtotime( '-29 days' ) );
			$to    = $today;
		}

		$rows = Utm_Attribution_Reports::get_top_metrics( 'utm_campaign', $from, $to, 9999 );

		$headers = array(
			__( 'Campaign Name', 'utm-attribution-for-woocommerce' ),
			__( 'Visits', 'utm-attribution-for-woocommerce' ),
			__( 'Conversions', 'utm-attribution-for-woocommerce' ),
			__( 'Conv. Rate', 'utm-attribution-for-woocommerce' ),
			__( 'Revenue', 'utm-attribution-for-woocommerce' ),
		);

		$csv_rows = array();
		foreach ( $rows as $row ) {
			$conv_rate = $row['visits'] > 0 ? round( ( $row['conversions'] / $row['visits'] ) * 100, 2 ) : 0;
			$csv_rows[] = array(
				$row['label'] ?: __( '(Direct / None)', 'utm-attribution-for-woocommerce' ),
				$row['visits'],
				$row['conversions'],
				$conv_rate . '%',
				$row['revenue'],
			);
		}

		$this->output_csv( 'utm-campaigns', $headers, $csv_rows );
	}

	/**
	 * Format a datetime string using WordPress date/time settings.
	 *
	 * @param string $datetime MySQL datetime string.
	 * @return string
	 */
	private function format_date( $datetime ) {
		if ( empty( $datetime ) ) {
			return '';
		}
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $datetime ) );
	}

	/**
	 * Output a CSV file download.
	 *
	 * @param string $filename Base filename (without extension).
	 * @param array  $headers  Column header labels.
	 * @param array  $rows     Array of arrays, each inner array is a row.
	 */
	private function output_csv( $filename, $headers, $rows ) {
		$timestamp = gmdate( 'Y-m-d' );
		$full_name = $filename . '-' . $timestamp . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $full_name );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		// UTF-8 BOM for Excel compatibility.
		fwrite( $output, "\xEF\xBB\xBF" );

		fputcsv( $output, $headers );

		foreach ( $rows as $row ) {
			fputcsv( $output, $row );
		}

		fclose( $output );
		exit;
	}
}

new Utm_Attribution_Export();
