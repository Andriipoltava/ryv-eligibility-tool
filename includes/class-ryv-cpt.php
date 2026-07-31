<?php
/**
 * Registers the `ryv_forms` custom post type and its postmeta.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_CPT
 *
 * Each `ryv_forms` post represents one eligibility questionnaire. The full
 * decision graph - one universal flat DAG, `{entry_node, nodes}` - lives in
 * a single JSON blob in the `_ryv_form_schema` postmeta, not in nested
 * repeaters. get_schema()/save_schema() are the one canonical read/write
 * path for that meta; every other class should go through them rather than
 * hand-rolling get_post_meta()/json_decode()/wp_slash(wp_json_encode()).
 */
class RYV_CPT {

	/**
	 * Post type slug.
	 */
	const POST_TYPE = 'ryv_forms';

	/**
	 * Postmeta key holding the flat decision-graph JSON.
	 */
	const SCHEMA_META_KEY = '_ryv_form_schema';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
	}

	/**
	 * Register the `ryv_forms` post type.
	 *
	 * Not publicly queryable: a form has no frontend permalink of its own,
	 * it is only ever rendered by the Elementor widget referencing its ID.
	 */
	public function register_post_type() {
		$labels = array(
			'name'                     => __( 'Eligibility Forms', 'ryv-eligibility-tool' ),
			'singular_name'            => __( 'Eligibility Form', 'ryv-eligibility-tool' ),
			'add_new'                  => __( 'Add New', 'ryv-eligibility-tool' ),
			'add_new_item'             => __( 'Add New Form', 'ryv-eligibility-tool' ),
			'edit_item'                => __( 'Edit Form', 'ryv-eligibility-tool' ),
			'new_item'                 => __( 'New Form', 'ryv-eligibility-tool' ),
			'view_item'                => __( 'View Form', 'ryv-eligibility-tool' ),
			'search_items'             => __( 'Search Forms', 'ryv-eligibility-tool' ),
			'not_found'                => __( 'No forms found', 'ryv-eligibility-tool' ),
			'not_found_in_trash'       => __( 'No forms found in Trash', 'ryv-eligibility-tool' ),
			'all_items'                => __( 'All Forms', 'ryv-eligibility-tool' ),
			'menu_name'                => __( 'RYV Forms', 'ryv-eligibility-tool' ),
			'item_updated'             => __( 'Form updated.', 'ryv-eligibility-tool' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => true,
			'rest_base'           => 'ryv_forms',
			'menu_icon'           => 'dashicons-forms',
			// 'excerpt' is what the builder SPA edits as "Description" (also
			// what the Elementor widget's show_description control reads
			// directly off the post) - it must be in `supports` or core's
			// REST controller won't expose an `excerpt` field to PATCH at all.
			'supports'            => array( 'title', 'excerpt' ),
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register the schema postmeta so it is readable/writable via the REST
	 * API (used by the Phase 3 decision engine and the Phase 5 admin SPA).
	 */
	public function register_meta() {
		register_post_meta(
			self::POST_TYPE,
			self::SCHEMA_META_KEY,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => static function ( $value ) {
					// Stored as raw JSON text; validity is enforced by the
					// parser/SPA layer, not by WP's meta sanitization.
					return is_string( $value ) ? $value : wp_json_encode( $value );
				},
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
					),
				),
				'auth_callback'     => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * The canonical schema read path: decode postmeta, transparently
	 * migrate a legacy per-state schema to the flat DAG shape if found
	 * (persisting the migration immediately so it only ever runs once per
	 * post - see RYV_Schema_Migrator), and normalize missing keys.
	 *
	 * @param int $post_id Post ID.
	 * @return array{entry_node: ?string, nodes: array}
	 */
	public static function get_schema( int $post_id ): array {
		$json   = get_post_meta( $post_id, self::SCHEMA_META_KEY, true );
		$schema = json_decode( (string) $json, true );

		if ( ! is_array( $schema ) ) {
			$schema = array();
		}

		if ( isset( $schema['states'] ) && is_array( $schema['states'] ) ) {
			$schema = RYV_Schema_Migrator::migrate( $schema );
			self::save_schema( $post_id, $schema );
		}

		if ( ! isset( $schema['nodes'] ) || ! is_array( $schema['nodes'] ) ) {
			$schema['nodes'] = array();
		}

		if ( ! array_key_exists( 'entry_node', $schema ) ) {
			$schema['entry_node'] = null;
		}

		return $schema;
	}

	/**
	 * The canonical schema write path.
	 *
	 * wp_slash() is required: update_post_meta() unslashes its input before
	 * storing (WP's meta API assumes slashed data), so an un-slashed JSON
	 * string loses every "\"" and "\uXXXX" escape on the way into the DB,
	 * corrupting it into invalid JSON on the next read.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $schema  `{entry_node, nodes}` schema to store.
	 */
	public static function save_schema( int $post_id, array $schema ): void {
		update_post_meta(
			$post_id,
			self::SCHEMA_META_KEY,
			wp_slash( wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) )
		);
	}
}
