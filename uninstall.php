<?php
/**
 * Uninstall WP Login Activity
 *
 * Removes the BerlinDB table, plugin options, and cron schedule.
 * Runs only when the user actually deletes the plugin from
 * Plugins → Installed Plugins → Delete (not on plain deactivate).
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the BerlinDB table. We use raw DDL here rather than booting
// the plugin's BerlinDB classes — uninstall runs in a stripped-down
// context where plugin autoloading isn't guaranteed.
$table = $wpdb->prefix . 'login_activity';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB

// Drop the BerlinDB-managed schema-version option that tracks the
// installed table version separately from the plugin version.
delete_option( "wpdb_{$wpdb->prefix}login_activity_version" );

// Plugin options.
$options = [
	'wp_login_activity_retention_days',
	'wp_login_activity_notify_new_location',
	'wp_login_activity_notify_recipient',
	'wp_login_activity_log_failed_logins',
	'wp_login_activity_log_logouts',
	'wp_login_activity_log_registrations',
];

foreach ( $options as $option ) {
	delete_option( $option );
}

// Cron.
wp_clear_scheduled_hook( 'wp_login_activity_cleanup' );
