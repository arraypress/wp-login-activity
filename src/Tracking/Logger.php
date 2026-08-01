<?php
/**
 * Tracking Logger
 *
 * Listens on WordPress authentication hooks and writes one row per
 * event to the activity table. Country / IP / UA detection is
 * delegated to `arraypress/visitor-country` so this class stays focused
 * on event resolution + persistence rather than re-implementing every
 * forwarded-IP-header dance and country-list-of-the-week.
 *
 * Emits a single action after each successful insert:
 *   `wp_login_activity_logged` ( int $row_id, Activity $row )
 *
 * Email alerts, audit forwarders, and any other reaction subscribe
 * to that action — this class doesn't know they exist.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Tracking;

defined( 'ABSPATH' ) || exit;

use ArrayPress\WP\LoginActivity\Plugin;
use ArrayPress\WP\LoginActivity\Database\Rows\Activity as ActivityRow;
use ArrayPress\VisitorCountry\Country as VisitorCountry;
use ArrayPress\IPUtils\IP;
use ArrayPress\UserAgentUtils\UserAgent;
use WP_User;

/**
 * Class Logger
 *
 * @since 2.0.0
 */
class Logger {

	/**
	 * Constructor — wires WordPress hooks.
	 *
	 * Each hook handler is gated by an option so admins can disable
	 * categories of events (failed logins, logouts, registrations,
	 * password changes) without unloading the plugin.
	 *
	 * Password-change detection runs on TWO hooks:
	 *   - `profile_update` — admin/user changed their password on the
	 *     edit-profile screen. Receives `$old_user_data` so we diff.
	 *   - `password_reset` — user reset via the "lost password" flow.
	 *     Always represents a password change, no diff needed.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		// `wp_login_failed` fires with 2 args since WP 5.4 (username +
		// the WP_Error). We accept both even though we only use the
		// username — registering args=1 in strict-types mode means
		// PHP type-coerces any unexpected input shape, and silent
		// hook-handler exceptions in WP get swallowed before they
		// reach error_log on most setups.
		add_action( 'wp_login',             [ $this, 'on_login' ], 10, 2 );
		add_action( 'wp_login_failed',      [ $this, 'on_login_failed' ], 10, 2 );
		add_action( 'wp_logout',            [ $this, 'on_logout' ], 10, 1 );
		add_action( 'user_register',        [ $this, 'on_user_register' ], 10, 1 );
		add_action( 'profile_update',       [ $this, 'on_profile_update' ], 10, 2 );
		// `after_password_reset` fires AFTER wp_set_password commits;
		// `password_reset` fires BEFORE. Hook only the post-commit
		// action so the audit-log row reflects a state that's
		// actually true. Hooking both would double-record.
		add_action( 'after_password_reset', [ $this, 'on_password_reset' ], 10, 1 );
		add_action( 'set_user_role',        [ $this, 'on_set_user_role' ], 10, 3 );
	}

	/**
	 * Hook-handler signatures intentionally take untyped parameters
	 * and cast/validate inside the body.
	 *
	 * Why: this file is `declare(strict_types=1)`, which combined with
	 * `string`/`int` parameter type hints means PHP throws TypeError
	 * if WP passes anything unexpected (null, mixed-type, etc.). WP's
	 * `do_action` doesn't propagate handler exceptions in a visible
	 * way — the error gets swallowed and the row never logs, with no
	 * obvious symptom for the admin to debug. Defensive casting inside
	 * the handler keeps the recording path resilient to whatever shape
	 * the action is fired with (now or in a future WP release).
	 */

	/**
	 * Successful login.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $user_login Login name (username) — usually a string.
	 * @param mixed $user       The authenticated user — usually WP_User.
	 *
	 * @return void
	 */
	public function on_login( $user_login, $user ): void {
		$user_id    = $user instanceof WP_User ? (int) $user->ID : 0;
		$identifier = is_string( $user_login ) ? $user_login : '';

		$this->record( 'login', $user_id, $identifier );
	}

