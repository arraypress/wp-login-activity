<?php
/**
 * Admin Password Changed Alert
 *
 * Sends an email to the admin team when an account with the
 * administrator capability has its password changed — through any
 * route (profile edit, lost-password reset). The threat: an attacker
 * who has temporary access (session hijack, leaked password) makes
 * the access permanent by rotating the password to one only they
 * know, locking the legitimate admin out.
 *
 * Critically, the email goes to OTHER admins, NOT the user whose
 * password changed. If the change was an attacker, they already know.
 * If it was the legitimate user, they don't need a "you just changed
 * your password" notification — they did it.
 *
 * Non-admin password changes fall through to silence — those are
 * still in the activity log for audit, just not email-worthy.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Notifications;

defined( 'ABSPATH' ) || exit;

use ArrayPress\WP\LoginActivity\Database\Rows\Activity as ActivityRow;

/**
 * Class AdminPasswordChangedAlert
 *
 * @since 2.0.0
 */
class AdminPasswordChangedAlert {

	/**
	 * Constructor — subscribes to the post-insert action.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_action( 'wp_login_activity_logged', [ $this, 'maybe_notify' ], 20, 2 );
	}

	/**
	 * Decide + dispatch.
	 *
	 * @since 2.0.0
	 *
	 * @param int          $row_id Inserted row ID.
	 * @param ActivityRow  $row    Row model.
	 *
	 * @return void
	 */
	public function maybe_notify( int $row_id, ActivityRow $row ): void {
		if ( ! get_option( 'wp_login_activity_notify_admin_password_changed', 1 ) ) {
			return;
		}

		if ( $row->event_type !== 'password_changed' ) {
			return;
		}

		/** This filter is documented in src/Notifications/AdminAssignedAlert.php */
		if ( ! apply_filters( 'wp_login_activity_should_send_alert', true, 'admin_password_changed', $row ) ) {
			return;
		}

		// Gate on admin capability — skip ordinary subscriber password
		// changes. Use user_can() rather than checking the role list
		// directly because some sites grant `manage_options` outside
		// the canonical administrator role (multisite, custom roles).
		if ( $row->user_id <= 0 || ! user_can( $row->user_id, 'manage_options' ) ) {
			return;
		}

		$recipients = Recipients::for_admins( $row->user_id );
		if ( empty( $recipients ) ) {
			return;
		}

		$subject = $this->build_subject( $row );
		$body    = $this->build_body( $row );
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		foreach ( $recipients as $to ) {
			wp_mail( $to, $subject, $body, $headers );
		}
	}

	/**
	 * Build the subject.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row model.
	 *
	 * @return string
	 */
	private function build_subject( ActivityRow $row ): string {
		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		return sprintf(
			/* translators: 1: admin username, 2: site name */
			__( '[%2$s] Admin password changed for %1$s', 'wp-login-activity' ),
			$row->get_display_name(),
			$site_name
		);
	}

	/**
	 * Build the email body.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row model.
	 *
	 * @return string
	 */
	private function build_body( ActivityRow $row ): string {
		$lines = [
			sprintf(
				/* translators: %s: admin username */
				__( 'The password for the administrator account "%s" has just been changed.', 'wp-login-activity' ),
				esc_html( $row->get_display_name() )
			),
			'',
			sprintf( '<strong>%s:</strong> %s', esc_html__( 'When',    'wp-login-activity' ), esc_html( $row->date_created . ' UTC' ) ),
			sprintf( '<strong>%s:</strong> %s', esc_html__( 'IP',      'wp-login-activity' ), esc_html( $row->ip_address ) ),
			sprintf( '<strong>%s:</strong> %s', esc_html__( 'Country', 'wp-login-activity' ), esc_html( $row->country_code !== '' ? $row->country_code : __( 'Unknown', 'wp-login-activity' ) ) ),
			'',
			__( 'If this change was expected, no action is needed. If not, treat it as a possible compromise: revoke active sessions for the affected admin, rotate other admin passwords, and review recent activity for unfamiliar logins.', 'wp-login-activity' ),
		];

		$body = implode( "<br />\n", $lines );

		/**
		 * Filter the admin-password-changed alert email body.
		 *
		 * @since 2.0.0
		 *
		 * @param string      $body Default body.
		 * @param ActivityRow $row  Row model.
		 */
		return (string) apply_filters( 'wp_login_activity_admin_password_changed_email_body', $body, $row );
	}

}
