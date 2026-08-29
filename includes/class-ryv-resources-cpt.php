<?php
/**
 * Resources CPT — ryv_resources.
 *
 * Standalone post type for guides, forms, videos, toolkits. States link to
 * resources via an ACF relationship field rather than embedding them as
 * repeater rows.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Resources_CPT
 */
class RYV_Resources_CPT {

	const POST_TYPE = 'ryv_resources';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_column' ), 10, 2 );

		if ( function_exists( 'acf_add_local_field_group' ) ) {
			add_action( 'acf/init', array( $this, 'register_acf_fields' ) );
		}
	}

	/**
	 * Register the ryv_resources post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => 'RYV Resources',
			'singular_name'      => 'Resource',
			'add_new'            => 'Add New',
			'add_new_item'       => 'Add New Resource',
			'edit_item'          => 'Edit Resource',
			'new_item'           => 'New Resource',
			'view_item'          => 'View Resource',
			'search_items'       => 'Search Resources',
			'not_found'          => 'No resources found',
			'not_found_in_trash' => 'No resources found in trash',
			'menu_name'          => 'RYV Resources',
		);

		$args = array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_position'       => 28,
			'menu_icon'           => 'dashicons-media-document',
			'has_archive'         => false,
			'rewrite'             => array( 'slug' => 'resources', 'with_front' => false ),
			'supports'            => array( 'title', 'thumbnail' ),
			'show_in_rest'        => true,
			'exclude_from_search' => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Custom admin list columns.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public function admin_columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['ryv_resource_category'] = 'Category';
				$new['ryv_resource_format']   = 'Format';
			}
		}
		return $new;
	}

	/**
	 * Render custom column values.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_admin_column( string $column, int $post_id ) {
		switch ( $column ) {
			case 'ryv_resource_category':
				$cat = get_field( 'ryv_resource_category', $post_id );
				$labels = array(
					'guide'        => 'Guide',
					'form'         => 'Form',
					'video'        => 'Video',
					'for-partners' => 'For Partners',
					'for-press'    => 'For Press',
				);
				echo isset( $labels[ $cat ] ) ? esc_html( $labels[ $cat ] ) : '—';
				break;
			case 'ryv_resource_format':
				$format = get_field( 'ryv_resource_format', $post_id );
				echo $format ? esc_html( $format ) : '—';
				break;
		}
	}

	/**
	 * Register ACF fields for the Resources CPT.
	 */
	public function register_acf_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		acf_add_local_field_group( array(
			'key'    => 'group_ryv_resource_details',
			'title'  => 'Resource Details',
			'fields' => array(
				array(
					'key'           => 'field_ryv_resource_category',
					'label'         => 'Category',
					'name'          => 'ryv_resource_category',
					'type'          => 'select',
					'choices'       => array(
						'guide'        => 'Guide',
						'form'         => 'Form',
						'video'        => 'Video',
						'for-partners' => 'For Partners',
						'for-press'    => 'For Press',
					),
					'allow_null'    => 0,
					'default_value' => 'guide',
					'wrapper'       => array( 'width' => '50' ),
				),
				array(
					'key'          => 'field_ryv_resource_format',
					'label'        => 'Format',
					'name'         => 'ryv_resource_format',
					'type'         => 'text',
					'instructions' => 'E.g. "PDF", "Video", "Web Page"',
					'wrapper'      => array( 'width' => '50' ),
				),
				array(
					'key'   => 'field_ryv_resource_description',
					'label' => 'Description',
					'name'  => 'ryv_resource_description',
					'type'  => 'textarea',
					'rows'  => 3,
				),
				array(
					'key'     => 'field_ryv_resource_url',
					'label'   => 'URL',
					'name'    => 'ryv_resource_url',
					'type'    => 'url',
					'wrapper' => array( 'width' => '50' ),
					'instructions' => 'Link to the resource page.',
				),
				array(
					'key'           => 'field_ryv_resource_file',
					'label'         => 'File',
					'name'          => 'ryv_resource_file',
					'type'          => 'file',
					'return_format' => 'array',
					'instructions'  => 'Upload a file (PDF, etc.) instead of a URL.',
					'wrapper'       => array( 'width' => '50' ),
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => self::POST_TYPE,
					),
				),
			),
			'menu_order' => 0,
			'style'      => 'default',
		) );
	}
}
