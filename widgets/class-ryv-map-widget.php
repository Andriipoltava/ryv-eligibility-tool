<?php
/**
 * Elementor widget: embeds a MapSVG interactive state map (via the MapSVG
 * plugin's own shortcode renderer) with an optional heading/description and
 * a styled "Or pick from a list" state dropdown fallback, plus a full set
 * of layout/appearance Style-tab controls - unlike MapSVG's own bundled
 * Elementor widget (`\MapSVG\Elementor\ElementorMapWidget`), which only
 * exposes map selection, a default selected region, and lazy-load.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Map_Widget
 */
class RYV_Map_Widget extends \Elementor\Widget_Base {

	/**
	 * @return string
	 */
	public function get_name() {
		return 'ryv_state_map';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return __( 'RYV State Map', 'ryv-eligibility-tool' );
	}

	/**
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-google-maps';
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
		return array( 'map', 'mapsvg', 'states', 'voting rights' );
	}

	/**
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( RYV_Assets::STYLE_HANDLE );
	}

	/**
	 * Register Content + Style tab controls.
	 */
	protected function register_controls() {
		$this->register_heading_controls();
		$this->register_map_controls();
		$this->register_legend_controls();
		$this->register_picker_controls();

		$this->register_heading_style_controls();
		$this->register_map_style_controls();
		$this->register_legend_style_controls();
		$this->register_picker_style_controls();
	}

