<?php
/**
 * Events CPT — ryv_events.
 *
 * Standalone post type for local events. States link to events via an ACF
 * relationship field rather than embedding them as repeater rows.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Events_CPT
 */
class RYV_Events_CPT {

	const POST_TYPE = 'ryv_events';

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
	 * Register the ryv_events post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => 'RYV Events',
			'singular_name'      => 'Event',
			'add_new'            => 'Add New',
			'add_new_item'       => 'Add New Event',
			'edit_item'          => 'Edit Event',
			'new_item'           => 'New Event',
			'view_item'          => 'View Event',
			'search_items'       => 'Search Events',
			'not_found'          => 'No events found',
			'not_found_in_trash' => 'No events found in trash',
			'menu_name'          => 'RYV Events',
		);

		$args = array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_position'       => 27,
			'menu_icon'           => 'dashicons-calendar-alt',
			'has_archive'         => false,
			'rewrite'             => array( 'slug' => 'events', 'with_front' => false ),
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
				$new['ryv_event_date']     = 'Date';
				$new['ryv_event_location'] = 'Location';
				$new['ryv_event_organizer'] = 'Organizer';
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
			case 'ryv_event_date':
				$date = get_field( 'ryv_event_date', $post_id );
				echo $date ? esc_html( $date ) : '—';
				break;
			case 'ryv_event_location':
				$location = get_field( 'ryv_event_location', $post_id );
				echo $location ? esc_html( $location ) : '—';
				break;
			case 'ryv_event_organizer':
				$organizer = get_field( 'ryv_event_organizer', $post_id );
				echo $organizer ? esc_html( $organizer ) : '—';
				break;
		}
	}

	/**
	 * Register ACF fields for the Events CPT.
	 */
	public function register_acf_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		acf_add_local_field_group( array(
			'key'    => 'group_ryv_event_details',
			'title'  => 'Event Details',
			'fields' => array(
				array(
					'key'            => 'field_ryv_event_date',
					'label'          => 'Date',
					'name'           => 'ryv_event_date',
					'type'           => 'date_picker',
					'display_format' => 'F j, Y',
					'return_format'  => 'Y-m-d',
					'wrapper'        => array( 'width' => '33' ),
				),
				array(
					'key'          => 'field_ryv_event_time',
					'label'        => 'Time',
					'name'         => 'ryv_event_time',
					'type'         => 'text',
					'wrapper'      => array( 'width' => '33' ),
					'instructions' => 'E.g. "6:00 PM - 8:00 PM CST"',
				),
				array(
					'key'     => 'field_ryv_event_location',
					'label'   => 'Location',
					'name'    => 'ryv_event_location',
					'type'    => 'text',
					'wrapper' => array( 'width' => '34' ),
				),
				array(
					'key'   => 'field_ryv_event_organizer',
					'label' => 'Organizer',
					'name'  => 'ryv_event_organizer',
					'type'  => 'text',
				),
				array(
					'key'   => 'field_ryv_event_description',
					'label' => 'Description',
					'name'  => 'ryv_event_description',
					'type'  => 'textarea',
					'rows'  => 4,
				),
				array(
					'key'   => 'field_ryv_event_url',
					'label' => 'Event URL',
					'name'  => 'ryv_event_url',
					'type'  => 'url',
					'instructions' => 'Link to the event page or registration.',
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
