<?php
/**
 * Settings view.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template file, variables scoped from settings_page().

$utm_name = Utm_Attribution_Settings::OPTION;

$utm_checkbox = function ( $key, $label, $help = '' ) use ( $settings, $utm_name ) {
	?>
	<label>
		<input type="checkbox" name="<?php echo esc_attr( $utm_name . '[' . $key . ']' ); ?>" value="1" <?php checked( 1, (int) $settings[ $key ] ); ?> />
		<?php echo esc_html( $label ); ?>
	</label>
	<?php if ( $help ) : ?>
		<p class="description"><?php echo esc_html( $help ); ?></p>
	<?php endif; ?>
	<?php
};

$utm_textarea = function ( $key, $help ) use ( $settings, $utm_name ) {
	?>
	<textarea id="<?php echo esc_attr( 'utm-' . $key ); ?>" name="<?php echo esc_attr( $utm_name . '[' . $key . ']' ); ?>" rows="4" cols="50" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $settings[ $key ] ) ); ?></textarea>
	<p class="description"><?php echo esc_html( $help ); ?></p>
	<?php
};

$utm_override = function ( $key ) use ( $overrides ) {
	if ( ! empty( $overrides[ $key ] ) ) {
		echo '<p class="description"><strong>' . esc_html__( 'A code filter on this site may override this value.', 'utm-attribution-for-woocommerce' ) . '</strong></p>';
	}
};
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors(); ?>

	<form method="post" action="options.php">
		<?php settings_fields( $utm_name ); ?>

		<h2 class="title"><?php esc_html_e( 'Traffic filtering', 'utm-attribution-for-woocommerce' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Bots and crawlers', 'utm-attribution-for-woocommerce' ); ?></th>
				<td><?php $utm_checkbox( 'exclude_bots', __( 'Do not record bots, crawlers, uptime monitors and HTTP tools', 'utm-attribution-for-woocommerce' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="utm-extra_bot_patterns"><?php esc_html_e( 'Additional user agents to ignore', 'utm-attribution-for-woocommerce' ); ?></label></th>
				<td><?php $utm_textarea( 'extra_bot_patterns', __( 'One per line. A visit is ignored when its user agent contains this text (not case-sensitive). 4 to 100 characters. Text that matches your own browser is refused.', 'utm-attribution-for-woocommerce' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Non-page URLs', 'utm-attribution-for-woocommerce' ); ?></th>
				<td><?php $utm_checkbox( 'exclude_non_page', __( 'Do not record sitemaps, /.well-known/ files, robots.txt, favicon and feeds', 'utm-attribution-for-woocommerce' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="utm-extra_noise_paths"><?php esc_html_e( 'Additional URL paths to ignore', 'utm-attribution-for-woocommerce' ); ?></label></th>
				<td><?php $utm_textarea( 'extra_noise_paths', __( 'One per line, starting with "/". A visit is ignored when its URL path starts with this text, e.g. /internal-api/. At least 4 characters.', 'utm-attribution-for-woocommerce' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Prefetch requests', 'utm-attribution-for-woocommerce' ); ?></th>
				<td><?php $utm_checkbox( 'ignore_prefetch', __( 'Do not record prefetch requests', 'utm-attribution-for-woocommerce' ), __( 'Off by default. Browsers reuse a prefetched page when the visitor clicks, so ignoring prefetches also loses those real visits and their orders.', 'utm-attribution-for-woocommerce' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Repeat visits', 'utm-attribution-for-woocommerce' ); ?></th>
				<td>
					<?php $utm_checkbox( 'merge_repeat_visits', __( 'Merge repeat visits from the same browser and source without cookies within 30 minutes', 'utm-attribution-for-woocommerce' ) ); ?>
					<?php if ( $merge_inactive ) : ?>
						<p class="description"><strong><?php esc_html_e( 'Inactive: this needs "Store a hashed IP address" to be on.', 'utm-attribution-for-woocommerce' ); ?></strong></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Shop staff', 'utm-attribution-for-woocommerce' ); ?></th>
				<td><?php $utm_checkbox( 'exclude_staff', __( 'Do not record visits from logged-in administrators and shop managers', 'utm-attribution-for-woocommerce' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="utm-internal_domains"><?php esc_html_e( 'Internal domains', 'utm-attribution-for-woocommerce' ); ?></label></th>
				<td><?php $utm_textarea( 'internal_domains', __( 'One per line, e.g. example.com. Links from these domains and their subdomains are not counted as referrals. Your own site is always internal; add its subdomains here only if they are not real traffic sources.', 'utm-attribution-for-woocommerce' ) ); ?></td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Attribution', 'utm-attribution-for-woocommerce' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="utm-attribution-window"><?php esc_html_e( 'Attribution window (days)', 'utm-attribution-for-woocommerce' ); ?></label></th>
				<td>
					<input type="number" id="utm-attribution-window" name="<?php echo esc_attr( $utm_name . '[attribution_window_days]' ); ?>" value="<?php echo esc_attr( (int) $settings['attribution_window_days'] ); ?>" min="1" max="365" class="small-text" />
					<p class="description"><?php esc_html_e( 'How long an order can still be credited to a visit. 1 to 365.', 'utm-attribution-for-woocommerce' ); ?></p>
					<?php $utm_override( 'attribution_window_days' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Logged-in customers', 'utm-attribution-for-woocommerce' ); ?></th>
				<td>
					<?php $utm_checkbox( 'user_stitching', __( 'Attribute orders from logged-in customers without a cookie to their latest visit', 'utm-attribution-for-woocommerce' ) ); ?>
					<?php $utm_override( 'user_stitching' ); ?>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Privacy', 'utm-attribution-for-woocommerce' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'IP address', 'utm-attribution-for-woocommerce' ); ?></th>
				<td>
					<?php $utm_checkbox( 'ip_hashing', __( 'Store a hashed IP address', 'utm-attribution-for-woocommerce' ), __( 'The IP itself is never stored. Required for merging repeat visits.', 'utm-attribution-for-woocommerce' ) ); ?>
					<?php $utm_override( 'ip_hashing' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="utm-pii-retention"><?php esc_html_e( 'Remove personal data after (days)', 'utm-attribution-for-woocommerce' ); ?></label></th>
				<td>
					<input type="number" id="utm-pii-retention" name="<?php echo esc_attr( $utm_name . '[pii_retention_days]' ); ?>" value="<?php echo esc_attr( (int) $settings['pii_retention_days'] ); ?>" min="0" max="3650" class="small-text" />
					<p class="description"><?php esc_html_e( 'Clears IP hash, user agent, referrer and user ID from older visits. 0 keeps them.', 'utm-attribution-for-woocommerce' ); ?></p>
					<?php $utm_override( 'pii_retention_days' ); ?>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
