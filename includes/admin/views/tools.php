<?php
/**
 * Tools view: noise cleanup.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template file, variables scoped from settings_page().

$utm_labels = Utm_Attribution_Cleanup::labels();
$utm_scan   = $tools['scan'];
$utm_job    = $tools['job'];
$utm_post   = admin_url( 'admin-post.php' );

// Redirect feedback code from the start/cancel handlers; display only.
$utm_notice_key = isset( $_GET['utm_notice'] ) ? sanitize_key( wp_unslash( $_GET['utm_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$utm_notices    = array(
	'started'     => array( 'info', __( 'Started. You can leave this page; the work continues in the background.', 'utm-attribution-for-woocommerce' ) ),
	'cancelled'   => array( 'info', __( 'Cancelled.', 'utm-attribution-for-woocommerce' ) ),
	'locked'      => array( 'warning', __( 'A cleanup is already running.', 'utm-attribution-for-woocommerce' ) ),
	'rescan'      => array( 'warning', __( 'Settings or data changed. Please scan again.', 'utm-attribution-for-woocommerce' ) ),
	'confirm'     => array( 'warning', __( 'Tick the confirmation box to delete.', 'utm-attribution-for-woocommerce' ) ),
	'unavailable' => array( 'error', __( 'Cleanup needs WooCommerce (it includes Action Scheduler).', 'utm-attribution-for-woocommerce' ) ),
);

$utm_total_delete = 0;
if ( $utm_scan ) {
	foreach ( $utm_scan['counts'] as $utm_c ) {
		$utm_total_delete += $utm_c['found'] - $utm_c['protected'];
	}
}
?>
<div class="wrap utm-cleanup">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<?php Utm_Attribution_Settings::render_tabs( 'tools' ); ?>

	<?php if ( isset( $utm_notices[ $utm_notice_key ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $utm_notices[ $utm_notice_key ][0] ); ?> inline"><p><?php echo esc_html( $utm_notices[ $utm_notice_key ][1] ); ?></p></div>
	<?php endif; ?>

	<h2 class="title"><?php esc_html_e( 'Clean up past noise', 'utm-attribution-for-woocommerce' ); ?></h2>
	<p><?php esc_html_e( 'Finds visits that your current filters would no longer record: bots, uptime monitors, non-page URLs, repeat visits without cookies and staff. Visits linked to orders are never deleted.', 'utm-attribution-for-woocommerce' ); ?></p>

	<?php if ( $utm_job ) : ?>
		<?php $utm_pct = $utm_job['max_id'] ? min( 100, (int) floor( $utm_job['cursor'] / $utm_job['max_id'] * 100 ) ) : 100; ?>
		<div id="utm-cleanup-progress"
			data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( Utm_Attribution_Cleanup::NONCE ) ); ?>"
			data-waiting="<?php esc_attr_e( 'Waiting for background processing. See WooCommerce → Status → Scheduled Actions (group utm-attribution).', 'utm-attribution-for-woocommerce' ); ?>">
			<p>
				<strong><?php echo 'delete' === $utm_job['mode'] ? esc_html__( 'Deleting…', 'utm-attribution-for-woocommerce' ) : esc_html__( 'Scanning…', 'utm-attribution-for-woocommerce' ); ?></strong>
				<span class="utm-cleanup-pct"><?php echo esc_html( $utm_pct ); ?>%</span>
				<?php esc_html_e( 'You can leave this page.', 'utm-attribution-for-woocommerce' ); ?>
			</p>
			<div class="utm-cleanup-bar"><span style="width: <?php echo esc_attr( $utm_pct ); ?>%"></span></div>
			<p class="utm-cleanup-wait description"></p>
			<form method="post" action="<?php echo esc_url( $utm_post ); ?>">
				<input type="hidden" name="action" value="utm_attribution_cleanup_cancel" />
				<?php wp_nonce_field( Utm_Attribution_Cleanup::NONCE ); ?>
				<?php submit_button( __( 'Cancel', 'utm-attribution-for-woocommerce' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( $utm_post ); ?>">
			<input type="hidden" name="action" value="utm_attribution_cleanup_start" />
			<input type="hidden" name="mode" value="scan" />
			<?php wp_nonce_field( Utm_Attribution_Cleanup::NONCE ); ?>
			<p>
				<label for="utm-cleanup-from"><?php esc_html_e( 'From', 'utm-attribution-for-woocommerce' ); ?></label>
				<input type="date" id="utm-cleanup-from" name="from" />
				<label for="utm-cleanup-to"><?php esc_html_e( 'To', 'utm-attribution-for-woocommerce' ); ?></label>
				<input type="date" id="utm-cleanup-to" name="to" />
				<span class="description">
					<?php
					/* translators: %s: site timezone name. */
					printf( esc_html__( 'Optional, in your site timezone (%s). Leave empty to scan all visits.', 'utm-attribution-for-woocommerce' ), esc_html( wp_timezone_string() ) );
					?>
				</span>
			</p>
			<?php submit_button( __( 'Scan', 'utm-attribution-for-woocommerce' ), 'primary', 'submit', false ); ?>
		</form>

		<?php if ( $tools['stale'] ) : ?>
			<p class="description"><?php esc_html_e( 'The last scan is no longer valid (older than 30 minutes, another user, or settings changed). Scan again to delete.', 'utm-attribution-for-woocommerce' ); ?></p>
		<?php endif; ?>

		<?php if ( $utm_scan ) : ?>
			<h2 class="title"><?php esc_html_e( 'Scan result', 'utm-attribution-for-woocommerce' ); ?></h2>
			<?php $utm_repeat_off = ! utm_attribution_get_settings( 'merge_repeat_visits' ) || ! utm_attribution_get_settings( 'ip_hashing' ); ?>
			<table class="widefat striped utm-cleanup-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Category', 'utm-attribution-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Found', 'utm-attribution-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Protected', 'utm-attribution-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Will delete', 'utm-attribution-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$utm_sum = array( 0, 0 );
				foreach ( $utm_labels as $utm_key => $utm_label ) :
					$utm_c = isset( $utm_scan['counts'][ $utm_key ] ) ? $utm_scan['counts'][ $utm_key ] : array( 'found' => 0, 'protected' => 0 );
					?>
					<tr>
						<td><?php echo esc_html( $utm_label ); ?></td>
						<?php if ( 'repeat' === $utm_key && $utm_repeat_off ) : ?>
							<td colspan="3"><?php esc_html_e( 'n/a (IP hashing or repeat merging is off)', 'utm-attribution-for-woocommerce' ); ?></td>
						<?php else : ?>
							<td><?php echo esc_html( number_format_i18n( $utm_c['found'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $utm_c['protected'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $utm_c['found'] - $utm_c['protected'] ) ); ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th><?php esc_html_e( 'Total', 'utm-attribution-for-woocommerce' ); ?></th>
						<th><?php echo esc_html( number_format_i18n( array_sum( array_column( $utm_scan['counts'], 'found' ) ) ) ); ?></th>
						<th><?php echo esc_html( number_format_i18n( array_sum( array_column( $utm_scan['counts'], 'protected' ) ) ) ); ?></th>
						<th><?php echo esc_html( number_format_i18n( $utm_total_delete ) ); ?></th>
					</tr>
				</tfoot>
			</table>

			<?php if ( $utm_scan['uas'] ) : ?>
				<h3><?php esc_html_e( 'Top user agents found', 'utm-attribution-for-woocommerce' ); ?></h3>
				<ol>
					<?php foreach ( $utm_scan['uas'] as $utm_ua => $utm_n ) : ?>
						<li><code><?php echo esc_html( '' === $utm_ua ? __( '(empty)', 'utm-attribution-for-woocommerce' ) : $utm_ua ); ?></code> &times; <?php echo esc_html( number_format_i18n( $utm_n ) ); ?></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>

			<?php if ( $utm_total_delete ) : ?>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=utm-attribution-settings&utm-export=noise' ), 'utm_attribution_export' ) ); ?>"><?php esc_html_e( 'Download rows to be deleted (CSV)', 'utm-attribution-for-woocommerce' ); ?></a>
				</p>
				<form method="post" action="<?php echo esc_url( $utm_post ); ?>">
					<input type="hidden" name="action" value="utm_attribution_cleanup_start" />
					<input type="hidden" name="mode" value="delete" />
					<?php wp_nonce_field( Utm_Attribution_Cleanup::NONCE ); ?>
					<p class="description"><?php esc_html_e( 'Back up your database first. Deleted visits cannot be restored.', 'utm-attribution-for-woocommerce' ); ?></p>
					<p>
						<label>
							<input type="checkbox" name="confirm" value="1" id="utm-cleanup-confirm" />
							<?php esc_html_e( 'I understand this cannot be undone', 'utm-attribution-for-woocommerce' ); ?>
						</label>
					</p>
					<?php
					submit_button(
						/* translators: %s: number of visits. */
						sprintf( __( 'Delete %s visits', 'utm-attribution-for-woocommerce' ), number_format_i18n( $utm_total_delete ) ),
						'delete',
						'submit',
						false,
						array( 'id' => 'utm-cleanup-delete', 'disabled' => 'disabled' )
					);
					?>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $tools['last_run'] ) : ?>
		<?php $utm_user = get_userdata( $tools['last_run']['user_id'] ); ?>
		<p class="description">
			<?php
			printf(
				/* translators: 1: date and time, 2: user name, 3: deleted count, 4: protected count. */
				esc_html__( 'Last cleanup: %1$s by %2$s. %3$s visits deleted, %4$s kept because they are linked to orders.', 'utm-attribution-for-woocommerce' ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $tools['last_run']['finished_at'] ) ),
				esc_html( $utm_user ? $utm_user->display_name : '—' ),
				esc_html( number_format_i18n( $tools['last_run']['deleted'] ) ),
				esc_html( number_format_i18n( $tools['last_run']['protected'] ) )
			);
			?>
		</p>
	<?php endif; ?>
</div>
