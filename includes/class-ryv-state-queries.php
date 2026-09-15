<?php
/**
 * Elementor custom queries for a state page's own related content: lets a
 * Loop Grid / Posts widget in the `ryv_states` single template list exactly
 * the posts an admin picked in that state's ACF relationship fields, instead
 * of the whole archive of that post type.
 *
 * Set the widget's Query ID (Elementor Pro: Query section > Advanced >
 * Query ID) to one of:
 *
 * - `ryv_state_events`    - the state's picked Events (The Events Calendar),
 *                           soonest first, past ones dropped.
 * - `ryv_state_resources` - the state's picked Resources, in the order the
 *                           admin arranged them in the relationship field.
 * - `ryv_state_news`      - standard blog `post`s whose `category` matches
 *                           this state, newest first. Which category(ies)
 *                           match is an admin's own `ryv_news_categories`
 *                           pick on the state (RYV_States_ACF) when set,
 *                           falling back to auto-matching a single category
 *                           by slug against the state's `ryv_state_slug`
 *                           (e.g. a "Kentucky" category shows on the
 *                           Kentucky state page) for a state nobody has
 *                           curated yet. Pair the widget's Query ID with
 *                           `ryv-hide-empty--query-ryv_state_news` on the
 *                           section's own container (see
 *                           RYV_States_Template::is_marker_empty()) so the
 *                           whole "Latest News & Updates" section disappears
 *                           on a state with no matching posts instead of
 *                           rendering an empty grid.
 *
 * Why query filters rather than widgets of this plugin's own: each card's
 * design is authored as an Elementor Loop Item template, so the layout stays
 * editable in the builder. These queries only decide *which posts*, never
 * how they look.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_State_Queries
 */
class RYV_State_Queries {

	/**
	 * The Events Calendar's own post type. Declared here rather than on
	 * RYV_States_ACF (which registers the relationship field pointed at it)
	 * because that class only loads when ACF is active, while these queries
	 * run unconditionally - referencing a constant on a class that may not
	 * exist would be a fatal error on an ACF-less site.
	 */
	const EVENTS_POST_TYPE = 'tribe_events';

	/**
	 * Standard WordPress post type the `ryv_state_news` query lists.
	 */
	const NEWS_POST_TYPE = 'post';

	/**
	 * The state page resolved from an in-flight Elementor Pro loop-filter/
	 * search REST refresh request's `Referer` header (clicking a Taxonomy
	 * Filter tab, or typing into a Search widget, on a Loop Grid that also
	 * carries one of this class's Query IDs), if this request is one of
	 * those. 0 otherwise.
	 *
	 * Why this exists: those refresh requests hit
	 * `/wp-json/elementor-pro/v1/refresh-loop` (or `refresh-search`), which
	 * rebuilds just the one widget from its stored Elementor data
	 * (`Pro_Utils::create_widget_instance_from_db()`) and calls
	 * `render_content()` directly - it never runs the site's normal template
	 * bootstrap (`wp()`/`setup_postdata()`), so there is no "page being
	 * viewed" for `get_queried_object_id()`/`get_the_ID()` to report; both
	 * return 0. Confirmed live: clicking any tab on a state's "Guides,
	 * Forms, and Toolkits" filter reliably emptied the list regardless of
	 * which tab, because `get_state_id()` fell through to 0 every time.
	 *
	 * The request does carry its own `post_id` param, but that's the
	 * *Theme Builder template's* post id (e.g. the shared "State Post!"
	 * document, 745) - it's how Elementor locates the widget's stored
	 * definition, not which state page the visitor is actually on, and every
	 * state shares that one template id. Confirmed live via the request
	 * body: `{"post_id":"745", ...}` regardless of which state's page issued
	 * it. The `Referer` header - the browser's own record of the actual page
	 * URL the fetch was made from - is what's left to resolve "which state"
	 * from; `capture_rest_referer_state_id()` below is the only place that
	 * header is available, since by the time our query filter runs there is
	 * no `$request` object in scope, only whatever globals Elementor chose
	 * to set.
	 *
	 * @var int
	 */
	private static $rest_referer_state_id = 0;

