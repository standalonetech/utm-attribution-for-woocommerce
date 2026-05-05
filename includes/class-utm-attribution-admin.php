<?php
/**
 * Admin interface.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers admin menu pages and enqueues admin assets.
 */
class Utm_Attribution_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
	}

	public function admin_menu() {
		$capability = apply_filters( 'utm_attribution_user_capability', 'manage_options' );

		add_menu_page(
			__( 'UTM Attribution', 'utm-attribution-for-woocommerce' ),
			__( 'UTM Attribution', 'utm-attribution-for-woocommerce' ),
			$capability,
			'utm-attribution-for-woocommerce',
			array( $this, 'dashboard_page' ),
			'dashicons-chart-line',
			58
		);

		add_submenu_page(
			'utm-attribution-for-woocommerce',
			__( 'Dashboard', 'utm-attribution-for-woocommerce' ),
			__( 'Dashboard', 'utm-attribution-for-woocommerce' ),
			$capability,
			'utm-attribution-for-woocommerce',
			array( $this, 'dashboard_page' )
		);

		add_submenu_page(
			'utm-attribution-for-woocommerce',
			__( 'Visits', 'utm-attribution-for-woocommerce' ),
			__( 'Visits', 'utm-attribution-for-woocommerce' ),
			$capability,
			'utm-attribution-visits',
			array( $this, 'visits_page' )
		);

		add_submenu_page(
			'utm-attribution-for-woocommerce',
			__( 'Conversions', 'utm-attribution-for-woocommerce' ),
			__( 'Conversions', 'utm-attribution-for-woocommerce' ),
			$capability,
			'utm-attribution-conversions',
			array( $this, 'conversions_page' )
		);
	}

	public function admin_assets( $hook ) {
		if ( strpos( $hook, 'utm-attribution' ) === false ) {
			return;
		}

		wp_enqueue_style( 'utm-attribution-admin', UTM_ATTRIBUTION_URL . '/assets/css/admin.css', array(), UTM_ATTRIBUTION_VERSION );

		// Chart.js is bundled locally — no external CDN.
		wp_enqueue_script( 'chartjs', UTM_ATTRIBUTION_URL . '/assets/js/chart.min.js', array(), '4.5.1', true );
		wp_enqueue_script( 'utm-attribution-admin', UTM_ATTRIBUTION_URL . '/assets/js/admin.js', array( 'chartjs', 'jquery' ), UTM_ATTRIBUTION_VERSION, true );

		if ( 'toplevel_page_utm-attribution-for-woocommerce' === $hook ) {
			list( $from, $to, $range ) = $this->resolve_date_range();
			$granularity = $this->auto_granularity( $from, $to );
			$series      = Utm_Attribution_Reports::get_time_series( $granularity, $from, $to );

			wp_localize_script( 'utm-attribution-admin', 'utm_attribution_data', array(
				'series'      => $series,
				'range'       => $range,
				'granularity' => $granularity,
			) );
		}
	}

	/**
	 * Resolve the active from/to dates and the matching preset label.
	 * Priority: explicit from/to params → range preset → default 30d.
	 *
	 * @return array [ $from, $to, $range ]
	 */
	private function resolve_date_range() {
		$today = current_time( 'Y-m-d' );

		// Read-only display filter (no state changes), so nonce verification is not required.
		$from_raw  = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to_raw    = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$range_raw = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $from_raw && '' !== $to_raw ) {
			$from  = $from_raw;
			$to    = $to_raw;
			$range = '' !== $range_raw ? $range_raw : 'custom';

			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
				list( $from, $to ) = $this->preset_to_dates( '30d', $today );
				$range             = '30d';
			}

			if ( $from > $to ) {
				list( $from, $to ) = array( $to, $from );
			}

			return array( $from, $to, $range );
		}

		$range             = '' !== $range_raw ? $range_raw : '30d';
		list( $from, $to ) = $this->preset_to_dates( $range, $today );

		return array( $from, $to, $range );
	}

	private function preset_to_dates( $preset, $today ) {
		switch ( $preset ) {
			case 'today':
				return array( $today, $today );
			case '7d':
				return array( gmdate( 'Y-m-d', strtotime( '-6 days' ) ), $today );
			case '90d':
				return array( gmdate( 'Y-m-d', strtotime( '-89 days' ) ), $today );
			case 'year':
				return array( gmdate( 'Y-01-01' ), $today );
			case '30d':
			default:
				return array( gmdate( 'Y-m-d', strtotime( '-29 days' ) ), $today );
		}
	}

	private function auto_granularity( $from, $to ) {
		$days = ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS;
		if ( $days > 60 ) {
			return 'month';
		}
		return 'day';
	}

	public function dashboard_page() {
		list( $from, $to, $range ) = $this->resolve_date_range();

		$kpis          = Utm_Attribution_Reports::get_kpis( $from, $to );
		$top_campaigns = Utm_Attribution_Reports::get_top_metrics( 'utm_campaign', $from, $to );

		include UTM_ATTRIBUTION_ABSPATH . 'includes/admin/views/dashboard.php';
	}

	public function visits_page() {
		$table = new Utm_Attribution_Visits_List_Table();
		$table->prepare_items();

		echo '<div class="wrap utm-attribution-dashboard"><h2>' . esc_html__( 'Visits', 'utm-attribution-for-woocommerce' ) . '</h2>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="utm-attribution-visits" />';
		$table->display();
		echo '</form></div>';
	}

	public function conversions_page() {
		$table = new Utm_Attribution_Conversions_List_Table();
		$table->prepare_items();

		echo '<div class="wrap utm-attribution-dashboard"><h2>' . esc_html__( 'Conversions', 'utm-attribution-for-woocommerce' ) . '</h2>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="utm-attribution-conversions" />';
		$table->display();
		echo '</form></div>';
	}
}

new Utm_Attribution_Admin();
