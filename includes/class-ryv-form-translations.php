<?php
/**
 * Shared read-only view of a form's WPML language editions, plus the
 * per-language UI strings the frontend needs to render a *tool-only*
 * language switch.
 *
 * Why this exists as its own class rather than living in the widget: a
 * form and its Spanish edition are two separately-linked `ryv_forms` posts
 * (see RYV_Translations_Page), and three places now need the same
 * "which other editions of this form exist" answer - the Elementor widget,
 * the `[ryv_form]` shortcode, and (indirectly, via the same data
 * attribute) engine.js's switchLanguage(). RYV_Step_Names_Page has its own
 * private copy of the group-lookup for admin use; this one is the frontend
 * half and deliberately never writes anything.
 *
 * The switch is *tool-only* on purpose: the visitor stays on the English
 * page and only the questionnaire re-renders in Spanish. That's possible
 * because both editions' state-picker options carry the same *untranslated*
 * `value` (confirmed against the real English/Spanish editions: both use
 * `Alabama`, `Alaska`, ... while each state's own `next_node` ids differ),
 * so RYV_States_Sync::find_start_node() resolves the same state in either
 * graph - see RYV_Widget::get_state_entry_override().
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Form_Translations
 */
class RYV_Form_Translations {

	/**
	 * Button text for switching *to* a given language, keyed by WPML
	 * language code. These are the wordings the site actually wants ("En
	 * Español", not WPML's own native name "Español"), and they're what the
	 * widget's per-language Button Text controls default to - an admin can
	 * override any of them per widget instance. A language absent from this
	 * table falls back to WPML's native name for it.
	 *
	 * @var array<string,string>
	 */
	const SWITCH_LABELS = array(
		'en' => 'In English',
		'es' => 'En Español',
	);

	/**
	 * Translations of renderer.js's DEFAULT_LABELS, keyed by language code.
	 *
	 * Needed because the widget's own "Labels" controls are single-valued
	 * English strings on an English page, and WPML string translation can't
	 * help: it resolves against the *page's* language, which a tool-only
	 * switch deliberately does not change. Without these, switching the
	 * tool to Spanish would leave an English "Continue"/"Start Over" under
	 * Spanish questions.
	 *
	 * Only keys that differ need listing; engine.js merges each set over
	 * DEFAULT_LABELS. A language with no entry here keeps whatever labels
	 * the widget is already configured with, which is the right fallback
	 * for the page's own language.
	 *
	 * @var array<string,array<string,string>>
	 */
	const UI_LABELS = array(
		'es' => array(
			'loading'            => 'Cargando…',
			'error'              => 'Algo salió mal.',
			'retry'              => 'Intentar de nuevo',
			'startOver'          => 'Empezar de nuevo',
			'selectPlaceholder'  => '— Seleccione —',
			'select2NoResults'   => 'No se encontraron resultados',
			'submit'             => 'Enviar',
			'submitting'         => 'Enviando…',
			'continue'           => 'Continuar',
			'back'               => '← Atrás',
			'leadSubmitted'      => '¡Gracias! Hemos recibido su información.',
			'leadError'          => 'Algo salió mal al enviar su información. Por favor, inténtelo de nuevo.',
			'step'               => 'Paso {current}',
			'stepWithTotal'      => 'Paso {current} de {total}',
		),
	);

	/**
	 * Whether WPML's (or a compatible plugin's) translation API is present -
	 * same three filters RYV_Step_Names_Page::is_wpml_available() checks,
	 * since this reads the same translation group.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return has_filter( 'wpml_element_trid' )
			&& has_filter( 'wpml_get_element_translations' )
			&& has_filter( 'wpml_post_language_details' );
	}

	/**
	 * Every language edition of $form_id, keyed by language code, always
	 * including $form_id itself. Returns an empty array when WPML isn't
	 * active or the form has no language assignment at all - callers treat
	 * "fewer than two editions" as "no switch to offer", so there's no need
	 * for the 'default' pseudo-code RYV_Step_Names_Page uses for its own
	 * single-row admin rendering.
	 *
	 * Unpublished editions are dropped unless the current user could edit
	 * them, matching RYV_API::get_viewable_form()'s draft rule - offering a
	 * switch to an edition the REST route would then refuse to return would
	 * just render an error card.
	 *
	 * @param int $form_id Form post ID.
	 * @return array<string,WP_Post> language_code => post.
	 */
	public static function get_editions( int $form_id ): array {
		$post = get_post( $form_id );

		if ( ! $post || RYV_CPT::POST_TYPE !== $post->post_type || ! self::is_available() ) {
			return array();
		}

		$trid = apply_filters( 'wpml_element_trid', false, $form_id, 'post_' . RYV_CPT::POST_TYPE );

		if ( ! $trid ) {
			return array();
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . RYV_CPT::POST_TYPE );

		if ( ! is_array( $translations ) ) {
			return array();
		}

		$editions = array();

		foreach ( $translations as $lang_code => $translation ) {
			$element_id = ( is_object( $translation ) && isset( $translation->element_id ) ) ? (int) $translation->element_id : 0;
			$member     = $element_id ? get_post( $element_id ) : null;

			if ( ! $member || RYV_CPT::POST_TYPE !== $member->post_type ) {
				continue;
			}

			if ( 'publish' !== $member->post_status && ! current_user_can( 'edit_post', $member->ID ) ) {
				continue;
			}

			$editions[ (string) $lang_code ] = $member;
		}

		return $editions;
	}

