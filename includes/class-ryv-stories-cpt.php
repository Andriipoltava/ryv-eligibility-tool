<?php
/**
 * Stories CPT — ryv_stories.
 *
 * Backs the "Real People, Real Impact" video-stories section
 * (widgets/class-ryv-stories-widget.php): one post per story, title is the
 * storyteller's name, native fields plus a plain (non-ACF) meta box carry
 * the YouTube link/timestamp/location/quote. Kept ACF-independent, unlike
 * ryv_resources/ryv_states, since the widget needs these fields to render
 * on every install regardless of whether ACF Pro happens to be active.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Stories_CPT
 */
class RYV_Stories_CPT {

	const POST_TYPE = 'ryv_stories';

	/**
	 * Meta keys, each single-value on the post. `youtube_url` is the only
	 * one a story can't meaningfully render without; the rest have sane
	 * empty-string/false fallbacks at render time.
	 */
	const META_YOUTUBE_URL = '_ryv_story_youtube_url';
	const META_DURATION    = '_ryv_story_duration';
	const META_LOCATION    = '_ryv_story_location';
	const META_QUOTE       = '_ryv_story_quote';
	const META_CAPTIONED   = '_ryv_story_captioned';
	const META_FEATURED    = '_ryv_story_featured';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		// Same backstop RYV_CPT applies to `ryv_forms`: a post with no WPML
		// language row is excluded by the INNER JOIN every language-filtered
		// query uses, which for this post type means silently vanishing from
		// a state page's own related list on the frontend.
		add_action( 'save_post_' . self::POST_TYPE, array( 'RYV_CPT', 'assign_default_language_if_missing' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta_box' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_column' ), 10, 2 );
	}

	/**
	 * Register the ryv_stories post type.
	 *
	 * `page-attributes` support exposes the native "Order" number field, used
	 * as the manual sort key (widget's default query order) so an admin can
	 * reorder the list on the right without needing drag-and-drop tooling.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => 'RYV Stories',
			'singular_name'      => 'Story',
			'add_new'            => 'Add New',
			'add_new_item'       => 'Add New Story',
			'edit_item'          => 'Edit Story',
			'new_item'           => 'New Story',
			'view_item'          => 'View Story',
			'search_items'       => 'Search Stories',
			'not_found'          => 'No stories found',
			'not_found_in_trash' => 'No stories found in trash',
			'menu_name'          => 'RYV Stories',
			'featured_image'     => 'Photo',
			'set_featured_image' => 'Set photo',
			'remove_featured_image' => 'Remove photo',
		);

		$args = array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_position'       => 29,
			'menu_icon'           => 'dashicons-format-video',
			'has_archive'         => false,
			'rewrite'             => array( 'slug' => 'stories', 'with_front' => false ),
			'supports'            => array( 'title', 'thumbnail', 'page-attributes' ),
			'show_in_rest'        => true,
			'exclude_from_search' => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Expose every story field on the REST post object too (not just as raw
	 * postmeta), so the widget - or any future non-PHP consumer - can read
	 * a resolved set of fields in one shape. The widget itself renders
	 * server-side via get_post_meta() directly, same as every other
	 * PHP-rendered CPT in this plugin; this is purely for REST/admin-tooling
	 * parity with ryv_resources' ACF-exposed fields.
	 */
	public function register_meta() {
		$string_fields = array(
			self::META_YOUTUBE_URL => 'esc_url_raw',
			self::META_DURATION    => 'sanitize_text_field',
			self::META_LOCATION    => 'sanitize_text_field',
			self::META_QUOTE       => 'sanitize_textarea_field',
		);

		foreach ( $string_fields as $key => $sanitize_callback ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => $sanitize_callback,
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}

		foreach ( array( self::META_CAPTIONED, self::META_FEATURED ) as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'          => 'boolean',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Meta box: one panel with every story field, since there are only six
	 * and they're all closely related - matches the plugin's existing
	 * preference for a single flat panel over several small ones on a CPT
	 * this size (e.g. the map widget's own settings groupings are Elementor
	 * controls, not post meta boxes).
	 */
	public function add_meta_box() {
		add_meta_box(
			'ryv_story_details',
			__( 'Story Details', 'ryv-eligibility-tool' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * @param WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'ryv_story_meta', 'ryv_story_meta_nonce' );

		$youtube_url = get_post_meta( $post->ID, self::META_YOUTUBE_URL, true );
		$duration    = get_post_meta( $post->ID, self::META_DURATION, true );
		$location    = get_post_meta( $post->ID, self::META_LOCATION, true );
		$quote       = get_post_meta( $post->ID, self::META_QUOTE, true );
		$captioned   = (bool) get_post_meta( $post->ID, self::META_CAPTIONED, true );
		$featured    = (bool) get_post_meta( $post->ID, self::META_FEATURED, true );
		?>
		<p>
			<label for="ryv_story_youtube_url"><strong><?php esc_html_e( 'YouTube URL', 'ryv-eligibility-tool' ); ?></strong></label><br />
			<input type="url" id="ryv_story_youtube_url" name="ryv_story_youtube_url" class="large-text" placeholder="https://www.youtube.com/watch?v=..." value="<?php echo esc_attr( $youtube_url ); ?>" />
			<span class="description"><?php esc_html_e( 'Link to the story\'s video on YouTube. Required for the story to render.', 'ryv-eligibility-tool' ); ?></span>
		</p>
		<p>
			<label for="ryv_story_duration"><strong><?php esc_html_e( 'Duration', 'ryv-eligibility-tool' ); ?></strong></label><br />
			<input type="text" id="ryv_story_duration" name="ryv_story_duration" placeholder="1:47" value="<?php echo esc_attr( $duration ); ?>" />
			<span class="description"><?php esc_html_e( 'Shown as "1:47 · TENNESSEE". Plain text, not read from YouTube automatically.', 'ryv-eligibility-tool' ); ?></span>
		</p>
		<p>
			<label for="ryv_story_location"><strong><?php esc_html_e( 'Location', 'ryv-eligibility-tool' ); ?></strong></label><br />
			<input type="text" id="ryv_story_location" name="ryv_story_location" placeholder="TENNESSEE" value="<?php echo esc_attr( $location ); ?>" />
			<span class="description"><?php esc_html_e( 'State/region label shown alongside the duration.', 'ryv-eligibility-tool' ); ?></span>
		</p>
		<p>
			<label for="ryv_story_quote"><strong><?php esc_html_e( 'Quote / Short Description', 'ryv-eligibility-tool' ); ?></strong></label><br />
			<textarea id="ryv_story_quote" name="ryv_story_quote" class="large-text" rows="3" placeholder="&quot;I didn't know I could vote until someone told me.&quot;"><?php echo esc_textarea( $quote ); ?></textarea>
		</p>
		<p>
			<label><input type="checkbox" id="ryv_story_captioned" name="ryv_story_captioned" value="1" <?php checked( $captioned ); ?> /> <?php esc_html_e( 'Video is captioned', 'ryv-eligibility-tool' ); ?></label>
		</p>
		<p>
			<label><input type="checkbox" id="ryv_story_featured" name="ryv_story_featured" value="1" <?php checked( $featured ); ?> /> <strong><?php esc_html_e( 'Featured (use as the large video)', 'ryv-eligibility-tool' ); ?></strong></label><br />
			<span class="description"><?php esc_html_e( 'Only one story should be featured at a time - the widget uses the first one it finds. Every other published story renders in the list.', 'ryv-eligibility-tool' ); ?></span>
		</p>
		<?php
	}

	/**
	 * @param int $post_id Post ID being saved.
	 */
	public function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['ryv_story_meta_nonce'] ) || ! wp_verify_nonce( $_POST['ryv_story_meta_nonce'], 'ryv_story_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_YOUTUBE_URL, isset( $_POST['ryv_story_youtube_url'] ) ? esc_url_raw( wp_unslash( $_POST['ryv_story_youtube_url'] ) ) : '' );
		update_post_meta( $post_id, self::META_DURATION, isset( $_POST['ryv_story_duration'] ) ? sanitize_text_field( wp_unslash( $_POST['ryv_story_duration'] ) ) : '' );
		update_post_meta( $post_id, self::META_LOCATION, isset( $_POST['ryv_story_location'] ) ? sanitize_text_field( wp_unslash( $_POST['ryv_story_location'] ) ) : '' );
		update_post_meta( $post_id, self::META_QUOTE, isset( $_POST['ryv_story_quote'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ryv_story_quote'] ) ) : '' );
		update_post_meta( $post_id, self::META_CAPTIONED, isset( $_POST['ryv_story_captioned'] ) ? 1 : 0 );
		update_post_meta( $post_id, self::META_FEATURED, isset( $_POST['ryv_story_featured'] ) ? 1 : 0 );
	}

	/**
	 * @param array $columns Default columns.
	 * @return array
	 */
	public function admin_columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['ryv_story_featured'] = __( 'Featured', 'ryv-eligibility-tool' );
				$new['ryv_story_meta']     = __( 'Duration / Location', 'ryv-eligibility-tool' );
				$new['ryv_story_youtube']  = __( 'YouTube', 'ryv-eligibility-tool' );
			}
		}
		return $new;
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_admin_column( string $column, int $post_id ) {
		if ( 'ryv_story_featured' === $column ) {
			echo get_post_meta( $post_id, self::META_FEATURED, true ) ? '★' : '—';
			return;
		}

		if ( 'ryv_story_meta' === $column ) {
			$duration = get_post_meta( $post_id, self::META_DURATION, true );
			$location = get_post_meta( $post_id, self::META_LOCATION, true );
			$parts    = array_filter( array( $duration, $location ) );
			echo $parts ? esc_html( implode( ' · ', $parts ) ) : '—';
			return;
		}

		if ( 'ryv_story_youtube' === $column ) {
			$url = get_post_meta( $post_id, self::META_YOUTUBE_URL, true );
			if ( $url ) {
				printf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', esc_url( $url ), esc_html__( 'View', 'ryv-eligibility-tool' ) );
			} else {
				echo '—';
			}
		}
	}
}
