<?php
/**
 * "Help" admin submenu page: a plain-language guide to using this plugin -
 * creating/editing forms, importing a Drupal YAML export, embedding a form,
 * and where the Global Styles / Activity Log / Submissions pages fit in.
 * Read-only, no state - purely documentation, so it's the one admin page
 * with no capability requirement stricter than `manage_options` and no
 * side effects of any kind.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Help_Page
 */
class RYV_Help_Page {

	/**
	 * Capability required to view - same as every other admin/*-page.php
	 * controller here, for consistency (the content below references the
	 * Form Builder and Global Styles pages, both manage_options-gated).
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Hook slug for this submenu page.
	 */
	const PAGE_SLUG = 'ryv-help';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Register the submenu page under the ryv_forms CPT menu - last item,
	 * after Submissions, so it reads as "everything else is a tool, this is
	 * the manual" in the menu order.
	 */
	public function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . RYV_CPT::POST_TYPE,
			__( 'Help', 'ryv-eligibility-tool' ),
			__( 'Help', 'ryv-eligibility-tool' ),
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

		require RYV_PLUGIN_DIR . 'admin/views/help-page.php';
	}
}
