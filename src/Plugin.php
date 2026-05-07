<?php
/**
 * Plugin Singleton
 *
 * Boots the plugin: instantiates the BerlinDB table, wires the
 * tracking listener, registers the cron purge, and (in admin)
 * spins up the admin surfaces. Single entry point for everything;
 * the entry file just calls `Plugin::instance()`.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity;

defined( 'ABSPATH' ) || exit;

use ArrayPress\WP\LoginActivity\Database\Tables\Activity as ActivityTable;
use ArrayPress\WP\LoginActivity\Tracking\Logger;
use ArrayPress\WP\LoginActivity\Tracking\Cleanup;
use ArrayPress\WP\LoginActivity\Notifications\NewLocationAlert;
use ArrayPress\WP\LoginActivity\Notifications\AdminAssignedAlert;
use ArrayPress\WP\LoginActivity\Notifications\AdminPasswordChangedAlert;
use ArrayPress\WP\LoginActivity\Notifications\AdminEmailChangedAlert;
use ArrayPress\WP\LoginActivity\Notifications\FailedLoginBurstAlert;

/**
 * Class Plugin
 *
 * @since 2.0.0
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @since 2.0.0
	 * @var   Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Get / create the singleton instance.
	 *
	 * @since 2.0.0
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Wire components.
	 *
	 * Order matters slightly:
	 *   1. Table class is instantiated first so BerlinDB is aware of
	 *      the table on every page load (handles version upgrades).
	 *   2. Logger (event listener) attaches to WP login hooks.
	 *   3. NewLocationAlert subscribes to the logger's action.
	 *   4. Cleanup registers the cron handler.
	 *   5. Admin surfaces only load when in admin (and only on
	 *      `init` so capability checks see the resolved current user).
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function boot(): void {

		// 1. BerlinDB Table — registering the class is enough; BerlinDB
		// auto-installs/upgrades on construction.
		new ActivityTable();

		// 2. Tracking listener — attaches to wp_login / wp_login_failed /
		// wp_logout / user_register and writes a row per event.
		new Logger();

		// 3. Notification listeners. Each subscribes to the
		// `wp_login_activity_logged` action emitted by Logger and
		// independently decides whether the row is worth an email.
		new NewLocationAlert();
		new AdminAssignedAlert();
		new AdminPasswordChangedAlert();
		new AdminEmailChangedAlert();
		new FailedLoginBurstAlert();

		// 4. Cron purge — expired rows get deleted daily.
		new Cleanup();

		// 5. Admin (settings, list table, user-profile section,
		//    CSV exporter). The Exporter listens on `admin_post_*`
		//    which fires regardless of menu state, so it's
		//    instantiated outside the `is_admin()` gate that wraps
		//    the rendered surfaces.
		new Admin\Exporter();

		if ( is_admin() ) {
			add_action( 'init', static function (): void {
				new Admin\Settings();
				new Admin\ActivityPage();
				new Admin\UserProfile();
				new Admin\UserColumns();
				new Admin\DashboardWidget();
			} );
		}
	}

	/**
	 * Activation handler.
	 *
	 * Forces the BerlinDB table install path immediately rather than
	 * waiting for the next admin pageview, and seeds the default
	 * options so a fresh install has sensible behaviour out of the box.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function install(): void {
		( new ActivityTable() )->install();

		$defaults = [
			'wp_login_activity_retention_days'                => 90,

			// Email alerts — security-sensitive ones default ON, the
			// noisier ones (failed-login burst) default OFF.
			'wp_login_activity_notify_new_location'           => 1,
			'wp_login_activity_notify_admin_assigned'         => 1,
			'wp_login_activity_notify_admin_password_changed' => 1,
			'wp_login_activity_notify_admin_email_changed'    => 1,
			'wp_login_activity_notify_failed_burst'           => 0,
			'wp_login_activity_notify_recipient'              => 'user',

			// Per-event-type capture toggles.
			'wp_login_activity_log_failed_logins'             => 1,
			'wp_login_activity_log_logouts'                   => 1,
			'wp_login_activity_log_registrations'             => 1,
			'wp_login_activity_log_password_changes'          => 1,
			'wp_login_activity_log_email_changes'             => 1,
		];

		foreach ( $defaults as $option => $value ) {
			if ( get_option( $option, null ) === null ) {
				add_option( $option, $value );
			}
		}

		Cleanup::schedule();
	}

	/**
	 * Get the cached Query instance.
	 *
	 * Convenience accessor — same pattern as edd_fraud_filter()'s
	 * helper. Lets call sites do:
	 *
	 *   Plugin::query()->query( [ 'user_id' => 1 ] );
	 *
	 * @since 2.0.0
	 *
	 * @return Database\Queries\Activity
	 */
	public static function query(): Database\Queries\Activity {
		static $instance = null;

		if ( $instance === null ) {
			$instance = new Database\Queries\Activity();
		}

		return $instance;
	}

	/**
	 * Disable cloning.
	 *
	 * @since 2.0.0
	 */
	private function __clone() {}

	/**
	 * Disable wakeup.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function __wakeup(): void {}

}
