<?php
/**
 * Activity Row
 *
 * Per-row model returned by the Query class. Holds the raw column
 * values from BerlinDB and provides typed getters so callers don't
 * touch property names directly. Display-only derivations (parsed
 * user-agent string, country flag emoji) live on the row so the
 * computation happens once per row when accessed.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Database\Rows;

defined( 'ABSPATH' ) || exit;

use BerlinDB\Database\Row;
use ArrayPress\UserAgentUtils\UserAgent;
use ArrayPress\IPUtils\IP;

/**
 * Class Activity
 *
 * @since 2.0.0
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $identifier
 * @property string $event_type
 * @property string $ip_address
 * @property string $country_code
 * @property string $country_source
 * @property string $user_agent
 * @property string $referer
 * @property int    $actor_user_id
 * @property string $user_role
 * @property string $session_token_hash
 * @property int    $is_new_country
 * @property string $date_created
 * @property string $uuid
 */
class Activity extends Row {

	/**
	 * Constructor — coerces raw DB string values to their typed PHP
	 * equivalents. Anything missing from the input falls back to a
	 * sensible default so consumer code never sees a `null` property.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $item Database row object/array from BerlinDB.
	 */
	public function __construct( $item ) {
		parent::__construct( $item );

		$this->id             = (int) ( $this->id ?? 0 );
		$this->user_id        = (int) ( $this->user_id ?? 0 );
		$this->identifier     = (string) ( $this->identifier ?? '' );
		$this->event_type     = (string) ( $this->event_type ?? 'login' );
		$this->ip_address     = (string) ( $this->ip_address ?? '' );
		$this->country_code   = (string) ( $this->country_code ?? '' );
		$this->country_source = (string) ( $this->country_source ?? '' );
		$this->user_agent     = (string) ( $this->user_agent ?? '' );
		$this->referer        = (string) ( $this->referer ?? '' );
		$this->actor_user_id  = (int) ( $this->actor_user_id ?? 0 );
		$this->user_role           = (string) ( $this->user_role ?? '' );
		$this->session_token_hash  = (string) ( $this->session_token_hash ?? '' );
		$this->is_new_country = (int) ( $this->is_new_country ?? 0 );
		$this->date_created   = (string) ( $this->date_created ?? '' );
		$this->uuid           = (string) ( $this->uuid ?? '' );
	}

	/**
	 * Get the user's display name, or the raw identifier when the row
	 * couldn't be tied to a user (failed login on a non-existent user,
	 * registration rejection, etc.).
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_display_name(): string {
		if ( $this->user_id > 0 ) {
			$user = get_userdata( $this->user_id );
			if ( $user ) {
				return (string) $user->display_name;
			}
		}

		return $this->identifier !== ''
			? $this->identifier
			: __( '(unknown)', 'wp-login-activity' );
	}

	/**
	 * Was this row's (user, country) pair seen for the first time?
	 *
	 * Pre-computed at insert time (see `Logger::is_first_time_country()`)
	 * so the read path doesn't re-scan the table.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function is_new_country(): bool {
		return $this->is_new_country === 1;
	}

	/**
	 * Whether this is a successful authentication event.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function is_successful_login(): bool {
		return $this->event_type === 'login';
	}

	/* -------------------------------------------------------------------
	 * User-Agent presentation helpers — derive on read from the stored
	 * raw UA string. Storing raw means a parser-library upgrade
	 * automatically improves classification on existing rows; storing
	 * pre-parsed labels would lock historical rows to whatever the UA
	 * library knew at insert time.
	 * ----------------------------------------------------------------- */

	/**
	 * Browser name (e.g. "Chrome", "Safari", "Firefox").
	 *
	 * @since 2.0.0
	 *
	 * @return string Empty when unknown / no UA stored.
	 */
	public function get_browser(): string {
		if ( $this->user_agent === '' ) {
			return '';
		}

		return (string) ( UserAgent::get_browser( $this->user_agent ) ?? '' );
	}

	/**
	 * Operating system (e.g. "Windows", "macOS", "iOS").
	 *
	 * @since 2.0.0
	 *
	 * @return string Empty when unknown / no UA stored.
	 */
	public function get_os(): string {
		if ( $this->user_agent === '' ) {
			return '';
		}

		return (string) ( UserAgent::get_os( $this->user_agent ) ?? '' );
	}

