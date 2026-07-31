<?php
/**
 * Anonymous visitor answer-trail logging: one row per (form, session),
 * upserted as the visitor progresses so partial completions aren't lost.
 * Deliberately carries no IP or other identifying info - just the answers,
 * which state/node they reached, and timestamps.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Submission_Log
 */
class RYV_Submission_Log {

	/**
	 * Reject/clear oversized answer payloads rather than truncating JSON
	 * mid-string, which would store invalid JSON.
	 */
	const MAX_ANSWERS_BYTES = 20000;

	/**
	 * Insert or update this session's row for this form.
	 *
	 * @param int         $form_id      Form post ID.
	 * @param string      $session_id   Client-generated id, unique per visitor page-load.
	 * @param string|null $state_id     Currently selected state slug, if any.
	 * @param string|null $current_node Deepest node id reached so far.
	 * @param array       $answers      node id => answer (string or string[]).
	 */
	public static function record( int $form_id, string $session_id, ?string $state_id, ?string $current_node, array $answers ) {
		global $wpdb;

		$answers_json = wp_json_encode( $answers, JSON_UNESCAPED_SLASHES );
		if ( false === $answers_json || strlen( $answers_json ) > self::MAX_ANSWERS_BYTES ) {
			$answers_json = wp_json_encode( array() );
		}

		$table = RYV_DB::submissions_table();
		$now   = current_time( 'mysql', true );

		// A single atomic upsert on the (form_id, session_id) UNIQUE KEY
		// (see RYV_DB::install()) rather than a separate SELECT then
		// conditional INSERT/UPDATE - that older approach raced under two
		// near-simultaneous requests for the same session (e.g. a retried
		// log call), where both could miss each other's row and both
		// INSERT, leaving duplicate rows instead of one upserted row.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (form_id, session_id, state_id, current_node, answers, created_at, updated_at)
				VALUES (%d, %s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					state_id = VALUES(state_id),
					current_node = VALUES(current_node),
					answers = VALUES(answers),
					updated_at = VALUES(updated_at)",
				$form_id,
				$session_id,
				$state_id,
				$current_node,
				$answers_json,
				$now,
				$now
			)
		);
	}

	/**
	 * Most recent submissions across every form, newest first.
	 *
	 * @param int $limit  Row cap (page size).
	 * @param int $offset Rows to skip (for pagination).
	 * @return array[]
	 */
	public static function recent( int $limit = 200, int $offset = 0 ): array {
		global $wpdb;
		$table = RYV_DB::submissions_table();

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		);
	}

	/**
	 * Total submission row count, for pagination.
	 *
	 * @return int
	 */
	public static function count_all(): int {
		global $wpdb;
		$table = RYV_DB::submissions_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
