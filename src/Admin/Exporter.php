<?php
/**
 * CSV Exporter
 *
 * Streams the activity table as a CSV file. Honours the same filter
 * args the admin list-table accepts (event_type / user_id / search /
 * orderby / order), so admins can scope what they export by setting
 * the filters first and clicking Export — exactly mirrors the
 * standard WP-list-table-then-bulk-export pattern.
 *
 * Streaming via `fputcsv()` against `php://output` rather than
 * building the whole CSV in memory; the activity table can grow
 * large on busy sites and a 100k-row export shouldn't OOM.
 *
 * Endpoint: `admin-post.php?action=wpla_export` with the filter
 * args + a wpla_export nonce.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\WP\LoginActivity\Admin;

defined( 'ABSPATH' ) || exit;

use ArrayPress\WP\LoginActivity\Plugin;
use ArrayPress\WP\LoginActivity\Database\Rows\Activity as ActivityRow;

/**
 * Class Exporter
 *
 * @since 2.0.0
 */
class Exporter {

	/**
	 * The admin-post action slug.
	 *
	 * @since 2.0.0
	 */
	public const ACTION = 'wpla_export';

	/**
	 * Rows fetched per chunk while streaming. Keeps memory
	 * bounded without making the streaming N+1-query slow.
	 *
	 * @since 2.0.0
	 */
	private const CHUNK_SIZE = 1000;

	/**
	 * Constructor — wires the admin-post handler.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_export' ] );
	}

	/**
	 * Build the export URL with the given filter args + a fresh
	 * nonce. Used by ActivityPage to render the Export button so
	 * the URL automatically inherits whatever filters are active.
	 *
	 * @since 2.0.0
	 *
	 * @param array $filters URL filter args (event_type, user_id, s, etc).
	 *
	 * @return string
	 */
	public static function url( array $filters = [] ): string {
		$args = array_merge(
			[ 'action' => self::ACTION ],
			$filters,
			[ '_wpnonce' => wp_create_nonce( self::ACTION ) ]
		);

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * Handle the export request.
	 *
	 * Verifies cap + nonce, sets headers, streams CSV, exits.
	 * Errors short-circuit to wp_die() so the user gets feedback
	 * instead of a half-broken file download.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export login activity.', 'wp-login-activity' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$args = $this->build_query_args();
		$this->stream_csv( $args );

		exit;
	}

