<?php
/**
 * "RYV Resources Sort" Elementor widget - the "Sort by: Most relevant ⌄"
 * control on the Resources page (design: right end of the category-tab row).
 *
 * Sets `ryv_sort` in the URL; RYV_Resources_Search applies it to the Loop
 * Grid whose Query ID is `ryv_resources_search`. Follows the page's RYV
 * Resources Search widget: in place (AJAX) when that widget has AJAX on,
 * otherwise a normal page load.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

/**
 * Class RYV_Resources_Sort_Widget
 */
class RYV_Resources_Sort_Widget extends \Elementor\Widget_Base {

	/**
	 * @return string
	 */
	public function get_name() {
		return 'ryv_resources_sort';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return __( 'RYV Resources Sort', 'ryv-eligibility-tool' );
	}

	/**
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-sort-amount-desc';
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
		return array( 'resources', 'sort', 'order' );
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
	 * Option key (the `ryv_sort` value, '' = the Loop Grid's own order) =>
	 * [ control id, default label ].
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private function get_options(): array {
		return array(
			''           => array( 'sort_text_relevant', __( 'Most relevant', 'ryv-eligibility-tool' ) ),
			'newest'     => array( 'sort_text_newest', __( 'Newest', 'ryv-eligibility-tool' ) ),
			'oldest'     => array( 'sort_text_oldest', __( 'Oldest', 'ryv-eligibility-tool' ) ),
			'title-asc'  => array( 'sort_text_title_asc', __( 'Title A–Z', 'ryv-eligibility-tool' ) ),
			'title-desc' => array( 'sort_text_title_desc', __( 'Title Z–A', 'ryv-eligibility-tool' ) ),
		);
	}

	/**
	 * Register controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Sort', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'sort_label',
			array(
				'label'   => __( 'Label', 'ryv-eligibility-tool' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Sort by:', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'options_heading',
			array(
				'label'     => __( 'Options', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		foreach ( $this->get_options() as $value => $option ) {
			$this->add_control(
				$option[0],
				array(
					'label'       => $option[1],
					'type'        => Controls_Manager::TEXT,
					'default'     => $option[1],
					'description' => '' === $value ? __( 'The Loop Grid\'s own order. Leave any option empty to hide it.', 'ryv-eligibility-tool' ) : '',
				)
			);
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'style_section',
			array(
				'label' => __( 'Sort', 'ryv-eligibility-tool' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'align',
			array(
				'label'     => __( 'Alignment', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => __( 'Left', 'ryv-eligibility-tool' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center'     => array(
						'title' => __( 'Center', 'ryv-eligibility-tool' ),
						'icon'  => 'eicon-text-align-center',
					),
					'flex-end'   => array(
						'title' => __( 'Right', 'ryv-eligibility-tool' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors' => array( '{{WRAPPER}} .ryv-resources-sort' => 'justify-content: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'label_color',
			array(
				'label'     => __( 'Label Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}}' => '--ryv-sort-label-color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'label'    => __( 'Label Typography', 'ryv-eligibility-tool' ),
				'global'   => array( 'default' => 'globals/typography?id=clc_small' ),
				'selector' => '{{WRAPPER}} .ryv-resources-sort__label',
			)
		);

		$this->add_control(
			'value_color',
			array(
				'label'     => __( 'Selected Value Color', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => 'globals/colors?id=clc_primary' ),
				'separator' => 'before',
				'selectors' => array( '{{WRAPPER}}' => '--ryv-sort-value-color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'value_typography',
				'label'    => __( 'Selected Value Typography', 'ryv-eligibility-tool' ),
				'global'   => array( 'default' => 'globals/typography?id=clc_button' ),
				'selector' => '{{WRAPPER}} .ryv-resources-sort__select',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$current  = RYV_Resources_Search::get_current_filters()['sort'];
		$id       = 'ryv-sort-' . $this->get_id();

		// A tiny GET form of its own so it also works without JS; every other
		// current param rides along as a hidden field.
		$hidden = '';
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public filter.
			if ( ! is_string( $value ) || RYV_Resources_Search::PARAM_SORT === $key || 0 === strpos( $key, 'e-page-' ) ) {
				continue;
			}
			$hidden .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( $key ), esc_attr( wp_unslash( $value ) ) );
		}
		?>
		<form class="ryv-resources-sort" method="get">
			<?php echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr() above. ?>
			<label class="ryv-resources-sort__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $settings['sort_label'] ); ?></label>
			<select class="ryv-resources-sort__select" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( RYV_Resources_Search::PARAM_SORT ); ?>">
				<?php
				foreach ( $this->get_options() as $value => $option ) {
					$label = trim( (string) $settings[ $option[0] ] );
					if ( '' === $label ) {
						continue;
					}
					printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
				}
				?>
			</select>
			<noscript><button type="submit"><?php echo esc_html( $settings['sort_label'] ); ?></button></noscript>
		</form>
		<?php
	}
}
