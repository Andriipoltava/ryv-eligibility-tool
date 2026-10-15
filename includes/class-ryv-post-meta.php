<?php
/**
 * Data helpers + Elementor dynamic tags for the single blog-post template
 * (Figma "Blog Post – Template", node 1353:606): the article header's
 * topic/state tags, the "By X · Role · date · N min read" byline, and the
 * sidebar's "Can I vote in my state?" picker.
 *
 * Topic vs. state: blog posts carry both kinds of term in the one core
 * `category` taxonomy (e.g. "POLICY" and "KENTUCKY"). A category is a
 * *state* when its slug - in the site's default language, since WPML keeps
 * a separate term per language ("MINESOTA" on the Spanish side) - is one of
 * RYV_US_States; every other category is a *topic*. The same slug rule
 * RYV_State_Queries already uses to match a state page to its news.
 *
 * Author details are two optional ACF fields on the user profile (Job
 * Title, Photo) - WordPress has neither - with the core display name, bio
 * and Gravatar as fallbacks, so an author with nothing filled in still
 * renders a complete byline.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Post_Meta
 */
class RYV_Post_Meta {

	/**
	 * Query param the State Picker widget submits; any front-end URL with it
	 * redirects to that state's page.
	 */
	const PARAM_GO_STATE = 'ryv_go_state';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect_to_state' ) );
		add_action( 'elementor/dynamic_tags/register', array( $this, 'register_dynamic_tags' ) );

