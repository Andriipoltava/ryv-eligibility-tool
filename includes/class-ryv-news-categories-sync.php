<?php
/**
 * Bulk "Sync News Categories" tool: walks every `ryv_states` post and its
 * own auto-matched `category` (RYV_State_Queries::auto_match_category_id() -
 * the same by-slug match the `ryv_state_news` query itself falls back to at
 * render time) and writes it into the state's `ryv_news_categories` ACF
 * field, so an admin doesn't have to open all 50-odd state edit screens by
 * hand to get the "Latest News & Updates" section wired up.
 *
 * Deliberately additive, never destructive of a manual pick: a state whose
 * `ryv_news_categories` is already set (an admin explicitly curated it,
 * possibly to a category with a different slug, or to more than one) is
 * left untouched unless `$force` is passed - same "fill in what's missing,
 * only overwrite on an explicit repeat action" split
 * RYV_States_ACF::maybe_seed_map_defaults() uses for map categories, exposed
 * the same way via the "Sync News Categories" button on
 * **RYV States → Generate States**.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_News_Categories_Sync
 */
class RYV_News_Categories_Sync {

	/**
	 * Walk every state, auto-matching a `category` by slug and writing it to
	 * `ryv_news_categories`.
	 *
	 * @param bool $force When true, also overwrites a state that already has
	 *                     a `ryv_news_categories` value with the fresh
	 *                     auto-match (the "Sync News Categories" button's own
	 *                     re-run case); when false, only fills states with no
	 *                     value at all.
	 * @return array{updated:array<int,array{state_id:int,title:string,category_id:int,category_name:string}>,unchanged:int,no_match:array<int,string>}
	 */
	public static function sync_all( bool $force = false ): array {
		$result = array(
			'updated'   => array(),
			'unchanged' => 0,
			'no_match'  => array(),
		);

		if ( ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) ) {
			return $result;
		}

		$state_ids = get_posts(
			array(
				'post_type'        => RYV_States_CPT::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		foreach ( $state_ids as $state_id ) {
			$existing = get_field( 'ryv_news_categories', $state_id );

			if ( ! $force && is_array( $existing ) && $existing ) {
				++$result['unchanged'];
				continue;
			}

			$term_id = RYV_State_Queries::auto_match_category_id( $state_id );

			if ( ! $term_id ) {
				$result['no_match'][ $state_id ] = get_the_title( $state_id );
				continue;
			}

			// Nothing to write if the auto-match is already exactly what's
			// stored (a $force re-run against an already-synced state) -
			// avoids a spurious ACF save on every run.
			$current_ids = array();
			if ( is_array( $existing ) ) {
				foreach ( $existing as $item ) {
					$current_ids[] = is_object( $item ) ? (int) $item->term_id : (int) $item;
				}
			}

			if ( array( $term_id ) === array_values( $current_ids ) ) {
				++$result['unchanged'];
				continue;
			}

			update_field( 'ryv_news_categories', array( $term_id ), $state_id );

			$term = get_term( $term_id, 'category' );

			$result['updated'][] = array(
				'state_id'      => $state_id,
				'title'         => get_the_title( $state_id ),
				'category_id'   => $term_id,
				'category_name' => ( $term && ! is_wp_error( $term ) ) ? $term->name : '',
			);
		}

		return $result;
	}
}
