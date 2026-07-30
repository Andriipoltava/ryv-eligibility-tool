<?php
/**
 * Who-edited-what-when audit trail for the form builder: one row per
 * successful schema save (RYV_Admin_API::save_form()), optionally carrying a
 * structural diff of what changed (see RYV_Schema_Diff).
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Edit_Log
 */
class RYV_Edit_Log {

	/**
	 * Reject/clear oversized diffs rather than truncating JSON mid-string,
	 * which would store invalid JSON. A real diff of a large form (many
	 * states/nodes touched in one save) can legitimately be sizeable, so
	 * this is deliberately more generous than RYV_Submission_Log's cap.
	 */
	const MAX_DIFF_BYTES = 50000;

	/**
	 * Record one save.
	 *
	 * @param int   $form_id Form post ID.
	 * @param int   $user_id Editing user's ID.
	 * @param array $diff    Structural diff from RYV_Schema_Diff::compute(), or empty if nothing changed.
	 */
	public static function record( int $form_id, int $user_id, array $diff = array() ) {
		global $wpdb;

		$diff_json = null;
		if ( ! empty( $diff ) ) {
			$encoded = wp_json_encode( $diff, JSON_UNESCAPED_SLASHES );
			if ( false !== $encoded && strlen( $encoded ) <= self::MAX_DIFF_BYTES ) {
				$diff_json = $encoded;
			}
		}

		$wpdb->insert(
			RYV_DB::edit_log_table(),
			array(
				'form_id'   => $form_id,
				'user_id'   => $user_id,
				'diff'      => $diff_json,
				'edited_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Most recent edits across every form, newest first.
	 *
	 * @param int $limit  Row cap (page size).
	 * @param int $offset Rows to skip (for pagination).
	 * @return array[]
	 */
	public static function recent( int $limit = 200, int $offset = 0 ): array {
		global $wpdb;
		$table = RYV_DB::edit_log_table();

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY edited_at DESC LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		);
	}

	/**
	 * Total edit-log row count, for pagination.
	 *
	 * @return int
	 */
	public static function count_all(): int {
		global $wpdb;
		$table = RYV_DB::edit_log_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
