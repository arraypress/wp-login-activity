<?php
/**
 * Activity List Table
 *
 * Proper `WP_List_Table` implementation for `Users → Login Activity`.
 * The previous version rendered the table markup inline — fine for a
 * v0, but it duplicated half of WP's list-table feature set (per-page
 * options, column hiding, sortable headers, bulk actions) that
 * `WP_List_Table` provides for free.
 *
 * What lands here for free by extending the core class:
 *   - "subsubsub" event-type filter strip (post-status-style — All |
 *     Logins | Failed | …) via `get_views()`, with per-bucket counts.
 *   - Sortable column headers driven by `get_sortable_columns()`.
 *   - Built-in pagination markup via `prepare_items()` set_pagination_args.
 *   - Screen Options panel — per-page slider, column-hide checkboxes —
 *     wired by ActivityPage.
 *   - Bulk-delete via `get_bulk_actions()` and the standard form-submit
 *     dance.
 *
 * The "When" column reads a per-user screen option for absolute vs
 * relative ("2 hours ago") rendering — defaults to absolute since
 * forensics work usually wants exact UTC timestamps.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Admin;

defined( 'ABSPATH' ) || exit;

// WP_List_Table isn't loaded by default in admin contexts.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

use ArrayPress\WP\LoginActivity\Database\Rows\Activity as ActivityRow;
use ArrayPress\WP\LoginActivity\Plugin;
use ArrayPress\Countries\Countries;
use WP_List_Table;

/**
 * Class ListTable
 *
 * @since 2.0.0
 */
class ListTable extends WP_List_Table {

	/**
	 * The screen-option key for per-page rows.
	 *
	 * @since 2.0.0
	 */
	public const PER_PAGE_OPTION = 'wpla_per_page';

	/**
	 * Default rows-per-page when no screen-option set.
	 *
	 * @since 2.0.0
	 */
	private const DEFAULT_PER_PAGE = 50;

	/**
	 * Version stamp for `get_default_hidden_columns()`. Bump whenever
	 * a new default-hidden column is added so existing users get the
	 * one-time merge applied via `ActivityPage::hidden_columns_migration`.
	 *
	 *   v1 — initial set (empty)
	 *   v2 — added: language, referer
	 *   v3 — added: name, role, device. Plugin pivoted to a 5-column
	 *        minimal main view (Username, Event, IP, Country, Date)
	 *        with everything else moved into the flyout panel; users
	 *        can still toggle the hidden columns back on via Screen
	 *        Options when they want full-table mode.
	 *
	 * @since 2.0.0
	 */
	public const HIDDEN_COLUMNS_VERSION = 3;

	/**
	 * Whether to render in compact mode — used on the user-profile
	 * screen where a full Settings-page-style table with bulk actions,
	 * status links, and search would be overkill. Mirrors the
	 * Application Passwords table layout in core: just column headers,
	 * striped rows, repeated header at the bottom.
	 *
	 * @since 2.0.0
	 * @var   bool
	 */
	private bool $compact = false;

	/**
	 * Forced user-ID filter — set via the constructor for the
	 * user-profile embed case. Overrides `?user_id=` on the URL.
	 *
	 * @since 2.0.0
	 * @var   int
	 */
	private int $forced_user_id = 0;

	/**
	 * Override the per-page count — used in compact mode where 50
	 * rows would be a wall of text inside someone's profile page.
	 *
	 * @since 2.0.0
	 * @var   int
	 */
	private int $forced_per_page = 0;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param array{compact?: bool} $args Optional config.
	 */
	public function __construct( array $args = [] ) {
		$this->compact         = ! empty( $args['compact'] );
		$this->forced_user_id  = (int) ( $args['user_id'] ?? 0 );
		$this->forced_per_page = (int) ( $args['per_page'] ?? 0 );

		// Screen ID resolution:
		//   - Full admin page: omit so the parent uses
		//     `get_current_screen()` and the Screen Options column-
		//     hide checkboxes register against the actual page hook
		//     (`users_page_wp-login-activity`).
		//   - Compact mode (user-profile embed): use a synthetic
		//     handle so Screen Options on profile.php doesn't end
		//     up showing OUR columns mixed in with WP's own ones.
		$parent_args = [
			'singular' => 'activity',
			'plural'   => 'activities',
			'ajax'     => false,
		];

		if ( $this->compact ) {
			$parent_args['screen'] = 'wpla-activity-compact';
		}

		parent::__construct( $parent_args );
	}

	/**
	 * Column definitions — these become both the header labels AND
	 * the `cb` checkbox for bulk actions when `cb` is keyed first.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		// Full-mode column order mirrors WordPress core's Users list
		// table: cb | Username (primary) | Name | Role | … | Date
		// rightmost (Posts-table convention). Compact mode keeps Date
		// first since there's no Username column to anchor on.
		if ( $this->compact ) {
			return [
				'date'    => __( 'Date',    'wp-login-activity' ),
				'event'   => __( 'Event',   'wp-login-activity' ),
				'ip'      => __( 'IP',      'wp-login-activity' ),
				'country' => __( 'Country', 'wp-login-activity' ),
				'device'  => __( 'Device',  'wp-login-activity' ),
			];
		}

		// Tight, intentional column set. Everything else (Name,
		// Role, Device, Language, Referer, raw UA, country source,
		// referrer URL, identifier-typed-value, etc.) lives in the
		// flyout. Hideable-column toggling for the secondary fields
		// was adding configuration surface without proportional
		// value — admins who want forensic depth get it via the
		// flyout per-row, where it's better laid out anyway.
		return [
			'cb'       => '<input type="checkbox" />',
			'username' => __( 'Username', 'wp-login-activity' ),
			'event'    => __( 'Event',    'wp-login-activity' ),
			'ip'       => __( 'IP',       'wp-login-activity' ),
			'country'  => __( 'Country',  'wp-login-activity' ),
			'date'     => __( 'Date',     'wp-login-activity' ),
		];
	}

	/**
	 * Columns the user can hide via Screen Options. Anything OMITTED
	 * here can't be hidden (which is appropriate for `cb` and `when`
	 * since they're load-bearing).
	 *
	 * @since 2.0.0
	 *
	 * @return string[]
	 */
	public function get_hideable_columns(): array {
		// All five non-primary, non-Date columns are toggleable.
		// Username is the row's primary column; Date anchors the
		// sort. The legacy "secondary" columns (Name, Role, Device,
		// Language, Referer) are no longer columns at all — they
		// surface in the flyout instead.
		return [ 'event', 'ip', 'country' ];
	}

