<?php
/**
 * "Global Styles" admin submenu page: plugin-wide default colors/typography
 * for every RYV form, backed by the WP Settings API (one `ryv_global_styles`
 * option, an associative array).
 *
 * Unlike the other admin/*-page.php controllers (Import/Builder/Logs/
 * Submissions), this one isn't admin-only: build_css()/output_style_vars()
 * below feed the *frontend* CSS variable cascade the Elementor widget and
 * the [ryv_form] shortcode both render through, so RYV_Plugin instantiates
 * this one unconditionally rather than inside its `is_admin()` block - the
 * admin_menu/admin_init/admin_enqueue_scripts hooks it also registers are
 * simply no-ops on a frontend request.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Settings_Page
 */
class RYV_Settings_Page {

	/**
	 * Capability required to view/change.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Hook slug for this submenu page.
	 */
	const PAGE_SLUG = 'ryv-global-styles';

	/**
	 * Settings API option group.
	 */
	const OPTION_GROUP = 'ryv_global_styles_group';

	/**
	 * Option name - one array, not five separate autoloaded options.
	 */
	const OPTION_NAME = 'ryv_global_styles';

	/**
	 * Hook suffix for this page, set by register_menu() - used by
	 * enqueue_admin_assets() to only load the color picker here.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Field defaults.
	 *
	 * primary_color/text_color match the hardcoded fallbacks already baked
	 * into assets/src/input.css (`var(--ryv-primary-color, #1d4ed8)` etc.),
	 * so saving this page unmodified changes nothing visually. bg_color/
	 * border_radius/font_family default to '' (omitted entirely from the
	 * generated CSS - see build_css()) rather than to a value, since those
	 * three are genuinely new overrides with no prior equivalent; defaulting
	 * them to a concrete value would repaint every existing site's forms the
	 * moment this feature ships, before any admin opted in.
	 *
	 * @return array<string,string>
	 */
	public static function defaults(): array {
		return array(
			'primary_color' => '#1d4ed8',
			'text_color'    => '#1f2937',
			'bg_color'      => '',
			'border_radius' => '',
			'font_family'   => '',
		);
	}

	/**
	 * Saved values merged over defaults() - never returns a missing key.
	 *
	 * @return array<string,string>
	 */
	public static function get_values(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Priority 6: right after RYV_Assets::register() (priority 5)
		// creates the ryv-app handle this attaches inline CSS to.
		// wp_add_inline_style() only needs the handle *registered*, not
		// enqueued yet - it prints later, whenever/if something (the
		// Elementor widget, the shortcode) actually enqueues + prints that
		// stylesheet, so this never outputs anything on a page that uses
		// neither.
		add_action( 'wp_enqueue_scripts', array( $this, 'output_style_vars' ), 6 );
	}

