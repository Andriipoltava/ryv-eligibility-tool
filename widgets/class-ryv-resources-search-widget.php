<?php
/**
 * "RYV Resources Search" Elementor widget - the keyword field + 50-state
 * dropdown + Search button inside the Resources page's blue search panel.
 *
 * One of three small widgets that drive the Resources page's Loop Grid
 * (Query ID `ryv_resources_search`, see RYV_Resources_Search) - each placed
 * wherever the design wants it rather than bundled into one block:
 *
 * - this one: the search bar itself (a plain GET form, `ryv_search` /
 *   `ryv_state`),
 * - RYV_Resources_Sort_Widget: "Sort by: Most relevant ⌄" (`ryv_sort`),
 * - RYV_Resources_Count_Widget: "Showing 1–6 of 48 resources for …".
 *
 * The panel around the bar (blue background, decorative swoosh, "Find a
 * resource" eyebrow), the category tabs (Taxonomy Filter) and the cards
 * (Loop Grid + "Resources Loop Item" template) are ordinary Elementor
 * elements on the page, so all of it is edited the usual way.
 *
 * Renders outside `.ryv-app-wrapper`, so its CSS in input.css is plain,
 * unscoped `.ryv-resources-search...` rules driven by `--ryv-rs-*` custom
 * properties, which the Style tab below sets per instance.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Icons_Manager;

/**
 * Class RYV_Resources_Search_Widget
 */
class RYV_Resources_Search_Widget extends \Elementor\Widget_Base {

	const SCRIPT_HANDLE = 'ryv-resources-search';

	/**
	 * @return string
	 */
	public function get_name() {
		return 'ryv_resources_search';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return __( 'RYV Resources Search', 'ryv-eligibility-tool' );
	}

	/**
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-site-search';
	}

	/**
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'ryv' );
	}

	/**
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'resources', 'search', 'state', 'filter' );
	}

	/**
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( RYV_Assets::STYLE_HANDLE );
	}

	/**
	 * @return string[]
	 */
	public function get_script_depends() {
		return array( self::register_script() );
	}