	/**
	 * Device class — `desktop`, `mobile`, `tablet`, `bot`, `unknown`.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_device_type(): string {
		if ( $this->user_agent === '' ) {
			return 'unknown';
		}

		return (string) UserAgent::get_device_type( $this->user_agent );
	}

	/**
	 * Was the request from a recognised bot UA?
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function is_bot(): bool {
		return $this->user_agent !== '' && UserAgent::is_bot( $this->user_agent );
	}

	/**
	 * Pretty-printed UA string for admin display — e.g. "Chrome 123 on
	 * macOS 14". The raw header stays untouched in the column.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_formatted_user_agent(): string {
		if ( $this->user_agent === '' ) {
			return '';
		}

		return (string) UserAgent::get_formatted( $this->user_agent );
	}

	/* -------------------------------------------------------------------
	 * IP presentation helpers
	 * ----------------------------------------------------------------- */

	/**
	 * GDPR-anonymised IP — last octet of IPv4 zeroed, last 80 bits of
	 * IPv6 zeroed. Use in any UI surface that gets exported / shared
	 * outside the admin (CSV exports, support emails). The raw IP
	 * stays in the column for novelty checks + abuse correlation.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_anonymised_ip(): string {
		if ( $this->ip_address === '' ) {
			return '';
		}

		return (string) ( IP::anonymize( $this->ip_address ) ?? $this->ip_address );
	}

	/* -------------------------------------------------------------------
	 * Actor / role helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Display name of the user who PERFORMED the action — vs
	 * `get_display_name()` which returns the SUBJECT user. Differs
	 * for admin-driven changes ("Alice promoted Bob to admin"); equal
	 * for self-actions ("Bob changed his own password").
	 *
	 * Returns empty string when there's no actor (passive events
	 * like login / logout / login_failed have no actor distinct from
	 * the subject).
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_actor_display_name(): string {
		if ( $this->actor_user_id <= 0 || $this->actor_user_id === $this->user_id ) {
			return '';
		}

		$actor = get_userdata( $this->actor_user_id );

		return $actor ? (string) $actor->display_name : '#' . $this->actor_user_id;
	}

	/**
	 * Whether this row represents an action ONE user performed on
	 * ANOTHER. Useful for differentiating "I changed my password" vs
	 * "an admin changed my password" in the UI.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function has_distinct_actor(): bool {
		return $this->actor_user_id > 0
			&& $this->user_id > 0
			&& $this->actor_user_id !== $this->user_id;
	}

	/**
	 * Human-readable label for the snapshotted role — converts the
	 * machine slug (`administrator`) to the display label (`Administrator`)
	 * via WP's role registry. Falls back to the slug when no matching
	 * role is registered (custom role removed since the event was logged).
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	/**
	 * Whether this row represents the session the viewer is currently
	 * using on this device — i.e. the row that authenticated the
	 * cookie that's making the current request. Lets the UI tint the
	 * "this is YOUR active session" row à la Google's account-activity
	 * page, so users can spot unfamiliar parallel sessions instantly.
	 *
	 * Returns false when:
	 *   - We're not inside an authenticated request
	 *   - The row isn't a successful login event
	 *   - The row's session-token hash is empty (pre-feature row)
	 *   - The row belongs to a different user than the viewer
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function is_current_session(): bool {
		if ( $this->event_type !== 'login' || $this->session_token_hash === '' ) {
			return false;
		}

		$current_user_id = get_current_user_id();
		if ( $current_user_id <= 0 || $current_user_id !== $this->user_id ) {
			return false;
		}

		$current_token = function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : '';
		if ( $current_token === '' ) {
			return false;
		}

		return hash_equals( $this->session_token_hash, hash( 'sha256', $current_token ) );
	}

	public function get_role_label(): string {
		if ( $this->user_role === '' ) {
			return '';
		}

		$role = get_role( $this->user_role );
		if ( $role && function_exists( 'translate_user_role' ) ) {
			global $wp_roles;
			$names = $wp_roles ? $wp_roles->get_names() : [];
			if ( isset( $names[ $this->user_role ] ) ) {
				return (string) translate_user_role( $names[ $this->user_role ] );
			}
		}

		return ucfirst( $this->user_role );
	}

}
