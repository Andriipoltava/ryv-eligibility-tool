<?php
/**
 * Tools -> CLC Design Tokens: one-click import of RYV_Design_Tokens into
 * the active Elementor Kit's Global Colors/Typography.
 *
 * Moved here from the theme's functions.php (genesis-block-theme), same as
 * RYV_Design_Tokens itself - see that class's docblock for the full
 * rationale. Kept under the Tools menu (not RYV Forms) since it isn't
 * specific to the eligibility questionnaire - it's the whole site's brand
 * import.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Design_Tokens_Page
 */
class RYV_Design_Tokens_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	/**
	 * Register the Tools submenu page.
	 */
	public function register_page() {
		add_management_page(
			__( 'CLC Design Tokens', 'ryv-eligibility-tool' ),
			__( 'CLC Design Tokens', 'ryv-eligibility-tool' ),
			'manage_options',
			'clc-design-tokens',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the page and handle the import form submission.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$imported = false;
		$error    = '';

		if ( isset( $_POST['clc_import_tokens'] ) && check_admin_referer( 'clc_import_tokens_nonce' ) ) {
			$result = RYV_Design_Tokens::import_to_elementor();
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$imported = true;
			}
		}

		$tokens      = RYV_Design_Tokens::get_tokens();
		$color_count = count( $tokens['colors'] );
		$typo_count  = count( $tokens['typography'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CLC Restore Your Vote — Design Tokens', 'ryv-eligibility-tool' ); ?></h1>

			<?php if ( $imported ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Tokens imported into the Elementor Kit successfully!', 'ryv-eligibility-tool' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<div class="card" style="max-width:640px;padding:20px;">
				<h2><?php esc_html_e( 'What will be imported:', 'ryv-eligibility-tool' ); ?></h2>
				<ul style="list-style:disc;padding-left:20px;">
					<li>
						<?php
						printf(
							/* translators: %d: color count */
							esc_html__( '%d global colors - the Style Guide palette (Navy, Red, Red Bright, Blue, Teal, Pale, White) plus working shades for text, backgrounds, and borders', 'ryv-eligibility-tool' ),
							(int) $color_count
						);
						?>
					</li>
					<li>
						<?php
						printf(
							/* translators: %d: typography preset count */
							esc_html__( '%d typography presets (H1-H6, Body, Prehead, Breadcrumb, Input, Button, Button Link, Nav Link)', 'ryv-eligibility-tool' ),
							(int) $typo_count
						);
						?>
					</li>
					<li>
						<?php
						printf(
							/* translators: 1: heading font, 2: body font */
							esc_html__( 'Fonts: %1$s (headings) + %2$s (body)', 'ryv-eligibility-tool' ),
							'<strong>' . esc_html( RYV_Design_Tokens::font_heading() ) . '</strong>',
							'<strong>' . esc_html( RYV_Design_Tokens::font_body() ) . '</strong>'
						);
						?>
					</li>
				</ul>

				<p style="margin-top:12px;">
					<span style="display:inline-block;width:26px;height:16px;background:#002A41;vertical-align:middle;"></span>
					<span style="display:inline-block;width:26px;height:16px;background:#A21D1B;vertical-align:middle;"></span>
					<span style="display:inline-block;width:26px;height:16px;background:#EC2927;vertical-align:middle;"></span>
					<span style="display:inline-block;width:26px;height:16px;background:#03509F;vertical-align:middle;"></span>
					<span style="display:inline-block;width:26px;height:16px;background:#2A6A8F;vertical-align:middle;"></span>
					<span style="display:inline-block;width:26px;height:16px;background:#D4EFFC;vertical-align:middle;"></span>
					<span style="display:inline-block;width:26px;height:16px;background:#FFFFFF;border:1px solid #ccd;vertical-align:middle;"></span>
				</p>

				<p style="color:#d63638;">
					<strong><?php esc_html_e( 'Warning:', 'ryv-eligibility-tool' ); ?></strong>
					<?php esc_html_e( 'This overwrites the existing global colors and typography in the Elementor Kit!', 'ryv-eligibility-tool' ); ?>
				</p>

				<p style="color:#646970;font-size:12px;">
					<?php
					printf(
						/* translators: 1: clc_font_heading filter name, 2: clc_font_body filter name */
						wp_kses(
							__( 'Sentinel and District Pro are licensed fonts, not available on Google Fonts. Until they\'re added (Elementor Pro &rarr; Custom Fonts, or an Adobe Typekit embed), free look-alikes can be swapped in via the %1$s / %2$s filters - e.g. <code>Bitter</code> and <code>Barlow</code>.', 'ryv-eligibility-tool' ),
							array( 'code' => array() )
						),
						'<code>clc_font_heading</code>',
						'<code>clc_font_body</code>'
					);
					?>
				</p>

				<form method="post">
					<?php wp_nonce_field( 'clc_import_tokens_nonce' ); ?>
					<p>
						<button type="submit" name="clc_import_tokens" class="button button-primary button-hero">
							<?php esc_html_e( 'Import Tokens to Elementor Kit', 'ryv-eligibility-tool' ); ?>
						</button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}
}
