<?php
/**
 * Registers the RYV widget category and the widget itself with Elementor.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Elementor
 */
class RYV_Elementor {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
	}

	/**
	 * Add a dedicated "RYV Eligibility Tool" category so the widget doesn't
	 * get lost under Elementor's generic "General"/"Basic" groups.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Elements manager.
	 */
	public function register_category( $elements_manager ) {
		$elements_manager->add_category(
			'ryv',
			array(
				'title' => __( 'RYV Eligibility Tool', 'ryv-eligibility-tool' ),
				'icon'  => 'fa fa-check-square-o',
			)
		);
	}

	/**
	 * Register the widget.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Widgets manager.
	 */
	public function register_widgets( $widgets_manager ) {
		require_once RYV_PLUGIN_DIR . 'widgets/class-ryv-widget.php';
		$widgets_manager->register( new RYV_Widget() );
	}
}
