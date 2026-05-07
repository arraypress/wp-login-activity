<?php
/**
 * Admin Activity Page
 *
 * Users → Login Activity. Hosts the full-fidelity `WP_List_Table`
 * defined in `Admin\ListTable` — handles registration, screen-options
 * wiring (per-page count + relative-time toggle), and help-tab content.
 *
 * Activity-list rendering itself lives entirely on `ListTable`; this
 * class is just the screen scaffolding.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class ActivityPage
 *
 * @since 2.0.0
 */
class ActivityPage {

	/**
	 * Page slug.
	 *
	 * @since 2.0.0
	 */
	private const PAGE_SLUG = 'wp-login-activity';

	/**
	 * The screen hook returned by `add_users_page`. Captured at
	 * registration time so we can attach screen options + help tabs
	 * to exactly the right screen.
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	private string $hook = '';

	/**
	 * Lazily-instantiated list table. Built once `prepare_items()` has
	 * been called so it's safe to render later.
	 *
	 * @since 2.0.0
	 * @var   ListTable|null
	 */
	private ?ListTable $list_table = null;

	/**
	 * Constructor — registers the menu and screen-option callbacks.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_filter( 'set-screen-option', [ $this, 'persist_screen_option' ], 10, 3 );
	}

	/**
	 * Add page under Users → Login Activity.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->hook = (string) add_users_page(
			__( 'Login Activity', 'wp-login-activity' ),
			__( 'Login Activity', 'wp-login-activity' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);

		// `load-{$hook}` fires before `render_page()`, which is when
		// screen options must be registered + the help tab attached
		// to be honoured by the rendering chain.
		add_action( "load-{$this->hook}", [ $this, 'on_load' ] );
	}

	/**
	 * load-{hook} callback — wires Screen Options + Help tab + builds
	 * the list table early so its prepare_items() sees the right
	 * screen-option state.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function on_load(): void {
		// Per-page rows option (slider in Screen Options).
		add_screen_option( 'per_page', [
			'label'   => __( 'Activities per page', 'wp-login-activity' ),
			'default' => 50,
			'option'  => ListTable::PER_PAGE_OPTION,
		] );

		// Some columns ship hidden by default (Username — forensics
		// admins toggle it on). WP merges this with each user's saved
		// preferences so the screen-options checkboxes still win.
		add_filter( 'default_hidden_columns', [ $this, 'default_hidden_columns' ], 10, 2 );

		$this->register_help_tab();

		$this->list_table = new ListTable();

		// Screen-Options-panel column-hide checkboxes drive off
		// `get_column_headers($screen)`, which reads the
		// `manage_{$screen_id}_columns` filter. WP_List_Table doesn't
		// auto-register on this filter — we have to hook it ourselves
		// so WP knows which checkboxes to render.
		add_filter( "manage_{$this->hook}_columns", [ $this->list_table, 'get_columns' ] );

		$this->list_table->prepare_items();
	}

	/**
	 * Apply our default-hidden-columns set on this screen only.
	 *
	 * @since 2.0.0
	 *
	 * @param string[]   $hidden Defaults so far.
	 * @param \WP_Screen $screen Current screen.
	 *
	 * @return string[]
	 */
	public function default_hidden_columns( $hidden, $screen ): array {
		$hidden = is_array( $hidden ) ? $hidden : [];

		if ( $screen && $screen->id === $this->hook ) {
			$hidden = array_unique( array_merge( $hidden, ListTable::get_default_hidden_columns() ) );
		}

		return $hidden;
	}

	/**
	 * Persist user-meta-backed screen options when WordPress saves them.
	 *
	 * The `set-screen-option` filter is the canonical way to allow
	 * custom per-user options — return the sanitised value to let
	 * core write it; return `false` to reject.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed  $status Default rejection (`false`).
	 * @param string $option The option key being saved.
	 * @param mixed  $value  Submitted value.
	 *
	 * @return mixed
	 */
	public function persist_screen_option( $status, string $option, $value ) {
		if ( $option === ListTable::PER_PAGE_OPTION ) {
			return max( 5, min( 200, (int) $value ) );
		}

		return $status;
	}

