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
 * 6. **Wires the Partner highlight photo, the Resources grid and the At a
 *    glance columns** (dynamic tag, Query ID + stray term filter, full-width
 *    text when there is no image) - see IMAGE_TAGS_BY_CLASS,
 *    STRIP_SETTINGS and WIDEN_MARKERS.
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
		// "Source: ..." lines under the hero stats (added by SOURCE_WIDGET_ID).
		'ryvsrc1'  => 'ryv-hide-empty--field-ryv_hero_stats_sources',
		// At a glance: the image column goes away when there is no image...
		'148bd5a4' => 'ryv-hide-empty--field-ryv_glance_image',
	);

	/**
	 * Element id => `ryv-full-width-if-empty--...` marker (see
	 * RYV_States_Template::WIDEN_PREFIX). The At a glance text column takes
	 * the full row when the state has no image to put beside it.
	 *
	 * @var array<string,string>
	 */
	const WIDEN_MARKERS = array(
		'43435bf0' => 'ryv-full-width-if-empty--field-ryv_glance_image',
	);

	/**
	 * CSS class on an Image widget => the dynamic tag that must feed it.
	 * The Partner highlight photo was bound to the post's *featured image*,
	 * which state pages never set, so it rendered nothing; the section's own
	 * "Main Image" field is read by RYV_Tag_Featured_Partners_Image.
	 *
	 * @var array<string,string>
	 */
	const IMAGE_TAGS_BY_CLASS = array(
		'ryv-partner-hero-image' => 'ryv-featured-partners-image',
	);

	/**
	 * Loop Grid element id => static settings that must be removed. The
	 * Resources grid was saved with a fixed "include these terms" filter,
	 * which is ANDed with the state's own list of picked resources, so a
	 * state's resources were dropped (or other states' shown) unless they
	 * also carried that term.
	 *
	 * @var array<string,string[]>
	 */
	const STRIP_SETTINGS = array(
		'7149e1b' => array( 'post_query_include', 'post_query_include_term_ids' ),
	);

	/**
	 * Element id of the hero stats card the "Source:" line is appended to
	 * (last child, so it sits full-width below both stat rows), and the id
	 * given to the Text Editor widget holding `[ryv_stats_source]`. Its
	 * typography mirrors the stat description widgets. Added only when
	 * missing, so re-running is a no-op.
	 */
	const SOURCE_PARENT_ID = '39d232c0';
	const SOURCE_WIDGET_ID = 'ryvsrc1';

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
			'spanish_synced'     => array(),
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

				// Elementor keeps a rendered-element cache beside the data and
				// can keep serving it after the data changed - stale output
				// there is how a section that exists in the data was missing
				// from the page.
				delete_post_meta( $template_id, '_elementor_element_cache' );
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

		// After the English template has been repaired, so the Spanish one is
		// built from the finished version.
		$result['spanish_synced'] = self::sync_spanish_template( $dry_run );

		$result['missing'] = array_values( $result['missing'] );

		return $result;
	}

	/**
	 * Spanish wording for elements the Spanish template doesn't have (or has
	 * with an older English-era label), by Elementor id - applied on top of
	 * the English structure by sync_spanish_template().
	 *
	 * @var array<string,array<string,string>>
	 */
	const SPANISH_TEXT_BY_ID = array(
		'3d1c021f' => array( 'title' => 'Lo que necesita saber' ),
		'28a15c04' => array( 'title' => 'Factores que pueden afectar la restauración' ),
		'fdc29dc'  => array( 'title' => 'Últimas noticias y actualizaciones' ),
		'44dfe7a'  => array( 'title' => 'Socio destacado' ),
		'f521cae'  => array( 'title' => 'Socios destacados' ),
		'd9a7175'  => array( 'title' => 'Qué está ocurriendo a nivel local' ),
		'12e4cb2'  => array( 'nothing_found_message_text' => 'Parece que no podemos encontrar lo que está buscando.' ),
		'a9a1265'  => array( 'nothing_found_message_text' => 'Parece que no podemos encontrar lo que está buscando.' ),
	);

	/**
	 * Settings that carry visitor-facing wording and are therefore kept from
	 * the Spanish template when the same element exists in both.
	 *
	 * @var string[]
	 */
	const SPANISH_KEPT_KEYS = array( 'title', 'text', 'first_item_title', 'icon_list', 'nothing_found_message_text' );

	/**
	 * Rebuilds the Spanish State Post template from the English one.
	 *
	 * The Spanish edition had been built once and left behind: it lacked the
	 * Featured Partners widget (so Spanish state pages showed no partners),
	 * the partner photo, the news/events wiring and the current section
	 * markers. The English template is taken as the structure and design;
	 * only wording is carried over from the Spanish one (headings, buttons,
	 * the eligibility tool's `label_*` strings, the breadcrumb, filter
	 * labels), plus SPANISH_TEXT_BY_ID for what it never had.
	 *
	 * One-time by design: it only acts while the Spanish template has no
	 * `ryv_featured_partners` widget yet, so an edit made to the Spanish
	 * template afterwards is never overwritten by a later repair run. The
	 * previous data is kept in `_ryv_backup_elementor_data`.
	 *
	 * @param bool $dry_run True: report only.
	 * @return array<int,array{source:int,target:int,title:string}>
	 */
	public static function sync_spanish_template( bool $dry_run ): array {
		if ( ! has_filter( 'wpml_object_id' ) ) {
			return array();
		}

		$done = array();

		$sources = get_posts(
			array(
				'post_type'        => 'elementor_library',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		foreach ( $sources as $source_id ) {
			$source_raw = (string) get_post_meta( $source_id, '_elementor_data', true );

			// The English State Post template: the one holding the Featured
			// Partners widget.
			if ( false === strpos( $source_raw, '"ryv_featured_partners"' ) ) {
				continue;
			}

			$target_id = (int) apply_filters( 'wpml_object_id', $source_id, 'elementor_library', false, 'es' );

			if ( ! $target_id || $target_id === (int) $source_id ) {
				continue;
			}

			$target_raw = (string) get_post_meta( $target_id, '_elementor_data', true );

			if ( false !== strpos( $target_raw, '"ryv_featured_partners"' ) ) {
				continue;
			}

			$source = json_decode( $source_raw, true );
			$target = json_decode( $target_raw, true );

			if ( ! is_array( $source ) || ! is_array( $target ) ) {
				continue;
			}

			$spanish = array();
			self::index_settings( $target, $spanish );

			self::apply_spanish( $source, $spanish );

			if ( ! $dry_run ) {
				update_post_meta( $target_id, '_ryv_backup_elementor_data', wp_slash( $target_raw ) );
				update_post_meta( $target_id, '_elementor_data', wp_slash( wp_json_encode( $source ) ) );
				delete_post_meta( $target_id, '_elementor_element_cache' );

				if ( class_exists( '\Elementor\Plugin' ) ) {
					\Elementor\Plugin::$instance->files_manager->clear_cache();
				}
			}

			$done[] = array(
				'source' => (int) $source_id,
				'target' => $target_id,
				'title'  => get_the_title( $target_id ),
			);
		}

		return $done;
	}

	/**
	 * @param array $elements Elementor elements.
	 * @param array $index    Element id => settings, by reference.
	 */
	private static function index_settings( array $elements, array &$index ) {
		foreach ( $elements as $element ) {
			$index[ $element['id'] ?? '' ] = $element['settings'] ?? array();

			if ( ! empty( $element['elements'] ) ) {
				self::index_settings( $element['elements'], $index );
			}
		}
	}

	/**
	 * Overlay Spanish wording onto the English element tree, in place.
	 *
	 * @param array $elements Elementor elements (English), by reference.
	 * @param array $spanish  Element id => settings of the Spanish template.
	 */
	private static function apply_spanish( array &$elements, array $spanish ) {
		foreach ( $elements as &$element ) {
			$id = $element['id'] ?? '';

			foreach ( $spanish[ $id ] ?? array() as $key => $value ) {
				$is_wording = in_array( $key, self::SPANISH_KEPT_KEYS, true ) || 0 === strpos( $key, 'label_' );

				// A dynamic tag drives the value (the H1, the buttons): the
				// stored text is empty and must not blank the English one.
				if ( $is_wording && '' !== $value && array() !== $value && ! isset( $element['settings']['__dynamic__'][ $key ] ) ) {
					$element['settings'][ $key ] = $value;
				}
			}

			foreach ( self::SPANISH_TEXT_BY_ID[ $id ] ?? array() as $key => $value ) {
				$element['settings'][ $key ] = $value;
			}

			if ( 'ryv_eligibility_tool' === ( $element['widgetType'] ?? '' ) && empty( $spanish[ $id ]['label_back'] ) ) {
				$element['settings']['label_back'] = '← Atrás';
			}

			if ( ! empty( $element['elements'] ) ) {
				self::apply_spanish( $element['elements'], $spanish );
			}
		}
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

			// `post_query_query_id` is the Loop Grid's real control name (the
			// Posts widget uses `posts_query_id`); this used to write a bare
			// `query_id` that nothing reads, so the grid kept running its own
			// default query.
			if ( isset( self::QUERY_IDS[ $id ] ) && ( $element['settings']['post_query_query_id'] ?? '' ) !== self::QUERY_IDS[ $id ] ) {
				$element['settings']['post_query_query_id'] = self::QUERY_IDS[ $id ];
				unset( $element['settings']['query_id'] );
				$changes['query_ids_set'][ $id ]            = self::QUERY_IDS[ $id ];
			}

			foreach ( self::STRIP_SETTINGS[ $id ] ?? array() as $setting_key ) {
				if ( isset( $element['settings'][ $setting_key ] ) ) {
					unset( $element['settings'][ $setting_key ] );
					$changes['static_settings_set'][ $id ][ $setting_key ] = 'removed';
				}
			}

			if ( isset( self::WIDEN_MARKERS[ $id ] ) ) {
				$current = (string) ( $element['settings']['css_classes'] ?? '' );
				$classes = preg_split( '/\s+/', trim( $current ), -1, PREG_SPLIT_NO_EMPTY );

				if ( ! in_array( self::WIDEN_MARKERS[ $id ], $classes, true ) ) {
					$classes   = array_filter( $classes, static fn( $c ) => 0 !== strpos( $c, RYV_States_Template::WIDEN_PREFIX ) );
					$classes[] = self::WIDEN_MARKERS[ $id ];

					$element['settings']['css_classes'] = implode( ' ', $classes );
					$changes['markers_set'][ $id ]      = $element['settings']['css_classes'];
				}
			}

			foreach ( self::IMAGE_TAGS_BY_CLASS as $class => $tag_name ) {
				if ( 'image' !== ( $element['widgetType'] ?? '' ) || false === strpos( ' ' . (string) ( $element['settings']['css_classes'] ?? '' ) . ' ', ' ' . $class . ' ' ) ) {
					continue;
				}

				$current = (string) ( $element['settings']['__dynamic__']['image'] ?? '' );

				if ( false === strpos( $current, 'name="' . $tag_name . '"' ) ) {
					$element['settings']['__dynamic__']['image'] = sprintf( '[elementor-tag id="%s" name="%s" settings="%%7B%%7D"]', substr( md5( $tag_name ), 0, 7 ), $tag_name );
					$changes['dynamic_tags_set'][ $id ]          = $tag_name;
				}
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

			if ( self::SOURCE_PARENT_ID === $id && ! self::has_child( $element, self::SOURCE_WIDGET_ID ) ) {
				$element['elements'][]                  = self::build_source_widget();
				$changes['static_settings_set'][ self::SOURCE_WIDGET_ID ]['added'] = '[ryv_stats_source]';
			}

			if ( ! empty( $element['elements'] ) ) {
				self::walk( $element['elements'], $changes, $missing );
			}
		}
	}

	/**
	 * Whether an element has a direct child with this id.
	 *
	 * @param array  $element Elementor element.
	 * @param string $id      Child id.
	 * @return bool
	 */
	private static function has_child( array $element, string $id ): bool {
		foreach ( $element['elements'] ?? array() as $child ) {
			// Also an equivalent widget an editor added by hand under another
			// id - adding ours next to it would print every source twice.
			if ( ( $child['id'] ?? '' ) === $id || false !== strpos( (string) ( $child['settings']['editor'] ?? '' ), '[ryv_stats_source]' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Text Editor widget rendering `[ryv_stats_source]`, styled like the
	 * stat description text above it.
	 *
	 * @return array
	 */
	private static function build_source_widget(): array {
		return array(
			'id'         => self::SOURCE_WIDGET_ID,
			'elType'     => 'widget',
			'widgetType' => 'text-editor',
			'elements'   => array(),
			'settings'   => array(
				'editor'                 => '[ryv_stats_source]',
				'align'                  => 'left',
				'typography_typography'  => 'custom',
				'typography_font_family' => 'Barlow',
				'typography_font_size'   => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
				'typography_font_weight' => '400',
				'typography_line_height' => array( 'unit' => 'em', 'size' => 1.4, 'sizes' => array() ),
				'_title'                 => 'Text · Stats source',
				'_element_width'         => 'initial',
				'_element_custom_width'  => array( 'unit' => '%', 'size' => 100, 'sizes' => array() ),
				'__globals__'            => array( 'text_color' => 'globals/colors?id=clc_primary' ),
			),
		);
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
