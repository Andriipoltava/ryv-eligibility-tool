<?php
/**
 * CLC Restore Your Vote - design tokens (colors, typography, spacing).
 *
 * Moved here from the theme's functions.php (genesis-block-theme) so the
 * tokens ship with the plugin rather than the theme - the theme can be
 * swapped without losing the brand's Elementor Kit import/CSS variables.
 *
 * Source: Figma -> CLC Restore Your Vote -> "Style Guide" page.
 * Palette: 002A41 / A21D1B / EC2927 / 03509F / 2A6A8F / D4EFFC / FFFFFF
 * Fonts:   Sentinel (headings) + District Pro (body)
 *
 * Usage:
 * 1. This class is loaded by RYV_Plugin like every other includes/ file, so
 *    it only runs once Elementor is confirmed active (see class-ryv-plugin.php).
 * 2. Run the one-click import: WP Admin -> Tools -> CLC Design Tokens -> "Import Tokens".
 * 3. CSS custom properties (--clc-*) are always output, whether or not the
 *    tokens have ever been imported into the Kit.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Design_Tokens
 */
class RYV_Design_Tokens {

	/**
	 * Register the always-on hooks (CSS custom properties + font preconnects).
	 * The one-click Elementor Kit import is wired up separately by
	 * RYV_Design_Tokens_Page (admin-only), which calls import_to_elementor()
	 * directly rather than through a hook.
	 */
	public function __construct() {
		add_action( 'wp_head', array( __CLASS__, 'output_css_custom_properties' ), 5 );
		add_action( 'elementor/editor/after_enqueue_styles', array( __CLASS__, 'output_css_custom_properties' ), 5 );
		add_action( 'wp_head', array( __CLASS__, 'output_font_preconnect' ), 1 );
		// Site chrome rather than tokens, but it belongs on the same hook:
		// the language switcher sits in the header, i.e. on every page,
		// while assets/css/app.css only loads where an RYV widget or
		// shortcode is actually present.
		// Priority 20, i.e. after WordPress prints enqueued stylesheets on
		// this same hook: WPML's own switcher CSS is enqueued, so an earlier
		// block would lose the source-order tie on every rule of equal
		// specificity (its flag styling in particular).
		add_action( 'wp_head', array( __CLASS__, 'output_language_switcher_styles' ), 20 );
	}

