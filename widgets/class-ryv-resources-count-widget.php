<?php
/**
 * "RYV Resources Count" Elementor widget - the "Showing 1–6 of 48 resources
 * for All states" line on the Resources page.
 *
 * The numbers aren't known when this widget renders (it sits above the
 * grid, whose query hasn't run yet), so it renders the wording templates
 * and resources-search.js fills them from the `.ryv-rs-found` marker that
 * RYV_Resources_Search stamps onto the Loop Grid's own markup - which also
 * keeps it right after every AJAX search, tab click and page change.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

/**
 * Class RYV_Resources_Count_Widget
 */
class RYV_Resources_Count_Widget extends \Elementor\Widget_Base {

	/**
	 * @return string
	 */
	public function get_name() {
		return 'ryv_resources_count';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return __( 'RYV Resources Count', 'ryv-eligibility-tool' );
	}

	/**
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-counter';
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
		return array( 'resources', 'count', 'results', 'showing' );
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
		return array( RYV_Resources_Search_Widget::register_script() );
	}

	/**
	 * Register controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Results Count', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'placeholders_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'Placeholders: <code>{start}</code> <code>{end}</code> <code>{total}</code> <code>{state}</code> <code>{search}</code>', 'ryv-eligibility-tool' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$texts = array(
			'text_many'   => array( __( 'Several Results', 'ryv-eligibility-tool' ), __( 'Showing {start}–{end} of {total} resources for {state}', 'ryv-eligibility-tool' ) ),
			'text_one'    => array( __( 'One Result', 'ryv-eligibility-tool' ), __( 'Showing 1 resource for {state}', 'ryv-eligibility-tool' ) ),
			'text_none'   => array( __( 'No Results', 'ryv-eligibility-tool' ), __( 'No resources found for {state}', 'ryv-eligibility-tool' ) ),
			'text_search' => array( __( 'Suffix While Searching', 'ryv-eligibility-tool' ), __( ' matching “{search}”', 'ryv-eligibility-tool' ) ),
			'text_all'    => array( __( '{state} When No State Is Picked', 'ryv-eligibility-tool' ), __( 'All states', 'ryv-eligibility-tool' ) ),
		);

		foreach ( $texts as $id => $text ) {
			$this->add_control(
				$id,
				array(
					'label'       => $text[0],
					'type'        => Controls_Manager::TEXT,
					'default'     => $text[1],
					'label_block' => true,
				)
			);
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'style_section',
			array(
				'label' => __( 'Results Count', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'align',
			array(
				'label'     => __( 'Alignment', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::CHOOSE,
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
				'selectors' => array( '{{WRAPPER}} .ryv-resources-count' => 'text-align: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'color',
			array(
				'label'     => __( 'Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => 'globals/colors?id=clc_primary' ),
				'selectors' => array( '{{WRAPPER}} .ryv-resources-count' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'global'   => array( 'default' => 'globals/typography?id=clc_small' ),
				'selector' => '{{WRAPPER}} .ryv-resources-count',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$attrs    = array(
			'class'       => 'ryv-resources-count',
			'aria-live'   => 'polite',
			'data-many'   => $settings['text_many'],
			'data-one'    => $settings['text_one'],
			'data-none'   => $settings['text_none'],
			'data-search' => $settings['text_search'],
			'data-all'    => $settings['text_all'],
		);
		$this->add_render_attribute( 'count', $attrs );

		// Editor preview gets a sample line; the real one is filled by JS.
		$sample = \Elementor\Plugin::$instance->editor->is_edit_mode()
			? strtr( $settings['text_many'], array( '{start}' => '1', '{end}' => '6', '{total}' => '48', '{state}' => $settings['text_all'] ) )
			: '';

		printf( '<p %s>%s</p>', $this->get_render_attribute_string( 'count' ), esc_html( $sample ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor escapes render attributes.
	}
}
