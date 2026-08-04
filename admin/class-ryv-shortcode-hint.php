<?php
/**
 * "RYV Eligibility Tool" meta box on the standard post/page editor - a
 * read-only helper listing published Eligibility Forms and each one's
 * `[ryv_form id="X"]` shortcode (see RYV_Shortcode), so an author embedding
 * a form into an ordinary post/page doesn't need to already know the form's
 * post ID or leave the editor to look it up. Purely informational - unlike
 * every other admin/*-page.php controller, there is no field here to save,
 * so no save_post handling is needed at all.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Shortcode_Hint
 */
class RYV_Shortcode_Hint {

	/**
	 * Post types this meta box appears on. Deliberately just the two most
	 * common places to embed a shortcode, not every public post type -
	 * looping over all of them would also pick up unrelated CPTs (Elementor
	 * library items, etc.) this was never meant to target.
	 */
	const POST_TYPES = array( 'post', 'page' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
	}

	/**
	 * Register the meta box on each of self::POST_TYPES.
	 */
	public function register_meta_box() {
		foreach ( self::POST_TYPES as $post_type ) {
			add_meta_box(
				'ryv-shortcode-hint',
				__( 'RYV Eligibility Tool', 'ryv-eligibility-tool' ),
				array( $this, 'render' ),
				$post_type,
				'side',
				'low'
			);
		}
	}

	/**
	 * Render the meta box: a form picker + the matching shortcode, updated
	 * client-side as the picker changes, plus a copy button. Only published
	 * forms are listed - unlike the Elementor widget's own form picker
	 * (which also includes drafts, since that's edited by whoever is
	 * already trusted to manage forms), this box is aimed at any post/page
	 * author, who shouldn't be pointed at an unfinished form.
	 */
	public function render() {
		$forms = get_posts(
			array(
				'post_type'      => RYV_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( empty( $forms ) ) {
			echo '<p>' . esc_html__( 'No published Eligibility Forms yet.', 'ryv-eligibility-tool' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'Embed a form anywhere with its shortcode:', 'ryv-eligibility-tool' ) . '</p>';

		echo '<select id="ryv-shortcode-hint-select" style="width:100%;margin-bottom:8px;">';
		foreach ( $forms as $form ) {
			printf( '<option value="%d">%s</option>', (int) $form->ID, esc_html( $form->post_title ) );
		}
		echo '</select>';

		printf(
			'<p style="margin-bottom:8px;"><code id="ryv-shortcode-hint-code">%s</code></p>',
			esc_html( '[ryv_form id="' . $forms[0]->ID . '"]' )
		);

		printf(
			'<button type="button" class="button button-small" id="ryv-shortcode-hint-copy">%s</button>',
			esc_html__( 'Copy Shortcode', 'ryv-eligibility-tool' )
		);
		?>
		<script>
		( function () {
			var select  = document.getElementById( 'ryv-shortcode-hint-select' );
			var code    = document.getElementById( 'ryv-shortcode-hint-code' );
			var copyBtn = document.getElementById( 'ryv-shortcode-hint-copy' );

			if ( ! select || ! code || ! copyBtn ) {
				return;
			}

			function currentShortcode() {
				return '[ryv_form id="' + select.value + '"]';
			}

			select.addEventListener( 'change', function () {
				code.textContent = currentShortcode();
			} );

			copyBtn.addEventListener( 'click', function () {
				navigator.clipboard.writeText( currentShortcode() ).then( function () {
					var original = copyBtn.textContent;
					copyBtn.textContent = '<?php echo esc_js( __( 'Copied!', 'ryv-eligibility-tool' ) ); ?>';
					setTimeout( function () {
						copyBtn.textContent = original;
					}, 1500 );
				} );
			} );
		} )();
		</script>
		<?php
	}
}