	/**
	 * Columns hidden by default. Empty since we shipped the minimal
	 * 5-column main view — every remaining column is high-value-at-
	 * a-glance and visible by default.
	 *
	 * @since 2.0.0
	 *
	 * @return string[]
	 */
	public static function get_default_hidden_columns(): array {
		return [];
	}

	/**
	 * Override parent to always return an array.
	 *
	 * The parent `WP_List_Table::get_hidden_columns()` calls the
	 * global `get_hidden_columns($screen)` which returns `false` when
	 * the screen isn't a registered WP screen — which it isn't for
	 * the user-profile compact embed (we use a synthetic screen ID).
	 * That `false` then trips `in_array($col, false)` deeper in
	 * `print_column_headers()` with a TypeError on PHP 8+.
	 *
	 * Fix: cast to array. Compact mode also gets an empty array since
	 * there's no Screen Options panel to drive column hiding from.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	public function get_hidden_columns() {
		if ( $this->compact ) {
			return [];
		}

		return (array) parent::get_hidden_columns();
	}

	/**
	 * Per-column orderby key. The values map to BerlinDB Schema
	 * columns and are validated on the way through prepare_items().
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, array{0:string,1:bool}>
	 */
	public function get_sortable_columns(): array {
		// Compact (user-profile embed) is a fixed "recent activity"
		// widget showing newest-first — clickable sort headers there
		// would invite admins to re-order what's meant to be a stable
		// chronological log. Drop them entirely.
		if ( $this->compact ) {
			return [];
		}

		return [
			'date'     => [ 'date_created', true ],   // default-desc
			'username' => [ 'identifier', false ],
			'event'    => [ 'event_type', false ],
			'ip'       => [ 'ip_address', false ],
			'country'  => [ 'country_code', false ],
		];
	}

	/**
	 * Status-link bar above the table — renders the same
	 * "All (124) | Logins (100) | Failed (12) …" subsubsub navigation
	 * WP uses for post statuses. Fast: counts are one indexed query
	 * per bucket, all against the same (event_type) index.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, string>
	 */
	public function get_views(): array {
		// Compact mode (per-user profile embed) skips the status link
		// bar entirely — the per-user view is short enough that
		// filtering further would empty most buckets.
		if ( $this->compact ) {
			return [];
		}

		$current = isset( $_GET['event_type'] ) ? sanitize_key( (string) $_GET['event_type'] ) : '';
		$base    = admin_url( 'users.php?page=wp-login-activity' );

		// Preserve the user-scope filter across status-link clicks.
		// Without this, navigating between event types from inside a
		// per-user view drops the scope and silently widens the
		// dataset to site-wide — a UX trap.
		$scoped_user_id = isset( $_GET['user_id'] ) ? absint( (string) $_GET['user_id'] ) : 0;
		if ( $scoped_user_id > 0 ) {
			$base = add_query_arg( 'user_id', $scoped_user_id, $base );
		}

		$views = [];

		// "All" bucket — total row count, no event-type filter.
		$views['all'] = $this->view_link(
			$base,
			__( 'All', 'wp-login-activity' ),
			$this->count_for_event( '', $scoped_user_id ),
			$current === ''
		);

		$buckets = [
			'login'             => __( 'Logins',              'wp-login-activity' ),
			'login_failed'      => __( 'Failed',              'wp-login-activity' ),
			'logout'            => __( 'Logouts',             'wp-login-activity' ),
			'registered'        => __( 'Registrations',       'wp-login-activity' ),
			'password_changed'  => __( 'Password changes',    'wp-login-activity' ),
			'email_changed'     => __( 'Email changes',       'wp-login-activity' ),
			'admin_assigned'    => __( 'Admin assigned',      'wp-login-activity' ),
		];

		foreach ( $buckets as $slug => $label ) {
			$views[ $slug ] = $this->view_link(
				add_query_arg( 'event_type', $slug, $base ),
				$label,
				$this->count_for_event( $slug, $scoped_user_id ),
				$current === $slug
			);
		}

		return $views;
	}

