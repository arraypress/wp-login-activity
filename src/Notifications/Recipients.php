<?php
/**
 * Recipient Resolution
 *
 * Centralises "who should receive this email" logic for the
 * notification listeners. Two recipient models exist:
 *
 *   1. Per-user — for events that affect ONE user (a login from a
 *      new country). Honours the `wp_login_activity_notify_recipient`
 *      setting (user / admin / both). Used by NewLocationAlert.
 *
 *   2. Admin team — for events that represent a SITE-LEVEL security
 *      signal (admin role assigned, admin password changed). Always
 *      goes to every administrator with a manage_options capability,
 *      optionally excluding the user the event is about (sending the
 *      "your role changed!" email to the role-changed user is noise
 *      at best, attacker-aiding at worst).
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Class Recipients
 *
 * @since 2.0.0
 */
class Recipients {

	/**
	 * Resolve recipients for the per-user model.
	 *
	 * @since 2.0.0
	 *
	 * @param string $user_email Subject user's email.
	 *
	 * @return string[] Deduped, non-empty addresses.
	 */
	public static function for_user( string $user_email ): array {
		$mode       = (string) get_option( 'wp_login_activity_notify_recipient', 'user' );
		$recipients = [];

		if ( in_array( $mode, [ 'user', 'both' ], true ) && $user_email !== '' ) {
			$recipients[] = $user_email;
		}

		if ( in_array( $mode, [ 'admin', 'both' ], true ) ) {
			$admin = (string) get_option( 'admin_email' );
			if ( $admin !== '' ) {
				$recipients[] = $admin;
			}
		}

		$recipients = array_values( array_unique( array_filter( $recipients ) ) );

		/**
		 * Filter the per-user-alert recipient list.
		 *
		 * Lets integrators add a SIEM ingest address, route based on
		 * the alert type, or drop recipients entirely.
		 *
		 * @since 2.0.0
		 *
		 * @param string[] $recipients Default recipient list.
		 * @param string   $context    'user' — the recipient model.
		 */
		return (array) apply_filters( 'wp_login_activity_alert_recipients', $recipients, 'user' );
	}

	/**
	 * Resolve recipients for the admin-team model — every site
	 * administrator, plus the canonical `admin_email` option (in case
	 * it's a forwarding alias that doesn't resolve to an actual WP
	 * account).
	 *
	 * @since 2.0.0
	 *
	 * @param int $exclude_user_id Optional user ID to omit (typically
	 *                             the subject of the alert, when telling
	 *                             them about the event would be redundant
	 *                             or counter-productive).
	 *
	 * @return string[] Deduped, non-empty addresses.
	 */
	public static function for_admins( int $exclude_user_id = 0 ): array {
		$recipients = [];

		// `admin_email` first — it's the canonical "site owner" address
		// and may differ from any individual administrator account.
		$admin_email = (string) get_option( 'admin_email' );
		if ( $admin_email !== '' ) {
			$recipients[] = $admin_email;
		}

		// Then every WP user with the administrator role. We restrict
		// to a sane upper bound (`number => 50`) so a hijacked site
		// with 10k spam-admin accounts doesn't email-blast on every
		// alert event.
		$admins = get_users( [
			'role'   => 'administrator',
			'fields' => [ 'ID', 'user_email' ],
			'number' => 50,
		] );

		foreach ( $admins as $admin ) {
			if ( $exclude_user_id > 0 && (int) $admin->ID === $exclude_user_id ) {
				continue;
			}
			if ( ! empty( $admin->user_email ) ) {
				$recipients[] = (string) $admin->user_email;
			}
		}

		$recipients = array_values( array_unique( array_filter( $recipients ) ) );

		/** This filter is documented in src/Notifications/Recipients.php */
		return (array) apply_filters( 'wp_login_activity_alert_recipients', $recipients, 'admins' );
	}

}
