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
	 * Export action controller instance (admin-only).
	 *
	 * @var RYV_Export_Page|null
	 */
	private $export_page = null;

	/**
	 * Form builder page controller instance (admin-only).
	 *
	 * @var RYV_Builder_Page|null
	 */
	private $builder_page = null;

	/**
	 * Activity log page controller instance (admin-only).
	 *
	 * @var RYV_Logs_Page|null
	 */
	private $logs_page = null;

	/**
	 * Submissions page controller instance (admin-only).
	 *
	 * @var RYV_Submissions_Page|null
	 */
	private $submissions_page = null;

	/**
	 * Help page controller instance (admin-only).
	 *
	 * @var RYV_Help_Page|null
	 */
	private $help_page = null;

	/**
	 * States CPT controller instance.
	 *
	 * @var RYV_States_CPT|null
	 */
	private $states_cpt = null;

	/**
	 * Events CPT controller instance.
	 *
	 * @var RYV_Events_CPT|null
	 */
	private $events_cpt = null;

	/**
	 * Resources CPT controller instance.
	 *
	 * @var RYV_Resources_CPT|null
	 */
	private $resources_cpt = null;

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
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-schema-migrator.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-yaml-parser.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-drupal-yaml-exporter.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-graph-validator.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-us-states.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-db.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-submission-log.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-leads.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-edit-log.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-schema-diff.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-api.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-admin-api.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-assets.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-elementor.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-shortcode.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-states-cpt.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-events-cpt.php';
		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-resources-cpt.php';

		// ACF + sync only when ACF is available.
		if ( class_exists( 'ACF' ) ) {
			require_once RYV_PLUGIN_DIR . 'includes/class-ryv-states-acf.php';
			require_once RYV_PLUGIN_DIR . 'includes/class-ryv-states-sync.php';
		}

		// Not is_admin()-gated like the other admin/*-page.php controllers
		// below - RYV_Settings_Page also feeds the frontend CSS variable
		// cascade (see its own docblock), so it needs to load on every
		// request, not just wp-admin ones.
		require_once RYV_PLUGIN_DIR . 'admin/class-ryv-settings-page.php';

		if ( is_admin() ) {
			require_once RYV_PLUGIN_DIR . 'admin/class-ryv-import-page.php';
			require_once RYV_PLUGIN_DIR . 'admin/class-ryv-export-page.php';
			require_once RYV_PLUGIN_DIR . 'admin/class-ryv-builder-page.php';
			require_once RYV_PLUGIN_DIR . 'admin/class-ryv-logs-page.php';
			require_once RYV_PLUGIN_DIR . 'admin/class-ryv-submissions-page.php';
			require_once RYV_PLUGIN_DIR . 'admin/class-ryv-shortcode-hint.php';
			require_once RYV_PLUGIN_DIR . 'admin/class-ryv-help-page.php';
		}
	}

	/**
	 * Instantiate controllers and register plugin-wide hooks.
	 */
	private function init_hooks() {
		// Cheap no-op once up to date (single get_option() check) - covers a
		// plugin update that lands without a deactivate/reactivate cycle,
		// which is the only other place install() would otherwise run.
		RYV_DB::maybe_upgrade();

		$this->cpt           = new RYV_CPT();
		$this->states_cpt    = new RYV_States_CPT();
		$this->events_cpt    = new RYV_Events_CPT();
		$this->resources_cpt = new RYV_Resources_CPT();
		new RYV_API();
		new RYV_Admin_API();
		new RYV_Assets();
		new RYV_Elementor();
		new RYV_Shortcode();
		new RYV_Settings_Page();

		if ( class_exists( 'ACF' ) ) {
			new RYV_States_ACF();
			new RYV_States_Sync();
		}

		if ( is_admin() ) {
			$this->import_page      = new RYV_Import_Page();
			$this->export_page      = new RYV_Export_Page();
			$this->builder_page     = new RYV_Builder_Page();
			$this->logs_page        = new RYV_Logs_Page();
			$this->submissions_page = new RYV_Submissions_Page();
			new RYV_Shortcode_Hint();
			$this->help_page = new RYV_Help_Page();
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

		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-states-cpt.php';
		$states_cpt = new RYV_States_CPT();
		$states_cpt->register_post_type();

		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-events-cpt.php';
		$events_cpt = new RYV_Events_CPT();
		$events_cpt->register_post_type();

		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-resources-cpt.php';
		$resources_cpt = new RYV_Resources_CPT();
		$resources_cpt->register_post_type();

		require_once RYV_PLUGIN_DIR . 'includes/class-ryv-db.php';
		RYV_DB::install();

		flush_rewrite_rules();
	}

	/**
	 * Deactivation callback.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