	/**
	 * Build a single subsubsub link.
	 *
	 * @since 2.0.0
	 *
	 * @param string $url     Target URL.
	 * @param string $label   Text shown.
	 * @param int    $count   Bucket count.
	 * @param bool   $current Whether this is the active filter.
	 *
	 * @return string HTML.
	 */
	private function view_link( string $url, string $label, int $count, bool $current ): string {
		$class_attr = $current ? ' class="current"' : '';

		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			$class_attr,
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Count rows for an event-type bucket, optionally scoped to a
	 * single user. One query per call, cached statically per request
	 * so re-rendering the bar (e.g. on the bottom of the page)
	 * doesn't re-query.
	 *
	 * @since 2.0.0
	 *
	 * @param string $event_type Empty string = all events.
	 * @param int    $user_id    Optional user-scope filter.
	 *
	 * @return int
	 */
	private function count_for_event( string $event_type, int $user_id = 0 ): int {
		static $cache = [];

		$key = ( $event_type === '' ? '__all' : $event_type ) . '|u' . $user_id;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$args = [ 'count' => true, 'number' => 0 ];
		if ( $event_type !== '' ) {
			$args['event_type'] = $event_type;
		}
		if ( $user_id > 0 ) {
			$args['user_id'] = $user_id;
		}

		$result = Plugin::query()->query( $args );

		return $cache[ $key ] = is_numeric( $result ) ? (int) $result : (int) count( (array) $result );
	}

	/**
	 * Bulk actions — currently just delete-selected. Future:
	 * "anonymise selected" (GDPR purge of IPs while preserving rows).
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		// Compact mode = no bulk actions. The user-profile embed is a
		// read-only preview; deletion happens on the full admin page.
		if ( $this->compact ) {
			return [];
		}

		return [
			'delete' => __( 'Delete', 'wp-login-activity' ),
		];
	}

	/**
	 * Build the BerlinDB date_created_query payload from the
	 * `from` / `to` URL params.
	 *
	 * Both bounds are optional — admin can scope "from a date with
	 * no upper bound" (audit since incident X), "up to a date with
	 * no lower bound" (everything before retention purges it), or
	 * a closed range. Returns empty array when neither input is set
	 * so the query stays unfiltered.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	private function build_date_query(): array {
		$from_raw = isset( $_REQUEST['from'] ) ? sanitize_text_field( (string) $_REQUEST['from'] ) : '';
		$to_raw   = isset( $_REQUEST['to'] )   ? sanitize_text_field( (string) $_REQUEST['to'] )   : '';

		$from_ts = $from_raw !== '' ? strtotime( $from_raw . ' 00:00:00' ) : 0;
		$to_ts   = $to_raw   !== '' ? strtotime( $to_raw   . ' 23:59:59' ) : 0;

		if ( $from_ts <= 0 && $to_ts <= 0 ) {
			return [];
		}

		$clause = [ 'inclusive' => true ];

		if ( $from_ts > 0 ) {
			$clause['after'] = gmdate( 'Y-m-d H:i:s', $from_ts );
		}

		if ( $to_ts > 0 ) {
			$clause['before'] = gmdate( 'Y-m-d H:i:s', $to_ts );
		}

		return [ $clause ];
	}

	/**
	 * Render the date-range filter inputs above the table.
	 *
	 * `extra_tablenav` is the WP_List_Table extension point for
	 * non-bulk-action filters. Renders inside the standard tablenav
	 * div which is inside the form ActivityPage wraps display() in,
	 * so the inputs auto-submit via the existing Filter button.
	 *
	 * Bottom tablenav skipped — duplicating the filter inputs at the
	 * page foot adds noise without value.
	 *
	 * @since 2.0.0
	 *
	 * @param string $which 'top' or 'bottom'.
	 *
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( $which !== 'top' || $this->compact ) {
			return;
		}

		$from = isset( $_GET['from'] ) ? sanitize_text_field( (string) $_GET['from'] ) : '';
		$to   = isset( $_GET['to'] )   ? sanitize_text_field( (string) $_GET['to'] )   : '';

		?>
		<div class="alignleft actions" style="display:inline-flex;gap:6px;align-items:center;">
			<label for="wpla-from" class="screen-reader-text"><?php esc_html_e( 'From', 'wp-login-activity' ); ?></label>
			<input type="date"
			       id="wpla-from"
			       name="from"
			       value="<?php echo esc_attr( $from ); ?>"
			       placeholder="<?php esc_attr_e( 'From', 'wp-login-activity' ); ?>"
			       title="<?php esc_attr_e( 'From date (inclusive)', 'wp-login-activity' ); ?>" />

			<span aria-hidden="true">–</span>

			<label for="wpla-to" class="screen-reader-text"><?php esc_html_e( 'To', 'wp-login-activity' ); ?></label>
			<input type="date"
			       id="wpla-to"
			       name="to"
			       value="<?php echo esc_attr( $to ); ?>"
			       placeholder="<?php esc_attr_e( 'To', 'wp-login-activity' ); ?>"
			       title="<?php esc_attr_e( 'To date (inclusive)', 'wp-login-activity' ); ?>" />

			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'wp-login-activity' ); ?>" />
		</div>
		<?php
	}

	/**
	 * Suppress the tablenav (top + bottom) entirely in compact mode.
	 *
	 * `WP_List_Table::display()` calls `display_tablenav('top')` then
	 * the table itself then `display_tablenav('bottom')`. The tablenav
	 * is what wraps bulk-action selects, search box, and pagination —
	 * none of which belong on a user-profile-embedded preview. Override
	 * to early-return in compact mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $which 'top' or 'bottom'.
	 *
	 * @return void
	 */
	protected function display_tablenav( $which ): void {
		if ( $this->compact ) {
			return;
		}
		parent::display_tablenav( $which );
	}

	/**
	 * Resolve + dispatch the bulk action.
	 *
	 * Called by `prepare_items()`. Reads the WP-list-table standard
	 * `_wpnonce` from `bulk-{$plural}` (set up by the parent class)
	 * for CSRF protection.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function process_bulk_action(): void {
		if ( $this->current_action() !== 'delete' || empty( $_REQUEST['activity'] ) ) {
			return;
		}

		// Bulk and single-row delete share the same `action=delete`
		// query param. Discriminate by shape: the bulk form submits
		// `activity[]=N&activity[]=M` (PHP unpacks to an array); the
		// row-action link submits `activity=N` (scalar). Picking the
		// wrong nonce here is what produced the "link has expired"
		// error — bulk's `bulk-activities` nonce doesn't match the
		// row link's `wpla_delete_{id}` nonce.
		if ( is_array( $_REQUEST['activity'] ) ) {
			check_admin_referer( 'bulk-activities' );
			$this->delete_rows( array_map( 'absint', (array) $_REQUEST['activity'] ) );

			return;
		}

		$id = absint( (string) $_REQUEST['activity'] );
		if ( $id <= 0 ) {
			return;
		}

		check_admin_referer( 'wpla_delete_' . $id );
		$this->delete_rows( [ $id ] );
	}

	/**
	 * Delete a list of activity rows + emit the standard admin notice.
	 *
	 * Single point through which both bulk-delete and single-delete
	 * flow so the capability gate and "rows actually deleted" notice
	 * stay in one place.
	 *
	 * @since 2.0.0
	 *
	 * @param int[] $ids Row IDs to delete.
	 *
	 * @return void
	 */
	private function delete_rows( array $ids ): void {
		// Cap-gate at the action layer too (the page render is gated
		// already, but defence in depth is cheap). Note: an attacker
		// with `manage_options` can wipe ANY plugin's data; preventing
		// log-tampering at the plugin layer is at best raising-the-
		// bar, not actual prevention. Forwarding to a write-once
		// SIEM is the only real mitigation.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$ids = array_filter( $ids );
		if ( empty( $ids ) ) {
			return;
		}

		$query   = Plugin::query();
		$deleted = 0;

		foreach ( $ids as $id ) {
			if ( $query->delete_item( (int) $id ) ) {
				$deleted++;
			}
		}

		if ( $deleted > 0 ) {
			add_action( 'admin_notices', function () use ( $deleted ): void {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html( sprintf(
						/* translators: %d: number of rows deleted */
						_n( '%d activity row deleted.', '%d activity rows deleted.', $deleted, 'wp-login-activity' ),
						$deleted
					) )
				);
			} );
		}
	}

