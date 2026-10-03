<?php
/**
 * Settings page.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the UTM Attribution → Settings page and sanitizes the utm_attribution_settings option.
 */
class Utm_Attribution_Settings {

	const OPTION = 'utm_attribution_settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_filter( 'option_page_capability_' . self::OPTION, array( $this, 'capability' ) );
	}

	/**
	 * @return string
	 */
	public function capability() {
		return apply_filters( 'utm_attribution_user_capability', 'manage_options' );
	}

	public function admin_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_submenu_page(
			'utm-attribution-for-woocommerce',
			__( 'Settings', 'utm-attribution-for-woocommerce' ),
			__( 'Settings', 'utm-attribution-for-woocommerce' ),
			$this->capability(),
			'utm-attribution-settings',
			array( $this, 'settings_page' )
		);
	}

	public function register() {
		register_setting(
			self::OPTION,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * General / Tools tab links.
	 *
	 * @param string $active 'general' or 'tools'.
	 */
	public static function render_tabs( $active ) {
		$base = admin_url( 'admin.php?page=utm-attribution-settings' );
		?>
		<nav class="nav-tab-wrapper">
			<a href="<?php echo esc_url( $base ); ?>" class="nav-tab <?php echo 'general' === $active ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'General', 'utm-attribution-for-woocommerce' ); ?></a>
			<a href="<?php echo esc_url( $base . '&tab=tools' ); ?>" class="nav-tab <?php echo 'tools' === $active ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Tools', 'utm-attribution-for-woocommerce' ); ?></a>
		</nav>
		<?php
	}

	public function settings_page() {
		// Tab switch is a read-only display choice, no nonce needed.
		if ( isset( $_GET['tab'] ) && 'tools' === $_GET['tab'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tools = Utm_Attribution_Cleanup::view_data();
			include UTM_ATTRIBUTION_ABSPATH . 'includes/admin/views/tools.php';
			return;
		}

		$settings  = utm_attribution_get_settings();
		$overrides = array(
			'attribution_window_days' => has_filter( 'utm_attribution_cookie_lifetime_days' ),
			'user_stitching'          => has_filter( 'utm_attribution_enable_user_stitching' ),
			'ip_hashing'              => has_filter( 'utm_attribution_enable_ip_hashing' ),
			'pii_retention_days'      => has_filter( 'utm_attribution_pii_retention_days' ),
		);
		$merge_inactive = $settings['merge_repeat_visits'] && null === utm_attribution_get_ip_hash();

		include UTM_ATTRIBUTION_ABSPATH . 'includes/admin/views/settings.php';
	}

	/**
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$out     = array();
		$dropped = 0;

		foreach ( array( 'exclude_bots', 'exclude_non_page', 'ignore_prefetch', 'merge_repeat_visits', 'exclude_staff', 'user_stitching', 'ip_hashing' ) as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$out['attribution_window_days'] = min( 365, max( 1, (int) ( isset( $input['attribution_window_days'] ) ? $input['attribution_window_days'] : 30 ) ) );
		$out['pii_retention_days']      = min( 3650, max( 0, (int) ( isset( $input['pii_retention_days'] ) ? $input['pii_retention_days'] : 365 ) ) );

		// A pattern that matches the saving admin's own browser would hide real visitors, so it is refused.
		$own_ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';

		$out['extra_bot_patterns'] = array();
		foreach ( self::lines( $input, 'extra_bot_patterns' ) as $line ) {
			$line = strtolower( $line );
			if ( strlen( $line ) < 4 || strlen( $line ) > 100 || count( $out['extra_bot_patterns'] ) >= 50 || ( '' !== $own_ua && false !== strpos( $own_ua, $line ) ) ) {
				++$dropped;
				continue;
			}
			$out['extra_bot_patterns'][] = $line;
		}

		// Paths match the start of the URL path, so one that is a prefix of the home page path would hide the whole site.
		$home_path = trailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );

		$out['extra_noise_paths'] = array();
		foreach ( self::lines( $input, 'extra_noise_paths' ) as $line ) {
			$line = (string) strtok( $line, '?' );
			if ( '/' !== substr( $line, 0, 1 ) || strlen( $line ) < 4 || strlen( $line ) > 200 || count( $out['extra_noise_paths'] ) >= 50 || 0 === strpos( $home_path, trailingslashit( $line ) ) ) {
				++$dropped;
				continue;
			}
			$out['extra_noise_paths'][] = $line;
		}

		$out['internal_domains'] = array();
		foreach ( self::lines( $input, 'internal_domains' ) as $line ) {
			$host = false !== strpos( $line, '://' ) ? (string) wp_parse_url( $line, PHP_URL_HOST ) : (string) strtok( $line, '/' );
			$host = preg_replace( '/^(\*\.|www\.)+/', '', strtolower( $host ) );
			if ( strlen( $host ) > 253 || ! preg_match( '/^([a-z0-9-]+\.)+[a-z0-9-]{2,}$/', $host ) || count( $out['internal_domains'] ) >= 20 ) {
				++$dropped;
				continue;
			}
			$out['internal_domains'][] = $host;
		}

		foreach ( array( 'extra_bot_patterns', 'extra_noise_paths', 'internal_domains' ) as $key ) {
			$out[ $key ] = array_values( array_unique( $out[ $key ] ) );
		}

		// WordPress runs this callback twice when the option is first created; report once.
		if ( $dropped && ! get_settings_errors( self::OPTION ) ) {
			add_settings_error(
				self::OPTION,
				'utm-attribution-dropped',
				sprintf(
					/* translators: %d: number of ignored lines. */
					_n( '%d line was ignored because it was too short, too long, invalid, over the limit or would match your own browser or home page.', '%d lines were ignored because they were too short, too long, invalid, over the limit or would match your own browser or home page.', $dropped, 'utm-attribution-for-woocommerce' ),
					$dropped
				),
				'warning'
			);
		}

		return $out;
	}

	/**
	 * Non-empty, sanitized lines of a textarea (or an already-saved array).
	 *
	 * @param array  $input Submitted values.
	 * @param string $key   Field key.
	 * @return string[]
	 */
	private static function lines( $input, $key ) {
		$value = isset( $input[ $key ] ) ? $input[ $key ] : array();
		$lines = is_array( $value ) ? $value : preg_split( '/\R/', (string) $value );

		return array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $lines ) ), 'strlen' );
	}
}

new Utm_Attribution_Settings();
