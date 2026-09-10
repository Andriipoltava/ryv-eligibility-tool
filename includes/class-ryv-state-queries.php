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
		foreach ( array_keys( $this->get_queries() ) as $query_id ) {
			add_action(
				'elementor/query/' . $query_id,
				function ( $query ) use ( $query_id ) {
					$this->filter_query( $query, $query_id );
				}
			);
		}
	}

	/**
	 * @param WP_Query $query    The widget's query, before it runs.
	 * @param string   $query_id Which of get_queries() fired this.
	 */
	public function filter_query( $query, string $query_id ) {
		if ( ! $query instanceof WP_Query ) {
			return;
		}

		$queries = $this->get_queries();

		if ( ! isset( $queries[ $query_id ] ) ) {
			return;
		}

		$ids = $this->get_related_ids( $this->get_state_id(), $queries[ $query_id ] );

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
	 * placed somewhere else that has a state in context.
	 *
	 * @return int
	 */
	private function get_state_id(): int {
		$queried_id = (int) get_queried_object_id();

		if ( $queried_id && RYV_States_CPT::POST_TYPE === get_post_type( $queried_id ) ) {
			return $queried_id;
		}

		$post_id = (int) get_the_ID();

		return ( $post_id && RYV_States_CPT::POST_TYPE === get_post_type( $post_id ) ) ? $post_id : 0;
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