	/**
	 * Per-row action links rendered below the primary column. The
	 * primary column is "user" by default (or "when" in compact mode);
	 * see `get_default_primary_column_name()`.
	 *
	 *   View details — JS-toggles the hidden detail row beneath
	 *   Filter user — same page, scoped to this user
	 *   Filter IP   — same page, scoped to this IP
	 *   Delete      — single-row delete with nonce
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row         Row.
	 * @param string      $column_name Column slug.
	 * @param string      $primary     The primary column for this table.
	 *
	 * @return string
	 */
	protected function handle_row_actions( $row, $column_name, $primary ): string {
		if ( $column_name !== $primary || $this->compact ) {
			return '';
		}

		$base = admin_url( 'users.php?page=wp-login-activity' );

		$actions = [];

		// "Filter by user" needs an explicit row action because
		// clicking the username opens the flyout (drill into one
		// event), not the filter (scope to all events for that
		// user). Different operations, different paths.
		if ( $row->user_id > 0 ) {
			$actions['filter_user'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( 'user_id', (int) $row->user_id, $base ) ),
				esc_html__( 'Filter by user', 'wp-login-activity' )
			);
		}

		$delete_url = wp_nonce_url(
			add_query_arg(
				[ 'action' => 'delete', 'activity' => (int) $row->id ],
				$base
			),
			'wpla_delete_' . (int) $row->id
		);

