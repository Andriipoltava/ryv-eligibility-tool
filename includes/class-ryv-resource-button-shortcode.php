<?php
/**
 * `[ryv_resource_button_text id="123"]` shortcode: outputs just the resolved
 * button-label text for one `ryv_resources` post - the "Button Text" field's
 * preset-or-custom value (e.g. "Download" / "Get The Toolkit" / a typed-in
 * custom label) - as plain text, no link markup. Meant to be dropped inside
 * an admin's own button element (an Elementor Button widget's text control,
 * etc.) rather than to render a button itself.
 *
 * Resolved exactly the way RYV_Resources_CPT::get_cta_text() already
 * resolves it for every other consumer, so this is never a second source of
 * truth - editing "Button Text" on the resource itself is reflected here
 * immediately, with nothing separate to update.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Resource_Button_Shortcode
 */
class RYV_Resource_Button_Shortcode {

	/**
	 * Shortcode tag.
	 */
	const TAG = 'ryv_resource_button_text';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_shortcode' ) );
	}

	/**
	 * Register the [ryv_resource_button_text] shortcode.
	 */
	public function register_shortcode() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * `id` defaults to the current post when the shortcode is placed directly
	 * in a `ryv_resources` post's own content, so
	 * `[ryv_resource_button_text]` with no attributes "just works" there.
	 *
	 * @param array|string $atts Shortcode attributes: id.
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts( array( 'id' => '' ), $atts, self::TAG );

		$resource_id = absint( $atts['id'] );

		// Falls back to whatever post the current loop is actually on, not
		// just the main-query page (is_singular() alone missed the common
		// case: this shortcode rendered inside a foreach/setup_postdata()
		// loop over a state's related resources, where the main query is
		// the state page but get_the_ID() correctly tracks each resource in
		// turn).
		if ( ! $resource_id ) {
			$resource_id = absint( get_the_ID() );
		}

		if ( ! $resource_id ) {
			return $this->error_markup(
				__( '[ryv_resource_button_text] needs an "id" attribute set to a Resource\'s post ID (unless it\'s placed on that Resource\'s own page).', 'ryv-eligibility-tool' )
			);
		}

		if ( ! class_exists( 'RYV_Resources_CPT' ) ) {
			return $this->error_markup( __( '[ryv_resource_button_text] is unavailable - the Resources post type failed to load.', 'ryv-eligibility-tool' ) );
		}

		$post = get_post( $resource_id );

		if ( ! $post || RYV_Resources_CPT::POST_TYPE !== $post->post_type ) {
			return $this->error_markup(
				sprintf(
					/* translators: %d: post ID */
					__( '[ryv_resource_button_text id="%d"] does not match a Resource.', 'ryv-eligibility-tool' ),
					$resource_id
				)
			);
		}

		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $resource_id ) ) {
			return $this->error_markup( __( 'This Resource is not published yet.', 'ryv-eligibility-tool' ) );
		}

		if ( ! function_exists( 'get_field' ) ) {
			return $this->error_markup( __( '[ryv_resource_button_text] needs ACF Pro active - the Button Text field lives there.', 'ryv-eligibility-tool' ) );
		}

		$label = RYV_Resources_CPT::get_cta_text( $resource_id );
		if ( '' === $label ) {
			$label = __( 'Learn More', 'ryv-eligibility-tool' );
		}

		return esc_html( $label );
	}

	/**
	 * Only rendered for whoever could actually act on it - matches the
	 * [ryv_form] shortcode's own edit-mode-only error convention.
	 *
	 * @param string $message Error message.
	 * @return string
	 */
	private function error_markup( string $message ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		return sprintf( '<div class="ryv-error-box">%s</div>', esc_html( $message ) );
	}
}
