<?php
/**
 * Settings Page
 *
 * Settings → Login Activity. Five fields, one form, ten lines of UI:
 * the plugin is intentionally narrow so the settings page reflects
 * that. Each option is registered against the Settings API so
 * sanitization is shared and `wp-login-activity_options` group is
 * the single source of truth.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 *
 * @since 2.0.0
 */
class Settings {

	/**
	 * Settings group + page slug.
	 *
	 * @since 2.0.0
	 */
	private const OPTION_GROUP = 'wp_login_activity';
	private const PAGE_SLUG    = 'wp-login-activity-settings';

	/**
	 * Constructor — wires the menu + Settings API hooks.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Add the menu item under Settings.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'Login Activity Settings', 'wp-login-activity' ),
			__( 'Login Activity', 'wp-login-activity' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Register the option fields.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function register_settings(): void {
		$options = [
			'wp_login_activity_retention_days'         => 'absint',
			'wp_login_activity_notify_new_location'    => 'absint',
			'wp_login_activity_notify_recipient'       => [ $this, 'sanitize_recipient' ],
			'wp_login_activity_log_failed_logins'      => 'absint',
			'wp_login_activity_log_logouts'            => 'absint',
			'wp_login_activity_log_registrations'      => 'absint',
			'wp_login_activity_log_password_changes'   => 'absint',
		];

		foreach ( $options as $option => $sanitizer ) {
			register_setting( self::OPTION_GROUP, $option, [
				'sanitize_callback' => $sanitizer,
			] );
		}
	}

	/**
	 * Whitelist the recipient mode.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return string
	 */
	public function sanitize_recipient( $value ): string {
		$value = is_string( $value ) ? $value : 'user';

		return in_array( $value, [ 'user', 'admin', 'both' ], true ) ? $value : 'user';
	}

	/**
	 * Render the settings page.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$retention   = (int) get_option( 'wp_login_activity_retention_days', 90 );
		$notify      = (int) get_option( 'wp_login_activity_notify_new_location', 1 );
		$recipient   = (string) get_option( 'wp_login_activity_notify_recipient', 'user' );
		$failed      = (int) get_option( 'wp_login_activity_log_failed_logins', 1 );
		$logouts     = (int) get_option( 'wp_login_activity_log_logouts', 1 );
		$registers   = (int) get_option( 'wp_login_activity_log_registrations', 1 );
		$pwd_changes = (int) get_option( 'wp_login_activity_log_password_changes', 1 );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Login Activity', 'wp-login-activity' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="wp_login_activity_retention_days">
									<?php esc_html_e( 'Retention (days)', 'wp-login-activity' ); ?>
								</label>
							</th>
							<td>
								<input type="number" min="0" max="3650" step="1"
								       id="wp_login_activity_retention_days"
								       name="wp_login_activity_retention_days"
								       value="<?php echo esc_attr( (string) $retention ); ?>"
								       class="small-text"/>
								<p class="description">
									<?php esc_html_e( 'Rows older than this are purged daily. Set to 0 to keep forever.', 'wp-login-activity' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Events to log', 'wp-login-activity' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="checkbox" disabled checked />
										<?php esc_html_e( 'Successful logins (always)', 'wp-login-activity' ); ?>
									</label><br />
									<label>
										<input type="checkbox" name="wp_login_activity_log_failed_logins" value="1" <?php checked( $failed, 1 ); ?> />
										<?php esc_html_e( 'Failed logins', 'wp-login-activity' ); ?>
									</label><br />
									<label>
										<input type="checkbox" name="wp_login_activity_log_logouts" value="1" <?php checked( $logouts, 1 ); ?> />
										<?php esc_html_e( 'Logouts', 'wp-login-activity' ); ?>
									</label><br />
									<label>
										<input type="checkbox" name="wp_login_activity_log_registrations" value="1" <?php checked( $registers, 1 ); ?> />
										<?php esc_html_e( 'New registrations', 'wp-login-activity' ); ?>
									</label><br />
									<label>
										<input type="checkbox" name="wp_login_activity_log_password_changes" value="1" <?php checked( $pwd_changes, 1 ); ?> />
										<?php esc_html_e( 'Password changes (profile edits + lost-password resets)', 'wp-login-activity' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'New-country email alert', 'wp-login-activity' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="wp_login_activity_notify_new_location" value="1" <?php checked( $notify, 1 ); ?> />
									<?php esc_html_e( 'Email when a successful login arrives from a country never seen for that user before.', 'wp-login-activity' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wp_login_activity_notify_recipient"><?php esc_html_e( 'Email recipient', 'wp-login-activity' ); ?></label>
							</th>
							<td>
								<select id="wp_login_activity_notify_recipient" name="wp_login_activity_notify_recipient">
									<option value="user"  <?php selected( $recipient, 'user' ); ?>><?php esc_html_e( 'User who logged in', 'wp-login-activity' ); ?></option>
									<option value="admin" <?php selected( $recipient, 'admin' ); ?>><?php esc_html_e( 'Site admin', 'wp-login-activity' ); ?></option>
									<option value="both"  <?php selected( $recipient, 'both' ); ?>><?php esc_html_e( 'Both', 'wp-login-activity' ); ?></option>
								</select>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

}
