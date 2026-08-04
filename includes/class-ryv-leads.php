<?php
/**
 * Storage for `lead_capture` node submissions - one row per successful
 * submit, only ever written when that node's `actions.save_to_db` is on
 * (see RYV_API::submit_lead()).
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Leads
 */
class RYV_Leads {

	/**
	 * Insert one submitted lead.
	 *
	 * @param int         $form_id    Form post ID.
	 * @param string      $node_id    The `lead_capture` node that was submitted.
	 * @param string|null $session_id Client-generated session id, if provided.
	 * @param string      $name       Submitted name (may be empty - only email is required).
	 * @param string      $email      Submitted email.
	 */
	public static function record( int $form_id, string $node_id, ?string $session_id, string $name, string $email ) {
		global $wpdb;

		$wpdb->insert(
			RYV_DB::leads_table(),
			array(
				'form_id'    => $form_id,
				'node_id'    => $node_id,
				'session_id' => $session_id,
				'name'       => $name,
				'email'      => $email,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Most recent leads across every form, newest first.
	 *
	 * @param int $limit  Row cap (page size).
	 * @param int $offset Rows to skip (for pagination).
	 * @return array[]
	 */
	public static function recent( int $limit = 200, int $offset = 0 ): array {
		global $wpdb;
		$table = RYV_DB::leads_table();

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		);
	}

	/**
	 * Total lead row count, for pagination.
	 *
	 * @return int
	 */
	public static function count_all(): int {
		global $wpdb;
		$table = RYV_DB::leads_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}