	/**
	 * Register the Help tab content for this screen.
	 *
	 * Two tabs: an Overview describing what each event type means,
	 * and a Notifications tab summarising the alert behaviour. Both
	 * are useful when admins are first investigating an alert email
	 * and want context for a row they don't recognise.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function register_help_tab(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$screen->add_help_tab( [
			'id'      => 'wpla-overview',
			'title'   => __( 'Overview', 'wp-login-activity' ),
			'content' =>
				'<p>' . esc_html__( 'This page lists every authentication event recorded by WP Login Activity, newest-first. Use the status links above the table to filter by event type, the search box to match users / IPs / countries, and the column headers to sort.', 'wp-login-activity' ) . '</p>'
				. '<h3>' . esc_html__( 'Event types', 'wp-login-activity' ) . '</h3>'
				. '<dl>'
				. '<dt><strong>' . esc_html__( 'Login', 'wp-login-activity' ) . '</strong></dt>'
				. '<dd>' . esc_html__( 'A successful authentication. The "NEW" badge appears the first time we see a given user-country pair.', 'wp-login-activity' ) . '</dd>'
				. '<dt><strong>' . esc_html__( 'Failed login', 'wp-login-activity' ) . '</strong></dt>'
				. '<dd>' . esc_html__( 'An attempted login with bad credentials. The identifier is whatever the visitor typed.', 'wp-login-activity' ) . '</dd>'
				. '<dt><strong>' . esc_html__( 'Logout', 'wp-login-activity' ) . '</strong></dt>'
				. '<dd>' . esc_html__( 'A user-initiated logout — useful for confirming session boundaries.', 'wp-login-activity' ) . '</dd>'
				. '<dt><strong>' . esc_html__( 'Registered', 'wp-login-activity' ) . '</strong></dt>'
				. '<dd>' . esc_html__( 'A new user account was created.', 'wp-login-activity' ) . '</dd>'
				. '<dt><strong>' . esc_html__( 'Password changed', 'wp-login-activity' ) . '</strong></dt>'
				. '<dd>' . esc_html__( 'Captures both profile-edit and lost-password-reset routes.', 'wp-login-activity' ) . '</dd>'
				. '<dt><strong>' . esc_html__( 'Email changed', 'wp-login-activity' ) . '</strong></dt>'
				. '<dd>' . esc_html__( 'The identifier records "old → new" so admins can see the address swap at a glance.', 'wp-login-activity' ) . '</dd>'
				. '<dt><strong>' . esc_html__( 'Admin role assigned', 'wp-login-activity' ) . '</strong></dt>'
				. '<dd>' . esc_html__( 'A user gained the Administrator role — covers both new-user-as-admin and existing-user-promoted routes.', 'wp-login-activity' ) . '</dd>'
				. '</dl>',
		] );

		$screen->add_help_tab( [
			'id'      => 'wpla-notifications',
			'title'   => __( 'Email alerts', 'wp-login-activity' ),
			'content' =>
				'<p>' . esc_html__( 'Five email alerts ship with the plugin, each independently toggleable under Settings → Login Activity:', 'wp-login-activity' ) . '</p>'
				. '<ul>'
				. '<li>' . esc_html__( 'New-country login (defaults: on, sent to the user that logged in).', 'wp-login-activity' ) . '</li>'
				. '<li>' . esc_html__( 'Administrator role assigned (defaults: on, sent to other admins — never the affected user).', 'wp-login-activity' ) . '</li>'
				. '<li>' . esc_html__( 'Admin password changed (defaults: on, sent to other admins).', 'wp-login-activity' ) . '</li>'
				. '<li>' . esc_html__( 'Admin email address changed (defaults: on, sent to other admins).', 'wp-login-activity' ) . '</li>'
				. '<li>' . esc_html__( 'Failed-login burst — 5+ failed attempts on a single identifier in 10 minutes (defaults: off; opt in once you know the noise floor).', 'wp-login-activity' ) . '</li>'
				. '</ul>',
		] );

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'Useful', 'wp-login-activity' ) . '</strong></p>'
			. '<p><a href="' . esc_url( admin_url( 'options-general.php?page=wp-login-activity-settings' ) ) . '">' . esc_html__( 'Plugin Settings', 'wp-login-activity' ) . '</a></p>'
		);
	}

	/**
	 * Render the page shell.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Defensive — `on_load()` already constructed it, but make
		// sure direct calls (tests, future re-use) still work.
		if ( $this->list_table === null ) {
			$this->list_table = new ListTable();
			$this->list_table->prepare_items();
		}

		// Inherit whatever filters are active so the export targets
		// the SAME rows the admin can see, not the entire table.
		$export_filters = array_intersect_key(
			$_GET,
			array_flip( [ 'event_type', 'user_id', 's', 'orderby', 'order', 'from', 'to', 'is_new_country' ] )
		);
		$export_url = Exporter::url( $export_filters );

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Login Activity', 'wp-login-activity' ); ?></h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">
				<?php esc_html_e( 'Export CSV', 'wp-login-activity' ); ?>
			</a>
			<hr class="wp-header-end" />

			<?php $this->render_quick_stats(); ?>

			<?php $this->list_table->views(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />

				<?php
				// Persist any active filters across pagination/sort
				// without losing them. `from` / `to` are exposed as
				// real inputs in extra_tablenav so they don't need
				// hidden duplicates here.
				foreach ( [ 'event_type', 'user_id', 'is_new_country' ] as $key ) {
					if ( ! empty( $_GET[ $key ] ) ) {
						printf(
							'<input type="hidden" name="%s" value="%s" />',
							esc_attr( $key ),
							esc_attr( (string) wp_unslash( $_GET[ $key ] ) )
						);
					}
				}
				?>

				<?php $this->list_table->search_box( __( 'Search activity', 'wp-login-activity' ), 'wpla-search' ); ?>
				<?php $this->list_table->display(); ?>
			</form>
		</div>

		<?php $this->print_inline_assets(); ?>
		<?php
	}

	/**
	 * Render the quick-stats summary above the status link bar.
	 *
	 * Three operational counters covering whatever date window is
	 * currently filtered (or the last 24 hours when no filter is
	 * set). Each counter is a clickable drill-in URL that combines
	 * the current filter context with the counter's own scope —
	 * clicking "Failed logins" while a date range is set scopes
	 * BOTH conditions in the resulting view.
	 *
	 * The four queries are indexed COUNTs against the activity
	 * table (event_type, is_new_country are both indexed columns)
	 * so the cost is negligible even on busy sites.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function render_quick_stats(): void {
		$base_args = $this->stats_window_args();
		$base_url  = admin_url( 'users.php?page=wp-login-activity' );

		// Preserve any active date / user / search filter on the
		// drill-in links so clicking a stat doesn't widen the view.
		$preserve_url_args = array_intersect_key(
			$_GET,
			array_flip( [ 'from', 'to', 'user_id', 's' ] )
		);

		// If no explicit filter is set, the stats default to last
		// 24h — show that fact in the labels for transparency.
		$is_default_window = empty( $_GET['from'] ) && empty( $_GET['to'] );
		$window_label      = $is_default_window
			? __( 'last 24 hours', 'wp-login-activity' )
			: __( 'in the selected range', 'wp-login-activity' );

		$query = \ArrayPress\WP\LoginActivity\Plugin::query();

		$total = (int) $query->query( array_merge( $base_args, [ 'count' => true, 'number' => 0 ] ) );
		$failed = (int) $query->query( array_merge( $base_args, [ 'count' => true, 'number' => 0, 'event_type' => 'login_failed' ] ) );
		$new_country = (int) $query->query( array_merge( $base_args, [ 'count' => true, 'number' => 0, 'is_new_country' => 1, 'event_type' => 'login' ] ) );

		// Default-window URL: when the stats default to last 24h,
		// the drill-in URLs should explicitly carry from=24h-ago so
		// the resulting filtered view reflects the same window.
		$window_url_args = $preserve_url_args;
		if ( $is_default_window ) {
			$window_url_args['from'] = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		}

		$total_url       = add_query_arg( $window_url_args, $base_url );
		$failed_url      = add_query_arg( array_merge( $window_url_args, [ 'event_type' => 'login_failed' ] ), $base_url );
		$new_country_url = add_query_arg( array_merge( $window_url_args, [ 'event_type' => 'login', 'is_new_country' => '1' ] ), $base_url );

		?>
		<div class="wpla-stats" style="display:flex;gap:16px;flex-wrap:wrap;margin:14px 0;padding:14px 18px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;">
			<div>
				<div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;"><?php esc_html_e( 'Events', 'wp-login-activity' ); ?></div>
				<div style="font-size:22px;font-weight:600;line-height:1.2;">
					<a href="<?php echo esc_url( $total_url ); ?>"><?php echo esc_html( number_format_i18n( $total ) ); ?></a>
				</div>
				<div style="font-size:11px;color:#646970;"><?php echo esc_html( $window_label ); ?></div>
			</div>

			<div style="border-left:1px solid #e0e0e0;padding-left:16px;">
				<div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#9b1c1c;"><?php esc_html_e( 'Failed logins', 'wp-login-activity' ); ?></div>
				<div style="font-size:22px;font-weight:600;line-height:1.2;color:<?php echo $failed > 0 ? '#9b1c1c' : '#1d2327'; ?>;">
					<a href="<?php echo esc_url( $failed_url ); ?>" style="color:inherit;"><?php echo esc_html( number_format_i18n( $failed ) ); ?></a>
				</div>
				<div style="font-size:11px;color:#646970;"><?php echo esc_html( $window_label ); ?></div>
			</div>

			<div style="border-left:1px solid #e0e0e0;padding-left:16px;">
				<div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#92400e;"><?php esc_html_e( 'New-country logins', 'wp-login-activity' ); ?></div>
				<div style="font-size:22px;font-weight:600;line-height:1.2;color:<?php echo $new_country > 0 ? '#92400e' : '#1d2327'; ?>;">
					<a href="<?php echo esc_url( $new_country_url ); ?>" style="color:inherit;"><?php echo esc_html( number_format_i18n( $new_country ) ); ?></a>
				</div>
				<div style="font-size:11px;color:#646970;"><?php echo esc_html( $window_label ); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * BerlinDB query args representing the date window the quick-
	 * stats counters cover. Mirrors ListTable's date-filter parsing
	 * so the stats reflect the SAME rows the table is showing.
	 *
	 * Falls back to "last 24 hours" when no explicit from/to is set,
	 * giving admins useful at-a-glance numbers on first page-load.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	private function stats_window_args(): array {
		$from_raw = isset( $_GET['from'] ) ? sanitize_text_field( (string) $_GET['from'] ) : '';
		$to_raw   = isset( $_GET['to'] )   ? sanitize_text_field( (string) $_GET['to'] )   : '';

		$from_ts = $from_raw !== '' ? strtotime( $from_raw . ' 00:00:00' ) : 0;
		$to_ts   = $to_raw   !== '' ? strtotime( $to_raw   . ' 23:59:59' ) : 0;

		// Default window: last 24 hours. Gives "is anything happening
		// right now?" without needing the admin to set a filter.
		if ( $from_ts <= 0 && $to_ts <= 0 ) {
			$from_ts = time() - DAY_IN_SECONDS;
		}

		$clause = [ 'inclusive' => true ];
		if ( $from_ts > 0 ) {
			$clause['after'] = gmdate( 'Y-m-d H:i:s', $from_ts );
		}
		if ( $to_ts > 0 ) {
			$clause['before'] = gmdate( 'Y-m-d H:i:s', $to_ts );
		}

		$args = [ 'date_created_query' => [ $clause ] ];

		// Inherit the active user / search filters so the stats
		// reflect the SAME scope the table shows.
		if ( ! empty( $_GET['user_id'] ) ) {
			$args['user_id'] = absint( (string) $_GET['user_id'] );
		}
		if ( ! empty( $_GET['s'] ) ) {
			$args['search'] = sanitize_text_field( wp_unslash( (string) $_GET['s'] ) );
		}

		return $args;
	}

	/**
	 * Tiny inline JS + CSS for the row-actions detail-row toggle and
	 * the current-session highlight tint.
	 *
	 * Inlined rather than enqueued because the surface is ~30 lines
	 * — a separate asset file would cost a network round-trip on
	 * every admin pageview for trivial styling.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function print_inline_assets(): void {
		?>
		<style>
			/* Override WP's automatic position-based striping
			   (.striped > tbody > :nth-child(odd)) — the injected
			   .wpla-detail-row siblings throw the parity off, so we
			   apply zebra class manually in PHP and override WP's
			   selector here. */
			.wp-list-table.striped > tbody > :nth-child(odd) {
				background-color: transparent;
			}
			.wp-list-table.striped > tbody > tr.wpla-alt {
				background-color: #f6f7f7;
			}

