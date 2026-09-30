<?php
/**
 * "RYV Post Tags" Elementor widget - the tinted topic/state chips above a
 * blog post's title ("POLICY" red-tinted, "TENNESSEE" blue-tinted) in the
 * Figma "Blog Post – Template", and on its Related Stories cards.
 *
 * Needed because both kinds live in the one core `category` taxonomy and
 * the design styles them differently - Elementor's Post Info/Terms widgets
 * can only list a taxonomy's terms, not tell the two apart. Which is which
 * is RYV_Post_Meta::is_state_term()'s call. Works on the current post, so
 * it's equally at home in the single template and inside a Loop Item.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

/**
 * Class RYV_Post_Tags_Widget
 */
class RYV_Post_Tags_Widget extends \Elementor\Widget_Base {

	/**
	 * @return string
	 */
	public function get_name() {
		return 'ryv_post_tags';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return __( 'RYV Post Tags', 'ryv-eligibility-tool' );
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
		return array( 'tags', 'category', 'state', 'topic', 'post' );
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
				'label' => __( 'Tags', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'Shows the current post\'s categories. A category named after a US state (e.g. KENTUCKY) is a <strong>state</strong> tag, every other one a <strong>topic</strong> tag.', 'ryv-eligibility-tool' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_control(
			'topics',
			array(
				'label'   => __( 'Topic Tags', 'ryv-eligibility-tool' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'primary',
				'options' => array(
					'primary' => __( 'Primary topic only', 'ryv-eligibility-tool' ),
					'all'     => __( 'All topics', 'ryv-eligibility-tool' ),
					'none'    => __( 'None', 'ryv-eligibility-tool' ),
				),
			)
		);

		$this->add_control(
			'states',
			array(
				'label'   => __( 'State Tags', 'ryv-eligibility-tool' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'all',
				'options' => array(
					'first' => __( 'First state only', 'ryv-eligibility-tool' ),
					'all'   => __( 'All states', 'ryv-eligibility-tool' ),
					'none'  => __( 'None', 'ryv-eligibility-tool' ),
				),
			)
		);

		$this->add_control(
			'link',
			array(
				'label'        => __( 'Link to Category Archive', 'ryv-eligibility-tool' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'variant',
			array(
				'label'       => __( 'Look', 'ryv-eligibility-tool' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'tinted',
				'options'     => array(
					'tinted'  => __( 'Tinted (article header, cards)', 'ryv-eligibility-tool' ),
					'outline' => __( 'Outline chips ("Filed under")', 'ryv-eligibility-tool' ),
				),
				'prefix_class' => 'ryv-post-tags--',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'style_section',
			array(
				'label' => __( 'Tags', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'selector' => '{{WRAPPER}} .ryv-post-tag',
			)
		);

		$this->add_responsive_control(
			'gap',
			array(
				'label'      => __( 'Gap', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'selectors'  => array( '{{WRAPPER}}' => '--ryv-post-tags-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'padding',
			array(
				'label'      => __( 'Padding', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px' ),
				'selectors'  => array( '{{WRAPPER}} .ryv-post-tag' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		foreach ( array(
			'topic' => __( 'Topic', 'ryv-eligibility-tool' ),
			'state' => __( 'State', 'ryv-eligibility-tool' ),
		) as $kind => $label ) {
			$this->add_control(
				$kind . '_heading',
				array(
					/* translators: %s: Topic / State */
					'label'     => sprintf( __( '%s Tag', 'ryv-eligibility-tool' ), $label ),
					'type'      => Controls_Manager::HEADING,
					'separator' => 'before',
				)
			);

			$this->add_control(
				$kind . '_color',
				array(
					'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}}' => '--ryv-post-tag-' . $kind . '-color: {{VALUE}};' ),
				)
			);

			$this->add_control(
				$kind . '_bg',
				array(
					'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}}' => '--ryv-post-tag-' . $kind . '-bg: {{VALUE}};' ),
				)
			);
		}

		$this->end_controls_section();
	}

	/**
	 * Render.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$post_id  = (int) get_the_ID();

		$topics = array();
		if ( 'primary' === $settings['topics'] ) {
			$primary = RYV_Post_Meta::get_primary_topic( $post_id );
			$topics  = $primary ? array( $primary ) : array();
		} elseif ( 'all' === $settings['topics'] ) {
			$topics = RYV_Post_Meta::get_terms_of_kind( $post_id, 'topic' );
		}

		$states = 'none' === $settings['states'] ? array() : RYV_Post_Meta::get_terms_of_kind( $post_id, 'state' );
		if ( 'first' === $settings['states'] ) {
			$states = array_slice( $states, 0, 1 );
		}

		if ( ! $topics && ! $states ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="ryv-post-tags"><span class="ryv-post-tag ryv-post-tag--topic">' . esc_html__( 'Topic', 'ryv-eligibility-tool' ) . '</span><span class="ryv-post-tag ryv-post-tag--state">' . esc_html__( 'State', 'ryv-eligibility-tool' ) . '</span></div>';
			}
			return;
		}

		$link = 'yes' === $settings['link'];
		$html = '';

		foreach ( array(
			'topic' => $topics,
			'state' => $states,
		) as $kind => $terms ) {
			foreach ( $terms as $term ) {
				$url = $link ? get_term_link( $term ) : '';
				$tag = $url && ! is_wp_error( $url ) ? 'a' : 'span';

				$html .= sprintf(
					'<%1$s class="ryv-post-tag ryv-post-tag--%2$s"%3$s>%4$s</%1$s>',
					$tag,
					esc_attr( $kind ),
					'a' === $tag ? ' href="' . esc_url( $url ) . '"' : '',
					esc_html( $term->name )
				);
			}
		}

		echo '<div class="ryv-post-tags">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per tag above.
	}
}
