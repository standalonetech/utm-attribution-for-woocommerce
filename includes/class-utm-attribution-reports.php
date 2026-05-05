<?php
/**
 * Reports data layer.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-side data layer for the reporting dashboard.
 */
class Utm_Attribution_Reports {

	/**
	 * @param string $granularity 'day', 'month', or 'year'.
	 * @param string $from        YYYY-MM-DD.
	 * @param string $to          YYYY-MM-DD.
	 * @return array
	 */
	public static function get_time_series( $granularity, $from, $to ) {
		global $wpdb;

		$allowed = array( 'day', 'month', 'year' );
		if ( ! in_array( $granularity, $allowed, true ) ) {
			$granularity = 'day';
		}

		// $period_expr is built solely from whitelisted values — safe to interpolate.
		if ( 'month' === $granularity ) {
			$period_expr = "DATE_FORMAT(v.visited_at, '%%Y-%%m-01')";
		} elseif ( 'year' === $granularity ) {
			$period_expr = "DATE_FORMAT(v.visited_at, '%%Y-01-01')";
		} else {
			$period_expr = 'DATE(v.visited_at)';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $period_expr derived from a fixed whitelist; never user input.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					{$period_expr} AS period,
					COUNT(DISTINCT v.id) AS visits,
					COUNT(DISTINCT c.id) AS conversions,
					COALESCE(SUM(c.order_total), 0) AS revenue
				FROM {$wpdb->prefix}utm_attribution_visits v
				LEFT JOIN {$wpdb->prefix}utm_attribution_conversions c ON c.visit_id = v.id
					AND c.converted_at BETWEEN %s AND %s
				WHERE v.visited_at BETWEEN %s AND %s
				GROUP BY period
				ORDER BY period ASC",
				$from . ' 00:00:00',
				$to . ' 23:59:59',
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $results;
	}

	/**
	 * @param string $field utm_source|utm_medium|utm_campaign.
	 * @param string $from
	 * @param string $to
	 * @param int    $limit
	 * @return array
	 */
	public static function get_top_metrics( $field, $from, $to, $limit = 10 ) {
		global $wpdb;

		$allowed = array( 'utm_source', 'utm_medium', 'utm_campaign' );
		if ( ! in_array( $field, $allowed, true ) ) {
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $field validated against a fixed whitelist above; never user input.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					v.{$field} AS label,
					COUNT(DISTINCT v.id) AS visits,
					COUNT(DISTINCT c.id) AS conversions,
					COALESCE(SUM(c.order_total), 0) AS revenue
				FROM {$wpdb->prefix}utm_attribution_visits v
				LEFT JOIN {$wpdb->prefix}utm_attribution_conversions c ON c.visit_id = v.id
					AND c.converted_at BETWEEN %s AND %s
				WHERE v.visited_at BETWEEN %s AND %s
				GROUP BY label
				ORDER BY visits DESC
				LIMIT %d",
				$from . ' 00:00:00',
				$to . ' 23:59:59',
				$from . ' 00:00:00',
				$to . ' 23:59:59',
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $results;
	}

	/**
	 * @param string $from
	 * @param string $to
	 * @return array
	 */
	public static function get_kpis( $from, $to ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(DISTINCT v.id) AS total_visits,
					COUNT(DISTINCT c.id) AS total_conversions,
					COALESCE(SUM(c.order_total), 0) AS total_revenue
				FROM {$wpdb->prefix}utm_attribution_visits v
				LEFT JOIN {$wpdb->prefix}utm_attribution_conversions c ON c.visit_id = v.id
					AND c.converted_at BETWEEN %s AND %s
				WHERE v.visited_at BETWEEN %s AND %s",
				$from . ' 00:00:00',
				$to . ' 23:59:59',
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $row;
	}
}
