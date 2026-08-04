<?php
/**
 * "Submissions" admin submenu page: lists recorded `lead_capture` node
 * submissions (RYV_Leads - only ever written when a node's
 * `email_settings.save_to_db` is on) across every form, plus a CSV export.
 * manage_options only, same trust boundary as the Activity Log page - a
 * lead's `form_data` is whatever the admin configured the form to collect.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Submissions_Page
 */
class RYV_Submissions_Page {

	/**
	 * Capability required to view or export.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Hook slug for this submenu page.
	 */
	const PAGE_SLUG = 'ryv-submissions';

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 20;

	/**
	 * Query arg that triggers a CSV export from admin_init, before any
	 * page HTML would otherwise be sent.
	 */
	const EXPORT_ACTION = 'ryv_export_csv';

	/**
	 * Nonce action name for the export link/request.
	 */
	const EXPORT_NONCE_ACTION = 'ryv_leads_export_csv';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_export_csv' ) );
	}

	/**
	 * Register the submenu page under the ryv_forms CPT menu.
	 */
	public function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . RYV_CPT::POST_TYPE,
			__( 'Submissions', 'ryv-eligibility-tool' ),
			__( 'Submissions', 'ryv-eligibility-tool' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the submenu page.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'ryv-eligibility-tool' ) );
		}

		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, no state change.

		$total       = RYV_Leads::count_all();
		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		// Clamp rather than 404 - a stale bookmarked/shared link to a page
		// that no longer exists (rows deleted since) should just show the
		// last real page instead of erroring.
		$paged = min( $paged, $total_pages );

		$leads = RYV_Leads::recent( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		$export_url = wp_nonce_url(
			add_query_arg(
				self::EXPORT_ACTION,
				'1',
				admin_url( 'edit.php?post_type=' . RYV_CPT::POST_TYPE . '&page=' . self::PAGE_SLUG )
			),
			self::EXPORT_NONCE_ACTION
		);

		require RYV_PLUGIN_DIR . 'admin/views/submissions-page.php';
	}

	/**
	 * Fires on every admin_init - only actually exports when the trigger
	 * query arg is present, so it stays a no-op on every other admin page
	 * load. Runs (and exits) before any page HTML would otherwise be sent,
	 * which is required for the CSV headers below to still be settable.
	 */
	public function maybe_export_csv() {
		if ( ! isset( $_GET[ self::EXPORT_ACTION ] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ryv-eligibility-tool' ) );
		}

		check_admin_referer( self::EXPORT_NONCE_ACTION );

		$this->export_csv();
	}

	/**
	 * Stream every lead as CSV and exit. Every `form_data` key seen across
	 * the exported rows becomes its own column (in first-seen order) rather
	 * than one opaque JSON column - `form_fields` are admin-defined per
	 * node, so there's no fixed schema to hardcode column names from.
	 */
	private function export_csv() {
		global $wpdb;

		$table = RYV_DB::leads_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );

		$field_names = array();
		$decoded     = array();

		foreach ( $rows as $row ) {
			$data = json_decode( (string) $row['form_data'], true );
			$data = is_array( $data ) ? $data : array();
			$decoded[] = $data;

			foreach ( array_keys( $data ) as $key ) {
				if ( ! in_array( $key, $field_names, true ) ) {
					$field_names[] = $key;
				}
			}
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ryv-submissions-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$output = fopen( 'php://output', 'w' );

		fputcsv( $output, array_merge( array( 'ID', 'Form ID', 'Form Title', 'Node ID', 'Session ID', 'Created At (UTC)' ), $field_names ) );

		foreach ( $rows as $i => $row ) {
			$line = array(
				$row['id'],
				$row['form_id'],
				RYV_Logs_Page::form_title( (int) $row['form_id'] ),
				$row['node_id'],
				$row['session_id'],
				$row['created_at'],
			);

			foreach ( $field_names as $key ) {
				$value  = isset( $decoded[ $i ][ $key ] ) ? (string) $decoded[ $i ][ $key ] : '';
				$line[] = $this->sanitize_csv_cell( $value );
			}

			fputcsv( $output, $line );
		}

		fclose( $output );
		exit;
	}

	/**
	 * OWASP CSV-injection mitigation: `form_data` values originate from the
	 * public, unauthenticated `/ryv/v1/form/{id}/submit` endpoint, and
	 * RYV_API::sanitize_form_data() only strips tags/whitespace - it never
	 * strips a leading formula trigger. Left as-is, a submitted value like
	 * `=HYPERLINK("http://evil","click")` would execute as a live formula
	 * the moment an admin opens this export in Excel/Sheets. Prefixing with
	 * a straight quote forces spreadsheet software to render the cell as
	 * literal text instead of evaluating it.
	 *
	 * @param string $value Raw cell value.
	 * @return string
	 */
	private function sanitize_csv_cell( string $value ): string {
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}

		return $value;
	}
}