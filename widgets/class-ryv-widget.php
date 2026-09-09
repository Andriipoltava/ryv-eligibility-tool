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

		$this->add_control(
			'auto_state_entry',
			array(
				'label'        => __( 'Auto-Detect State From Page', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'On', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'Off', 'ryv-eligibility-tool' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __(
					'When this widget is placed on a State page (RYV States post type) that has a linked Eligibility Form and State selected, skip the state picker entirely and jump straight to that state\'s first question - "Start Over" restarts from there too, not from the full picker. Falls back to the Eligibility Form selected below on any other page, or if the current State page has no form linked yet.',
					'ryv-eligibility-tool'
				),
			)
		);

		$this->add_control(
			'show_start_over',
			array(
				'label'        => __( 'Show "Start Over" Button', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'Hide', 'ryv-eligibility-tool' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __(
					'Shown on a terminal result card (markup with no further question). Some placements - e.g. a form meant to be filled out once per page load - may not want it.',
					'ryv-eligibility-tool'
				),
			)
		);

		$this->add_control(
			'show_title',
			array(
				'label'        => __( 'Show Form Title', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'Hide', 'ryv-eligibility-tool' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'The eligibility form\'s own title, rendered above the questionnaire.', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'show_description',
			array(
				'label'        => __( 'Show Form Description', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'Hide', 'ryv-eligibility-tool' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'The eligibility form\'s own description (set on import, or the post\'s excerpt), rendered below the title.', 'ryv-eligibility-tool' ),
			)
		);

		$this->end_controls_section();

		$this->register_step_indicator_controls();
		$this->register_label_controls();
		$this->register_wrapper_style_controls();
		$this->register_color_controls();
		$this->register_typography_controls();
		$this->register_header_style_controls();
		$this->register_button_style_controls();
		$this->register_continue_button_style_controls();
		$this->register_dropdown_style_controls();
		$this->register_input_style_controls();
		$this->register_radio_style_controls();
		$this->register_help_tooltip_style_controls();
		$this->register_step_indicator_style_controls();
		$this->register_step_sidebar_style_controls();
	}

	/**
	 * Content-tab controls for the optional "Step N" progress indicator
	 * shown above the question list. There's no fixed step count in a
	 * branching DAG (see CLAUDE.md - different answers reach different
	 * numbers of questions), so "current step" here means "how many
	 * question cards are on screen right now," and Total Steps is a
	 * manually-authored estimate for the "of N" portion rather than
	 * anything computed from the graph - left blank, only "Step N" shows.
	 */
	private function register_step_indicator_controls() {
		$this->start_controls_section(
			'steps_section',
			array(
				'label' => __( 'Steps & Layout', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'step_mode',
			array(
				'label'       => __( 'Step Indicator', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'none',
				'options'     => array(
					'none'    => __( 'None', 'ryv-eligibility-tool' ),
					'counter' => __( '"Step N" counter', 'ryv-eligibility-tool' ),
					'sidebar' => __( 'Named step sidebar', 'ryv-eligibility-tool' ),
				),
				'description' => __( '"Step N" counts however far into the questionnaire the visitor has gotten. The named sidebar instead shows a checklist built from each question\'s "Step Name" field (set in the form builder or a state\'s Nodes list) - it grows as the visitor reaches newly-named steps, since a branching questionnaire has no fixed total known in advance.', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'total_steps',
			array(
				'label'       => __( 'Total Steps (optional)', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 1,
				'condition'   => array(
					'step_mode' => 'counter',
				),
				'description' => __( 'An estimated total for this form/state (e.g. "8"), shown as "Step 3 of 8". This is authored by hand, not computed - a branching questionnaire has no single fixed length. Leave blank to just show "Step 3".', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'question_layout',
			array(
				'label'       => __( 'Question Layout', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'stacked',
				'options'     => array(
					'stacked' => __( 'Stacked (all questions, growing list)', 'ryv-eligibility-tool' ),
					'wizard'  => __( 'One question at a time', 'ryv-eligibility-tool' ),
				),
				'description' => __( 'Stacked keeps every answered question visible and advances instantly on click (today\'s behavior). "One question at a time" shows only the current question and always requires an explicit Continue click to advance, for every question type.', 'ryv-eligibility-tool' ),
			)
		);

		$this->end_controls_section();
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
	 * Chrome text that isn't question content (that already comes from the
	 * schema) - "Start Over", the loading/error messages, the dropdown
	 * placeholder. Each uses Elementor's own 'default' (not 'placeholder')
	 * so it round-trips through get_settings_for_display() as real content
	 * on every render, matching renderer.js's own DEFAULT_LABELS - this is
	 * deliberate, not cosmetic: WPML's Elementor string translation (see
	 * wpml-config.xml) only has something to register/translate when a
	 * control actually *has* a value. A 'placeholder' (the previous
	 * approach) only ever shows grayed-out hint text in the Elementor
	 * panel - get_settings_for_display() returns '' for it, so an admin
	 * who never touched these fields had nothing for WPML to translate at
	 * all, and a Spanish visitor always saw the English hardcoded default
	 * regardless of any translation work done. get_label_overrides()
	 * already only reaches renderer.js's own hardcoded fallback via an
	 * empty-string check, so this doesn't change that path - every one of
	 * these is now simply never empty.
	 */
	private function register_label_controls() {
		$this->start_controls_section(
			'labels_section',
			array(
				'label' => __( 'Labels', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'label_start_over',
			array(
				'label'   => __( '"Start Over" Button', 'ryv-eligibility-tool' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Start Over', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'label_loading',
			array(
				'label'   => __( 'Loading Text', 'ryv-eligibility-tool' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Loading…', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'label_retry',
			array(
				'label'   => __( 'Retry Button (on error)', 'ryv-eligibility-tool' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Try Again', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'label_select_placeholder',
			array(
				'label'       => __( 'Dropdown Placeholder', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( '— Select —', 'ryv-eligibility-tool' ),
				'description' => __( 'Only used when a picker or question is set to display as a dropdown.', 'ryv-eligibility-tool' ),
			)
		);

		$this->register_button_icon_controls( 'icon_continue', __( 'Continue Button', 'ryv-eligibility-tool' ), true );
		$this->register_button_icon_controls( 'icon_start_over', __( '"Start Over" Button', 'ryv-eligibility-tool' ) );
		$this->register_button_icon_controls( 'icon_retry', __( 'Retry Button (on error)', 'ryv-eligibility-tool' ) );

		$this->end_controls_section();
	}

	/**
	 * One Icon + Icon Position control pair, shared by the Continue/
	 * "Start Over"/Retry buttons (register_label_controls()) - rendered
	 * server-side into `data-icons` JSON by get_icon_overrides() and applied
	 * client-side by renderer.js's buttonChildren(). `$icon_key` is both the
	 * Icon control's own name and (with a `_position` suffix) its position
	 * control's name; get_icon_overrides()'s `$map` must keep using these
	 * same names.
	 *
	 * @param string $icon_key   Control name for the Icon control itself.
	 * @param string $heading    Heading label grouping this pair, so the
	 *                           three buttons stay visually distinct in a
	 *                           section that otherwise only has one heading.
	 * @param bool   $separator  Whether to draw a divider above the heading -
	 *                           only the first of the three needs one, to
	 *                           separate this group from the text labels above.
	 */
	private function register_button_icon_controls( string $icon_key, string $heading, bool $separator = false ) {
		$this->add_control(
			$icon_key . '_heading',
			array(
				'label'     => $heading,
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => $separator ? 'before' : 'default',
			)
		);

		$this->add_control(
			$icon_key,
			array(
				'label'       => __( 'Icon', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::ICONS,
				'skin'        => 'inline',
				'label_block' => false,
			)
		);

		$this->add_control(
			$icon_key . '_position',
			array(
				'label'     => __( 'Icon Position', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => array(
					'before' => __( 'Before Text', 'ryv-eligibility-tool' ),
					'after'  => __( 'After Text', 'ryv-eligibility-tool' ),
				),
				'default'   => 'after',
				'condition' => array(
					$icon_key . '[value]!' => '',
				),
			)
		);
	}

	/**
	 * The `.ryv-app-wrapper` element itself - max-width, padding, border,
	 * shadow, a per-instance background override, and the vertical gap
	 * between question cards (`.ryv-list`, which has no other Style-tab
	 * section of its own since gap is its only meaningful visual knob) - as
	 * opposed to every OTHER style section here, which targets something
	 * rendered INSIDE it (buttons, dropdown, tooltip, ...). Same
	 * CSS-custom-property technique as the rest of this file (see
	 * register_button_style_controls()'s docblock): every control here
	 * writes onto `.ryv-app-wrapper` itself, consumed by
	 * assets/src/input.css's own `.ryv-app-wrapper` rule.
	 *
	 * `wrapper_max_width`/`wrapper_padding`/`wrapper_border_width`/
	 * `wrapper_bg_color`/`wrapper_border_color` all deliberately have no
	 * `'default'` and reuse the exact variable names RYV_Settings_Page's
	 * Global Styles page also writes at `:root, .ryv-app-wrapper` scope -
	 * so leaving any of them blank falls through to Global Styles instead
	 * of a hardcoded value, same reasoning as primary_color/text_color in
	 * register_color_controls(). `wrapper_border_radius` isn't its own
	 * field at all - input.css chains it through the existing global
	 * Border Radius field (`--ryv-radius`) the same way every other
	 * border-radius property in this file already does. Only the
	 * question-gap control has no Global Styles equivalent (no sensible
	 * single "site-wide" value for it), so it keeps its own fallback baked
	 * into input.css directly (matching the widget's original look - no
	 * default value here either, so an untouched instance stays visually
	 * identical to before this section existed).
	 */
	private function register_wrapper_style_controls() {
		$this->start_controls_section(
			'wrapper_style_section',
			array(
				'label' => __( 'Wrapper', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'wrapper_max_width',
			array(
				'label'      => __( 'Max Width', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%', 'rem' ),
				'range'      => array(
					'px'  => array(
						'min' => 200,
						'max' => 1400,
					),
					'%'   => array(
						'min' => 10,
						'max' => 100,
					),
					'rem' => array(
						'min' => 10,
						'max' => 90,
					),
				),
				'description' => __( 'Leave blank for the default (36rem, ~576px).', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-wrapper-max-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'wrapper_padding',
			array(
				'label'      => __( 'Padding', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-wrapper-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'wrapper_list_gap',
			array(
				'label'      => __( 'Gap Between Questions', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 80,
					),
				),
				'description' => __( 'Vertical spacing between question cards (`.ryv-list`). Leave blank for the default (1.5rem, ~24px).', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-list-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'wrapper_bg_color',
			array(
				'label'       => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-bg-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'wrapper_border_heading',
			array(
				'label'     => __( 'Border', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'wrapper_border_width',
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
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-wrapper-border-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'wrapper_border_color',
			array(
				'label'       => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				// No 'default' - see register_color_controls()'s docblock:
				// falls through to RYV_Settings_Page's Global Styles, or
				// input.css's own #e5e7eb fallback if that's unset too.
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-wrapper-border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'wrapper_border_radius',
			array(
				'label'      => __( 'Border Radius', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-wrapper-border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		// Direct box-shadow, not a custom property - same exception
		// Group_Control_Typography already gets elsewhere in this file
		// (e.g. register_button_style_controls()): input.css has no
		// var()-driven box-shadow property to hook into, and a unique
		// per-instance {{WRAPPER}} selector has no specificity fight to
		// lose here anyway.
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'wrapper_box_shadow',
				'selector' => '{{WRAPPER}} .ryv-app-wrapper',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Colors -> CSS custom properties on .ryv-app-wrapper, consumed by
	 * assets/src/input.css (e.g. `var(--ryv-primary-color, #002a41)`).
	 *
	 * primary_color/text_color deliberately have no 'default': Elementor's
	 * CSS generator (Core\Files\CSS\Base::get_style_control_value()) treats
	 * an empty control value as null and skips emitting that `selectors`
	 * rule entirely, so leaving either blank means this widget instance
	 * writes no --ryv-primary-color/--ryv-text-color of its own - it falls
	 * straight through to RYV_Settings_Page's Global Styles (also written
	 * to the same variable names, just at `:root, .ryv-app-wrapper` scope
	 * instead of this widget instance's own higher-specificity selector),
	 * or to input.css's own hardcoded fallback if Global Styles was never
	 * configured either. A *default* value here (like the old hardcoded
	 * '#002a41') would instead have Elementor emit that literal color on
	 * every single instance unconditionally, permanently outranking Global
	 * Styles regardless of whether the admin ever touched the control.
	 * secondary_color keeps its own default since Global Styles has no
	 * "Secondary Color" setting for it to fall back to.
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
				'label'       => __( 'Primary Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-primary-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'secondary_color',
			array(
				'label'     => __( 'Secondary Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#454545',
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-secondary-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'text_color',
			array(
				'label'       => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
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
				'name'     => 'description_typography',
				'label'    => __( 'Description', 'ryv-eligibility-tool' ),
				'description' => __( 'The optional helper text set per-question in the Description field (builder → node editor), shown between the title and the options/input.', 'ryv-eligibility-tool' ),
				'selector' => '{{WRAPPER}} .ryv-question-description',
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
	 * The form's own title/description (see show_title/show_description on
	 * the Content tab and render()) - printed as an <h2>/<p> *above*
	 * .ryv-app-wrapper, i.e. as its preceding siblings, not descendants.
	 * Every other Style section bridges non-typography properties through a
	 * CSS var written onto '{{WRAPPER}} .ryv-app-wrapper', but a custom
	 * property set there is invisible to a preceding sibling (CSS custom
	 * properties only cascade to descendants) - so Color/Margin here are
	 * scoped to '{{WRAPPER}}' itself instead, which is an ancestor of both
	 * the header markup and .ryv-app-wrapper since render() prints them in
	 * the same call. Typography still targets the header selectors
	 * directly, matching register_typography_controls()'s own precedent
	 * (Elementor's typography group doesn't support CSS-var output either
	 * way).
	 */
	private function register_header_style_controls() {
		$this->start_controls_section(
			'header_style_section',
			array(
				'label' => __( 'Header', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'header_container_width',
			array(
				'label'      => __( 'Container Width', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 100,
						'max' => 1200,
					),
					'%'  => array(
						'min' => 10,
						'max' => 100,
					),
					'em' => array(
						'min' => 5,
						'max' => 80,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--ryv-header-width: {{SIZE}}{{UNIT}};',
				),
				'description' => __( 'How wide the title/description block is. Leave blank to keep the default (matches the questionnaire below it).', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'header_alignment',
			array(
				'label'     => __( 'Alignment', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array(
						'title' => __( 'Left', 'ryv-eligibility-tool' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => __( 'Center', 'ryv-eligibility-tool' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'  => array(
						'title' => __( 'Right', 'ryv-eligibility-tool' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'default'   => 'center',
				'toggle'    => false,
				'selectors' => array(
					'{{WRAPPER}}' => '--ryv-header-align: {{VALUE}};',
				),
				'description' => __( 'Where the header block sits when it\'s narrower than the full width (Container Width above) - matches the current look when left as Center.', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'header_title_typography',
				'label'    => __( 'Title Typography', 'ryv-eligibility-tool' ),
				'selector' => '{{WRAPPER}} .ryv-header-title',
			)
		);

		$this->add_control(
			'header_title_color',
			array(
				'label'     => __( 'Title Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}}' => '--ryv-header-title-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'header_title_margin_bottom',
			array(
				'label'      => __( 'Title Bottom Spacing', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--ryv-header-title-margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'header_description_typography',
				'label'    => __( 'Description Typography', 'ryv-eligibility-tool' ),
				'selector' => '{{WRAPPER}} .ryv-header-description',
			)
		);

		$this->add_control(
			'header_description_color',
			array(
				'label'     => __( 'Description Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}}' => '--ryv-header-description-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'header_description_margin_bottom',
			array(
				'label'      => __( 'Description Bottom Spacing', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--ryv-header-description-margin-bottom: {{SIZE}}{{UNIT}};',
				),
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

		$this->add_control(
			'button_layout',
			array(
				'label'   => __( 'Layout', 'ryv-eligibility-tool' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				// campaignlegal.org/restoreyourvote's own default look -
				// side-by-side pill buttons, not stacked full-width ones.
				'default' => 'row',
				'options' => array(
					'column' => __( 'Column (stacked, one per row)', 'ryv-eligibility-tool' ),
					'row'    => __( 'Row (side by side)', 'ryv-eligibility-tool' ),
				),
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-direction: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_wrap',
			array(
				'label'        => __( 'Wrap to New Line', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'No', 'ryv-eligibility-tool' ),
				'return_value' => 'wrap',
				'default'      => 'wrap',
				'condition'    => array(
					'button_layout' => 'row',
				),
				'selectors'    => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-wrap: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_width',
			array(
				'label'       => __( 'Button Width', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( '100%', 'ryv-eligibility-tool' ),
				'description' => __(
					'Any valid CSS width - e.g. "100%" (default), "auto" (fit content), or a fixed size like "200px". Only matters for Row layout - Column stretches to fill the width regardless.',
					'ryv-eligibility-tool'
				),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-width: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_gap',
			array(
				'label'      => __( 'Gap Between Buttons', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		// Defaults to 0/no rule emitted (same reasoning as
		// step_sidebar_header_gap above) - an untouched instance keeps
		// relying on the question title/description's own bottom margin
		// exactly as before this control existed.
		$this->add_control(
			'button_margin_top',
			array(
				'label'       => __( 'Top Spacing', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'description' => __( 'Extra space between the question title/description above and this button list, in addition to the title/description\'s own Bottom Spacing.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-list-margin-top: {{SIZE}}{{UNIT}};',
				),
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
				'label'       => __( 'Border Radius', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'size_units'  => array( 'px', 'em', 'rem', '%' ),
				'selectors'   => array(
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
				'label'       => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				// No 'default' - see register_color_controls()'s docblock for
				// why: this falls through to --ryv-bg-color (Global Styles)
				// instead of a hardcoded white when left blank.
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-bg: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_border_color',
			array(
				'label'       => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				// No 'default' - see register_color_controls()'s docblock:
				// falls through to RYV_Settings_Page's Global Styles, or
				// input.css's own #ececec fallback if that's unset too.
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
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
			'button_text_color_hover',
			array(
				'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-btn-text-color-hover: {{VALUE}};',
				),
			)
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

		// Every render path (a question's own Continue, a checkbox list's
		// deferred Continue, error/unsupported-node retry buttons) now shares
		// one CSS-driven default (input.css) instead of some getting a
		// hardcoded Tailwind `mt-3` and others getting nothing - see this
		// control's own history in CLAUDE.md for why that inconsistency
		// mattered.
		$this->add_control(
			'continue_button_margin_top',
			array(
				'label'      => __( 'Top Spacing', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-continue-btn-margin-top: {{SIZE}}{{UNIT}};',
				),
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
				'label'       => __( 'Border Radius', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'size_units'  => array( 'px', 'em', 'rem', '%' ),
				'selectors'   => array(
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
	 * display_style: 'dropdown' - either a native <select> (.ryv-select), or,
	 * when the source question also has select2: true (see
	 * RYV_YAML_Parser::build_node()), the search-enabled combobox
	 * (.ryv-select2-input/.ryv-select2-menu - see renderer.js's
	 * renderSearchableSelect()). Both variants read the same --ryv-select-*
	 * custom properties below, so one set of controls styles either; only
	 * Typography needs its own selector added per-variant, since Elementor's
	 * typography group generates literal CSS rules rather than a custom
	 * property.
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
				'selector' => '{{WRAPPER}} .ryv-select, {{WRAPPER}} .ryv-select2-input, {{WRAPPER}} .ryv-select2-option',
			)
		);

		$this->add_responsive_control(
			'dropdown_max_width',
			array(
				'label'       => __( 'Max Width', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px', '%', 'rem' ),
				'range'       => array(
					'px'  => array(
						'min' => 50,
						'max' => 1400,
					),
					'%'   => array(
						'min' => 10,
						'max' => 100,
					),
					'rem' => array(
						'min' => 5,
						'max' => 90,
					),
				),
				'description' => __( 'Applies to both the plain dropdown and the searchable (Select2) variant - they share the same underlying styling. Leave blank for full width (100%).', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-select-max-width: {{SIZE}}{{UNIT}};',
				),
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
				'label'       => __( 'Border Radius', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'size_units'  => array( 'px', 'em', 'rem', '%' ),
				'selectors'   => array(
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

		// Defaults to 0/no rule emitted, same reasoning as button_margin_top
		// above - shared by both the plain <select> and the searchable
		// (select2) variant, same as every other --ryv-select-* var.
		$this->add_control(
			'dropdown_margin_top',
			array(
				'label'      => __( 'Top Spacing', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'description' => __( 'Extra space between the question title/description above and the dropdown below.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-select-margin-top: {{SIZE}}{{UNIT}};',
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
				'label'       => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				// No 'default' - see register_color_controls()'s docblock.
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-select-bg: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'dropdown_border_color',
			array(
				'label'       => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				// No 'default' - see register_color_controls()'s docblock.
				// This one's old hardcoded '#d1d5db' never actually matched
				// input.css's own fallback (#afb0b1, campaignlegal.org's
				// real dropdown border) anyway - removing it also fixes
				// that mismatch for every untouched instance, not just
				// unlocking Global Styles.
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
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

		$this->add_control(
			'dropdown_select2_highlight_bg',
			array(
				'label'       => __( 'Highlighted Option Background', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Only affects the search-enabled dropdown (a question with Select2 enabled in the source form) - the background of the option under the cursor or keyboard focus in its results list.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-select2-highlight-bg: {{VALUE}};',
				),
			)
		);

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
			'input_max_width',
			array(
				'label'       => __( 'Max Width', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px', '%', 'rem' ),
				'range'       => array(
					'px'  => array(
						'min' => 50,
						'max' => 1400,
					),
					'%'   => array(
						'min' => 10,
						'max' => 100,
					),
					'rem' => array(
						'min' => 5,
						'max' => 90,
					),
				),
				'description' => __( 'Leave blank for full width (100%).', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-input-max-width: {{SIZE}}{{UNIT}};',
				),
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
				'label'       => __( 'Border Radius', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'size_units'  => array( 'px', 'em', 'rem', '%' ),
				'selectors'   => array(
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
				'label'       => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				// No 'default' - see register_color_controls()'s docblock.
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-input-bg: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'input_border_color',
			array(
				'label'       => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				// No 'default' - see register_color_controls()'s docblock.
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
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

		// Defaults to 0/no rule emitted, same reasoning as button_margin_top -
		// each .ryv-radio-option is appended straight to the card with no
		// enclosing group wrapper (see renderSingleChoiceOptions() in
		// renderer.js), so this only targets the first one via :first-of-type
		// rather than adding space between every option.
		$this->add_control(
			'radio_margin_top',
			array(
				'label'       => __( 'Top Spacing', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'description' => __( 'Extra space between the question title/description above and the radio options below.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-radio-margin-top: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The optional "?" tooltip icon after a question's title (node.help - a
	 * YAML import sets it automatically from Drupal's #help) and the popover
	 * it toggles (.ryv-help-icon / .ryv-help-tooltip - see renderer.js's
	 * buildHelpTooltip()).
	 */
	private function register_help_tooltip_style_controls() {
		$this->start_controls_section(
			'help_tooltip_style_section',
			array(
				'label' => __( 'Help Tooltip', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'help_tooltip_typography',
				'label'    => __( 'Tooltip Typography', 'ryv-eligibility-tool' ),
				'selector' => '{{WRAPPER}} .ryv-help-tooltip',
			)
		);

		$this->add_control(
			'help_icon_heading',
			array(
				'label' => __( 'Icon', 'ryv-eligibility-tool' ),
				'type'  => \Elementor\Controls_Manager::HEADING,
			)
		);

		$this->add_control(
			'help_icon_color',
			array(
				'label'       => __( 'Icon Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				// No 'default' - see register_color_controls()'s docblock.
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-help-icon-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'help_icon_bg_color',
			array(
				'label'       => __( 'Icon Background Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Leave blank to use the Secondary Color set under Colors.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-help-icon-bg: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'help_icon_bg_color_hover',
			array(
				'label'       => __( 'Icon Background Color (Hover)', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Leave blank to use the Primary Color set under Colors.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-help-icon-bg-hover: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'help_tooltip_heading',
			array(
				'label'     => __( 'Tooltip', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'help_tooltip_text_color',
			array(
				'label'       => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-help-tooltip-text-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'help_tooltip_bg_color',
			array(
				'label'       => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-help-tooltip-bg: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'help_tooltip_padding',
			array(
				'label'      => __( 'Padding', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-help-tooltip-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'help_tooltip_border_radius',
			array(
				'label'       => __( 'Border Radius', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'description' => __( 'Leave blank to use the site-wide default set under RYV Forms → Global Styles.', 'ryv-eligibility-tool' ),
				'size_units'  => array( 'px', 'em', 'rem', '%' ),
				'selectors'   => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-help-tooltip-border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style controls for the "Show Step Indicator" element (.ryv-step-indicator,
	 * see register_step_indicator_controls() on the Content tab for why "step"
	 * is a running count, not a fixed wizard position).
	 */
	private function register_step_indicator_style_controls() {
		$this->start_controls_section(
			'step_indicator_style_section',
			array(
				'label'     => __( 'Step Indicator', 'ryv-eligibility-tool' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array(
					'step_mode' => 'counter',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'step_indicator_typography',
				'selector' => '{{WRAPPER}} .ryv-step-indicator',
			)
		);

		$this->add_control(
			'step_indicator_color',
			array(
				'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-step-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'step_indicator_margin_bottom',
			array(
				'label'      => __( 'Bottom Spacing', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-app-wrapper' => '--ryv-step-margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style controls for the "Named step sidebar" mode (.ryv-step-list, see
	 * register_step_indicator_controls()'s `step_mode` control). Same
	 * CSS-custom-property technique as the rest of this file - values here
	 * fall through to input.css's own defaults (which already reuse
	 * --ryv-primary-color/--ryv-secondary-color) when left blank.
	 *
	 * Every custom property below is written onto plain `{{WRAPPER}}`
	 * (the widget's own root element), never `{{WRAPPER}} .ryv-app-wrapper`
	 * - the exact mistake register_header_style_controls() already warns
	 * about, but inverted: `.ryv-step-list`/`.ryv-step-list-marker` render
	 * inside `.ryv-layout-sidebar`, a *sibling* of `.ryv-app-wrapper` under
	 * `.ryv-layout--sidebar` (see render()), not a descendant of it. A
	 * property set on `.ryv-app-wrapper` is invisible outside its own
	 * subtree, so writing it there silently no-ops every control in this
	 * method - confirmed live (Gap Between Steps/Step Number Size did
	 * nothing) before this was corrected to target `{{WRAPPER}}` itself,
	 * which is an ancestor of both siblings.
	 */
	private function register_step_sidebar_style_controls() {
		$this->start_controls_section(
			'step_sidebar_style_section',
			array(
				'label'     => __( 'Step Sidebar', 'ryv-eligibility-tool' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array(
					'step_mode' => 'sidebar',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'step_sidebar_typography',
				'selector' => '{{WRAPPER}} .ryv-step-list-item',
			)
		);

		$this->add_control(
			'step_sidebar_active_color',
			array(
				'label'       => __( 'Active Step Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Leave blank to use the widget\'s Primary Color.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--ryv-step-sidebar-active-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'step_sidebar_gap',
			array(
				'label'      => __( 'Gap Between Steps', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--ryv-step-sidebar-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		// Spacing between the header (title/description) and the step
		// checklist below it - the two blocks stacked inside
		// .ryv-layout-sidebar (see render(), and the matching input.css
		// rule this drives). Defaults to 0/no rule emitted, so an untouched
		// instance keeps relying on the header's own bottom margin exactly
		// as before this control existed.
		$this->add_control(
			'step_sidebar_header_gap',
			array(
				'label'      => __( 'Spacing Above Steps', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'description' => __( 'Extra space between the title/description above and the step checklist below, in addition to the title/description\'s own Bottom Spacing.', 'ryv-eligibility-tool' ),
				'selectors'  => array(
					'{{WRAPPER}}' => '--ryv-layout-sidebar-inner-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'step_sidebar_marker_heading',
			array(
				'label'     => __( 'Step Number', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'step_sidebar_marker_size',
			array(
				'label'      => __( 'Size', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 16,
						'max' => 48,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--ryv-step-sidebar-marker-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'step_sidebar_marker_font_size',
			array(
				'label'      => __( 'Font Size', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 8,
						'max' => 32,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--ryv-step-sidebar-marker-font-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'step_sidebar_marker_color',
			array(
				'label'       => __( 'Number Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'The upcoming (not yet done/active) step number and its border. Leave blank to use the widget\'s Secondary Color.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--ryv-step-sidebar-marker-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'step_sidebar_marker_bg_color',
			array(
				'label'       => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Fill of an upcoming (not yet done/active) step number - transparent by default. A done/active step always shows the Active Step Color instead, regardless of this.', 'ryv-eligibility-tool' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--ryv-step-sidebar-marker-bg-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'step_sidebar_marker_border_width',
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
					'{{WRAPPER}}' => '--ryv-step-sidebar-marker-border-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		// Direct box-shadow, not a custom property - same exception as
		// register_wrapper_style_controls()'s own Box Shadow control:
		// input.css has no var()-driven box-shadow to hook into here, and a
		// unique per-instance {{WRAPPER}} selector has no specificity fight
		// to lose.
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'step_sidebar_marker_box_shadow',
				'selector' => '{{WRAPPER}} .ryv-step-list-marker',
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
		$form_id    = $this->get_settings_for_display( 'form_id' );
		$entry_node = null;

		if ( 'yes' === $this->get_settings_for_display( 'auto_state_entry' ) ) {
			$override = $this->get_state_entry_override();
			if ( $override ) {
				// The state page's own linked form always wins over a
				// manually-picked one below it - the whole point of this
				// mode is "show *this page's* state, regardless of what the
				// widget was originally configured with."
				$form_id    = $override['form_id'];
				$entry_node = $override['entry_node'];
			}
		}

		if ( empty( $form_id ) ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				printf(
					'<div class="ryv-error-box">%s</div>',
					esc_html__( 'Select an Eligibility Form in the widget settings.', 'ryv-eligibility-tool' )
				);
			}
			return;
		}

		$show_start_over = 'yes' === $this->get_settings_for_display( 'show_start_over' );
		$step_mode       = $this->get_settings_for_display( 'step_mode' );
		$step_mode       = in_array( $step_mode, array( 'counter', 'sidebar' ), true ) ? $step_mode : 'none';
		$total_steps     = absint( $this->get_settings_for_display( 'total_steps' ) );
		$question_layout = 'wizard' === $this->get_settings_for_display( 'question_layout' ) ? 'wizard' : 'stacked';

		// The "Named step sidebar" mode needs a two-column layout (header +
		// step checklist on the left, the questions on the right) - the
		// header and an empty sidebar slot (populated client-side by
		// engine.js's mount(), see updateStepSidebar()) both need to live
		// *outside* .ryv-app-wrapper as its sibling, inside one shared
		// `.ryv-layout--sidebar` grid container. Every other step_mode
		// keeps today's flat markup (header printed directly, no wrapper).
		if ( 'sidebar' === $step_mode ) {
			echo '<div class="ryv-layout ryv-layout--sidebar"><div class="ryv-layout-sidebar">';
			$this->render_header( absint( $form_id ) );
			echo '<div class="ryv-step-sidebar-slot"></div></div><div class="ryv-layout-main">';
		} else {
			$this->render_header( absint( $form_id ) );
		}

		printf(
			'<div class="ryv-app-wrapper" data-form-id="%d" data-show-start-over="%d" data-labels="%s" data-icons="%s" data-entry-node="%s" data-step-mode="%s" data-total-steps="%d" data-question-layout="%s"></div>',
			absint( $form_id ),
			$show_start_over ? 1 : 0,
			esc_attr( wp_json_encode( $this->get_label_overrides() ) ),
			esc_attr( wp_json_encode( $this->get_icon_overrides() ) ),
			esc_attr( (string) $entry_node ),
			esc_attr( $step_mode ),
			$total_steps,
			esc_attr( $question_layout )
		);

		if ( 'sidebar' === $step_mode ) {
			echo '</div></div>';
		}
	}

	/**
	 * "Auto-Detect State From Page" resolution: when the current page is a
	 * `ryv_states` post with both a linked form and a state selected, look
	 * up that state's own first question (the same node RYV_States_Sync's
	 * node-repeater sync uses as its walk's starting point) and hand it back
	 * as a decision-engine entry-node override, together with the form to
	 * fetch it from.
	 *
	 * Requires ACF (the `ryv_states` fields, and RYV_States_Sync::find_start_node()
	 * which RYV_Plugin only loads when ACF is active) - silently returns
	 * null without it, same as this whole feature simply not applying,
	 * rather than a fatal error.
	 *
	 * @return array{form_id:int,entry_node:string}|null
	 */
	private function get_state_entry_override(): ?array {
		if ( ! class_exists( 'RYV_States_Sync' ) || ! function_exists( 'get_field' ) ) {
			return null;
		}

		$post_id = get_the_ID();
		if ( ! $post_id || get_post_type( $post_id ) !== RYV_States_CPT::POST_TYPE ) {
			return null;
		}

		$form_id    = (int) get_field( 'ryv_form_connection', $post_id );
		$state_slug = (string) get_field( 'ryv_state_slug', $post_id );

		if ( ! $form_id || ! $state_slug ) {
			return null;
		}

		$schema     = RYV_CPT::get_schema( $form_id );
		$entry_node = RYV_States_Sync::find_start_node( $schema['nodes'] ?? array(), $schema['entry_node'] ?? null, $state_slug );

		if ( ! $entry_node ) {
			return null;
		}

		return array(
			'form_id'    => $form_id,
			'entry_node' => $entry_node,
		);
	}

	/**
	 * The form post's own title/description, printed as plain server-side
	 * HTML *above* the .ryv-app-wrapper mount point - unlike the
	 * questionnaire itself, this needs no JS/REST round trip, since the
	 * widget already has the post available at render time. That also means
	 * it bypasses RYV_API::get_viewable_form()'s visibility check, so the
	 * same "published to everyone, draft only to whoever can edit_post this
	 * form" rule has to be re-applied here explicitly - same rule
	 * RYV_Shortcode::render() enforces, so an embed of a draft/unpublished
	 * form behaves identically whether it's this widget or [ryv_form].
	 *
	 * @param int $form_id The `ryv_forms` post ID.
	 */
	private function render_header( int $form_id ) {
		$show_title       = 'yes' === $this->get_settings_for_display( 'show_title' );
		$show_description = 'yes' === $this->get_settings_for_display( 'show_description' );

		if ( ! $show_title && ! $show_description ) {
			return;
		}

		$post = get_post( $form_id );

		if ( ! $post ) {
			return;
		}

		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $form_id ) ) {
			return;
		}

		$title_html       = ( $show_title && '' !== trim( $post->post_title ) )
			? sprintf( '<h2 class="ryv-header-title">%s</h2>', esc_html( $post->post_title ) )
			: '';
		$description_html = ( $show_description && '' !== trim( $post->post_excerpt ) )
			? sprintf( '<p class="ryv-header-description">%s</p>', esc_html( $post->post_excerpt ) )
			: '';

		if ( '' === $title_html && '' === $description_html ) {
			return;
		}

		// `.ryv-header-align` (full width, text-align only) wraps
		// `.ryv-header` (display:inline-block, its own max-width) - the
		// same trick Elementor's own boxed widgets use to get "a
		// content-width-capped block, positioned left/center/right within
		// its full-width parent" out of plain CSS: text-align on a block
		// parent only repositions an inline-level child, it can't reposition
		// itself, so the width-constrained box and the alignment context
		// can't be the same element. See register_header_style_controls()
		// for the controls that feed --ryv-header-width/-align.
		printf(
			'<div class="ryv-header-align"><div class="ryv-header">%s%s</div></div>',
			$title_html,
			$description_html
		);
	}

	/**
	 * Only the "Labels" controls the user actually filled in - engine.js
	 * merges this over its own hardcoded defaults, so an empty setting here
	 * means "use the default", not "use an empty string".
	 *
	 * @return array<string,string>
	 */
	private function get_label_overrides(): array {
		$map = array(
			'label_start_over'         => 'startOver',
			'label_loading'            => 'loading',
			'label_retry'              => 'retry',
			'label_select_placeholder' => 'selectPlaceholder',
		);

		$overrides = array();

		foreach ( $map as $setting_key => $label_key ) {
			$value = $this->get_settings_for_display( $setting_key );
			if ( '' !== trim( (string) $value ) ) {
				$overrides[ $label_key ] = $value;
			}
		}

		return $overrides;
	}

	/**
	 * Icon overrides for the Continue/Start Over/Retry buttons (the
	 * `icon_*`/`icon_*_position` controls added in register_label_controls()),
	 * pre-rendered server-side into `{ html, position }` pairs and printed as
	 * `data-icons` JSON on `.ryv-app-wrapper` (see render()) - engine.js
	 * merges them over renderer.js's DEFAULT_ICONS. Rendering here rather
	 * than shipping the raw Elementor icon value to the frontend keeps the
	 * decision-engine module free of any Font-Awesome-vs-SVG icon-library
	 * branching of its own; `Icons_Manager::render_icon()`'s own output is
	 * the same trust boundary as `node.content` elsewhere in this plugin
	 * (admin-authored via the widget's own controls, applied via innerHTML).
	 * A key is only present when that button's Icon control is actually
	 * set - an absent key means "no icon", not "icon exists but is empty".
	 *
	 * @return array<string,array{html:string,position:string}>
	 */
	private function get_icon_overrides(): array {
		if ( ! class_exists( '\Elementor\Icons_Manager' ) ) {
			return array();
		}

		$map = array(
			'continue'  => 'icon_continue',
			'startOver' => 'icon_start_over',
			'retry'     => 'icon_retry',
		);

		$overrides = array();

		foreach ( $map as $label_key => $setting_key ) {
			$icon = $this->get_settings_for_display( $setting_key );
			if ( empty( $icon['value'] ) ) {
				continue;
			}

			ob_start();
			\Elementor\Icons_Manager::render_icon( $icon, array( 'aria-hidden' => 'true' ) );
			$html = trim( (string) ob_get_clean() );

			if ( '' === $html ) {
				continue;
			}

			$position               = $this->get_settings_for_display( $setting_key . '_position' );
			$overrides[ $label_key ] = array(
				'html'     => $html,
				'position' => 'before' === $position ? 'before' : 'after',
			);
		}

		return $overrides;
	}
}
