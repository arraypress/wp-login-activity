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
	 * categories of events (failed logins, logouts, registrations)
	 * without unloading the plugin.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_action( 'wp_login',         [ $this, 'on_login' ], 10, 2 );
		add_action( 'wp_login_failed',  [ $this, 'on_login_failed' ], 10, 1 );
		add_action( 'wp_logout',        [ $this, 'on_logout' ], 10, 1 );
		add_action( 'user_register',    [ $this, 'on_user_register' ], 10, 1 );
	}

	/**
	 * Successful login.
	 *
	 * @since 2.0.0
	 *
	 * @param string  $user_login Login name (username).
	 * @param WP_User $user       The authenticated user.
	 *
	 * @return void
	 */
	public function on_login( string $user_login, WP_User $user ): void {
		$this->record( 'login', (int) $user->ID, $user_login );
	}

	/**
	 * Failed login.
	 *
	 * `wp_login_failed` fires with whatever the visitor typed — could
	 * be a username, an email, or garbage. We resolve to a user_id
	 * when possible (real account that got the wrong password) so
	 * "failed logins for user X" reports work; we fall back to
	 * recording just the identifier when no such account exists.
	 *
	 * @since 2.0.0
	 *
	 * @param string $username The submitted username/email.
	 *
	 * @return void
	 */
	public function on_login_failed( string $username ): void {
		if ( ! get_option( 'wp_login_activity_log_failed_logins', 1 ) ) {
			return;
		}

		$user    = get_user_by( 'login', $username ) ?: get_user_by( 'email', $username );
		$user_id = $user instanceof WP_User ? (int) $user->ID : 0;

		$this->record( 'login_failed', $user_id, $username );
	}

	/**
	 * Logout. WP fires `wp_logout` with the user_id (since 5.5).
	 *
	 * @since 2.0.0
	 *
	 * @param int $user_id The user that logged out.
	 *
	 * @return void
	 */
	public function on_logout( int $user_id ): void {
		if ( ! get_option( 'wp_login_activity_log_logouts', 1 ) ) {
			return;
		}

		$user       = get_userdata( $user_id );
		$identifier = $user ? (string) $user->user_login : '';

		$this->record( 'logout', $user_id, $identifier );
	}

	/**
	 * New user registered.
	 *
	 * @since 2.0.0
	 *
	 * @param int $user_id The new user's ID.
	 *
	 * @return void
	 */
	public function on_user_register( int $user_id ): void {
		if ( ! get_option( 'wp_login_activity_log_registrations', 1 ) ) {
			return;
		}

		$user       = get_userdata( $user_id );
		$identifier = $user ? (string) $user->user_login : '';

		$this->record( 'registered', $user_id, $identifier );
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

		$row_id = Plugin::query()->add_item( [
			'user_id'        => $user_id,
			'identifier'     => $identifier,
			'event_type'     => $event_type,
			'ip_address'     => $ip,
			'country_code'   => $country['code'],
			'country_source' => $country['source'],
			'user_agent'     => $this->resolve_user_agent(),
			'is_new_country' => $this->is_first_time_country( $user_id, $country['code'] ) ? 1 : 0,
			'date_created'   => current_time( 'mysql', true ),
		] );

		if ( ! $row_id ) {
			return;
		}

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
	 * Walks the standard forwarded-IP-header cascade. CF-Connecting-IP
	 * wins on Cloudflare, X-Forwarded-For wins behind a generic load
	 * balancer (taking the leftmost / client-side address), X-Real-IP
	 * is a common Nginx convention, and REMOTE_ADDR is the fallback.
	 *
	 * Filterable via `wp_login_activity_visitor_ip` for sites with an
	 * unusual proxy chain.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	private function resolve_ip(): string {
		$candidates = [
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		];

		$ip = '';

		foreach ( $candidates as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			// X-Forwarded-For can be a comma-list — leftmost is the
			// closest-to-client address.
			$candidate = trim( explode( ',', (string) $_SERVER[ $header ] )[0] );

			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
				break;
			}
		}

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
	 * Get the User-Agent header. Trimmed to a sane upper bound to
	 * defend against a few-MB UA poisoning the row.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	private function resolve_user_agent(): string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? wp_check_invalid_utf8( (string) $_SERVER['HTTP_USER_AGENT'] )
			: '';

		return mb_substr( $ua, 0, 1024 );
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
