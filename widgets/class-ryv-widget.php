<?php
/**
 * Elementor widget: renders the mount point for the frontend decision
 * engine and exposes style controls that feed its CSS custom properties.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Widget
 */
class RYV_Widget extends \Elementor\Widget_Base {

	/**
	 * @return string
	 */
	public function get_name() {
		return 'ryv_eligibility_tool';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return __( 'RYV Eligibility Tool', 'ryv-eligibility-tool' );
	}

	/**
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
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
		return array( 'eligibility', 'voting', 'questionnaire', 'form', 'decision tree' );
	}

	/**
	 * @return string[]
	 */
	public function get_script_depends() {
		return array( RYV_Assets::SCRIPT_HANDLE );
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
		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Content', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'form_id',
			array(
				'label'       => __( 'Eligibility Form', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'label_block' => true,
				'options'     => $this->get_form_options(),
				'default'     => '',
			)
		);

		$this->end_controls_section();

		$this->register_color_controls();
		$this->register_typography_controls();
		$this->register_button_style_controls();
		$this->register_continue_button_style_controls();
		$this->register_dropdown_style_controls();
		$this->register_input_style_controls();
		$this->register_radio_style_controls();
	}

	/**
	 * Build the form-picker's option list from published/draft `ryv_forms` posts.
	 *
	 * @return array<string,string>
	 */
	private function get_form_options() {
		$options = array( '' => __( '- Select a form -', 'ryv-eligibility-tool' ) );

		$forms = get_posts(
			array(
				'post_type'      => RYV_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $forms as $form ) {
			$label = $form->post_title;
			if ( 'draft' === $form->post_status ) {
				$label .= ' (' . __( 'Draft', 'ryv-eligibility-tool' ) . ')';
			}
			$options[ $form->ID ] = $label;
		}

		return $options;
	}

	/**
	 * Colors -> CSS custom properties on .ryv-app-wrapper, consumed by
	 * assets/src/input.css (e.g. `var(--ryv-primary-color, #1d4ed8)`).
	 */
	private function register_color_controls() {
		$this->start_controls_section(
			'colors_section',
			array(
				'label' => __( 'Colors', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'primary_color',
			array(
				'label'     => __( 'Primary Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#1d4ed8',
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-primary-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'secondary_color',
			array(
				'label'     => __( 'Secondary Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#64748b',
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-secondary-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'text_color',
			array(
				'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#1f2937',
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-text-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Typography for questions, buttons, and result/markup content.
	 */
	private function register_typography_controls() {
		$this->start_controls_section(
			'typography_section',
			array(
				'label' => __( 'Typography', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'question_typography',
				'label'    => __( 'Questions', 'ryv-eligibility-tool' ),
				'selector' => '{{WRAPPER}} .ryv-question-title',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'results_typography',
				'label'    => __( 'Results', 'ryv-eligibility-tool' ),
				'selector' => '{{WRAPPER}} .ryv-content',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Choice buttons (.ryv-option-btn) - the radio/checkbox/state-picker
	 * 'buttons' display style. Every non-typography property here is bridged
	 * through a CSS custom property on .ryv-app-wrapper (see
	 * assets/src/input.css) rather than targeting `.ryv-option-btn` directly,
	 * so it can't lose a specificity fight with anything else on the page -
	 * same technique the existing color controls already use.
	 */
	private function register_button_style_controls() {
		$this->start_controls_section(
			'button_style_section',
			array(
				'label' => __( 'Choice Buttons', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'label'    => __( 'Typography', 'ryv-eligibility-tool' ),
				'selector' => '{{WRAPPER}} .ryv-option-btn',
			)
		);

		$this->add_responsive_control(
			'button_padding',
			array(
				'label'      => __( 'Padding', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'button_border_radius',
			array(
				'label'      => __( 'Border Radius', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'button_border_width',
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
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-border-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->start_controls_tabs( 'tabs_button_style' );

		$this->start_controls_tab(
			'tab_button_normal',
			array( 'label' => __( 'Normal', 'ryv-eligibility-tool' ) )
		);

		$this->add_control(
			'button_text_color',
			array(
				'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-text-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_bg_color',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-bg: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_border_color',
			array(
				'label'     => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#d1d5db',
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-border-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_button_hover',
			array( 'label' => __( 'Hover', 'ryv-eligibility-tool' ) )
		);

		$this->add_control(
			'button_bg_color_hover',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-bg-hover: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_border_color_hover',
			array(
				'label'     => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-border-color-hover: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_button_selected',
			array( 'label' => __( 'Selected', 'ryv-eligibility-tool' ) )
		);

		$this->add_control(
			'button_bg_color_selected',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-bg-selected: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_border_color_selected',
			array(
				'label'     => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-border-color-selected: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/**
	 * The "Start Over" / continue-style call-to-action button (.ryv-continue-btn).
	 */
	private function register_continue_button_style_controls() {
		$this->start_controls_section(
			'continue_button_style_section',
			array(
				'label' => __( 'Continue Button', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'continue_button_typography',
				'label'    => __( 'Typography', 'ryv-eligibility-tool' ),
				'selector' => '{{WRAPPER}} .ryv-continue-btn',
			)
		);

		$this->add_responsive_control(
			'continue_button_padding',
			array(
				'label'      => __( 'Padding', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-continue-btn-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'continue_button_border_radius',
			array(
				'label'      => __( 'Border Radius', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-continue-btn-border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'continue_button_border_width',
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
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-continue-btn-border-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'continue_button_border_color',
			array(
				'label'     => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-continue-btn-border-color: {{VALUE}};',
				),
			)
		);

		$this->start_controls_tabs( 'tabs_continue_button_style' );

		$this->start_controls_tab(
			'tab_continue_button_normal',
			array( 'label' => __( 'Normal', 'ryv-eligibility-tool' ) )
		);

		$this->add_control(
			'continue_button_text_color',
			array(
				'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-continue-btn-text-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'continue_button_bg_color',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-continue-btn-bg: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_continue_button_hover',
			array( 'label' => __( 'Hover', 'ryv-eligibility-tool' ) )
		);

		$this->add_control(
			'continue_button_bg_color_hover',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-continue-btn-bg-hover: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/**
	 * Native <select> used by display_style: 'dropdown' (.ryv-select).
	 */
	private function register_dropdown_style_controls() {
		$this->start_controls_section(
			'dropdown_style_section',
			array(
				'label' => __( 'Dropdown', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'dropdown_typography',
				'label'    => __( 'Typography', 'ryv-eligibility-tool' ),
				'selector' => '{{WRAPPER}} .ryv-select',
			)
		);

		$this->add_responsive_control(
			'dropdown_padding',
			array(
				'label'      => __( 'Padding', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-select-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'dropdown_border_radius',
			array(
				'label'      => __( 'Border Radius', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-select-border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'dropdown_border_width',
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
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-select-border-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->start_controls_tabs( 'tabs_dropdown_style' );

		$this->start_controls_tab(
			'tab_dropdown_normal',
			array( 'label' => __( 'Normal', 'ryv-eligibility-tool' ) )
		);

		$this->add_control(
			'dropdown_text_color',
			array(
				'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-select-text-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'dropdown_bg_color',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-select-bg: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'dropdown_border_color',
			array(
				'label'     => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#d1d5db',
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-select-border-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_dropdown_hover',
			array( 'label' => __( 'Hover', 'ryv-eligibility-tool' ) )
		);

		$this->add_control(
			'dropdown_border_color_hover',
			array(
				'label'     => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-select-border-color-hover: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/**
	 * Text/textarea question inputs (.ryv-input).
	 */
	private function register_input_style_controls() {
		$this->start_controls_section(
			'input_style_section',
			array(
				'label' => __( 'Text Input', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'input_typography',
				'label'    => __( 'Typography', 'ryv-eligibility-tool' ),
				'selector' => '{{WRAPPER}} .ryv-input',
			)
		);

		$this->add_responsive_control(
			'input_padding',
			array(
				'label'      => __( 'Padding', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-input-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'input_border_radius',
			array(
				'label'      => __( 'Border Radius', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-input-border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'input_border_width',
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
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-input-border-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->start_controls_tabs( 'tabs_input_style' );

		$this->start_controls_tab(
			'tab_input_normal',
			array( 'label' => __( 'Normal', 'ryv-eligibility-tool' ) )
		);

		$this->add_control(
			'input_text_color',
			array(
				'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-input-text-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'input_bg_color',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-input-bg: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'input_border_color',
			array(
				'label'     => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#d1d5db',
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-input-border-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_input_hover',
			array( 'label' => __( 'Hover', 'ryv-eligibility-tool' ) )
		);

		$this->add_control(
			'input_border_color_hover',
			array(
				'label'     => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-input-border-color-hover: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/**
	 * display_style: 'radio' options (.ryv-radio-option label + .ryv-radio-input).
	 * No Border/Background here - a native radio input's shape/fill is styled
	 * via `accent-color`, not box properties.
	 */
	private function register_radio_style_controls() {
		$this->start_controls_section(
			'radio_style_section',
			array(
				'label' => __( 'Radio Buttons', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'radio_typography',
				'label'    => __( 'Typography', 'ryv-eligibility-tool' ),
				'selector' => '{{WRAPPER}} .ryv-radio-option',
			)
		);

		$this->add_control(
			'radio_text_color',
			array(
				'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-radio-text-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'radio_accent_color',
			array(
				'label'     => __( 'Radio Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-radio-accent-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'radio_gap',
			array(
				'label'      => __( 'Radio-to-Label Spacing', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-radio-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Output the mount point. The decision engine (assets/js/decision-engine.js)
	 * finds this by `.ryv-app-wrapper[data-form-id]` and takes over from here -
	 * no markup for the questionnaire itself is rendered server-side.
	 */
	protected function render() {
		$form_id = $this->get_settings_for_display( 'form_id' );

		if ( empty( $form_id ) ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				printf(
					'<div class="ryv-error-box">%s</div>',
					esc_html__( 'Select an Eligibility Form in the widget settings.', 'ryv-eligibility-tool' )
				);
			}
			return;
		}

		printf( '<div class="ryv-app-wrapper" data-form-id="%d"></div>', absint( $form_id ) );
	}
}
