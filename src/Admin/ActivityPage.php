<?php
/**
 * Admin Activity Page
 *
 * Tools → Login Activity. Renders the full event list — paginated,
 * filterable by event type, with a per-row badge for new-country
 * logins. The list-table-style markup is rendered inline rather than
 * via WP_List_Table — the page has exactly one operation (browse +
 * filter), so the abstraction would be overhead.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Admin;

defined( 'ABSPATH' ) || exit;

use ArrayPress\WP\LoginActivity\Database\Rows\Activity as ActivityRow;
use ArrayPress\WP\LoginActivity\Plugin;

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
	 * Default rows per page.
	 *
	 * @since 2.0.0
	 */
	private const PER_PAGE = 50;

	/**
	 * Constructor — registers the menu item.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
	}

	/**
	 * Add page under Tools → Login Activity.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_management_page(
			__( 'Login Activity', 'wp-login-activity' ),
			__( 'Login Activity', 'wp-login-activity' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the page.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$args  = $this->parse_request();
		$query = Plugin::query();

		$rows  = $query->query( $args );
		$total = (int) $query->found_items;

		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Login Activity', 'wp-login-activity' ); ?></h1>
			<hr class="wp-header-end" />

			<?php $this->render_filter_bar( $args ); ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'When',    'wp-login-activity' ); ?></th>
						<th scope="col"><?php esc_html_e( 'User',    'wp-login-activity' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Event',   'wp-login-activity' ); ?></th>
						<th scope="col"><?php esc_html_e( 'IP',      'wp-login-activity' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Country', 'wp-login-activity' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Device',  'wp-login-activity' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
							<td colspan="6">
								<?php esc_html_e( 'No activity recorded yet.', 'wp-login-activity' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php $this->render_row( $row ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php $this->render_pagination( $args['paged'], $total_pages, $total ); ?>
		</div>
		<?php
	}

	/**
	 * Translate the URL query into a BerlinDB args array.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	private function parse_request(): array {
		$paged      = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$event_type = isset( $_GET['event_type'] ) ? sanitize_key( (string) $_GET['event_type'] ) : '';
		$user_id    = isset( $_GET['user_id'] ) ? absint( (string) $_GET['user_id'] ) : 0;

		$args = [
			'number'  => self::PER_PAGE,
			'offset'  => ( $paged - 1 ) * self::PER_PAGE,
			'orderby' => 'date_created',
			'order'   => 'DESC',
			'paged'   => $paged,
		];

		if ( $event_type !== '' ) {
			$args['event_type'] = $event_type;
		}

		if ( $user_id > 0 ) {
			$args['user_id'] = $user_id;
		}

		return $args;
	}

	/**
	 * Render the event-type filter dropdown.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Current query args.
	 *
	 * @return void
	 */
	private function render_filter_bar( array $args ): void {
		$current = (string) ( $args['event_type'] ?? '' );

		?>
		<form method="get" style="margin: 12px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />

			<select name="event_type">
				<option value=""              <?php selected( $current, '' ); ?>><?php esc_html_e( 'All events',     'wp-login-activity' ); ?></option>
				<option value="login"         <?php selected( $current, 'login' ); ?>><?php esc_html_e( 'Logins',         'wp-login-activity' ); ?></option>
				<option value="login_failed"  <?php selected( $current, 'login_failed' ); ?>><?php esc_html_e( 'Failed logins',  'wp-login-activity' ); ?></option>
				<option value="logout"        <?php selected( $current, 'logout' ); ?>><?php esc_html_e( 'Logouts',        'wp-login-activity' ); ?></option>
				<option value="registered"    <?php selected( $current, 'registered' ); ?>><?php esc_html_e( 'Registrations',  'wp-login-activity' ); ?></option>
			</select>

			<?php submit_button( __( 'Filter', 'wp-login-activity' ), '', '', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render a single table row.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row model.
	 *
	 * @return void
	 */
	private function render_row( ActivityRow $row ): void {
		?>
		<tr>
			<td><?php echo esc_html( $row->date_created . ' UTC' ); ?></td>
			<td>
				<?php if ( $row->user_id > 0 ) : ?>
					<a href="<?php echo esc_url( get_edit_user_link( $row->user_id ) ); ?>">
						<?php echo esc_html( $row->get_display_name() ); ?>
					</a>
				<?php else : ?>
					<?php echo esc_html( $row->get_display_name() ); ?>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $this->event_label( $row->event_type ) ); ?></td>
			<td><code><?php echo esc_html( $row->ip_address ); ?></code></td>
			<td>
				<?php echo esc_html( $row->country_code !== '' ? $row->country_code : '—' ); ?>
				<?php if ( $row->is_new_country() ) : ?>
					<span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:3px;font-size:11px;margin-left:4px;">
						<?php esc_html_e( 'NEW', 'wp-login-activity' ); ?>
					</span>
				<?php endif; ?>
			</td>
			<td>
				<?php $formatted = $row->get_formatted_user_agent(); ?>
				<?php echo esc_html( $formatted !== '' ? $formatted : '—' ); ?>
				<?php if ( $row->is_bot() ) : ?>
					<span style="background:#fde2e2;color:#9b1c1c;padding:1px 6px;border-radius:3px;font-size:11px;margin-left:4px;">
						<?php esc_html_e( 'BOT', 'wp-login-activity' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Translate an event_type slug to its display label.
	 *
	 * @since 2.0.0
	 *
	 * @param string $event_type Event slug.
	 *
	 * @return string
	 */
	private function event_label( string $event_type ): string {
		$labels = [
			'login'         => __( 'Login',          'wp-login-activity' ),
			'login_failed'  => __( 'Failed login',   'wp-login-activity' ),
			'logout'        => __( 'Logout',         'wp-login-activity' ),
			'registered'    => __( 'Registered',     'wp-login-activity' ),
		];

		return $labels[ $event_type ] ?? $event_type;
	}

	/**
	 * Render the pagination block.
	 *
	 * @since 2.0.0
	 *
	 * @param int $current     Current page (1-based).
	 * @param int $total_pages Total page count.
	 * @param int $total       Total row count.
	 *
	 * @return void
	 */
	private function render_pagination( int $current, int $total_pages, int $total ): void {
		if ( $total <= self::PER_PAGE ) {
			return;
		}

		$base = remove_query_arg( 'paged' );

		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: total event count */
						esc_html( _n( '%s item', '%s items', $total, 'wp-login-activity' ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>

				<span class="pagination-links">
					<?php
					echo paginate_links( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						'base'      => add_query_arg( 'paged', '%#%', $base ),
						'format'    => '',
						'current'   => $current,
						'total'     => $total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					] );
					?>
				</span>
			</div>
		</div>
		<?php
	}

}
