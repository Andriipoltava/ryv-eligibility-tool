<?php
/**
 * Content fallback for a translated state page.
 *
 * A Spanish state page is a WPML translation of the English one, created by
 * the states generator with only the few fields it needs to work (form link,
 * state slug, map category) - every *content* field is empty (confirmed:
 * English Alabama carries 32 `ryv_*` meta rows, its Spanish edition 10). The
 * result on the frontend was a page with a heading and the questionnaire and
 * nothing else, because the hide-empty markers correctly hid every section
 * whose field was blank.
 *
 * That is the exact situation the client described - "we will have to do
 * duplicate content on all state pages because those pages will not have
 * Spanish content, only the tool is in Spanish" - except done here rather
 * than by hand across 50-odd pages: any field left empty on a translation
 * reads through to the original language's value, so a Spanish page shows
 * the English copy until (and only until) someone actually translates that
 * field on the Spanish post, at which point their value wins.
 *
 * The questionnaire itself is unaffected: `ryv_form_connection` and
 * `ryv_state_slug` are set on the translation and are deliberately excluded
 * below - falling those back would point a Spanish page at the English form,
 * which is the one thing that must stay per-language.
 *
 * Frontend only. On an edit screen the translated post must keep showing its
 * own (empty) values, or an editor would be looking at English content in
 * Spanish fields and saving it as if they had written it.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_States_I18n
 */
class RYV_States_I18n {

	/**
	 * Fields that must never fall back - they're what makes the translation
	 * *its own* page rather than a copy.
	 *
	 * @var string[]
	 */
	const NEVER_FALL_BACK = array(
		'ryv_form_connection',
		'ryv_state_slug',
	);

	/**
	 * Guards against re-entering the filter while reading the source post.
	 *
	 * @var bool
	 */
	private static $resolving = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'acf/load_value', array( __CLASS__, 'fall_back_to_source' ), 20, 3 );
	}

	/**
	 * Read an empty field on a translated state through to its original.
	 *
	 * @param mixed      $value   The value loaded from this post.
	 * @param int|string $post_id ACF post id (an int for a normal post).
	 * @param array      $field   The ACF field array.
	 * @return mixed
	 */
	public static function fall_back_to_source( $value, $post_id, $field ) {
		if ( self::$resolving || is_admin() || wp_doing_ajax() ) {
			return $value;
		}

		if ( ! self::is_empty( $value ) ) {
			return $value;
		}

		// A repeater's value is its row count in raw form and its rows in
		// formatted form; handing ACF rows from here made it return them
		// keyed by field *key* (`field_xxx`) instead of by name, so a
		// Spanish page's partners/sources lost every field. Repeaters fall
		// back through get_value() at the point of use instead.
		if ( 'repeater' === ( $field['type'] ?? '' ) ) {
			return $value;
		}

		$field_name = $field['name'] ?? '';

		if ( '' === $field_name || in_array( $field_name, self::NEVER_FALL_BACK, true ) ) {
			return $value;
		}

		$source_id = self::get_source_post_id( (int) $post_id );

		if ( ! $source_id ) {
			return $value;
		}

		self::$resolving = true;
		$source_value    = get_field( $field_name, $source_id, false );
		self::$resolving = false;

		return self::is_empty( $source_value ) ? $value : $source_value;
	}

	/**
	 * A field's value for a state page, reading through to the original
	 * language's post when this (translated) one has none. Same rule as the
	 * load_value filter above, for the cases that filter can't serve -
	 * repeaters.
	 *
	 * @param string $field_name ACF field name.
	 * @param int    $post_id    A `ryv_states` post.
	 * @return mixed Formatted value.
	 */
	public static function get_value( string $field_name, int $post_id ) {
		$value = get_field( $field_name, $post_id );

		if ( ! self::is_empty( $value ) || in_array( $field_name, self::NEVER_FALL_BACK, true ) ) {
			return $value;
		}

		$source_id = self::get_source_post_id( $post_id );

		return $source_id ? get_field( $field_name, $source_id ) : $value;
	}

	/**
	 * Re-key repeater rows that came back keyed by sub-field *key*
	 * (`field_xxx`) to their names. That is the shape a translated page's
	 * value arrives in when the field is set to "copy" in ACF Multilingual:
	 * the original's raw rows are returned without being formatted, so a
	 * renderer reading `$row['name']` finds nothing.
	 *
	 * @param mixed                $rows    get_field() value of a repeater.
	 * @param array<string,string> $key_map Sub-field key => name.
	 * @return array
	 */
	public static function name_rows( $rows, array $key_map ): array {
		$named = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			foreach ( $key_map as $key => $name ) {
				if ( array_key_exists( $key, $row ) && ! array_key_exists( $name, $row ) ) {
					$row[ $name ] = $row[ $key ];
				}
			}

			$named[] = $row;
		}

		return $named;
	}

	/**
	 * The original-language `ryv_states` post $post_id is a translation of,
	 * or 0 when it is the original (or WPML isn't active).
	 *
	 * @param int $post_id Post ID being rendered.
	 * @return int
	 */
	public static function get_source_post_id( int $post_id ): int {
		static $cache = array();

		if ( isset( $cache[ $post_id ] ) ) {
			return $cache[ $post_id ];
		}

		$cache[ $post_id ] = 0;

		if ( ! $post_id || RYV_States_CPT::POST_TYPE !== get_post_type( $post_id ) ) {
			return 0;
		}

		if ( ! has_filter( 'wpml_element_trid' ) || ! has_filter( 'wpml_get_element_translations' ) ) {
			return 0;
		}

		$element_type = 'post_' . RYV_States_CPT::POST_TYPE;
		$trid         = apply_filters( 'wpml_element_trid', false, $post_id, $element_type );

		if ( ! $trid ) {
			return 0;
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );

		if ( ! is_array( $translations ) ) {
			return 0;
		}

		foreach ( $translations as $translation ) {
			$element_id = (int) ( $translation->element_id ?? 0 );

			// `source_language_code` is null on the original of a group -
			// that is what identifies it, rather than assuming a language.
			$is_source = empty( $translation->source_language_code );

			if ( $is_source && $element_id && $element_id !== $post_id ) {
				$cache[ $post_id ] = $element_id;
				break;
			}
		}

		return $cache[ $post_id ];
	}

	/**
	 * Emptiness as this fallback means it: an ACF field can legitimately
	 * hold `0`, so only null/''/empty-array count.
	 *
	 * @param mixed $value Value to test.
	 * @return bool
	 */
	private static function is_empty( $value ): bool {
		if ( is_array( $value ) ) {
			return array() === $value;
		}

		return null === $value || '' === $value || false === $value;
	}
}
