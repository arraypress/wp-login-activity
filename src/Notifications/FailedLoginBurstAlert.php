<?php
/**
 * Failed Login Burst Alert
 *
 * Sends an email when failed-login attempts against a single
 * identifier exceed a threshold within a short window — the textbook
 * brute-force / credential-stuffing signature. Tuned conservatively
 * by default (5 attempts in 10 minutes) and DISABLED by default
 * because every site has a different noise floor — admins opt in
 * after deciding they want this signal.
 *
 * Two threats this catches:
 *   - Targeted brute force against a known username.
 *   - Credential-stuffing where a dump-buyer tries known emails.
 *
 * Subscribes to `wp_login_activity_logged` for `login_failed` events.
 * Counts attempts via the wpla_count_failed_logins() helper, which
 * reads the activity table directly — so the burst alert observes
 * EVERY failed login, including ones that arrive under the threshold
 * by themselves but contribute to the rolling window.
 *
 * Dedupe: once we fire for a given identifier we suppress further
 * emails for an hour via a transient. Without this, a sustained
 * brute-force generates one alert per attempt — admins would unsubscribe
 * after the first wave. The activity log still captures each attempt;
 * the suppression is on the email side only.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Notifications;

defined( 'ABSPATH' ) || exit;

use ArrayPress\WP\LoginActivity\Database\Rows\Activity as ActivityRow;

/**
 * Class FailedLoginBurstAlert
 *
 * @since 2.0.0
 */
class FailedLoginBurstAlert {

	/**
	 * Default detection threshold + window. Both filterable, both
	 * conservative — burst-alert false positives erode trust in the
	 * signal fast.
	 *
	 * @since 2.0.0
	 */
	private const DEFAULT_THRESHOLD       = 5;
	private const DEFAULT_WINDOW_MINUTES  = 10;
	private const DEDUPE_TRANSIENT_PREFIX = 'wpla_burst_';
	private const DEDUPE_TTL              = HOUR_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_action( 'wp_login_activity_logged', [ $this, 'maybe_notify' ], 20, 2 );
	}

	/**
	 * Inspect the row + decide.
	 *
	 * @since 2.0.0
	 *
	 * @param int          $row_id Inserted row ID.
	 * @param ActivityRow  $row    Row model.
	 *
	 * @return void
	 */
	public function maybe_notify( int $row_id, ActivityRow $row ): void {
		if ( ! get_option( 'wp_login_activity_notify_failed_burst', 0 ) ) {
			return;
		}

		if ( $row->event_type !== 'login_failed' ) {
			return;
		}

		$identifier = trim( $row->identifier );
		if ( $identifier === '' ) {
			return;
		}

		$threshold = (int) apply_filters( 'wp_login_activity_failed_burst_threshold', self::DEFAULT_THRESHOLD );
		$window    = (int) apply_filters( 'wp_login_activity_failed_burst_window_minutes', self::DEFAULT_WINDOW_MINUTES );

		$threshold = max( 2, $threshold );  // 1 attempt isn't a burst
		$window    = max( 1, $window );

		$count = function_exists( 'wpla_count_failed_logins' )
			? (int) wpla_count_failed_logins( $identifier, 'identifier', $window )
			: 0;

		if ( $count < $threshold ) {
			return;
		}

		// Dedupe — short-circuit if we've already alerted for this
		// identifier within the dedupe TTL. Hash the key so an
		// identifier with weird characters (UTF-8 emoji, spaces) still
		// produces a valid transient name.
		$dedupe_key = self::DEDUPE_TRANSIENT_PREFIX . md5( strtolower( $identifier ) );
		if ( get_transient( $dedupe_key ) ) {
			return;
		}

		$recipients = Recipients::for_admins();
		if ( empty( $recipients ) ) {
			return;
		}

		set_transient( $dedupe_key, time(), self::DEDUPE_TTL );

		$subject = $this->build_subject( $identifier, $count, $window );
		$body    = $this->build_body( $row, $identifier, $count, $window );
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
	 * @param string $identifier Username/email targeted.
	 * @param int    $count      How many failed attempts.
	 * @param int    $window     Window size in minutes.
	 *
	 * @return string
	 */
	private function build_subject( string $identifier, int $count, int $window ): string {
		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		return sprintf(
			/* translators: 1: count, 2: identifier, 3: window minutes, 4: site */
			__( '[%4$s] %1$d failed login attempts on "%2$s" in %3$d minutes', 'wp-login-activity' ),
			$count,
			$identifier,
			$window,
			$site_name
		);
	}

	/**
	 * Build the body.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row        Triggering row.
	 * @param string      $identifier Identifier targeted.
	 * @param int         $count      Failure count.
	 * @param int         $window     Window in minutes.
	 *
	 * @return string
	 */
	private function build_body( ActivityRow $row, string $identifier, int $count, int $window ): string {
		$lines = [
			sprintf(
				/* translators: 1: count, 2: identifier, 3: window */
				__( 'There have been %1$d failed login attempts targeting "%2$s" in the last %3$d minutes — a brute-force or credential-stuffing pattern.', 'wp-login-activity' ),
				$count,
				esc_html( $identifier ),
				$window
			),
			'',
			sprintf( '<strong>%s:</strong> %s', esc_html__( 'Most recent attempt', 'wp-login-activity' ), esc_html( $row->date_created . ' UTC' ) ),
			sprintf( '<strong>%s:</strong> %s', esc_html__( 'Most recent IP',      'wp-login-activity' ), esc_html( $row->ip_address ) ),
			sprintf( '<strong>%s:</strong> %s', esc_html__( 'Most recent country', 'wp-login-activity' ), esc_html( $row->country_code !== '' ? $row->country_code : __( 'Unknown', 'wp-login-activity' ) ) ),
			'',
			__( 'Recommended response:', 'wp-login-activity' ),
			__( '— If the identifier matches a real account, force a password reset on it.', 'wp-login-activity' ),
			__( '— Block the source IP at the firewall if it persists.', 'wp-login-activity' ),
			__( '— Consider enabling 2FA / a passkey on any high-value accounts.', 'wp-login-activity' ),
			'',
			sprintf(
				/* translators: %d: dedupe-window minutes (always 60) */
				__( 'Further alerts for the same identifier are suppressed for %d minutes to avoid spam during a sustained attack. Every attempt is still recorded in the activity log.', 'wp-login-activity' ),
				(int) ( self::DEDUPE_TTL / MINUTE_IN_SECONDS )
			),
		];

		$body = implode( "<br />\n", $lines );

		/**
		 * Filter the failed-login-burst alert email body.
		 *
		 * @since 2.0.0
		 *
		 * @param string      $body       Default body.
		 * @param ActivityRow $row        Triggering row.
		 * @param string      $identifier Identifier targeted.
		 * @param int         $count      Failure count.
		 * @param int         $window     Window in minutes.
		 */
		return (string) apply_filters( 'wp_login_activity_failed_burst_email_body', $body, $row, $identifier, $count, $window );
	}

}
