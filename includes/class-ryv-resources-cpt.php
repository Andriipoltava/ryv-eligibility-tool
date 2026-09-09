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
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'maybe_seed_default_terms' ), 20 );
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
			'show_in_nav_menus' => false,
			'rewrite'           => array( 'slug' => 'resource-category', 'with_front' => false ),
		) );
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