	/**
	 * Query id => the ACF relationship field on `ryv_states` it reads.
	 *
	 * Adding another "this state's X" list means adding a row here and, if
	 * X needs its own ordering, a case in apply_ordering().
	 *
	 * @return array<string,string>
	 */
	private function get_queries(): array {
		return array(
			'ryv_state_events'    => 'ryv_events',
			'ryv_state_resources' => 'ryv_resources',
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$query_ids = array_merge( array_keys( $this->get_queries() ), array( 'ryv_state_news' ) );

		foreach ( $query_ids as $query_id ) {
			add_action(
				'elementor/query/' . $query_id,
				function ( $query ) use ( $query_id ) {
					$this->filter_query( $query, $query_id );
				}
			);
		}

		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'capture_rest_referer_state_id' ), 10, 3 );
	}

	/**
	 * Resolve the `ryv_states` post from an in-flight REST request's
	 * `Referer` header, so `get_state_id()` has something to fall back to
	 * once that request's own `render_content()` call reaches this class's
	 * query filters - see `$rest_referer_state_id`'s docblock for why
	 * nothing else is available at that point.
	 *
	 * Hooked globally (every REST request, not just Elementor's) since the
	 * only alternative is matching route strings by hand here and Elementor
	 * Pro doesn't expose a namespaced way to hook just its own routes - an
	 * unrelated request's referer simply not matching any `ryv_states` slug
	 * keeps this a no-op everywhere else.
	 *
	 * @param mixed            $response Unmodified; this only observes the request.
	 * @param array            $handler  Unused.
	 * @param \WP_REST_Request $request  The REST request about to be dispatched.
	 * @return mixed $response, unmodified.
	 */
	public static function capture_rest_referer_state_id( $response, $handler, $request ) {
		$referer = wp_get_referer();

		if ( ! $referer ) {
			return $response;
		}

		// Not `url_to_postid()`: confirmed dead-ending at 0 for a real
		// `ryv_states` permalink here (`/states/kentucky/`) even outside any
		// REST context - it matches against registered rewrite rules, which
		// this CPT's URL structure doesn't hit cleanly. The state pages
		// don't nest or share slugs across post types, so matching the
		// referer's last path segment directly against `post_name` is both
		// simpler and actually works.
		$path = (string) wp_parse_url( $referer, PHP_URL_PATH );
		$slug = basename( untrailingslashit( $path ) );

		if ( ! $slug ) {
			return $response;
		}

		$ids = get_posts(
			array(
				'name'        => $slug,
				'post_type'   => RYV_States_CPT::POST_TYPE,
				'post_status' => 'publish',
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);

		if ( $ids ) {
			self::$rest_referer_state_id = (int) $ids[0];
		}

		return $response;
	}

	/**
	 * @param WP_Query $query    The widget's query, before it runs.
	 * @param string   $query_id Which of get_queries() fired this.
	 */
	public function filter_query( $query, string $query_id ) {
		if ( ! $query instanceof WP_Query ) {
			return;
		}

		if ( 'ryv_state_news' === $query_id ) {
			$this->filter_news_query( $query );
			return;
		}

		$queries = $this->get_queries();

		if ( ! isset( $queries[ $query_id ] ) ) {
			return;
		}

		$ids = $this->get_related_ids( self::get_state_id(), $queries[ $query_id ] );

		if ( 'ryv_state_events' === $query_id ) {
			$ids = $this->prepare_event_ids( $ids );
		}

		$query->set( 'post_status', 'publish' );
		$query->set( 'ignore_sticky_posts', true );

		// Stop WPML re-filtering a list this class has already decided by id.
		// The ids come from the state's own field, i.e. the original
		// language's posts (that is what RYV_States_I18n falls back to on a
		// translation), and WPML's language clauses then exclude every one of
		// them: measured on a Spanish state page, the Loop Grid rendered 0
		// items while the English one rendered 3 events and 1 resource from
		// the identical id list. The section stayed visible throughout,
		// correctly - its ACF field is populated, so the hide-empty marker
		// had no reason to hide it - which is what made this look like a
		// hiding bug rather than a query one.
		$query->set( 'lang', 'all' );

		// An *empty* `post__in` is ignored by WP_Query rather than matching
		// nothing - a state with nothing picked would silently list every
		// post of that type. `array( 0 )` is the standard way to say "no
		// posts": a post id is never 0, so nothing can match.
		$query->set( 'post__in', $ids ? $ids : array( 0 ) );

		if ( ! $ids ) {
			// Still pin the post type, so an empty result renders as this
			// list being empty rather than as the widget's own default query.
			$query->set( 'post_type', $this->get_post_type_for( $query_id ) );
			return;
		}

		$query->set( 'post_type', $this->get_post_type_for( $query_id ) );

		$this->apply_ordering( $query, $query_id );
	}

	/**
	 * @param string $query_id Which query is running.
	 * @return string Post type to pin the query to.
	 */
	private function get_post_type_for( string $query_id ): string {
		return 'ryv_state_events' === $query_id
			? self::EVENTS_POST_TYPE
			: RYV_Resources_CPT::POST_TYPE;
	}

	/**
	 * Both lists are ordered by `post__in`, i.e. by whatever order this class
	 * put the ids in: a curatorial one for resources (the order the admin
	 * arranged the relationship field in), a chronological one for events
	 * (prepare_event_ids() sorts them).
	 *
	 * @param WP_Query $query    Query being built.
	 * @param string   $query_id Which query is running.
	 */
	private function apply_ordering( $query, string $query_id ) {
		$query->set( 'orderby', 'post__in' );

		if ( 'ryv_state_events' !== $query_id ) {
			return;
		}

		// The Events Calendar rewrites any query whose post_type is only
		// `tribe_events` - its own date filtering and ordering (see
		// the-events-calendar/src/Tribe/Query.php) would otherwise re-apply
		// its own upcoming/past rules and ordering on top of ours. This is
		// TEC's own documented opt-out.
		$query->set( 'tribe_suppress_query_filters', true );
	}

	/**
	 * Drop finished events and put the rest in chronological order, by
	 * reading each event's dates directly rather than asking the database to
	 * filter or sort on them.
	 *
	 * That indirection is the whole point. A `meta_query` on `_EventEndDate`
	 * is not answered from postmeta at all: TEC's custom-tables layer
	 * rewrites it into an INNER JOIN on `wp_tec_occurrences` (confirmed in
	 * the generated SQL), so on any site where that table hasn't been
	 * populated - which is exactly the state this site is in, 0 rows - every
	 * date-filtered event query returns nothing at all, silently. Ordering by
	 * `meta_value` on `_EventStartDate` sits behind the same rewrite. Doing
	 * both in PHP keeps this list working regardless of TEC's table state,
	 * and the id list is a handful of posts, not a whole archive.
	 *
	 * @param int[] $ids Event post ids picked on the state.
	 * @return int[] Chronological, upcoming-only (unless filtered otherwise).
	 */
	private function prepare_event_ids( array $ids ): array {
		if ( ! $ids ) {
			return $ids;
		}

		$hide_past = $this->should_hide_past_events();
		$now       = current_time( 'mysql' );
		$dated     = array();

		foreach ( $ids as $id ) {
			$start = (string) get_post_meta( $id, '_EventStartDate', true );
			$end   = (string) get_post_meta( $id, '_EventEndDate', true );

			// Compared against the *end* date, so an event running right now
			// still counts as happening rather than dropping off the list the
			// moment it starts. An event missing dates entirely is kept: it's
			// a broken event, but hiding it silently is worse than showing it.
			if ( $hide_past && '' !== $end && $end < $now ) {
				continue;
			}

			$dated[ $id ] = $start;
		}

		// TEC's stored format ('Y-m-d H:i:s') sorts correctly as a string, so
		// no date parsing is needed. Undated events sort first, where they're
		// visible enough for someone to notice and fix.
		asort( $dated, SORT_STRING );

		return array_keys( $dated );
	}

	/**
	 * Which `ryv_states` post to read the picked posts from.
	 *
	 * `get_queried_object_id()` rather than `get_the_ID()`: this runs inside
	 * a Loop Grid, whose own rendering swaps the global post per item, and on
	 * a single-state template the queried object is the state itself. Falls
	 * back to the global post so the query still works if the widget is
	 * placed somewhere else that has a state in context. Finally falls back
	 * to `$rest_referer_state_id`, for an Elementor Pro loop-filter/search
	 * widget-refresh REST request - see that property's docblock - where
	 * both of the above resolve to 0 since there's no real page being
	 * rendered for them to read.
	 *
	 * Static so `RYV_States_Template::is_marker_empty()` can resolve the same
	 * "which state" answer for the `query-ryv_state_news` hide-empty marker
	 * without instantiating this class - doing that would re-run the
	 * constructor and register every `elementor/query/*` hook a second time.
	 *
	 * @return int
	 */
	private static function get_state_id(): int {
		$queried_id = (int) get_queried_object_id();

		if ( $queried_id && RYV_States_CPT::POST_TYPE === get_post_type( $queried_id ) ) {
			return $queried_id;
		}

		$post_id = (int) get_the_ID();

		if ( $post_id && RYV_States_CPT::POST_TYPE === get_post_type( $post_id ) ) {
			return $post_id;
		}

		return self::$rest_referer_state_id;
	}

	/**
	 * Constrain a Loop Grid's query to standard `post`s categorized for this
	 * state, newest first - the `ryv_state_news` "Latest News & Updates"
	 * query.
	 *
	 * @param WP_Query $query The widget's query, before it runs.
	 */
	private function filter_news_query( $query ) {
		$query->set( 'post_status', 'publish' );
		$query->set( 'ignore_sticky_posts', true );
		$query->set( 'post_type', self::NEWS_POST_TYPE );

		$term_ids = self::get_state_category_ids( self::get_state_id() );

		if ( ! $term_ids ) {
			// Same "no posts" convention as post__in above: a state with no
			// matching category should list nothing, not fall through to
			// the widget's own default (every post on the site).
			$query->set( 'post__in', array( 0 ) );
			return;
		}

		$query->set(
			'tax_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a handful of term_ids, not a filterable archive.
			array(
				array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => $term_ids,
				),
			)
		);
	}

	/**
	 * The `category` term(s) that feed this state's news section, in two
	 * tiers:
	 *
	 * 1. **Admin-picked** - the `ryv_news_categories` ACF field
	 *    (RYV_States_ACF::register_news_group()), when the admin has chosen
	 *    one or more categories from the state's own edit screen. Preferred
	 *    because a state's category doesn't always share its exact slug, and
	 *    more than one category can legitimately feed one state.
	 * 2. **Automatic fallback** - a single category matched by slug against
	 *    the state's own `ryv_state_slug` ACF field (falling back to its post
	 *    slug, then its title), for a state nobody has curated yet.
	 *
	 * Either way, ids are mapped to the language being viewed: WPML keeps a
	 * separate category term per language (confirmed on this site - e.g. two
	 * "KENTUCKY" terms sharing one slug), so an id picked - or auto-matched -
	 * on one language edition needs translating to resolve the right term on
	 * another, the same reasoning `map_ids_to_current_language()` applies to
	 * picked event/resource ids above.
	 *
	 * @param int $state_id `ryv_states` post ID, or 0.
	 * @return int[] Category term_ids, empty if none matches.
	 */
	private static function get_state_category_ids( int $state_id ): array {
		if ( ! $state_id ) {
			return array();
		}

		$picked = function_exists( 'get_field' ) ? get_field( 'ryv_news_categories', $state_id ) : null;

		if ( is_array( $picked ) && $picked ) {
			$ids = array();

			foreach ( $picked as $item ) {
				$id = is_object( $item ) ? (int) $item->term_id : (int) $item;
				if ( $id ) {
					$ids[] = $id;
				}
			}

			if ( $ids ) {
				return self::map_term_ids_to_current_language( array_values( array_unique( $ids ) ) );
			}
		}

		$term_id = self::auto_match_category_id( $state_id );

		return $term_id ? self::map_term_ids_to_current_language( array( $term_id ) ) : array();
	}

	/**
	 * The single category term auto-matched to a state by slug, with no
	 * language mapping and no `ryv_news_categories` override applied - the
	 * raw "what would this state auto-match to" answer.
	 *
	 * Pulled out of `get_state_category_ids()` so `RYV_News_Categories_Sync`
	 * (the "Sync News Categories" admin tool, RYV States → Generate States)
	 * can offer an admin the exact same match this class would fall back to
	 * at render time, rather than a second, possibly-diverging guess.
	 *
	 * @param int $state_id `ryv_states` post ID, or 0.
	 * @return int Category term_id in the state post's own language/edition, or 0.
	 */
	public static function auto_match_category_id( int $state_id ): int {
		if ( ! $state_id ) {
			return 0;
		}

		$slug = function_exists( 'get_field' ) ? (string) get_field( 'ryv_state_slug', $state_id ) : '';

		if ( '' === $slug ) {
			$slug = (string) get_post_field( 'post_name', $state_id );
		}

		$slug = sanitize_title( $slug );

		$term = $slug ? get_term_by( 'slug', $slug, 'category' ) : false;

		if ( ! $term || is_wp_error( $term ) ) {
			$term = get_term_by( 'name', get_the_title( $state_id ), 'category' );
		}

		return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
	}

	/**
	 * Term-taxonomy counterpart of `map_ids_to_current_language()`: swaps
	 * each category id for its translation in the language being viewed,
	 * keeping the original where there isn't one (or WPML isn't active).
	 *
	 * @param int[] $term_ids Category term ids.
	 * @return int[]
	 */
	private static function map_term_ids_to_current_language( array $term_ids ): array {
		if ( ! $term_ids || ! has_filter( 'wpml_object_id' ) ) {
			return $term_ids;
		}

		$mapped = array();

		foreach ( $term_ids as $term_id ) {
			$translated = apply_filters( 'wpml_object_id', $term_id, 'category', true );
			$mapped[]   = $translated ? (int) $translated : (int) $term_id;
		}

		return array_values( array_unique( $mapped ) );
	}

	/**
	 * Whether this state has any published posts in its matching category
	 * (or categories) - the check behind the
	 * `ryv-hide-empty--query-ryv_state_news` marker, so the "Latest News &
	 * Updates" section disappears entirely rather than rendering an empty
	 * grid.
	 *
	 * @param int $state_id `ryv_states` post ID.
	 * @return bool
	 */
	public static function has_news_posts( int $state_id ): bool {
		$term_ids = self::get_state_category_ids( $state_id );

		if ( ! $term_ids ) {
			return false;
		}

		$query = new WP_Query(
			array(
				'post_type'           => self::NEWS_POST_TYPE,
				'post_status'         => 'publish',
				'posts_per_page'      => 1,
				'fields'              => 'ids',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
				'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a handful of term_ids, not a filterable archive.
					array(
						'taxonomy' => 'category',
						'field'    => 'term_id',
						'terms'    => $term_ids,
					),
				),
			)
		);

		return $query->found_posts > 0;
	}

	/**
	 * The ids picked in $field on $state_id, reduced to the ones that are
	 * actually still publishable.
	 *
	 * That filtering is not defensive padding: a relationship field keeps
	 * storing an id after the post it points at is deleted (confirmed on this
	 * site - a state referencing an event id that no longer exists), and
	 * feeding a dead id to `post__in` would just silently shrink the list,
	 * or - if every id were dead - leave a non-empty `post__in` that matches
	 * nothing while looking like a working query.
	 *
	 * Read through `get_field()` when ACF is active so the field's own return
	 * format applies, with a raw postmeta fallback so this keeps working
	 * (rather than fatally, or silently emptily) without it.
	 *
	 * @param int    $state_id `ryv_states` post ID, or 0.
	 * @param string $field    ACF relationship field name.
	 * @return int[]
	 */
	private function get_related_ids( int $state_id, string $field ): array {
		if ( ! $state_id ) {
			return array();
		}

		$raw = function_exists( 'get_field' )
			? get_field( $field, $state_id )
			: get_post_meta( $state_id, $field, true );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();

		foreach ( $raw as $item ) {
			// `return_format` is 'id' on both fields today, but a
			// relationship field can be switched to return post objects from
			// the ACF UI without any code change - handle both rather than
			// break silently if it is.
			$id = is_object( $item ) ? (int) $item->ID : (int) $item;

			if ( $id && 'publish' === get_post_status( $id ) ) {
				$ids[] = $id;
			}
		}

		return $this->map_ids_to_current_language( array_values( array_unique( $ids ) ) );
	}

	/**
	 * Swap each id for its translation in the language being viewed, keeping
	 * the original where there isn't one.
	 *
	 * So a Spanish state page shows the Spanish edition of a picked resource
	 * where it has been translated, and the English one where it hasn't -
	 * rather than dropping it, which is what WPML's own filtering does once
	 * `lang => all` above stops it filtering by language.
	 *
	 * @param int[] $ids Post ids picked on the state.
	 * @return int[]
	 */
	private function map_ids_to_current_language( array $ids ): array {
		if ( ! $ids || ! has_filter( 'wpml_object_id' ) ) {
			return $ids;
		}

		$mapped = array();

		foreach ( $ids as $id ) {
			// `true` = return the original when no translation exists.
			$translated = apply_filters( 'wpml_object_id', $id, get_post_type( $id ), true );
			$mapped[]   = $translated ? (int) $translated : (int) $id;
		}

		return array_values( array_unique( $mapped ) );
	}

	/**
	 * Whether events that have already finished are dropped from the list.
	 *
	 * Defaults to true - "What's happening locally" showing last month's
	 * clinic is worse than showing nothing - and is filterable for a
	 * placement that wants a full history.
	 *
	 * @return bool
	 */
	private function should_hide_past_events(): bool {
		/**
		 * Filters whether past events are hidden in the state-events query.
		 *
		 * @param bool $hide_past Default true.
		 */
		return (bool) apply_filters( 'ryv_state_events_hide_past', true );
	}
}