	/**
	 * Content: heading + description shown above the map (e.g. "State Laws
	 * Map" / "Hover or click a state to see a quick overview.").
	 */
	private function register_heading_controls() {
		$this->start_controls_section(
			'heading_section',
			array(
				'label' => __( 'Heading', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_heading',
			array(
				'label'        => __( 'Show Heading', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'Hide', 'ryv-eligibility-tool' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'heading_text',
			array(
				'label'       => __( 'Heading Text', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'State Laws Map', 'ryv-eligibility-tool' ),
				'label_block' => true,
				'condition'   => array( 'show_heading' => 'yes' ),
			)
		);

		$this->add_control(
			'show_description',
			array(
				'label'        => __( 'Show Description', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'Hide', 'ryv-eligibility-tool' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'description_text',
			array(
				'label'     => __( 'Description Text', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::TEXTAREA,
				'default'   => __( 'Hover or click a state to see a quick overview.', 'ryv-eligibility-tool' ),
				'condition' => array( 'show_description' => 'yes' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content: which MapSVG map to embed, and MapSVG's own passthrough
	 * shortcode attributes (default selected region, lazy load).
	 */
	private function register_map_controls() {
		$this->start_controls_section(
			'map_section',
			array(
				'label' => __( 'Map', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		if ( ! $this->is_mapsvg_active() ) {
			$this->add_control(
				'mapsvg_missing_notice',
				array(
					'type' => \Elementor\Controls_Manager::RAW_HTML,
					'raw'  => __( 'The MapSVG plugin is not active. Activate it to select and configure a map here.', 'ryv-eligibility-tool' ),
					'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
				)
			);
		}

		$this->add_control(
			'map_id',
			array(
				'label'   => __( 'Map', 'ryv-eligibility-tool' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => $this->get_map_options(),
				'default' => (string) $this->get_default_map_id(),
			)
		);

		$this->add_control(
			'selected_region',
			array(
				'label'       => __( 'Default Selected Region ID', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'description' => __( 'Optional. The 2-letter region id (e.g. "AL") to highlight as selected on load.', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'lazy_load',
			array(
				'label'        => __( 'Lazy Load', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'No', 'ryv-eligibility-tool' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'Load the map only once it scrolls into view (front end only).', 'ryv-eligibility-tool' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content: the category legend box, built from RYV_States_ACF's Map
	 * Categories (Options page) - a color swatch + label per category, in
	 * `order`. This is RYV's own addition (MapSVG's built-in legend only
	 * exists for its unrelated "choropleth" gradient mode, not this
	 * categorical status coloring), matching campaignlegal.org's own map
	 * legend design.
	 */
	private function register_legend_controls() {
		$this->start_controls_section(
			'legend_section',
			array(
				'label' => __( 'Legend', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_legend',
			array(
				'label'        => __( 'Show Legend', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'Hide', 'ryv-eligibility-tool' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Lists every category defined under RYV States → Map Settings, with its color swatch.', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'legend_title',
			array(
				'label'     => __( 'Legend Title', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Legend', 'ryv-eligibility-tool' ),
				'condition' => array( 'show_legend' => 'yes' ),
			)
		);

		$this->add_control(
			'legend_layout',
			array(
				'label'     => __( 'Legend Position', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => array(
					'below'  => __( 'Below the map', 'ryv-eligibility-tool' ),
					'beside' => __( 'Beside the map (side column)', 'ryv-eligibility-tool' ),
				),
				'default'   => 'beside',
				'condition' => array( 'show_legend' => 'yes' ),
			)
		);

		$this->add_control(
			'legend_collapsible',
			array(
				'label'        => __( 'Let Visitors Collapse Legend', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'No', 'ryv-eligibility-tool' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Adds a show/hide toggle to the legend title so visitors can collapse the category list.', 'ryv-eligibility-tool' ),
				'condition'    => array( 'show_legend' => 'yes' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content: the "Or pick from a list" state dropdown fallback, built
	 * from published ryv_states posts (title + permalink) - this is RYV's
	 * own addition, not part of MapSVG's shortcode output.
	 */
	private function register_picker_controls() {
		$this->start_controls_section(
			'picker_section',
			array(
				'label' => __( 'State Picker', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_picker',
			array(
				'label'        => __( 'Show "Or pick from a list"', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'Hide', 'ryv-eligibility-tool' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Lists every published State post as a dropdown, linking to that state\'s page - an accessible fallback to clicking the map.', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'picker_label',
			array(
				'label'     => __( 'Picker Label', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Or pick from a list', 'ryv-eligibility-tool' ),
				'condition' => array( 'show_picker' => 'yes' ),
			)
		);

		$this->add_control(
			'picker_placeholder',
			array(
				'label'     => __( 'Picker Placeholder', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Choose a state', 'ryv-eligibility-tool' ),
				'condition' => array( 'show_picker' => 'yes' ),
			)
		);

		$this->add_control(
			'picker_layout',
			array(
				'label'     => __( 'Picker Position', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => array(
					'below'  => __( 'Below the map', 'ryv-eligibility-tool' ),
					'beside' => __( 'Beside the map (side column)', 'ryv-eligibility-tool' ),
				),
				'default'   => 'beside',
				'condition' => array( 'show_picker' => 'yes' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style: heading + description typography/color.
	 */
	private function register_heading_style_controls() {
		$this->start_controls_section(
			'heading_style_section',
			array(
				'label'     => __( 'Heading', 'ryv-eligibility-tool' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_heading' => 'yes' ),
			)
		);

		$this->add_control(
			'heading_color',
			array(
				'label'     => __( 'Heading Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-map-widget-heading' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'heading_typography',
				'selector' => '{{WRAPPER}} .ryv-map-widget-heading',
			)
		);

		$this->add_control(
			'description_color',
			array(
				'label'     => __( 'Description Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => array(
					'{{WRAPPER}} .ryv-map-widget-description' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'description_typography',
				'selector' => '{{WRAPPER}} .ryv-map-widget-description',
			)
		);

		$this->add_responsive_control(
			'heading_spacing',
			array(
				'label'      => __( 'Spacing Below', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 80,
					),
				),
				'separator'  => 'before',
				'selectors'  => array(
					'{{WRAPPER}} .ryv-map-widget-intro' => '--ryv-map-intro-spacing: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style: the map container itself (sizing/border/shadow) plus the
	 * layout gap between it and the state picker column.
	 */
	private function register_map_style_controls() {
		$this->start_controls_section(
			'map_style_section',
			array(
				'label' => __( 'Map', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'map_max_width',
			array(
				'label'       => __( 'Max Width', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px', '%' ),
				'range'       => array(
					'px' => array(
						'min' => 200,
						'max' => 1600,
					),
					'%'  => array(
						'min' => 10,
						'max' => 100,
					),
				),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-map-widget-map' => 'max-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'body_gap',
			array(
				'label'      => __( 'Gap Between Map & Picker', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 80,
					),
				),
				'description' => __( 'Only applies when the picker is set to "Beside the map".', 'ryv-eligibility-tool' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-map-widget-body' => '--ryv-map-body-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'map_border_heading',
			array(
				'label'     => __( 'Border', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'map_border_width',
			array(
				'label'      => __( 'Border Width', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 10,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-map-widget-map' => 'border-width: {{SIZE}}{{UNIT}}; border-style: solid;',
				),
			)
		);

		$this->add_control(
			'map_border_color',
			array(
				'label'     => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#e5e7eb',
				'selectors' => array(
					'{{WRAPPER}} .ryv-map-widget-map' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'map_border_radius',
			array(
				'label'      => __( 'Border Radius', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-map-widget-map' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'map_box_shadow',
				'selector' => '{{WRAPPER}} .ryv-map-widget-map',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style: the category legend box - container, title, and each row's
	 * swatch/label.
	 */
	private function register_legend_style_controls() {
		$this->start_controls_section(
			'legend_style_section',
			array(
				'label'     => __( 'Legend', 'ryv-eligibility-tool' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_legend' => 'yes' ),
			)
		);

		$this->add_responsive_control(
			'legend_width',
			array(
				'label'      => __( 'Width', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 160,
						'max' => 600,
					),
					'%'  => array(
						'min' => 10,
						'max' => 100,
					),
				),
				'description' => __( 'Only applies when the legend is set to "Beside the map".', 'ryv-eligibility-tool' ),
				'selectors'  => array(
					// input.css reads --ryv-map-legend-width on .ryv-map-widget-side
					// (the legend/picker's shared parent, not the legend itself) -
					// a custom property only cascades to descendants, so writing it
					// onto .ryv-map-widget-legend (a child of .ryv-map-widget-side,
					// not an ancestor of it) left this control silently doing
					// nothing. Same fix as picker_width below.
					'{{WRAPPER}} .ryv-map-widget-side' => '--ryv-map-legend-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'legend_bg_color',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .ryv-map-widget-legend' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'legend_border_color',
			array(
				'label'     => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#e5e7eb',
				'selectors' => array(
					'{{WRAPPER}} .ryv-map-widget-legend' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'legend_padding',
			array(
				'label'      => __( 'Padding', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'default'    => array(
					'top'    => '20',
					'right'  => '20',
					'bottom' => '20',
					'left'   => '20',
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-map-widget-legend' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'legend_title_heading',
			array(
				'label'     => __( 'Title', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'legend_title_color',
			array(
				'label'     => __( 'Title Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-map-widget-legend-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'legend_title_typography',
				'selector' => '{{WRAPPER}} .ryv-map-widget-legend-title',
			)
		);

		$this->add_control(
			'legend_items_heading',
			array(
				'label'     => __( 'Items', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'legend_item_color',
			array(
				'label'     => __( 'Label Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-map-widget-legend-item' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'legend_item_typography',
				'selector' => '{{WRAPPER}} .ryv-map-widget-legend-item',
			)
		);

		$this->add_control(
			'legend_swatch_size',
			array(
				'label'      => __( 'Swatch Size', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 8,
						'max' => 40,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-map-widget-legend' => '--ryv-map-legend-swatch-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style: the "Or pick from a list" box - container, label, and select.
	 */
	private function register_picker_style_controls() {
		$this->start_controls_section(
			'picker_style_section',
			array(
				'label'     => __( 'State Picker', 'ryv-eligibility-tool' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_picker' => 'yes' ),
			)
		);

		$this->add_responsive_control(
			'picker_width',
			array(
				'label'      => __( 'Width', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 160,
						'max' => 600,
					),
					'%'  => array(
						'min' => 10,
						'max' => 100,
					),
				),
				'description' => __( 'Only applies when the picker is set to "Beside the map".', 'ryv-eligibility-tool' ),
				'selectors'  => array(
					// See legend_width's comment above - same wrong-target bug,
					// same fix: input.css reads --ryv-map-picker-width on
					// .ryv-map-widget-side, the picker's parent, not the picker
					// element itself.
					'{{WRAPPER}} .ryv-map-widget-side' => '--ryv-map-picker-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'picker_bg_color',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .ryv-map-widget-picker' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'picker_border_color',
			array(
				'label'     => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#e5e7eb',
				'selectors' => array(
					'{{WRAPPER}} .ryv-map-widget-picker' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'picker_padding',
			array(
				'label'      => __( 'Padding', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'default'    => array(
					'top'    => '16',
					'right'  => '16',
					'bottom' => '16',
					'left'   => '16',
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-map-widget-picker' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'picker_border_radius',
			array(
				'label'      => __( 'Border Radius', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-map-widget-picker' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'picker_label_heading',
			array(
				'label'     => __( 'Label', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'picker_label_color',
			array(
				'label'     => __( 'Label Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-map-widget-picker-label' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'picker_label_typography',
				'selector' => '{{WRAPPER}} .ryv-map-widget-picker-label',
			)
		);

		$this->add_control(
			'picker_select_heading',
			array(
				'label'     => __( 'Dropdown', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'picker_select_text_color',
			array(
				'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-map-widget-picker-select' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'picker_select_border_color',
			array(
				'label'     => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#d1d5db',
				'selectors' => array(
					'{{WRAPPER}} .ryv-map-widget-picker-select' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'picker_select_bg_color',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .ryv-map-widget-picker-select' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'picker_select_typography',
				'selector' => '{{WRAPPER}} .ryv-map-widget-picker-select',
			)
		);

		$this->add_responsive_control(
			'picker_select_border_width',
			array(
				'label'      => __( 'Border Width', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 6,
					),
				),
				'selectors'  => array(
					// input.css reads this var directly on .ryv-map-widget-picker-select
					// itself (unlike legend_width/picker_width above), so no
					// wrong-target risk here.
					'{{WRAPPER}} .ryv-map-widget-picker-select' => '--ryv-map-picker-select-border-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'picker_select_border_radius',
			array(
				'label'      => __( 'Border Radius', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-map-widget-picker-select' => '--ryv-map-picker-select-border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'picker_select_arrow_color',
			array(
				'label'     => __( 'Arrow Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#6b7280',
				'selectors' => array(
					// The wrap, not the select itself, is what the CSS's
					// ::after arrow is actually attached to.
					'{{WRAPPER}} .ryv-map-widget-picker-select-wrap' => '--ryv-map-picker-select-arrow-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'picker_select_focus_color',
			array(
				'label'     => __( 'Focus Border Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-map-widget-picker-select' => '--ryv-map-picker-select-focus-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Frontend (and Elementor editor preview) render.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$show_legend   = 'yes' === ( $settings['show_legend'] ?? '' );
		$legend_layout = $settings['legend_layout'] ?? 'beside';
		$show_picker   = 'yes' === ( $settings['show_picker'] ?? '' );
		$picker_layout = $settings['picker_layout'] ?? 'beside';

		// Legend and picker are each independently "beside" (a shared
		// right-hand column next to the map, matching
		// campaignlegal.org/restoreyourvote's own map layout - legend on
		// top, state picker below it) or "below" (full-width, under the
		// map) - not tied to each other.
		$has_side = ( $show_legend && 'beside' === $legend_layout ) || ( $show_picker && 'beside' === $picker_layout );

		printf(
			'<div class="ryv-map-widget%s">',
			$has_side ? ' ryv-map-widget--has-side' : ''
		);

		$this->render_intro( $settings );

		echo '<div class="ryv-map-widget-body">';
		$this->render_map( $settings );

		if ( $has_side ) {
			echo '<div class="ryv-map-widget-side">';
			if ( $show_legend && 'beside' === $legend_layout ) {
				$this->render_legend( $settings );
			}
			if ( $show_picker && 'beside' === $picker_layout ) {
				$this->render_picker( $settings );
			}
			echo '</div>';
		}
		echo '</div>'; // .ryv-map-widget-body - closed here so "below" items
		// below land as full-width siblings of it, not squeezed into its row-flex layout.

		if ( $show_legend && 'below' === $legend_layout ) {
			$this->render_legend( $settings );
		}
		if ( $show_picker && 'below' === $picker_layout ) {
			$this->render_picker( $settings );
		}

		echo '</div>';
	}

	/**
	 * @param array $settings Widget settings.
	 */
	private function render_intro( array $settings ) {
		$show_heading     = 'yes' === ( $settings['show_heading'] ?? '' );
		$show_description = 'yes' === ( $settings['show_description'] ?? '' );

		if ( ! $show_heading && ! $show_description ) {
			return;
		}

		echo '<div class="ryv-map-widget-intro">';

		if ( $show_heading && '' !== trim( (string) ( $settings['heading_text'] ?? '' ) ) ) {
			printf( '<h2 class="ryv-map-widget-heading">%s</h2>', esc_html( $settings['heading_text'] ) );
		}

		if ( $show_description && '' !== trim( (string) ( $settings['description_text'] ?? '' ) ) ) {
			printf( '<p class="ryv-map-widget-description">%s</p>', esc_html( $settings['description_text'] ) );
		}

		echo '</div>';
	}

	/**
	 * @param array $settings Widget settings.
	 */
	private function render_map( array $settings ) {
		echo '<div class="ryv-map-widget-map">';

		$map_id = absint( $settings['map_id'] ?? 0 );

		if ( ! $this->is_mapsvg_active() ) {
			if ( $this->is_edit_mode() ) {
				echo '<div class="ryv-map-widget-placeholder">';
				esc_html_e( 'The MapSVG plugin is not active.', 'ryv-eligibility-tool' );
				echo '</div>';
			}
			echo '</div>';
			return;
		}

		if ( ! $map_id ) {
			if ( $this->is_edit_mode() ) {
				echo '<div class="ryv-map-widget-placeholder">';
				esc_html_e( 'Choose a map in the widget settings.', 'ryv-eligibility-tool' );
				echo '</div>';
			}
			echo '</div>';
			return;
		}

		$atts = array( 'id' => (string) $map_id );

		if ( ! empty( $settings['selected_region'] ) ) {
			$atts['selected'] = sanitize_text_field( $settings['selected_region'] );
		}

		if ( 'yes' === ( $settings['lazy_load'] ?? '' ) ) {
			$atts['lazy'] = 'true';
		}

		$front = new \MapSVG\Front();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MapSVG's own shortcode renderer returns safe, self-escaped HTML.
		echo $front->renderShortcode( $atts );

		echo '</div>';

		$this->enqueue_frontend_enhancements();
	}

	/**
	 * State abbreviation labels, legend-hover highlighting, and the click
	 * popup are all layered on top of MapSVG's own rendering by
	 * assets/js/map-widget.js, since MapSVG's frontend has no equivalent
	 * features for this categorical (non-choropleth) coloring mode.
	 */
	private function enqueue_frontend_enhancements() {
		wp_enqueue_script(
			'ryv-map-widget',
			RYV_PLUGIN_URL . 'assets/js/map-widget.js',
			array(),
			RYV_VERSION,
			true
		);

		wp_localize_script( 'ryv-map-widget', 'ryvMapWidgetData', $this->build_states_data() );
		wp_localize_script(
			'ryv-map-widget',
			'ryvMapWidgetL10n',
			array(
				'close'     => __( 'Close', 'ryv-eligibility-tool' ),
				'learnMore' => __( 'Learn more →', 'ryv-eligibility-tool' ),
			)
		);
	}

	/**
	 * Per-region data (title/tooltip/link) for the click popup, keyed by
	 * MapSVG region ID (e.g. "TN") so assets/js/map-widget.js can look
	 * it up directly off the clicked <path>'s own id - avoids a second
	 * REST round trip for data the page already needs to render.
	 *
	 * `suppress_filters` must be forced false here: `get_posts()`'s own
	 * default is `true` (unlike a bare `new WP_Query`), which disables the
	 * `posts_join`/`posts_where` filters WPML uses to scope a query to the
	 * current language - with the default, this pulls every language
	 * edition of `ryv_states` (confirmed live: 104 published rows, exactly
	 * 2 per state/territory, `ryv_states` being WPML-translatable per
	 * `wpml-config.xml`). Harmless here since results are keyed by
	 * `$svg_id` (one key per state, language-independent) so a second
	 * language's row just overwrites the first with whichever language
	 * happened to sort last - but non-deterministic, so still worth fixing
	 * alongside render_picker()'s identical bug, where the effect is
	 * visibly duplicated entries rather than a silent, order-dependent
	 * overwrite.
	 *
	 * @return array<string,array{title:string,description:string,link:string}>
	 */
	private function build_states_data(): array {
		if ( ! class_exists( 'RYV_States_CPT' ) || ! class_exists( 'RYV_US_States' ) || ! function_exists( 'get_field' ) ) {
			return array();
		}

		$states = get_posts(
			array(
				'post_type'        => RYV_States_CPT::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'suppress_filters' => false,
			)
		);

		$data = array();

		foreach ( $states as $state ) {
			$slug = get_field( 'ryv_state_slug', $state->ID );
			if ( ! $slug ) {
				continue;
			}

			$svg_id = RYV_US_States::svg_id( $slug );
			if ( ! $svg_id ) {
				continue;
			}

			$data[ $svg_id ] = array(
				'title'       => $state->post_title,
				'description' => get_field( 'ryv_map_tooltip_text', $state->ID ) ?: '',
				'link'        => get_permalink( $state ),
			);
		}

		return $data;
	}

	/**
	 * @param array $settings Widget settings.
	 */
	private function render_legend( array $settings ) {
		if ( ! class_exists( 'RYV_States_ACF' ) ) {
			return;
		}

		$categories = RYV_States_ACF::get_map_categories();
		if ( empty( $categories ) ) {
			return;
		}

		$title       = trim( (string) ( $settings['legend_title'] ?? '' ) );
		$collapsible = 'yes' === ( $settings['legend_collapsible'] ?? '' );
		$list_id     = 'ryv-map-widget-legend-list-' . $this->get_id();

		// Normally enqueued from render_map() once MapSVG's own shortcode
		// renders, but the toggle itself doesn't depend on the map (see
		// map-widget.js's wireLegendToggle(), wired outside the MapSVG
		// polling loop) - enqueue it here too so the toggle still works if
		// the map itself fails to render (MapSVG inactive, no map chosen).
		if ( $collapsible ) {
			$this->enqueue_frontend_enhancements();
		}

		echo '<div class="ryv-map-widget-legend">';

		if ( '' !== $title ) {
			if ( $collapsible ) {
				printf(
					'<button type="button" class="ryv-map-widget-legend-title ryv-map-widget-legend-toggle" aria-expanded="true" aria-controls="%1$s">%2$s<span class="ryv-map-widget-legend-toggle-icon" aria-hidden="true"></span></button>',
					esc_attr( $list_id ),
					esc_html( $title )
				);
			} else {
				printf( '<div class="ryv-map-widget-legend-title">%s</div>', esc_html( $title ) );
			}
		}

		printf( '<ul class="ryv-map-widget-legend-list" id="%s">', esc_attr( $list_id ) );

		foreach ( $categories as $cat ) {
			$label = trim( (string) ( $cat['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$color = $cat['color'] ?? '';

			printf(
				'<li class="ryv-map-widget-legend-item"><span class="ryv-map-widget-legend-swatch" style="background-color:%s"></span>%s</li>',
				esc_attr( $color ?: 'transparent' ),
				esc_html( $label )
			);
		}

		echo '</ul>';
		echo '</div>';
	}

	/**
	 * @param array $settings Widget settings.
	 */
	private function render_picker( array $settings ) {
		// suppress_filters must be forced false - see build_states_data()'s
		// docblock. Without it, every state appears twice here (once per
		// WPML language edition, e.g. "Alabama" / "Alabama" - confirmed
		// live: 104 published ryv_states rows, exactly 2 per state).
		$states = get_posts(
			array(
				'post_type'        => RYV_States_CPT::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		if ( empty( $states ) ) {
			return;
		}

		$label       = trim( (string) ( $settings['picker_label'] ?? '' ) );
		$placeholder = trim( (string) ( $settings['picker_placeholder'] ?? '' ) );

		echo '<div class="ryv-map-widget-picker">';

		if ( '' !== $label ) {
			printf( '<div class="ryv-map-widget-picker-label">%s</div>', esc_html( $label ) );
		}

		// Wrapping element for the custom arrow (::after, see input.css) -
		// a native <select>'s own arrow can't be recolored directly, so it's
		// hidden (appearance: none) and this wrapper positions a replacement,
		// same pattern as the main questionnaire's .ryv-select2 dropdown.
		echo '<div class="ryv-map-widget-picker-select-wrap">';
		echo '<select class="ryv-map-widget-picker-select" onchange="if(this.value){window.location.href=this.value;}">';
		printf( '<option value="">%s</option>', esc_html( $placeholder ) );

		foreach ( $states as $state ) {
			printf(
				'<option value="%s">%s</option>',
				esc_url( get_permalink( $state ) ),
				esc_html( $state->post_title )
			);
		}

		echo '</select>';
		echo '</div>'; // .ryv-map-widget-picker-select-wrap
		echo '</div>'; // .ryv-map-widget-picker
	}

	/**
	 * @return bool Whether the MapSVG plugin's Front renderer is available.
	 */
	private function is_mapsvg_active(): bool {
		return class_exists( '\MapSVG\Front' );
	}

	/**
	 * @return array<string,string> map_id => title, for the SELECT control.
	 */
	private function get_map_options(): array {
		$options = array(
			'0' => __( 'Select a map…', 'ryv-eligibility-tool' ),
		);

		if ( ! $this->is_mapsvg_active() || ! class_exists( '\MapSVG\RepositoryFactory' ) ) {
			return $options;
		}

		try {
			$maps_repo = \MapSVG\RepositoryFactory::get( 'map' );
			$result    = $maps_repo->find( new \MapSVG\Query( array( 'perpage' => 0 ) ) );
			$items     = $result['items'] ?? array();

			foreach ( $items as $map ) {
				$id             = (string) $map->id;
				$options[ $id ] = $map->title ? (string) $map->title : sprintf( 'Map #%s', $id );
			}
		} catch ( \Throwable $e ) {
			// Keep the placeholder-only list if maps can't be loaded.
		}

		return $options;
	}

	/**
	 * The map ID configured for the RYV state-data sync (RYV_MapSVG_Sync),
	 * so a fresh widget instance defaults to the map the site actually
	 * keeps state colors/tooltips synced to, rather than an arbitrary map.
	 *
	 * @return int
	 */
	private function get_default_map_id(): int {
		if ( ! function_exists( 'get_field' ) ) {
			return 0;
		}

		$map_id = get_field( 'ryv_mapsvg_map_id', 'option' );
		return $map_id ? (int) $map_id : 0;
	}

	/**
	 * @return bool
	 */
	private function is_edit_mode(): bool {
		return class_exists( '\Elementor\Plugin' )
			&& isset( \Elementor\Plugin::$instance->editor )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}
}
