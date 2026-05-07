<?php
/**
 * User-List Columns
 *
 * Adds a "Last login" column to the wp-users list table. Sorted on
 * the most recent successful login per user — non-admins see only
 * their own column populated to avoid leaking timing data on other
 * accounts.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Admin;

defined( 'ABSPATH' ) || exit;

use ArrayPress\WP\LoginActivity\Plugin;

/**
 * Class UserColumns
 *
 * @since 2.0.0
 */
class UserColumns {

	/**
	 * Cache of "last login" lookups keyed by user ID. Page renders
	 * call `column_value()` once per row; the cache prevents N
	 * separate queries when `manage_users_custom_column` fires.
	 *
	 * @since 2.0.0
	 * @var   array<int, string>
	 */
	private array $last_login_cache = [];

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_filter( 'manage_users_columns',       [ $this, 'register_column' ] );
		add_filter( 'manage_users_custom_column', [ $this, 'render_column' ], 10, 3 );
	}

	/**
	 * Register the column header.
	 *
	 * @since 2.0.0
	 *
	 * @param array $columns Existing columns.
	 *
	 * @return array
	 */
	public function register_column( array $columns ): array {
		$columns['wp_login_activity_last_login'] = __( 'Last login', 'wp-login-activity' );

		return $columns;
	}

	/**
	 * Render the column value for a user row.
	 *
	 * @since 2.0.0
	 *
	 * @param string $value       Default column value (empty for custom columns).
	 * @param string $column_name Column slug.
	 * @param int    $user_id     User ID.
	 *
	 * @return string
	 */
	public function render_column( $value, string $column_name, int $user_id ): string {
		if ( $column_name !== 'wp_login_activity_last_login' ) {
			return (string) $value;
		}

		// Privacy guard — only admins, or the user themselves, see
		// the populated value. Other rows render an em dash.
		if ( ! current_user_can( 'manage_options' ) && get_current_user_id() !== $user_id ) {
			return '—';
		}

		return $this->get_last_login( $user_id );
	}

	/**
	 * Resolve "most recent successful login" for a user. Cached.
	 *
	 * @since 2.0.0
	 *
	 * @param int $user_id User ID.
	 *
	 * @return string Formatted datetime, or em-dash when no login.
	 */
	private function get_last_login( int $user_id ): string {
		if ( isset( $this->last_login_cache[ $user_id ] ) ) {
			return $this->last_login_cache[ $user_id ];
		}

		$rows = Plugin::query()->query( [
			'user_id'    => $user_id,
			'event_type' => 'login',
			'orderby'    => 'date_created',
			'order'      => 'DESC',
			'number'     => 1,
		] );

		if ( empty( $rows ) ) {
			return $this->last_login_cache[ $user_id ] = '—';
		}

		$row = $rows[0];

		// Format matches the activity table's Date column: site-
		// timezone absolute on top, relative ("X ago") muted
		// underneath. Posts/Pages list-table convention. Stored
		// timestamp is UTC, so we strtotime+UTC then wp_date()
		// converts to the site's configured timezone for display.
		$ts = strtotime( $row->date_created . ' UTC' );

		if ( ! $ts ) {
			return $this->last_login_cache[ $user_id ] = esc_html( $row->date_created );
		}

		$absolute = sprintf(
			/* translators: 1: date in Y/m/d format, 2: time in g:i a format */
			esc_html__( '%1$s at %2$s', 'wp-login-activity' ),
			esc_html( wp_date( __( 'Y/m/d', 'wp-login-activity' ), $ts ) ),
			esc_html( wp_date( __( 'g:i a', 'wp-login-activity' ), $ts ) )
		);

		$relative = sprintf(
			/* translators: %s: human-readable time difference (e.g. "2 hours") */
			esc_html__( '%s ago', 'wp-login-activity' ),
			esc_html( human_time_diff( $ts, time() ) )
		);

		return $this->last_login_cache[ $user_id ] =
			$absolute . '<br /><span style="color:#646970;font-size:12px;">' . $relative . '</span>';
	}

}
