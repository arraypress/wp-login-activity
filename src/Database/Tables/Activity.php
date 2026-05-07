<?php
/**
 * Activity Table
 *
 * BerlinDB Table installer — owns the DDL, schema version, and any
 * incremental upgrades. Indexes are tuned for the workload:
 *   - `(user_id, date_created)` powers the per-user history list
 *   - `(country_code)` powers the new-country novelty check
 *   - `(event_type, date_created)` powers admin filtering
 *   - `(date_created)` alone powers retention-purge scans
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Database\Tables;

defined( 'ABSPATH' ) || exit;

use BerlinDB\Database\Table;

/**
 * Class Activity
 *
 * Installs and manages the `wp_wpla_activity` table.
 *
 * @since 2.0.0
 */
final class Activity extends Table {

	/**
	 * Table name (no prefix). BerlinDB prepends `$wpdb->prefix` to
	 * produce the final `wp_wpla_activity` name. The `wpla_` segment
	 * lives in the name itself (rather than via a separate prefix
	 * property) so cross-plugin name collisions on a generic word like
	 * "activity" are impossible.
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	protected $name = 'wpla_activity';

	/**
	 * Schema version — bump when adding/changing columns or indexes
	 * AND register a corresponding `__YYYYMMDDN()` upgrade method.
	 *
	 * @since 2.0.0
	 * @var   int
	 */
	protected $version = 202605080;

	/**
	 * Map of registered upgrades. BerlinDB walks this on every load
	 * and applies any version newer than the stored option.
	 *
	 * @since 2.0.0
	 * @var   array
	 */
	protected $upgrades = [
		'202605080' => 202605080,
	];

	/**
	 * DDL.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	protected function set_schema(): void {
		$this->schema = "id bigint(20) unsigned NOT NULL auto_increment,
			user_id bigint(20) unsigned NOT NULL default '0',
			identifier varchar(255) NOT NULL default '',
			event_type varchar(40) NOT NULL default 'login',
			ip_address varchar(45) NOT NULL default '',
			country_code varchar(2) NOT NULL default '',
			country_source varchar(10) NOT NULL default '',
			user_agent text NOT NULL,
			referer varchar(500) NOT NULL default '',
			actor_user_id bigint(20) unsigned NOT NULL default '0',
			user_role varchar(50) NOT NULL default '',
			session_token_hash varchar(64) NOT NULL default '',
			is_new_country tinyint(1) unsigned NOT NULL default '0',
			date_created datetime NOT NULL default '0000-00-00 00:00:00',
			uuid varchar(100) NOT NULL default '',
			PRIMARY KEY (id),
			KEY user_date (user_id, date_created),
			KEY country (country_code),
			KEY event_date (event_type, date_created),
			KEY date_created (date_created),
			KEY ip_address (ip_address),
			KEY identifier (identifier(50)),
			KEY actor_user_id (actor_user_id),
			KEY session_token_hash (session_token_hash)";
	}

	/**
	 * Upgrade to 202605080 — add `referer`, `actor_user_id`, and
	 * `user_role` columns plus an index on `actor_user_id`. Existing
	 * rows get empty defaults; we don't backfill because the source
	 * data isn't reliably reconstructable.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	protected function __202605080(): bool {
		global $wpdb;

		$result = true;

		if ( ! $this->column_exists( 'referer' ) ) {
			$result = $this->is_success(
				$wpdb->query( "ALTER TABLE {$this->table_name} ADD COLUMN referer varchar(500) NOT NULL default '' AFTER user_agent" )
			);
		}

		if ( $result && ! $this->column_exists( 'actor_user_id' ) ) {
			$result = $this->is_success(
				$wpdb->query( "ALTER TABLE {$this->table_name} ADD COLUMN actor_user_id bigint(20) unsigned NOT NULL default '0' AFTER referer" )
			);
		}

		if ( $result && ! $this->column_exists( 'user_role' ) ) {
			$result = $this->is_success(
				$wpdb->query( "ALTER TABLE {$this->table_name} ADD COLUMN user_role varchar(50) NOT NULL default '' AFTER actor_user_id" )
			);
		}

		if ( $result && ! $this->column_exists( 'session_token_hash' ) ) {
			$result = $this->is_success(
				$wpdb->query( "ALTER TABLE {$this->table_name} ADD COLUMN session_token_hash varchar(64) NOT NULL default '' AFTER user_role" )
			);
		}

		if ( $result && ! $this->index_exists( 'actor_user_id' ) ) {
			$result = $this->is_success(
				$wpdb->query( "ALTER TABLE {$this->table_name} ADD INDEX actor_user_id (actor_user_id)" )
			);
		}

		if ( $result && ! $this->index_exists( 'session_token_hash' ) ) {
			$result = $this->is_success(
				$wpdb->query( "ALTER TABLE {$this->table_name} ADD INDEX session_token_hash (session_token_hash)" )
			);
		}

		return $result;
	}

}
