<?php
/**
 * One-time repair: 13 states (DC, NH, NJ, NM, NY, NC, ND, PR, RI, SC, SD, VA,
 * WV) use their 2-letter postal abbreviation as the state-picker option
 * `value` in both `ryv_forms` editions, instead of the full state name every
 * other state uses ("Alabama", "Kentucky", ...) - almost certainly inherited
 * from the original Drupal export. The corresponding `ryv_states` posts'
 * own `ryv_state_slug` field was generated to match (`"va"`, `"pr"`, ...)
 * instead of the canonical slug (`RYV_US_States::MAP`) every other state
 * uses (`"virginia"`, `"puerto-rico"`).
 *
 * This is silently correct as long as both sides agree with EACH OTHER, but
 * breaks two things that each independently expect the canonical slug:
 * - RYV_States_Sync's Form Node Editor (`sanitize_title($state_slug)` vs.
 *   `sanitize_title($option['value'])` - "No nodes found for state ...").
 * - the widget's "Auto-Detect State From Page" entry-node resolution, which
 *   uses the exact same lookup (RYV_States_Sync::find_start_node()).
 *
 * Fixes both sides consistently: every `ryv_forms` post's state-picker
 * options, and every `ryv_states` post's `ryv_state_slug`, abbreviation ->
 * canonical full slug. Idempotent (an already-canonical value is left
 * alone) and non-destructive to anything else in either post.
 *
 * Deliberately its own file/class, same reasoning as
 * class-ryv-partners-migration.php: delete this file (and its admin page /
 * WP-CLI command) once the live site has been repaired and QA'd.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_State_Slug_Repair
 */
class RYV_State_Slug_Repair {

	/**
	 * Abbreviation (as it appears in a form's option `value`, or lowercased
	 * as a `ryv_state_slug`) -> canonical slug (`RYV_US_States::MAP` key).
	 * Built from `RYV_US_States::SVG_IDS` (slug -> abbreviation) rather than
	 * a second hardcoded table, so the two can never drift apart.
	 *
	 * @return array<string,string>
	 */
	private static function abbrev_to_slug(): array {
		return array_flip( RYV_US_States::SVG_IDS );
	}

	/**
	 * @param bool $dry_run
	 * @return array{forms: array, states: array, totals: array<string,int>}
	 */
	public static function repair( bool $dry_run = true ): array {
		$abbrev_to_slug = self::abbrev_to_slug();

		$report = array(
			'forms'  => array(),
			'states' => array(),
			'totals' => array(
				'forms_changed'    => 0,
				'options_changed'  => 0,
				'states_changed'   => 0,
				'states_unchanged' => 0,
			),
		);

		foreach ( self::repair_forms( $abbrev_to_slug, $dry_run ) as $row ) {
			$report['forms'][] = $row;
			$report['totals']['options_changed'] += count( $row['changed_options'] );
		}
		$report['totals']['forms_changed'] = count(
			array_filter( $report['forms'], static fn( $r ) => ! empty( $r['changed_options'] ) )
		);

		foreach ( self::repair_states( $abbrev_to_slug, $dry_run ) as $row ) {
			$report['states'][] = $row;
			if ( $row['changed'] ) {
				++$report['totals']['states_changed'];
			} else {
				++$report['totals']['states_unchanged'];
			}
		}

		return $report;
	}

	/**
	 * @param array<string,string> $abbrev_to_slug
	 * @param bool                 $dry_run
	 * @return array<int, array{form_id:int, title:string, changed_options: array}>
	 */
	private static function repair_forms( array $abbrev_to_slug, bool $dry_run ): array {
		if ( ! class_exists( 'RYV_CPT' ) ) {
			return array();
		}

		$form_ids = get_posts(
			array(
				'post_type'        => 'ryv_forms',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		$rows = array();

		foreach ( $form_ids as $form_id ) {
			$schema = RYV_CPT::get_schema( (int) $form_id );
			$entry  = $schema['entry_node'] ?? null;

			if ( ! $entry || empty( $schema['nodes'][ $entry ]['options'] ) || ! is_array( $schema['nodes'][ $entry ]['options'] ) ) {
				continue;
			}

			$changed_options = array();

			foreach ( $schema['nodes'][ $entry ]['options'] as &$option ) {
				$value = $option['value'] ?? '';
				if ( isset( $abbrev_to_slug[ $value ] ) && isset( RYV_US_States::MAP[ $abbrev_to_slug[ $value ] ] ) ) {
					$full = RYV_US_States::MAP[ $abbrev_to_slug[ $value ] ];
					$changed_options[] = array(
						'from' => $value,
						'to'   => $full,
					);
					$option['value'] = $full;
				}
			}
			unset( $option );

			if ( $changed_options ) {
				$rows[] = array(
					'form_id'         => (int) $form_id,
					'title'           => get_the_title( $form_id ),
					'changed_options' => $changed_options,
				);

				if ( ! $dry_run ) {
					RYV_CPT::save_schema( (int) $form_id, $schema );
				}
			}
		}

		return $rows;
	}

	/**
	 * @param array<string,string> $abbrev_to_slug
	 * @param bool                 $dry_run
	 * @return array<int, array{post_id:int, title:string, from:string, to:string, changed:bool}>
	 */
	private static function repair_states( array $abbrev_to_slug, bool $dry_run ): array {
		if ( ! class_exists( 'RYV_States_CPT' ) ) {
			return array();
		}

		$abbrev_to_slug_lower = array_change_key_case( $abbrev_to_slug, CASE_LOWER );

		$post_ids = get_posts(
			array(
				'post_type'        => RYV_States_CPT::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		$rows = array();

		foreach ( $post_ids as $post_id ) {
			$current = (string) get_post_meta( (int) $post_id, 'ryv_state_slug', true );

			if ( ! isset( $abbrev_to_slug_lower[ $current ] ) ) {
				continue; // Already canonical (or empty/unrecognized) - nothing to do.
			}

			$canonical = $abbrev_to_slug_lower[ $current ];

			$rows[] = array(
				'post_id' => (int) $post_id,
				'title'   => get_the_title( $post_id ),
				'from'    => $current,
				'to'      => $canonical,
				'changed' => true,
			);

			if ( ! $dry_run ) {
				// A stray duplicate meta row (same key+value twice) was
				// found on every affected post during investigation -
				// deleting and re-inserting cleans that up as a side
				// effect, rather than leaving a duplicate behind.
				delete_post_meta( (int) $post_id, 'ryv_state_slug' );
				add_post_meta( (int) $post_id, 'ryv_state_slug', $canonical, true );
			}
		}

		return $rows;
	}
}
