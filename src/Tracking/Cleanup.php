<?php
/**
 * Tracking Cleanup
 *
 * Daily cron job that purges activity rows older than the configured
 * retention window. Batched in chunks to avoid long-running queries
 * locking the table on busy sites.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Tracking;

defined( 'ABSPATH' ) || exit;

use ArrayPress\WP\LoginActivity\Plugin;

/**
 * Class Cleanup
 *
 * @since 2.0.0
 */
class Cleanup {

	/**
	 * Cron hook name.
	 *
	 * @since 2.0.0
	 */
	private const HOOK = 'wp_login_activity_cleanup';

	/**
	 * Maximum rows deleted per cron tick. A single cron pass may not
	 * fully purge a long-neglected install — subsequent ticks finish
	 * the work — but the chunk keeps locks short on busy tables.
	 *
	 * @since 2.0.0
	 */
	private const BATCH_SIZE = 1000;

	/**
	 * Constructor — registers the cron action handler.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_action( self::HOOK, [ $this, 'purge' ] );

		// Idempotent: wp_schedule_event no-ops if already scheduled.
		// Re-registering on every load keeps activations recoverable
		// even when the activation hook didn't run (e.g. mu-plugin).
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	/**
	 * Schedule the daily cron.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Unschedule the cron — called from the uninstaller.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Purge expired rows.
	 *
	 * Retention of 0 means "keep forever" — useful for sites with
	 * compliance reporting requirements. Bails fast in that case.
	 *
	 * @since 2.0.0
	 *
	 * @return int Rows deleted on this tick.
	 */
	public function purge(): int {
		$days = (int) get_option( 'wp_login_activity_retention_days', 90 );

		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$ids = Plugin::query()->query( [
			'date_created_query' => [ [ 'before' => $cutoff, 'inclusive' => true ] ],
			'fields'             => 'ids',
			'number'             => self::BATCH_SIZE,
			'orderby'            => 'id',
			'order'              => 'ASC',
		] );

		if ( empty( $ids ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( Plugin::query()->delete_item( (int) $id ) ) {
				$deleted++;
			}
		}

		/**
		 * Fires after a cleanup pass. Useful for log forwarders or
		 * monitoring dashboards.
		 *
		 * @since 2.0.0
		 *
		 * @param int    $deleted Rows deleted this tick.
		 * @param string $cutoff  Datetime threshold used (UTC).
		 */
		do_action( 'wp_login_activity_purged', $deleted, $cutoff );

		return $deleted;
	}

}
