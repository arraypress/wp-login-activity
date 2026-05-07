<?php
/**
 * Activity Query
 *
 * BerlinDB Query class — the single read/write layer over the
 * `wp_wpla_activity` table. Pairs Schema (column metadata),
 * Table (DDL), and Row (per-row model) into one queryable surface.
 *
 * Typical use:
 *   $query = new Activity( [
 *       'user_id' => 42,
 *       'orderby' => 'date_created',
 *       'order'   => 'DESC',
 *       'number'  => 50,
 *   ] );
 *   foreach ( $query->items as $row ) { ... }
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Database\Queries;

defined( 'ABSPATH' ) || exit;

use BerlinDB\Database\Query;

/**
 * Class Activity
 *
 * @since 2.0.0
 */
class Activity extends Query {

	/**
	 * Table name (without prefix). Must match `Tables\Activity::$name`.
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	protected $table_name = 'wpla_activity';

	/**
	 * Singular name for the entity — used by BerlinDB for cache keys
	 * and event hooks like `wpla_activity_added`.
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	protected $item_name = 'activity';

	/**
	 * Plural name.
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	protected $item_name_plural = 'activities';

	/**
	 * Per-row model class.
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	protected $item_shape = \ArrayPress\WP\LoginActivity\Database\Rows\Activity::class;

	/**
	 * Cache group — namespaces all cached queries against this table
	 * so a `wp_cache_flush` on the group invalidates only our entries.
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	protected $cache_group = 'wpla_activities';

	/**
	 * Schema class — BerlinDB instantiates and uses it for column
	 * introspection (orderby validation, search columns, etc.).
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	protected $table_schema = \ArrayPress\WP\LoginActivity\Database\Schemas\Activity::class;

}
