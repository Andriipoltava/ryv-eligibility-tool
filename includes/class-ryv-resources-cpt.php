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

	const TAXONOMY = 'ryv_resource_category';

	/**
	 * "What kind of resource is this" - the type half of a card's tags
	 * ("GUIDE · PDF"). Registered here in code.
	 */
	const TYPE_TAXONOMY = 'ryv_resource_type';

	/**
	 * "Which state is this for" - the state half ("ALL STATES" /
	 * "TENNESSEE"). Registered from the ACF UI (ACF → Taxonomies), not in
	 * code; this class only seeds its terms and adjusts its admin args.
	 */
	const STATE_TAXONOMY = 'resource-state';

	/**
	 * Slug of the "applies everywhere" state term. A resource tagged with it
	 * matches every state in the Resources page's state filter.
	 */
	const ALL_STATES = 'all-states';

	/**
	 * Resource type terms seeded on first run, slug => name.
	 */
	const DEFAULT_TYPES = array(
		'pdf'           => 'PDF',
		'guide'         => 'Guide',
		'resource-link' => 'Resource Link',
		'tool-kit'      => 'Tool Kit',
		'fact-sheet'    => 'Fact Sheet',
	);

	/**
	 * Spanish display names for the type terms. The taxonomies aren't
	 * translated per language in WPML (one shared set of checkboxes, so an
	 * editor never has to tick the same thing twice) - the Spanish pages
	 * just show these labels instead.
	 */
	const SPANISH_TYPE_NAMES = array(
		'pdf'           => 'PDF',
		'guide'         => 'Guía',
		'resource-link' => 'Enlace',
		'tool-kit'      => 'Kit de herramientas',
		'fact-sheet'    => 'Hoja informativa',
	);

	/**
	 * Bump to re-run maybe_seed_resource_tag_terms() on the next request.
	 */
	const TAG_TERMS_VERSION = '1';

	/**
	 * Default terms seeded on first registration, keyed by slug.
	 *
	 * Matches the categories the old ACF select field used to hardcode, so
	 * existing usage (and the front-end filter tabs) keeps working.
	 *
	 * @var array
	 */
	const DEFAULT_TERMS = array(
		'guide'        => 'Guide',
		'form'         => 'Form',
		'video'        => 'Video',
		'for-partners' => 'For Partners',
		'for-press'    => 'For Press',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_taxonomy' ), 0 );
		add_action( 'init', array( $this, 'register_type_taxonomy' ), 0 );
		add_action( 'init', array( $this, 'register_post_type' ) );
		// Same backstop RYV_CPT applies to `ryv_forms`: a post with no WPML
		// language row is excluded by the INNER JOIN every language-filtered
		// query uses, which for this post type means silently vanishing from
		// a state page's own related list on the frontend.
		add_action( 'save_post_' . self::POST_TYPE, array( 'RYV_CPT', 'assign_default_language_if_missing' ) );
		add_action( 'init', array( $this, 'maybe_seed_default_terms' ), 20 );
		add_action( 'init', array( $this, 'maybe_seed_resource_tag_terms' ), 21 );
		add_filter( 'register_taxonomy_args', array( $this, 'adjust_state_taxonomy_args' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_column' ), 10, 2 );

		if ( function_exists( 'acf_add_local_field_group' ) ) {
			add_action( 'acf/init', array( $this, 'register_acf_fields' ) );
			add_action( 'acf/save_post', array( $this, 'sync_cta_text_meta' ), 20 );
		}
	}

	/**
	 * Preset CTA button labels, keyed by the value stored in
	 * `ryv_resource_cta_preset` - offered as quick picks (matching the
	 * verbs already in use: "Download", "Get The Toolkit", "Explore The
	 * Data") alongside the free-text `ryv_resource_cta_custom` field for
	 * anything else, rather than forcing every resource into one of a
	 * fixed set of labels.
	 *
	 * @return array<string,string>
	 */
	public static function cta_presets(): array {
		return array(
			'download'      => __( 'Download', 'ryv-eligibility-tool' ),
			'get-toolkit'   => __( 'Get The Toolkit', 'ryv-eligibility-tool' ),
			'explore-data'  => __( 'Explore The Data', 'ryv-eligibility-tool' ),
			'learn-more'    => __( 'Learn More', 'ryv-eligibility-tool' ),
			'view-resource' => __( 'View Resource', 'ryv-eligibility-tool' ),
			'get-started'   => __( 'Get Started', 'ryv-eligibility-tool' ),
		);
	}

	/**
	 * Resolves a resource's chosen preset/custom CTA fields down to the
	 * one string a template actually needs to print on the button - the
	 * one place that knows "custom" means read the other field instead of
	 * the preset's own label, so no template has to duplicate that branch.
	 *
	 * @param int $post_id Resource post ID.
	 * @return string
	 */
	public static function get_cta_text( int $post_id ): string {
		if ( ! function_exists( 'get_field' ) ) {
			return '';
		}

		$preset = get_field( 'ryv_resource_cta_preset', $post_id );

		if ( 'custom' === $preset ) {
			return trim( (string) get_field( 'ryv_resource_cta_custom', $post_id ) );
		}

		$presets = self::cta_presets();
		return $presets[ $preset ] ?? '';
	}

	/**
	 * Mirrors get_cta_text()'s resolved value onto a plain (non-ACF)
	 * `_ryv_resource_cta_text` postmeta key on every save, so it's directly
	 * usable from Elementor's built-in "Post Meta"/"Custom Field" dynamic
	 * tag (or any other plain-meta reader) without needing a bespoke
	 * dynamic tag registered for it - a widget just points at that meta key
	 * and always gets the already-resolved preset-or-custom text, never the
	 * raw "custom" sentinel value.
	 *
	 * @param int $post_id Post ID being saved (ACF passes this for every
	 *                      post type, not just ryv_resources - bail early
	 *                      for anything else).
	 */
	public function sync_cta_text_meta( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, '_ryv_resource_cta_text', self::get_cta_text( (int) $post_id ) );
	}

	/**
	 * Register the ryv_resource_category taxonomy.
	 */
	public function register_taxonomy() {
		$labels = array(
			'name'          => 'Resource Categories',
			'singular_name' => 'Resource Category',
			'search_items'  => 'Search Categories',
			'all_items'     => 'All Categories',
			'edit_item'     => 'Edit Category',
			'update_item'   => 'Update Category',
			'add_new_item'  => 'Add New Category',
			'new_item_name' => 'New Category Name',
			'menu_name'     => 'Categories',
		);

		register_taxonomy( self::TAXONOMY, self::POST_TYPE, array(
			'labels'            => $labels,
			'hierarchical'      => true,
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			// Must stay true, and not for nav menus' sake: Elementor Pro's
			// query control builds its "Term" filter from
			// `get_taxonomies( [ 'show_in_nav_menus' => true ] )` (see
			// elementor-pro/modules/query-control/controls/group-control-posts.php),
			// so with this off the taxonomy is simply absent from the Posts/
			// Loop Grid query dropdown and there's no way to filter resources
			// by category in the editor at all. The only cost of true is that
			// the taxonomy also becomes available under Appearance → Menus.
			'show_in_nav_menus' => true,
			'rewrite'           => array( 'slug' => 'resource-category', 'with_front' => false ),
		) );
	}

	/**
	 * Register the ryv_resource_type taxonomy - checkbox UI (hierarchical)
	 * like Categories, and an admin list column.
	 */
	public function register_type_taxonomy() {
		register_taxonomy(
			self::TYPE_TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => 'Resource Types',
					'singular_name' => 'Resource Type',
					'search_items'  => 'Search Resource Types',
					'all_items'     => 'All Resource Types',
					'edit_item'     => 'Edit Resource Type',
					'update_item'   => 'Update Resource Type',
					'add_new_item'  => 'Add New Resource Type',
					'new_item_name' => 'New Resource Type Name',
					'menu_name'     => 'Resource Types',
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				// Same reason as the category taxonomy above: Elementor Pro's
				// query/filter controls only list show_in_nav_menus taxonomies.
				'show_in_nav_menus' => true,
				'rewrite'           => array( 'slug' => 'resource-type', 'with_front' => false ),
			)
		);
	}

	/**
	 * The ACF-registered state taxonomy: force the checkbox UI and an admin
	 * list column, so picking states works exactly like picking categories
	 * whatever its ACF UI settings say.
	 *
	 * @param array  $args     Taxonomy args.
	 * @param string $taxonomy Taxonomy being registered.
	 * @return array
	 */
	public function adjust_state_taxonomy_args( $args, $taxonomy ) {
		if ( self::STATE_TAXONOMY === $taxonomy ) {
			$args['hierarchical']      = true;
			$args['show_admin_column'] = true;
		}
		return $args;
	}

	/**
	 * Seed the Resource Type terms and the "All States" + 50 state terms,
	 * once per TAG_TERMS_VERSION (51+ term_exists() calls are not something
	 * to run on every request). Existing terms are never renamed or removed.
	 */
	public function maybe_seed_resource_tag_terms() {
		if ( get_option( 'ryv_resource_tag_terms_version' ) === self::TAG_TERMS_VERSION || ! taxonomy_exists( self::STATE_TAXONOMY ) ) {
			return;
		}

		foreach ( self::DEFAULT_TYPES as $slug => $name ) {
			if ( ! term_exists( $slug, self::TYPE_TAXONOMY ) ) {
				wp_insert_term( $name, self::TYPE_TAXONOMY, array( 'slug' => $slug ) );
			}
		}

		$states = array( self::ALL_STATES => 'All States' ) + RYV_US_States::MAP;
		unset( $states['district-of-columbia'] );

		foreach ( $states as $slug => $name ) {
			if ( ! term_exists( $slug, self::STATE_TAXONOMY ) ) {
				wp_insert_term( $name, self::STATE_TAXONOMY, array( 'slug' => $slug ) );
			}
		}

		update_option( 'ryv_resource_tag_terms_version', self::TAG_TERMS_VERSION, false );
	}

	/**
	 * A term's display name in the language being viewed - Spanish type and
	 * state names come from the maps above / RYV_Resources_Search, since
	 * these taxonomies share one set of terms across languages.
	 *
	 * @param WP_Term $term A ryv_resource_type or resource-state term.
	 * @param string  $lang Language code ('' = current).
	 * @return string
	 */
	public static function get_term_label( WP_Term $term, string $lang = '' ): string {
		if ( '' === $lang && class_exists( 'RYV_Resources_Search' ) ) {
			$lang = RYV_Resources_Search::current_language();
		}

		if ( 'es' === $lang ) {
			if ( self::TYPE_TAXONOMY === $term->taxonomy && isset( self::SPANISH_TYPE_NAMES[ $term->slug ] ) ) {
				return self::SPANISH_TYPE_NAMES[ $term->slug ];
			}
			if ( self::STATE_TAXONOMY === $term->taxonomy ) {
				if ( self::ALL_STATES === $term->slug ) {
					return 'Todos los estados';
				}
				if ( isset( RYV_Resources_Search::SPANISH_NAMES[ $term->slug ] ) ) {
					return RYV_Resources_Search::SPANISH_NAMES[ $term->slug ];
				}
			}
		}

		return $term->name;
	}

	/**
	 * Seed the default category terms once, if the taxonomy has none yet.
	 *
	 * Runs on every request like the schema/DB upgrade checks elsewhere in
	 * this plugin, but `wp_insert_term()` is cheap to skip via `term_exists()`
	 * so there's no need for a dedicated "have we run this" flag.
	 */
	public function maybe_seed_default_terms() {
		foreach ( self::DEFAULT_TERMS as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term( $name, self::TAXONOMY, array( 'slug' => $slug ) );
			}
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
				$new['ryv_resource_format'] = 'Format';
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
		if ( 'ryv_resource_format' === $column ) {
			$format = get_field( 'ryv_resource_format', $post_id );
			echo $format ? esc_html( $format ) : '—';
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
					'key'          => 'field_ryv_resource_format',
					'label'        => 'Format',
					'name'         => 'ryv_resource_format',
					'type'         => 'text',
					'instructions' => 'Optional, legacy. The tags on cards come from Resource Types (right sidebar).',
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
				array(
					'key'          => 'field_ryv_resource_cta_preset',
					'label'        => 'Button Text',
					'name'         => 'ryv_resource_cta_preset',
					'type'         => 'select',
					'choices'      => array_merge(
						self::cta_presets(),
						array( 'custom' => 'Custom…' )
					),
					'default_value' => 'download',
					'ui'           => 1,
					'instructions' => 'Pick a common label, or "Custom…" to type your own below.',
					'wrapper'      => array( 'width' => '50' ),
				),
				array(
					'key'               => 'field_ryv_resource_cta_custom',
					'label'             => 'Custom Button Text',
					'name'              => 'ryv_resource_cta_custom',
					'type'              => 'text',
					'wrapper'           => array( 'width' => '50' ),
					'conditional_logic' => array(
						array(
							array(
								'field'    => 'field_ryv_resource_cta_preset',
								'operator' => '==',
								'value'    => 'custom',
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
						'value'    => self::POST_TYPE,
					),
				),
			),
			'menu_order' => 0,
			'style'      => 'default',
		) );
	}
}
