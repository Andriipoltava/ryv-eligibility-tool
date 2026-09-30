<?php
/**
 * Search + state filtering for the Resources page's Loop Grid.
 *
 * The page itself is plain Elementor: the same Taxonomy Filter tabs + Loop
 * Grid (card = the "Resources Loop Item" template) as the homepage's
 * Resources section, plus the RYV Resources Search widget above them. That
 * widget is only a GET form - it submits `ryv_search` / `ryv_state` back to
 * the same page, and this class narrows any Loop Grid whose Query ID
 * (Query section > Query ID) is `ryv_resources_search` to match:
 *
 * - `ryv_search` - matches the resource's title, or its ACF Description.
 * - `ryv_state`  - a state slug from RYV_US_States (DC excluded, the page
 *                  offers the 50 states only); matches the resources picked
 *                  in that `ryv_states` post's own "Resources" relationship
 *                  field, the same list its state page shows.
 *
 * Both combine with each other and with the Taxonomy Filter's category tab.
 *
 * Clicking a category tab (or "Load More") re-renders the grid through an
 * Elementor Pro REST refresh, where `$_GET` holds only Elementor's own
 * params. The page URL - and so the visitor's search/state - survives there
 * only as the request's `Referer`, same problem and same fix as
 * RYV_State_Queries::capture_rest_referer_state_id().
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Resources_Search
 */
class RYV_Resources_Search {

	const QUERY_ID = 'ryv_resources_search';

	const PARAM_SEARCH = 'ryv_search';

	const PARAM_STATE = 'ryv_state';

	const PARAM_SORT = 'ryv_sort';

	/**
	 * Sort keys the widget's "Sort by" dropdown offers => WP_Query order.
	 * The first one is the default (what an unsorted page shows).
	 */
	const SORTS = array(
		'newest'     => array( 'orderby' => 'date', 'order' => 'DESC' ),
		'oldest'     => array( 'orderby' => 'date', 'order' => 'ASC' ),
		'title-asc'  => array( 'orderby' => 'title', 'order' => 'ASC' ),
		'title-desc' => array( 'orderby' => 'title', 'order' => 'DESC' ),
	);

	/**
	 * The most recent WP_Query this class filtered - read back after the
	 * Loop Grid renders to report how many resources matched.
	 *
	 * @var WP_Query|null
	 */
	private $last_query = null;

	/**
	 * Per-resource "which state(s) is this about" tags - registered from the
	 * ACF UI (ACF → Taxonomies), not in code, so it may not exist.
	 */
	const STATE_TAXONOMY = 'resource-state';

	/**
	 * Spanish display names for the states whose name differs from the
	 * English one - shown on the Spanish edition of the page (WPML). The
	 * submitted value stays the untranslated slug either way.
	 */
	const SPANISH_NAMES = array(
		'hawaii'         => 'Hawái',
		'louisiana'      => 'Luisiana',
		'michigan'       => 'Míchigan',
		'mississippi'    => 'Misisipi',
		'new-hampshire'  => 'Nuevo Hampshire',
		'new-jersey'     => 'Nueva Jersey',
		'new-mexico'     => 'Nuevo México',
		'new-york'       => 'Nueva York',
		'north-carolina' => 'Carolina del Norte',
		'north-dakota'   => 'Dakota del Norte',
		'oregon'         => 'Oregón',
		'pennsylvania'   => 'Pensilvania',
		'south-carolina' => 'Carolina del Sur',
		'south-dakota'   => 'Dakota del Sur',
		'west-virginia'  => 'Virginia Occidental',
	);