	/**
	 * Failed login.
	 *
	 * `wp_login_failed` fires (since WP 5.4) with the typed username
	 * AND a WP_Error describing the failure. We only use the username
	 * but accept both args so the registration matches WP's signature
	 * exactly.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $username The submitted username/email.
	 * @param mixed $error    WP_Error with the failure details (unused).
	 *
	 * @return void
	 */
	public function on_login_failed( $username, $error = null ): void {
		self::debug_log( 'on_login_failed: hook fired, username=' . var_export( $username, true ) );

		if ( ! get_option( 'wp_login_activity_log_failed_logins', 1 ) ) {
			self::debug_log( 'on_login_failed: bailing — wp_login_activity_log_failed_logins option is off' );
			return;
		}

		$username = is_string( $username ) ? $username : '';

		$user    = $username !== ''
			? ( get_user_by( 'login', $username ) ?: get_user_by( 'email', $username ) )
			: false;
		$user_id = $user instanceof WP_User ? (int) $user->ID : 0;

		self::debug_log( "on_login_failed: about to record — user_id=$user_id identifier=$username" );

		$this->record( 'login_failed', $user_id, $username );
	}

	/**
	 * Logout. WP fires `wp_logout` with the user_id (since 5.5).
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $user_id The user that logged out.
	 *
	 * @return void
	 */
	public function on_logout( $user_id ): void {
		if ( ! get_option( 'wp_login_activity_log_logouts', 1 ) ) {
			return;
		}

		$user_id    = (int) $user_id;
		$user       = $user_id > 0 ? get_userdata( $user_id ) : false;
		$identifier = $user ? (string) $user->user_login : '';

		$this->record( 'logout', $user_id, $identifier );
	}

	/**
	 * New user registered.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $user_id The new user's ID.
	 *
	 * @return void
	 */
	public function on_user_register( $user_id ): void {
		if ( ! get_option( 'wp_login_activity_log_registrations', 1 ) ) {
			return;
		}

		$user_id    = (int) $user_id;
		$user       = $user_id > 0 ? get_userdata( $user_id ) : false;
		$identifier = $user ? (string) $user->user_login : '';

		$this->record( 'registered', $user_id, $identifier );
	}

	/**
	 * Profile updated — diff against the pre-update user data to log
	 * only meaningful changes (password rotation, email change). Each
	 * change is gated by its own settings toggle and emits its own
	 * event slug; both can fire from the same save (admin changes
	 * password AND email at once).
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $user_id       The user that was updated.
	 * @param mixed $old_user_data Pre-update user object (WP_User).
	 *
	 * @return void
	 */
	public function on_profile_update( $user_id, $old_user_data ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! is_object( $old_user_data ) ) {
			return;
		}