			.wpla-current-session > td { background: #f0f6ff !important; }
			.wpla-current-session > td:first-child { box-shadow: inset 3px 0 0 0 #2271b1; }
			.wpla-detail-row > td { border-top: 0 !important; }
			.wpla-toggle-details { cursor: pointer; }

			/* IP cell external-lookup dropdown. Position relative
			   on the wrapper, absolute on the menu so it floats
			   over adjacent rows without expanding the cell height. */
			.wpla-ip-tools {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				position: relative;
			}
			.wpla-ip-tools-trigger {
				background: transparent;
				border: 1px solid #c3c4c7;
				border-radius: 3px;
				color: #50575e;
				cursor: pointer;
				font-size: 13px;
				line-height: 1;
				padding: 1px 6px;
				min-height: 20px;
			}
			.wpla-ip-tools-trigger:hover,
			.wpla-ip-tools-trigger[aria-expanded="true"] {
				background: #f0f0f1;
				border-color: #8c8f94;
			}
			.wpla-ip-tools-menu {
				position: absolute;
				top: calc(100% + 4px);
				left: 0;
				min-width: 180px;
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 3px;
				box-shadow: 0 2px 6px rgba(0,0,0,0.08);
				padding: 4px 0;
				z-index: 100;
			}
			.wpla-ip-tools-menu a {
				display: block;
				padding: 6px 12px;
				color: #2271b1;
				text-decoration: none;
				font-size: 13px;
				white-space: nowrap;
			}
			.wpla-ip-tools-menu a:hover {
				background: #f0f6ff;
				color: #135e96;
			}
		</style>
		<script>
			( function () {
				// Detail-row toggle for the View-details row action.
				document.addEventListener( 'click', function ( e ) {
					var trigger = e.target.closest( '.wpla-toggle-details' );
					if ( ! trigger ) {
						return;
					}
					e.preventDefault();
					var rowId = trigger.getAttribute( 'data-row-id' );
					if ( ! rowId ) {
						return;
					}
					var detail = document.getElementById( 'wpla-detail-' + rowId );
					if ( ! detail ) {
						return;
					}
					var isHidden = detail.hasAttribute( 'hidden' );
					if ( isHidden ) {
						detail.removeAttribute( 'hidden' );
						trigger.setAttribute( 'aria-expanded', 'true' );
					} else {
						detail.setAttribute( 'hidden', '' );
						trigger.setAttribute( 'aria-expanded', 'false' );
					}
				} );

				// IP-cell external-lookup dropdown toggle. One open
				// at a time — clicking another trigger closes any
				// menu that's already open. Click outside closes.
				function closeAllIpMenus() {
					document.querySelectorAll( '.wpla-ip-tools-menu' ).forEach( function ( menu ) {
						menu.setAttribute( 'hidden', '' );
					} );
					document.querySelectorAll( '.wpla-ip-tools-trigger' ).forEach( function ( btn ) {
						btn.setAttribute( 'aria-expanded', 'false' );
					} );
				}

				document.addEventListener( 'click', function ( e ) {
					var trigger = e.target.closest( '.wpla-ip-tools-trigger' );

					if ( trigger ) {
						e.preventDefault();
						var menu = trigger.parentElement.querySelector( '.wpla-ip-tools-menu' );
						if ( ! menu ) {
							return;
						}
						var willOpen = menu.hasAttribute( 'hidden' );
						closeAllIpMenus();
						if ( willOpen ) {
							menu.removeAttribute( 'hidden' );
							trigger.setAttribute( 'aria-expanded', 'true' );
						}
						return;
					}

					// Click anywhere else (and not inside an open menu)
					// closes any open menu.
					if ( ! e.target.closest( '.wpla-ip-tools-menu' ) ) {
						closeAllIpMenus();
					}
				} );

				// ESC closes any open dropdown.
				document.addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'Escape' ) {
						closeAllIpMenus();
					}
				} );
			} )();
		</script>
		<?php
	}

}
