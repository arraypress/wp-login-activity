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

}
