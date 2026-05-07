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
	 * Table name (no prefix). BerlinDB prepends `$wpdb->prefix` plus
	 * the `db_global` value (configured via the Plugin) to produce the
	 * final `wp_wpla_activity` name.
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	protected $name = 'activity';

	/**
	 * Schema version — bump when adding/changing columns or indexes
	 * AND register a corresponding `__YYYYMMDDN()` upgrade method.
	 *
	 * @since 2.0.0
	 * @var   int
	 */
	protected $version = 202605070;

	/**
	 * Map of registered upgrades. BerlinDB walks this on every load
	 * and applies any version newer than the stored option.
	 *
	 * @since 2.0.0
	 * @var   array
	 */
	protected $upgrades = [];

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
			is_new_country tinyint(1) unsigned NOT NULL default '0',
			date_created datetime NOT NULL default '0000-00-00 00:00:00',
			uuid varchar(100) NOT NULL default '',
			PRIMARY KEY (id),
			KEY user_date (user_id, date_created),
			KEY country (country_code),
			KEY event_date (event_type, date_created),
			KEY date_created (date_created),
			KEY ip_address (ip_address),
			KEY identifier (identifier(50))";
	}

}
