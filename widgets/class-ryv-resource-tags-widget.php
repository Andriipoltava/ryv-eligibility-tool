<?php
/**
 * "RYV Resource Tags" Elementor widget - the tag line at the top of every
 * resource card: the resource's type(s) as teal uppercase text ("GUIDE ·
 * PDF") followed by its state(s) as tinted pills ("ALL STATES" blue,
 * a specific state like "TENNESSEE" orange) - Figma "Resource Card".
 *
 * Reads the two checkbox taxonomies an editor ticks on the resource's edit
 * screen: Resource Types (ryv_resource_type) and Resource States
 * (resource-state). It lives in the one "Resources Loop Item" template, so
 * the home page, the Resources page and every state page show the same
 * tags; Spanish pages get Spanish labels (RYV_Resources_CPT::get_term_label()).
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

/**
 * Class RYV_Resource_Tags_Widget
 */
class RYV_Resource_Tags_Widget extends \Elementor\Widget_Base {

	/**
	 * @return string
	 */
	public function get_name() {
		return 'ryv_resource_tags';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return __( 'RYV Resource Tags', 'ryv-eligibility-tool' );
	}

	/**
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-tags';
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
		return array( 'resource', 'tags', 'type', 'state' );
	}

	/**
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( RYV_Assets::STYLE_HANDLE );
	}

	/**
	 * Register controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Resource Tags', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'Shows the current resource\'s <strong>Resource Types</strong> and <strong>Resource States</strong> - both ticked like categories on the resource\'s edit screen.', 'ryv-eligibility-tool' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_control(
			'show_types',
			array(
				'label'        => __( 'Show Type', 'ryv-eligibility-tool' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'separator_text',
			array(
				'label'     => __( 'Separator Between Types', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => ' · ',
				'condition' => array( 'show_types' => 'yes' ),
			)
		);

		$this->add_control(
			'show_states',
			array(
				'label'        => __( 'Show State', 'ryv-eligibility-tool' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'separator'    => 'before',
			)
		);

		$this->add_control(
			'max_states',
			array(
				'label'       => __( 'Max States Shown', 'ryv-eligibility-tool' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 2,
				'description' => __( 'Any beyond this collapse into a "+N" pill. 0 = show all.', 'ryv-eligibility-tool' ),
				'condition'   => array( 'show_states' => 'yes' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'types_style_section',
			array(
				'label' => __( 'Type', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'types_color',
			array(
				'label'     => __( 'Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => 'globals/colors?id=clc_teal' ),
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rtag-type-color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'types_typography',
				'selector' => '{{WRAPPER}} .ryv-resource-tags__types, {{WRAPPER}} .ryv-resource-tag',
			)
		);

		$this->add_responsive_control(
			'gap',
			array(
				'label'      => __( 'Gap', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'selectors'  => array( '{{WRAPPER}}' => '--ryv-rtag-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'states_style_section',
			array(
				'label'     => __( 'State Pills', 'ryv-eligibility-tool' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_states' => 'yes' ),
			)
		);

		$this->add_responsive_control(
			'pill_padding',
			array(
				'label'      => __( 'Padding', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px' ),
				'selectors'  => array( '{{WRAPPER}} .ryv-resource-tag' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		foreach ( array(
			'all'   => __( '"All States" Pill', 'ryv-eligibility-tool' ),
			'state' => __( 'Single State Pill', 'ryv-eligibility-tool' ),
		) as $kind => $label ) {
			$this->add_control(
				$kind . '_heading',
				array(
					'label'     => $label,
					'type'      => Controls_Manager::HEADING,
					'separator' => 'before',
				)
			);

			$this->add_control(
				$kind . '_color',
				array(
					'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}}' => '--ryv-rtag-' . $kind . '-color: {{VALUE}};' ),
				)
			);

			$this->add_control(
				$kind . '_bg',
				array(
					'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}}' => '--ryv-rtag-' . $kind . '-bg: {{VALUE}};' ),
				)
			);
		}

		$this->end_controls_section();
	}

	/**
	 * @param string $taxonomy Taxonomy.
	 * @return WP_Term[]
	 */
	private function get_terms( string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_the_terms( (int) get_the_ID(), $taxonomy );
		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Render.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$types    = 'yes' === $settings['show_types'] ? $this->get_terms( RYV_Resources_CPT::TYPE_TAXONOMY ) : array();
		$states   = 'yes' === $settings['show_states'] ? $this->get_terms( RYV_Resources_CPT::STATE_TAXONOMY ) : array();

		// "All States" first, then the states alphabetically.
		usort(
			$states,
			static function ( $a, $b ) {
				if ( RYV_Resources_CPT::ALL_STATES === $a->slug ) {
					return -1;
				}
				if ( RYV_Resources_CPT::ALL_STATES === $b->slug ) {
					return 1;
				}
				return strcasecmp( $a->name, $b->name );
			}
		);

		if ( ! $types && ! $states ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="ryv-resource-tags"><span class="ryv-resource-tags__types">GUIDE · PDF</span><span class="ryv-resource-tag ryv-resource-tag--all">ALL STATES</span></div>';
			}
			return;
		}

		$html = '';

		if ( $types ) {
			$labels = array_map( array( 'RYV_Resources_CPT', 'get_term_label' ), $types );
			$html  .= '<span class="ryv-resource-tags__types">' . esc_html( implode( (string) $settings['separator_text'], $labels ) ) . '</span>';
		}

		$max   = (int) $settings['max_states'];
		$shown = $max > 0 ? array_slice( $states, 0, $max ) : $states;

		foreach ( $shown as $state ) {
			$html .= sprintf(
				'<span class="ryv-resource-tag ryv-resource-tag--%s">%s</span>',
				RYV_Resources_CPT::ALL_STATES === $state->slug ? 'all' : 'state',
				esc_html( RYV_Resources_CPT::get_term_label( $state ) )
			);
		}

		$rest = count( $states ) - count( $shown );
		if ( $rest > 0 ) {
			$html .= sprintf(
				'<span class="ryv-resource-tag ryv-resource-tag--state ryv-resource-tag--more" title="%s">+%d</span>',
				esc_attr( implode( ', ', array_map( array( 'RYV_Resources_CPT', 'get_term_label' ), array_slice( $states, count( $shown ) ) ) ) ),
				$rest
			);
		}

		echo '<div class="ryv-resource-tags">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per part above.
	}
}
