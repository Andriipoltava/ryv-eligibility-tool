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
	 * Option name - one array, not two dozen separate autoloaded options.
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
	 * One declarative description of every field, grouped into Settings API
	 * sections - register_settings()/render_field()/sanitize()/build_css()
	 * below all iterate this single structure instead of hand-listing each
	 * field key in four separate places (the shape this page had at 5
	 * fields; it stopped scaling once per-component color/spacing overrides
	 * were added, taking this to ~24). A method rather than a class `const`
	 * specifically because 'label' needs a real `__()` call for
	 * translatability (Loco Translate/WPML both work off of real gettext
	 * calls picked up from source - see ryv-eligibility-tool.php's
	 * load_plugin_textdomain() docblock) - a `const` initializer can't
	 * contain a function call at all.
	 *
	 * Per-field shape:
	 *   'type'    'color' | 'px' | 'font' - which render_field()/sanitize()
	 *             branch handles it.
	 *   'label'   Settings API field label.
	 *   'css_var' The exact CSS custom property this field writes -
	 *             deliberately the SAME name the corresponding per-instance
	 *             Elementor widget control already writes to (see
	 *             widgets/class-ryv-widget.php's register_*_style_controls()
	 *             methods, none of which carry a hardcoded 'default' for any
	 *             field also listed here - see register_color_controls()'s
	 *             docblock for why that matters) - so a value here only
	 *             ever adds a lower-specificity, `:root, .ryv-app-wrapper`-
	 *             scoped fallback layer underneath Elementor, never a
	 *             competing declaration.
	 *   'default' Stored/emitted default. '' means "omit the CSS
	 *             declaration entirely unless an admin sets a value" (see
	 *             build_css()) - used for every field that's a genuinely
	 *             new override with no prior equivalent, so shipping this
	 *             feature (or adding a field to it later) never repaints an
	 *             existing site's forms before an admin opts in. A concrete
	 *             default (primary_color, text_color) is only for fields
	 *             that already had a hardcoded fallback baked into
	 *             assets/src/input.css before Global Styles existed at all.
	 *   'max'     'px' fields only - sanitize() clamps to [0, max].
	 *   'placeholder' 'px'/'font' fields only - input placeholder text
	 *             ('font''s is also its help text, same as before this
	 *             page had more than one field per type).
	 *
	 * @return array<string,array{label:string,fields:array<string,array>}>
	 */
	private static function field_groups(): array {
		return array(
			'colors'         => array(
				'label'  => __( 'Colors & Typography', 'ryv-eligibility-tool' ),
				'fields' => array(
					'primary_color' => array(
						'type'    => 'color',
						'label'   => __( 'Primary Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-primary-color',
						'default' => '#002a41',
					),
					'text_color'    => array(
						'type'    => 'color',
						'label'   => __( 'Text Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-text-color',
						'default' => '#1f2937',
					),
					'bg_color'      => array(
						'type'    => 'color',
						'label'   => __( 'Background Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-bg-color',
						'default' => '',
					),
					'border_radius' => array(
						'type'        => 'px',
						'label'       => __( 'Border Radius', 'ryv-eligibility-tool' ),
						'css_var'     => '--ryv-radius',
						'default'     => '',
						'max'         => 100,
						'placeholder' => '8',
					),
					'font_family'   => array(
						'type'        => 'font',
						'label'       => __( 'Font Family', 'ryv-eligibility-tool' ),
						'css_var'     => '--ryv-font',
						'default'     => '',
						'placeholder' => __( 'e.g. "Inter", sans-serif - leave blank to inherit the theme\'s font', 'ryv-eligibility-tool' ),
					),
					'font_size'     => array(
						'type'        => 'px',
						'label'       => __( 'Base Font Size', 'ryv-eligibility-tool' ),
						'css_var'     => '--ryv-font-size',
						'default'     => '',
						'max'         => 60,
						'placeholder' => '18',
					),
				),
			),
			'wrapper'        => array(
				'label'  => __( 'Wrapper', 'ryv-eligibility-tool' ),
				'fields' => array(
					'wrapper_max_width'    => array(
						'type'        => 'px',
						'label'       => __( 'Max Width', 'ryv-eligibility-tool' ),
						'css_var'     => '--ryv-wrapper-max-width',
						'default'     => '',
						'max'         => 2000,
						'placeholder' => '576',
					),
					'wrapper_padding'      => array(
						'type'    => 'px',
						'label'   => __( 'Padding', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-wrapper-padding',
						'default' => '',
						'max'     => 200,
					),
					'wrapper_border_width' => array(
						'type'    => 'px',
						'label'   => __( 'Border Width', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-wrapper-border-width',
						'default' => '',
						'max'     => 20,
					),
					'wrapper_border_color' => array(
						'type'    => 'color',
						'label'   => __( 'Border Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-wrapper-border-color',
						'default' => '',
					),
				),
			),
			'buttons'        => array(
				'label'  => __( 'Choice Buttons', 'ryv-eligibility-tool' ),
				'fields' => array(
					'button_text_color'   => array(
						'type'    => 'color',
						'label'   => __( 'Text Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-btn-text-color',
						'default' => '',
					),
					'button_bg_color'     => array(
						'type'    => 'color',
						'label'   => __( 'Background Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-btn-bg',
						'default' => '',
					),
					'button_border_color' => array(
						'type'    => 'color',
						'label'   => __( 'Border Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-btn-border-color',
						'default' => '',
					),
					'button_padding'      => array(
						'type'    => 'px',
						'label'   => __( 'Padding', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-btn-padding',
						'default' => '',
						'max'     => 60,
					),
				),
			),
			'dropdown_input' => array(
				'label'  => __( 'Dropdown & Input', 'ryv-eligibility-tool' ),
				'fields' => array(
					'dropdown_text_color'   => array(
						'type'    => 'color',
						'label'   => __( 'Dropdown Text Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-select-text-color',
						'default' => '',
					),
					'dropdown_bg_color'     => array(
						'type'    => 'color',
						'label'   => __( 'Dropdown Background Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-select-bg',
						'default' => '',
					),
					'dropdown_border_color' => array(
						'type'    => 'color',
						'label'   => __( 'Dropdown Border Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-select-border-color',
						'default' => '',
					),
					'input_text_color'      => array(
						'type'    => 'color',
						'label'   => __( 'Text Input Text Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-input-text-color',
						'default' => '',
					),
					'input_bg_color'        => array(
						'type'    => 'color',
						'label'   => __( 'Text Input Background Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-input-bg',
						'default' => '',
					),
					'input_border_color'    => array(
						'type'    => 'color',
						'label'   => __( 'Text Input Border Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-input-border-color',
						'default' => '',
					),
				),
			),
			'radio_help'     => array(
				'label'  => __( 'Radio & Help Tooltip', 'ryv-eligibility-tool' ),
				'fields' => array(
					'radio_text_color'        => array(
						'type'    => 'color',
						'label'   => __( 'Radio Text Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-radio-text-color',
						'default' => '',
					),
					'radio_accent_color'      => array(
						'type'    => 'color',
						'label'   => __( 'Radio Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-radio-accent-color',
						'default' => '',
					),
					'help_icon_color'         => array(
						'type'    => 'color',
						'label'   => __( 'Help Icon Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-help-icon-color',
						'default' => '',
					),
					'help_icon_bg_color'      => array(
						'type'    => 'color',
						'label'   => __( 'Help Icon Background Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-help-icon-bg',
						'default' => '',
					),
					'help_tooltip_text_color' => array(
						'type'    => 'color',
						'label'   => __( 'Help Tooltip Text Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-help-tooltip-text-color',
						'default' => '',
					),
					'help_tooltip_bg_color'   => array(
						'type'    => 'color',
						'label'   => __( 'Help Tooltip Background Color', 'ryv-eligibility-tool' ),
						'css_var' => '--ryv-help-tooltip-bg',
						'default' => '',
					),
				),
			),
		);
	}

	/**
	 * Field defaults, flattened from field_groups().
	 *
	 * @return array<string,string>
	 */
	public static function defaults(): array {
		$out = array();

		foreach ( self::field_groups() as $group ) {
			foreach ( $group['fields'] as $key => $field ) {
				$out[ $key ] = $field['default'];
			}
		}

		return $out;
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
	 * Register the option + one Settings API section per field_groups()
	 * entry (mirroring the Elementor widget's own Style-tab section
	 * grouping, for the same reason - 24 fields under one blank heading
	 * isn't browsable).
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

		foreach ( self::field_groups() as $group_id => $group ) {
			$section_id = 'ryv_global_styles_' . $group_id;

			add_settings_section( $section_id, $group['label'], '__return_false', self::PAGE_SLUG );

			foreach ( $group['fields'] as $key => $field ) {
				add_settings_field(
					$key,
					$field['label'],
					array( $this, 'render_field' ),
					self::PAGE_SLUG,
					$section_id,
					array(
						'key'   => $key,
						'field' => $field,
					)
				);
			}
		}
	}

	/**
	 * Settings API field callback - one <input>, shape depends on the
	 * field's declared 'type' (see field_groups()'s docblock).
	 *
	 * @param array $args { 'key' => string, 'field' => array }.
	 */
	public function render_field( array $args ) {
		$key   = $args['key'];
		$field = $args['field'];
		$value = self::get_values()[ $key ];
		$name  = self::OPTION_NAME . '[' . $key . ']';

		switch ( $field['type'] ) {
			case 'color':
				printf(
					'<input type="text" class="ryv-color-field" name="%1$s" value="%2$s" data-default-color="%3$s" />',
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( $field['default'] )
				);
				break;

			case 'px':
				printf(
					'<input type="number" min="0" max="%4$d" step="1" class="small-text" name="%1$s" value="%2$s" placeholder="%3$s" /> px',
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( $field['placeholder'] ?? '' ),
					(int) ( $field['max'] ?? 9999 )
				);
				break;

			case 'font':
				printf(
					'<input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" />',
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( $field['placeholder'] ?? '' )
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
	 * Settings API sanitize callback for the whole `ryv_global_styles`
	 * array - iterates field_groups(), dispatching on each field's 'type'.
	 *
	 * @param mixed $input Raw $_POST value for this option.
	 * @return array<string,string>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = array();

		foreach ( self::field_groups() as $group ) {
			foreach ( $group['fields'] as $key => $field ) {
				$raw = $input[ $key ] ?? '';

				switch ( $field['type'] ) {
					case 'color':
						$out[ $key ] = $this->sanitize_color( $raw, $field['default'] );
						break;

					case 'px':
						$out[ $key ] = $this->sanitize_px( $raw, (int) ( $field['max'] ?? 9999 ) );
						break;

					case 'font':
						$out[ $key ] = $this->sanitize_font_family( $raw );
						break;

					default:
						$out[ $key ] = '';
				}
			}
		}

		return $out;
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
	 * A blank value stays blank (see field_groups()'s docblock on
	 * 'default' === '' - omitted from build_css() entirely, not written as
	 * "0px"). Anything else is clamped to a non-negative integer no larger
	 * than $max.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param int   $max   Upper bound for this field.
	 * @return string
	 */
	private function sanitize_px( $value, int $max ): string {
		$trimmed = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $trimmed ) {
			return '';
		}

		return (string) max( 0, min( $max, absint( $trimmed ) ) );
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
	 * saved values, iterating field_groups() - a blank value is omitted
	 * entirely (not written as an empty declaration), so it falls through
	 * to whatever assets/src/input.css's own `var(--x, <default>)` fallback
	 * (or an Elementor per-instance override, which always wins by
	 * specificity when set - see field_groups()'s own docblock) would
	 * otherwise resolve to. 'px' fields get a literal `px` suffix; every
	 * other type is written as-is (a color hex, or a sanitized font stack).
	 *
	 * @return string CSS, or '' if every value is blank.
	 */
	private function build_css(): string {
		$values       = self::get_values();
		$declarations = array();

		foreach ( self::field_groups() as $group ) {
			foreach ( $group['fields'] as $key => $field ) {
				$value = $values[ $key ];

				if ( '' === $value ) {
					continue;
				}

				$unit           = ( 'px' === $field['type'] ) ? 'px' : '';
				$declarations[] = $field['css_var'] . ': ' . $value . $unit . ';';
			}
		}

		if ( empty( $declarations ) ) {
			return '';
		}

		return ':root, .ryv-app-wrapper { ' . implode( ' ', $declarations ) . ' }';
	}
}
