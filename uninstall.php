<?php
/**
 * Uninstall plugin.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}utm_attribution_visits" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}utm_attribution_conversions" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

delete_option( 'utm_attribution_db_version' );
wp_clear_scheduled_hook( 'utm_attribution_purge_pii' );
