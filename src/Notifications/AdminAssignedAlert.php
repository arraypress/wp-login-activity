<?php
/**
 * Admin Assigned Alert
 *
 * Sends an email to every site administrator when the `administrator`
 * role is assigned to a user — whether they're brand new or a
 * promoted existing account. Both routes are the textbook "site has
 * been compromised" indicator: an attacker who got code-execution or
 * a password gets persistent control by adding their own admin.
 *
 * Subscribes to `wp_login_activity_logged` rather than the
 * `set_user_role` hook directly, so the email rides on the same
 * activity row + audit trail as everything else — admins receiving
 * the alert can click straight to the row in the activity table.
 *
 * The recipient list deliberately excludes the user the role was
 * assigned TO — telling them "you got admin!" is at best noise and
 * at worst gives the attacker a confirmation email if they have
 * inbox access.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Notifications;

defined( 'ABSPATH' ) || exit;

use ArrayPress\WP\LoginActivity\Database\Rows\Activity as ActivityRow;

/**
 * Class AdminAssignedAlert
 *
 * @since 2.0.0
 */
class AdminAssignedAlert {

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
		if ( ! get_option( 'wp_login_activity_notify_admin_assigned', 1 ) ) {
			return;
		}

		if ( $row->event_type !== 'admin_assigned' ) {
			return;
		}

		/**
		 * Filter whether an alert email should actually be sent for
		 * this row. Lets plugins suppress alerts in maintenance
		 * windows, during scripted rollouts, when forwarding to a
		 * SIEM that already covers the channel, etc.
		 *
		 * @since 2.0.0
		 *
		 * @param bool         $send       Default true.
		 * @param string       $alert_type Slug — one of: new_location,
		 *                                 admin_assigned,
		 *                                 admin_password_changed,
		 *                                 admin_email_changed,
		 *                                 failed_login_burst.
		 * @param ActivityRow  $row        Row context.
		 */
		if ( ! apply_filters( 'wp_login_activity_should_send_alert', true, 'admin_assigned', $row ) ) {
			return;
		}

		// Exclude the just-promoted user from the recipient list — they
		// shouldn't be the first to know they're suspected, and if the
		// account is attacker-controlled the attacker gets a "your
		// alert just fired" confirmation.
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
	 * Build the subject line.
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
			/* translators: 1: username assigned the admin role, 2: site name */
			__( '[%2$s] Administrator role assigned to %1$s', 'wp-login-activity' ),
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
			__( 'A user has just been granted the administrator role on this site:', 'wp-login-activity' ),
			'',
			sprintf( '<strong>%s:</strong> %s', esc_html__( 'User',    'wp-login-activity' ), esc_html( $row->get_display_name() ) ),
			sprintf( '<strong>%s:</strong> %s', esc_html__( 'When',    'wp-login-activity' ), esc_html( $row->date_created . ' UTC' ) ),
			sprintf( '<strong>%s:</strong> %s', esc_html__( 'IP',      'wp-login-activity' ), esc_html( $row->ip_address ) ),
			sprintf( '<strong>%s:</strong> %s', esc_html__( 'Country', 'wp-login-activity' ), esc_html( $row->country_code !== '' ? $row->country_code : __( 'Unknown', 'wp-login-activity' ) ) ),
			'',
			__( 'If you were not expecting this change, treat it as a possible site compromise: review the user\'s activity, change other admin passwords, and check for unfamiliar plugins.', 'wp-login-activity' ),
		];

		$body = implode( "<br />\n", $lines );

		/**
		 * Filter the admin-assigned alert email body.
		 *
		 * @since 2.0.0
		 *
		 * @param string      $body Default body.
		 * @param ActivityRow $row  Row model.
		 */
		return (string) apply_filters( 'wp_login_activity_admin_assigned_email_body', $body, $row );
	}

}
