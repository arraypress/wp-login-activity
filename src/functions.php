<?php
/**
 * Public function API
 *
 * Thin global helpers that wrap the Plugin singleton. Three reasons
 * for these to exist:
 *
 *   1. Theme + integration code shouldn't have to import the namespace
 *      to ask "what's the user's last login?". A short global helper
 *      reads better at the call site.
 *   2. The function-name surface is the most stable contract — class
 *      names can shift internally without breaking integrators.
 *   3. wp-cli command callbacks need plain function references.
 *
 * The functions live in the loadable file declared in composer.json's
 * autoload.files block, so Composer eagerly includes them on every
 * request without any additional require statement.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use ArrayPress\WP\LoginActivity\Plugin;
use ArrayPress\WP\LoginActivity\Database\Queries\Activity as ActivityQuery;
use ArrayPress\WP\LoginActivity\Database\Rows\Activity as ActivityRow;

if ( ! function_exists( 'wpla_query' ) ) {
	/**
	 * Get the cached Activity query instance.
	 *
	 * Convenience accessor — equivalent to `Plugin::query()`. Use
	 * directly with BerlinDB args:
	 *
	 *   wpla_query()->query( [ 'user_id' => 1, 'number' => 10 ] );
	 *
	 * @since 2.0.0
	 *
	 * @return ActivityQuery
	 */
	function wpla_query(): ActivityQuery {
		return Plugin::query();
	}
}

if ( ! function_exists( 'wpla_get_activity' ) ) {
	/**
	 * Get a single activity row by ID.
	 *
	 * @since 2.0.0
	 *
	 * @param int $id Row ID.
	 *
	 * @return ActivityRow|null
	 */
	function wpla_get_activity( int $id ): ?ActivityRow {
		$row = Plugin::query()->get_item( $id );

		return $row instanceof ActivityRow ? $row : null;
	}
}

if ( ! function_exists( 'wpla_get_user_activity' ) ) {
	/**
	 * Recent activity for a user, newest-first.
	 *
	 * @since 2.0.0
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Max rows to return (default 10).
	 *
	 * @return ActivityRow[]
	 */
	function wpla_get_user_activity( int $user_id, int $limit = 10 ): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		return (array) Plugin::query()->query( [
			'user_id' => $user_id,
			'orderby' => 'date_created',
			'order'   => 'DESC',
			'number'  => max( 1, $limit ),
		] );
	}
}

if ( ! function_exists( 'wpla_get_last_login' ) ) {
	/**
	 * Get the user's most recent successful login row (or null).
	 *
	 * @since 2.0.0
	 *
	 * @param int $user_id User ID.
	 *
	 * @return ActivityRow|null
	 */
	function wpla_get_last_login( int $user_id ): ?ActivityRow {
		if ( $user_id <= 0 ) {
			return null;
		}

		$rows = Plugin::query()->query( [
			'user_id'    => $user_id,
			'event_type' => 'login',
			'orderby'    => 'date_created',
			'order'      => 'DESC',
			'number'     => 1,
		] );

		return ! empty( $rows ) && $rows[0] instanceof ActivityRow ? $rows[0] : null;
	}
}

if ( ! function_exists( 'wpla_count_failed_logins' ) ) {
	/**
	 * Count failed login attempts within a time window.
	 *
	 * Useful for integration with rate-limit / brute-force plugins
	 * that want to read failure velocity off our log rather than
	 * maintaining a parallel counter.
	 *
	 * @since 2.0.0
	 *
	 * @param string $identifier Username, email, or IP.
	 * @param string $field      One of: 'identifier', 'ip_address'.
	 * @param int    $minutes    Window size, default 15.
	 *
	 * @return int
	 */
	function wpla_count_failed_logins( string $identifier, string $field = 'identifier', int $minutes = 15 ): int {
		if ( $identifier === '' || ! in_array( $field, [ 'identifier', 'ip_address' ], true ) ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $minutes ) * MINUTE_IN_SECONDS ) );

		$rows = Plugin::query()->query( [
			$field               => $identifier,
			'event_type'         => 'login_failed',
			'date_created_query' => [ [ 'after' => $cutoff, 'inclusive' => true ] ],
			'fields'             => 'ids',
			'number'             => 0,
			'count'              => true,
		] );

		return is_numeric( $rows ) ? (int) $rows : count( (array) $rows );
	}
}