	/**
	 * Register the submenu page under the ryv_forms CPT menu.
	 */
	public function register_menu() {
		$this->hook_suffix = add_submenu_page(
			'edit.php?post_type=' . RYV_CPT::POST_TYPE,
			__( 'Global Styles', 'ryv-eligibility-tool' ),
			__( 'Global Styles', 'ryv-eligibility-tool' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * wp-color-picker only needs to load on this one page - admin_menu's
	 * add_submenu_page() return value is the exact hook_suffix WP passes to
	 * admin_enqueue_scripts, so this cheaply no-ops on every other admin
	 * screen. Registering it here (rather than inside render_page(), which
	 * only runs once the page body is already being output) is what makes
	 * it actually load in time - admin_enqueue_scripts fires before that,
	 * while admin_head can still print it.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script(
			'wp-color-picker',
			'jQuery(function ($) { $(".ryv-color-field").wpColorPicker(); });'
		);
	}

	/**
	 * Register the option + Settings API section/fields.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section( 'ryv_global_styles_main', '', '__return_false', self::PAGE_SLUG );

		$fields = array(
			'primary_color' => __( 'Primary Color', 'ryv-eligibility-tool' ),
			'text_color'    => __( 'Text Color', 'ryv-eligibility-tool' ),
			'bg_color'      => __( 'Background Color', 'ryv-eligibility-tool' ),
			'border_radius' => __( 'Border Radius', 'ryv-eligibility-tool' ),
			'font_family'   => __( 'Font Family', 'ryv-eligibility-tool' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				$key,
				$label,
				array( $this, 'render_field' ),
				self::PAGE_SLUG,
				'ryv_global_styles_main',
				array( 'key' => $key )
			);
		}
	}

	/**
	 * Settings API field callback - one <input>, type/attributes depend on
	 * which field this is.
	 *
	 * @param array $args { 'key' => string }.
	 */
	public function render_field( array $args ) {
		$key    = $args['key'];
		$value  = self::get_values()[ $key ];
		$name   = self::OPTION_NAME . '[' . $key . ']';

		switch ( $key ) {
			case 'primary_color':
			case 'text_color':
			case 'bg_color':
				printf(
					'<input type="text" class="ryv-color-field" name="%1$s" value="%2$s" data-default-color="%3$s" />',
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( self::defaults()[ $key ] )
				);
				break;

			case 'border_radius':
				printf(
					'<input type="number" min="0" max="100" step="1" class="small-text" name="%1$s" value="%2$s" placeholder="8" /> px',
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			case 'font_family':
				printf(
					'<input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" />',
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr__( 'e.g. "Inter", sans-serif - leave blank to inherit the theme\'s font', 'ryv-eligibility-tool' )
				);
				break;
		}
	}

	/**
	 * Render the submenu page.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'ryv-eligibility-tool' ) );
		}

		require RYV_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * Settings API sanitize callback for the whole `ryv_global_styles` array.
	 *
	 * @param mixed $input Raw $_POST value for this option.
	 * @return array<string,string>
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		$border_radius = isset( $input['border_radius'] ) ? trim( (string) $input['border_radius'] ) : '';

		return array(
			'primary_color' => $this->sanitize_color( $input['primary_color'] ?? '', $defaults['primary_color'] ),
			'text_color'    => $this->sanitize_color( $input['text_color'] ?? '', $defaults['text_color'] ),
			'bg_color'      => $this->sanitize_color( $input['bg_color'] ?? '', '' ),
			'border_radius' => ( '' === $border_radius ) ? '' : (string) max( 0, min( 100, absint( $border_radius ) ) ),
			'font_family'   => $this->sanitize_font_family( $input['font_family'] ?? '' ),
		);
	}

	/**
	 * A blank value is valid (falls back to `$fallback`, e.g. `''` for
	 * "no override" on bg_color) - anything non-blank must be a real hex
	 * color or it's discarded rather than stored as garbage.
	 *
	 * @param mixed  $value    Raw submitted value.
	 * @param string $fallback Value to use if blank/invalid.
	 * @return string
	 */
	private function sanitize_color( $value, string $fallback ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return $fallback;
		}

		$hex = sanitize_hex_color( $value );

		return $hex ? $hex : $fallback;
	}

	/**
	 * font_family ends up inside a raw <style> block (build_css()), a
	 * different injection context than the HTML content this plugin
	 * otherwise trusts manage_options users with elsewhere (node.content
	 * etc.) - restricted to characters a real font-family value/stack
	 * actually needs, so it can't break out of the CSS custom property
	 * declaration it's written into.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	private function sanitize_font_family( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		$value = preg_replace( '/[^a-zA-Z0-9\s,\'"_-]/', '', $value );

		return substr( $value, 0, 200 );
	}

	/**
	 * Attach the saved Global Styles as inline CSS custom properties on the
	 * ryv-app stylesheet handle (see the priority-6 wp_enqueue_scripts hook
	 * in the constructor for why this is timing-safe).
	 */
	public function output_style_vars() {
		$css = $this->build_css();

		if ( '' === $css ) {
			return;
		}

		wp_add_inline_style( RYV_Assets::STYLE_HANDLE, $css );
	}

	/**
	 * Build the `:root, .ryv-app-wrapper { --ryv-...: ...; }` block from the
	 * saved values - a blank value is omitted entirely (not written as an
	 * empty declaration), so it falls through to whatever
	 * assets/src/input.css's own `var(--x, <default>)` fallback (or an
	 * Elementor per-instance override) would otherwise resolve to.
	 *
	 * @return string CSS, or '' if every value is blank.
	 */
	private function build_css(): string {
		$values       = self::get_values();
		$declarations = array();

		if ( '' !== $values['primary_color'] ) {
			$declarations[] = '--ryv-primary-color: ' . $values['primary_color'] . ';';
		}

		if ( '' !== $values['text_color'] ) {
			$declarations[] = '--ryv-text-color: ' . $values['text_color'] . ';';
		}

		if ( '' !== $values['bg_color'] ) {
			$declarations[] = '--ryv-bg-color: ' . $values['bg_color'] . ';';
		}

		if ( '' !== $values['border_radius'] ) {
			$declarations[] = '--ryv-radius: ' . absint( $values['border_radius'] ) . 'px;';
		}

		if ( '' !== $values['font_family'] ) {
			$declarations[] = '--ryv-font: ' . $values['font_family'] . ';';
		}

		if ( empty( $declarations ) ) {
			return '';
		}

		return ':root, .ryv-app-wrapper { ' . implode( ' ', $declarations ) . ' }';
	}
}