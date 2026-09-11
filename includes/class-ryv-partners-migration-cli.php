<?php
/**
 * `wp ryv migrate-partners` - WP-CLI wrapper around RYV_Partners_Migration.
 * Deletable together with that class and its admin fallback page - see
 * class-ryv-partners-migration.php's own docblock.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Partners_Migration_CLI
 */
class RYV_Partners_Migration_CLI {

	/**
	 * Copy each state's legacy single-partner fields into the new
	 * `featured_partners` repeater.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything. This is the
	 * default behavior - pass --dry-run=0 (or run the command a second time
	 * without it) to actually write.
	 * ---
	 * default: true
	 * ---
	 *
	 * [--post_id=<id>]
	 * : Migrate a single ryv_states post instead of every one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ryv migrate-partners
	 *     wp ryv migrate-partners --dry-run=0
	 *     wp ryv migrate-partners --post_id=270 --dry-run=0
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args: dry-run, post_id.
	 */
	public function __invoke( $args, $assoc_args ) {
		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', true );
		$post_id = isset( $assoc_args['post_id'] ) ? absint( $assoc_args['post_id'] ) : null;

		if ( ! class_exists( 'RYV_Partners_Migration' ) ) {
			\WP_CLI::error( 'RYV_Partners_Migration is not loaded (is the plugin active?).' );
			return;
		}

		$result = ( new RYV_Partners_Migration() )->run( $dry_run, $post_id );

		if ( $dry_run ) {
			\WP_CLI::log( 'DRY RUN - nothing was written. Pass --dry-run=0 to apply.' );
		}

		$table_rows = array();
		foreach ( $result['rows'] as $row ) {
			$table_rows[] = array(
				'ID'     => $row['id'],
				'Title'  => $row['title'],
				'Status' => RYV_Partners_Migration::status_label( $row['status'] ),
				'Detail' => $row['detail'],
			);
		}

		\WP_CLI\Utils\format_items( 'table', $table_rows, array( 'ID', 'Title', 'Status', 'Detail' ) );

		\WP_CLI::log( '' );
		foreach ( $result['totals'] as $status => $count ) {
			if ( $count > 0 ) {
				\WP_CLI::log( sprintf( '%s: %d', RYV_Partners_Migration::status_label( $status ), $count ) );
			}
		}

		\WP_CLI::success( $dry_run ? 'Dry run complete.' : 'Migration complete.' );
	}
}
