<?php
/**
 * Activity Schema
 *
 * BerlinDB column metadata for the activity table — declares each column's
 * type, indexing, search/sort eligibility, and cache key participation.
 * Drives query-layer column whitelisting (`Query::query_clause_*`) so
 * untrusted user input on `orderby` / `where` is filtered against this
 * list rather than a hand-maintained allowlist.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Database\Schemas;

defined( 'ABSPATH' ) || exit;

use BerlinDB\Database\Schema;

/**
 * Class Activity
 *
 * Column declarations for `wp_wpla_activity`.
 *
 * @since 2.0.0
 */
class Activity extends Schema {

	/**
	 * Column metadata.
	 *
	 * Order mirrors the `set_schema()` DDL in the Table class — keeps
	 * column-edit cognitive load to one place when the schema evolves.
	 *
	 * @since 2.0.0
	 * @var   array
	 */
	public $columns = [

		// Primary key.
		[
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'extra'    => 'auto_increment',
			'primary'  => true,
			'sortable' => true,
		],

		// Foreign key to wp_users. Zero for failed-login / registration-
		// rejected events where we never resolved the user — keeps the
		// audit row even when the principal is unknown.
		[
			'name'       => 'user_id',
			'type'       => 'bigint',
			'length'     => '20',
			'unsigned'   => true,
			'default'    => '0',
			'cache_key'  => true,
			'searchable' => true,
			'sortable'   => true,
		],

		// Free-text identifier — for failed logins this captures whatever
		// the visitor typed (could be a username OR an email). Indexed
		// so admins can search "every event for `bob@example.com`"
		// regardless of whether the row resolved to a user.
		[
			'name'       => 'identifier',
			'type'       => 'varchar',
			'length'     => '255',
			'default'    => '',
			'searchable' => true,
			'sortable'   => true,
		],

		// Event slug: login, logout, login_failed, registered.
		// Short string vs ENUM so we can extend without an ALTER.
		[
			'name'       => 'event_type',
			'type'       => 'varchar',
			'length'     => '40',
			'default'    => 'login',
			'cache_key'  => true,
			'searchable' => true,
			'sortable'   => true,
		],

		// IPv4 / IPv6 — varchar 45 covers worst-case IPv6.
		[
			'name'       => 'ip_address',
			'type'       => 'varchar',
			'length'     => '45',
			'default'    => '',
			'cache_key'  => true,
			'searchable' => true,
			'sortable'   => true,
		],

		// ISO-3166-1 alpha-2 (e.g. `US`, `GB`). Empty when geo lookup
		// declines. Indexed for the new-country novelty query and for
		// per-country reporting.
		[
			'name'       => 'country_code',
			'type'       => 'varchar',
			'length'     => '2',
			'default'    => '',
			'searchable' => true,
			'sortable'   => true,
		],

		// Source of the country code — `cf` (Cloudflare header), `geo`
		// (geo-IP database), or `''` (none). Helps debug "wrong country"
		// reports — different sources have different accuracy profiles.
		[
			'name'    => 'country_source',
			'type'    => 'varchar',
			'length'  => '10',
			'default' => '',
		],

		// Raw User-Agent header. Display-only — no parsing on the way in
		// (parsing is presentation concern, done on read).
		[
			'name'    => 'user_agent',
			'type'    => 'text',
			'default' => '',
		],

		// HTTP_REFERER at the time of the event. Useful for spotting
		// auth attempts that didn't come via the login form (direct
		// API requests, bookmarks, suspicious referrers).
		[
			'name'    => 'referer',
			'type'    => 'varchar',
			'length'  => '500',
			'default' => '',
		],

		// Actor — who PERFORMED the action vs `user_id` which is the
		// SUBJECT. For self-actions (your own login, you change your
		// own password) the two are equal. For admin-driven changes
		// (Alice promotes Bob to admin) actor_user_id captures the
		// initiating admin. Indexed so "what did this admin do today?"
		// queries are fast.
		[
			'name'      => 'actor_user_id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'default'   => '0',
			'cache_key' => true,
			'sortable'  => true,
		],

		// Snapshot of the subject user's primary role AT THE TIME of
		// the event. Stored at write time so a later role change (or
		// user deletion) doesn't rewrite history — "what role logged
		// in?" stays answerable forever. Render uses the snapshot
		// rather than a live get_userdata() lookup for the same reason.
		[
			'name'    => 'user_role',
			'type'    => 'varchar',
			'length'  => '50',
			'default' => '',
		],

		// SHA-256 of the WP session token that authenticated this
		// login event. Lets the admin/profile UI highlight "this is
		// the session you're using right now" — matches against
		// `wp_get_session_token()` of the current request. We hash
		// rather than store raw because the raw token is a credential
		// equivalent to a password — attacker DB read would let them
		// hijack the session if stored plain.
		[
			'name'    => 'session_token_hash',
			'type'    => 'varchar',
			'length'  => '64',
			'default' => '',
		],

		// Accept-Language header — the browser's advertised language
		// preferences at request time, e.g. "en-GB,en;q=0.9,fr;q=0.8".
		// Forensically valuable as a SECONDARY identity signal: if
		// a user's typical Accept-Language is en-GB and a login
		// suddenly arrives with ru-RU, that's a strong "different
		// person on this account" indicator even when IP + country
		// happen to match (e.g. VPN exit in the user's home country).
		// Stored RAW for the same reason we store raw user_agent —
		// future parser improvements can derive richer signals from
		// historical rows.
		[
			'name'    => 'accept_language',
			'type'    => 'varchar',
			'length'  => '100',
			'default' => '',
		],

		// Boolean flag set at write time when the (user_id, country_code)
		// pair has not been seen before. Pre-computed instead of derived
		// at read time so the new-country email + admin "new" badge are
		// O(1) lookups, not O(history).
		[
			'name'     => 'is_new_country',
			'type'     => 'tinyint',
			'length'   => '1',
			'unsigned' => true,
			'default'  => '0',
			'sortable' => true,
		],

		// Created-at — primary sort key + retention-purge boundary.
		[
			'name'       => 'date_created',
			'type'       => 'datetime',
			'default'    => '0000-00-00 00:00:00',
			'date_query' => true,
			'sortable'   => true,
		],

		// UUID — generated by BerlinDB on insert. Surfaced in admin URLs
		// so individual rows have a stable, non-guessable handle even
		// if/when integers are reused after a `wp_login_activity_purge`.
		[
			'name'       => 'uuid',
			'type'       => 'varchar',
			'length'     => '100',
			'default'    => '',
			'uuid'       => true,
		],

	];

}
