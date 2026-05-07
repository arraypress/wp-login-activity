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

		// Default-hidden columns: covers fresh users (no saved Screen
		// Options meta yet). The filter only fires when there's no
		// saved value, so existing users with saved meta from before
		// new columns existed don't benefit — that's what
		// `hidden_columns_migration` handles below.
		add_filter( 'default_hidden_columns', [ $this, 'default_hidden_columns' ], 10, 2 );

		// One-time-per-user migration: when default-hidden columns
		// are ADDED to an already-released plugin, existing users'
		// saved hidden-columns lists don't know about them, so the
		// new columns appear unhidden. Bumping the version below
		// runs a single force-hide for each user when they next load
		// the page; after that their saved preferences are honoured
		// normally.
		add_filter( 'hidden_columns', [ $this, 'hidden_columns_migration' ], 10, 3 );

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
	 * One-time-per-user migration of the saved hidden-columns list.
	 *
	 * Problem this solves: WP saves the user's hidden-columns list
	 * to user meta on first Screen-Options Apply. When new columns
	 * are added later, the saved list doesn't know about them, so
	 * `default_hidden_columns` (which only fires for users without
	 * saved meta) never gets to mark them hidden — they show as
	 * unhidden until the user manually toggles them off.
	 *
	 * Fix: track a per-user migration version. Whenever we add new
	 * default-hidden columns, bump the constant. On the user's next
	 * page load, this filter notices their saved version is older,
	 * force-merges the new defaults into their hidden list, and
	 * stamps the version. From then on their saved preferences are
	 * honoured normally — explicit toggles after migration win.
	 *
	 * @since 2.0.0
	 *
	 * @param string[]   $hidden       Hidden-columns list (may be saved or default).
	 * @param \WP_Screen $screen       Current screen.
	 * @param bool       $use_defaults Whether $hidden came from defaults.
	 *
	 * @return string[]
	 */
	public function hidden_columns_migration( $hidden, $screen, $use_defaults ): array {
		if ( ! $screen || $screen->id !== $this->hook ) {
			return (array) $hidden;
		}

		// Defaults path already handled by `default_hidden_columns` —
		// no migration needed.
		if ( $use_defaults ) {
			return (array) $hidden;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return (array) $hidden;
		}

		$current_version = ListTable::HIDDEN_COLUMNS_VERSION;
		$user_version    = (int) get_user_meta( $user_id, 'wpla_hidden_columns_version', true );

		if ( $user_version >= $current_version ) {
			return (array) $hidden;
		}

		// Migration: merge defaults into the user's saved list and
		// stamp the version so we don't keep doing this every load.
		$hidden = array_values( array_unique( array_merge(
			(array) $hidden,
			ListTable::get_default_hidden_columns()
		) ) );

		update_user_meta( $user_id, 'wpla_hidden_columns_version', $current_version );

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

		<?php $this->list_table->render_flyout_payload(); ?>
		<?php $this->print_inline_assets(); ?>
		<?php
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
			/* Manual zebra striping. WP's `.striped` uses
			   :nth-child(odd) which we override here to hand
			   striping to the wpla-alt class — avoids parity issues
			   if anything ever injects siblings between data rows. */
			.wp-list-table.striped > tbody > :nth-child(odd) {
				background-color: transparent;
			}
			.wp-list-table.striped > tbody > tr.wpla-alt {
				background-color: #f6f7f7;
			}

			.wpla-current-session > td { background: #f0f6ff !important; }
			.wpla-current-session > td:first-child { box-shadow: inset 3px 0 0 0 #2271b1; }
			.wpla-toggle-details { cursor: pointer; }

			/* Flyout panel — slides in from the right of the viewport
			   when "View details" is clicked. Backdrop fades over
			   the content underneath, panel itself has a soft drop
			   shadow + comfortable padding. */
			.wpla-flyout-backdrop {
				position: fixed;
				inset: 0;
				background: rgba(0, 0, 0, 0.35);
				z-index: 99999;
				opacity: 0;
				transition: opacity 200ms ease;
				pointer-events: none;
			}
			.wpla-flyout-backdrop.is-open {
				opacity: 1;
				pointer-events: auto;
			}
			.wpla-flyout {
				position: fixed;
				top: 0;
				right: 0;
				bottom: 0;
				width: 100%;
				max-width: 480px;
				background: #fff;
				z-index: 100000;
				box-shadow: -4px 0 18px rgba(0, 0, 0, 0.18);
				transform: translateX(100%);
				transition: transform 240ms cubic-bezier(.22,.61,.36,1);
				display: flex;
				flex-direction: column;
				overflow: hidden;
			}
			.wpla-flyout.is-open {
				transform: translateX(0);
			}
			.wpla-flyout__close {
				position: absolute;
				top: 10px;
				right: 14px;
				background: transparent;
				border: 0;
				font-size: 24px;
				line-height: 1;
				color: #50575e;
				cursor: pointer;
				padding: 4px 10px;
				border-radius: 3px;
				z-index: 1;
			}
			.wpla-flyout__close:hover {
				background: #f0f0f1;
				color: #1d2327;
			}

			/* Header — avatar + event badge + title + subtitle.
			   Generous padding mirrors WP's settings-screen sectioning. */
			.wpla-flyout__header {
				padding: 24px 28px 20px;
				border-bottom: 1px solid #dcdcde;
				background: #fff;
				display: flex;
				gap: 16px;
				align-items: flex-start;
			}
			.wpla-flyout__avatar img {
				border-radius: 50%;
			}
			.wpla-flyout__header-meta {
				min-width: 0;
				flex: 1;
			}
			.wpla-flyout__badges {
				display: flex;
				gap: 6px;
				flex-wrap: wrap;
			}
			.wpla-flyout__event-badge {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: 600;
				text-transform: uppercase;
				letter-spacing: .3px;
			}
			.wpla-flyout__current-session-badge {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: 600;
				background: #e0ecfb;
				color: #1d4ed8;
				text-transform: uppercase;
				letter-spacing: .3px;
			}
			.wpla-flyout__title {
				margin: 8px 0 2px;
				padding: 0;
				font-size: 18px;
				font-weight: 600;
				line-height: 1.3;
				color: #1d2327;
			}
			.wpla-flyout__subtitle {
				color: #646970;
				font-size: 13px;
			}

			/* Body — sectioned with form-table-style label/value
			   pairs. Same visual vocabulary as WP's Settings → General. */
			.wpla-flyout__body {
				flex: 1;
				overflow-y: auto;
				padding: 0;
				background: #f6f7f7;
			}
			.wpla-flyout__section {
				background: #fff;
				border-bottom: 1px solid #dcdcde;
				padding: 20px 28px;
			}
			.wpla-flyout__section:last-child {
				border-bottom: 0;
			}
			.wpla-flyout__section h3 {
				margin: 0 0 12px;
				padding: 0;
				font-size: 13px;
				font-weight: 600;
				color: #1d2327;
				text-transform: uppercase;
				letter-spacing: .5px;
			}
			.wpla-flyout__table {
				width: 100%;
				border-collapse: collapse;
				font-size: 13px;
			}
			.wpla-flyout__table th,
			.wpla-flyout__table td {
				padding: 8px 0;
				text-align: left;
				vertical-align: top;
				border-bottom: 1px solid #f0f0f1;
				line-height: 1.5;
			}
			.wpla-flyout__table tr:last-child th,
			.wpla-flyout__table tr:last-child td {
				border-bottom: 0;
			}
			.wpla-flyout__table th {
				width: 38%;
				font-weight: 500;
				color: #646970;
				padding-right: 16px;
			}
			.wpla-flyout__table td {
				color: #1d2327;
				word-break: break-word;
				font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
				font-size: 12px;
			}
			.wpla-flyout__links {
				display: flex;
				flex-wrap: wrap;
				gap: 12px;
			}
			.wpla-flyout__links a {
				font-size: 13px;
			}

			/* Footer — sticky action bar. */
			.wpla-flyout__footer {
				padding: 14px 28px;
				border-top: 1px solid #dcdcde;
				display: flex;
				gap: 8px;
				align-items: center;
				background: #fff;
			}
			.wpla-flyout__footer-delete {
				margin-left: auto;
			}

		</style>
		<script>
			( function () {
				var flyout      = document.querySelector( '.wpla-flyout' );
				var backdrop    = document.querySelector( '.wpla-flyout-backdrop' );
				var flyoutBody  = flyout ? flyout.querySelector( '.wpla-flyout__content' ) : null;

				/**
				 * Open the flyout populated with the detail template
				 * for the given row id.
				 */
				function openFlyout( rowId ) {
					if ( ! flyout || ! backdrop || ! flyoutBody ) {
						return;
					}
					var template = document.getElementById( 'wpla-detail-' + rowId );
					if ( ! template || ! template.content ) {
						return;
					}
					flyoutBody.innerHTML = '';
					flyoutBody.appendChild( template.content.cloneNode( true ) );

					backdrop.removeAttribute( 'hidden' );
					flyout.removeAttribute( 'hidden' );
					// Force reflow so the transition triggers off the
					// `is-open` class addition rather than coinciding
					// with the hidden-attribute removal.
					void flyout.offsetWidth;
					backdrop.classList.add( 'is-open' );
					flyout.classList.add( 'is-open' );
					flyout.setAttribute( 'aria-hidden', 'false' );
					document.body.style.overflow = 'hidden';
				}

				function closeFlyout() {
					if ( ! flyout || ! backdrop ) {
						return;
					}
					backdrop.classList.remove( 'is-open' );
					flyout.classList.remove( 'is-open' );
					flyout.setAttribute( 'aria-hidden', 'true' );
					document.body.style.overflow = '';
					// Hide after the transition so screen readers
					// don't traverse a positionally-offscreen panel.
					setTimeout( function () {
						if ( ! flyout.classList.contains( 'is-open' ) ) {
							flyout.setAttribute( 'hidden', '' );
							backdrop.setAttribute( 'hidden', '' );
						}
					}, 260 );
				}

				// "View details" row action — open the flyout for
				// the matching row.
				document.addEventListener( 'click', function ( e ) {
					var trigger = e.target.closest( '.wpla-toggle-details' );
					if ( ! trigger ) {
						return;
					}
					e.preventDefault();
					var rowId = trigger.getAttribute( 'data-row-id' );
					if ( rowId ) {
						openFlyout( rowId );
					}
				} );

				// Close handlers — backdrop click, close button, ESC.
				if ( backdrop ) {
					backdrop.addEventListener( 'click', closeFlyout );
				}
				document.addEventListener( 'click', function ( e ) {
					if ( e.target.closest( '.wpla-flyout__close' ) ) {
						closeFlyout();
					}
				} );
				document.addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'Escape' ) {
						closeFlyout();
					}
				} );
			} )();
		</script>
		<?php
	}

}