	/**
	 * Re-entrancy guard, see filter_query().
	 *
	 * @var bool
	 */
	private $running = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'elementor/query/' . self::QUERY_ID, array( $this, 'filter_query' ) );
		add_filter( 'elementor/widget/render_content', array( $this, 'append_found_count' ), 10, 2 );
		add_filter( 'elementor/posts/wp_link_page', array( $this, 'keep_filters_in_pagination' ) );
	}

	/**
	 * Carry the visitor's search/state/sort into the Loop Grid's pagination
	 * links. Elementor Pro builds them from the page permalink and re-adds
	 * only its own `e-filter-*` params on a normal page load, so "page 2"
	 * would otherwise silently drop the search and list everything (its AJAX
	 * pagination fetches exactly that href). During a REST refresh it copies
	 * the whole Referer query itself - already-present params are skipped.
	 *
	 * @param string $url Escaped pagination URL.
	 * @return string
	 */
	public function keep_filters_in_pagination( $url ) {
		$filters = self::get_current_filters();
		$params  = array_filter(
			array(
				self::PARAM_SEARCH => $filters['search'],
				self::PARAM_STATE  => $filters['state'],
				self::PARAM_SORT   => $filters['sort'],
			),
			'strlen'
		);

		$raw = html_entity_decode( (string) $url );

		foreach ( $params as $key => $value ) {
			if ( false === strpos( $raw, $key . '=' ) ) {
				$raw = add_query_arg( $key, rawurlencode( $value ), $raw );
			}
		}

		return esc_url( $raw );
	}

	/**
	 * Stamp the matched-resources total onto the Loop Grid's own markup, as
	 * a hidden `.ryv-rs-found[data-found]` marker the search widget's JS
	 * reads for its "N resources found" line.
	 *
	 * Done here, after the grid rendered, because that's the only point the
	 * number is known: the search widget sits above the grid, so it renders
	 * before the grid's query has run. Riding inside the grid's own markup
	 * also means it arrives with every refresh of it - an AJAX search, a
	 * category tab click, Load More - with nothing extra to fetch.
	 *
	 * The last query this class filtered is the grid's own: the Taxonomy
	 * Filter's "which tabs have posts" query (same Query ID) runs earlier,
	 * while that widget renders above the grid.
	 *
	 * @param string                 $content Widget HTML.
	 * @param \Elementor\Widget_Base $widget  Widget being rendered.
	 * @return string
	 */
	public function append_found_count( $content, $widget ) {
		if ( ! $this->last_query || 'loop-grid' !== $widget->get_name()
			|| self::QUERY_ID !== $widget->get_settings_for_display( 'post_query_query_id' ) ) {
			return $content;
		}

		$query = $this->last_query;
		$found = max( (int) $query->found_posts, (int) $query->post_count );

		// A matches-nothing query (post__in = array( 0 )) can still report
		// the unfiltered total when it skips counting - trust post_count then.
		if ( array( 0 ) === $query->get( 'post__in' ) ) {
			$found = 0;
		}

		$this->last_query = null;

		$filters  = self::get_current_filters();
		$states   = self::get_states();
		$per_page = (int) $query->get( 'posts_per_page' );
		$page     = max( 1, (int) $query->get( 'paged' ) );

		return $content . sprintf(
			'<span class="ryv-rs-found" data-found="%1$d" data-page="%2$d" data-per-page="%3$d" data-state="%4$s" data-search="%5$s" hidden></span>',
			$found,
			$page,
			$per_page > 0 ? $per_page : $found,
			esc_attr( $states[ $filters['state'] ] ?? '' ),
			esc_attr( $filters['search'] )
		);
	}

	/**
	 * The 50 states offered in the dropdown, slug => name.
	 *
	 * @return array<string,string>
	 */
	public static function get_states(): array {
		$states = RYV_US_States::MAP;
		unset( $states['district-of-columbia'] );

		if ( 'es' === self::current_language() ) {
			$states = array_merge( $states, self::SPANISH_NAMES );
			// Re-sort by the displayed name, so "Nueva York" sits under N.
			uasort(
				$states,
				static function ( $a, $b ) {
					return strcasecmp( remove_accents( $a ), remove_accents( $b ) );
				}
			);
		}

		/**
		 * Filters the state dropdown's options (slug => display name).
		 *
		 * @param array<string,string> $states
		 */
		return apply_filters( 'ryv_resources_search_states', $states );
	}

	/**
	 * The page's language. An Elementor Pro REST refresh (category tab click)
	 * runs in WPML's default language whatever page issued it, so there the
	 * language is read off the Referer's `/xx/` path prefix instead.
	 *
	 * @return string Language code, '' when WPML isn't active.
	 */
	public static function current_language(): string {
		$lang = (string) apply_filters( 'wpml_current_language', '' );

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$path = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), PHP_URL_PATH );
			if ( preg_match( '#^/([a-z]{2})/#', $path, $m ) && array_key_exists( $m[1], (array) apply_filters( 'wpml_active_languages', array() ) ) ) {
				$lang = $m[1];
			}
		}

		return $lang;
	}

	/**
	 * The visitor's current search/state, from the page's own query string
	 * or - during an Elementor Pro REST refresh - from its Referer.
	 *
	 * @return array{search:string,state:string,sort:string}
	 */
	public static function get_current_filters(): array {
		$params = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public filter.

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$query  = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), PHP_URL_QUERY );
			$params = array();
			wp_parse_str( $query, $params );
		}

		$sort   = isset( $params[ self::PARAM_SORT ] ) ? sanitize_key( wp_unslash( (string) $params[ self::PARAM_SORT ] ) ) : '';
		$search = isset( $params[ self::PARAM_SEARCH ] ) ? sanitize_text_field( wp_unslash( (string) $params[ self::PARAM_SEARCH ] ) ) : '';
		$state  = isset( $params[ self::PARAM_STATE ] ) ? sanitize_title( wp_unslash( (string) $params[ self::PARAM_STATE ] ) ) : '';

		if ( '' !== $state && ! isset( self::get_states()[ $state ] ) ) {
			$state = '';
		}

		if ( ! isset( self::SORTS[ $sort ] ) ) {
			$sort = '';
		}

		return array(
			'search' => $search,
			'state'  => $state,
			'sort'   => $sort,
		);
	}

	/**
	 * @param WP_Query $query The Loop Grid's query, before it runs.
	 */
	public function filter_query( $query ) {
		// Elementor Pro fires this hook from a `pre_get_posts` callback that
		// stays attached while the widget's query is built, so the lookups
		// below (get_posts()) would re-enter it for their own queries -
		// endless recursion until PHP runs out of memory (confirmed live).
		if ( $this->running || ! $query instanceof WP_Query ) {
			return;
		}

		$this->running = true;
		$this->apply_filters_to( $query );
		$this->running = false;
	}

	/**
	 * @param WP_Query $query The Loop Grid's query, before it runs.
	 */
	private function apply_filters_to( WP_Query $query ) {
		$query->set( 'post_type', RYV_Resources_CPT::POST_TYPE );
		$query->set( 'post_status', 'publish' );

		$filters = self::get_current_filters();
		$ids     = null;

		$this->last_query = $query;

		if ( '' !== $filters['sort'] ) {
			$query->set( 'orderby', self::SORTS[ $filters['sort'] ]['orderby'] );
			$query->set( 'order', self::SORTS[ $filters['sort'] ]['order'] );
		}

		if ( '' !== $filters['state'] ) {
			$ids = self::get_state_resource_ids( $filters['state'] );
		}

		if ( '' !== $filters['search'] ) {
			$matches = self::get_search_matches( $filters['search'] );
			$ids     = null === $ids ? $matches : array_values( array_intersect( $ids, $matches ) );
		}

		if ( null === $ids ) {
			return;
		}

		// Empty post__in is ignored by WP_Query; array( 0 ) matches nothing,
		// so the grid shows its "nothing found" message instead of everything.
		$query->set( 'post__in', $ids ? $ids : array( 0 ) );
	}

	/**
	 * Resources for a state, from both places that link the two: the
	 * resource's own `resource-state` tag, and the state page's "Resources"
	 * relationship field (in the current language, see
	 * RYV_State_Queries::get_related_ids()).
	 *
	 * @param string $slug State slug, e.g. "new-york".
	 * @return int[]
	 */
	private static function get_state_resource_ids( string $slug ): array {
		global $wpdb;

		// Plain SQL rather than get_posts() on purpose: this runs inside
		// Elementor Pro's query hook, and the Taxonomy Filter builds the Loop
		// Grid's query with a global `include_field_ids_arg` flag set - any
		// nested WP_Query started from here gets mangled by it (confirmed
		// live: the category tabs vanished whenever a state was picked).
		// A state whose post slug drifted still carries its canonical slug
		// in the `ryv_state_slug` ACF field.
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

		if ( $state_id && has_filter( 'wpml_object_id' ) ) {
			$state_id = (int) apply_filters( 'wpml_object_id', $state_id, RYV_States_CPT::POST_TYPE, true );
		}

		$picked = $state_id ? RYV_State_Queries::get_related_ids( $state_id, 'ryv_resources' ) : array();
		$tagged = array();

		if ( taxonomy_exists( self::STATE_TAXONOMY ) ) {
			$term = get_term_by( 'slug', $slug, self::STATE_TAXONOMY );
			if ( $term && ! is_wp_error( $term ) ) {
				$tagged = get_objects_in_term( (int) $term->term_id, self::STATE_TAXONOMY );
				$tagged = is_wp_error( $tagged ) ? array() : array_map( 'intval', $tagged );
			}
		}

		// Unpublished/other-type ids are dropped by the Loop Grid's own
		// post_type/post_status, so no need to filter them here.
		// "All States" resources apply to every state.
		if ( taxonomy_exists( self::STATE_TAXONOMY ) && class_exists( 'RYV_Resources_CPT' ) ) {
			$all = get_term_by( 'slug', RYV_Resources_CPT::ALL_STATES, self::STATE_TAXONOMY );
			if ( $all && ! is_wp_error( $all ) ) {
				$everywhere = get_objects_in_term( (int) $all->term_id, self::STATE_TAXONOMY );
				$tagged     = array_merge( $tagged, is_wp_error( $everywhere ) ? array() : array_map( 'intval', $everywhere ) );
			}
		}

		return array_values( array_unique( array_merge( $picked, $tagged ) ) );
	}

	/**
	 * Resource ids whose title or ACF Description contains $term.
	 *
	 * @param string $term Search text.
	 * @return int[]
	 */
	private static function get_search_matches( string $term ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one small indexed lookup per page view.
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'ryv_resource_description'
				WHERE p.post_type = %s AND p.post_status = 'publish'
				AND ( p.post_title LIKE %s OR m.meta_value LIKE %s )",
				RYV_Resources_CPT::POST_TYPE,
				$like,
				$like
			)
		);

		return array_map( 'intval', $ids );
	}
}
