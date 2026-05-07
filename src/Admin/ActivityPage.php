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

		$this->register_help_tab();

		$this->list_table = new ListTable();
		$this->list_table->prepare_items();
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
		<?php
	}

}
