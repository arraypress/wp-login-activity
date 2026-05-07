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
	 * The screen-option key for relative-time rendering.
	 *
	 * @since 2.0.0
	 */
	public const RELATIVE_TIME_OPTION = 'wpla_relative_time';

	/**
	 * Default rows-per-page when no screen-option set.
	 *
	 * @since 2.0.0
	 */
	private const DEFAULT_PER_PAGE = 50;

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

		parent::__construct( [
			'singular' => 'activity',
			'plural'   => 'activities',
			'ajax'     => false,
			'screen'   => 'wpla-activity',
		] );
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
		$columns = [];

		// Compact mode (user-profile embed) skips the bulk-action
		// checkbox AND the "User" column — the surrounding context
		// already names the user, repeating it on every row would
		// be visual noise.
		if ( ! $this->compact ) {
			$columns['cb']   = '<input type="checkbox" />';
		}

		$columns['when']  = __( 'When',  'wp-login-activity' );

		if ( ! $this->compact ) {
			$columns['user'] = __( 'User', 'wp-login-activity' );
		}

		$columns['event']   = __( 'Event',   'wp-login-activity' );
		$columns['ip']      = __( 'IP',      'wp-login-activity' );
		$columns['country'] = __( 'Country', 'wp-login-activity' );
		$columns['device']  = __( 'Device',  'wp-login-activity' );

		return $columns;
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
		return [ 'ip', 'country', 'device' ];
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
		return [
			'when'    => [ 'date_created', true ],   // default-desc
			'user'    => [ 'user_id', false ],
			'event'   => [ 'event_type', false ],
			'ip'      => [ 'ip_address', false ],
			'country' => [ 'country_code', false ],
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
		if ( $this->current_action() !== 'delete' ) {
			return;
		}

		check_admin_referer( 'bulk-activities' );

		$ids = array_map( 'absint', (array) ( $_REQUEST['activity'] ?? [] ) );
		$ids = array_filter( $ids );

		if ( empty( $ids ) ) {
			return;
		}

		$query   = Plugin::query();
		$deleted = 0;

		foreach ( $ids as $id ) {
			if ( $query->delete_item( $id ) ) {
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
	public function column_when( ActivityRow $row ): string {
		if ( $row->date_created === '' || $row->date_created === '0000-00-00 00:00:00' ) {
			return '—';
		}

		// Compact (user-profile embed) defaults to relative — that
		// surface is "at a glance" recent activity, not forensics.
		// The full admin page respects the user's screen-options
		// preference (default off so timestamps stay exact).
		if ( $this->compact ) {
			$relative = true;
		} else {
			$relative = get_user_meta( get_current_user_id(), self::RELATIVE_TIME_OPTION, true ) === '1';
		}

		if ( $relative ) {
			$ts = strtotime( $row->date_created . ' UTC' );
			if ( $ts ) {
				return sprintf(
					/* translators: %s: human-readable time difference (e.g. "2 hours") */
					esc_html__( '%s ago', 'wp-login-activity' ),
					esc_html( human_time_diff( $ts, time() ) )
				);
			}
		}

		return esc_html( $row->date_created . ' UTC' );
	}

	/**
	 * "User" column.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row.
	 *
	 * @return string
	 */
	public function column_user( ActivityRow $row ): string {
		$display = esc_html( $row->get_display_name() );

		if ( $row->user_id > 0 ) {
			return sprintf(
				'<a href="%s">%s</a>',
				esc_url( get_edit_user_link( $row->user_id ) ),
				$display
			);
		}

		return $display;
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
		return esc_html( $this->event_label( $row->event_type ) );
	}

	/**
	 * "IP" column.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row.
	 *
	 * @return string
	 */
	public function column_ip( ActivityRow $row ): string {
		return $row->ip_address !== ''
			? '<code>' . esc_html( $row->ip_address ) . '</code>'
			: '—';
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
		$value = $row->country_code !== ''
			? esc_html( $row->country_code )
			: '—';

		if ( $row->is_new_country() ) {
			$value .= ' <span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:3px;font-size:11px;margin-left:4px;">' . esc_html__( 'NEW', 'wp-login-activity' ) . '</span>';
		}

		return $value;
	}

	/**
	 * "Device" column — formatted UA + BOT badge.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row.
	 *
	 * @return string
	 */
	public function column_device( ActivityRow $row ): string {
		$formatted = $row->get_formatted_user_agent();
		$value     = $formatted !== '' ? esc_html( $formatted ) : '—';

		if ( $row->is_bot() ) {
			$value .= ' <span style="background:#fde2e2;color:#9b1c1c;padding:1px 6px;border-radius:3px;font-size:11px;margin-left:4px;">' . esc_html__( 'BOT', 'wp-login-activity' ) . '</span>';
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

		return $labels[ $event_type ] ?? $event_type;
	}

}
