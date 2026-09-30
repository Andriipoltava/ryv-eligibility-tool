<?php
/**
 * "RYV State Picker" Elementor widget - a 50-state dropdown + "Continue →"
 * button that takes the visitor to that state's page (the "Can I vote in
 * my state?" card in the Figma "Blog Post – Template" sidebar).
 *
 * A plain GET form submitting `ryv_go_state`, which RYV_Post_Meta turns
 * into a redirect to the state page in the current language - so it works
 * without JS, and the heading/intro above it stay ordinary Elementor
 * widgets. Reuses the Resources search bar's field styling and its
 * select2-style searchable dropdown (resources-search.js).
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
 * Class RYV_State_Picker_Widget
 */
class RYV_State_Picker_Widget extends \Elementor\Widget_Base {

	/**
	 * @return string
	 */
	public function get_name() {
		return 'ryv_state_picker';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return __( 'RYV State Picker', 'ryv-eligibility-tool' );
	}

	/**
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-select';
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
		return array( 'state', 'picker', 'dropdown', 'eligibility' );
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
				'label' => __( 'State Picker', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'placeholder',
			array(
				'label'   => __( 'Placeholder', 'ryv-eligibility-tool' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'All states', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'searchable',
			array(
				'label'        => __( 'Searchable Dropdown (Select2 style)', 'ryv-eligibility-tool' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'no_results_text',
			array(
				'label'     => __( '"No States Found" Text', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'No states found', 'ryv-eligibility-tool' ),
				'condition' => array( 'searchable' => 'yes' ),
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'     => __( 'Button Text', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Continue', 'ryv-eligibility-tool' ),
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

		$this->end_controls_section();

		$this->start_controls_section(
			'field_style_section',
			array(
				'label' => __( 'Dropdown', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'field_typography',
				'global'   => array( 'default' => 'globals/typography?id=clc_input' ),
				'selector' => '{{WRAPPER}} .ryv-resources-search__select, {{WRAPPER}} .ryv-resources-search__combo-input, {{WRAPPER}} .ryv-resources-search__combo-option',
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

		$this->add_responsive_control(
			'gap',
			array(
				'label'      => __( 'Space Above Button', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 48 ) ),
				'selectors'  => array( '{{WRAPPER}}' => '--ryv-rs-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();

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

		$this->add_control(
			'button_text_color',
			array(
				'label'     => __( 'Text Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => 'globals/colors?id=clc_white' ),
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rs-button-color: {{VALUE}}; --ryv-rs-button-hover-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'button_bg_color',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => 'globals/colors?id=clc_red' ),
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rs-button-bg: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'button_hover_bg_color',
			array(
				'label'     => __( 'Hover Background Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => 'globals/colors?id=clc_primary' ),
				'selectors' => array( '{{WRAPPER}}' => '--ryv-rs-button-hover-bg: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'button_height',
			array(
				'label'      => __( 'Height', 'ryv-eligibility-tool' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 32, 'max' => 96 ) ),
				'selectors'  => array( '{{WRAPPER}} .ryv-resources-search__button' => 'height: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$uid      = 'ryv-sp-' . $this->get_id();

		$icon_html = '';
		if ( ! empty( $settings['button_icon']['value'] ) ) {
			ob_start();
			Icons_Manager::render_icon( $settings['button_icon'], array( 'aria-hidden' => 'true' ) );
			$icon_html = '<span class="ryv-resources-search__button-icon">' . ob_get_clean() . '</span>';
		}
		?>
		<form class="ryv-resources-search ryv-state-picker" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<div class="ryv-resources-search__field ryv-resources-search__field--state">
				<label class="ryv-resources-search__sr" for="<?php echo esc_attr( $uid ); ?>"><?php echo esc_html( $settings['placeholder'] ); ?></label>
				<select class="ryv-resources-search__select" id="<?php echo esc_attr( $uid ); ?>" name="<?php echo esc_attr( RYV_Post_Meta::PARAM_GO_STATE ); ?>"
					<?php
					if ( 'yes' === $settings['searchable'] ) {
						printf( 'data-searchable data-no-results="%s"', esc_attr( $settings['no_results_text'] ) );
					}
					?>
					>
					<option value=""><?php echo esc_html( $settings['placeholder'] ); ?></option>
					<?php foreach ( RYV_Resources_Search::get_states() as $slug => $name ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<button class="ryv-resources-search__button" type="submit">
				<span class="ryv-resources-search__button-text"><?php echo esc_html( $settings['button_text'] ); ?></span>
				<?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons_Manager output. ?>
			</button>
		</form>
		<?php
	}
}
