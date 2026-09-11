<?php
/**
 * Elementor widget for the current State page's Featured Partners cards -
 * the Elementor-native counterpart to `[ryv_featured_partners]`
 * (RYV_States_Partners_Shortcode). Both share the exact same field
 * resolution/markup via RYV_Featured_Partners_Renderer; this widget's only
 * job on top of that is a proper Style tab (spacing, logo, typography,
 * colors, quote styling, link styling) instead of the shortcode's fixed
 * look - see that renderer class and assets/src/input.css's
 * `.ryv-partner-card...` rules for what every control below actually
 * drives.
 *
 * Data-driven from whichever `ryv_states` post is being viewed - has no
 * Content-tab controls of its own, same reasoning as RYV_Map_Widget's state
 * auto-detection: there's nothing for an admin to configure per-instance,
 * only where it's placed.
 *
 * Renders outside `.ryv-app-wrapper` (it lives on the State Post template,
 * not inside the questionnaire), so its CSS in input.css is plain,
 * unscoped rules, same as RYV_Stories_Widget/RYV_Map_Widget.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Featured_Partners_Widget
 */
class RYV_Featured_Partners_Widget extends \Elementor\Widget_Base {

	/**
	 * @return string
	 */
	public function get_name() {
		return 'ryv_featured_partners';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return __( 'RYV Featured Partners', 'ryv-eligibility-tool' );
	}

	/**
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-post-list';
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
		return array( 'partners', 'featured partner', 'logos', 'state' );
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
		$this->register_content_controls();
		$this->register_card_style_controls();
		$this->register_logo_style_controls();
		$this->register_name_style_controls();
		$this->register_description_style_controls();
		$this->register_quote_style_controls();
		$this->register_link_style_controls();
	}

	/**
	 * Content-tab context only - nothing to configure, the data comes from
	 * the state post itself.
	 */
	private function register_content_controls() {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Featured Partners', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'content_note',
			array(
				'type' => \Elementor\Controls_Manager::RAW_HTML,
				'raw'  => __( 'Lists the current State post\'s "Featured Partners" field (RYV Forms → the state\'s own edit screen) - one card per partner: logo, name, location, description, quote, and a link. Each field is optional except Partner Name; an empty field is simply left out of that card. Nothing to configure here - only where this widget is placed.', 'ryv-eligibility-tool' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Card-level spacing: gap between partner cards, divider, header gap.
	 */
	private function register_card_style_controls() {
		$this->start_controls_section(
			'card_style_section',
			array(
				'label' => __( 'Card Layout', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'card_gap',
			array(
				'label'      => __( 'Gap Between Partner Cards', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 32 ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-card-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'card_divider_color',
			array(
				'label'     => __( 'Divider Color (between cards)', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#E5E7EB',
				'selectors' => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-card-divider-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'header_gap',
			array(
				'label'      => __( 'Gap Between Logo & Heading', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 14 ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-header-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'header_margin_bottom',
			array(
				'label'      => __( 'Space Below Header', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 48 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 16 ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-header-margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Logo (and its no-logo placeholder) sizing/color.
	 */
	private function register_logo_style_controls() {
		$this->start_controls_section(
			'logo_style_section',
			array(
				'label' => __( 'Logo', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'logo_size',
			array(
				'label'      => __( 'Logo Box Size', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 24, 'max' => 128 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 56 ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-logo-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'logo_border_color',
			array(
				'label'     => __( 'Logo Box Border Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#CBD5E1',
				'selectors' => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-logo-border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'logo_border_radius',
			array(
				'label'      => __( 'Corner Rounding', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array( 'min' => 0, 'max' => 48 ),
					'%'  => array( 'min' => 0, 'max' => 50 ),
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-logo-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'placeholder_color',
			array(
				'label'     => __( 'Placeholder Icon Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#9CA3AF',
				'selectors' => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-logo-placeholder-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Partner name + location styling.
	 */
	private function register_name_style_controls() {
		$this->start_controls_section(
			'name_style_section',
			array(
				'label' => __( 'Name & Location', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'name_color',
			array(
				'label'     => __( 'Name Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#0F172A',
				'selectors' => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-name-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'name_typography',
				'selector' => '{{WRAPPER}} .ryv-partner-card-name',
			)
		);

		$this->add_control(
			'location_color',
			array(
				'label'     => __( 'Location Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#6B7280',
				'separator' => 'before',
				'selectors' => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-location-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'location_typography',
				'selector' => '{{WRAPPER}} .ryv-partner-card-location',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Description paragraph styling.
	 */
	private function register_description_style_controls() {
		$this->start_controls_section(
			'description_style_section',
			array(
				'label' => __( 'Description', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'description_color',
			array(
				'label'     => __( 'Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#1F2937',
				'selectors' => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-description-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'description_typography',
				'selector' => '{{WRAPPER}} .ryv-partner-card-description',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Quote block styling - the red-bordered "pull quote" in the reference
	 * design.
	 */
	private function register_quote_style_controls() {
		$this->start_controls_section(
			'quote_style_section',
			array(
				'label' => __( 'Quote', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'quote_border_color',
			array(
				'label'     => __( 'Left Border Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#C8102E',
				'selectors' => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-quote-border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'quote_border_width',
			array(
				'label'      => __( 'Left Border Width', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 12 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 3 ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-quote-border-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'quote_color',
			array(
				'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#1F2937',
				'selectors' => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-quote-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'quote_typography',
				'selector' => '{{WRAPPER}} .ryv-partner-card-quote p',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * "Visit {Name} →" link styling.
	 */
	private function register_link_style_controls() {
		$this->start_controls_section(
			'link_style_section',
			array(
				'label' => __( 'Visit Link', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'link_color',
			array(
				'label'     => __( 'Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#0F172A',
				'selectors' => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-link-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'link_hover_color',
			array(
				'label'     => __( 'Hover Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#C8102E',
				'selectors' => array(
					'{{WRAPPER}} .ryv-partner-cards' => '--ryv-partner-link-hover-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'link_typography',
				'selector' => '{{WRAPPER}} .ryv-partner-card-link',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render: same card HTML the shortcode produces, from the same
	 * renderer - see RYV_Featured_Partners_Renderer.
	 */
	protected function render() {
		if ( ! class_exists( 'RYV_Featured_Partners_Renderer' ) ) {
			return;
		}

		$html = RYV_Featured_Partners_Renderer::render_html( RYV_Featured_Partners_Renderer::get_state_id() );

		if ( '' === $html ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				printf(
					'<div class="ryv-stories-widget-placeholder">%s</div>',
					esc_html__( 'No Featured Partners set for this state (or this isn\'t a State page).', 'ryv-eligibility-tool' )
				);
			}
			return;
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- RYV_Featured_Partners_Renderer::render_html() already escapes everything it builds.
	}
}
