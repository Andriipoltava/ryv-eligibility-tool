<?php
/**
 * "Import YAML" admin submenu page: uploads a Drupal Webform YAML export,
 * runs it through RYV_YAML_Parser, and merges the result into a `ryv_forms`
 * post's `_ryv_form_schema` under a given state.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Import_Page
 */
class RYV_Import_Page {

	/**
	 * Capability required to import.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Nonce action/name.
	 */
	const NONCE_ACTION = 'ryv_import_yaml';

	/**
	 * Transient key prefix for post/redirect/get result passing.
	 */
	const RESULT_TRANSIENT_PREFIX = 'ryv_import_result_';

	/**
	 * Hook slug for this submenu page.
	 */
	const PAGE_SLUG = 'ryv-import-yaml';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_submission' ) );
	}

	/**
	 * Register the submenu page under the ryv_forms CPT menu.
	 */
	public function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . RYV_CPT::POST_TYPE,
			__( 'Import YAML', 'ryv-eligibility-tool' ),
			__( 'Import YAML', 'ryv-eligibility-tool' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle the upload form submission (runs on admin_init, before any
	 * page output, so we can wp_safe_redirect per the Post/Redirect/Get
	 * pattern and show results as a dismissible admin notice).
	 */
	public function handle_submission() {
		if ( ! isset( $_POST['ryv_import_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import forms.', 'ryv-eligibility-tool' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'ryv_import_nonce' );

		try {
			$post_id         = $this->resolve_target_post();
			$state_override  = $this->resolve_state_override();
			$file_path       = $this->validate_and_get_uploaded_file();

			$raw    = RYV_YAML_Parser::parse_file( $file_path );
			$parser = new RYV_YAML_Parser();
			$result = $parser->parse( $raw, $state_override );

			$this->merge_states_into_post( $post_id, $result['states'] );

			$this->store_result(
				array(
					'success'     => true,
					'post_id'     => $post_id,
					'states'      => array_map(
						static function ( $state ) {
							return array(
								'id'    => $state['state_id'],
								'label' => $state['label'],
								'nodes' => count( $state['nodes'] ),
							);
						},
						array_values( $result['states'] )
					),
					'warnings'    => $result['warnings'],
					'skipped'     => $result['skipped'],
					'unassigned'  => $result['unassigned'],
				)
			);
		} catch ( \Throwable $e ) {
			$this->store_result(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				)
			);
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . RYV_CPT::POST_TYPE . '&page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Resolve (or create) the target `ryv_forms` post from $_POST.
	 *
	 * @return int Post ID.
	 * @throws RuntimeException On invalid input.
	 */
	private function resolve_target_post(): int {
		$target = isset( $_POST['ryv_target_form'] ) ? sanitize_text_field( wp_unslash( $_POST['ryv_target_form'] ) ) : '';

		if ( '__new__' === $target ) {
			$title = isset( $_POST['ryv_new_form_title'] ) ? sanitize_text_field( wp_unslash( $_POST['ryv_new_form_title'] ) ) : '';

			if ( '' === trim( $title ) ) {
				throw new RuntimeException( __( 'A title is required to create a new form.', 'ryv-eligibility-tool' ) );
			}

			$post_id = wp_insert_post(
				array(
					'post_type'   => RYV_CPT::POST_TYPE,
					'post_title'  => $title,
					'post_status' => 'draft',
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				throw new RuntimeException( $post_id->get_error_message() );
			}

			return (int) $post_id;
		}

		$post_id = absint( $target );
		$post    = get_post( $post_id );

		if ( ! $post || RYV_CPT::POST_TYPE !== $post->post_type ) {
			throw new RuntimeException( __( 'Selected target form was not found.', 'ryv-eligibility-tool' ) );
		}

		return $post_id;
	}

	/**
	 * Resolve the optional state override from $_POST. Most RYV exports
	 * bundle every state into one file (a state-select element followed by
	 * per-state gated containers) which the parser auto-splits on its own;
	 * this override only matters when auto-detection finds nothing, e.g. a
	 * hand-trimmed file containing just one state's elements.
	 *
	 * @return string|null
	 */
	private function resolve_state_override(): ?string {
		$raw  = isset( $_POST['ryv_state_override'] ) ? wp_unslash( $_POST['ryv_state_override'] ) : '';
		$slug = sanitize_title( $raw );

		return '' === $slug ? null : $slug;
	}

	/**
	 * Validate the uploaded file and return its temporary path.
	 *
	 * @return string
	 * @throws RuntimeException On invalid/missing upload.
	 */
	private function validate_and_get_uploaded_file(): string {
		if ( empty( $_FILES['ryv_yaml_file'] ) || UPLOAD_ERR_NO_FILE === $_FILES['ryv_yaml_file']['error'] ) {
			throw new RuntimeException( __( 'Please choose a .yml file to upload.', 'ryv-eligibility-tool' ) );
		}

		$file = $_FILES['ryv_yaml_file'];

		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			throw new RuntimeException( __( 'File upload failed. Please try again.', 'ryv-eligibility-tool' ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			throw new RuntimeException( __( 'Invalid upload.', 'ryv-eligibility-tool' ) );
		}

		$max_bytes = 2 * MB_IN_BYTES;
		if ( $file['size'] > $max_bytes ) {
			throw new RuntimeException( __( 'File is too large (2MB max).', 'ryv-eligibility-tool' ) );
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'yml', 'yaml' ), true ) ) {
			throw new RuntimeException( __( 'File must have a .yml or .yaml extension.', 'ryv-eligibility-tool' ) );
		}

		return $file['tmp_name'];
	}

	/**
	 * Merge one or more freshly-parsed state graphs into a form post's
	 * schema meta, preserving any other states already stored there (so
	 * re-importing, e.g. an updated voting_rights.yml, only touches the
	 * states present in that file).
	 *
	 * @param int   $post_id Target `ryv_forms` post ID.
	 * @param array $states  state_id => { state_id, label, entry_node, nodes }, from RYV_YAML_Parser::parse().
	 */
	private function merge_states_into_post( int $post_id, array $states ) {
		$existing_json = get_post_meta( $post_id, RYV_CPT::SCHEMA_META_KEY, true );
		$schema        = json_decode( (string) $existing_json, true );

		if ( ! is_array( $schema ) || ! isset( $schema['states'] ) || ! is_array( $schema['states'] ) ) {
			$schema = array( 'states' => array() );
		}

		foreach ( $states as $state_id => $state ) {
			$schema['states'][ $state_id ] = $state;
		}

		// update_post_meta() runs the value through wp_unslash() before storing
		// it (WP's meta API assumes callers pass slashed data, mirroring the
		// old magic_quotes convention for $_POST). Without wp_slash() here,
		// every backslash-escape in the JSON (\" around quoted text, \uXXXX
		// for any non-ASCII character) gets silently stripped, corrupting the
		// stored value into invalid JSON.
		update_post_meta(
			$post_id,
			RYV_CPT::SCHEMA_META_KEY,
			wp_slash( wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) )
		);
	}

	/**
	 * Stash the import outcome in a short-lived, per-user transient so it
	 * survives the Post/Redirect/Get round trip.
	 *
	 * @param array $data Result payload.
	 */
	private function store_result( array $data ) {
		set_transient( self::RESULT_TRANSIENT_PREFIX . get_current_user_id(), $data, MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * Pop (read + delete) this user's pending import result, if any.
	 *
	 * @return array|null
	 */
	private function consume_result(): ?array {
		$key    = self::RESULT_TRANSIENT_PREFIX . get_current_user_id();
		$result = get_transient( $key );

		if ( false === $result ) {
			return null;
		}

		delete_transient( $key );
		return $result;
	}

	/**
	 * Render the submenu page: result notice (if any) + upload form.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'ryv-eligibility-tool' ) );
		}

		$result = $this->consume_result();
		$forms  = get_posts(
			array(
				'post_type'      => RYV_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		require RYV_PLUGIN_DIR . 'admin/views/import-page.php';
	}
}
