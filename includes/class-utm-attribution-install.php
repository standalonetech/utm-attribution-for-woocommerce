<?php
/**
 * Installation and migration class.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates plugin database tables on activation.
 */
class Utm_Attribution_Install {

	/**
	 * Install the plugin.
	 */
	public static function install() {
		if ( ! is_blog_installed() ) {
			return;
		}

		self::create_tables();

		update_option( 'utm_attribution_db_version', UTM_ATTRIBUTION_VERSION );
	}

	/**
	 * Create DB tables.
	 */
	private static function create_tables() {
		global $wpdb;

		$wpdb->hide_errors();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::get_schema() );
	}

	/**
	 * Get table schema.
	 *
	 * @return string
	 */
	private static function get_schema() {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();

		return "
CREATE TABLE {$wpdb->prefix}utm_attribution_visits (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id varchar(64) DEFAULT NULL,
  utm_source varchar(191) NOT NULL,
  utm_medium varchar(191) DEFAULT NULL,
  utm_campaign varchar(191) DEFAULT NULL,
  utm_term varchar(191) DEFAULT NULL,
  utm_content varchar(191) DEFAULT NULL,
  landing_url varchar(500) NOT NULL,
  referrer varchar(500) DEFAULT NULL,
  ip_hash char(64) DEFAULT NULL,
  user_agent varchar(500) DEFAULT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  visited_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY site_id (site_id),
  KEY utm_source (utm_source),
  KEY utm_campaign (utm_campaign),
  KEY visited_at (visited_at)
) $collate;

CREATE TABLE {$wpdb->prefix}utm_attribution_conversions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  visit_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned NOT NULL,
  order_total decimal(18,6) NOT NULL DEFAULT '0.000000',
  currency varchar(10) NOT NULL,
  product_ids varchar(500) DEFAULT NULL,
  status varchar(20) NOT NULL,
  converted_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_id (order_id),
  KEY visit_id (visit_id),
  KEY converted_at (converted_at)
) $collate;
";
	}
}
