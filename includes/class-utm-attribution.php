<?php
/**
 * Main Utm_Attribution Class.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin bootstrap.
 */
final class Utm_Attribution {

	/**
	 * @var string
	 */
	public $version = '1.3.2';

	/**
	 * @var Utm_Attribution
	 */
	protected static $_instance = null;

	/**
	 * @return Utm_Attribution
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		$this->define_constants();
		$this->includes();
		$this->init_hooks();

		do_action( 'utm_attribution_loaded' );
	}

	private function define_constants() {
		$this->define( 'UTM_ATTRIBUTION_ABSPATH', dirname( UTM_ATTRIBUTION_PLUGIN_FILE ) . '/' );
		$this->define( 'UTM_ATTRIBUTION_BASENAME', plugin_basename( UTM_ATTRIBUTION_PLUGIN_FILE ) );
		$this->define( 'UTM_ATTRIBUTION_URL', untrailingslashit( plugins_url( '/', UTM_ATTRIBUTION_PLUGIN_FILE ) ) );
		$this->define( 'UTM_ATTRIBUTION_VERSION', $this->version );
	}

	/**
	 * @param string $const_name  Constant name (always UTM_ATTRIBUTION_* prefixed).
	 * @param mixed  $value Constant value.
	 */
	private function define( $const_name, $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound
		if ( ! defined( $const_name ) ) {
			define( $const_name, $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound
		}
	}

	public function includes() {
		include_once UTM_ATTRIBUTION_ABSPATH . 'includes/helpers/utm-attribution-functions.php';
		include_once UTM_ATTRIBUTION_ABSPATH . 'includes/class-utm-attribution-install.php';
		include_once UTM_ATTRIBUTION_ABSPATH . 'includes/class-utm-attribution-capture.php';
		include_once UTM_ATTRIBUTION_ABSPATH . 'includes/class-utm-attribution-conversion.php';
		include_once UTM_ATTRIBUTION_ABSPATH . 'includes/class-utm-attribution-reports.php';
		include_once UTM_ATTRIBUTION_ABSPATH . 'includes/class-utm-attribution-export.php';
		include_once UTM_ATTRIBUTION_ABSPATH . 'includes/class-utm-attribution-privacy.php';

		if ( is_admin() ) {
			include_once UTM_ATTRIBUTION_ABSPATH . 'includes/class-utm-attribution-admin.php';
			include_once UTM_ATTRIBUTION_ABSPATH . 'includes/class-utm-attribution-visits-list-table.php';
			include_once UTM_ATTRIBUTION_ABSPATH . 'includes/class-utm-attribution-conversions-list-table.php';
			include_once UTM_ATTRIBUTION_ABSPATH . 'includes/class-utm-attribution-settings.php';
		}
	}

	private function init_hooks() {
		register_activation_hook( UTM_ATTRIBUTION_PLUGIN_FILE, array( 'Utm_Attribution_Install', 'install' ) );
		register_deactivation_hook( UTM_ATTRIBUTION_PLUGIN_FILE, array( 'Utm_Attribution_Privacy', 'unschedule' ) );
		add_action( 'init', array( $this, 'init' ), 0 );
		add_action( 'before_woocommerce_init', array( $this, 'declare_wc_compatibility' ) );
	}

	public function init() {
		// Translations are auto-loaded by WordPress.org since WP 4.6.
		Utm_Attribution_Install::maybe_upgrade();
	}

	/**
	 * Orders are read only through WC_Order and OrderUtil, so HPOS is supported.
	 */
	public function declare_wc_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', UTM_ATTRIBUTION_PLUGIN_FILE, true );
		}
	}
}