	/**
	 * Shared by all three Resources widgets - whichever renders first
	 * registers it.
	 *
	 * @return string Script handle.
	 */
	public static function register_script(): string {
		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::SCRIPT_HANDLE,
				RYV_PLUGIN_URL . 'assets/js/resources-search.js',
				array(),
				RYV_VERSION,
				true
			);
		}

		return self::SCRIPT_HANDLE;
	}

	/**
	 * Register Content + Style tab controls.
	 */
	protected function register_controls() {
		$this->register_content_controls();
		$this->register_layout_style_controls();
		$this->register_field_style_controls();
		$this->register_dropdown_menu_style_controls();
		$this->register_button_style_controls();
		$this->register_reset_style_controls();
	}

	/**
	 * Content tab: every visitor-facing string, plus behavior toggles.
	 */
	private function register_content_controls() {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Search Bar', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'how_it_works',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'Filters a <strong>Loop Grid</strong> on the same page. In that Loop Grid set <em>Query → Query ID</em> to <code>ryv_resources_search</code>. Pair with the <strong>RYV Resources Sort</strong> and <strong>RYV Resources Count</strong> widgets.', 'ryv-eligibility-tool' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		$this->add_control(
			'search_placeholder',
			array(
				'label'   => __( 'Search Placeholder', 'ryv-eligibility-tool' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Search guides, forms, and toolkits…', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'show_state',
			array(
				'label'        => __( 'State Dropdown', 'ryv-eligibility-tool' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'separator'    => 'before',
			)
		);

		$this->add_control(
			'state_placeholder',
			array(
				'label'     => __( '"All States" Option', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'All states', 'ryv-eligibility-tool' ),
				'condition' => array( 'show_state' => 'yes' ),
			)
		);

		$this->add_control(
			'searchable_state',
			array(
				'label'        => __( 'Searchable Dropdown (Select2 style)', 'ryv-eligibility-tool' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Visitors can type to find a state. The plain dropdown is kept as a fallback when JavaScript is off.', 'ryv-eligibility-tool' ),
				'condition'    => array( 'show_state' => 'yes' ),
			)
		);

		$this->add_control(
			'no_results_text',
			array(
				'label'     => __( '"No States Found" Text', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'No states found', 'ryv-eligibility-tool' ),
				'condition' => array(
					'show_state'       => 'yes',
					'searchable_state' => 'yes',
				),
			)
		);

		$this->add_control(
			'auto_submit_state',
			array(
				'label'        => __( 'Filter As Soon As a State Is Picked', 'ryv-eligibility-tool' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => array( 'show_state' => 'yes' ),
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'     => __( 'Button Text', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Search', 'ryv-eligibility-tool' ),
				'separator' => 'before',
			)
		);

		$this->add_control(
			'button_icon',
			array(
				'label'   => __( 'Button Icon', 'ryv-eligibility-tool' ),
				'type'    => Controls_Manager::ICONS,
				'default' => array(
					'value'   => 'fas fa-arrow-right',
					'library' => 'fa-solid',
				),
				'skin'    => 'inline',
			)
		);

		$this->add_control(
			'button_icon_position',
			array(
				'label'     => __( 'Icon Position', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'after',
				'toggle'    => false,
				'options'   => array(
					'before' => array(
						'title' => __( 'Before', 'ryv-eligibility-tool' ),
						'icon'  => 'eicon-h-align-left',
					),
					'after'  => array(
						'title' => __( 'After', 'ryv-eligibility-tool' ),
						'icon'  => 'eicon-h-align-right',
					),
				),
				'condition' => array( 'button_icon[value]!' => '' ),
			)
		);

		$this->add_control(
			'reset_text',
			array(
				'label'       => __( '"Clear" Link Text', 'ryv-eligibility-tool' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Clear filters', 'ryv-eligibility-tool' ),
				'separator'   => 'before',
				'description' => __( 'Shown under the bar only while a search or state is active. Leave empty to hide.', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'ajax',
			array(
				'label'        => __( 'Update Results Without Reloading (AJAX)', 'ryv-eligibility-tool' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'separator'    => 'before',
				'description'  => __( 'Refreshes the Loop Grid, its category tabs and the results count in place. The address bar still updates, so results can be shared and Back works.', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'live_search',
			array(
				'label'        => __( 'Search While Typing', 'ryv-eligibility-tool' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => array( 'ajax' => 'yes' ),
			)
		);

		$this->add_control(
			'results_anchor',
			array(
				'label'       => __( 'Jump To (CSS ID)', 'ryv-eligibility-tool' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'resources-results',
				'description' => __( 'After pressing Search, the page scrolls to the element with this CSS ID (Advanced → CSS ID) if it is out of view. Leave empty to stay put.', 'ryv-eligibility-tool' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style: overall row layout.
	 */
	private function register_layout_style_controls() {
		$this->start_controls_section(
			'layout_style_section',
			array(
				'label' => __( 'Layout', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'gap',
			array(
				'label'      => __( 'Gap Between Fields', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 48 ) ),
				'selectors'  => array( '{{WRAPPER}}' => '--ryv-rs-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'state_width',
			array(
				'label'      => __( 'State Dropdown Width', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array( 'min' => 120, 'max' => 600 ),
					'%'  => array( 'min' => 10, 'max' => 100 ),
				),
				'selectors'  => array( '{{WRAPPER}}' => '--ryv-rs-state-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'field_height',
			array(
				'label'      => __( 'Height', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 32, 'max' => 96 ) ),
				'selectors'  => array( '{{WRAPPER}}' => '--ryv-rs-height: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style: the search input + state select share one look.
	 */
	private function register_field_style_controls() {
		$this->start_controls_section(
			'field_style_section',
			array(
				'label' => __( 'Search Field & Dropdown', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'field_typography',
				'global'   => array( 'default' => 'globals/typography?id=clc_input' ),
				'selector' => '{{WRAPPER}} .ryv-resources-search__input, {{WRAPPER}} .ryv-resources-search__select, {{WRAPPER}} .ryv-resources-search__combo-input, {{WRAPPER}} .ryv-resources-search__combo-option, {{WRAPPER}} .ryv-resources-search__combo-empty',
			)
		);

		$this->add_control(
			'field_text_color',
			array(
				'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => 'globals/colors?id=clc_primary' ),
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rs-field-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'field_placeholder_color',
			array(
				'label'     => __( 'Placeholder Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rs-placeholder-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'field_bg_color',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rs-field-bg: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'field_border_color',
			array(
				'label'     => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rs-border-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'field_focus_color',
			array(
				'label'     => __( 'Focus Border Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => 'globals/colors?id=clc_primary' ),
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rs-focus-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'field_border_width',
			array(
				'label'      => __( 'Border Width', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 6, 'step' => 0.5 ) ),
				'selectors'  => array( '{{WRAPPER}}' => '--ryv-rs-border-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'field_radius',
			array(
				'label'      => __( 'Corner Rounding', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 48 ) ),
				'selectors'  => array( '{{WRAPPER}}' => '--ryv-rs-radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style: the searchable dropdown's open list.
	 */
	private function register_dropdown_menu_style_controls() {
		$this->start_controls_section(
			'menu_style_section',
			array(
				'label'     => __( 'Dropdown List', 'ryv-eligibility-tool' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_state'       => 'yes',
					'searchable_state' => 'yes',
				),
			)
		);

		$this->add_responsive_control(
			'menu_max_height',
			array(
				'label'      => __( 'Max Height', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 120, 'max' => 600 ) ),
				'selectors'  => array( '{{WRAPPER}}' => '--ryv-rs-menu-max-height: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'menu_hover_bg',
			array(
				'label'     => __( 'Highlighted Option Background', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => 'globals/colors?id=clc_primary' ),
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rs-option-hover-bg: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'menu_hover_color',
			array(
				'label'     => __( 'Highlighted Option Text', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rs-option-hover-color: {{VALUE}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style: the Search button.
	 */
	private function register_button_style_controls() {
		$this->start_controls_section(
			'button_style_section',
			array(
				'label' => __( 'Button', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'global'   => array( 'default' => 'globals/typography?id=clc_button' ),
				'selector' => '{{WRAPPER}} .ryv-resources-search__button',
			)
		);

		$this->start_controls_tabs( 'button_color_tabs' );

		$this->start_controls_tab( 'button_normal_tab', array( 'label' => __( 'Normal', 'ryv-eligibility-tool' ) ) );

		$this->add_control(
			'button_text_color',
			array(
				'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => 'globals/colors?id=clc_primary' ),
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rs-button-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'button_bg_color',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => 'globals/colors?id=clc_white' ),
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rs-button-bg: {{VALUE}};' ),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'button_hover_tab', array( 'label' => __( 'Hover', 'ryv-eligibility-tool' ) ) );

		$this->add_control(
			'button_hover_text_color',
			array(
				'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => 'globals/colors?id=clc_primary' ),
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rs-button-hover-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'button_hover_bg_color',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => 'globals/colors?id=clc_pale' ),
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rs-button-hover-bg: {{VALUE}};' ),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_responsive_control(
			'button_padding_x',
			array(
				'label'      => __( 'Horizontal Padding', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'separator'  => 'before',
				'selectors'  => array( '{{WRAPPER}}' => '--ryv-rs-button-padding-x: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'button_icon_size',
			array(
				'label'      => __( 'Icon Size', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 8, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}}' => '--ryv-rs-button-icon-size: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'button_icon_gap',
			array(
				'label'      => __( 'Icon Spacing', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'selectors'  => array( '{{WRAPPER}}' => '--ryv-rs-button-icon-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style: the "Clear filters" link.
	 */
	private function register_reset_style_controls() {
		$this->start_controls_section(
			'reset_style_section',
			array(
				'label' => __( 'Clear Link', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'reset_color',
			array(
				'label'     => __( 'Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rs-reset-color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'reset_typography',
				'global'   => array( 'default' => 'globals/typography?id=clc_small' ),
				'selector' => '{{WRAPPER}} .ryv-resources-search__reset',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Current page URL with the search/state params (and any Loop Grid page
	 * number) removed - the target of the "Clear filters" link. Sorting and
	 * the category tab are kept: they're view choices, not filters.
	 *
	 * @return string
	 */
	private function get_clear_url(): string {
		$url = remove_query_arg( array( RYV_Resources_Search::PARAM_SEARCH, RYV_Resources_Search::PARAM_STATE ) );

		return preg_replace( '/([?&])e-page-[^=&]+=[^&]*&?/', '$1', $url );
	}

	/**
	 * Render.
	 */
	protected function render() {
		$settings  = $this->get_settings_for_display();
		$filters   = RYV_Resources_Search::get_current_filters();
		$uid       = 'ryv-rs-' . $this->get_id();
		$anchor    = sanitize_html_class( (string) $settings['results_anchor'] );
		$has_state = 'yes' === $settings['show_state'];
		$ajax      = 'yes' === $settings['ajax'];

		// Keep every other query param (the selected category tab, the sort
		// order) across a new search - a GET form would otherwise replace the
		// whole query string with just its own fields. Loop Grid page numbers
		// are dropped: a new search starts at page 1.
		$hidden = '';
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public filter.
			if ( ! is_string( $value ) || in_array( $key, array( RYV_Resources_Search::PARAM_SEARCH, RYV_Resources_Search::PARAM_STATE ), true ) || 0 === strpos( $key, 'e-page-' ) ) {
				continue;
			}
			$hidden .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( $key ), esc_attr( wp_unslash( $value ) ) );
		}

		$form_attrs = array(
			'class'  => 'ryv-resources-search',
			'method' => 'get',
			'role'   => 'search',
			'id'     => $uid . '-form',
			'action' => $anchor ? '#' . $anchor : '',
		);
		if ( $has_state && 'yes' === $settings['auto_submit_state'] ) {
			$form_attrs['data-auto-submit'] = '';
		}
		if ( $ajax ) {
			$form_attrs['data-ajax'] = '';
		}
		if ( $ajax && 'yes' === $settings['live_search'] ) {
			$form_attrs['data-live'] = '';
		}
		if ( $anchor ) {
			$form_attrs['data-anchor'] = $anchor;
		}
		$this->add_render_attribute( 'form', $form_attrs );

		$icon_html = '';
		if ( ! empty( $settings['button_icon']['value'] ) ) {
			ob_start();
			Icons_Manager::render_icon( $settings['button_icon'], array( 'aria-hidden' => 'true' ) );
			$icon_html = '<span class="ryv-resources-search__button-icon">' . ob_get_clean() . '</span>';
		}
		$icon_before = 'before' === $settings['button_icon_position'];
		?>
		<div class="ryv-resources-search-widget">
			<form <?php $this->print_render_attribute_string( 'form' ); ?>>
				<?php echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr() above. ?>
				<div class="ryv-resources-search__field ryv-resources-search__field--search">
					<label class="ryv-resources-search__sr" for="<?php echo esc_attr( $uid ); ?>-q"><?php echo esc_html( $settings['search_placeholder'] ); ?></label>
					<img class="ryv-resources-search__icon" src="<?php echo esc_url( RYV_PLUGIN_URL . 'assets/img/resources/icon-search.svg' ); ?>" width="22" height="22" alt="" aria-hidden="true">
					<input class="ryv-resources-search__input" type="search" id="<?php echo esc_attr( $uid ); ?>-q"
						name="<?php echo esc_attr( RYV_Resources_Search::PARAM_SEARCH ); ?>"
						value="<?php echo esc_attr( $filters['search'] ); ?>"
						placeholder="<?php echo esc_attr( $settings['search_placeholder'] ); ?>">
				</div>
				<?php if ( $has_state ) : ?>
					<div class="ryv-resources-search__field ryv-resources-search__field--state">
						<label class="ryv-resources-search__sr" for="<?php echo esc_attr( $uid ); ?>-state"><?php echo esc_html( $settings['state_placeholder'] ); ?></label>
						<select class="ryv-resources-search__select" id="<?php echo esc_attr( $uid ); ?>-state" name="<?php echo esc_attr( RYV_Resources_Search::PARAM_STATE ); ?>"
							<?php
							if ( 'yes' === $settings['searchable_state'] ) {
								printf( 'data-searchable data-no-results="%s"', esc_attr( $settings['no_results_text'] ) );
							}
							?>
							>
							<option value=""><?php echo esc_html( $settings['state_placeholder'] ); ?></option>
							<?php foreach ( RYV_Resources_Search::get_states() as $slug => $name ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['state'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>
				<?php if ( '' !== trim( (string) $settings['button_text'] ) || $icon_html ) : ?>
					<button class="ryv-resources-search__button" type="submit">
						<?php
						echo $icon_before ? $icon_html : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons_Manager output.
						echo '<span class="ryv-resources-search__button-text">' . esc_html( $settings['button_text'] ) . '</span>';
						echo $icon_before ? '' : $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons_Manager output.
						?>
					</button>
				<?php endif; ?>
			</form>
			<?php
			// Always rendered (hidden while no filter is active) so the AJAX
			// mode can show/hide it without a reload.
			if ( '' !== trim( (string) $settings['reset_text'] ) ) {
				printf(
					'<a class="ryv-resources-search__reset" href="%s"%s>%s</a>',
					esc_url( $this->get_clear_url() . ( $anchor ? '#' . $anchor : '' ) ),
					( '' !== $filters['search'] || '' !== $filters['state'] ) ? '' : ' hidden',
					esc_html( $settings['reset_text'] )
				);
			}
			?>
		</div>
		<?php
	}
}
