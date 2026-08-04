<?php
/**
 * `[ryv_form id="123"]` shortcode: renders the exact same `.ryv-app-wrapper`
 * mount point as the Elementor widget (widgets/class-ryv-widget.php's
 * render()), so a form can be embedded on any page/post - no Elementor
 * required.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Shortcode
 */
class RYV_Shortcode {

	/**
	 * Shortcode tag.
	 */
	const TAG = 'ryv_form';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_shortcode' ) );

		// Priority 10: after RYV_Assets::register() (priority 5) has
		// registered the handles this enqueues.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ), 10 );
	}

	/**
	 * Register the [ryv_form] shortcode.
	 */
	public function register_shortcode() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Enqueue the same CSS/JS the Elementor widget declares via
	 * get_style_depends()/get_script_depends() up front - while wp_head can
	 * still print the stylesheet - whenever the current singular post/page's
	 * own content contains the shortcode. render() below also enqueues
	 * directly as a fallback for a shortcode invoked some other way (a
	 * classic text widget, a template's do_shortcode() call, ...), but that
	 * path runs during the_content, after wp_head has already fired, so it
	 * can only reliably guarantee the (in_footer) script, not the
	 * stylesheet - this hook is what makes the common case (the shortcode
	 * sitting in a post/page's own content) enqueue correctly.
	 */
	public function maybe_enqueue_assets() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post || ! has_shortcode( $post->post_content, self::TAG ) ) {
			return;
		}

		$this->enqueue_assets();
	}

	/**
	 * Enqueue the exact handles RYV_Assets registers (and the Elementor
	 * widget depends on) - never re-registers its own copies, so there is
	 * only ever one ryv-app / ryv-decision-engine on a page regardless of
	 * whether Elementor, the shortcode, or both are in use.
	 */
	private function enqueue_assets() {
		wp_enqueue_style( RYV_Assets::STYLE_HANDLE );
		wp_enqueue_script( RYV_Assets::SCRIPT_HANDLE );
	}

	/**
	 * Shortcode callback. Outputs the same mount point RYV_Widget::render()
	 * does - assets/js/decision-engine.js finds it by
	 * `.ryv-app-wrapper[data-form-id]` and takes over from there; no markup
	 * for the questionnaire itself is ever rendered server-side.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$atts    = shortcode_atts( array( 'id' => '' ), $atts, self::TAG );
		$form_id = absint( $atts['id'] );

		if ( ! $form_id ) {
			return $this->error_markup(
				__( '[ryv_form] requires an "id" attribute set to an Eligibility Form\'s post ID.', 'ryv-eligibility-tool' )
			);
		}

		$post = get_post( $form_id );

		if ( ! $post || RYV_CPT::POST_TYPE !== $post->post_type ) {
			return $this->error_markup(
				sprintf(
					/* translators: %d: post ID */
					__( '[ryv_form id="%d"] does not match an Eligibility Form.', 'ryv-eligibility-tool' ),
					$form_id
				)
			);
		}

		// Same visibility rule as RYV_API::get_viewable_form(): published to
		// everyone, draft only to whoever can edit that specific form - lets
		// an editor preview a draft form through the shortcode before
		// publishing, same as they already can through the Elementor widget.
		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $form_id ) ) {
			return $this->error_markup( __( 'This Eligibility Form is not published yet.', 'ryv-eligibility-tool' ) );
		}

		// Fallback for a shortcode rendered outside the queried post's own
		// content (see maybe_enqueue_assets() above) - a no-op if already
		// enqueued there.
		$this->enqueue_assets();

		return sprintf(
			'<div class="ryv-app-wrapper" data-form-id="%d" data-show-start-over="1" data-labels="%s"></div>',
			$form_id,
			esc_attr( wp_json_encode( array() ) )
		);
	}

	/**
	 * Only rendered for whoever could actually act on it (matches the
	 * Elementor widget's own edit-mode-only error box) - a public visitor
	 * just sees nothing rather than an internal error string.
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