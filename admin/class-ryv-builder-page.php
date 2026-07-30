<?php
/**
 * "Form Builder" admin page: a React SPA that replaces the default post
 * editor for `ryv_forms`. Editing a form (list table, admin bar, direct
 * post.php URL) redirects here instead of the normal Gutenberg/classic
 * editor; "Add New" redirects here too, where the builder handles creating
 * the post itself via the core REST API before it has anything to edit.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Builder_Page
 */
class RYV_Builder_Page {

	/**
	 * Capability required to use the builder.
	 */
	const CAPABILITY = 'edit_posts';

	/**
	 * Hook slug for this submenu page.
	 */
	const PAGE_SLUG = 'ryv-form-builder';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_filter( 'get_edit_post_link', array( $this, 'filter_edit_post_link' ), 10, 2 );
		add_action( 'load-post.php', array( $this, 'redirect_post_edit' ) );
		add_action( 'load-post-new.php', array( $this, 'redirect_post_new' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Register the builder page under the ryv_forms CPT menu, then hide its
	 * top-level entry - `add_submenu_page()` is what makes
	 * `edit.php?post_type=ryv_forms&page=ryv-form-builder` a routable page
	 * (needed by redirect_post_edit()/redirect_post_new() and by direct
	 * `&post_id=` links), but a visible menu item for it duplicates
	 * WordPress's own "Add New Form" - both land on the same empty
	 * "create new form" screen when opened with no post_id.
	 */
	public function register_menu() {
		$hook = add_submenu_page(
			'edit.php?post_type=' . RYV_CPT::POST_TYPE,
			__( 'Form Builder', 'ryv-eligibility-tool' ),
			__( 'Form Builder', 'ryv-eligibility-tool' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		remove_submenu_page( 'edit.php?post_type=' . RYV_CPT::POST_TYPE, self::PAGE_SLUG );

		// remove_submenu_page() also removes the entry get_admin_page_title()
		// reads the page's <title> from, which under PHP 8.2 surfaces as a
		// strip_tags(null) deprecation notice in admin-header.php (and the
		// "headers already sent" warnings that follow from it printing before
		// the page's own headers go out). Set the title back explicitly, only
		// for this page's own load - not admin_menu, which runs on every
		// wp-admin request.
		if ( $hook ) {
			add_action(
				"load-{$hook}",
				static function () {
					$GLOBALS['title'] = __( 'Form Builder', 'ryv-eligibility-tool' );
				}
			);
		}
	}

	/**
	 * Point every "Edit" link for a `ryv_forms` post (list table row action,
	 * admin bar, get_edit_post_link() callers generally) at the builder
	 * instead of the standard post editor.
	 *
	 * @param string $url     Original edit link.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function filter_edit_post_link( $url, $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || RYV_CPT::POST_TYPE !== $post->post_type ) {
			return $url;
		}

		return $this->builder_url( $post_id );
	}

	/**
	 * Safety net for anyone landing on post.php?action=edit directly (typed
	 * URL, bookmark, etc.) rather than through a filtered link.
	 */
	public function redirect_post_edit() {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

		if ( ! $post_id || RYV_CPT::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		wp_safe_redirect( $this->builder_url( $post_id ) );
		exit;
	}

	/**
	 * Same safety net for post-new.php?post_type=ryv_forms.
	 */
	public function redirect_post_new() {
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';

		if ( RYV_CPT::POST_TYPE !== $post_type ) {
			return;
		}

		wp_safe_redirect( $this->builder_url( null ) );
		exit;
	}

	/**
	 * @param int|null $post_id Post ID, or null for the "new form" state.
	 * @return string
	 */
	private function builder_url( ?int $post_id ): string {
		$url = admin_url( 'edit.php?post_type=' . RYV_CPT::POST_TYPE . '&page=' . self::PAGE_SLUG );

		if ( $post_id ) {
			$url = add_query_arg( 'post_id', $post_id, $url );
		}

		return $url;
	}

	/**
	 * Enqueue the built React bundle, only on our own admin page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( 'ryv_forms_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Loads TinyMCE + Quicktags (and their CSS) so the React app's markup
		// node editor can mount a classic Visual/Text editor via
		// wp.editor.initialize() - see components/ClassicEditor.js.
		wp_enqueue_editor();

		$asset_file = RYV_PLUGIN_DIR . 'admin/build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'ryv-form-builder',
			RYV_PLUGIN_URL . 'admin/build/index.js',
			array_merge( $asset['dependencies'], array( 'editor' ) ),
			$asset['version'],
			true
		);

		wp_set_script_translations( 'ryv-form-builder', 'ryv-eligibility-tool' );

		// @wordpress/scripts names extracted CSS "style-{entry}.css" (a
		// convention from separating frontend vs. editor-only styles), not
		// "{entry}.css" - not "index.css".
		if ( file_exists( RYV_PLUGIN_DIR . 'admin/build/style-index.css' ) ) {
			wp_enqueue_style(
				'ryv-form-builder',
				RYV_PLUGIN_URL . 'admin/build/style-index.css',
				array( 'wp-components' ),
				$asset['version']
			);
		}

		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;

		wp_localize_script(
			'ryv-form-builder',
			'RYVBuilderSettings',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'ryv/v1/admin' ) ),
				'wpRestUrl' => esc_url_raw( rest_url() ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'postId'    => $post_id ?: null,
				'listUrl'   => esc_url( admin_url( 'edit.php?post_type=' . RYV_CPT::POST_TYPE ) ),
			)
		);
	}

	/**
	 * Render the mount point. All actual UI is the React app.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'ryv-eligibility-tool' ) );
		}

		echo '<div id="ryv-form-builder-root" class="wrap"></div>';
	}
}
