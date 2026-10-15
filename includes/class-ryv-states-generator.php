<?php
/**
 * Generates `ryv_states` posts from a form's state-picker node, so an admin
 * doesn't have to hand-create all 50+ State posts one at a time.
 *
 * Walks the same picker node RYV_States_Sync::find_start_node() routes off
 * of and creates one `ryv_states` post per option, keyed uniquely by
 * `ryv_state_slug` (sanitize_title() of the option's `value`). The
 * uniqueness check runs across *every* existing ryv_states post regardless
 * of status or which form created it - so re-running this against a second
 * form that lists the same states (e.g. a Spanish-language translation of
 * the same questionnaire, which naturally lists the same 50 states under
 * its own translated option labels) never creates duplicates: the slug is
 * derived from the option's `value`, not its (possibly translated) `label`,
 * and Drupal-sourced schemas keep `value` as the untranslated state key
 * across every language edition.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_States_Generator
 */
class RYV_States_Generator {

	/**
	 * NCSL reference-table category => state slugs (sourced 2026-05-20,
	 * https://www.ncsl.org/elections-and-campaigns/felon-voting-rights -
	 * supersedes the earlier CLC 2024 map this table was originally seeded
	 * from), mirroring RYV_States_ACF::DEFAULT_MAP_CATEGORIES's slugs. Used
	 * by assign_default_map_categories() to auto-populate each state's
	 * `ryv_map_category` without an admin hand-assigning all 50+ one at a
	 * time. Every state slug appears in exactly one list.
	 */
	const CLC_CATEGORY_STATES = array(
		'voting-rights-not-lost'             => array( 'maine', 'vermont', 'district-of-columbia' ),
		'lost-only-during-incarceration'     => array( 'california', 'colorado', 'connecticut', 'hawaii', 'illinois', 'indiana', 'maryland', 'massachusetts', 'michigan', 'minnesota', 'montana', 'nevada', 'new-hampshire', 'new-jersey', 'new-mexico', 'new-york', 'north-dakota', 'ohio', 'oregon', 'pennsylvania', 'rhode-island', 'utah', 'washington' ),
		'restored-after-sentence-completion' => array( 'alaska', 'georgia', 'idaho', 'kansas', 'missouri', 'nebraska', 'north-carolina', 'oklahoma', 'south-carolina', 'south-dakota', 'west-virginia', 'wisconsin', 'alabama', 'florida', 'louisiana', 'texas', 'arizona' ),
		'documents-or-certificate-required'  => array( 'arkansas', 'iowa', 'tennessee', 'wyoming' ),
		'discretionary-action'               => array( 'kentucky', 'virginia' ),
		'permanent-disenfranchisement'       => array( 'delaware', 'mississippi' ),
	);

	/**
	 * State slug => secondary category (drawn as stripes on the map). Only the
	 * states whose second category is known are listed - Arkansas and
	 * Connecticut are the client's own examples; the four LFO states and
	 * Arizona carry over what the previous map already implied (an added
	 * payment requirement / a permanent-for-multiple-felonies carve-out). Any
	 * other state gets its secondary category by hand on its own edit screen.
	 */
	const CLC_SECONDARY_CATEGORY_STATES = array(
		'arkansas'    => 'payment-legal-debts',
		'connecticut' => 'restored-after-sentence-completion',
		'alabama'     => 'payment-legal-debts',
		'florida'     => 'payment-legal-debts',
		'louisiana'   => 'payment-legal-debts',
		'texas'       => 'payment-legal-debts',
		'arizona'     => 'permanent-disenfranchisement',
	);

	/**
	 * State slugs with a striped/mixed-rules pattern on the map (not yet
	 * reflected visually - see RYV_MapSVG_Sync's docblock), mirroring
	 * `ryv_map_mixed_rules`.
	 */
	const CLC_MIXED_RULES_STATES = array( 'arizona', 'mississippi', 'north-carolina', 'tennessee', 'virginia', 'iowa', 'wyoming' );