		$actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete">%s</a>',
			esc_url( $delete_url ),
			esc_html__( 'Delete', 'wp-login-activity' )
		);

		/**
		 * Filter the per-row action links.
		 *
		 * Plugins can add their own actions ("Send to Slack",
		 * "Block this IP at the firewall") by appending to the array.
		 * Each value should be a fully-formed `<a>` tag.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, string> $actions Slug => HTML link.
		 * @param ActivityRow           $row     Row context.
		 */
		$actions = (array) apply_filters( 'wp_login_activity_row_actions', $actions, $row );

		return $this->row_actions( $actions );
	}

	/**
	 * Make "user" the row-actions primary column on the full admin
	 * page (so the View/Delete/Filter links hang off the user cell,
	 * which is the most identifying column). Compact mode falls back
	 * to "when" since there's no User column.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name(): string {
		return $this->compact ? 'date' : 'username';
	}

	/**
	 * Render a row.
	 *
	 * Two extras vs the parent:
	 *   1. Tints the row when it represents the viewer's current
	 *      session (matched via session-token-hash). Lets people
	 *      spot "this is the laptop I'm on right now" without hunting.
	 *   2. Outputs a hidden second `<tr>` beneath the main row for
	 *      the JS-toggled detail view. Keeps DOM order so screen
	 *      readers traverse the detail in context.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $item Row.
	 *
	 * @return void
	 */
	public function single_row( $item ): void {
		// Manual zebra striping. WP's `striped` class uses
		// `tr:nth-child(odd)`, which counts EVERY child — and the
		// pre-flyout era injected hidden detail rows that threw the
		// parity off. The flyout removes that issue but we keep the
		// manual striping so the override is unambiguous regardless
		// of any future row injections.
		static $stripe_index = 0;
		$stripe_index++;

		$classes = [ 'wpla-data-row' ];

		if ( $stripe_index % 2 === 0 ) {
			$classes[] = 'wpla-alt';
		}

		if ( $item->is_current_session() ) {
			$classes[] = 'wpla-current-session';
		}

		printf(
			'<tr id="wpla-row-%d" class="%s">',
			(int) $item->id,
			esc_attr( implode( ' ', $classes ) )
		);
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Render the after-table block that powers the flyout panel.
	 *
	 * Outputs one `<template id="wpla-detail-{N}">` per visible row
	 * holding the row's detail HTML (sectioned: USER / WHERE / HOW /
	 * RAW), plus the flyout shell itself (one container, reused
	 * across all rows) and a backdrop. JS clones the matching
	 * template into the flyout body when "View details" is clicked.
	 *
	 * Templates live OUTSIDE the table — `<template>` isn't a valid
	 * direct child of `<tbody>` and gets reparented by the parser
	 * when nested inside one.
	 *
	 * Skipped in compact mode (no flyout on the user-profile embed
	 * — that surface is space-constrained already).
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render_flyout_payload(): void {
		if ( $this->compact || empty( $this->items ) ) {
			return;
		}

		foreach ( $this->items as $item ) {
			if ( ! $item instanceof ActivityRow ) {
				continue;
			}
			$this->render_detail_template( $item );
		}

		$this->render_flyout_shell();
	}

	/**
	 * One per-row detail `<template>` — content cloned into the
	 * flyout on demand.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row.
	 *
	 * @return void
	 */
	private function render_detail_template( ActivityRow $row ): void {
		?>
		<template id="wpla-detail-<?php echo (int) $row->id; ?>">
			<?php $this->render_detail_body( $row ); ?>
		</template>
		<?php
	}

	/**
	 * Sectioned detail layout for one row — USER / WHERE / HOW / RAW.
	 *
	 * Rendered inside the flyout body. Each section is a `<dl>` of
	 * label/value pairs with empty values filtered out so the layout
	 * doesn't fill with em-dashes for fields the row didn't have.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row.
	 *
	 * @return void
	 */
	private function render_detail_body( ActivityRow $row ): void {
		$user    = $row->user_id > 0 ? get_userdata( $row->user_id ) : false;
		$avatar  = $user ? get_avatar( $row->user_id, 48 ) : '';
		$display = $user ? (string) $user->display_name : $row->get_display_name();

		// Drill-in URLs for the action buttons at the foot.
		$base_url      = admin_url( 'users.php?page=wp-login-activity' );
		$filter_user_url = $row->user_id > 0
			? add_query_arg( 'user_id', (int) $row->user_id, $base_url )
			: '';
		$filter_ip_url = $row->ip_address !== ''
			? add_query_arg( 's', $row->ip_address, $base_url )
			: '';
		$delete_url = wp_nonce_url(
			add_query_arg( [ 'action' => 'delete', 'activity' => (int) $row->id ], $base_url ),
			'wpla_delete_' . (int) $row->id
		);

		// External-lookup services (IPInfo by default, plus whatever
		// the wp_login_activity_ip_lookup_services filter adds).
		// Used to live in a kebab dropdown on the IP column — moved
		// here to keep the column tight; same plugin extension point.
		$ip_services = $row->ip_address !== ''
			? $this->ip_lookup_services( $row->ip_address, $row )
			: [];

		// Pre-build sections as label-value arrays then render in a
		// loop so empty-value rows can be skipped uniformly.
		$user_pairs = array_filter( [
			__( 'Username',    'wp-login-activity' ) => $user ? $user->user_login : ( $row->identifier !== '' ? $row->identifier : '' ),
			__( 'Display name','wp-login-activity' ) => $user ? $display : '',
			__( 'Role',        'wp-login-activity' ) => $row->get_role_label(),
			__( 'Performed by','wp-login-activity' ) => $row->get_actor_display_name(),
		], static fn( $v ) => $v !== '' );

		$where_pairs = array_filter( [
			__( 'IP address',     'wp-login-activity' ) => $row->ip_address,
			__( 'Anonymised IP',  'wp-login-activity' ) => $row->get_anonymised_ip(),
			__( 'Country',        'wp-login-activity' ) => $row->country_code,
			__( 'Country source', 'wp-login-activity' ) => $row->country_source,
		], static fn( $v ) => $v !== '' );

		$how_pairs = array_filter( [
			__( 'Browser',  'wp-login-activity' ) => $row->get_browser(),
			__( 'OS',       'wp-login-activity' ) => $row->get_os(),
			__( 'Device',   'wp-login-activity' ) => $row->get_device_type(),
			__( 'Language', 'wp-login-activity' ) => $row->get_primary_language(),
			__( 'Referer',  'wp-login-activity' ) => $row->referer,
		], static fn( $v ) => $v !== '' );

		$raw_pairs = array_filter( [
			__( 'Identifier (typed)',  'wp-login-activity' ) => $row->identifier,
			__( 'Accept-Language',     'wp-login-activity' ) => $row->accept_language,
			__( 'User-Agent',          'wp-login-activity' ) => $row->user_agent,
			__( 'UUID',                'wp-login-activity' ) => $row->uuid,
		], static fn( $v ) => $v !== '' );

		$ts        = strtotime( $row->date_created . ' UTC' );
		$date_disp = $ts
			? sprintf(
				/* translators: 1: date, 2: time */
				esc_html__( '%1$s at %2$s', 'wp-login-activity' ),
				esc_html( wp_date( __( 'Y/m/d', 'wp-login-activity' ), $ts ) ),
				esc_html( wp_date( __( 'g:i a', 'wp-login-activity' ), $ts ) )
			)
			: esc_html( $row->date_created );

		[ $bg, $fg ] = $this->event_colours( $row->event_type );

		?>
		<header class="wpla-flyout__header">
			<?php if ( $avatar !== '' ) : ?>
				<span class="wpla-flyout__avatar"><?php echo $avatar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<?php endif; ?>
			<div class="wpla-flyout__header-meta">
				<div class="wpla-flyout__badges">
					<span class="wpla-flyout__event-badge" style="background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $fg ); ?>;">
						<?php echo esc_html( $this->event_label( $row->event_type ) ); ?>
					</span>
					<?php if ( $row->is_current_session() ) : ?>
						<span class="wpla-flyout__current-session-badge">
							<?php esc_html_e( 'Current session', 'wp-login-activity' ); ?>
						</span>
					<?php endif; ?>
				</div>
				<h2 class="wpla-flyout__title"><?php echo esc_html( $display ); ?></h2>
				<div class="wpla-flyout__subtitle"><?php echo $date_disp; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</div>
		</header>

		<div class="wpla-flyout__body">
			<?php $this->render_flyout_section( __( 'User',  'wp-login-activity' ), $user_pairs ); ?>
			<?php $this->render_flyout_section( __( 'Where', 'wp-login-activity' ), $where_pairs ); ?>
			<?php $this->render_flyout_section( __( 'How',   'wp-login-activity' ), $how_pairs ); ?>
			<?php $this->render_flyout_section( __( 'Raw',   'wp-login-activity' ), $raw_pairs ); ?>

			<?php if ( ! empty( $ip_services ) ) : ?>
				<section class="wpla-flyout__section">
					<h3><?php esc_html_e( 'External Lookup', 'wp-login-activity' ); ?></h3>
					<div class="wpla-flyout__links">
						<?php foreach ( $ip_services as $service ) : ?>
							<a href="<?php echo esc_url( (string) $service['url'] ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( (string) $service['label'] ); ?> ↗
							</a>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>
		</div>

		<footer class="wpla-flyout__footer">
			<?php if ( $filter_user_url !== '' ) : ?>
				<a href="<?php echo esc_url( $filter_user_url ); ?>" class="button"><?php esc_html_e( 'Filter by user', 'wp-login-activity' ); ?></a>
			<?php endif; ?>
			<?php if ( $filter_ip_url !== '' ) : ?>
				<a href="<?php echo esc_url( $filter_ip_url ); ?>" class="button"><?php esc_html_e( 'Filter by IP', 'wp-login-activity' ); ?></a>
			<?php endif; ?>
			<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-link-delete wpla-flyout__footer-delete"><?php esc_html_e( 'Delete', 'wp-login-activity' ); ?></a>
		</footer>
		<?php
	}

	/**
	 * Render one section of the flyout body.
	 *
	 * Each section is a labelled card with a `form-table`-style
	 * label/value layout — feels native because it borrows the
	 * exact pattern WP uses for Settings screens.
	 *
	 * @since 2.0.0
	 *
	 * @param string                $title Section heading.
	 * @param array<string, string> $pairs Label => value (already filtered).
	 *
	 * @return void
	 */
	private function render_flyout_section( string $title, array $pairs ): void {
		if ( empty( $pairs ) ) {
			return;
		}

		?>
		<section class="wpla-flyout__section">
			<h3><?php echo esc_html( $title ); ?></h3>
			<table class="wpla-flyout__table">
				<tbody>
					<?php foreach ( $pairs as $label => $value ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( (string) $label ); ?></th>
							<td><?php echo esc_html( (string) $value ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Render the empty flyout shell + backdrop. JS populates the
	 * body and toggles the `is-open` class on click.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function render_flyout_shell(): void {
		?>
		<div class="wpla-flyout-backdrop" hidden></div>
		<aside class="wpla-flyout" role="dialog" aria-labelledby="wpla-flyout-title" aria-hidden="true" hidden>
			<button type="button" class="wpla-flyout__close" aria-label="<?php esc_attr_e( 'Close', 'wp-login-activity' ); ?>">×</button>
			<div class="wpla-flyout__content"></div>
		</aside>
		<?php
	}

	/**
	 * The pre-render workhorse — runs the BerlinDB query, sets up
	 * pagination args, processes bulk actions.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->process_bulk_action();

		$columns  = $this->get_columns();
		$hidden   = $this->get_hidden_columns();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = [ $columns, $hidden, $sortable ];

		$per_page = $this->forced_per_page > 0
			? $this->forced_per_page
			: $this->get_items_per_page( self::PER_PAGE_OPTION, self::DEFAULT_PER_PAGE );

		$paged    = max( 1, (int) ( $_REQUEST['paged'] ?? 1 ) );

		$args = [
			'number' => $per_page,
			'offset' => ( $paged - 1 ) * $per_page,
			'paged'  => $paged,
		];

		// Sort handling — validate orderby against the sortable
		// allowlist so a hostile `?orderby=…` can't reach BerlinDB
		// with an arbitrary column name.
		$requested_orderby = isset( $_REQUEST['orderby'] ) ? (string) $_REQUEST['orderby'] : 'date_created';
		$orderby_allowed   = array_column( $sortable, 0 );

		$args['orderby'] = in_array( $requested_orderby, $orderby_allowed, true )
			? $requested_orderby
			: 'date_created';

		$args['order'] = ( strtoupper( (string) ( $_REQUEST['order'] ?? 'DESC' ) ) === 'ASC' ) ? 'ASC' : 'DESC';

		// Filters (event type / search / single-user view).
		$event_type = isset( $_REQUEST['event_type'] ) ? sanitize_key( (string) $_REQUEST['event_type'] ) : '';
		if ( $event_type !== '' ) {
			$args['event_type'] = $event_type;
		}

		// Forced user-ID (compact embed) wins over `?user_id=` on the URL.
		$user_id = $this->forced_user_id > 0
			? $this->forced_user_id
			: ( isset( $_REQUEST['user_id'] ) ? absint( (string) $_REQUEST['user_id'] ) : 0 );

		if ( $user_id > 0 ) {
			$args['user_id'] = $user_id;
		}

		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
		if ( $search !== '' ) {
			$args['search'] = $search;
		}

		// Date range — both ends optional. We expand the YYYY-MM-DD
		// input to start-of-day / end-of-day so an admin filtering
		// "from 2026-05-01 to 2026-05-01" gets rows from the WHOLE
		// of May 1 instead of just 00:00:00 sharp.
		$date_query = $this->build_date_query();
		if ( ! empty( $date_query ) ) {
			$args['date_created_query'] = $date_query;
		}

		// Optional: filter to first-time-country-only rows when
		// the quick-stats "New-country logins" link is followed.
		if ( ! empty( $_REQUEST['is_new_country'] ) ) {
			$args['is_new_country'] = 1;
		}

		$query        = Plugin::query();
		$this->items  = (array) $query->query( $args );
		$total_items  = (int) $query->found_items;

		$this->set_pagination_args( [
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => max( 1, (int) ceil( $total_items / $per_page ) ),
		] );
	}

	/**
	 * Default column renderer — used when there's no `column_$key()`
	 * specifically defined.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row    Row model.
	 * @param string      $column Column key.
	 *
	 * @return string
	 */
	public function column_default( $row, $column ): string {
		return '—';
	}

	/**
	 * Bulk-action checkbox.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row.
	 *
	 * @return string
	 */
	public function column_cb( $row ): string {
		return sprintf(
			'<input type="checkbox" name="activity[]" value="%d" />',
			(int) $row->id
		);
	}

	/**
	 * "When" column — absolute UTC timestamp by default; relative
	 * ("2 hours ago") when the screen option is enabled.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row.
	 *
	 * @return string
	 */
	public function column_date( ActivityRow $row ): string {
		if ( $row->date_created === '' || $row->date_created === '0000-00-00 00:00:00' ) {
			return '—';
		}

		// Stored timestamps are UTC; convert to site timezone for
		// display via wp_date() (which also handles i18n month names).
		$ts = strtotime( $row->date_created . ' UTC' );
		if ( ! $ts ) {
			return esc_html( $row->date_created );
		}

		// Two-line cell — absolute on top, relative muted underneath.
		// Same shape Posts/Pages list-table uses ("Published" + the
		// formatted timestamp). We skip the status word since every
		// activity row is "happened at"; the second line gives the
		// at-a-glance "how long ago" without an extra toggle.
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

		return $absolute . '<br /><span style="color:#646970;font-size:12px;">' . $relative . '</span>';
	}

	/**
	 * "Username" column — the literal user_login string with the
	 * user's avatar prefixed, matching WP core's Users-list-table
	 * primary column. For unresolved rows (failed-login attempts on
	 * non-existent users), shows whatever the visitor typed in
	 * monospace so admins can spot scripted attempts at a glance.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row.
	 *
	 * @return string
	 */
	public function column_username( ActivityRow $row ): string {
		// Click on the username opens the flyout panel with full
		// detail for this row — matches WP convention where clicking
		// the primary column drills into "the thing". The
		// `wpla-toggle-details` JS handler hooks any link with that
		// class. "Filter by user" stays accessible via the hover-
		// revealed row action.
		$trigger_attrs = sprintf(
			'class="wpla-toggle-details" data-row-id="%d" aria-expanded="false"',
			(int) $row->id
		);

		if ( $row->user_id > 0 ) {
			$user = get_userdata( $row->user_id );
			if ( $user ) {
				$avatar = get_avatar( $row->user_id, 32 );
				$link   = sprintf(
					'<strong><a href="#" %s title="%s">%s</a></strong>',
					$trigger_attrs,
					esc_attr__( 'View full event details', 'wp-login-activity' ),
					esc_html( (string) $user->user_login )
				);

				return $avatar . ' ' . $link;
			}
		}

		// Unresolved row — typed identifier in monospace, also a
		// flyout trigger so admins can see exactly what was typed
		// alongside everything else captured for the failed attempt.
		if ( $row->identifier !== '' ) {
			return sprintf(
				'<a href="#" %s title="%s"><code>%s</code></a>',
				$trigger_attrs,
				esc_attr__( 'View full event details', 'wp-login-activity' ),
				esc_html( $row->identifier )
			);
		}

		return '—';
	}

	/**
	 * "Event" column.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row.
	 *
	 * @return string
	 */
	public function column_event( ActivityRow $row ): string {
		// Colour-coded badge per event type, matching the visual
		// vocabulary EDD uses for order/payment statuses. Picks a
		// background + text colour from a fixed palette so admins
		// can pattern-scan the column at a glance: red = trouble,
		// green = normal, amber = notable, grey = informational.
		[ $bg, $fg ] = $this->event_colours( $row->event_type );

		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.3px;background:%s;color:%s;">%s</span>',
			esc_attr( $bg ),
			esc_attr( $fg ),
			esc_html( $this->event_label( $row->event_type ) )
		);
	}

	/**
	 * Pick the badge background + foreground colours for an event.
	 *
	 *   login            → green   (normal, expected)
	 *   logout           → grey    (neutral, informational)
	 *   login_failed     → red     (negative signal)
	 *   registered       → blue    (notable but expected)
	 *   password_changed → amber   (notable, ambiguous severity)
	 *   email_changed    → amber   (same threat profile as password)
	 *   admin_assigned   → red     (high-severity security event)
	 *
	 * @since 2.0.0
	 *
	 * @param string $event_type Event slug.
	 *
	 * @return array{0:string,1:string} `[background, foreground]`.
	 */
	private function event_colours( string $event_type ): array {
		$palette = [
			'login'            => [ '#dcf5e6', '#1b6e3a' ],  // green
			'logout'           => [ '#e9eaee', '#50575e' ],  // grey
			'login_failed'     => [ '#fde2e2', '#9b1c1c' ],  // red
			'registered'       => [ '#e0ecfb', '#1d4ed8' ],  // blue
			'password_changed' => [ '#fef3c7', '#92400e' ],  // amber
			'email_changed'    => [ '#fef3c7', '#92400e' ],  // amber
			'admin_assigned'   => [ '#fcd5d5', '#7f1d1d' ],  // red (deeper)
		];

		/**
		 * Filter the event-badge colour palette.
		 *
		 * Plugins registering custom event types can extend this
		 * palette by adding their own slug => [bg, fg] entry. Returning
		 * a tuple for an existing slug overrides the default.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, array{0:string,1:string}> $palette Slug => [bg, fg].
		 * @param string                                  $event_type The slug being rendered.
		 */
		$palette = (array) apply_filters( 'wp_login_activity_event_colours', $palette, $event_type );

		return $palette[ $event_type ] ?? [ '#e9eaee', '#50575e' ];
	}

	/**
	 * "IP" column.
	 *
	 * Click filters the table by this IP — the dominant "show me
	 * everything from this address" workflow. External lookup
	 * services (IPInfo, AbuseIPDB, etc.) live in the flyout footer
	 * now rather than a kebab dropdown next to the cell — the cell
	 * stays focused on its primary action and the lookup options
	 * are still one click away via the row's flyout.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row.
	 *
	 * @return string
	 */
	public function column_ip( ActivityRow $row ): string {
		if ( $row->ip_address === '' ) {
			return '—';
		}

		$base       = admin_url( 'users.php?page=wp-login-activity' );
		$filter_url = add_query_arg( 's', $row->ip_address, $base );

		return sprintf(
			'<a href="%s" title="%s"><code>%s</code></a>',
			esc_url( $filter_url ),
			esc_attr__( 'Filter the activity log by this IP', 'wp-login-activity' ),
			esc_html( $row->ip_address )
		);
	}

	/**
	 * Build the external-lookup-service list for an IP.
	 *
	 * Default: IPInfo.io. Plugins extend by hooking
	 * `wp_login_activity_ip_lookup_services`. The legacy
	 * `wp_login_activity_ip_lookup_url` filter still works as a
	 * back-compat shim that mutates the IPInfo entry.
	 *
	 * @since 2.0.0
	 *
	 * @param string      $ip  The IP being looked up.
	 * @param ActivityRow $row Row context.
	 *
	 * @return array<int, array{label:string,url:string}>
	 */
	private function ip_lookup_services( string $ip, ActivityRow $row ): array {
		$ipinfo_url = 'https://ipinfo.io/' . rawurlencode( $ip );

		// Back-compat: the older `_ip_lookup_url` filter still wins
		// over the default IPInfo URL if anyone's already hooked it.
		$ipinfo_url = (string) apply_filters( 'wp_login_activity_ip_lookup_url', $ipinfo_url, $ip, $row );

		$services = [
			[
				'label' => __( 'Check on IPInfo.io', 'wp-login-activity' ),
				'url'   => $ipinfo_url,
			],
		];

		/**
		 * Filter the list of external IP-lookup services rendered
		 * in the column dropdown.
		 *
		 * Each entry is `[ 'label' => string, 'url' => string ]`.
		 * Plugins can append AbuseIPDB / VirusTotal / Shodan / etc.,
		 * or remove services they don't want exposed.
		 *
		 * @since 2.0.0
		 *
		 * @param array       $services Default list.
		 * @param string      $ip       The IP being looked up.
		 * @param ActivityRow $row      Row context.
		 */
		$services = (array) apply_filters( 'wp_login_activity_ip_lookup_services', $services, $ip, $row );

		// Defensive shape validation — drop any entry missing label
		// or URL so a misconfigured filter doesn't render <a> with
		// empty href.
		return array_values( array_filter(
			$services,
			static fn( $svc ) => is_array( $svc ) && ! empty( $svc['label'] ) && ! empty( $svc['url'] )
		) );
	}

	/**
	 * "Country" column — code plus a NEW badge when the row is the
	 * first time we've seen this user-country pair.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row.
	 *
	 * @return string
	 */
	public function column_country( ActivityRow $row ): string {
		if ( $row->country_code === '' ) {
			return '—';
		}

		// Use wp-countries to render flag + full name, falling back
		// to the raw code if the library isn't loaded for any reason
		// (e.g. someone deleted the vendor dir but the row data still
		// resolves). format() returns "🇬🇧 United Kingdom".
		$value = class_exists( Countries::class )
			? esc_html( Countries::format( $row->country_code, true, false ) )
			: esc_html( $row->country_code );

		if ( $row->is_new_country() ) {
			$value .= ' <span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:3px;font-size:11px;margin-left:4px;">' . esc_html__( 'NEW', 'wp-login-activity' ) . '</span>';
		}

		return $value;
	}

	/**
	 * Empty-state.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No activity recorded yet.', 'wp-login-activity' );
	}

	/**
	 * Translate event slug → display label. Lives here AND on the
	 * UserProfile screen — extract to a shared helper if a third
	 * caller appears.
	 *
	 * @since 2.0.0
	 *
	 * @param string $event_type Event slug.
	 *
	 * @return string
	 */
	private function event_label( string $event_type ): string {
		$labels = [
			'login'             => __( 'Login',                  'wp-login-activity' ),
			'login_failed'      => __( 'Failed login',           'wp-login-activity' ),
			'logout'            => __( 'Logout',                 'wp-login-activity' ),
			'registered'        => __( 'Registered',             'wp-login-activity' ),
			'password_changed'  => __( 'Password changed',       'wp-login-activity' ),
			'email_changed'     => __( 'Email changed',          'wp-login-activity' ),
			'admin_assigned'    => __( 'Admin role assigned',    'wp-login-activity' ),
		];

		/**
		 * Filter the event-type display labels.
		 *
		 * Plugins that emit custom event types via the
		 * `wp_login_activity_pre_insert_data` filter (or by inserting
		 * directly via Plugin::query()->add_item()) can register the
		 * display label here so the list table renders them with a
		 * human-friendly string instead of the raw slug.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, string> $labels    Slug => translated label.
		 * @param string                $event_type Slug being rendered.
		 */
		$labels = (array) apply_filters( 'wp_login_activity_event_labels', $labels, $event_type );

		return $labels[ $event_type ] ?? $event_type;
	}

}
