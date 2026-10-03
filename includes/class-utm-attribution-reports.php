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
	 * Order statuses whose conversions no longer count (kept in sync by Utm_Attribution_Conversion).
	 */
	const REVERSED_STATUSES_SQL = "'refunded','cancelled','failed'";

	/**
	 * @param string $granularity 'day', 'month', or 'year'.
	 * @param string $from        YYYY-MM-DD.
	 * @param string $to          YYYY-MM-DD.
	 * @return array
	 */
	public static function get_time_series( $granularity, $from, $to ) {
		global $wpdb;

		list( $start, $end ) = self::utc_bounds( $from, $to );
		$currency            = get_woocommerce_currency();

		$allowed = array( 'day', 'month', 'year' );
		if ( ! in_array( $granularity, $allowed, true ) ) {
			$granularity = 'day';
		}

		$reversed     = self::REVERSED_STATUSES_SQL;
		$visit_period = self::period_expr( $granularity, 'visited_at' );
		$conv_period  = self::period_expr( $granularity, 'converted_at' );

		// Visits are bucketed by visited_at and conversions by converted_at, so an order placed in
		// range counts even when its visit predates the range.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- period expressions derived from a fixed whitelist; never user input.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT period, SUM(visits) AS visits, SUM(conversions) AS conversions, COALESCE(SUM(revenue), 0) AS revenue
				FROM (
					SELECT {$visit_period} AS period, 1 AS visits, 0 AS conversions, 0 AS revenue
					FROM {$wpdb->prefix}utm_attribution_visits
					WHERE visited_at BETWEEN %s AND %s
					UNION ALL
					SELECT {$conv_period} AS period, 0 AS visits, 1 AS conversions, IF(currency = %s, order_total, 0) AS revenue
					FROM {$wpdb->prefix}utm_attribution_conversions
					WHERE converted_at BETWEEN %s AND %s AND status NOT IN ({$reversed})
				) t
				GROUP BY period
				ORDER BY period ASC",
				$start,
				$end,
				$currency,
				$start,
				$end
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

		list( $start, $end ) = self::utc_bounds( $from, $to );
		$currency            = get_woocommerce_currency();

		$allowed = array( 'utm_source', 'utm_medium', 'utm_campaign' );
		if ( ! in_array( $field, $allowed, true ) ) {
			return array();
		}

		$reversed = self::REVERSED_STATUSES_SQL;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $field validated against a fixed whitelist above; never user input.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT label, SUM(visits) AS visits, SUM(conversions) AS conversions, COALESCE(SUM(revenue), 0) AS revenue
				FROM (
					SELECT {$field} AS label, 1 AS visits, 0 AS conversions, 0 AS revenue
					FROM {$wpdb->prefix}utm_attribution_visits
					WHERE visited_at BETWEEN %s AND %s
					UNION ALL
					SELECT v.{$field} AS label, 0 AS visits, 1 AS conversions, IF(c.currency = %s, c.order_total, 0) AS revenue
					FROM {$wpdb->prefix}utm_attribution_conversions c
					INNER JOIN {$wpdb->prefix}utm_attribution_visits v ON v.id = c.visit_id
					WHERE c.converted_at BETWEEN %s AND %s AND c.status NOT IN ({$reversed})
				) t
				GROUP BY label
				ORDER BY visits DESC
				LIMIT %d",
				$start,
				$end,
				$currency,
				$start,
				$end,
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

		list( $start, $end ) = self::utc_bounds( $from, $to );
		$currency            = get_woocommerce_currency();

		$reversed = self::REVERSED_STATUSES_SQL;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $reversed is a class constant.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					( SELECT COUNT(*) FROM {$wpdb->prefix}utm_attribution_visits WHERE visited_at BETWEEN %s AND %s ) AS total_visits,
					COUNT(*) AS total_conversions,
					COALESCE(SUM(IF(currency = %s, order_total, 0)), 0) AS total_revenue
				FROM {$wpdb->prefix}utm_attribution_conversions
				WHERE converted_at BETWEEN %s AND %s AND status NOT IN ({$reversed})",
				$start,
				$end,
				$currency,
				$start,
				$end
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $row;
	}

	/**
	 * SQL expression that buckets a date column by granularity.
	 *
	 * @param string $granularity 'day', 'month', or 'year' (already whitelisted).
	 * @param string $column      visited_at|converted_at.
	 * @return string
	 */
	private static function period_expr( $granularity, $column ) {
		// ponytail: offset taken "now"; a range spanning a DST change buckets 1h off on one side. Upgrade path: MySQL tz tables + CONVERT_TZ to the zone name.
		$offset = ( new DateTime( 'now', wp_timezone() ) )->format( 'P' );
		$column = "CONVERT_TZ({$column}, '+00:00', '{$offset}')";

		if ( 'month' === $granularity ) {
			return "DATE_FORMAT({$column}, '%%Y-%%m-01')";
		}
		if ( 'year' === $granularity ) {
			return "DATE_FORMAT({$column}, '%%Y-01-01')";
		}
		return "DATE({$column})";
	}

	/**
	 * Convert a site-local Y-m-d range into UTC datetime bounds for the UTC columns.
	 *
	 * @param string $from YYYY-MM-DD, site time.
	 * @param string $to   YYYY-MM-DD, site time.
	 * @return string[] [ start, end ] as UTC MySQL datetimes.
	 */
	public static function utc_bounds( $from, $to ) {
		return array( get_gmt_from_date( $from . ' 00:00:00' ), get_gmt_from_date( $to . ' 23:59:59' ) );
	}
}