	/**
	 * Build BerlinDB query args from the request URL filter params.
	 *
	 * Validates the same way the list table does — orderby is
	 * checked against an allowlist, search/event are sanitised, etc.
	 * Returns an args array suitable for streaming via repeated
	 * paginated `query()` calls.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	private function build_query_args(): array {
		$args = [
			'orderby' => 'date_created',
			'order'   => 'DESC',
		];

		$allowed_orderby = [ 'date_created', 'identifier', 'user_id', 'event_type', 'ip_address', 'country_code', 'user_role' ];
		$requested       = isset( $_REQUEST['orderby'] ) ? (string) $_REQUEST['orderby'] : '';

		if ( in_array( $requested, $allowed_orderby, true ) ) {
			$args['orderby'] = $requested;
		}

		$args['order'] = ( strtoupper( (string) ( $_REQUEST['order'] ?? 'DESC' ) ) === 'ASC' ) ? 'ASC' : 'DESC';

		$event_type = isset( $_REQUEST['event_type'] ) ? sanitize_key( (string) $_REQUEST['event_type'] ) : '';
		if ( $event_type !== '' ) {
			$args['event_type'] = $event_type;
		}

		$user_id = isset( $_REQUEST['user_id'] ) ? absint( (string) $_REQUEST['user_id'] ) : 0;
		if ( $user_id > 0 ) {
			$args['user_id'] = $user_id;
		}

		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
		if ( $search !== '' ) {
			$args['search'] = $search;
		}

		// Date range — same parsing as ListTable so an export
		// inherits whatever the admin filtered on the page.
		$from_raw = isset( $_REQUEST['from'] ) ? sanitize_text_field( (string) $_REQUEST['from'] ) : '';
		$to_raw   = isset( $_REQUEST['to'] )   ? sanitize_text_field( (string) $_REQUEST['to'] )   : '';
		$from_ts  = $from_raw !== '' ? strtotime( $from_raw . ' 00:00:00' ) : 0;
		$to_ts    = $to_raw   !== '' ? strtotime( $to_raw   . ' 23:59:59' ) : 0;

		if ( $from_ts > 0 || $to_ts > 0 ) {
			$clause = [ 'inclusive' => true ];
			if ( $from_ts > 0 ) {
				$clause['after'] = gmdate( 'Y-m-d H:i:s', $from_ts );
			}
			if ( $to_ts > 0 ) {
				$clause['before'] = gmdate( 'Y-m-d H:i:s', $to_ts );
			}
			$args['date_created_query'] = [ $clause ];
		}

		if ( ! empty( $_REQUEST['is_new_country'] ) ) {
			$args['is_new_country'] = 1;
		}

		return $args;
	}

	/**
	 * Stream the matching rows as CSV.
	 *
	 * Sends Content-Disposition + Content-Type headers, a UTF-8 BOM
	 * (so Excel autodetects encoding correctly), the header row,
	 * and then rows in CHUNK_SIZE batches.
	 *
	 * Output filename includes the date so consecutive exports
	 * don't overwrite each other in the admin's downloads folder.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Base BerlinDB query args.
	 *
	 * @return void
	 */
	private function stream_csv( array $args ): void {
		nocache_headers();

		// Filename includes UTC datetime + a short random token. The
		// random suffix prevents collisions when two admins (or one
		// admin in two tabs) export within the same second — bare
		// gmdate('His') has 1-second resolution, easy to collide.
		$filename = sprintf(
			'login-activity-%s-%s.csv',
			gmdate( 'Y-m-d-His' ),
			substr( wp_hash( uniqid( '', true ) ), 0, 8 )
		);

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		if ( $out === false ) {
			return;
		}

		// UTF-8 BOM so Excel opens the file with the right encoding.
		fwrite( $out, "\xEF\xBB\xBF" );

		// Header row — every column we capture, in a stable order.
		// Ordered for human readability rather than DB-column order.
		fputcsv( $out, [
			'id',
			'date_utc',
			'event',
			'user_id',
			'username',
			'display_name',
			'identifier_typed',
			'role',
			'ip',
			'country',
			'country_source',
			'browser',
			'os',
			'is_bot',
			'is_new_country',
			'actor_user_id',
			'referer',
			'user_agent_raw',
			'uuid',
		] );

		$query  = Plugin::query();
		$offset = 0;

		while ( true ) {
			$page_args = array_merge( $args, [
				'number' => self::CHUNK_SIZE,
				'offset' => $offset,
			] );

			$rows = (array) $query->query( $page_args );

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( ! $row instanceof ActivityRow ) {
					continue;
				}
				fputcsv( $out, $this->row_to_csv( $row ) );
			}

			// Last page — exit the loop without an extra empty fetch.
			if ( count( $rows ) < self::CHUNK_SIZE ) {
				break;
			}

			$offset += self::CHUNK_SIZE;
		}

		fclose( $out );
	}

	/**
	 * Convert one row to its CSV column array — order MUST match
	 * the header row written by `stream_csv`.
	 *
	 * @since 2.0.0
	 *
	 * @param ActivityRow $row Row.
	 *
	 * @return array<int, string>
	 */
	private function row_to_csv( ActivityRow $row ): array {
		$user     = $row->user_id > 0 ? get_userdata( $row->user_id ) : false;
		$username = $user ? (string) $user->user_login   : '';
		$display  = $user ? (string) $user->display_name : '';

		return [
			(string) $row->id,
			(string) $row->date_created,
			(string) $row->event_type,
			(string) $row->user_id,
			$username,
			$display,
			(string) $row->identifier,
			(string) $row->user_role,
			(string) $row->ip_address,
			(string) $row->country_code,
			(string) $row->country_source,
			(string) $row->get_browser(),
			(string) $row->get_os(),
			$row->is_bot() ? '1' : '0',
			(string) $row->is_new_country,
			(string) $row->actor_user_id,
			(string) $row->referer,
			(string) $row->user_agent,
			(string) $row->uuid,
		];
	}

}
