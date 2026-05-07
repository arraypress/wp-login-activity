<?php
/**
 * Dashboard Widget
 *
 * Registers a "Login Activity" widget on the WordPress dashboard
 * showing three at-a-glance counters covering the last 24 hours:
 * total events, failed logins, and new-country logins. Each is a
 * clickable drill-in to the matching filtered view of the full
 * activity table.
 *
 * Why on the Dashboard rather than inline on the activity page —
 * stats are an at-a-glance "is something happening?" surface; the
 * activity page itself is for investigation, where the same
 * counters are visual noise above the rows you came to look at.
 * Admins log in to wp-admin, see the dashboard, immediately know
 * if something needs attention. WP's native widget hide/show via
 * Screen Options handles "I don't want this" without a plugin
 * setting.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Admin;

defined( 'ABSPATH' ) || exit;

use ArrayPress\WP\LoginActivity\Plugin;

/**
 * Class DashboardWidget
 *
 * @since 2.0.0
 */
class DashboardWidget {

	/**
	 * Constructor — registers the widget on `wp_dashboard_setup`.
	 *
	 * Filterable via `wp_login_activity_dashboard_widget_enabled`
	 * for sites that want to suppress the widget entirely without
	 * relying on the per-user Screen Options checkbox.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_action( 'wp_dashboard_setup', [ $this, 'register' ] );
	}

	/**
	 * Register the widget.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		// Cap-gate. Non-admins shouldn't see site-wide auth stats
		// even if they happen to land on the dashboard (multisite
		// admin pages, custom roles with dashboard access, etc.).
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		/**
		 * Filter whether the dashboard widget is registered.
		 *
		 * @since 2.0.0
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'wp_login_activity_dashboard_widget_enabled', true ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wp_login_activity_widget',
			__( 'Login Activity', 'wp-login-activity' ),
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the widget body.
	 *
	 * Three counters in a flex row + a footer link to the full
	 * activity page. Counter values are coloured red/amber when
	 * non-zero so a problem is visible at a glance without reading
	 * the labels.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		$base_args = $this->window_args();
		$base_url  = admin_url( 'users.php?page=wp-login-activity' );

		$query = Plugin::query();

		$total       = (int) $query->query( array_merge( $base_args, [ 'count' => true, 'number' => 0 ] ) );
		$failed      = (int) $query->query( array_merge( $base_args, [ 'count' => true, 'number' => 0, 'event_type' => 'login_failed' ] ) );
		$new_country = (int) $query->query( array_merge( $base_args, [ 'count' => true, 'number' => 0, 'is_new_country' => 1, 'event_type' => 'login' ] ) );

		// Drill-in URLs preserve the 24h window so the activity
		// page lands on the same scope the widget shows.
		$window_url_args = [ 'from' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) ];

		$total_url       = add_query_arg( $window_url_args, $base_url );
		$failed_url      = add_query_arg( array_merge( $window_url_args, [ 'event_type' => 'login_failed' ] ), $base_url );
		$new_country_url = add_query_arg( array_merge( $window_url_args, [ 'event_type' => 'login', 'is_new_country' => '1' ] ), $base_url );

		?>
		<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;">
			<?php $this->render_counter( __( 'Events', 'wp-login-activity' ), $total, $total_url, '#1d2327' ); ?>
			<?php $this->render_counter( __( 'Failed logins', 'wp-login-activity' ), $failed, $failed_url, $failed > 0 ? '#9b1c1c' : '#1d2327' ); ?>
			<?php $this->render_counter( __( 'New-country logins', 'wp-login-activity' ), $new_country, $new_country_url, $new_country > 0 ? '#92400e' : '#1d2327' ); ?>
		</div>

		<p style="margin:14px 0 0;font-size:12px;color:#646970;">
			<?php esc_html_e( 'Last 24 hours.', 'wp-login-activity' ); ?>
			<a href="<?php echo esc_url( $base_url ); ?>" style="margin-left:6px;">
				<?php esc_html_e( 'View full activity →', 'wp-login-activity' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Render one counter cell.
	 *
	 * @since 2.0.0
	 *
	 * @param string $label Human label.
	 * @param int    $count Counter value.
	 * @param string $url   Drill-in URL.
	 * @param string $color Hex foreground for the count value.
	 *
	 * @return void
	 */
	private function render_counter( string $label, int $count, string $url, string $color ): void {
		?>
		<div style="flex:1;min-width:90px;">
			<div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;">
				<?php echo esc_html( $label ); ?>
			</div>
			<div style="font-size:24px;font-weight:600;line-height:1.2;color:<?php echo esc_attr( $color ); ?>;">
				<a href="<?php echo esc_url( $url ); ?>" style="color:inherit;text-decoration:none;">
					<?php echo esc_html( number_format_i18n( $count ) ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * BerlinDB query args representing the last-24h window.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	private function window_args(): array {
		return [
			'date_created_query' => [
				[
					'after'     => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
					'inclusive' => true,
				],
			],
		];
	}

}
