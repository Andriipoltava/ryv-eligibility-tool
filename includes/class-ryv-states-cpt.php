<?php
/**
 * Registers the `ryv_states` custom post type — one post per US state,
 * holding both state-page content (ACF fields) and a form-node editing
 * interface that syncs back to the parent ryv_forms schema.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_States_CPT
 */
class RYV_States_CPT {

	/**
	 * Post type slug.
	 */
	const POST_TYPE = 'ryv_states';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );

		add_filter( 'manage_edit-' . self::POST_TYPE . '_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_column' ), 10, 2 );
	}

	/**
	 * Register the `ryv_states` post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => __( 'States', 'ryv-eligibility-tool' ),
			'singular_name'      => __( 'State', 'ryv-eligibility-tool' ),
			'add_new'            => __( 'Add New', 'ryv-eligibility-tool' ),
			'add_new_item'       => __( 'Add New State', 'ryv-eligibility-tool' ),
			'edit_item'          => __( 'Edit State', 'ryv-eligibility-tool' ),
			'new_item'           => __( 'New State', 'ryv-eligibility-tool' ),
			'view_item'          => __( 'View State', 'ryv-eligibility-tool' ),
			'search_items'       => __( 'Search States', 'ryv-eligibility-tool' ),
			'not_found'          => __( 'No states found', 'ryv-eligibility-tool' ),
			'not_found_in_trash' => __( 'No states found in Trash', 'ryv-eligibility-tool' ),
			'all_items'          => __( 'All States', 'ryv-eligibility-tool' ),
			'menu_name'          => __( 'RYV States', 'ryv-eligibility-tool' ),
			'item_updated'       => __( 'State updated.', 'ryv-eligibility-tool' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'exclude_from_search' => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_admin_bar'   => true,
			'show_in_rest'        => true,
			'rest_base'           => 'ryv_states',
			'menu_icon'           => 'dashicons-location-alt',
			'menu_position'       => 21,
			'supports'            => array( 'title', 'thumbnail', 'excerpt' ),
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'has_archive'         => true,
			'rewrite'             => array(
				'slug'       => 'states',
				'with_front' => false,
			),
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Custom admin columns.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function admin_columns( array $columns ): array {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['ryv_state_slug']   = __( 'State Slug', 'ryv-eligibility-tool' );
				$new['ryv_linked_form']  = __( 'Linked Form', 'ryv-eligibility-tool' );
				$new['ryv_state_status'] = __( 'Status', 'ryv-eligibility-tool' );
			}
		}

		return $new;
	}

	/**
	 * Render custom admin column content.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Row's post ID.
	 */
	public function render_admin_column( string $column, int $post_id ) {
		switch ( $column ) {
			case 'ryv_state_slug':
				$slug = get_field( 'ryv_state_slug', $post_id );
				if ( $slug ) {
					printf( '<code>%s</code>', esc_html( $slug ) );
				}
				break;

			case 'ryv_linked_form':
				$form_id = get_field( 'ryv_form_connection', $post_id );
				if ( $form_id ) {
					$form = get_post( $form_id );
					if ( $form ) {
						printf(
							'<a href="%s">%s</a>',
							esc_url( get_edit_post_link( $form_id ) ),
							esc_html( $form->post_title ?: '#' . $form_id )
						);
					}
				} else {
					echo '<span class="dashicons dashicons-minus"></span>';
				}
				break;

			case 'ryv_state_status':
				$status = get_field( 'ryv_hero_status', $post_id );
				if ( $status ) {
					echo esc_html( $status );
				}
				break;
		}
	}
}
