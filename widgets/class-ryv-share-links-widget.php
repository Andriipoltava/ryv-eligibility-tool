<?php
/**
 * "RYV Share Links" Elementor widget - the byline's "SHARE [copy] [email]
 * [print]" row from the Figma "Blog Post – Template": 44px outlined square
 * buttons with the design's own icons (assets/img/share/).
 *
 * Elementor Pro's Share Buttons widget has Email and Print but no "copy
 * link", and its skins don't do the outlined-square look, hence this one.
 * Copy uses the Clipboard API (assets/js/share-links.js); Email is a plain
 * mailto: link and Print calls window.print(), so neither needs a network.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

/**
 * Class RYV_Share_Links_Widget
 */
class RYV_Share_Links_Widget extends \Elementor\Widget_Base {

	const SCRIPT_HANDLE = 'ryv-share-links';

	/**
	 * @return string
	 */
	public function get_name() {
		return 'ryv_share_links';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return __( 'RYV Share Links', 'ryv-eligibility-tool' );
	}

	/**
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-share';
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
		return array( 'share', 'copy', 'email', 'print' );
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
		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_script( self::SCRIPT_HANDLE, RYV_PLUGIN_URL . 'assets/js/share-links.js', array(), RYV_VERSION, true );
		}
		return array( self::SCRIPT_HANDLE );
	}

	/**
	 * Register controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Share Links', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'label',
			array(
				'label'   => __( 'Label', 'ryv-eligibility-tool' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Share', 'ryv-eligibility-tool' ),
			)
		);

		$buttons = array(
			'copy'  => array( __( 'Copy Link', 'ryv-eligibility-tool' ), __( 'Copy link', 'ryv-eligibility-tool' ) ),
			'email' => array( __( 'Email', 'ryv-eligibility-tool' ), __( 'Share by email', 'ryv-eligibility-tool' ) ),
			'print' => array( __( 'Print', 'ryv-eligibility-tool' ), __( 'Print this page', 'ryv-eligibility-tool' ) ),
		);

		foreach ( $buttons as $key => $button ) {
			$this->add_control(
				'show_' . $key,
				array(
					'label'        => $button[0],
					'type'         => Controls_Manager::SWITCHER,
					'default'      => 'yes',
					'return_value' => 'yes',
					'separator'    => 'copy' === $key ? 'before' : 'none',
				)
			);

			$this->add_control(
				$key . '_title',
				array(
					'label'       => __( 'Tooltip / Screen Reader Text', 'ryv-eligibility-tool' ),
					'type'        => Controls_Manager::TEXT,
					'default'     => $button[1],
					'condition'   => array( 'show_' . $key => 'yes' ),
				)
			);
		}

		$this->add_control(
			'copied_text',
			array(
				'label'     => __( '"Copied" Message', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Link copied', 'ryv-eligibility-tool' ),
				'condition' => array( 'show_copy' => 'yes' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'style_section',
			array(
				'label' => __( 'Share Links', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'label'    => __( 'Label Typography', 'ryv-eligibility-tool' ),
				'selector' => '{{WRAPPER}} .ryv-share-links__label',
			)
		);

		$this->add_control(
			'label_color',
			array(
				'label'     => __( 'Label Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => 'globals/colors?id=clc_primary' ),
				'selectors' => array( '{{WRAPPER}}' => '--ryv-share-label-color: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'size',
			array(
				'label'      => __( 'Button Size', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 24, 'max' => 80 ) ),
				'separator'  => 'before',
				'selectors'  => array( '{{WRAPPER}}' => '--ryv-share-size: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'gap',
			array(
				'label'      => __( 'Gap', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'selectors'  => array( '{{WRAPPER}}' => '--ryv-share-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'border_color',
			array(
				'label'     => __( 'Border Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}}' => '--ryv-share-border: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'hover_border_color',
			array(
				'label'     => __( 'Hover Border Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => 'globals/colors?id=clc_primary' ),
				'selectors' => array( '{{WRAPPER}}' => '--ryv-share-border-hover: {{VALUE}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$url      = (string) get_permalink();
		$title    = wp_strip_all_tags( (string) get_the_title() );
		$icons    = RYV_PLUGIN_URL . 'assets/img/share/';

		$icon = static function ( $file ) use ( $icons ) {
			return sprintf( '<img src="%s" width="24" height="24" alt="" aria-hidden="true">', esc_url( $icons . $file ) );
		};

		$items = '';

		if ( 'yes' === $settings['show_copy'] ) {
			$items .= sprintf(
				'<button type="button" class="ryv-share-links__btn" data-ryv-copy="%1$s" data-copied="%2$s" title="%3$s" aria-label="%3$s">%4$s</button>',
				esc_attr( $url ),
				esc_attr( $settings['copied_text'] ),
				esc_attr( $settings['copy_title'] ),
				$icon( 'icon-copy-link.svg' )
			);
		}

		if ( 'yes' === $settings['show_email'] ) {
			$items .= sprintf(
				'<a class="ryv-share-links__btn" href="%1$s" title="%2$s" aria-label="%2$s">%3$s</a>',
				esc_url( 'mailto:?subject=' . rawurlencode( $title ) . '&body=' . rawurlencode( $url ) ),
				esc_attr( $settings['email_title'] ),
				$icon( 'icon-email.svg' )
			);
		}

		if ( 'yes' === $settings['show_print'] ) {
			$items .= sprintf(
				'<button type="button" class="ryv-share-links__btn" data-ryv-print title="%1$s" aria-label="%1$s">%2$s</button>',
				esc_attr( $settings['print_title'] ),
				$icon( 'icon-print.svg' )
			);
		}

		printf(
			'<div class="ryv-share-links">%1$s%2$s<span class="ryv-share-links__status" role="status" aria-live="polite"></span></div>',
			'' !== trim( (string) $settings['label'] ) ? '<span class="ryv-share-links__label">' . esc_html( $settings['label'] ) . '</span>' : '',
			$items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		);
	}
}
