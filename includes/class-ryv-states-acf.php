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
	 * The 8 CLC-reference-map categories (slug/label/color/order) - the one
	 * canonical source both the options-page field's own default_value and
	 * maybe_seed_map_defaults()'s self-healing reconcile pull from, so the
	 * two can never drift apart.
	 */
	const DEFAULT_MAP_CATEGORIES = array(
		array( 'slug' => 'no-restrictions', 'label' => 'No Restrictions', 'color' => '#cfe7f5', 'order' => 1 ),
		array( 'slug' => 'prison-only', 'label' => 'Prison Only', 'color' => '#87b7cd', 'order' => 2 ),
		array( 'slug' => 'prison-parole-probation', 'label' => 'Prison, Parole, & Probation', 'color' => '#1b7a9e', 'order' => 3 ),
		array( 'slug' => 'prison-parole-probation-lfos', 'label' => 'Prison, Parole, & Probation, & LFOs', 'color' => '#1257a5', 'order' => 4 ),
		array( 'slug' => 'completion-plus-requirements', 'label' => 'Completion of Sentence Plus Additional Requirements', 'color' => '#0a2a43', 'order' => 5 ),
		array( 'slug' => 'permanent-multiple-felonies', 'label' => 'Permanent Disenfranchisement for Multiple Felonies', 'color' => '#f7c0a5', 'order' => 6 ),
		array( 'slug' => 'streamlined-executive-restoration', 'label' => 'Streamlined Executive Restoration', 'color' => '#e8431f', 'order' => 7 ),
		array( 'slug' => 'permanent-disenfranchisement', 'label' => 'Permanent Disenfranchisement', 'color' => '#8c2a15', 'order' => 8 ),
	);

	/**
	 * Bump whenever DEFAULT_MAP_CATEGORIES or RYV_States_Generator's CLC
	 * state-assignment tables change, so an already-provisioned site
	 * self-heals (wrong category color, a fixed label typo, a newly added
	 * state left uncategorized) the next time wp-admin loads, instead of a
	 * fresh install being the only case that ever gets seeded.
	 *
	 * NOTE: this alone does NOT re-categorize a state that already has a
	 * `ryv_map_category` set - assign_default_map_categories() only fills
	 * in states with no category yet, to avoid clobbering an admin's manual
	 * override (see its own docblock). A correction to which category an
	 * already-assigned state belongs in (as opposed to a category's own
	 * color/label) needs the "Auto-Populate Map Categories" admin button
	 * (force=true) or a one-off `assign_default_map_categories( true )`
	 * call to actually take effect on a site that was seeded before the
	 * correction.
	 */
	const MAP_DEFAULTS_VERSION = 4;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'acf/init', array( $this, 'register_field_groups' ) );
		add_action( 'acf/init', array( $this, 'register_options_pages' ) );
		add_filter( 'acf/load_field/name=ryv_state_slug', array( $this, 'populate_state_slug_choices' ) );
		add_filter( 'acf/load_field/name=ryv_map_category', array( $this, 'populate_map_category_choices' ) );
		add_filter( 'acf/load_field/key=field_ryv_node_next_node', array( $this, 'populate_next_node_choices' ) );
		add_filter( 'acf/load_field/key=field_ryv_option_next_node', array( $this, 'populate_next_node_choices' ) );
		add_action( 'after_setup_theme', array( $this, 'register_image_sizes' ) );
	}

	/**
	 * Small, cropped size for the Featured Partners repeater's `logo` sub
	 * field (see register_partner_highlight_group()) - a partner logo is a
	 * small fixed slot next to its name, never the full uploaded file the
	 * way the section's other (large, hero-style) images are. Registered
	 * here (fires on `after_setup_theme`, ahead of any front-end render)
	 * rather than left to a theme, since this size is specific to this
	 * plugin's own markup and must exist regardless of which theme is
	 * active.
	 */
	public function register_image_sizes() {
		add_image_size( 'ryv-partner-logo', 160, 160, false );
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
		$this->register_news_group();
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
					'key'           => 'field_ryv_map_category',
					'label'         => 'Map Category',
					'name'          => 'ryv_map_category',
					'type'          => 'select',
					'choices'       => array(), // Populated dynamically from Options page.
					'ui'            => 1,
					'allow_null'    => 1,
					'instructions'  => 'Determines the fill color on the state map. Categories are managed under RYV States → Map Settings.',
				),
				array(
					'key'          => 'field_ryv_map_tooltip_text',
					'label'        => 'Map Tooltip Text',
					'name'         => 'ryv_map_tooltip_text',
					'type'         => 'textarea',
					'rows'         => 4,
					'instructions' => 'Short 1–2 sentence summary shown on hover over the state on the map.',
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
					'instructions'  => 'If yes, the state will display with a diagonal striped pattern overlay on the map.',
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
				// --- Featured Partners (list) ---
				// The section's actual source of content, rendered by
				// RYV_Featured_Partners_Renderer. Registered FIRST/default-
				// active tab deliberately - the legacy single-partner tab
				// below it is CSS-hidden from the edit screen entirely (see
				// assets/css/admin/states-admin.css's
				// `[data-key="field_ryv_partner_tab_featured"]` rule), so it
				// must not be left as ACF's default-open tab or an admin
				// would land on a blank-looking screen.
				array(
					'key'   => 'field_ryv_partner_tab_list',
					'label' => 'Featured Partners',
					'name'  => '',
					'type'  => 'tab',
				),
				// The big left-hand photo is the *section's* image, not any
				// one partner's - it's the client-provided group photo the
				// whole "Featured Partner(s)" block sits next to, unrelated
				// to which (or how many) partners are listed next to it.
				// Its own field, deliberately separate from both the
				// legacy `ryv_partner_image` (kept only as a migration
				// source/fallback - see RYV_Partners_Migration) and from
				// any partner's own `logo` sub-field (a small per-partner
				// mark, not this photo).
				array(
					'key'           => 'field_ryv_featured_partners_image',
					'label'         => 'Main Image',
					'name'          => 'featured_partners_image',
					'type'          => 'image',
					'return_format' => 'id',
					'preview_size'  => 'medium',
					'instructions'  => 'The large photo next to the Featured Partners list - one image for the whole section, not per partner. Falls back to the legacy "Image / Video Thumbnail" field above while this is empty.',
				),
				array(
					'key'          => 'field_ryv_featured_partners',
					'label'        => 'Featured Partners',
					'name'         => 'featured_partners',
					'type'         => 'repeater',
					'layout'       => 'block',
					'button_label' => 'Add Partner',
					'instructions' => 'One full card per partner - logo, name, location, description, quote, and a link to their site. Every field is optional except Partner Name; an empty field simply does not render for that card (see RYV_Featured_Partners_Renderer). Shown instead of the legacy single-partner fields above once at least one row exists.',
					'sub_fields'   => array(
						array(
							'key'           => 'field_ryv_featured_partner_logo',
							'label'         => 'Logo',
							'name'          => 'logo',
							'type'          => 'image',
							'return_format' => 'id',
							'preview_size'  => 'thumbnail',
							'wrapper'       => array( 'width' => '25' ),
						),
						array(
							'key'      => 'field_ryv_featured_partner_name',
							'label'    => 'Partner Name',
							'name'     => 'name',
							'type'     => 'text',
							'required' => 1,
							'wrapper'  => array( 'width' => '35' ),
						),
						array(
							'key'    => 'field_ryv_featured_partner_url',
							'label'  => 'Website URL',
							'name'   => 'url',
							'type'   => 'url',
							'wrapper' => array( 'width' => '40' ),
						),
						array(
							'key'          => 'field_ryv_featured_partner_location',
							'label'        => 'Location',
							'name'         => 'location',
							'type'         => 'text',
							'instructions' => 'E.g. "Nashville · led by formerly incarcerated women"',
						),
						array(
							'key'          => 'field_ryv_featured_partner_description',
							'label'        => 'Description',
							'name'         => 'description',
							'type'         => 'wysiwyg',
							'tabs'         => 'all',
							'media_upload' => 0,
						),
						array(
							'key'   => 'field_ryv_featured_partner_quote',
							'label' => 'Quote',
							'name'  => 'quote',
							'type'  => 'textarea',
							'rows'  => 3,
						),
					),
				),

				// General description/quote for the whole Featured Partners
				// list, not tied to any one partner - renders once, below
				// every partner card, via
				// RYV_Featured_Partners_Renderer::render_general_note().
				// Both optional and independent of each partner's own
				// description/quote above: a state can use only this shared
				// pair, only per-partner ones, or both at once.
				array(
					'key'          => 'field_ryv_featured_partners_description',
					'label'        => 'General Description',
					'name'         => 'featured_partners_description',
					'type'         => 'wysiwyg',
					'tabs'         => 'all',
					'media_upload' => 0,
					'instructions' => 'Optional. Shown once below all Featured Partners cards, not attached to any single partner.',
				),
				array(
					'key'          => 'field_ryv_featured_partners_quote',
					'label'        => 'General Quote',
					'name'         => 'featured_partners_quote',
					'type'         => 'textarea',
					'rows'         => 3,
					'instructions' => 'Optional. Shown once below all Featured Partners cards, not attached to any single partner.',
				),

				// --- Featured Partner (legacy) ---
				// Hidden from the edit screen entirely via
				// assets/css/admin/states-admin.css - this whole tab is
				// display:none, not something an admin can open. Every
				// field stays fully registered on purpose: nothing here is
				// unregistered/removed, because RYV_Featured_Partners_Renderer's
				// legacy fallback and RYV_Partners_Migration both still read
				// these via get_field(), which needs a live field
				// registration to resolve correctly (an unregistered key
				// can return null instead of the stored value for some
				// field types). Their stored postmeta is untouched either
				// way; this is a display-only change.
				array(
					'key'   => 'field_ryv_partner_tab_featured',
					'label' => 'Featured Partner (legacy, hidden)',
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
	 * Events — relationship field linking to The Events Calendar's
	 * `tribe_events` CPT. Previously pointed at this plugin's own
	 * `ryv_events` CPT (RYV_Events_CPT, now removed) - event management
	 * moved to The Events Calendar so this plugin doesn't duplicate a
	 * generic events feature. `tribe_events` isn't registered at all until
	 * that plugin is active, so this field's picker is simply empty on a
	 * site without it - same degrade-gracefully behavior as any other ACF
	 * relationship field pointed at an inactive post type.
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
					'post_type'     => array( RYV_State_Queries::EVENTS_POST_TYPE ),
					'filters'       => array( 'search' ),
					'return_format' => 'id',
					'min'           => 0,
					'max'           => '',
					'instructions'  => 'Select events connected to this state. Events are managed by The Events Calendar.',
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
	 * News Categories - which blog `category` term(s) feed this state's
	 * "Latest News & Updates" section (RYV_State_Queries::ryv_state_news).
	 *
	 * Optional and admin-curated, same shape as Events/Resources above,
	 * rather than relying solely on the automatic slug match
	 * (`get_state_category_ids()`'s fallback): a state's category doesn't
	 * always share its exact slug (naming drift, a shared/renamed category,
	 * more than one category feeding one state), and an admin should be able
	 * to fix that from the state's own edit screen instead of a database
	 * change.
	 *
	 * `save_terms`/`load_terms` are both off on purpose: this field is just
	 * picking which categories to *query*, not tagging the `ryv_states` post
	 * itself with them (which isn't even a `category`-enabled post type) -
	 * ACF stores the picked ids as plain postmeta either way.
	 */
	private function register_news_group() {
		acf_add_local_field_group( array(
			'key'    => 'group_ryv_news',
			'title'  => 'Latest News & Updates',
			'fields' => array(
				array(
					'key'           => 'field_ryv_news_categories',
					'label'         => 'News Categories',
					'name'          => 'ryv_news_categories',
					'type'          => 'taxonomy',
					'taxonomy'      => 'category',
					'field_type'    => 'checkbox',
					'add_term'      => 0,
					'save_terms'    => 0,
					'load_terms'    => 0,
					'return_format' => 'id',
					'multiple'      => 1,
					'allow_null'    => 1,
					'instructions'  => 'Optional. Pick which blog categories populate this state\'s "Latest News & Updates" section. Leave blank to auto-match a category whose slug matches this state (e.g. a "Kentucky" category on the Kentucky page).',
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
			'menu_order' => 7,
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
							'key'          => 'field_ryv_node_id',
							'label'        => 'Node ID',
							'name'         => 'node_id',
							'type'         => 'text',
							'wrapper'      => array( 'width' => '30' ),
							// Editable so "Add Node" can create a brand-new question
							// directly from this screen - see
							// RYV_States_Sync::sync_nodes_to_schema(), which keys off
							// this value and field_ryv_node_original_id below to tell
							// a genuinely new node apart from a rename of an existing
							// one (and rewrites every reference on a safe rename, the
							// same as the form builder's renameNode()).
							'instructions' => 'Unique ID for this question. Letters, numbers, and underscores only. Leave the default (from RYV Forms) alone unless you mean to rename this exact question everywhere it\'s referenced.',
						),
						array(
							'key'        => 'field_ryv_node_original_id',
							'label'      => 'Original Node ID',
							'name'       => 'node_original_id',
							// NOT 'hidden' - ACF has no such field type (there is no
							// class-acf-field-hidden.php in core or Pro), so a field
							// declared with it never rendered a real, submittable
							// input at all: its key was completely absent from
							// $_POST on every save, no matter how the row was
							// populated. resolve_row_node_id() reads this field to
							// tell "editing an existing node" apart from "a brand-new
							// Add Node row"; with it always empty, every row looked
							// brand-new, and since its typed id already existed as a
							// node, the row was silently skipped in full - no field
							// edit on any existing row (Step Name included) could
							// ever be saved from this screen. 'text' is a real field
							// type that actually submits; the existing
							// `.acf-field[data-key="field_ryv_node_original_id"]
							// { display: none !important; }` rule in
							// states-admin.css already keeps it invisible either way.
							'type'       => 'text',
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
							'key'          => 'field_ryv_node_step_name',
							'label'        => 'Step Name',
							'name'         => 'node_step_name',
							'type'         => 'text',
							'wrapper'      => array( 'width' => '50' ),
							'instructions' => 'Shown to visitors in the widget\'s step sidebar. Leave blank to keep this node folded into the previous named step instead of starting a new one.',
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
							// Values must match what renderQuestionCard()/
							// renderSingleChoiceOptions() in renderer.js actually
							// switch on ('buttons' | 'radio' | 'dropdown') - and
							// the admin builder SPA's own DISPLAY_STYLE_OPTIONS
							// (NodeEditor.js) - not an independent label set.
							// A stored value that doesn't match one of these
							// falls through to the 'buttons' default silently.
							'choices'       => array(
								''        => '(Default)',
								'buttons' => 'Buttons',
								'radio'   => 'Radio buttons',
								'dropdown' => 'Dropdown',
							),
							'allow_null'    => 1,
							'wrapper'       => array( 'width' => '25' ),
							'instructions'  => 'Only applies to Radio nodes - Checkbox questions always render as buttons regardless of this setting.',
							// Matches the admin builder SPA: display_style only
							// ever applies to single-choice (radio) questions,
							// never checkbox - see renderQuestionCard() in
							// renderer.js. NOT conditional_logic-gated - see the
							// note on field_ryv_node_next_node below: ACF only
							// re-evaluates conditional_logic on a real 'change'
							// event on the trigger field, which states-admin.js's
							// populateRepeater() (field.val(), not a user click)
							// never fires - so a freshly AJAX-synced row showed
							// whichever fields matched the *clone template's*
							// default type, not the row's actual type, until the
							// admin manually touched the Type dropdown (exactly
							// the reported symptom: wrong/missing fields on first
							// load, fixed by switching Type). Visibility is
							// instead handled in JS by
							// toggleTypeConditionalFields().
						),
						array(
							'key'           => 'field_ryv_node_hidden',
							'label'         => 'Hidden',
							'name'          => 'node_hidden',
							'type'          => 'true_false',
							'ui'            => 1,
							'wrapper'       => array( 'width' => '25' ),
							'instructions'  => 'Hidden nodes are skipped during rendering but remain in the graph.',
							// Hiding a choice/text/lead_capture node would strand a
							// visitor with no way to ever answer it - only markup
							// and unsupported nodes support being hidden. NOT
							// conditional_logic-gated - see
							// toggleTypeConditionalFields() note below.
						),
						array(
							'key'          => 'field_ryv_node_next_node',
							'label'        => 'Next Node',
							'name'         => 'node_next_node',
							'type'         => 'select',
							'ui'           => 1,
							'allow_null'   => 1,
							'choices'      => array(),
							// Populated dynamically by populate_next_node_choices() from
							// the connected form's own node list - same validated,
							// typo-proof dropdown the RYV Forms builder uses, instead
							// of a free-text field a visitor could route to a
							// nonexistent id with no warning.
							'instructions' => 'Node ID to advance to (for non-choice nodes). Not shown for Radio/Checkbox questions, which route via their own options instead.',
							// Deliberately NOT conditional_logic-gated on type, unlike
							// display_style/reference_nodes above: this is a select2
							// (`ui => 1`) field, and ACF disables (not just CSS-hides)
							// a select2 input while its conditional_logic evaluates to
							// "hidden" - a disabled input never submits at all. A row
							// populated by states-admin.js's populateRepeater() via
							// field.val() never gets ACF's conditional-logic
							// recalculation re-run after node_type is set, so it stays
							// stuck disabled from the clone template's default type
							// forever - silently dropping this field from $_POST on
							// every subsequent save and wiping real next_node routing
							// (confirmed: wiped northam_exec/virginia_oos and 4 more
							// Virginia nodes' routing in production). Hiding it in JS
							// instead (see toggleNextNodeVisibility() in
							// states-admin.js) keeps the same UX without disabling the
							// input.
						),
						array(
							'key'     => 'field_ryv_node_reference_nodes',
							'label'   => 'Reference Node IDs',
							'name'    => 'node_reference_nodes',
							'type'    => 'text',
							'instructions' => 'Comma-separated list of node IDs whose content is shown alongside this node.',
							// Only choice nodes can carry reference content
							// (see RYV_Eligibility_Tool CLAUDE.md's reference_node_ids).
							// NOT conditional_logic-gated - see
							// toggleTypeConditionalFields() note below.
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
							// NOT conditional_logic-gated - ACF only recalculates
							// conditional_logic on a real 'change' event on the
							// trigger field (field_ryv_node_type), which
							// states-admin.js's populateRepeater() (field.val(),
							// not a genuine user interaction) never fires. A
							// freshly AJAX-synced row therefore showed/hid fields
							// based on the clone template's default type, not the
							// row's actual populated type, until the admin
							// manually touched the Type dropdown - reported live
							// as "wrong fields on first load, fixed by switching
							// type". toggleTypeConditionalFields() in
							// states-admin.js replaces this: it show/hides
							// display_style, node_hidden, node_reference_nodes and
							// this options repeater in plain JS/CSS from the
							// row's actual current type, run right after
							// populateRepeater() sets it and again on every manual
							// Type change - never relying on ACF's own
							// conditional_logic recalculation.
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
									'type'         => 'select',
									'ui'           => 1,
									'allow_null'   => 1,
									'choices'      => array(),
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
	 * Register the "Map Settings" ACF options sub-page under the RYV States menu.
	 *
	 * Contains a repeater of map categories (slug, label, hex color) that
	 * drive the legend and per-state fill color on the interactive map.
	 */
	public function register_options_pages() {
		if ( ! function_exists( 'acf_add_options_sub_page' ) ) {
			return;
		}

		acf_add_options_sub_page( array(
			'page_title'  => __( 'Map Settings', 'ryv-eligibility-tool' ),
			'menu_title'  => __( 'Map Settings', 'ryv-eligibility-tool' ),
			'menu_slug'   => 'ryv-map-settings',
			'parent_slug' => 'edit.php?post_type=' . RYV_States_CPT::POST_TYPE,
			'capability'  => 'manage_options',
		) );

		acf_add_local_field_group( array(
			'key'    => 'group_ryv_map_options',
			'title'  => 'Map Categories',
			'fields' => array(
				array(
					'key'          => 'field_ryv_map_id',
					'label'        => 'MapSVG Map ID',
					'name'         => 'ryv_mapsvg_map_id',
					'type'         => 'number',
					'default_value' => 1,
					'instructions' => 'The MapSVG map ID to sync region data to. Find it in the MapSVG admin URL: map_id=X.',
				),
				array(
					'key'          => 'field_ryv_map_categories',
					'label'        => 'Categories',
					'name'         => 'ryv_map_categories',
					'type'         => 'repeater',
					'layout'       => 'table',
					'button_label' => 'Add Category',
					'min'          => 1,
					/*
					 * The 8 CLC-reference-map categories, so a fresh install
					 * (or a repeater reset back to empty) starts with the
					 * real category set rather than one blank row. This is
					 * an ACF *default value* - it only applies while the
					 * option has never been explicitly saved; it is not
					 * re-applied over an admin's own edits. See
					 * maybe_seed_map_defaults() for the self-healing path
					 * that also reconciles this into an already-provisioned
					 * site's saved option value.
					 */
					'default_value' => self::DEFAULT_MAP_CATEGORIES,
					'instructions' => 'Define the categories shown in the map legend. The slug is used internally; the label is shown to visitors.',
					'sub_fields'   => array(
						array(
							'key'     => 'field_ryv_map_cat_slug',
							'label'   => 'Slug',
							'name'    => 'slug',
							'type'    => 'text',
							'wrapper' => array( 'width' => '25' ),
							'instructions' => 'Unique identifier, e.g. "no-restrictions"',
						),
						array(
							'key'     => 'field_ryv_map_cat_label',
							'label'   => 'Label',
							'name'    => 'label',
							'type'    => 'text',
							'wrapper' => array( 'width' => '35' ),
							'instructions' => 'Display name in the legend',
						),
						array(
							'key'           => 'field_ryv_map_cat_color',
							'label'         => 'Color',
							'name'          => 'color',
							'type'          => 'color_picker',
							'default_value' => '#cccccc',
							'wrapper'       => array( 'width' => '20' ),
						),
						array(
							'key'           => 'field_ryv_map_cat_order',
							'label'         => 'Order',
							'name'          => 'order',
							'type'          => 'number',
							'default_value' => 0,
							'wrapper'       => array( 'width' => '20' ),
							'instructions'  => 'Legend display order (lower = first)',
						),
					),
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'options_page',
						'operator' => '==',
						'value'    => 'ryv-map-settings',
					),
				),
			),
			'style' => 'default',
		) );
	}

	/**
	 * One-shot (per MAP_DEFAULTS_VERSION bump) reconciliation: makes sure
	 * the map's 8 canonical categories exist with the right color/label,
	 * every ryv_states post matching RYV_States_Generator's CLC reference
	 * table has a category assigned, and MapSVG is synced to match - so
	 * getting the map into its correct state is a function that runs
	 * itself on the next wp-admin load rather than a one-off WP-CLI/DB task
	 * someone has to remember to run.
	 *
	 * Hooked on `admin_init` (see RYV_Plugin::init_hooks()). Safe to run on
	 * every request: the version-option guard makes it a single
	 * get_option() read once caught up.
	 *
	 * @param bool $force Bypass the version guard and reconcile/re-sync
	 *                     regardless - used by the manual "Auto-Populate
	 *                     Map Categories" admin button.
	 */
	public static function maybe_seed_map_defaults( bool $force = false ) {
		if ( ! $force && (int) get_option( 'ryv_map_defaults_version', 0 ) >= self::MAP_DEFAULTS_VERSION ) {
			return;
		}

		self::reconcile_map_categories();

		if ( class_exists( 'RYV_States_Generator' ) ) {
			RYV_States_Generator::assign_default_map_categories( $force );
		}

		if ( class_exists( 'RYV_MapSVG_Sync' ) ) {
			RYV_MapSVG_Sync::sync_all();
		}

		update_option( 'ryv_map_defaults_version', self::MAP_DEFAULTS_VERSION );
	}

	/**
	 * Upsert DEFAULT_MAP_CATEGORIES into the options-page repeater by slug.
	 * The 8 canonical categories always get their color/label/order
	 * corrected - they're a fixed design system, not admin-editable content
	 * - while any *extra* category an admin added on top (a non-canonical
	 * slug) is left untouched.
	 */
	private static function reconcile_map_categories() {
		$existing = get_field( 'ryv_map_categories', 'option' );
		$existing = is_array( $existing ) ? $existing : array();

		$by_slug = array();
		foreach ( $existing as $row ) {
			$slug = $row['slug'] ?? '';
			if ( '' !== $slug ) {
				$by_slug[ $slug ] = $row;
			}
		}

		foreach ( self::DEFAULT_MAP_CATEGORIES as $canonical ) {
			$by_slug[ $canonical['slug'] ] = $canonical;
		}

		update_field( 'field_ryv_map_categories', array_values( $by_slug ), 'option' );

		if ( ! get_field( 'ryv_mapsvg_map_id', 'option' ) ) {
			update_field( 'field_ryv_map_id', 1, 'option' );
		}
	}

	/**
	 * Retrieve all map categories from the Options page.
	 *
	 * @return array[] Each entry has keys: slug, label, color, order.
	 */
	public static function get_map_categories(): array {
		$rows = get_field( 'ryv_map_categories', 'option' );

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		// Sort by order field.
		usort( $rows, function ( $a, $b ) {
			return ( (int) ( $a['order'] ?? 0 ) ) - ( (int) ( $b['order'] ?? 0 ) );
		} );

		return $rows;
	}

	/**
	 * Get the hex color for a given map category slug.
	 *
	 * @param string $category_slug Category slug.
	 * @return string|null Hex color or null.
	 */
	public static function get_category_color( string $category_slug ): ?string {
		foreach ( self::get_map_categories() as $cat ) {
			if ( ( $cat['slug'] ?? '' ) === $category_slug ) {
				return $cat['color'] ?? null;
			}
		}
		return null;
	}

	/**
	 * Get the display label for a given map category slug.
	 *
	 * @param string $category_slug Category slug.
	 * @return string|null Label or null.
	 */
	public static function get_category_label( string $category_slug ): ?string {
		foreach ( self::get_map_categories() as $cat ) {
			if ( ( $cat['slug'] ?? '' ) === $category_slug ) {
				return $cat['label'] ?? null;
			}
		}
		return null;
	}

	/**
	 * Dynamically populate the map category select with values from
	 * the Options page repeater.
	 *
	 * @param array $field ACF field definition.
	 * @return array
	 */
	public function populate_map_category_choices( array $field ): array {
		$field['choices'] = array();

		foreach ( self::get_map_categories() as $cat ) {
			$slug  = $cat['slug'] ?? '';
			$label = $cat['label'] ?? $slug;

			if ( $slug ) {
				$field['choices'][ $slug ] = $label;
			}
		}

		return $field;
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

	/**
	 * Dynamically populate a "Next Node" select (both the node-level field and
	 * the one inside the options sub-repeater) with every node id actually in
	 * the connected form's schema - the same validated dropdown the RYV Forms
	 * builder gives, so a question can only ever be routed to a node that
	 * really exists. Runs on the normal post-edit page load and on ACF's own
	 * AJAX row-add request (adding an Option or a new Node), so a freshly
	 * added row gets real choices immediately rather than an empty list.
	 *
	 * @param array $field ACF field definition.
	 * @return array
	 */
	public function populate_next_node_choices( array $field ): array {
		$post_id = 0;

		if ( ! empty( $_POST['post_id'] ) ) {
			$post_id = absint( $_POST['post_id'] );
		} elseif ( ! empty( $_GET['post'] ) ) {
			$post_id = absint( $_GET['post'] );
		} elseif ( function_exists( 'get_the_ID' ) && get_the_ID() ) {
			$post_id = get_the_ID();
		}

		$field['choices'] = array( '' => '— End —' );

		if ( ! $post_id ) {
			return $field;
		}

		$form_id = (int) get_field( 'ryv_form_connection', $post_id );

		if ( ! $form_id ) {
			return $field;
		}

		$schema = RYV_CPT::get_schema( $form_id );

		foreach ( ( $schema['nodes'] ?? array() ) as $node_id => $node ) {
			$label = $node_id;

			if ( ! empty( $node['title'] ) ) {
				$label .= ' — ' . wp_strip_all_tags( $node['title'] );
			}

			$field['choices'][ $node_id ] = $label;
		}

		return $field;
	}
}
