<?php
/**
 * Brings the State Post Elementor template to the configuration the state
 * pages need, so it can be reproduced on another environment with one click
 * instead of being re-authored by hand in the editor.
 *
 * Two things it fixes, both of which have already broken this site once:
 *
 * 1. **Removes every `e_display_conditions`.** Elementor Pro's Display
 *    Conditions comparator is typed `string` and fatals on an ACF field that
 *    returns an array - a relationship like `ryv_events`/`ryv_resources`:
 *    "Comparators_Checker::check_string_contains_and_empty(): Argument #3
 *    ($actual_value) must be of type string, array given". That kills the
 *    whole template's render, and **every state page went blank below the
 *    header** while the posts and the template data were perfectly intact.
 *
 * 2. **Applies this plugin's own `ryv-hide-empty--...` markers instead**,
 *    which handle array-returning fields correctly (see
 *    RYV_States_Template).
 *
 * 3. **Sets each Loop Grid's Query ID** (`ryv_state_resources` /
 *    `ryv_state_events`). A Loop Grid with none runs its own default query -
 *    every resource on the site, not the ones this state picked - which on a
 *    translated page matches nothing at all, leaving those sections rendered
 *    but empty *and still visible*: the section's ACF field is populated, so
 *    the hide-empty marker correctly keeps it. That is the reported "empty
 *    sections aren't hidden on translations".
 *
 * Elements are matched by their Elementor id, which is stable across
 * exports/imports of the same template - and any id that isn't found is
 * simply reported rather than treated as an error, since a template that has
 * since been re-authored may legitimately no longer have it.
 *
 * Idempotent: re-running changes nothing once the template is already in
 * this shape.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_States_Template_Repair
 */
class RYV_States_Template_Repair {

	/**
	 * Elementor element id => the marker it should carry ('' removes any).
	 *
	 * The two `any-` markers are load-bearing rather than cosmetic:
	 * - the Partner section also contains the events column, so keying it on
	 *   the partner alone would hide the events of a state that has events
	 *   but no partner;
	 * - the hero stats card holds both the stat columns and the Issue
	 *   column, so it may only disappear once both are empty.
	 *
	 * `Row · Featured partner` is deliberately cleared: it wraps the partner
	 * card *and* the events column, and its children each carry their own
	 * marker already.
	 *
	 * @var array<string,string>
	 */
	const MARKERS = array(
		// 04 · At a glance.
		'6d83c570' => 'ryv-hide-empty--field-ryv_glance_body',
		// 07 · Partner highlight (also holds the events column).
		'4752e0d8' => 'ryv-hide-empty--any-ryv_partner_name,ryv_partner_description,ryv_events',
		// Row · Featured partner - see above.
		'5f38f78a' => '',
		// Card · Featured partner.
		'7e0b3673' => 'ryv-hide-empty--field-ryv_partner_name',
		// Col · What's happening locally (events).
		'5793f9f'  => 'ryv-hide-empty--field-ryv_events',
		// 10 · Resources.
		'c896d1d'  => 'ryv-hide-empty--field-ryv_resources',
		// Card · hero stats box.
		'39d232c0' => 'ryv-hide-empty--any-ryv_hero_stats,ryv_hero_issue_tag',
		// Col · Issue, inside that card.
		'55fbef1f' => 'ryv-hide-empty--field-ryv_hero_issue_tag',
		// Card · The short answer.
		'6fc88d22' => 'ryv-hide-empty--field-ryv_short_answer_text',
		// The three hero stat columns.
		'9a85e3e'  => 'ryv-hide-empty--repeater-ryv_hero_stats-0',
		'21bcaf18' => 'ryv-hide-empty--repeater-ryv_hero_stats-1',
		'6579184e' => 'ryv-hide-empty--repeater-ryv_hero_stats-2',
	);

	/**
	 * Loop Grid element id => the Query ID it must carry.
	 *
	 * Without one, a Loop Grid runs its own default query - every resource on
	 * the site rather than the ones this state picked - which is why those
	 * sections came out empty on a translated page (nothing matches that
	 * language) while the section itself stayed visible: its ACF field is
	 * populated, so the hide-empty marker correctly kept it.
	 *
	 * @var array<string,string>
	 */
	const QUERY_IDS = array(
		// Row · Resources.
		'7149e1b' => 'ryv_state_resources',
		// Col · What's happening locally (events).
		'242736c' => 'ryv_state_events',
	);

