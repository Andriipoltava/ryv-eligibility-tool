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
	 * Nonce action/field names for the import form.
	 */
	const IMPORT_NONCE_ACTION = 'ryv_import_csv_action';
	const IMPORT_NONCE_FIELD  = 'ryv_import_nonce';

	/**
	 * Max upload size accepted for a CSV import.
	 */
	const IMPORT_MAX_BYTES = 5 * MB_IN_BYTES;

	/**
	 * Transient key prefix used to pass an import error across the
	 * Post/Redirect/Get round trip (same pattern as RYV_Import_Page).
	 */
	const IMPORT_ERROR_TRANSIENT_PREFIX = 'ryv_import_csv_error_';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_export_csv' ) );
		add_action( 'admin_init', array( $this, 'maybe_import_csv' ) );
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

		$import_action_url = admin_url( 'edit.php?post_type=' . RYV_CPT::POST_TYPE . '&page=' . self::PAGE_SLUG );

		$forms = get_posts(
			array(
				'post_type'      => RYV_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$import_error   = $this->consume_import_error();
		$imported_count = isset( $_GET['imported'] ) ? absint( $_GET['imported'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a PRG redirect result, no state change.

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

	/**
	 * Fires on every admin_init - only actually imports when the import
	 * form's submit button was pressed, so it stays a no-op on every other
	 * admin page load. Runs before any page HTML would otherwise be sent,
	 * which is required for wp_safe_redirect() below (Post/Redirect/Get).
	 */
	public function maybe_import_csv() {
		if ( ! isset( $_POST['ryv_import_csv'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ryv-eligibility-tool' ) );
		}

		check_admin_referer( self::IMPORT_NONCE_ACTION, self::IMPORT_NONCE_FIELD );

		$redirect_url = admin_url( 'edit.php?post_type=' . RYV_CPT::POST_TYPE . '&page=' . self::PAGE_SLUG );

		try {
			$form_id   = $this->validate_target_form();
			$file_path = $this->validate_and_get_uploaded_csv();
			$imported  = $this->import_csv_rows( $file_path, $form_id );

			$redirect_url = add_query_arg( 'imported', $imported, $redirect_url );
		} catch ( \Throwable $e ) {
			$this->store_import_error( $e->getMessage() );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Resolve and validate the target `ryv_forms` post from $_POST.
	 *
	 * @return int Post ID.
	 * @throws RuntimeException On invalid/missing selection.
	 */
	private function validate_target_form(): int {
		$form_id = isset( $_POST['ryv_import_form_id'] ) ? absint( $_POST['ryv_import_form_id'] ) : 0;
		$post    = get_post( $form_id );

		if ( ! $post || RYV_CPT::POST_TYPE !== $post->post_type ) {
			throw new RuntimeException( __( 'Please select a valid form to import these leads into.', 'ryv-eligibility-tool' ) );
		}

		return $form_id;
	}

	/**
	 * Validate the uploaded CSV and return its temporary path.
	 *
	 * @return string
	 * @throws RuntimeException On invalid/missing upload.
	 */
	private function validate_and_get_uploaded_csv(): string {
		if ( empty( $_FILES['ryv_csv_file'] ) || UPLOAD_ERR_NO_FILE === $_FILES['ryv_csv_file']['error'] ) {
			throw new RuntimeException( __( 'Please choose a .csv file to upload.', 'ryv-eligibility-tool' ) );
		}

		$file = $_FILES['ryv_csv_file'];

		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			throw new RuntimeException( __( 'File upload failed. Please try again.', 'ryv-eligibility-tool' ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			throw new RuntimeException( __( 'Invalid upload.', 'ryv-eligibility-tool' ) );
		}

		if ( $file['size'] > self::IMPORT_MAX_BYTES ) {
			throw new RuntimeException( __( 'File is too large (5MB max).', 'ryv-eligibility-tool' ) );
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			throw new RuntimeException( __( 'File must have a .csv extension.', 'ryv-eligibility-tool' ) );
		}

		// Real-world CSV exports (Excel, Google Sheets, Numbers) report a
		// number of different MIME types for the same file format, so this
		// only rejects the extension being spoofed onto something that isn't
		// text at all - it isn't relied on as the sole check.
		$filetype = wp_check_filetype( $file['name'], array( 'csv' => 'text/csv' ) );
		if ( 'csv' !== $filetype['ext'] ) {
			throw new RuntimeException( __( 'File must have a .csv extension.', 'ryv-eligibility-tool' ) );
		}

		return $file['tmp_name'];
	}

	/**
	 * Parse the CSV at $file_path and insert one RYV_Leads row per data row.
	 *
	 * @param string $file_path Path to the uploaded (temp) CSV file.
	 * @param int    $form_id   Target `ryv_forms` post ID.
	 * @return int Number of rows imported.
	 * @throws RuntimeException If the file is empty or has no header row.
	 */
	private function import_csv_rows( string $file_path, int $form_id ): int {
		$handle = fopen( $file_path, 'r' );
		if ( false === $handle ) {
			throw new RuntimeException( __( 'Could not read the uploaded file.', 'ryv-eligibility-tool' ) );
		}

		$header = fgetcsv( $handle );
		if ( false === $header || empty( $header ) ) {
			fclose( $handle );
			throw new RuntimeException( __( 'File is empty or invalid.', 'ryv-eligibility-tool' ) );
		}

		$header = array_map(
			static function ( $name, $index ) {
				$name = sanitize_text_field( wp_unslash( (string) $name ) );
				return '' !== $name ? $name : 'column_' . ( $index + 1 );
			},
			$header,
			array_keys( $header )
		);

		$imported = 0;

		while ( false !== ( $row = fgetcsv( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition -- standard fgetcsv loop idiom.
			// fgetcsv() returns a single [null] element for a blank line - skip
			// it rather than importing an all-empty row.
			if ( 1 === count( $row ) && null === $row[0] ) {
				continue;
			}

			$form_data = $this->csv_row_to_form_data( $header, $row );

			RYV_Leads::record( $form_id, 'csv_import', null, $form_data );
			++$imported;
		}

		fclose( $handle );

		return $imported;
	}

	/**
	 * Zip a CSV data row together with the header row into a sanitized
	 * `{ column_name: value }` map, padding/truncating so a row with a
	 * different column count than the header never throws.
	 *
	 * @param string[] $header Column names, in order.
	 * @param array    $row    Raw row values from fgetcsv().
	 * @return array<string, string>
	 */
	private function csv_row_to_form_data( array $header, array $row ): array {
		$count = count( $header );
		$row   = array_pad( array_slice( $row, 0, $count ), $count, '' );

		$form_data = array();
		foreach ( $header as $index => $column_name ) {
			$value                     = (string) ( $row[ $index ] ?? '' );
			$form_data[ $column_name ] = sanitize_text_field( wp_unslash( $value ) );
		}

		return $form_data;
	}

	/**
	 * Stash an import error in a short-lived, per-user transient so it
	 * survives the Post/Redirect/Get round trip (same pattern as
	 * RYV_Import_Page::store_result()).
	 *
	 * @param string $message Error message to show as an admin notice.
	 */
	private function store_import_error( string $message ) {
		set_transient( self::IMPORT_ERROR_TRANSIENT_PREFIX . get_current_user_id(), $message, MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * Pop (read + delete) this user's pending import error, if any.
	 *
	 * @return string|null
	 */
	private function consume_import_error(): ?string {
		$key     = self::IMPORT_ERROR_TRANSIENT_PREFIX . get_current_user_id();
		$message = get_transient( $key );

		if ( false === $message ) {
			return null;
		}

		delete_transient( $key );
		return $message;
	}
}