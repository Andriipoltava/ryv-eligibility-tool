<?php
/**
 * Who-edited-what-when audit trail for the form builder: one row per
 * successful schema save (RYV_Admin_API::save_form()), no field-level diff.
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
	 * Record one save.
	 *
	 * @param int $form_id Form post ID.
	 * @param int $user_id Editing user's ID.
	 */
	public static function record( int $form_id, int $user_id ) {
		global $wpdb;

		$wpdb->insert(
			RYV_DB::edit_log_table(),
			array(
				'form_id'   => $form_id,
				'user_id'   => $user_id,
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