	/**
	 * Apply the configuration to every `elementor_library` template that
	 * contains at least one of the ids above.
	 *
	 * Scans all templates rather than taking an id, because the State Post
	 * template's post ID differs per environment (a duplicate, an import, a
	 * "State Post." copy) while the element ids inside it do not.
	 *
	 * @param bool $dry_run When true, report what would change without writing.
	 * @return array{templates:array<int,array>,conditions_removed:int,markers_set:int,missing:string[]}
	 */
	public static function repair( bool $dry_run = false ): array {
		$result = array(
			'templates'          => array(),
			'conditions_removed' => 0,
			'markers_set'        => 0,
			'query_ids_set'      => 0,
			'missing'            => array_keys( self::MARKERS ),
		);

		$template_ids = get_posts(
			array(
				'post_type'        => 'elementor_library',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		foreach ( $template_ids as $template_id ) {
			$raw = get_post_meta( $template_id, '_elementor_data', true );

			if ( ! is_string( $raw ) || '' === $raw ) {
				continue;
			}

			$data = json_decode( $raw, true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			$changes = array(
				'conditions_removed' => 0,
				'markers_set'        => array(),
				'query_ids_set'      => array(),
			);

			self::walk( $data, $changes, $result['missing'] );

			if ( ! $changes['conditions_removed'] && ! $changes['markers_set'] && ! $changes['query_ids_set'] ) {
				continue;
			}

			$result['templates'][] = array(
				'id'                 => (int) $template_id,
				'title'              => get_the_title( $template_id ),
				'conditions_removed' => $changes['conditions_removed'],
				'markers_set'        => $changes['markers_set'],
				'query_ids_set'      => $changes['query_ids_set'],
			);

			$result['conditions_removed'] += $changes['conditions_removed'];
			$result['markers_set']        += count( $changes['markers_set'] );
			$result['query_ids_set']      += count( $changes['query_ids_set'] );

			if ( ! $dry_run ) {
				update_post_meta( $template_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
			}
		}

		if ( ! $dry_run && ( $result['conditions_removed'] || $result['markers_set'] || $result['query_ids_set'] ) ) {
			// The marker list is cached, and Elementor caches its generated
			// CSS - neither would otherwise notice this.
			if ( class_exists( 'RYV_States_Template' ) ) {
				RYV_States_Template::flush_marker_cache();
			}

			if ( class_exists( '\Elementor\Plugin' ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		}

		$result['missing'] = array_values( $result['missing'] );

		return $result;
	}

	/**
	 * Walk an element tree, applying both fixes in place.
	 *
	 * @param array    $elements Elementor elements, by reference.
	 * @param array    $changes  Running tally, by reference.
	 * @param string[] $missing  Ids not seen yet, by reference.
	 */
	private static function walk( array &$elements, array &$changes, array &$missing ) {
		foreach ( $elements as &$element ) {
			if ( isset( $element['settings']['e_display_conditions'] ) ) {
				unset( $element['settings']['e_display_conditions'] );
				$changes['conditions_removed']++;
			}

			$id = $element['id'] ?? '';

			if ( isset( self::MARKERS[ $id ] ) ) {
				$missing = array_diff( $missing, array( $id ) );

				$current = (string) ( $element['settings']['css_classes'] ?? '' );
				$updated = self::apply_marker( $current, self::MARKERS[ $id ] );

				if ( $updated !== $current ) {
					$element['settings']['css_classes'] = $updated;
					$changes['markers_set'][ $id ]      = $updated;
				}
			}

			if ( isset( self::QUERY_IDS[ $id ] ) && ( $element['settings']['query_id'] ?? '' ) !== self::QUERY_IDS[ $id ] ) {
				$element['settings']['query_id'] = self::QUERY_IDS[ $id ];
				$changes['query_ids_set'][ $id ] = self::QUERY_IDS[ $id ];
			}

			if ( ! empty( $element['elements'] ) ) {
				self::walk( $element['elements'], $changes, $missing );
			}
		}
	}

	/**
	 * Replace whatever marker a class string carries with $marker, leaving
	 * every other class the admin put there untouched.
	 *
	 * One marker per element, because `RYV_States_Template::get_marker()`
	 * reads the first it finds - appending a second would silently do
	 * nothing.
	 *
	 * @param string $classes Existing CSS classes.
	 * @param string $marker  Marker to apply, or '' to just remove.
	 * @return string
	 */
	private static function apply_marker( string $classes, string $marker ): string {
		$kept = array();

		foreach ( preg_split( '/\s+/', trim( $classes ), -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
			if ( 0 === strpos( $class, RYV_States_Template::MARKER_PREFIX ) ) {
				continue;
			}

			// Also drop a marker that has lost its prefix. Two were found in
			// this state in the live template ("any-ryv_hero_stats,..." and
			// "field-ryv_hero_issue_tag"), presumably mangled by an edit -
			// they do nothing, since nothing looks for them, and leaving them
			// behind would make the class list read as though the section
			// were still gated.
			if ( preg_match( '/^(field|any|repeater)-[A-Za-z0-9_,\-]+$/', $class ) ) {
				continue;
			}

			$kept[] = $class;
		}

		if ( '' !== $marker ) {
			$kept[] = $marker;
		}

		return implode( ' ', $kept );
	}
}
