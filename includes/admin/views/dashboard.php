<?php
/**
 * Dashboard view.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template file, variables scoped from dashboard_page().

$utm_today = gmdate( 'Y-m-d' );
?>
<div class="wrap utm-attribution-dashboard">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<div class="utm-attribution-filters">
		<div class="utm-filter-presets">
			<button type="button" class="utm-preset-btn<?php echo ( 'today' === $range ) ? ' active' : ''; ?>" data-range="today">
				<?php esc_html_e( 'Today', 'utm-attribution-for-woocommerce' ); ?>
			</button>
			<button type="button" class="utm-preset-btn<?php echo ( '7d' === $range ) ? ' active' : ''; ?>" data-range="7d">
				<?php esc_html_e( 'Last 7 Days', 'utm-attribution-for-woocommerce' ); ?>
			</button>
			<button type="button" class="utm-preset-btn<?php echo ( '30d' === $range ) ? ' active' : ''; ?>" data-range="30d">
				<?php esc_html_e( 'Last 30 Days', 'utm-attribution-for-woocommerce' ); ?>
			</button>
			<button type="button" class="utm-preset-btn<?php echo ( '90d' === $range ) ? ' active' : ''; ?>" data-range="90d">
				<?php esc_html_e( 'Last 90 Days', 'utm-attribution-for-woocommerce' ); ?>
			</button>
			<button type="button" class="utm-preset-btn<?php echo ( 'year' === $range ) ? ' active' : ''; ?>" data-range="year">
				<?php esc_html_e( 'This Year', 'utm-attribution-for-woocommerce' ); ?>
			</button>
		</div>

		<div class="utm-filter-divider"></div>

		<form method="get" id="utm-filter-form">
			<input type="hidden" name="page"  value="utm-attribution-for-woocommerce" />
			<input type="hidden" name="range" value="<?php echo esc_attr( $range ); ?>" id="utm-range-val" />
			<div class="utm-daterange-row">
				<div class="utm-daterange-field">
					<label for="utm-from-date"><?php esc_html_e( 'From', 'utm-attribution-for-woocommerce' ); ?></label>
					<input type="date" name="from" id="utm-from-date"
						value="<?php echo esc_attr( $from ); ?>"
						max="<?php echo esc_attr( $utm_today ); ?>" />
				</div>
				<span class="utm-date-arrow">&#8594;</span>
				<div class="utm-daterange-field">
					<label for="utm-to-date"><?php esc_html_e( 'To', 'utm-attribution-for-woocommerce' ); ?></label>
					<input type="date" name="to" id="utm-to-date"
						value="<?php echo esc_attr( $to ); ?>"
						max="<?php echo esc_attr( $utm_today ); ?>" />
				</div>
				<button type="submit" class="button utm-apply-btn">
					<?php esc_html_e( 'Apply', 'utm-attribution-for-woocommerce' ); ?>
				</button>
			</div>
		</form>
	</div>

	<div class="utm-attribution-kpis">
		<div class="kpi-card">
			<h3><span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Total Visits', 'utm-attribution-for-woocommerce' ); ?></h3>
			<p class="value"><?php echo esc_html( number_format_i18n( $kpis['total_visits'] ) ); ?></p>
		</div>
		<div class="kpi-card">
			<h3><span class="dashicons dashicons-cart"></span> <?php esc_html_e( 'Total Conversions', 'utm-attribution-for-woocommerce' ); ?></h3>
			<p class="value"><?php echo esc_html( number_format_i18n( $kpis['total_conversions'] ) ); ?></p>
		</div>
		<div class="kpi-card">
			<h3><span class="dashicons dashicons-chart-pie"></span> <?php esc_html_e( 'Conversion Rate', 'utm-attribution-for-woocommerce' ); ?></h3>
			<p class="value">
				<?php
				$utm_cr = $kpis['total_visits'] > 0 ? ( $kpis['total_conversions'] / $kpis['total_visits'] ) * 100 : 0;
				echo esc_html( number_format_i18n( $utm_cr, 2 ) . '%' );
				?>
			</p>
		</div>
		<div class="kpi-card">
			<h3><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e( 'Total Revenue', 'utm-attribution-for-woocommerce' ); ?></h3>
			<p class="value"><?php echo wp_kses_post( wc_price( $kpis['total_revenue'] ) ); ?></p>
		</div>
	</div>

	<div class="utm-attribution-charts">
		<h3><?php esc_html_e( 'Performance Overview', 'utm-attribution-for-woocommerce' ); ?></h3>
		<div style="height: 350px;">
			<canvas id="utm-attribution-main-chart"></canvas>
		</div>
	</div>

	<div class="utm-attribution-tables">
		<h3><?php esc_html_e( 'Top Performing Campaigns', 'utm-attribution-for-woocommerce' ); ?></h3>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Campaign Name', 'utm-attribution-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Visits', 'utm-attribution-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Conversions', 'utm-attribution-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Conv. Rate', 'utm-attribution-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Revenue Generated', 'utm-attribution-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $top_campaigns ) : ?>
					<?php foreach ( $top_campaigns as $utm_row ) : ?>
						<tr>
							<td style="font-weight: 500; color: #0f172a;"><?php echo esc_html( $utm_row['label'] ?: __( '(Direct / None)', 'utm-attribution-for-woocommerce' ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $utm_row['visits'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $utm_row['conversions'] ) ); ?></td>
							<td>
								<?php
								$utm_row_cr = $utm_row['visits'] > 0 ? ( $utm_row['conversions'] / $utm_row['visits'] ) * 100 : 0;
								echo esc_html( number_format_i18n( $utm_row_cr, 2 ) . '%' );
								?>
							</td>
							<td style="font-weight: 600; color: #10b981;"><?php echo wp_kses_post( wc_price( $utm_row['revenue'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="5" style="text-align: center; padding: 40px; color: #94a3b8;">
							<?php esc_html_e( 'No campaign data recorded for this period.', 'utm-attribution-for-woocommerce' ); ?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
