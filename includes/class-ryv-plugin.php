<?php
/**
 * Core plugin bootstrap: dependency checks, includes, and lifecycle hooks.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Plugin
 *
 * Singleton bootstrap. Verifies Elementor is loaded before wiring up any
 * plugin functionality, since the frontend widget (Phase 4) is an
 * Elementor_Widget_Base subclass.
 */
final class RYV_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var RYV_Plugin|null
	 */
	private static $instance = null;

	/**
	 * CPT controller instance.
	 *
	 * @var RYV_CPT|null
	 */
	private $cpt = null;

	/**
	 * Import page controller instance (admin-only).
	 *
	 * @var RYV_Import_Page|null
	 */
	private $import_page = null;

	/**
	 * Get (or create) the singleton instance.
	 *
	 * @return RYV_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - private to enforce singleton usage.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'bootstrap' ), 20 );
	}

	/**
	 * Verify dependencies and, if satisfied, load the plugin.
	 *
	 * Runs at priority 20 on plugins_loaded so Elementor (which fires
	 * 'elementor/loaded' from its own plugins_loaded callback) has already
	 * had a chance to initialize.
	 */
	public function bootstrap() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			add_action( 'admin_notices', array( $this, 'render_missing_elementor_notice' ) );
			return;
		}

		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Require class files.
	 */
	private function includes() {
		if ( file_exists( RYV_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
			require_once RYV_PLUGIN_DIR . 'vendor/autoload.php';
		}

		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-cpt.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-yaml-parser.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-us-states.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-api.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-assets.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-elementor.php';

		if ( is_admin() ) {
			require_once RYV_PLUGIN_DIR . 'admin/class-ryv-import-page.php';
		}
	}

	/**
	 * Instantiate controllers and register plugin-wide hooks.
	 */
	private function init_hooks() {
		$this->cpt = new RYV_CPT();
		new RYV_API();
		new RYV_Assets();
		new RYV_Elementor();

		if ( is_admin() ) {
			$this->import_page = new RYV_Import_Page();
		}
	}

	/**
	 * Admin notice shown when Elementor is missing or inactive.
	 */
	public function render_missing_elementor_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'RYV Eligibility Tool requires Elementor to be installed and active. The plugin has been loaded but its widget and admin features are disabled until Elementor is available.', 'ryv-eligibility-tool' )
		);
	}

	/**
	 * Activation callback. Registers the CPT early so rewrite rules flush
	 * correctly, even though full dependency-gated bootstrap has not run yet.
	 */
	public static function activate() {
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-cpt.php';
		$cpt = new RYV_CPT();
		$cpt->register_post_type();

		flush_rewrite_rules();
	}

	/**
	 * Deactivation callback.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
