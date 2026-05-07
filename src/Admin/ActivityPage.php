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
		add_filter( 'screen_settings', [ $this, 'render_extra_screen_options' ], 10, 2 );
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

		$this->maybe_save_extra_screen_options();
		$this->register_help_tab();

		$this->list_table = new ListTable();
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
	 * Save the custom Screen Options checkbox values when WP's screen-
	 * options form is submitted.
	 *
	 * The built-in `set-screen-option` filter only handles ONE option
	 * per screen — fine for `per_page`, not enough for the relative-
	 * time toggle on top of it. For custom inputs, the standard
	 * approach is to detect the screen-options POST in `load-{$hook}`
	 * (which runs before WP's redirect-after-save) and write user
	 * meta directly.
	 *
	 * Relies on the existing `screenoptionnonce` field WP renders
	 * inside the Screen Options form for CSRF protection.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function maybe_save_extra_screen_options(): void {
		if ( empty( $_POST['screenoptionnonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['screenoptionnonce'] ), 'screen-options-nonce' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$relative = ! empty( $_POST['wpla_relative_time'] ) ? '1' : '0';
		update_user_meta( $user_id, ListTable::RELATIVE_TIME_OPTION, $relative );
	}

	/**
	 * Inject custom checkboxes into the Screen Options panel.
	 *
	 * `screen_settings` is the filter that lets you append HTML below
	 * WP's built-in screen-options inputs. We use it to add a single
	 * "Show times as relative" checkbox; the surrounding form +
	 * Apply button + nonce are all rendered by core.
	 *
	 * @since 2.0.0
	 *
	 * @param string     $settings Existing HTML.
	 * @param \WP_Screen $screen   Current screen object.
	 *
	 * @return string
	 */
	public function render_extra_screen_options( string $settings, $screen ): string {
		if ( ! $screen || $screen->id !== $this->hook ) {
			return $settings;
		}

		$relative = get_user_meta( get_current_user_id(), ListTable::RELATIVE_TIME_OPTION, true ) === '1';

		ob_start();
		?>
		<fieldset class="wpla-extra-screen-options" style="margin-top:8px;">
			<legend><?php esc_html_e( 'Date format', 'wp-login-activity' ); ?></legend>
			<label for="wpla_relative_time">
				<input type="checkbox"
				       id="wpla_relative_time"
				       name="wpla_relative_time"
				       value="1"
					<?php checked( $relative ); ?> />
				<?php esc_html_e( 'Show times as relative ("2 hours ago") instead of absolute UTC.', 'wp-login-activity' ); ?>
			</label>
		</fieldset>
		<?php

		return $settings . (string) ob_get_clean();
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

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Login Activity', 'wp-login-activity' ); ?></h1>
			<hr class="wp-header-end" />

			<?php $this->list_table->views(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />

				<?php
				// Persist any active filters across pagination/sort
				// without losing them.
				foreach ( [ 'event_type', 'user_id' ] as $key ) {
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
			.wpla-current-session > td { background: #f0f6ff !important; }
			.wpla-current-session > td:first-child { box-shadow: inset 3px 0 0 0 #2271b1; }
			.wpla-detail-row > td { border-top: 0 !important; }
			.wpla-toggle-details { cursor: pointer; }
		</style>
		<script>
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
		</script>
		<?php
	}

}
