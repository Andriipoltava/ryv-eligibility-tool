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

		// Priority 999: Elementor only enqueues the active kit's stylesheet
		// while rendering the page's own CSS, which happens after this hook's
		// default priority - the handle does not exist yet at priority 5, so the
		// dependency has to be attached once everything else has enqueued.
		add_action( 'wp_enqueue_scripts', array( $this, 'depend_on_elementor_kit' ), 999 );

		add_filter( 'script_loader_tag', array( $this, 'add_module_type_attribute' ), 10, 2 );
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

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'RYVSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'ryv/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Elementor prints each kit's Site Settings as its own stylesheet
	 * (`elementor-post-<kit id>`, i.e. post-6.css) whose rules are element-level
	 * and kit-scoped - `.elementor-kit-6 h2 { ... }`. Those beat a bare
	 * `.ryv-question-title` on specificity, and on top of that the kit sheet is
	 * often printed *after* this plugin's, so even equally-specific rules lost.
	 *
	 * Declaring the kit handle as a dependency makes WP order app.css after it,
	 * which settles the tie-break half of the problem; the specificity half is
	 * handled in assets/src/input.css (doubled class selectors) - both are
	 * needed, neither alone is enough.
	 *
	 * Nothing is touched unless our own style is on the page *and* the kit sheet
	 * is genuinely enqueued: a dependency on a handle that was never enqueued
	 * would make WP drop app.css entirely, and with Elementor's "Inline Font
	 * Icons"/inline-CSS modes (or Elementor simply inactive) there is no kit
	 * handle at all. In those cases the specificity fix carries the load on its
	 * own, which is why it is not optional.
	 *
	 * @return void
	 */
	public function depend_on_elementor_kit() {
		if ( ! did_action( 'elementor/loaded' ) || ! wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
			return;
		}

		$kit_id = (int) get_option( 'elementor_active_kit' );

		if ( ! $kit_id ) {
			return;
		}

		$kit_handle = 'elementor-post-' . $kit_id;

		if ( ! wp_style_is( $kit_handle, 'enqueued' ) ) {
			return;
		}

		$styles = wp_styles();

		if ( ! isset( $styles->registered[ self::STYLE_HANDLE ] ) ) {
			return;
		}

		$style = $styles->registered[ self::STYLE_HANDLE ];

		if ( ! in_array( $kit_handle, $style->deps, true ) ) {
			$style->deps[] = $kit_handle;
		}
	}

	/**
	 * wp_script_add_data( $handle, 'type', 'module' ) is not actually read by
	 * WP core's script loader - core has no support for a 'type' data key, so
	 * the decision-engine script was silently shipping as a classic script
	 * despite using `import`, causing a browser-level
	 * "Cannot use import statement outside a module" SyntaxError. Rewriting
	 * the tag directly via this filter is the standard way to ship an ES
	 * module through wp_enqueue_script() prior to wp_enqueue_script_module().
	 *
	 * @param string $tag    Script tag markup.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public function add_module_type_attribute( $tag, $handle ) {
		if ( self::SCRIPT_HANDLE !== $handle ) {
			return $tag;
		}

		return str_replace( '<script ', '<script type="module" ', $tag );
	}
}