	/**
	 * ============================================================
	 * 3b. HEADER LANGUAGE SWITCHER (site chrome)
	 * ============================================================
	 *
	 * WPML's legacy switcher renders as two 18x12 flag images, which next to
	 * the header's 22px/800 menu and its solid red CTA reads as a leftover
	 * plugin default rather than part of the design. This restyles it into a
	 * compact `EN / ES` pair in the menu's own typography and token colors:
	 * current language in the primary navy, the other muted, red on hover.
	 *
	 * The codes are CSS-generated because WPML is emitting flags only. Both
	 * the flag *and* any name/code WPML might render are hidden, so the
	 * result stays identical whichever "Display as" option is set in WPML >
	 * Languages - rather than showing a name and a code side by side if that
	 * setting is ever changed. Accessibility is unaffected either way: each
	 * link keeps WPML's own `aria-label`/`title` ("Switch to English"), which
	 * is what a screen reader announces; the visible glyph was never the
	 * accessible name.
	 */
	public static function output_language_switcher_styles() {
		?>
<style id="clc-language-switcher">
.wpml-ls-legacy-list-horizontal { padding: 0; border: 0; background: transparent; }
.wpml-ls-legacy-list-horizontal > ul { display: flex; align-items: center; gap: 0; margin: 0; padding: 0; list-style: none; }
.wpml-ls-legacy-list-horizontal .wpml-ls-item { margin: 0; }
/* Separator between the two codes, never before the first or after the last. */
.wpml-ls-legacy-list-horizontal .wpml-ls-item + .wpml-ls-item::before {
	content: "/";
	color: var(--clc-text-secondary, #62707c);
	opacity: 0.5;
	font-weight: 700;
}
.wpml-ls-legacy-list-horizontal .wpml-ls-link {
	display: inline-block;
	padding: 0 0.5rem;
	font-family: var(--clc-font-body, inherit);
	font-size: 16px;
	font-weight: 800;
	letter-spacing: 1.2px;
	line-height: 1.2;
	text-transform: uppercase;
	text-decoration: none;
	color: var(--clc-text-secondary, #62707c);
	transition: color 150ms ease;
}
.wpml-ls-legacy-list-horizontal .wpml-ls-current-language > .wpml-ls-link { color: var(--clc-primary, #002a41); }
.wpml-ls-legacy-list-horizontal .wpml-ls-link:hover,
.wpml-ls-legacy-list-horizontal .wpml-ls-link:focus-visible { color: var(--clc-red, #a21d1b); }
/* Whatever WPML itself renders inside the link is replaced by the code below. */
.wpml-ls-legacy-list-horizontal a.wpml-ls-link img.wpml-ls-flag,
.wpml-ls-legacy-list-horizontal a.wpml-ls-link .wpml-ls-native,
.wpml-ls-legacy-list-horizontal a.wpml-ls-link .wpml-ls-display { display: none !important; }
.wpml-ls-legacy-list-horizontal .wpml-ls-link[lang="en"]::after { content: "EN"; }
.wpml-ls-legacy-list-horizontal .wpml-ls-link[lang="es"]::after { content: "ES"; }
</style>
		<?php
	}

	/**
	 * ============================================================
	 * 0. FONTS
	 * ============================================================
	 *
	 * Sentinel (Hoefler) and District Pro (Adobe Fonts) are licensed fonts,
	 * not available on Google Fonts. Until they're actually loaded on the
	 * site (Elementor Pro -> Custom Fonts, or an Adobe Typekit embed), the
	 * closest free look-alikes can be swapped in here temporarily: 'Bitter'
	 * and 'Barlow'.
	 *
	 * Change it in one place - the tokens, the Kit import, and the CSS
	 * variables all pick it up.
	 */

	/**
	 * @return string
	 */
	public static function font_heading() {
		return apply_filters( 'clc_font_heading', 'Sentinel' );
	}

	/**
	 * @return string
	 */
	public static function font_body() {
		return apply_filters( 'clc_font_body', 'District Pro' );
	}

	/**
	 * Full stack with fallbacks - for CSS outside Elementor.
	 *
	 * @return string
	 */
	public static function font_stack_heading() {
		return sprintf( '"%s", "Bitter", Georgia, "Times New Roman", serif', self::font_heading() );
	}

	/**
	 * @return string
	 */
	public static function font_stack_body() {
		return sprintf( '"%s", "Barlow", system-ui, -apple-system, "Segoe UI", sans-serif', self::font_body() );
	}

	/**
	 * ============================================================
	 * 1. DESIGN TOKENS - the single source of truth
	 * ============================================================
	 *
	 * @return array{colors: array, typography: array, spacing: array}
	 */
	public static function get_tokens() {
		$heading = self::font_heading();
		$body    = self::font_body();

		return array(

			// -- Colors --------------------------------------------------
			// The first seven are exactly the Style Guide swatches. The
			// rest are working shades already used across the layouts
			// (text, backgrounds, borders). `_id` keeps the original ids
			// where the meaning hasn't changed - already-built widgets may
			// reference them via globals/colors.
			'colors' => array(
				array(
					'_id'   => 'clc_primary',
					'title' => 'Navy',
					'color' => '#002A41',
				),
				array(
					'_id'   => 'clc_red',
					'title' => 'Red (UI)',
					'color' => '#A21D1B',
				),
				array(
					'_id'   => 'clc_red_bright',
					'title' => 'Red Bright (graphics)',
					'color' => '#EC2927',
				),
				array(
					'_id'   => 'clc_blue',
					'title' => 'Blue',
					'color' => '#03509F',
				),
				array(
					'_id'   => 'clc_teal',
					'title' => 'Teal',
					'color' => '#2A6A8F',
				),
				array(
					'_id'   => 'clc_pale',
					'title' => 'Pale Blue',
					'color' => '#D4EFFC',
				),
				array(
					'_id'   => 'clc_white',
					'title' => 'White',
					'color' => '#FFFFFF',
				),
				array(
					'_id'   => 'clc_text_primary',
					'title' => 'Text Primary',
					'color' => '#0E3247',
				),
				array(
					'_id'   => 'clc_text_secondary',
					'title' => 'Text Secondary',
					'color' => '#3A5A6C',
				),
				array(
					'_id'   => 'clc_text_muted',
					'title' => 'Text Muted',
					'color' => '#6B8494',
				),
				array(
					'_id'   => 'clc_text_light',
					'title' => 'Text Light',
					'color' => '#7E98A6',
				),
				array(
					'_id'   => 'clc_bg_light',
					'title' => 'Background Light',
					'color' => '#F4F6F8',
				),
				array(
					'_id'   => 'clc_bg_alt',
					'title' => 'Background Alt',
					'color' => '#F7F9FA',
				),
				array(
					'_id'   => 'clc_dark',
					'title' => 'Dark (ticker bar)',
					'color' => '#00202F',
				),
				array(
					'_id'   => 'clc_border',
					'title' => 'Border',
					'color' => '#DCE5EA',
				),
				array(
					'_id'   => 'clc_gray_medium',
					'title' => 'Divider Light',
					'color' => '#D7E2E8',
				),
			),

			// -- Typography ------------------------------------------------
			// Style Guide rows:
			//   H1  Sentinel      Black      70   auto    0
			//   H2  Sentinel      Bold       36   auto    0
			//   H3  Sentinel      Bold       28   auto    0
			//   H4  District Pro  Demi       26   145%    0
			//   SPAN-Breadcrumb   Regular    15   auto    0
			//   SPAN-PREHEAD      ExtraBold  16   52      1.68px
			//   P   District Pro  Regular    22   150%    0
			//   Input             Regular    17   auto    0
			//   Button            Bold       18   auto    0
			//   Button Link       Bold       18   auto    0
			// Tablet/mobile aren't specified in the guide - the values
			// below are scaled down proportionally so long H1/H2 text
			// doesn't break the grid.
			'typography' => array(

				'h1' => array(
					'label'            => 'H1 - Hero',
					'font_family'      => $heading,
					'font_weight'      => '900', // Black.
					'font_size'        => 70,
					'font_size_tablet' => 48,
					'font_size_mobile' => 36,
					'line_height'      => 1.08,
					'letter_spacing'   => 0,
				),

				'h2' => array(
					'label'            => 'H2 - Section Title',
					'font_family'      => $heading,
					'font_weight'      => '700', // Bold.
					'font_size'        => 36,
					'font_size_tablet' => 30,
					'font_size_mobile' => 26,
					'line_height'      => 1.2,
					'letter_spacing'   => 0,
				),

				'h3' => array(
					'label'            => 'H3 - Subsection',
					'font_family'      => $heading,
					'font_weight'      => '700',
					'font_size'        => 28,
					'font_size_tablet' => 24,
					'font_size_mobile' => 22,
					'line_height'      => 1.25,
					'letter_spacing'   => 0,
				),

				'h4' => array(
					'label'            => 'H4 - Lead / Card Title',
					'font_family'      => $body,
					'font_weight'      => '600', // Demi.
					'font_size'        => 26,
					'font_size_tablet' => 22,
					'font_size_mobile' => 20,
					'line_height'      => 1.45, // 145%.
					'letter_spacing'   => 0,
				),

				'h5' => array(
					'label'            => 'H5 - Small Serif Heading',
					'font_family'      => $heading,
					'font_weight'      => '700',
					'font_size'        => 22,
					'font_size_tablet' => 20,
					'font_size_mobile' => 19,
					'line_height'      => 1.3,
					'letter_spacing'   => 0,
				),

				// SPAN-PREHEAD from the Style Guide. Its "LH 52" is the
				// block's height, not the line-height, hence 1.4em here.
				'h6' => array(
					'label'            => 'H6 - Prehead / Eyebrow',
					'font_family'      => $body,
					'font_weight'      => '800', // ExtraBold.
					'font_size'        => 16,
					'font_size_tablet' => 15,
					'font_size_mobile' => 14,
					'line_height'      => 1.4,
					'letter_spacing'   => 1.68,
					'text_transform'   => 'uppercase',
				),

				// P from the Style Guide.
				'body' => array(
					'label'            => 'Body (P)',
					'font_family'      => $body,
					'font_weight'      => '400',
					'font_size'        => 22,
					'font_size_tablet' => 19,
					'font_size_mobile' => 17,
					'line_height'      => 1.5, // 150%.
					'letter_spacing'   => 0,
				),

				'body_large' => array(
					'label'            => 'Body Large (lead)',
					'font_family'      => $body,
					'font_weight'      => '600',
					'font_size'        => 22,
					'font_size_tablet' => 20,
					'font_size_mobile' => 18,
					'line_height'      => 1.5,
					'letter_spacing'   => 0,
				),

				'body_medium' => array(
					'label'            => 'Body Medium (cards)',
					'font_family'      => $body,
					'font_weight'      => '400',
					'font_size'        => 19,
					'font_size_tablet' => 18,
					'font_size_mobile' => 16,
					'line_height'      => 1.55,
					'letter_spacing'   => 0,
				),

				'subtitle' => array(
					'label'            => 'Subtitle',
					'font_family'      => $body,
					'font_weight'      => '700',
					'font_size'        => 20,
					'font_size_tablet' => 18,
					'font_size_mobile' => 17,
					'line_height'      => 1.4,
					'letter_spacing'   => 0,
				),

				// SPAN-Breadcrumb from the Style Guide.
				'small' => array(
					'label'            => 'Small / Breadcrumb',
					'font_family'      => $body,
					'font_weight'      => '400',
					'font_size'        => 15,
					'font_size_tablet' => 14,
					'font_size_mobile' => 14,
					'line_height'      => 1.5,
					'letter_spacing'   => 0,
				),

				'input' => array(
					'label'            => 'Input',
					'font_family'      => $body,
					'font_weight'      => '400',
					'font_size'        => 17,
					'font_size_tablet' => 16,
					'font_size_mobile' => 16,
					'line_height'      => 1.5,
					'letter_spacing'   => 0,
				),

				// The guide doesn't specify uppercase for buttons - the
				// layouts use sentence case ("Can I Vote?"), so
				// text_transform is deliberately 'none' here.
				'button' => array(
					'label'            => 'Button',
					'font_family'      => $body,
					'font_weight'      => '700', // Bold.
					'font_size'        => 18,
					'font_size_tablet' => 17,
					'font_size_mobile' => 16,
					'line_height'      => 1.2,
					'letter_spacing'   => 0.4,
					'text_transform'   => 'none',
				),

				'button_link' => array(
					'label'            => 'Button Link',
					'font_family'      => $body,
					'font_weight'      => '700',
					'font_size'        => 18,
					'font_size_tablet' => 17,
					'font_size_mobile' => 16,
					'line_height'      => 1.2,
					'letter_spacing'   => 0.4,
					'text_transform'   => 'none',
				),

				'nav_link' => array(
					'label'            => 'Navigation Link',
					'font_family'      => $body,
					'font_weight'      => '800',
					'font_size'        => 15,
					'font_size_tablet' => 14,
					'font_size_mobile' => 14,
					'line_height'      => 1.5,
					'letter_spacing'   => 1.6,
					'text_transform'   => 'uppercase',
				),
			),

			// -- Spacing scale (px) ----------------------------------------
			// Collected from the auto-layout padding/gap values in Figma.
			'spacing' => array(
				'3xs' => 2,
				'2xs' => 4,
				'xs'  => 8,
				'sm'  => 12,
				'md'  => 16,
				'lg'  => 24,
				'xl'  => 32,
				'2xl' => 48,
				'3xl' => 64,
				'4xl' => 80,
			),
		);
	}

	/**
	 * ============================================================
	 * 2. IMPORT INTO ELEMENTOR (one-off)
	 * ============================================================
	 *
	 * Programmatically update Elementor Kit settings.
	 *
	 * @return true|WP_Error
	 */
	public static function import_to_elementor() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return new WP_Error( 'elementor_not_active', __( 'Elementor is not active.', 'ryv-eligibility-tool' ) );
		}

		$tokens = self::get_tokens();

		// -- Get active Kit --.
		$kit_id = get_option( 'elementor_active_kit' );
		if ( ! $kit_id ) {
			return new WP_Error( 'no_kit', __( 'Elementor Kit not found.', 'ryv-eligibility-tool' ) );
		}

		$kit = \Elementor\Plugin::$instance->documents->get( $kit_id );
		if ( ! $kit ) {
			return new WP_Error( 'kit_error', __( 'Cannot load Elementor Kit document.', 'ryv-eligibility-tool' ) );
		}

		$settings = $kit->get_settings();

		// -- 1. Global Colors --.
		$settings['system_colors'] = array(); // Clear system presets.
		$settings['custom_colors'] = $tokens['colors'];

		// -- 2. Global Fonts --.
		$settings['system_typography'] = array(); // Clear system presets.

		$typography_presets = array();
		foreach ( $tokens['typography'] as $key => $typo ) {
			$preset = array(
				'_id'                          => 'clc_' . $key,
				'title'                        => $typo['label'],
				'typography_typography'       => 'custom',
				'typography_font_family'      => $typo['font_family'],
				'typography_font_weight'      => $typo['font_weight'],
				'typography_font_size'        => array(
					'unit' => 'px',
					'size' => $typo['font_size'],
				),
				'typography_font_size_tablet' => array(
					'unit' => 'px',
					'size' => $typo['font_size_tablet'],
				),
				'typography_font_size_mobile' => array(
					'unit' => 'px',
					'size' => $typo['font_size_mobile'],
				),
				'typography_line_height'      => array(
					'unit' => 'em',
					'size' => $typo['line_height'],
				),
			);

			if ( ! empty( $typo['letter_spacing'] ) ) {
				$preset['typography_letter_spacing'] = array(
					'unit' => 'px',
					'size' => $typo['letter_spacing'],
				);
			}

			if ( ! empty( $typo['text_transform'] ) ) {
				$preset['typography_text_transform'] = $typo['text_transform'];
			}

			$typography_presets[] = $preset;
		}

		$settings['custom_typography'] = $typography_presets;

		// -- 3. Default Body & Heading fonts --.
		$body_token = $tokens['typography']['body'];

		$settings['body_typography_typography']       = 'custom';
		$settings['body_typography_font_family']      = $body_token['font_family'];
		$settings['body_typography_font_weight']      = $body_token['font_weight'];
		$settings['body_typography_font_size']        = array( 'unit' => 'px', 'size' => $body_token['font_size'] );
		$settings['body_typography_font_size_tablet'] = array( 'unit' => 'px', 'size' => $body_token['font_size_tablet'] );
		$settings['body_typography_font_size_mobile'] = array( 'unit' => 'px', 'size' => $body_token['font_size_mobile'] );
		$settings['body_typography_line_height']      = array( 'unit' => 'em', 'size' => $body_token['line_height'] );

		// H1-H6 defaults.
		$heading_map = array(
			'h1' => 'h1',
			'h2' => 'h2',
			'h3' => 'h3',
			'h4' => 'h4',
			'h5' => 'h5',
			'h6' => 'h6',
		);

		foreach ( $heading_map as $tag => $token_key ) {
			$typo = $tokens['typography'][ $token_key ];

			$settings[ "{$tag}_typography_typography" ]      = 'custom';
			$settings[ "{$tag}_typography_font_family" ]     = $typo['font_family'];
			$settings[ "{$tag}_typography_font_weight" ]     = $typo['font_weight'];
			$settings[ "{$tag}_typography_font_size" ]       = array(
				'unit' => 'px',
				'size' => $typo['font_size'],
			);
			$settings[ "{$tag}_typography_font_size_tablet" ] = array(
				'unit' => 'px',
				'size' => $typo['font_size_tablet'],
			);
			$settings[ "{$tag}_typography_font_size_mobile" ] = array(
				'unit' => 'px',
				'size' => $typo['font_size_mobile'],
			);
			$settings[ "{$tag}_typography_line_height" ]     = array(
				'unit' => 'em',
				'size' => $typo['line_height'],
			);

			if ( ! empty( $typo['letter_spacing'] ) ) {
				$settings[ "{$tag}_typography_letter_spacing" ] = array(
					'unit' => 'px',
					'size' => $typo['letter_spacing'],
				);
			}

			if ( ! empty( $typo['text_transform'] ) ) {
				$settings[ "{$tag}_typography_text_transform" ] = $typo['text_transform'];
			}
		}

		// -- 4. Button typography --.
		// text_transform comes straight from the token: the guide's
		// buttons are sentence case, so a hardcoded 'uppercase' is no
		// longer baked in here.
		$btn                                           = $tokens['typography']['button'];
		$settings['button_typography_typography']      = 'custom';
		$settings['button_typography_font_family']     = $btn['font_family'];
		$settings['button_typography_font_weight']     = $btn['font_weight'];
		$settings['button_typography_font_size']       = array( 'unit' => 'px', 'size' => $btn['font_size'] );
		$settings['button_typography_font_size_tablet'] = array( 'unit' => 'px', 'size' => $btn['font_size_tablet'] );
		$settings['button_typography_font_size_mobile'] = array( 'unit' => 'px', 'size' => $btn['font_size_mobile'] );
		$settings['button_typography_line_height']     = array( 'unit' => 'em', 'size' => $btn['line_height'] );
		$settings['button_typography_letter_spacing']  = array( 'unit' => 'px', 'size' => $btn['letter_spacing'] );
		$settings['button_typography_text_transform']  = $btn['text_transform'];

		// -- Save Kit --.
		$kit->save( array( 'settings' => $settings ) );

		// Clear Elementor cache.
		if ( method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		return true;
	}

	/**
	 * ============================================================
	 * 3. CSS CUSTOM PROPERTIES (always on)
	 * ============================================================
	 *
	 * Outputs CSS variables on the frontend for use in custom CSS,
	 * Elementor's Custom CSS panel, or a theme.
	 */
	public static function output_css_custom_properties() {
		$tokens = self::get_tokens();

		echo '<style id="clc-design-tokens">
:root {
';

		// Colors.
		foreach ( $tokens['colors'] as $color ) {
			$var_name = str_replace( 'clc_', '', $color['_id'] );
			$var_name = str_replace( '_', '-', $var_name );
			printf( "\t--clc-%s: %s;\n", esc_attr( $var_name ), esc_attr( $color['color'] ) );
		}

		echo "\n";

		// Spacing.
		foreach ( $tokens['spacing'] as $name => $value ) {
			printf( "\t--clc-space-%s: %dpx;\n", esc_attr( $name ), intval( $value ) );
		}

		echo "\n";

		// Font stacks - with fallbacks, since Sentinel / District Pro are licensed.
		printf( "\t--clc-font-heading: %s;\n", self::font_stack_heading() );
		printf( "\t--clc-font-body: %s;\n", self::font_stack_body() );

		echo "\n";

		// Typography (font sizes only - families are set via Elementor).
		foreach ( $tokens['typography'] as $key => $typo ) {
			$var_key = str_replace( '_', '-', $key );
			printf( "\t--clc-fs-%s: %.1fpx;\n", esc_attr( $var_key ), floatval( $typo['font_size'] ) );
		}

		echo '}
</style>
';
	}

	/**
	 * ============================================================
	 * 4. FONT PRECONNECT (performance)
	 * ============================================================
	 *
	 * Google - for the free fallbacks (Bitter / Barlow) that Elementor
	 * loads on its own. Typekit - for District Pro, if Adobe Fonts is wired up.
	 */
	public static function output_font_preconnect() {
		echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
		echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
		echo '<link rel="preconnect" href="https://use.typekit.net" crossorigin>' . "\n";
	}
}
