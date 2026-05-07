<?php
/**
 * Admin Email Changed Alert
 *
 * Sends an email to the admin team when an administrator-capability
 * account has its email address changed. Threat: an attacker with
 * temporary access (session hijack, leaked password) makes the
 * compromise permanent by switching the recovery email — locking the
 * legitimate user out of password-reset flows.
 *
 * Pairs with AdminPasswordChangedAlert: between them they cover the
 * two routes an attacker uses to "lock in" a takeover (rotate the
 * password, change the recovery email).
 *
 * Same recipient model as the password-changed alert — emails OTHER
 * admins, never the user whose email just changed.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Notifications;

defined( 'ABSPATH' ) || exit;

use ArrayPress\WP\LoginActivity\Database\Rows\Activity as ActivityRow;

/**
 * Class AdminEmailChangedAlert
 *
 * @since 2.0.0
 */
class AdminEmailChangedAlert {

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
		if ( ! get_option( 'wp_login_activity_notify_admin_email_changed', 1 ) ) {
			return;
		}

		if ( $row->event_type !== 'email_changed' ) {
			return;
		}

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
		$user      = get_userdata( $row->user_id );
		$display   = $user ? (string) $user->display_name : '#' . $row->user_id;

		return sprintf(
			/* translators: 1: admin display name, 2: site name */
			__( '[%2$s] Admin email address changed for %1$s', 'wp-login-activity' ),
			$display,
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
		$user    = get_userdata( $row->user_id );
		$display = $user ? (string) $user->display_name : '#' . $row->user_id;

		// `identifier` is stored as "old → new" by the Logger.
		[ $old_email, $new_email ] = array_pad(
			array_map( 'trim', explode( '→', $row->identifier ) ),
			2,
			''
		);

		$lines = [
			sprintf(
				/* translators: %s: admin display name */
				__( 'The email address on the administrator account "%s" has just been changed.', 'wp-login-activity' ),
				esc_html( $display )
			),
			'',
			sprintf( '<strong>%s:</strong> %s', esc_html__( 'Old email', 'wp-login-activity' ), esc_html( $old_email ) ),
			sprintf( '<strong>%s:</strong> %s', esc_html__( 'New email', 'wp-login-activity' ), esc_html( $new_email ) ),
			sprintf( '<strong>%s:</strong> %s', esc_html__( 'When',      'wp-login-activity' ), esc_html( $row->date_created . ' UTC' ) ),
			sprintf( '<strong>%s:</strong> %s', esc_html__( 'IP',        'wp-login-activity' ), esc_html( $row->ip_address ) ),
			sprintf( '<strong>%s:</strong> %s', esc_html__( 'Country',   'wp-login-activity' ), esc_html( $row->country_code !== '' ? $row->country_code : __( 'Unknown', 'wp-login-activity' ) ) ),
			'',
			__( 'If this change was expected, no action is needed. If not, treat it as a possible compromise — an attacker often rotates the recovery email immediately after taking over an account so the legitimate owner can\'t use the password-reset flow to recover access.', 'wp-login-activity' ),
		];

		$body = implode( "<br />\n", $lines );

		/**
		 * Filter the admin-email-changed alert email body.
		 *
		 * @since 2.0.0
		 *
		 * @param string      $body Default body.
		 * @param ActivityRow $row  Row model.
		 */
		return (string) apply_filters( 'wp_login_activity_admin_email_changed_email_body', $body, $row );
	}

}
