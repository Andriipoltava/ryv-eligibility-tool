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

		add_filter( 'manage_edit-' . self::POST_TYPE . '_columns', array( $this, 'add_shortcode_column' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_shortcode_column' ), 10, 2 );
		add_action( 'admin_footer', array( $this, 'print_list_table_copy_script' ) );

		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'assign_default_language_if_missing' ) );
	}

	/**
	 * Backstop for a `ryv_forms` post that WPML never assigned a language
	 * to - the builder creates new forms via core's `POST /wp/v2/ryv_forms`
	 * endpoint (see CLAUDE.md's "Admin builder SPA" section) rather than
	 * the classic post-new.php screen, and that path doesn't reliably run
	 * WPML's own default-language assignment for every WPML/site
	 * configuration. A post left without a language row is invisible to
	 * WPML's own admin list-table language filtering (an `INNER JOIN` to
	 * `wp_icl_translations`), which is what caused a freshly-created,
	 * published form to vanish from "All"/"Mine" on the `ryv_forms` list
	 * table - the post existed, WPML's filtered query just excluded it.
	 *
	 * Hooked on every `save_post_ryv_forms` (not just creation), same as
	 * `RYV_DB::maybe_upgrade()`'s "runs on every request, cheap no-op once
	 * current" pattern: `wpml_element_trid` is checked first, so an
	 * already-assigned post costs one filter call and returns immediately.
	 *
	 * Also callable directly (`RYV_CPT::assign_default_language_if_missing( $id )`)
	 * to backfill an existing post - `RYV_Translations_Page`'s "Assign
	 * Default Language" bulk action reuses this same method against every
	 * `ryv_forms` post rather than duplicating the assignment logic.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if a language was just assigned, false if the post
	 *              already had one (or WPML/its default-language filter
	 *              isn't available).
	 */
	public static function assign_default_language_if_missing( int $post_id ): bool {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return false;
		}

		if ( ! has_filter( 'wpml_element_trid' ) || ! has_filter( 'wpml_default_language' ) || ! has_action( 'wpml_set_element_language_details' ) ) {
			return false;
		}

		$element_type = 'post_' . self::POST_TYPE;

		if ( apply_filters( 'wpml_element_trid', false, $post_id, $element_type ) ) {
			return false;
		}

		$default_language = apply_filters( 'wpml_default_language', null );

		if ( ! $default_language ) {
			return false;
		}

		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'    => $post_id,
				'element_type'  => $element_type,
				'trid'          => false,
				'language_code' => $default_language,
			)
		);

		return true;
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
					// Must match RYV_Admin_API::check_permission()'s
					// `manage_options` gate: this meta is also exposed
					// read/write through core's own `/wp/v2/ryv_forms/{id}`
					// endpoint via `show_in_rest` below, and its raw HTML
					// content needs the same trust boundary regardless of
					// which REST route writes it - `edit_posts` would let an
					// Editor bypass the tightened admin-api.php route
					// entirely by writing this meta field directly.
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Insert a "Shortcode" column into the ryv_forms list table right after
	 * Title, so `[ryv_form id="X"]` is discoverable without opening the
	 * builder - the person embedding a form into an ordinary post/page is
	 * often not the same person who built it.
	 *
	 * @param array<string,string> $columns Existing column id => label map.
	 * @return array<string,string>
	 */
	public function add_shortcode_column( array $columns ): array {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['ryv_shortcode'] = __( 'Shortcode', 'ryv-eligibility-tool' );
			}
		}

		return $new;
	}

	/**
	 * Render the Shortcode column's cell.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Row's post ID.
	 */
	public function render_shortcode_column( string $column, int $post_id ) {
		if ( 'ryv_shortcode' !== $column ) {
			return;
		}

		$shortcode = '[ryv_form id="' . $post_id . '"]';

		printf(
			'<code>%1$s</code> <button type="button" class="button button-small ryv-copy-shortcode" data-shortcode="%2$s">%3$s</button>',
			esc_html( $shortcode ),
			esc_attr( $shortcode ),
			esc_html__( 'Copy', 'ryv-eligibility-tool' )
		);
	}

	/**
	 * Tiny click-delegated clipboard handler for the Shortcode column's
	 * Copy buttons - only printed on the ryv_forms list table itself
	 * (get_current_screen(), not the admin_footer hook's own hook_suffix,
	 * since every post type's list table shares the same 'edit.php'
	 * hook_suffix).
	 */
	public function print_list_table_copy_script() {
		$screen = get_current_screen();

		if ( ! $screen || 'edit' !== $screen->base || self::POST_TYPE !== $screen->post_type ) {
			return;
		}
		?>
		<script>
		document.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '.ryv-copy-shortcode' );
			if ( ! btn ) {
				return;
			}
			navigator.clipboard.writeText( btn.dataset.shortcode ).then( function () {
				var original = btn.textContent;
				btn.textContent = '<?php echo esc_js( __( 'Copied!', 'ryv-eligibility-tool' ) ); ?>';
				setTimeout( function () { btn.textContent = original; }, 1500 );
			} );
		} );
		</script>
		<?php
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
