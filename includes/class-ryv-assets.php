<?php
/**
 * Registers (but does not enqueue) the frontend CSS/JS. Enqueuing is left to
 * whatever actually needs the widget on a given page (the Elementor widget,
 * Phase 4), so pages without the questionnaire never load this for nothing.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Assets
 */
class RYV_Assets {

	/**
	 * Registered handle for the compiled Tailwind stylesheet.
	 */
	const STYLE_HANDLE = 'ryv-app';

	/**
	 * Registered handle for the decision-engine entry module.
	 */
	const SCRIPT_HANDLE = 'ryv-decision-engine';

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Priority 5: the Elementor widget references these handles via
		// get_script_depends()/get_style_depends() and enqueues them on this
		// same hook - registration has to land first.
		add_action( 'wp_enqueue_scripts', array( $this, 'register' ), 5 );
	}

	/**
	 * Register (not enqueue) assets.
	 */
	public function register() {
		wp_register_style(
			self::STYLE_HANDLE,
			RYV_PLUGIN_URL . 'assets/css/app.css',
			array(),
			RYV_VERSION
		);

		// `type="module"` scripts are deferred by the browser automatically,
		// so plain $in_footer = true is enough - no need for WP 6.3's
		// strategy-array form and its higher minimum-version requirement.
		wp_register_script(
			self::SCRIPT_HANDLE,
			RYV_PLUGIN_URL . 'assets/js/decision-engine.js',
			array(),
			RYV_VERSION,
			true
		);

		wp_script_add_data( self::SCRIPT_HANDLE, 'type', 'module' );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'RYVSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'ryv/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
