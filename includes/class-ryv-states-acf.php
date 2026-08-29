<?php
/**
 * ACF field group definitions for the ryv_states CPT.
 *
 * Two field groups:
 *   1. State Page Content — fields matching the Figma mockup sections
 *   2. Form Node Editor  — form selector + state selector + nodes repeater
 *
 * All field groups are registered via acf_add_local_field_group() so they
 * live in code, not in the DB — no JSON sync files needed.
 *
 * Requires ACF Pro (repeaters, relationship fields).
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_States_ACF
 */
class RYV_States_ACF {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'acf/init', array( $this, 'register_field_groups' ) );
		add_filter( 'acf/load_field/name=ryv_state_slug', array( $this, 'populate_state_slug_choices' ) );
	}

	/**
	 * Register all ACF field groups for ryv_states.
	 */
	public function register_field_groups() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		$this->register_form_connection_group();
		$this->register_map_settings_group();
		$this->register_hero_group();
		$this->register_short_answer_group();
		$this->register_at_a_glance_group();
		$this->register_partner_highlight_group();
		$this->register_events_group();
		$this->register_resources_group();
		$this->register_form_nodes_group();
	}

	/**
	 * Form Connection — links this state to a form and identifies which
	 * state slug it represents.
	 */
	private function register_form_connection_group() {
		acf_add_local_field_group( array(
			'key'      => 'group_ryv_form_connection',
			'title'    => 'Form Connection',
			'fields'   => array(
				array(
					'key'           => 'field_ryv_form_connection',
					'label'         => 'Eligibility Form',
					'name'          => 'ryv_form_connection',
					'type'          => 'post_object',
					'post_type'     => array( RYV_CPT::POST_TYPE ),
					'return_format' => 'id',
					'ui'            => 1,
					'instructions'  => 'Select the eligibility form this state belongs to.',
				),
				array(
					'key'           => 'field_ryv_state_slug',
					'label'         => 'State',
					'name'          => 'ryv_state_slug',
					'type'          => 'select',
					'choices'       => array(), // Populated dynamically.
					'ui'            => 1,
					'allow_null'    => 1,
					'instructions'  => 'Select the US state. Must match the state slug used in the form schema.',
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => RYV_States_CPT::POST_TYPE,
					),
				),
			),
			'position'     => 'side',
			'menu_order'   => 0,
			'style'        => 'default',
		) );
	}

	/**
	 * Map Settings — SVG map tooltip text and striped pattern flag for MapSVG.
	 */
	private function register_map_settings_group() {
		acf_add_local_field_group( array(
			'key'    => 'group_ryv_map_settings',
			'title'  => 'Map Settings',
			'fields' => array(
				array(
					'key'          => 'field_ryv_map_tooltip_text',
					'label'        => 'Map Tooltip Text',
					'name'         => 'ryv_map_tooltip_text',
					'type'         => 'textarea',
					'rows'         => 4,
					'instructions' => 'Shown on hover over the state on the map.',
				),
				array(
					'key'           => 'field_ryv_map_mixed_rules',
					'label'         => 'Mixed Rules (Striped Pattern)',
					'name'          => 'ryv_map_mixed_rules',
					'type'          => 'select',
					'choices'       => array(
						'0' => 'No',
						'1' => 'Yes',
					),
					'default_value' => '0',
					'allow_null'    => 0,
					'instructions'  => 'If yes, the state will display with a striped pattern on the map.',
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => RYV_States_CPT::POST_TYPE,
					),
				),
			),
			'menu_order'   => 0,
			'style'        => 'default',
			'label_placement' => 'top',
		) );
	}

	/**
	 * Hero Section — title, status badge, date, stats, featured image.
	 */
	private function register_hero_group() {
		acf_add_local_field_group( array(
			'key'    => 'group_ryv_hero',
			'title'  => 'Hero Section',
			'fields' => array(
				array(
					'key'          => 'field_ryv_hero_headline',
					'label'        => 'Headline',
					'name'         => 'ryv_hero_headline',
					'type'         => 'text',
					'instructions' => 'E.g. "Felony Voting Rights Restoration In Tennessee"',
				),
				array(
					'key'          => 'field_ryv_hero_status',
					'label'        => 'Status',
					'name'         => 'ryv_hero_status',
					'type'         => 'select',
					'choices'      => array(
						'active'   => 'Active',
						'inactive' => 'Inactive',
						'pending'  => 'Pending',
					),
					'default_value' => 'active',
					'allow_null'    => 0,
				),
				array(
					'key'          => 'field_ryv_hero_updated_date',
					'label'        => 'Last Updated',
					'name'         => 'ryv_hero_updated_date',
					'type'         => 'date_picker',
					'display_format' => 'F j, Y',
					'return_format'  => 'Y-m-d',
				),
				array(
					'key'          => 'field_ryv_hero_issue_tag',
					'label'        => 'Issue Tag',
					'name'         => 'ryv_hero_issue_tag',
					'type'         => 'text',
					'instructions' => 'E.g. "Voting Rights"',
				),
				array(
					'key'          => 'field_ryv_hero_sidebar_image',
					'label'        => 'Sidebar Graphic',
					'name'         => 'ryv_hero_sidebar_image',
					'type'         => 'image',
					'return_format' => 'array',
					'preview_size'  => 'medium',
				),
				array(
					'key'        => 'field_ryv_hero_stats',
					'label'      => 'Stats',
					'name'       => 'ryv_hero_stats',
					'type'       => 'repeater',
					'layout'     => 'table',
					'min'        => 0,
					'max'        => 5,
					'button_label' => 'Add Stat',
					'sub_fields' => array(
						array(
							'key'   => 'field_ryv_hero_stat_number',
							'label' => 'Number',
							'name'  => 'stat_number',
							'type'  => 'text',
							'instructions' => 'E.g. "470,000+"',
							'wrapper' => array( 'width' => '40' ),
						),
						array(
							'key'   => 'field_ryv_hero_stat_label',
							'label' => 'Label',
							'name'  => 'stat_label',
							'type'  => 'text',
							'instructions' => 'E.g. "People Affected"',
							'wrapper' => array( 'width' => '60' ),
						),
					),
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => RYV_States_CPT::POST_TYPE,
					),
				),
			),
			'menu_order' => 1,
			'style'      => 'default',
		) );
	}

	/**
	 * The Short Answer — summary text + CTA.
	 */
	private function register_short_answer_group() {
		acf_add_local_field_group( array(
			'key'    => 'group_ryv_short_answer',
			'title'  => 'The Short Answer',
			'fields' => array(
				array(
					'key'   => 'field_ryv_short_answer_text',
					'label' => 'Summary Text',
					'name'  => 'ryv_short_answer_text',
					'type'  => 'wysiwyg',
					'tabs'  => 'all',
					'media_upload' => 0,
				),
				array(
					'key'          => 'field_ryv_short_answer_cta_text',
					'label'        => 'CTA Button Text',
					'name'         => 'ryv_short_answer_cta_text',
					'type'         => 'text',
					'default_value' => 'Can I Vote?',
				),
				array(
					'key'   => 'field_ryv_short_answer_cta_url',
					'label' => 'CTA Button URL',
					'name'  => 'ryv_short_answer_cta_url',
					'type'  => 'url',
					'instructions' => 'Leave empty to scroll to the eligibility tool on the same page.',
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => RYV_States_CPT::POST_TYPE,
					),
				),
			),
			'menu_order' => 2,
			'style'      => 'default',
		) );
	}

	/**
	 * At A Glance — heading, body text, sidebar image.
	 */
	private function register_at_a_glance_group() {
		acf_add_local_field_group( array(
			'key'    => 'group_ryv_at_a_glance',
			'title'  => 'At A Glance',
			'fields' => array(
				array(
					'key'   => 'field_ryv_glance_heading',
					'label' => 'Heading',
					'name'  => 'ryv_glance_heading',
					'type'  => 'text',
				),
				array(
					'key'   => 'field_ryv_glance_body',
					'label' => 'Body Text',
					'name'  => 'ryv_glance_body',
					'type'  => 'wysiwyg',
					'tabs'  => 'all',
					'media_upload' => 1,
				),
				array(
					'key'           => 'field_ryv_glance_image',
					'label'         => 'Sidebar Image',
					'name'          => 'ryv_glance_image',
					'type'          => 'image',
					'return_format' => 'array',
					'preview_size'  => 'medium',
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => RYV_States_CPT::POST_TYPE,
					),
				),
			),
			'menu_order' => 3,
			'style'      => 'default',
		) );
	}

	/**
	 * Partner Highlight — featured partner, events, coalition logos.
	 */
	private function register_partner_highlight_group() {
		acf_add_local_field_group( array(
			'key'    => 'group_ryv_partner_highlight',
			'title'  => 'Partner Highlight',
			'fields' => array(
				// --- Featured Partner ---
				array(
					'key'   => 'field_ryv_partner_tab_featured',
					'label' => 'Featured Partner',
					'name'  => '',
					'type'  => 'tab',
				),
				array(
					'key'   => 'field_ryv_partner_name',
					'label' => 'Partner Name',
					'name'  => 'ryv_partner_name',
					'type'  => 'text',
				),
				array(
					'key'   => 'field_ryv_partner_location',
					'label' => 'Location',
					'name'  => 'ryv_partner_location',
					'type'  => 'text',
					'instructions' => 'E.g. "Nashville, Tennessee"',
				),
				array(
					'key'   => 'field_ryv_partner_description',
					'label' => 'Description',
					'name'  => 'ryv_partner_description',
					'type'  => 'wysiwyg',
					'tabs'  => 'all',
					'media_upload' => 0,
				),
				array(
					'key'   => 'field_ryv_partner_quote',
					'label' => 'Quote',
					'name'  => 'ryv_partner_quote',
					'type'  => 'textarea',
					'rows'  => 3,
				),
				array(
					'key'           => 'field_ryv_partner_image',
					'label'         => 'Image / Video Thumbnail',
					'name'          => 'ryv_partner_image',
					'type'          => 'image',
					'return_format' => 'array',
					'preview_size'  => 'medium',
				),
				array(
					'key'   => 'field_ryv_partner_video_url',
					'label' => 'Video URL',
					'name'  => 'ryv_partner_video_url',
					'type'  => 'url',
					'instructions' => 'YouTube or Vimeo URL. Leave empty to show image only.',
				),
				array(
					'key'   => 'field_ryv_partner_link_url',
					'label' => 'Partner Link URL',
					'name'  => 'ryv_partner_link_url',
					'type'  => 'url',
				),
				array(
					'key'   => 'field_ryv_partner_link_text',
					'label' => 'Partner Link Text',
					'name'  => 'ryv_partner_link_text',
					'type'  => 'text',
					'default_value' => 'Learn More',
				),

				// --- Coalition Partners ---
				array(
					'key'   => 'field_ryv_partner_tab_coalition',
					'label' => 'Coalition',
					'name'  => '',
					'type'  => 'tab',
				),
				array(
					'key'          => 'field_ryv_coalition_heading',
					'label'        => 'Coalition Heading',
					'name'         => 'ryv_coalition_heading',
					'type'         => 'text',
					'default_value' => 'Coalition',
				),
				array(
					'key'          => 'field_ryv_coalition_logos',
					'label'        => 'Coalition Partner Logos',
					'name'         => 'ryv_coalition_logos',
					'type'         => 'repeater',
					'layout'       => 'block',
					'button_label' => 'Add Partner Logo',
					'sub_fields'   => array(
						array(
							'key'           => 'field_ryv_coalition_logo_image',
							'label'         => 'Logo',
							'name'          => 'logo',
							'type'          => 'image',
							'return_format' => 'array',
							'preview_size'  => 'thumbnail',
							'wrapper'       => array( 'width' => '40' ),
						),
						array(
							'key'   => 'field_ryv_coalition_logo_name',
							'label' => 'Partner Name',
							'name'  => 'name',
							'type'  => 'text',
							'wrapper' => array( 'width' => '30' ),
						),
						array(
							'key'   => 'field_ryv_coalition_logo_url',
							'label' => 'URL',
							'name'  => 'url',
							'type'  => 'url',
							'wrapper' => array( 'width' => '30' ),
						),
					),
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => RYV_States_CPT::POST_TYPE,
					),
				),
			),
			'menu_order' => 4,
			'style'      => 'default',
		) );
	}

	/**
	 * Events — relationship field linking to ryv_events CPT.
	 */
	private function register_events_group() {
		acf_add_local_field_group( array(
			'key'    => 'group_ryv_events',
			'title'  => "What's Happening Locally",
			'fields' => array(
				array(
					'key'           => 'field_ryv_events',
					'label'         => 'Events',
					'name'          => 'ryv_events',
					'type'          => 'relationship',
					'post_type'     => array( RYV_Events_CPT::POST_TYPE ),
					'filters'       => array( 'search' ),
					'return_format' => 'id',
					'min'           => 0,
					'max'           => '',
					'instructions'  => 'Select events connected to this state. Events are managed in the separate Events post type.',
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => RYV_States_CPT::POST_TYPE,
					),
				),
			),
			'menu_order' => 5,
			'style'      => 'default',
		) );
	}

	/**
	 * Resources — relationship field linking to ryv_resources CPT.
	 */
	private function register_resources_group() {
		acf_add_local_field_group( array(
			'key'    => 'group_ryv_resources',
			'title'  => 'Resources',
			'fields' => array(
				array(
					'key'           => 'field_ryv_resources',
					'label'         => 'Resources',
					'name'          => 'ryv_resources',
					'type'          => 'relationship',
					'post_type'     => array( RYV_Resources_CPT::POST_TYPE ),
					'filters'       => array( 'search' ),
					'return_format' => 'id',
					'min'           => 0,
					'max'           => '',
					'instructions'  => 'Select resources connected to this state. Resources are managed in the separate Resources post type.',
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => RYV_States_CPT::POST_TYPE,
					),
				),
			),
			'menu_order' => 6,
			'style'      => 'default',
		) );
	}

	/**
	 * Form Nodes — repeater of all form nodes belonging to this state,
	 * loaded via AJAX from the schema, synced back on save.
	 */
	private function register_form_nodes_group() {
		acf_add_local_field_group( array(
			'key'    => 'group_ryv_form_nodes',
			'title'  => 'Form Nodes (Eligibility Questions)',
			'fields' => array(
				array(
					'key'          => 'field_ryv_form_nodes_message',
					'label'        => '',
					'name'         => '',
					'type'         => 'message',
					// The filter <input> is added client-side (states-admin.js)
					// rather than here: ACF's `message` field runs its content
					// through wp_kses_post() before output, which strips
					// <input> (unlike <button>, which survives - see the old
					// "Load Nodes" button this replaced) since form controls
					// aren't in post content's allowed-tags list.
					'message'      => '<p class="ryv-sa-intro">Questions load automatically once a Form and State are selected above, and stay in sync as you switch either one. Editing a field here and saving this post writes the change straight back into the form\'s schema - this is the same content the form builder edits, just scoped to this one state.</p>'
						. '<div id="ryv-load-nodes-toolbar" class="ryv-sa-toolbar" hidden>'
						. '<span id="ryv-load-nodes-status" class="ryv-sa-status" role="status"></span>'
						. '</div>',
				),
				array(
					'key'          => 'field_ryv_form_nodes',
					'label'        => 'Nodes',
					'name'         => 'ryv_form_nodes',
					'type'         => 'repeater',
					'layout'       => 'block',
					'button_label' => 'Add Node',
					'min'          => 0,
					'sub_fields'   => array(
						array(
							'key'      => 'field_ryv_node_id',
							'label'    => 'Node ID',
							'name'     => 'node_id',
							'type'     => 'text',
							'wrapper'  => array( 'width' => '30' ),
							'readonly' => 1,
						),
						array(
							'key'           => 'field_ryv_node_type',
							'label'         => 'Type',
							'name'          => 'node_type',
							'type'          => 'select',
							'choices'       => array(
								'radio'        => 'Radio',
								'checkbox'     => 'Checkbox',
								'text'         => 'Text',
								'textarea'     => 'Textarea',
								'markup'       => 'Markup',
								'lead_capture' => 'Lead Capture',
								'unsupported'  => 'Unsupported',
							),
							'wrapper'       => array( 'width' => '20' ),
						),
						array(
							'key'     => 'field_ryv_node_title',
							'label'   => 'Title',
							'name'    => 'node_title',
							'type'    => 'text',
							'wrapper' => array( 'width' => '50' ),
						),
						array(
							'key'          => 'field_ryv_node_content',
							'label'        => 'Content / HTML',
							'name'         => 'node_content',
							'type'         => 'wysiwyg',
							'tabs'         => 'all',
							'media_upload' => 0,
							'instructions' => 'For markup nodes, this is the body HTML. For choice nodes, this is optional instructional text shown above the choices.',
						),
						array(
							'key'     => 'field_ryv_node_help',
							'label'   => 'Help Text',
							'name'    => 'node_help',
							'type'    => 'textarea',
							'rows'    => 2,
							'wrapper' => array( 'width' => '50' ),
							'instructions' => 'Tooltip content shown via the "?" icon next to the title.',
						),
						array(
							'key'           => 'field_ryv_node_display_style',
							'label'         => 'Display Style',
							'name'          => 'node_display_style',
							'type'          => 'select',
							'choices'       => array(
								''        => '(Default)',
								'buttons' => 'Buttons',
								'dropdown' => 'Dropdown',
								'list'    => 'List',
							),
							'allow_null'    => 1,
							'wrapper'       => array( 'width' => '25' ),
						),
						array(
							'key'           => 'field_ryv_node_hidden',
							'label'         => 'Hidden',
							'name'          => 'node_hidden',
							'type'          => 'true_false',
							'ui'            => 1,
							'wrapper'       => array( 'width' => '25' ),
							'instructions'  => 'Hidden nodes are skipped during rendering but remain in the graph.',
						),
						array(
							'key'     => 'field_ryv_node_next_node',
							'label'   => 'Next Node',
							'name'    => 'node_next_node',
							'type'    => 'text',
							'instructions' => 'Node ID to advance to (for non-choice nodes).',
						),
						array(
							'key'     => 'field_ryv_node_reference_nodes',
							'label'   => 'Reference Node IDs',
							'name'    => 'node_reference_nodes',
							'type'    => 'text',
							'instructions' => 'Comma-separated list of node IDs whose content is shown alongside this node.',
						),
						// --- Options sub-repeater (for radio/checkbox nodes) ---
						array(
							'key'          => 'field_ryv_node_options',
							'label'        => 'Options',
							'name'         => 'node_options',
							'type'         => 'repeater',
							'layout'       => 'table',
							'button_label' => 'Add Option',
							'min'          => 0,
							'instructions' => 'Only applicable for radio/checkbox node types.',
							'sub_fields'   => array(
								array(
									'key'     => 'field_ryv_option_label',
									'label'   => 'Label',
									'name'    => 'option_label',
									'type'    => 'text',
									'wrapper' => array( 'width' => '30' ),
								),
								array(
									'key'     => 'field_ryv_option_value',
									'label'   => 'Value',
									'name'    => 'option_value',
									'type'    => 'text',
									'wrapper' => array( 'width' => '30' ),
								),
								array(
									'key'          => 'field_ryv_option_next_node',
									'label'        => 'Next Node',
									'name'         => 'option_next_node',
									'type'         => 'text',
									'wrapper'      => array( 'width' => '40' ),
									'instructions' => 'Node ID to route to when this option is selected.',
								),
							),
						),
					),
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => RYV_States_CPT::POST_TYPE,
					),
				),
			),
			'menu_order' => 10,
			'style'      => 'default',
		) );
	}

	/**
	 * Dynamically populate the state slug select with values from
	 * RYV_US_States::MAP.
	 *
	 * @param array $field ACF field definition.
	 * @return array
	 */
	public function populate_state_slug_choices( array $field ): array {
		$field['choices'] = array();

		foreach ( RYV_US_States::MAP as $slug => $label ) {
			$field['choices'][ $slug ] = $label;
		}

		return $field;
	}
}