	/**
	 * Auto-assign `ryv_map_category` (+ `ryv_map_mixed_rules`, + a
	 * placeholder `ryv_map_tooltip_text`) to every existing `ryv_states`
	 * post found in CLC_CATEGORY_STATES, so the map's categories/colors
	 * populate themselves against real state data instead of requiring a
	 * manual per-state admin edit or one-off DB script.
	 *
	 * By default only fills in states that have no category yet, so it's
	 * safe to re-run (e.g. after a new state post is created) without
	 * clobbering an admin's own manual override; pass $force to
	 * re-assign every matching state regardless.
	 *
	 * @param bool $force Overwrite an already-set category too.
	 * @return array{assigned: string[], skipped: string[]}
	 */
	public static function assign_default_map_categories( bool $force = false ): array {
		$slug_to_category = array();
		foreach ( self::CLC_CATEGORY_STATES as $category => $state_slugs ) {
			foreach ( $state_slugs as $state_slug ) {
				$slug_to_category[ $state_slug ] = $category;
			}
		}

		$posts = get_posts( array(
			'post_type'      => RYV_States_CPT::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			// WPML's pre_get_posts filtering isn't affected by
			// suppress_filters - only its own 'lang' query var bypasses
			// it - so without this a site with an active non-default
			// admin language would only see that language's states here.
			'lang'           => 'all',
		) );

		$result = array(
			'assigned' => array(),
			'skipped'  => array(),
		);

		foreach ( $posts as $post_id ) {
			$state_slug = get_field( 'ryv_state_slug', $post_id );
			if ( ! $state_slug ) {
				continue;
			}

			$canonical_slug = self::resolve_canonical_state_slug( $state_slug );
			if ( ! $canonical_slug || ! isset( $slug_to_category[ $canonical_slug ] ) ) {
				continue;
			}

			if ( RYV_States_ACF::get_state_categories( $post_id ) && ! $force ) {
				$result['skipped'][] = $state_slug;
				continue;
			}

			$category = $slug_to_category[ $canonical_slug ];

			$secondary = self::CLC_SECONDARY_CATEGORY_STATES[ $canonical_slug ] ?? '';
			RYV_States_ACF::set_state_categories( $post_id, array( $category, $secondary ) );

			if ( ! get_field( 'ryv_map_tooltip_text', $post_id ) ) {
				$category_label = class_exists( 'RYV_States_ACF' ) ? RYV_States_ACF::get_category_label( $category ) : null;

				update_field(
					'field_ryv_map_tooltip_text',
					sprintf(
						'%s: %s. Click to learn more about voting rights restoration.',
						RYV_US_States::label( $canonical_slug ),
						$category_label ?: $category
					),
					$post_id
				);
			}

			$result['assigned'][] = $state_slug;
		}

		return $result;
	}

	/**
	 * Resolve a `ryv_state_slug` value to the full-name slug
	 * CLC_CATEGORY_STATES/RYV_US_States::MAP key on - `ryv_state_slug` isn't
	 * consistently a hyphenated full-name slug across every state (some
	 * carry a bare 2-letter postal code instead, see RYV_US_States::svg_id()'s
	 * own docblock for why), so a direct array key lookup alone would silently
	 * skip every state stored that way.
	 *
	 * @param string $state_slug Raw `ryv_state_slug` value.
	 * @return string|null Full-name slug, or null if unrecognized.
	 */
	public static function resolve_canonical_state_slug( string $state_slug ): ?string {
		if ( isset( RYV_US_States::SVG_IDS[ $state_slug ] ) ) {
			return $state_slug;
		}

		static $by_abbreviation = null;
		if ( null === $by_abbreviation ) {
			$by_abbreviation = array();
			foreach ( RYV_US_States::SVG_IDS as $full_slug => $code ) {
				$by_abbreviation[ strtolower( $code ) ] = $full_slug;
			}
		}

		return $by_abbreviation[ strtolower( $state_slug ) ] ?? null;
	}

