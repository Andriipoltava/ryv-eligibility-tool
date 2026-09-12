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
 * 4. **Rebinds the Hero H1's dynamic tag to `[ryv_state_field]`**
 *    (RYV_States_Field_Shortcode) via Elementor Pro's own Shortcode dynamic
 *    tag. The English edition's H1 was wired to an ACF Field tag scoped
 *    `options:ryv_hero_headline` - an *Options Page* field, not the current
 *    state post's own `ryv_hero_headline` - so it always resolved empty and
 *    the headline rendered blank on every state (confirmed live on
 *    Vermont). The Spanish edition instead kept Elementor's own Post Title
 *    tag, which never picks up a custom `ryv_hero_headline` at all. Both are
 *    replaced with the shortcode, which reads the *current* state's own
 *    field and falls back to the post title when it's empty - exactly the
 *    behavior RYV_States_Field_Shortcode's own docblock describes this
 *    widget as needing.
 *
 * 5. **Force-writes plain `settings` values a brand-new widget control has
 *    never been saved with.** Elementor only persists a control's value
 *    into `_elementor_data` once an admin actually opens and edits it in
 *    the panel - `get_settings_for_display()` supplies the class-level
 *    default at *render* time regardless, so this went unnoticed on the
 *    frontend. But WPML's Elementor string-registration scan reads the
 *    *stored* settings, not the resolved display value - so a just-added
 *    control (`label_back`, the "Back" button's Content-tab label) had no
 *    key to find no matter how many times the template was re-saved
 *    (confirmed live: re-saving both language editions changed nothing in
 *    `wp_icl_strings` until this fix explicitly wrote the key). Writing it
 *    here once is exactly equivalent to an admin typing the same text into
 *    the field by hand and clicking Update.
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
		// 06 · Latest news (Latest News & Updates - hidden when the state has
		// no posts in its matching category, see RYV_State_Queries).
		'78152eaf' => 'ryv-hide-empty--query-ryv_state_news',
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
		// Loop Grid · Latest News & Updates.
		'd7a134d' => 'ryv_state_news',
	);

	/**
	 * Widget element id => the `[ryv_state_field]` shortcode its `title`
	 * (or, for a future entry, another) dynamic-tag setting should carry -
	 * see RYV_States_Field_Shortcode and point 4 of the class docblock.
	 *
	 * Applied via `settings.__dynamic__[$setting]`, Elementor Pro's own
	 * "Shortcode" dynamic tag (`name: 'shortcode'`) - not a plain string in
	 * `settings.title` itself, which Elementor would just print literally
	 * (`[ryv_state_field]` as visible text) rather than evaluate.
	 *
	 * @var array<string,array{setting:string,shortcode:string}>
	 */
	const DYNAMIC_TAG_FIXES = array(
		// H · Hero H1 (Felony Voting Rights Restoration In {State}).
		'14ecb78e' => array(
			'setting'   => 'title',
			'shortcode' => '[ryv_state_field]',
		),
	);

	/**
	 * Widget element id => plain `settings` key/value pairs to force-write.
	 *
	 * Elementor only persists a control's value into `_elementor_data` once
	 * an admin actually opens and edits it in the panel - a brand-new
	 * control (like `label_back`, added after this template was last
	 * touched) has no key in the stored JSON at all, `get_settings_for_display()`
	 * only ever supplying its class-level default at *render* time. That
	 * silently breaks WPML: its Elementor string-registration scan reads the
	 * *stored* settings, not the resolved display value, so a control with
	 * no key yet never gets a string to offer for translation no matter how
	 * many times the template is re-saved - confirmed live (re-saving both
	 * language editions of "State Post!" changed nothing in `wp_icl_strings`
	 * until this fix explicitly wrote the key). Setting it here once is
	 * exactly equivalent to an admin typing the same text into the field by
	 * hand and clicking Update.
	 *
	 * @var array<string,array<string,string>>
	 */
	const STATIC_SETTING_FIXES = array(
		// The ryv_eligibility_tool widget itself (Voting Rights form).
		'4a4fa476' => array(
			'label_back' => '← Back',
		),
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
			'dynamic_tags_set'   => 0,
			'static_settings_set' => 0,
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
				'conditions_removed'  => 0,
				'markers_set'         => array(),
				'query_ids_set'       => array(),
				'dynamic_tags_set'    => array(),
				'static_settings_set' => array(),
			);

			self::walk( $data, $changes, $result['missing'] );

			if ( ! $changes['conditions_removed'] && ! $changes['markers_set'] && ! $changes['query_ids_set'] && ! $changes['dynamic_tags_set'] && ! $changes['static_settings_set'] ) {
				continue;
			}

			$result['templates'][] = array(
				'id'                  => (int) $template_id,
				'title'               => get_the_title( $template_id ),
				'conditions_removed'  => $changes['conditions_removed'],
				'markers_set'         => $changes['markers_set'],
				'query_ids_set'       => $changes['query_ids_set'],
				'dynamic_tags_set'    => $changes['dynamic_tags_set'],
				'static_settings_set' => $changes['static_settings_set'],
			);

			$result['conditions_removed']   += $changes['conditions_removed'];
			$result['markers_set']          += count( $changes['markers_set'] );
			$result['query_ids_set']        += count( $changes['query_ids_set'] );
			$result['dynamic_tags_set']     += count( $changes['dynamic_tags_set'] );
			$result['static_settings_set']  += count( $changes['static_settings_set'] );

			if ( ! $dry_run ) {
				update_post_meta( $template_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
			}
		}

		if ( ! $dry_run && ( $result['conditions_removed'] || $result['markers_set'] || $result['query_ids_set'] || $result['dynamic_tags_set'] || $result['static_settings_set'] ) ) {
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

			if ( isset( self::DYNAMIC_TAG_FIXES[ $id ] ) ) {
				$fix     = self::DYNAMIC_TAG_FIXES[ $id ];
				$desired = self::build_shortcode_tag( $fix['shortcode'] );
				$current = (string) ( $element['settings']['__dynamic__'][ $fix['setting'] ] ?? '' );

				// Only the tag *name* has to match, not the id Elementor
				// randomly assigns each tag instance - re-running this would
				// otherwise "fix" an already-fixed widget on every call,
				// since a freshly generated id never matches the last one
				// written.
				if ( false === strpos( $current, 'name="shortcode"' ) || false === strpos( $current, rawurlencode( $fix['shortcode'] ) ) ) {
					$element['settings']['__dynamic__'][ $fix['setting'] ] = $desired;
					$changes['dynamic_tags_set'][ $id ]                    = $fix['shortcode'];
				}
			}

			if ( isset( self::STATIC_SETTING_FIXES[ $id ] ) ) {
				foreach ( self::STATIC_SETTING_FIXES[ $id ] as $setting_key => $value ) {
					if ( ( $element['settings'][ $setting_key ] ?? null ) !== $value ) {
						$element['settings'][ $setting_key ]        = $value;
						$changes['static_settings_set'][ $id ][ $setting_key ] = $value;
					}
				}
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

	/**
	 * Builds an `[elementor-tag ...]` string for Elementor Pro's "Shortcode"
	 * dynamic tag (`ElementorPro\Modules\DynamicTags\Tags\Shortcode`,
	 * `get_name() === 'shortcode'`), in the exact shape Elementor itself
	 * writes to `_elementor_data` - a random-looking hex id (any unique
	 * string works; nothing looks it up in a registry, it just has to be
	 * present) plus the tag name and a `rawurlencode(wp_json_encode(...))`
	 * settings blob, matching what was found in this template's own
	 * `options:ryv_hero_headline` binding this replaces.
	 *
	 * @param string $shortcode Raw shortcode string, e.g. `[ryv_state_field]`.
	 * @return string
	 */
	private static function build_shortcode_tag( string $shortcode ): string {
		$id       = substr( md5( $shortcode ), 0, 7 );
		$settings = rawurlencode( wp_json_encode( array( 'shortcode' => $shortcode ) ) );

		return sprintf( '[elementor-tag id="%s" name="shortcode" settings="%s"]', $id, $settings );
	}
}