	/**
	 * The edition of $form_id that matches the language currently being
	 * rendered - or $form_id unchanged when there's no such edition (or no
	 * WPML at all).
	 *
	 * This is what makes an embed on a Spanish page show the Spanish form
	 * even though the widget instance was configured with the English
	 * form's id. WPML *does* rewrite ids inside a translated page's own
	 * Elementor data (see the `post-ids` field declared for `form_id` in
	 * wpml-config.xml), but only at the moment that translation is created
	 * or updated - it can't help a Spanish page that was built
	 * independently, duplicated before the declaration existed, or that
	 * simply hasn't been re-saved since. Resolving again at render time
	 * covers all of those, and is a no-op whenever the id is already right.
	 *
	 * @param int $form_id Configured form post ID.
	 * @return int
	 */
	public static function resolve_for_current_language( int $form_id ): int {
		if ( ! $form_id || ! self::is_available() || ! has_filter( 'wpml_current_language' ) ) {
			return $form_id;
		}

		$current = (string) apply_filters( 'wpml_current_language', null );

		if ( '' === $current || $current === self::get_language( $form_id ) ) {
			return $form_id;
		}

		$editions = self::get_editions( $form_id );

		return isset( $editions[ $current ] ) ? (int) $editions[ $current ]->ID : $form_id;
	}

	/**
	 * The language code $form_id itself is assigned to, or '' when WPML
	 * isn't active / the form has no assignment.
	 *
	 * @param int $form_id Form post ID.
	 * @return string
	 */
	public static function get_language( int $form_id ): string {
		if ( ! self::is_available() ) {
			return '';
		}

		$details = apply_filters( 'wpml_post_language_details', null, $form_id );

		return ! empty( $details['language_code'] ) ? (string) $details['language_code'] : '';
	}

	/**
	 * WPML's active languages keyed by code, as returned by
	 * `wpml_active_languages` - used for the native-name fallback on a
	 * language absent from SWITCH_LABELS, and to register one Button Text
	 * control per language on the widget (site config, so it's available at
	 * control-registration time, unlike anything form-specific).
	 *
	 * @return array<string,array>
	 */
	public static function get_active_languages(): array {
		if ( ! has_filter( 'wpml_active_languages' ) ) {
			return array();
		}

		$languages = apply_filters( 'wpml_active_languages', null );

		return is_array( $languages ) ? $languages : array();
	}

	/**
	 * Default button text for switching to $lang_code: this class's own
	 * wording when it has one, else WPML's native name for the language,
	 * else the bare code.
	 *
	 * @param string $lang_code WPML language code.
	 * @return string
	 */
	public static function default_switch_label( string $lang_code ): string {
		if ( isset( self::SWITCH_LABELS[ $lang_code ] ) ) {
			return self::SWITCH_LABELS[ $lang_code ];
		}

		$languages = self::get_active_languages();

		if ( ! empty( $languages[ $lang_code ]['native_name'] ) ) {
			return (string) $languages[ $lang_code ]['native_name'];
		}

		return strtoupper( $lang_code );
	}

	/**
	 * Engine label overrides for $lang_code (see UI_LABELS) - an empty array
	 * when that language has no set, meaning "keep the labels the widget is
	 * already configured with".
	 *
	 * @param string $lang_code WPML language code.
	 * @return array<string,string>
	 */
	public static function ui_labels( string $lang_code ): array {
		return self::UI_LABELS[ $lang_code ] ?? array();
	}
}
