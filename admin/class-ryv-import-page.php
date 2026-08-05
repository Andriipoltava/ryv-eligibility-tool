<?php
/**
 * "Import YAML" admin submenu page: uploads a Drupal Webform YAML export,
 * runs it through RYV_YAML_Parser, and stores the resulting flat graph as
 * a `ryv_forms` post's `_ryv_form_schema`.
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
	 * Postmeta key holding the imported form's flagged-elements review list
	 * (RYV_YAML_Parser's `review`, see its docblock) - kept on the post
	 * itself, not just the transient, so it survives past the immediate
	 * post-import page load (e.g. revisiting the import results isn't
	 * possible once the transient expires, but the data a "review these
	 * nodes" workflow needs still should be).
	 */
	const REVIEW_META_KEY = '_ryv_import_warnings';

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
			$file_path = $this->validate_and_get_uploaded_file();

			$raw    = RYV_YAML_Parser::parse_file( $file_path );
			$parser = new RYV_YAML_Parser();
			$result = $parser->parse( $raw );

			$is_new  = false;
			$post_id = $this->resolve_target_post( $result['title'], $is_new );

			// A single YAML file now represents the *entire* flat graph (there's
			// no more "one file = one state's worth of nodes" partial import
			// once everything is flattened) - importing into a form that
			// already has content necessarily replaces its whole schema, not
			// just adds to it. Require an explicit acknowledgement of that
			// before doing anything destructive.
			$this->assert_replace_confirmed( $post_id );

			$schema = array(
				'entry_node' => $result['entry_node'],
				'nodes'      => $result['nodes'],
			);

			RYV_CPT::save_schema( $post_id, $schema );

			$this->apply_title_and_description( $post_id, $result, $is_new );

			// Persisted on the post itself (not just the transient below) so
			// it's still there if an admin comes back to this form later,
			// e.g. from a "review flagged nodes" link days after the import.
			$review = $result['review'];
			if ( empty( $review ) ) {
				delete_post_meta( $post_id, self::REVIEW_META_KEY );
			} else {
				update_post_meta( $post_id, self::REVIEW_META_KEY, $review );
			}

			$health = RYV_Graph_Validator::analyze( $schema );

			$this->store_result(
				array(
					'success'     => true,
					'post_id'     => $post_id,
					'node_count'  => count( $result['nodes'] ),
					'title'       => $result['title'],
					'description' => $result['description'],
					'warnings'    => $result['warnings'],
					'skipped'     => $result['skipped'],
					'review'      => $review,
					'health'      => $health,
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
	 * @param string|null $yaml_title The uploaded file's own root `title:`, if any.
	 * @param bool        $is_new     Set to true if a new post was created.
	 * @return int Post ID.
	 * @throws RuntimeException On invalid input.
	 */
	private function resolve_target_post( ?string $yaml_title, bool &$is_new ): int {
		$is_new = false;
		$target = isset( $_POST['ryv_target_form'] ) ? sanitize_text_field( wp_unslash( $_POST['ryv_target_form'] ) ) : '';

		if ( '__new__' === $target ) {
			$is_new = true;

			$typed_title = isset( $_POST['ryv_new_form_title'] ) ? sanitize_text_field( wp_unslash( $_POST['ryv_new_form_title'] ) ) : '';
			// The admin's own typed title wins if they bothered to type one;
			// otherwise fall back to the YAML file's own title so leaving the
			// field blank isn't a dead end when the file already names itself.
			$title = '' !== trim( $typed_title ) ? $typed_title : (string) $yaml_title;

			if ( '' === trim( $title ) ) {
				throw new RuntimeException( __( 'A title is required - type one, or use a file that has its own title.', 'ryv-eligibility-tool' ) );
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
	 * A single YAML import now replaces a target form's entire schema, so
	 * require an explicit checkbox whenever that target already has content
	 * - a brand-new post never needs this (nothing to lose).
	 *
	 * @param int $post_id Target post ID.
	 * @throws RuntimeException If the target has existing content and the confirmation checkbox wasn't checked.
	 */
	private function assert_replace_confirmed( int $post_id ) {
		$existing = RYV_CPT::get_schema( $post_id );

		if ( empty( $existing['nodes'] ) ) {
			return;
		}

		if ( empty( $_POST['ryv_confirm_replace'] ) ) {
			throw new RuntimeException(
				__( 'This form already has content. Check "I understand this replaces this form\'s entire existing content" to continue.', 'ryv-eligibility-tool' )
			);
		}
	}

	/**
	 * Apply the YAML's own title/description to the target post. For a
	 * freshly-created post, the title was already set correctly (with the
	 * right admin-typed-vs-YAML precedence) in resolve_target_post() - don't
	 * re-touch it here, only the description (a brand-new post never has one
	 * to conflict with). For an existing post being replaced, the YAML title
	 * wins unconditionally when present - low-stakes next to the schema
	 * replace itself.
	 *
	 * @param int    $post_id Target post ID.
	 * @param array  $result  Parser result ({title, description, ...}).
	 * @param bool   $is_new  Whether $post_id was just created by this request.
	 */
	private function apply_title_and_description( int $post_id, array $result, bool $is_new ) {
		$update = array( 'ID' => $post_id );

		if ( ! $is_new && ! empty( $result['title'] ) ) {
			$update['post_title'] = $result['title'];
		}

		if ( ! empty( $result['description'] ) ) {
			$update['post_excerpt'] = $result['description'];
		}

		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}
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

		// So the picker can warn "this form already has 455 nodes" before the
		// admin picks it as a target - see assert_replace_confirmed().
		$form_node_counts = array();
		foreach ( $forms as $form ) {
			$schema                          = RYV_CPT::get_schema( $form->ID );
			$form_node_counts[ $form->ID ] = count( $schema['nodes'] );
		}

		require RYV_PLUGIN_DIR . 'admin/views/import-page.php';
	}
}