	/**
	 * Generate ryv_states posts for every state option in $form_id's picker
	 * node that doesn't already have a post.
	 *
	 * WPML-aware: if $form_id is a translation (e.g. a Spanish edition of
	 * the questionnaire), a state slug that already exists in a *different*
	 * language doesn't get skipped outright - it gets a proper WPML
	 * translation of that same `ryv_states` post created and linked
	 * (see maybe_create_translation()), rather than either a language-blind
	 * duplicate post or a silent no-op. Running this against a form in the
	 * *same* language as an already-existing state (including re-running
	 * against the original English form, or on a site with no WPML at all)
	 * still just skips, exactly as before.
	 *
	 * @param int    $form_id     ryv_forms post ID.
	 * @param string $post_status Status to create new state posts with ('draft' or 'publish'). Default 'draft'.
	 * @return array{created: array, skipped: array, errors: string[]}
	 */
	public static function generate( int $form_id, string $post_status = 'draft' ): array {
		$result = array(
			'created' => array(),
			'skipped' => array(),
			'errors'  => array(),
		);

		if ( ! in_array( $post_status, array( 'draft', 'publish' ), true ) ) {
			$post_status = 'draft';
		}

		$schema     = RYV_CPT::get_schema( $form_id );
		$nodes      = $schema['nodes'] ?? array();
		$entry_node = $schema['entry_node'] ?? null;

		if ( ! $entry_node || ! isset( $nodes[ $entry_node ] ) ) {
			$result['errors'][] = __( 'This form has no entry node.', 'ryv-eligibility-tool' );
			return $result;
		}

		$options = $nodes[ $entry_node ]['options'] ?? array();

		if ( empty( $options ) || ! is_array( $options ) ) {
			$result['errors'][] = __( 'This form\'s entry node has no options - is it really the state picker?', 'ryv-eligibility-tool' );
			return $result;
		}

		// Snapshot of every existing state post's slug => post ID, built once
		// so a 50+ option form doesn't run 50+ separate lookup queries.
		$existing = self::existing_slugs();

		// $form_id's own WPML language, if any - null on a site without
		// WPML, or if $form_id was never assigned one. Fetched once; every
		// option in this run targets the same form, so the same language.
		$target_lang = self::get_post_language( $form_id );

		// De-dupe within this single form's own option list too - the same
		// state can legitimately appear under two different node ids in a
		// real Drupal export; that should still only ever produce one post.
		$seen_this_run = array();

		foreach ( $options as $option ) {
			$label = trim( (string) ( $option['label'] ?? '' ) );
			$value = trim( (string) ( $option['value'] ?? $label ) );

			if ( '' === $value ) {
				continue;
			}

			$slug = sanitize_title( $value );

			if ( '' === $slug || isset( $seen_this_run[ $slug ] ) ) {
				continue;
			}
			$seen_this_run[ $slug ] = true;

			if ( isset( $existing[ $slug ] ) ) {
				$translated = $target_lang
					? self::maybe_create_translation( $existing[ $slug ], $target_lang, $label ?: $value, $form_id, $post_status )
					: null;

				if ( null !== $translated ) {
					if ( isset( $translated['error'] ) ) {
						$result['errors'][] = sprintf(
							/* translators: 1: state label, 2: error message */
							__( 'Could not create a translated post for "%1$s": %2$s', 'ryv-eligibility-tool' ),
							$label ?: $value,
							$translated['error']
						);
					} elseif ( $translated['created'] ) {
						$result['created'][] = array(
							'slug'    => $slug,
							'label'   => $label ?: $value,
							'post_id' => $translated['post_id'],
						);
					} else {
						$result['skipped'][] = array(
							'slug'             => $slug,
							'label'            => $label ?: $value,
							'existing_post_id' => $translated['post_id'],
						);
					}
					continue;
				}

				$result['skipped'][] = array(
					'slug'             => $slug,
					'label'            => $label ?: $value,
					'existing_post_id' => $existing[ $slug ],
				);
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_type'   => RYV_States_CPT::POST_TYPE,
					'post_title'  => $label ?: $value,
					'post_status' => $post_status,
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				$result['errors'][] = sprintf(
					/* translators: 1: state label, 2: error message */
					__( 'Could not create a post for "%1$s": %2$s', 'ryv-eligibility-tool' ),
					$label ?: $value,
					$post_id->get_error_message()
				);
				continue;
			}

			update_field( 'ryv_state_slug', $slug, $post_id );
			update_field( 'ryv_form_connection', $form_id, $post_id );

			// Record it immediately so a duplicate slug later in this same
			// options array, or a second form processed in the same
			// request, never inserts twice.
			$existing[ $slug ] = (int) $post_id;

			$result['created'][] = array(
				'slug'    => $slug,
				'label'   => $label ?: $value,
				'post_id' => (int) $post_id,
			);
		}

		return $result;
	}

