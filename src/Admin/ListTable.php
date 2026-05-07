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

		return [
			'cb'       => '<input type="checkbox" />',
			'username' => __( 'Username', 'wp-login-activity' ),
			'name'     => __( 'Name',     'wp-login-activity' ),
			'role'     => __( 'Role',     'wp-login-activity' ),
			'event'    => __( 'Event',    'wp-login-activity' ),
			'ip'       => __( 'IP',       'wp-login-activity' ),
			'country'  => __( 'Country',  'wp-login-activity' ),
			'device'   => __( 'Device',   'wp-login-activity' ),
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
		// Username is the primary identifier column; not hideable.
		// Name + Role + IP + Country + Device are all toggleable via
		// Screen Options. Date is required (anchors the sort).
		return [ 'name', 'role', 'ip', 'country', 'device' ];
	}

	/**
	 * Columns hidden by default for fresh users (no saved Screen
	 * Options state yet). WP merges this with the user's saved
	 * preferences via the `default_hidden_columns` filter hook,
	 * which we wire from ActivityPage. Empty here — every column
	 * ships visible; admins opt into hiding via Screen Options.
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
			'name'     => [ 'user_id', false ],
			'event'    => [ 'event_type', false ],
			'ip'       => [ 'ip_address', false ],
			'country'  => [ 'country_code', false ],
			'role'     => [ 'user_role', false ],
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
		// Bulk-delete via the dropdown.
		if ( $this->current_action() === 'delete' && ! empty( $_REQUEST['activity'] ) ) {
			check_admin_referer( 'bulk-activities' );
			$this->delete_rows( array_map( 'absint', (array) $_REQUEST['activity'] ) );

			return;
		}

		// Single-row delete via the row-action link
		// (`?action=delete&activity=N&_wpnonce=...`).
		if ( ! empty( $_REQUEST['action'] ) && $_REQUEST['action'] === 'delete' && ! empty( $_REQUEST['activity'] ) ) {
			$id = absint( (string) $_REQUEST['activity'] );
			if ( $id > 0 ) {
				check_admin_referer( 'wpla_delete_' . $id );
				$this->delete_rows( [ $id ] );
			}
		}
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

		$actions['view'] = sprintf(
			'<a href="#" class="wpla-toggle-details" data-row-id="%d" aria-expanded="false">%s</a>',
			(int) $row->id,
			esc_html__( 'View details', 'wp-login-activity' )
		);

		if ( $row->user_id > 0 ) {
			$actions['filter_user'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( 'user_id', (int) $row->user_id, $base ) ),
				esc_html__( 'Filter by user', 'wp-login-activity' )
			);
		}

		if ( $row->ip_address !== '' ) {
			$actions['filter_ip'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( 's', $row->ip_address, $base ) ),
				esc_html__( 'Filter by IP', 'wp-login-activity' )
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
		// `tr:nth-child(odd)`, which counts EVERY child including the
		// hidden detail rows we inject between data rows — that
		// throws the alternation off and ends up colouring every
		// data row the same. We toggle a class ourselves and override
		// WP's selector via inline CSS so the alternation tracks
		// data rows only.
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

		// Detail row — hidden by default; JS toggles `hidden` attr.
		// Skipped in compact mode (the embed surface is already
		// space-constrained; full details belong on the main page).
		if ( ! $this->compact ) {
			$this->render_detail_row( $item );
		}
	}

	/**
	 * Render the JS-toggled detail row.
	 *
	 * Shows everything that doesn't fit in the main columns: raw
	 * User-Agent, country source, identifier (typed value), referer,
	 * actor (when distinct from subject), session-current marker,
	 * UUID. One `<tr><td colspan>` collapsing the detail into a
	 * single grid cell.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row.
	 *
	 * @return void
	 */
	private function render_detail_row( ActivityRow $row ): void {
		$column_count = count( $this->get_columns() );

		// Collect KV pairs — only include rows that have meaningful
		// values, so the detail isn't padded with em-dashes.
		$pairs = [];

		$pairs[ __( 'When (UTC)',     'wp-login-activity' ) ] = $row->date_created;
		$pairs[ __( 'Identifier',     'wp-login-activity' ) ] = $row->identifier !== '' ? $row->identifier : '—';
		$pairs[ __( 'Country source', 'wp-login-activity' ) ] = $row->country_source !== '' ? $row->country_source : '—';
		$pairs[ __( 'Anonymised IP',  'wp-login-activity' ) ] = $row->get_anonymised_ip() !== '' ? $row->get_anonymised_ip() : '—';

		$browser = $row->get_browser();
		$os      = $row->get_os();
		if ( $browser !== '' || $os !== '' ) {
			$pairs[ __( 'Browser / OS', 'wp-login-activity' ) ] = trim( $browser . ' / ' . $os, ' /' );
		}

		if ( $row->user_agent !== '' ) {
			$pairs[ __( 'User-Agent (raw)', 'wp-login-activity' ) ] = $row->user_agent;
		}

		if ( $row->referer !== '' ) {
			$pairs[ __( 'Referer', 'wp-login-activity' ) ] = $row->referer;
		}

		if ( $row->has_distinct_actor() ) {
			$pairs[ __( 'Performed by', 'wp-login-activity' ) ] = $row->get_actor_display_name();
		}

		if ( $row->is_current_session() ) {
			$pairs[ __( 'Session', 'wp-login-activity' ) ] = __( 'This is your current session.', 'wp-login-activity' );
		}

		$pairs[ __( 'UUID', 'wp-login-activity' ) ] = $row->uuid !== '' ? $row->uuid : '—';

		?>
		<tr id="wpla-detail-<?php echo (int) $row->id; ?>" class="wpla-detail-row" hidden>
			<td colspan="<?php echo (int) $column_count; ?>" style="background:#f6f7f7;padding:14px 18px;">
				<dl style="margin:0;display:grid;grid-template-columns:max-content 1fr;gap:4px 16px;font-size:13px;">
					<?php foreach ( $pairs as $label => $value ) : ?>
						<dt style="font-weight:600;color:#50575e;"><?php echo esc_html( (string) $label ); ?></dt>
						<dd style="margin:0;word-break:break-all;"><?php echo esc_html( (string) $value ); ?></dd>
					<?php endforeach; ?>
				</dl>
			</td>
		</tr>
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
		if ( $row->user_id > 0 ) {
			$user = get_userdata( $row->user_id );
			if ( $user ) {
				$avatar = get_avatar( $row->user_id, 32 );
				$link   = sprintf(
					'<strong><a href="%s">%s</a></strong>',
					esc_url( get_edit_user_link( $row->user_id ) ),
					esc_html( (string) $user->user_login )
				);

				return $avatar . ' ' . $link;
			}
		}

		// Unresolved row — show the typed identifier in monospace.
		// No avatar (we don't know the user) and no link.
		return $row->identifier !== ''
			? '<code>' . esc_html( $row->identifier ) . '</code>'
			: '—';
	}

	/**
	 * "Name" column — the user's display_name, mirroring WP core's
	 * Users-list-table second column. Linked to the edit-user screen
	 * the same way Username is.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row.
	 *
	 * @return string
	 */
	public function column_name( ActivityRow $row ): string {
		if ( $row->user_id > 0 ) {
			$user = get_userdata( $row->user_id );
			if ( $user ) {
				return sprintf(
					'<a href="%s">%s</a>',
					esc_url( get_edit_user_link( $row->user_id ) ),
					esc_html( (string) $user->display_name )
				);
			}
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

		return $palette[ $event_type ] ?? [ '#e9eaee', '#50575e' ];
	}

	/**
	 * "Role" column — snapshot of the user's primary role at the
	 * time of the event.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row.
	 *
	 * @return string
	 */
	public function column_role( ActivityRow $row ): string {
		$label = $row->get_role_label();

		return $label !== '' ? esc_html( $label ) : '—';
	}

	/**
	 * "IP" column. Renders the address as a link to ipinfo.io's
	 * lookup page for that IP — admins investigating an event almost
	 * always want full external intel (ASN, ISP, abuse history,
	 * geographic precision) which a third-party service does better
	 * than we ever could from a local table.
	 *
	 * The "Filter by IP" row action stays available for the separate
	 * "show me everything from this IP on this site" workflow.
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

		/**
		 * Filter the URL the IP cell links to. Allows swapping
		 * IPInfo for a different reputation service (AbuseIPDB,
		 * VirusTotal, Shodan, an internal tool) site-wide.
		 *
		 * @since 2.0.0
		 *
		 * @param string      $url Default URL (https://ipinfo.io/{ip}).
		 * @param string      $ip  The IP being looked up.
		 * @param ActivityRow $row Row context.
		 */
		$lookup_url = apply_filters(
			'wp_login_activity_ip_lookup_url',
			'https://ipinfo.io/' . rawurlencode( $row->ip_address ),
			$row->ip_address,
			$row
		);

		return sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer" title="%s"><code>%s</code></a>',
			esc_url( $lookup_url ),
			esc_attr__( 'Look up this IP on IPInfo.io', 'wp-login-activity' ),
			esc_html( $row->ip_address )
		);
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
