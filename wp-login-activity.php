<?php
/**
 * Plugin Name:       WP Login Activity
 * Plugin URI:        https://arraypress.com/plugins/wp-login-activity
 * Description:       Lightweight login activity tracking for WordPress — successful logins, logouts, failed attempts, and registrations, with country-based new-location alerts. BerlinDB storage, no bloat.
 * Version:           2.0.0
 * Author:            ArrayPress
 * Author URI:        https://arraypress.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-login-activity
 * Domain Path:       /languages
 * Requires PHP:      8.0
 * Requires at least: 6.0
 *
 * @package ArrayPress\WP\LoginActivity
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants.
 */
const WP_LOGIN_ACTIVITY_VERSION = '2.0.0';
const WP_LOGIN_ACTIVITY_FILE    = __FILE__;
define( 'WP_LOGIN_ACTIVITY_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_LOGIN_ACTIVITY_URL', plugin_dir_url( __FILE__ ) );

/**
 * Composer autoloader. Bails with an admin notice if vendor/ is missing —
 * a fresh git clone needs `composer install` before the plugin can boot.
 */
if ( ! file_exists( WP_LOGIN_ACTIVITY_PATH . 'vendor/autoload.php' ) ) {
	add_action( 'admin_notices', static function (): void {
		echo '<div class="notice notice-error"><p><strong>WP Login Activity:</strong> ';
		echo esc_html__( 'Composer dependencies are missing. Run "composer install" inside the plugin directory.', 'wp-login-activity' );
		echo '</p></div>';
	} );

	return;
}

require_once WP_LOGIN_ACTIVITY_PATH . 'vendor/autoload.php';

/**
 * Boot the plugin singleton on plugins_loaded so other plugins can hook
 * into ours via `wp_login_activity_*` actions before logging starts.
 */
add_action( 'plugins_loaded', static function (): void {
	\ArrayPress\WP\LoginActivity\Plugin::instance();
} );

/**
 * Activation hook — installs the BerlinDB table and seeds defaults.
 *
 * Delegated to the Plugin class so install logic stays in one place.
 */
register_activation_hook( __FILE__, static function (): void {
	require_once WP_LOGIN_ACTIVITY_PATH . 'vendor/autoload.php';
	\ArrayPress\WP\LoginActivity\Plugin::install();
} );