	/**
	 * WPML language code of a post, or null on a site without WPML (or a
	 * post WPML never assigned one - e.g. a legacy `ryv_states` post
	 * created before this CPT was registered translatable).
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	private static function get_post_language( int $post_id ): ?string {
		if ( ! has_filter( 'wpml_post_language_details' ) ) {
			return null;
		}

		$details = apply_filters( 'wpml_post_language_details', null, $post_id );

		return ( is_array( $details ) && ! empty( $details['language_code'] ) )
			? (string) $details['language_code']
			: null;
	}

	/**
	 * Given an existing `ryv_states` post (found by slug) and the language
	 * of the form currently being generated against, either find or create
	 * that state's translation in the target language - so generating
	 * against a Spanish-language form auto-connects each state to its
	 * English original via WPML instead of either skipping silently or
	 * creating a language-blind duplicate post.
	 *
	 * Returns null (meaning: "not applicable, caller should fall back to a
	 * plain skip") when WPML isn't available, the original post has no
	 * language of its own yet, or the original is already in the target
	 * language (an ordinary re-run against the same-language form).
	 *
	 * @param int    $original_id Existing `ryv_states` post ID found for this slug.
	 * @param string $target_lang Language code of the form being generated against.
	 * @param string $label       State label (option label/value), used as the new post's title.
	 * @param int    $form_id     Form to connect the new translation's `ryv_form_connection` to.
	 * @param string $post_status Status to create the new post with.
	 * @return array{created: bool, post_id: int, error?: string}|null
	 */
	private static function maybe_create_translation( int $original_id, string $target_lang, string $label, int $form_id, string $post_status ): ?array {
		if ( ! has_filter( 'wpml_object_id' ) || ! has_filter( 'wpml_element_trid' ) || ! has_action( 'wpml_set_element_language_details' ) ) {
			return null;
		}

		$original_lang = self::get_post_language( $original_id );
		if ( ! $original_lang || $target_lang === $original_lang ) {
			return null;
		}

		$existing_translation_id = apply_filters( 'wpml_object_id', $original_id, RYV_States_CPT::POST_TYPE, false, $target_lang );
		if ( $existing_translation_id ) {
			return array(
				'created' => false,
				'post_id' => (int) $existing_translation_id,
			);
		}

		$new_id = wp_insert_post(
			array(
				'post_type'   => RYV_States_CPT::POST_TYPE,
				'post_title'  => $label,
				'post_status' => $post_status,
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return array(
				'created' => false,
				'post_id' => 0,
				'error'   => $new_id->get_error_message(),
			);
		}

		$new_id       = (int) $new_id;
		$element_type = 'post_' . RYV_States_CPT::POST_TYPE;
		$trid         = apply_filters( 'wpml_element_trid', null, $original_id, $element_type );

		do_action( 'wpml_set_element_language_details', array(
			'element_id'           => $new_id,
			'element_type'         => $element_type,
			'trid'                 => $trid,
			'language_code'        => $target_lang,
			'source_language_code' => $original_lang,
		) );

		// Same state slug as the original (that's how it was matched here
		// in the first place) - only the connected form differs, pointing
		// this translation at the form it was actually generated from.
		update_field( 'ryv_state_slug', get_field( 'ryv_state_slug', $original_id ), $new_id );
		update_field( 'ryv_form_connection', $form_id, $new_id );

		return array(
			'created' => true,
			'post_id' => $new_id,
		);
	}

	/**
	 * Map of every existing ryv_states post's state slug to its post ID,
	 * across every post status (trash included - see generate()'s docblock
	 * for why a trashed state post still counts as "already exists").
	 *
	 * @return array<string,int>
	 */
	private static function existing_slugs(): array {
		$posts = get_posts( array(
			'post_type'      => RYV_States_CPT::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			// See the matching comment in assign_default_map_categories() -
			// without this, generating states while a non-default WPML
			// language is active would only see that language's existing
			// states and create duplicates instead of skipping them.
			'lang'           => 'all',
		) );

		$map = array();
		foreach ( $posts as $post_id ) {
			$slug = get_field( 'ryv_state_slug', $post_id );
			if ( $slug ) {
				$map[ $slug ] = (int) $post_id;
			}
		}

		return $map;
	}
}
