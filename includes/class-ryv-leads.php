<?php
/**
 * Storage for `lead_capture` node submissions - one row per successful
 * submit, only ever written when that node's `email_settings.save_to_db` is
 * on (see RYV_API::submit_lead()).
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
	 * Reject/clear oversized payloads rather than truncating JSON mid-string,
	 * which would store invalid JSON. Same idea/limit as
	 * RYV_Submission_Log::MAX_ANSWERS_BYTES.
	 */
	const MAX_FORM_DATA_BYTES = 20000;

	/**
	 * Insert one submitted lead.
	 *
	 * @param int         $form_id    Form post ID.
	 * @param string      $node_id    The `lead_capture` node that was submitted.
	 * @param string|null $session_id Client-generated session id, if provided.
	 * @param array       $form_data  Sanitized `{ field_name: value }` map - the
	 *                                node's `form_fields` are admin-defined, so
	 *                                there's no fixed name/email shape left to
	 *                                give its own columns to.
	 */
	public static function record( int $form_id, string $node_id, ?string $session_id, array $form_data ) {
		global $wpdb;

		$form_data_json = wp_json_encode( $form_data, JSON_UNESCAPED_SLASHES );
		if ( false === $form_data_json || strlen( $form_data_json ) > self::MAX_FORM_DATA_BYTES ) {
			$form_data_json = wp_json_encode( array() );
		}

		$wpdb->insert(
			RYV_DB::leads_table(),
			array(
				'form_id'    => $form_id,
				'node_id'    => $node_id,
				'session_id' => $session_id,
				'form_data'  => $form_data_json,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
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