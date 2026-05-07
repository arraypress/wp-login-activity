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
	 * produce the final `wp_login_activity` table name.
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	protected $name = 'login_activity';

	/**
	 * Schema version. Plugin pre-release — every column we ship lives
	 * directly in `set_schema()` as a single canonical DDL. When the
	 * plugin actually has an install base and the schema needs to
	 * change, bump this and register an `__YYYYMMDDN()` upgrade method
	 * via the `$upgrades` map below.
	 *
	 * @since 2.0.0
	 * @var   int
	 */
	protected $version = 1;

	/**
	 * Map of registered upgrades. Empty until the first post-release
	 * schema change.
	 *
	 * @since 2.0.0
	 * @var   array
	 */
	protected $upgrades = [];

	/**
	 * DDL — full canonical schema. Every column the plugin captures
	 * lives here in one block; pre-release we don't carry migration
	 * baggage from intermediate column additions during development.
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
			accept_language varchar(100) NOT NULL default '',
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

}
