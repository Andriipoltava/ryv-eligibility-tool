<?php
/**
 * Two shortcodes for the State Post template's "Featured Partner" section:
 *
 * - `[ryv_featured_partners]` renders the current state's Featured Partners
 *   list - see RYV_Featured_Partners_Renderer for the actual field
 *   resolution/markup, shared with RYV_Featured_Partners_Widget (the
 *   Elementor-native version of the same thing, with Style-tab controls).
 *   This shortcode exists alongside that widget, not instead of it, for any
 *   placement that isn't a full widget slot (a Shortcode widget, a
 *   WYSIWYG/Text Editor block, post content, ...).
 *
 * - `[ryv_state_partners_heading]` renders "Our Partners on the Ground in
 *   {State}" with the state's own post title interpolated - replacing a
 *   static heading that was hardcoded to "...in Tennessee" in the Elementor
 *   template and so showed on every other state's page too.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_States_Partners_Shortcode
 */
class RYV_States_Partners_Shortcode {

	const PARTNERS_TAG = 'ryv_featured_partners';
	const HEADING_TAG   = 'ryv_state_partners_heading';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_shortcodes' ) );
	}

	/**
	 * Register both shortcodes.
	 */
	public function register_shortcodes() {
		add_shortcode( self::PARTNERS_TAG, array( $this, 'render_partners' ) );
		add_shortcode( self::HEADING_TAG, array( $this, 'render_heading' ) );
	}

	/**
	 * [ryv_featured_partners]
	 *
	 * @return string
	 */
	public function render_partners(): string {
		if ( ! class_exists( 'RYV_Featured_Partners_Renderer' ) ) {
			return '';
		}

		// The list needs its stylesheet loaded even when this shortcode is
		// the only thing on the page that does - e.g. sitting inside an
		// Elementor Shortcode widget, where the shortcode text lives in
		// `_elementor_data`, not `post_content`, so RYV_Assets never sees it
		// via a has_shortcode() scan of the post content the way RYV_Shortcode
		// checks for `[ryv_form]`. Enqueuing this late (during render, after
		// wp_head has already run) still works: WordPress prints any
		// style queued before wp_footer in the footer, same as a script.
		if ( class_exists( 'RYV_Assets' ) ) {
			wp_enqueue_style( RYV_Assets::STYLE_HANDLE );
		}

		return RYV_Featured_Partners_Renderer::render_html( RYV_Featured_Partners_Renderer::get_state_id() );
	}

	/**
	 * [ryv_state_partners_heading]
	 *
	 * @return string
	 */
	public function render_heading(): string {
		$post_id = class_exists( 'RYV_Featured_Partners_Renderer' )
			? RYV_Featured_Partners_Renderer::get_state_id()
			: (int) get_the_ID();

		if ( ! $post_id ) {
			return '';
		}

		return sprintf(
			/* translators: %s: state name */
			esc_html__( 'Our Partners on the Ground in %s', 'ryv-eligibility-tool' ),
			esc_html( get_the_title( $post_id ) )
		);
	}
}