		// Direct DB read for the NEW user row — bypasses WP's user-
		// data cache, which can still hold the pre-update row when
		// `profile_update` fires (the cache cleanup in wp_insert_user
		// happens around the same point as the action and the order
		// has shifted between WP releases). `get_userdata()` was
		// returning the stale pre-update row here, so the password +
		// email comparisons saw OLD === OLD and never recorded the
		// event. Reading the wp_users table directly is the cheapest
		// way to guarantee we see post-update values.
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT user_login, user_pass, user_email FROM {$wpdb->users} WHERE ID = %d",
			$user_id
		) );

		if ( ! $row ) {
			return;
		}

		// Password change — hashed string changes byte-for-byte even
		// when plaintext is the same (new bcrypt salt), so literal
		// `!==` is the right test.
		if (
			get_option( 'wp_login_activity_log_password_changes', 1 )
			&& isset( $old_user_data->user_pass )
			&& (string) $row->user_pass !== (string) $old_user_data->user_pass
		) {
			$this->record( 'password_changed', $user_id, (string) $row->user_login );
		}

		// Email change — case-insensitive comparison so a re-save with
		// different casing (rare but possible) doesn't generate noise.
		if (
			get_option( 'wp_login_activity_log_email_changes', 1 )
			&& isset( $old_user_data->user_email )
			&& strtolower( (string) $row->user_email ) !== strtolower( (string) $old_user_data->user_email )
		) {
			// Identifier captures the OLD → NEW email transition so
			// the admin alert can surface "user X switched from
			// foo@old to bar@new" without joining tables.
			$identifier = sprintf( '%s → %s', $old_user_data->user_email, $row->user_email );
			$this->record( 'email_changed', $user_id, $identifier );
		}
	}

	/**
	 * Password reset via the lost-password flow.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $user The user whose password was reset (WP_User).
	 *
	 * @return void
	 */
	public function on_password_reset( $user ): void {
		if ( ! get_option( 'wp_login_activity_log_password_changes', 1 ) ) {
			return;
		}

		if ( ! is_object( $user ) ) {
			return;
		}

		$user_id    = isset( $user->ID ) ? (int) $user->ID : 0;
		$identifier = isset( $user->user_login ) ? (string) $user->user_login : '';

		if ( $user_id <= 0 ) {
			return;
		}

		$this->record( 'password_changed', $user_id, $identifier );
	}

	/**
	 * User role changed — log only when the new role is administrator.
	 *
	 * Captures both "new user created as admin" (old_roles empty) and
	 * "existing user promoted to admin" (old_roles non-empty). Both
	 * routes carry the same threat profile — an unexpected admin
	 * appearing on the site — so we collapse them into a single
	 * `admin_assigned` event slug.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $user_id   The user being assigned the role.
	 * @param mixed $role      The role slug being set.
	 * @param mixed $old_roles Roles the user had before the change.
	 *
	 * @return void
	 */
	public function on_set_user_role( $user_id, $role, $old_roles = [] ): void {
		$role = is_string( $role ) ? $role : '';
		if ( $role !== 'administrator' ) {
			return;
		}

		$old_roles = is_array( $old_roles ) ? $old_roles : [];

		// Skip if they were ALREADY an admin — a no-op role-set on the
		// same admin user shouldn't generate noise (e.g. profile saves
		// that re-assert the role without changing it).
		if ( in_array( 'administrator', $old_roles, true ) ) {
			return;
		}

		$user_id    = (int) $user_id;
		$user       = $user_id > 0 ? get_userdata( $user_id ) : false;
		$identifier = $user ? (string) $user->user_login : '';

		$this->record( 'admin_assigned', $user_id, $identifier );
	}

	/**
	 * Write a diagnostic line to the WordPress debug log.
	 *
	 * Gated on `WP_DEBUG_LOG` so production sites without debug
	 * logging on don't accumulate noise. Used during development /
	 * support to trace why a particular event didn't record.
	 *
	 * @since 2.0.0
	 *
	 * @param string $message Log line.
	 *
	 * @return void
	 */
	public static function debug_log( string $message ): void {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[wp-login-activity] ' . $message );
	}

	/**
	 * Insert a row + fire the post-insert action.
	 *
	 * @since 2.0.0
	 *
	 * @param string $event_type One of: login, login_failed, logout, registered.
	 * @param int    $user_id    Resolved user ID, or 0 if unresolved.
	 * @param string $identifier Free-text identifier (username / email / typed value).
	 *
	 * @return void
	 */
	private function record( string $event_type, int $user_id, string $identifier ): void {
		$ip      = $this->resolve_ip();
		$country = $this->resolve_country();

		$data = [
			'user_id'            => $user_id,
			'identifier'         => $identifier,
			'event_type'         => $event_type,
			'ip_address'         => $ip,
			'country_code'       => $country['code'],
			'country_source'     => $country['source'],
			'user_agent'         => $this->resolve_user_agent(),
			'referer'            => $this->resolve_referer(),
			'actor_user_id'      => $this->resolve_actor_user_id( $user_id, $event_type ),
			'user_role'          => $this->resolve_subject_role( $user_id ),
			'session_token_hash' => $this->resolve_session_token_hash( $event_type ),
			'accept_language'    => $this->resolve_accept_language(),
			'is_new_country'     => $this->is_first_time_country( $user_id, $country['code'] ) ? 1 : 0,
			'date_created'       => current_time( 'mysql', true ),
		];

		/**
		 * Filter the row data before insert.
		 *
		 * Return an array to mutate the row (e.g. add a custom field
		 * to a meta column, redact the user_agent for a privacy-strict
		 * site). Return `false` from this filter to skip writing the
		 * row entirely — useful for "don't log MY logins" rules,
		 * maintenance windows, or capping ingest rate during
		 * brute-force events.
		 *
		 * @since 2.0.0
		 *
		 * @param array|false $data        Row data, or false to skip insert.
		 * @param string      $event_type  Event slug.
		 * @param int         $user_id     Subject user ID (0 if unresolved).
		 * @param string      $identifier  Free-text identifier captured from the request.
		 */
		$data = apply_filters( 'wp_login_activity_pre_insert_data', $data, $event_type, $user_id, $identifier );

		if ( $data === false || ! is_array( $data ) ) {
			return;
		}

		$row_id = Plugin::query()->add_item( $data );

		if ( ! $row_id ) {
			global $wpdb;
			self::debug_log(
				'record: add_item returned falsy for event_type=' . $event_type
				. ' user_id=' . $user_id
				. ( ! empty( $wpdb->last_error ) ? ' wpdb_last_error=' . $wpdb->last_error : '' )
			);
			return;
		}

		self::debug_log( "record: inserted row_id=$row_id event_type=$event_type" );

		$row = Plugin::query()->get_item( $row_id );

		if ( ! $row instanceof ActivityRow ) {
			return;
		}

		/**
		 * Fires after an activity row has been written.
		 *
		 * Subscribers: `Notifications\NewLocationAlert` (email on new
		 * country), and any third-party listener that wants to forward
		 * to a SIEM, Slack, etc.
		 *
		 * @since 2.0.0
		 *
		 * @param int          $row_id Inserted row ID.
		 * @param ActivityRow  $row    The inserted row model.
		 */
		do_action( 'wp_login_activity_logged', (int) $row_id, $row );
	}

	/**
	 * Get the visitor's IP address.
	 *
	 * Delegated entirely to wp-ip-utils, which reads forwarding headers
	 * only when the request actually arrived from a trusted proxy, and
	 * walks `X-Forwarded-For` from the right-hand end.
	 *
	 * `$allow_private` keeps loopback and RFC 1918 addresses rather than
	 * discarding them — on local dev, an intranet, or in Docker, "logged
	 * in from 192.168.1.50" is exactly the audit data we want. Filter
	 * `wp_login_activity_allow_private_ip` to false for the stricter
	 * behaviour.
	 *
	 * This used to fall back to its own header cascade when the strict
	 * pass returned nothing, which meant walking CF-Connecting-IP /
	 * X-Real-IP / Client-IP / X-Forwarded-For with no trust check and
	 * taking the leftmost entry — the one part of that header a client
	 * controls. Anyone could then make a failed login appear to come from
	 * any address they chose, which in an audit log is worse than having
	 * no address at all. See wp-ip-utils 1.1.0 and 1.1.1.
	 *
	 * @since 2.0.0
	 * @since 2.0.1 Trust-gated; the inline fallback cascade was removed.
	 *
	 * @return string
	 */
	private function resolve_ip(): string {
		$allow_private = (bool) apply_filters( 'wp_login_activity_allow_private_ip', true );

		$ip = (string) ( IP::get( $allow_private ) ?? '' );

		return (string) apply_filters( 'wp_login_activity_visitor_ip', $ip );
	}


	/**
	 * Resolve the country for the current request via visitor-country.
	 *
	 * Returns `[ 'code' => 'US', 'source' => 'cloudflare'|'cloudfront'|… ]`.
	 * The source tag is recorded so admins debugging "wrong country"
	 * reports can see whether the value came from a CDN edge header
	 * or from a server-level GeoIP module.
	 *
	 * @since 2.0.0
	 *
	 * @return array{code: string, source: string}
	 */
	private function resolve_country(): array {
		if ( ! class_exists( VisitorCountry::class ) ) {
			return [ 'code' => '', 'source' => '' ];
		}

		$result = VisitorCountry::resolve_detailed();

		return [
			'code'   => strtoupper( (string) $result->get_country() ),
			'source' => (string) $result->get_source(),
		];
	}

	/**
	 * Get the raw User-Agent header.
	 *
	 * **Always store the raw UA string** — formatting (browser/OS
	 * extraction, "Chrome on Windows" labels) happens at render time
	 * via wp-user-agent-utils. Storing pre-formatted values would
	 * lock us into one parser version forever AND lose information
	 * the bot/device helpers rely on.
	 *
	 * Trimmed to 1024 chars to defend against a few-MB UA poisoning
	 * a row, sanitised against invalid UTF-8 so the column doesn't
	 * blow up on `text` insert.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	private function resolve_user_agent(): string {
		$ua = (string) UserAgent::get();

		if ( $ua === '' ) {
			return '';
		}

		return mb_substr( (string) wp_check_invalid_utf8( $ua ), 0, 1024 );
	}

	/**
	 * Get the HTTP referer header. Trimmed to a sane length to defend
	 * against poison data and capped at the column size.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	private function resolve_referer(): string {
		if ( empty( $_SERVER['HTTP_REFERER'] ) ) {
			return '';
		}

		$ref = wp_check_invalid_utf8( (string) $_SERVER['HTTP_REFERER'] );

		// esc_url_raw rejects garbage and returns '' on invalid URLs.
		// We don't reject empty results — an event without a referer
		// is itself useful audit data ("logged in directly, not from
		// the login page").
		$ref = esc_url_raw( $ref );

		return mb_substr( (string) $ref, 0, 500 );
	}

	/**
	 * Resolve the actor — who PERFORMED the action.
	 *
	 * For self-driven events (login / logout / failed-login / register
	 * / password-reset via lost-password flow), the subject IS the
	 * actor. For admin-driven events (admin promotes a user, admin
	 * changes another user's password) `wp_get_current_user()` at
	 * write time IS the actor.
	 *
	 * Returns 0 for events with no resolvable user context (anonymous
	 * failed-login attempts, registration when there's no logged-in
	 * actor).
	 *
	 * @since 2.0.0
	 *
	 * @param int    $subject_user_id The user the event is ABOUT.
	 * @param string $event_type      The event slug.
	 *
	 * @return int
	 */
	private function resolve_actor_user_id( int $subject_user_id, string $event_type ): int {
		// Login / logout / failed-login don't have a separate actor —
		// the subject IS the actor (or there isn't one for failed
		// logins). Skip the wp_get_current_user() lookup entirely.
		if ( in_array( $event_type, [ 'login', 'logout', 'login_failed', 'registered' ], true ) ) {
			return $subject_user_id;
		}

		$actor_id = (int) get_current_user_id();

		// Profile self-edits return the same ID either way.
		return $actor_id > 0 ? $actor_id : $subject_user_id;
	}

	/**
	 * Snapshot the subject user's primary role at write time so a
	 * later role change (or user deletion) doesn't rewrite history.
	 *
	 * Returns the FIRST role on the user — multi-role users are
	 * comparatively rare and the column is sized for a single slug.
	 * Sites that genuinely need multi-role audit can add a second
	 * column or switch the column to TEXT.
	 *
	 * @since 2.0.0
	 *
	 * @param int $user_id The subject user.
	 *
	 * @return string
	 */
	private function resolve_subject_role( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->roles ) || ! is_array( $user->roles ) ) {
			return '';
		}

		return (string) reset( $user->roles );
	}

	/**
	 * Capture the visitor's Accept-Language header.
	 *
	 * Stored RAW so future parser logic can derive richer signals
	 * from historical rows. Capped at 100 chars (matches the column
	 * size) — typical real-world values are under 80; the cap is
	 * defence against poison data.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	private function resolve_accept_language(): string {
		if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			return '';
		}

		$lang = wp_check_invalid_utf8( (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] );

		// Strip control characters / newlines defensively. The header
		// field shouldn't contain them, but a malicious client can
		// set anything.
		$lang = preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $lang );

		return mb_substr( (string) $lang, 0, 100 );
	}

	/**
	 * Hash the WP session token for the current request, so the
	 * "current session" indicator in the UI can match against it
	 * later. Only meaningful for `login` events — other events
	 * inherit their session from whatever the actor was already
	 * authenticated as, which would make the matching ambiguous.
	 *
	 * Hashed with SHA-256 — the raw token is a credential equivalent
	 * to a password (anyone holding it can ride the user's session),
	 * so storing it plain in the DB would be a credential leak waiting
	 * for a backup-dump compromise.
	 *
	 * @since 2.0.0
	 *
	 * @param string $event_type The event slug.
	 *
	 * @return string Empty when we shouldn't / can't capture.
	 */
	private function resolve_session_token_hash( string $event_type ): string {
		if ( $event_type !== 'login' ) {
			return '';
		}

		if ( ! function_exists( 'wp_get_session_token' ) ) {
			return '';
		}

		$token = (string) wp_get_session_token();
		if ( $token === '' ) {
			return '';
		}

		return hash( 'sha256', $token );
	}

	/**
	 * Is this the first time we've seen this `(user_id, country)` pair?
	 *
	 * Pre-computed at insert time so the read path doesn't re-scan
	 * history on every page-load. We only flag novelty for resolved
	 * users (user_id > 0) and meaningful country codes — everything
	 * else short-circuits to false.
	 *
	 * Excludes failed-login rows from the lookup so a fraudster can't
	 * suppress the "new country" flag by trying-and-failing first.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $user_id      Resolved user ID.
	 * @param string $country_code ISO-3166 alpha-2 code.
	 *
	 * @return bool
	 */
	private function is_first_time_country( int $user_id, string $country_code ): bool {
		if ( $user_id <= 0 || $country_code === '' ) {
			return false;
		}

		$existing = Plugin::query()->query( [
			'user_id'      => $user_id,
			'country_code' => $country_code,
			'event_type'   => 'login',
			'fields'       => 'ids',
			'number'       => 1,
		] );

		return empty( $existing );
	}

}
