<?php
/**
 * User Profile Section
 *
 * Renders a "Recent login activity" section on the user-edit screen
 * (and `profile.php`). Uses the same `WP_List_Table` implementation
 * as the standalone Users → Login Activity page, but in compact mode
 * — no bulk actions, no status-link bar, no pagination chrome —
 * matching the visual treatment WordPress core uses for its own
 * Application Passwords table on the same screen.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Admin;

defined( 'ABSPATH' ) || exit;

use WP_User;

/**
 * Class UserProfile
 *
 * @since 2.0.0
 */
class UserProfile {

	/**
	 * Number of recent events to render. Kept low because this is a
	 * profile-page widget, not a forensics tool — the "View full
	 * activity" link below the table takes admins to the full page.
	 *
	 * @since 2.0.0
	 */
	private const RECENT_LIMIT = 10;

	/**
	 * Constructor — hooks both `edit_user_profile` (admin editing
	 * another user) and `show_user_profile` (user viewing their own).
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_action( 'edit_user_profile', [ $this, 'render' ] );
		add_action( 'show_user_profile', [ $this, 'render' ] );
	}

	/**
	 * Render the section.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_User $user The user being edited.
	 *
	 * @return void
	 */
	public function render( WP_User $user ): void {
		// Non-admin viewers only see their own activity. Admins see
		// everyone's. Capability mirrors the user-edit screen itself.
		if ( ! current_user_can( 'manage_options' ) && (int) $user->ID !== get_current_user_id() ) {
			return;
		}

		$table = new ListTable( [
			'compact'  => true,
			'user_id'  => (int) $user->ID,
			'per_page' => self::RECENT_LIMIT,
		] );
		$table->prepare_items();

		?>
		<h2><?php esc_html_e( 'Recent login activity', 'wp-login-activity' ); ?></h2>

		<?php $table->display(); ?>

		<p>
			<a href="<?php echo esc_url( admin_url( 'users.php?page=wp-login-activity&user_id=' . (int) $user->ID ) ); ?>">
				<?php esc_html_e( 'View full activity →', 'wp-login-activity' ); ?>
			</a>
		</p>
		<?php
	}

}
