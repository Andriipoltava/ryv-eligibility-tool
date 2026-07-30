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
				'name'     => 'button_typography',
				'label'    => __( 'Buttons', 'ryv-eligibility-tool' ),
				'selector' => '{{WRAPPER}} .ryv-option-btn, {{WRAPPER}} .ryv-continue-btn',
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
