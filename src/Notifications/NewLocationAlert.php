<?php
/**
 * New Location Alert
 *
 * Sends an email when a successful login is recorded from a country
 * the user has never logged in from before. Subscribes to the
 * `wp_login_activity_logged` action so the alert is fully decoupled
 * from the Logger — third parties can swap this implementation out
 * by removing the action and adding their own (Slack, SMS, etc.).
 *
 * Recipients are governed by the `wp_login_activity_notify_recipient`
 * setting:
 *   - `user`  — the user who logged in
 *   - `admin` — the site admin email
 *   - `both`  — both addresses
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Notifications;

defined( 'ABSPATH' ) || exit;

use ArrayPress\WP\LoginActivity\Database\Rows\Activity as ActivityRow;
use ArrayPress\WP\LoginActivity\Notifications\Recipients;

/**
 * Class NewLocationAlert
 *
 * @since 2.0.0
 */
class NewLocationAlert {

	/**
	 * Constructor — subscribes to the post-insert action.
	 *
	 * Priority 20 leaves room for filters at lower priorities to
	 * mutate the row metadata before we render it into an email.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_action( 'wp_login_activity_logged', [ $this, 'maybe_notify' ], 20, 2 );
	}

	/**
	 * Decide whether to send + dispatch the email.
	 *
	 * Bails when:
	 *   - Notifications are disabled in settings
	 *   - The row isn't a successful login (failed attempts / logouts /
	 *     registrations don't trigger this alert)
	 *   - The country isn't a "first time" — for resolved users on a
	 *     known country we stay quiet
	 *   - We can't resolve a real WP_User (rare but possible)
	 *
	 * @since 2.0.0
	 *
	 * @param int          $row_id Inserted row ID.
	 * @param ActivityRow  $row    Row model.
	 *
	 * @return void
	 */
	public function maybe_notify( int $row_id, ActivityRow $row ): void {
		if ( ! get_option( 'wp_login_activity_notify_new_location', 1 ) ) {
			return;
		}

		if ( ! $row->is_successful_login() ) {
			return;
		}

		if ( ! $row->is_new_country() ) {
			return;
		}

		/** This filter is documented in src/Notifications/NewLocationAlert.php */
		if ( ! apply_filters( 'wp_login_activity_should_send_alert', true, 'new_location', $row ) ) {
			return;
		}

		$user = get_userdata( $row->user_id );
		if ( ! $user ) {
			return;
		}

		$recipients = Recipients::for_user( (string) $user->user_email );
		if ( empty( $recipients ) ) {
			return;
		}

		$subject = $this->build_subject( $row );
		$body    = $this->build_body( $user, $row );
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
		$country   = $row->country_code !== '' ? $row->country_code : 'Unknown';

		return sprintf(
			/* translators: 1: country code, 2: site name */
			__( '[%2$s] New login from %1$s', 'wp-login-activity' ),
			$country,
			$site_name
		);
	}

	/**
	 * Build the email body.
	 *
	 * Plain HTML — no template engine. The body is also filterable so
	 * sites that want to brand it can swap in their own template.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_User    $user The user that logged in.
	 * @param ActivityRow $row  Row model.
	 *
	 * @return string
	 */
	private function build_body( \WP_User $user, ActivityRow $row ): string {
		$lines = [
			sprintf(
				/* translators: %s: display name */
				__( 'Hello %s,', 'wp-login-activity' ),
				esc_html( (string) $user->display_name )
			),
			'',
			__( 'A new login was recorded for your account from a country we haven\'t seen before:', 'wp-login-activity' ),
			'',
			sprintf( '<strong>%s:</strong> %s',  esc_html__( 'Country',    'wp-login-activity' ), esc_html( $row->country_code !== '' ? $row->country_code : __( 'Unknown', 'wp-login-activity' ) ) ),
			sprintf( '<strong>%s:</strong> %s',  esc_html__( 'IP address', 'wp-login-activity' ), esc_html( $row->ip_address ) ),
			sprintf( '<strong>%s:</strong> %s',  esc_html__( 'When',       'wp-login-activity' ), esc_html( $row->date_created . ' UTC' ) ),
			sprintf( '<strong>%s:</strong> %s',  esc_html__( 'Browser',    'wp-login-activity' ), esc_html( $row->user_agent ) ),
			'',
			__( 'If this was you, no action is needed. If not, change your password and review your active sessions.', 'wp-login-activity' ),
		];

		$body = implode( "<br />\n", $lines );

		/**
		 * Filter the new-location alert email body.
		 *
		 * @since 2.0.0
		 *
		 * @param string      $body Default rendered body.
		 * @param \WP_User    $user The user.
		 * @param ActivityRow $row  Row model.
		 */
		return (string) apply_filters( 'wp_login_activity_new_location_email_body', $body, $user, $row );
	}

}