		if ( function_exists( 'acf_add_local_field_group' ) ) {
			add_action( 'acf/init', array( $this, 'register_author_fields' ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Categories: topic vs. state                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * @param WP_Term $term A `category` term.
	 * @return bool
	 */
	public static function is_state_term( WP_Term $term ): bool {
		$slug = $term->slug;

		if ( has_filter( 'wpml_object_id' ) ) {
			$default = (string) apply_filters( 'wpml_default_language', '' );
			$orig_id = (int) apply_filters( 'wpml_object_id', $term->term_id, $term->taxonomy, true, $default );
			if ( $orig_id && $orig_id !== $term->term_id ) {
				$orig = get_term( $orig_id, $term->taxonomy );
				if ( $orig instanceof WP_Term ) {
					$slug = $orig->slug;
				}
			}
		}

		return isset( RYV_US_States::MAP[ $slug ] );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $kind    'topic' or 'state'.
	 * @return WP_Term[]
	 */
	public static function get_terms_of_kind( int $post_id, string $kind ): array {
		$terms = get_the_terms( $post_id, 'category' );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$want_state = 'state' === $kind;

		return array_values(
			array_filter(
				$terms,
				static function ( $term ) use ( $want_state ) {
					return 'uncategorized' !== $term->slug && self::is_state_term( $term ) === $want_state;
				}
			)
		);
	}

	/**
	 * The one topic to feature (breadcrumb, first tag): Yoast's "Primary
	 * category" when it's a topic, else the first topic category.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Term|null
	 */
	public static function get_primary_topic( int $post_id ) {
		$topics = self::get_terms_of_kind( $post_id, 'topic' );

		if ( ! $topics ) {
			return null;
		}

		$taxonomy   = $topics[0]->taxonomy;
		$primary_id = (int) get_post_meta( $post_id, '_yoast_wpseo_primary_' . $taxonomy, true );
		if ( $primary_id && has_filter( 'wpml_object_id' ) ) {
			$primary_id = (int) apply_filters( 'wpml_object_id', $primary_id, $taxonomy, true );
		}

		foreach ( $topics as $topic ) {
			if ( $topic->term_id === $primary_id ) {
				return $topic;
			}
		}

		return $topics[0];
	}

	/* ------------------------------------------------------------------ */
	/* Byline                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Minutes to read: Yoast's own estimate when it has stored one, else
	 * words / 200 - never less than 1.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function get_reading_minutes( int $post_id ): int {
		$yoast = (int) get_post_meta( $post_id, '_yoast_wpseo_estimated-reading-time-minutes', true );

		if ( $yoast > 0 ) {
			return $yoast;
		}

		$words = str_word_count( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ) );

		return max( 1, (int) ceil( $words / 200 ) );
	}

	/**
	 * @param int $user_id Author.
	 * @return string The ACF "Job Title" on their profile, '' if none.
	 */
	public static function get_author_title( int $user_id ): string {
		return function_exists( 'get_field' ) ? trim( (string) get_field( 'ryv_author_title', 'user_' . $user_id ) ) : '';
	}

	/**
	 * @param int $user_id Author.
	 * @param int $size    Pixel size for the Gravatar fallback.
	 * @return array{id:int|null,url:string}
	 */
	public static function get_author_photo( int $user_id, int $size = 192 ): array {
		$id = function_exists( 'get_field' ) ? (int) get_field( 'ryv_author_photo', 'user_' . $user_id ) : 0;

		if ( $id ) {
			return array(
				'id'  => $id,
				'url' => (string) wp_get_attachment_url( $id ),
			);
		}

		return array(
			'id'  => null,
			'url' => (string) get_avatar_url( $user_id, array( 'size' => $size ) ),
		);
	}

	/**
	 * ACF fields on the user profile screen.
	 */
	public function register_author_fields() {
		acf_add_local_field_group(
			array(
				'key'      => 'group_ryv_author_details',
				'title'    => 'Author Details (blog posts)',
				'fields'   => array(
					array(
						'key'          => 'field_ryv_author_title',
						'label'        => 'Job Title',
						'name'         => 'ryv_author_title',
						'type'         => 'text',
						'instructions' => 'Shown under the author\'s name on blog posts, e.g. "Director, Restore Your Vote".',
					),
					array(
						'key'           => 'field_ryv_author_photo',
						'label'         => 'Photo',
						'name'          => 'ryv_author_photo',
						'type'          => 'image',
						'return_format' => 'id',
						'preview_size'  => 'thumbnail',
						'instructions'  => 'Square works best. Falls back to the profile\'s Gravatar when empty.',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'user_form',
							'operator' => '==',
							'value'    => 'all',
						),
					),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* State picker                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * `?ryv_go_state=kentucky` on any front-end URL -> that state's page
	 * (in the current language when it has one). Unknown slugs fall through
	 * to the page itself.
	 */
	public function maybe_redirect_to_state() {
		if ( empty( $_GET[ self::PARAM_GO_STATE ] ) || ! class_exists( 'RYV_States_CPT' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
			return;
		}

		$slug = sanitize_title( wp_unslash( (string) $_GET[ self::PARAM_GO_STATE ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		global $wpdb;
		$state_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ~50 rows.
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'ryv_state_slug'
				WHERE p.post_type = %s AND p.post_status = 'publish' AND ( p.post_name = %s OR m.meta_value = %s )
				ORDER BY ( p.post_name = %s ) DESC, p.ID ASC LIMIT 1",
				RYV_States_CPT::POST_TYPE,
				$slug,
				$slug,
				$slug
			)
		);

		if ( ! $state_id ) {
			return;
		}

		if ( has_filter( 'wpml_object_id' ) ) {
			$state_id = (int) apply_filters( 'wpml_object_id', $state_id, RYV_States_CPT::POST_TYPE, true );
		}

		wp_safe_redirect( get_permalink( $state_id ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Elementor dynamic tags                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * @param \Elementor\Core\DynamicTags\Manager $manager Tag manager.
	 */
	public function register_dynamic_tags( $manager ) {
		require_once __DIR__ . '/elementor-tags/class-ryv-tag-post-meta.php';

		$manager->register( new RYV_Tag_Post_Topic() );
		$manager->register( new RYV_Tag_Post_Topic_Url() );
		$manager->register( new RYV_Tag_Reading_Time() );
		$manager->register( new RYV_Tag_Author_Title() );
		$manager->register( new RYV_Tag_Author_Photo() );
		$manager->register( new RYV_Tag_Byline_Meta() );
	}
}
