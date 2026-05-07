<?php
/**
 * User Profile Section
 *
 * Renders a "Recent login activity" section on the user-edit screen
 * (and `profile.php`). Surfaces the most recent N events for the user
 * being viewed, with the same NEW-country badge as the main page.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Admin;

defined( 'ABSPATH' ) || exit;

use ArrayPress\WP\LoginActivity\Plugin;
use ArrayPress\WP\LoginActivity\Database\Rows\Activity as ActivityRow;
use WP_User;

/**
 * Class UserProfile
 *
 * @since 2.0.0
 */
class UserProfile {

	/**
	 * Number of recent events to render.
	 *
	 * @since 2.0.0
	 */
	private const RECENT_LIMIT = 10;

	/**
	 * Constructor.
	 *
	 * Hooks both `edit_user_profile` (admin editing another user) and
	 * `show_user_profile` (user viewing their own).
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_action( 'edit_user_profile', [ $this, 'render' ] );
		add_action( 'show_user_profile', [ $this, 'render' ] );
	}

	/**
	 * Render the section.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_User $user The user being edited.
	 *
	 * @return void
	 */
	public function render( WP_User $user ): void {
		// Non-admin viewers only see their own activity. Admins see
		// everyone's. Capability mirrors the user-edit screen itself.
		if ( ! current_user_can( 'manage_options' ) && (int) $user->ID !== get_current_user_id() ) {
			return;
		}

		$rows = Plugin::query()->query( [
			'user_id' => (int) $user->ID,
			'orderby' => 'date_created',
			'order'   => 'DESC',
			'number'  => self::RECENT_LIMIT,
		] );

		?>
		<h2><?php esc_html_e( 'Recent login activity', 'wp-login-activity' ); ?></h2>

		<?php if ( empty( $rows ) ) : ?>
			<p><?php esc_html_e( 'No activity yet.', 'wp-login-activity' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When',    'wp-login-activity' ); ?></th>
						<th><?php esc_html_e( 'Event',   'wp-login-activity' ); ?></th>
						<th><?php esc_html_e( 'IP',      'wp-login-activity' ); ?></th>
						<th><?php esc_html_e( 'Country', 'wp-login-activity' ); ?></th>
						<th><?php esc_html_e( 'Device',  'wp-login-activity' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php /** @var ActivityRow $row */ ?>
						<tr>
							<td><?php echo esc_html( $row->date_created . ' UTC' ); ?></td>
							<td><?php echo esc_html( $this->event_label( $row->event_type ) ); ?></td>
							<td><code><?php echo esc_html( $row->ip_address ); ?></code></td>
							<td>
								<?php echo esc_html( $row->country_code !== '' ? $row->country_code : '—' ); ?>
								<?php if ( $row->is_new_country() ) : ?>
									<span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:3px;font-size:11px;margin-left:4px;">
										<?php esc_html_e( 'NEW', 'wp-login-activity' ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<?php $formatted = $row->get_formatted_user_agent(); ?>
								<?php echo esc_html( $formatted !== '' ? $formatted : '—' ); ?>
								<?php if ( $row->is_bot() ) : ?>
									<span style="background:#fde2e2;color:#9b1c1c;padding:1px 6px;border-radius:3px;font-size:11px;margin-left:4px;">
										<?php esc_html_e( 'BOT', 'wp-login-activity' ); ?>
									</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<a href="<?php echo esc_url( admin_url( 'users.php?page=wp-login-activity&user_id=' . (int) $user->ID ) ); ?>">
					<?php esc_html_e( 'View full activity →', 'wp-login-activity' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Translate event slug to display label.
	 *
	 * @since 2.0.0
	 *
	 * @param string $event_type Event slug.
	 *
	 * @return string
	 */
	private function event_label( string $event_type ): string {
		$labels = [
			'login'             => __( 'Login',                  'wp-login-activity' ),
			'login_failed'      => __( 'Failed login',           'wp-login-activity' ),
			'logout'            => __( 'Logout',                 'wp-login-activity' ),
			'registered'        => __( 'Registered',             'wp-login-activity' ),
			'password_changed'  => __( 'Password changed',       'wp-login-activity' ),
			'admin_assigned'    => __( 'Admin role assigned',    'wp-login-activity' ),
		];

		return $labels[ $event_type ] ?? $event_type;
	}

}
