<?php
/**
 * Return Requests WooCommerce plugin uninstallation file.
 *
 * Runs when the user clicks "Delete" in the WordPress plugins panel.
 * Does NOT run on deactivation — only on complete removal.
 */

// Security: do not execute if called outside of WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

// Remove database tables
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $wpdb->prefix is safe
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}woo_returns`" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $wpdb->prefix is safe
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}woo_return_security_logs`" );

// Remove all plugin options
$plugin_options = [
    'woo_return_admin_email',
    'woo_return_from_email',
    'woo_return_customer_subject',
    'woo_return_admin_subject',
    'woo_return_customer_message',
    'woo_return_admin_message',
    'woo_return_email_name',
    'woo_return_contact_email',
    'woo_return_store_details',
    'woo_return_encryption_key',
    'woo_return_show_setup_notice',
    'woo_return_slug_form',
    'woo_return_slug_items',
    'woo_return_slug_confirm',
    'woo_return_window_days',
    'woo_return_bank_format',
    'turnstile_site_key',
    'turnstile_secret_key',
    'turnstile_enable',
];

foreach ( $plugin_options as $option ) {
    delete_option( $option );
}

// Remove transients (verification tokens)
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '\_transient\_woo\_return\_%'" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '\_transient\_timeout\_woo\_return\_%'" );

// Clear scheduled cron events
wp_clear_scheduled_hook( 'woo_return_clean_security_logs' );
