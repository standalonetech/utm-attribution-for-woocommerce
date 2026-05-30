<?php
/**
 * Plugin Name: UTM Attribution for WooCommerce
 * Plugin URI: https://standalonetech.com/
 * Description: Captures UTM parameters from inbound URLs, attributes WooCommerce purchases, and shows conversion reports.
 * Version: 1.2.0
 * Author: StandaloneTech
 * Author URI: https://profiles.wordpress.org/standalonetech/
 * Text Domain: utm-attribution-for-woocommerce
 * Domain Path: /languages/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'UTM_ATTRIBUTION_PLUGIN_FILE' ) ) {
	define( 'UTM_ATTRIBUTION_PLUGIN_FILE', __FILE__ );
}

/**
 * Main instance of Utm_Attribution.
 */
function utm_attribution() {
	return Utm_Attribution::instance();
}

if ( ! class_exists( 'Utm_Attribution' ) ) {
	include_once __DIR__ . '/includes/class-utm-attribution.php';
}

$GLOBALS['utm_attribution'] = utm_attribution();
