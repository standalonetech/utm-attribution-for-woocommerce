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
	public $version = '1.2.0';

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

		if ( is_admin() ) {
			include_once UTM_ATTRIBUTION_ABSPATH . 'includes/class-utm-attribution-admin.php';
			include_once UTM_ATTRIBUTION_ABSPATH . 'includes/class-utm-attribution-visits-list-table.php';
			include_once UTM_ATTRIBUTION_ABSPATH . 'includes/class-utm-attribution-conversions-list-table.php';
		}
	}

	private function init_hooks() {
		register_activation_hook( UTM_ATTRIBUTION_PLUGIN_FILE, array( 'Utm_Attribution_Install', 'install' ) );
		add_action( 'init', array( $this, 'init' ), 0 );
	}

	public function init() {
		// Translations are auto-loaded by WordPress.org since WP 4.6.
	}
}
