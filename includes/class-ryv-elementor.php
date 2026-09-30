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

		require_once RYV_PLUGIN_DIR . 'widgets/class-ryv-map-widget.php';
		$widgets_manager->register( new RYV_Map_Widget() );

		require_once RYV_PLUGIN_DIR . 'widgets/class-ryv-stories-widget.php';
		$widgets_manager->register( new RYV_Stories_Widget() );

		require_once RYV_PLUGIN_DIR . 'widgets/class-ryv-featured-partners-widget.php';
		$widgets_manager->register( new RYV_Featured_Partners_Widget() );

		require_once RYV_PLUGIN_DIR . 'widgets/class-ryv-resources-search-widget.php';
		$widgets_manager->register( new RYV_Resources_Search_Widget() );

		require_once RYV_PLUGIN_DIR . 'widgets/class-ryv-resources-sort-widget.php';
		$widgets_manager->register( new RYV_Resources_Sort_Widget() );

		require_once RYV_PLUGIN_DIR . 'widgets/class-ryv-resources-count-widget.php';
		$widgets_manager->register( new RYV_Resources_Count_Widget() );

		require_once RYV_PLUGIN_DIR . 'widgets/class-ryv-post-tags-widget.php';
		$widgets_manager->register( new RYV_Post_Tags_Widget() );

		require_once RYV_PLUGIN_DIR . 'widgets/class-ryv-resource-tags-widget.php';
		$widgets_manager->register( new RYV_Resource_Tags_Widget() );

		require_once RYV_PLUGIN_DIR . 'widgets/class-ryv-share-links-widget.php';
		$widgets_manager->register( new RYV_Share_Links_Widget() );

		require_once RYV_PLUGIN_DIR . 'widgets/class-ryv-state-picker-widget.php';
		$widgets_manager->register( new RYV_State_Picker_Widget() );
	}
}
