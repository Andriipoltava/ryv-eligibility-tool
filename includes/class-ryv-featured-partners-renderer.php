<?php
/**
 * Shared markup builder for the Featured Partners section - the single
 * place that turns a `ryv_states` post's `featured_partners` repeater (or,
 * while that's still empty for a given state, its legacy single-partner
 * fields) into one full card per partner: logo, name, location, description,
 * quote, and a "Visit {Name} →" link - the same layout the section always
 * had for its one partner, just repeatable now. Every field but the name is
 * optional and simply omits its own markup when empty, so a partner with
 * only a name and a logo renders a shorter card, not a broken one.
 *
 * A second, independent pair of fields -
 * `featured_partners_description`/`featured_partners_quote` - holds a
 * description/quote for the list as a whole rather than any one partner
 * (render_general_note()): rendered once, after every card, so a state can
 * say something about its coalition of partners collectively without
 * pinning that copy to whichever partner happens to be listed first.
 *
 * Two front ends call this, both producing byte-identical output:
 * - `[ryv_featured_partners]` (RYV_States_Partners_Shortcode) - drop it
 *   anywhere do_shortcode() runs (a Shortcode widget, post content, ...).
 * - RYV_Featured_Partners_Widget - a proper Elementor widget wrapping the
 *   same output with Style-tab controls (logo size, spacing, typography,
 *   colors, quote styling) - use this on the State Post template itself,
 *   where those controls are worth having.
 *
 * Styling (assets/src/input.css, the `.ryv-partner-card...` rules) is
 * shared too, driven by the same `--ryv-partner-*` CSS custom properties
 * either front end's wrapper can set - the shortcode's plain markup picks
 * up the file's own defaults; the widget's Style tab overrides them
 * per-instance via `{{WRAPPER}}`.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Featured_Partners_Renderer
 */
class RYV_Featured_Partners_Renderer {

	/**
	 * @param int $post_id A `ryv_states` post.
	 * @return string `<div class="ryv-partner-cards">...</div>`, or '' if
	 *                 there's nothing to show (no ACF, no repeater rows and
	 *                 no legacy fallback data).
	 */
	public static function render_html( int $post_id ): string {
		if ( ! $post_id || ! function_exists( 'get_field' ) ) {
			return '';
		}

		$rows = class_exists( 'RYV_States_I18n' ) ? RYV_States_I18n::get_value( 'featured_partners', $post_id ) : get_field( 'featured_partners', $post_id );

		if ( class_exists( 'RYV_States_I18n' ) ) {
			$rows = RYV_States_I18n::name_rows(
				$rows,
				array(
					'field_ryv_featured_partner_logo' => 'logo',
					'field_ryv_featured_partner_name' => 'name',
					'field_ryv_featured_partner_url'  => 'url',
				)
			);
		}

		if ( is_array( $rows ) && count( $rows ) > 0 ) {
			$cards = self::build_cards_markup( $rows );
		} else {
			// Legacy fallback (see class docblock): one card built from the
			// old single-partner fields (which is exactly what they were -
			// one partner's logo/name/location/description/quote/link, just
			// not in a repeater), so a state RYV_Partners_Migration hasn't
			// reached yet keeps rendering its existing content unchanged.
			$legacy_name = trim( (string) get_field( 'ryv_partner_name', $post_id ) );
			$cards       = '' !== $legacy_name
				? self::build_cards_markup(
					array(
						array(
							'logo'        => null,
							'name'        => $legacy_name,
							'url'         => (string) get_field( 'ryv_partner_link_url', $post_id ),
						),
					)
				)
				: '';
		}

		$note = self::render_general_note( $post_id );

		if ( '' === $cards && '' === $note ) {
			return '';
		}

		return '<div class="ryv-partner-cards">' . $cards . $note . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $cards is pre-escaped per card in render_card(), $note in render_general_note().
	}

	/**
	 * @param array $rows Repeater rows (or the single-row legacy fallback),
	 *                     each `{ logo: int|null, name, url, location,
	 *                     description, quote: string }`.
	 * @return string Concatenated `<article class="ryv-partner-card">` tags,
	 *                 '' if every row was nameless. Not itself wrapped in
	 *                 `.ryv-partner-cards` - render_html() does that once,
	 *                 alongside render_general_note()'s output.
	 */
	private static function build_cards_markup( array $rows ): string {
		$cards = '';

		foreach ( $rows as $row ) {
			$cards .= self::render_card( $row );
		}

		return $cards;
	}

	/**
	 * The description/quote pair that applies to the whole Featured
	 * Partners list rather than any one partner
	 * (`featured_partners_description`/`featured_partners_quote` on
	 * RYV_States_ACF::register_partner_highlight_group()) - e.g. a note
	 * about the coalition as a whole that shouldn't read as belonging to
	 * whichever partner card happens to be listed first.
	 *
	 * Deliberately reuses the same `.ryv-partner-card-description`/
	 * `.ryv-partner-card-quote` markup/classes a single card's own fields
	 * render with, wrapped in `.ryv-partner-section-note` instead of
	 * `.ryv-partner-card`: the widget's existing Style-tab Description/Quote
	 * controls (color via a `--ryv-partner-*` custom property on
	 * `.ryv-partner-cards`, typography via those exact class selectors)
	 * apply to it automatically, with no separate controls to add or keep
	 * in sync - while the different wrapper class means it never picks up
	 * `.ryv-partner-card + .ryv-partner-card`'s divider border, which is
	 * only meant to separate one partner's card from the next.
	 *
	 * @param int $post_id A `ryv_states` post.
	 * @return string '' if both fields are empty.
	 */
	private static function render_general_note( int $post_id ): string {
		$description = trim( (string) get_field( 'featured_partners_description', $post_id ) );
		$quote       = trim( (string) get_field( 'featured_partners_quote', $post_id ) );

		if ( '' === $description && '' === $quote ) {
			return '';
		}

		// Trusted HTML - same admin-only boundary as every other rich-text
		// ACF field in this plugin (node.content, ryv_partner_description, a
		// partner card's own `description` sub-field above).
		$description_html = '' !== $description
			? sprintf( '<div class="ryv-partner-card-description">%s</div>', wp_kses_post( $description ) )
			: '';

		$quote_html = '' !== $quote
			? sprintf( '<blockquote class="ryv-partner-card-quote"><p>%s</p></blockquote>', self::format_quote( $quote ) )
			: '';

		return sprintf(
			'<div class="ryv-partner-section-note">%s%s</div>',
			$description_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() above.
			$quote_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format_quote() above (wp_kses()+nl2br()).
		);
	}

	/**
	 * One partner's card: logo, name and - when the row has a URL - a "Visit"
	 * link. (Location, description and quote were dropped from the rows at
	 * the client's request; the list-wide description/quote below the cards
	 * covers that copy.)
	 *
	 * @param array $row
	 * @return string '' if the row has no name (nothing safe to anchor a
	 *                 card on).
	 */
	private static function render_card( array $row ): string {
		$name = trim( (string) ( $row['name'] ?? '' ) );
		if ( '' === $name ) {
			return '';
		}

		$logo_id = isset( $row['logo'] ) ? absint( $row['logo'] ) : 0;
		$url     = trim( (string) ( $row['url'] ?? '' ) );

		if ( $logo_id && wp_attachment_is_image( $logo_id ) ) {
			$alt       = trim( (string) get_post_meta( $logo_id, '_wp_attachment_image_alt', true ) );
			$logo_html = wp_get_attachment_image(
				$logo_id,
				'ryv-partner-logo',
				false,
				array(
					'class' => 'ryv-partner-logo',
					'alt'   => '' !== $alt ? $alt : $name,
				)
			);
		} else {
			// No real logo yet - a generic placeholder mark instead of
			// leaving a gap, so every card keeps the same header layout.
			// Decorative only (the name text next to it already conveys
			// who the partner is), hence aria-hidden.
			$logo_html = self::placeholder_logo_svg();
		}

		$link_html = '';
		if ( '' !== $url ) {
			$link_html = sprintf(
				'<a class="ryv-partner-card-link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s <span class="ryv-partner-card-link-arrow" aria-hidden="true">&rarr;</span></a>',
				esc_url( $url ),
				esc_html(
					sprintf(
						/* translators: %s: partner name */
						self::localized( __( 'Visit %s', 'ryv-eligibility-tool' ), 'Visitar %s' ),
						$name
					)
				)
			);
		}

		return sprintf(
			'<article class="ryv-partner-card">'
				. '<header class="ryv-partner-card-header">%1$s<div class="ryv-partner-card-heading">'
				. '<h3 class="ryv-partner-card-name">%2$s</h3>'
				. '</div></header>%3$s'
				. '</article>',
			$logo_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image()/placeholder_logo_svg(), both already-safe markup.
			esc_html( $name ),
			$link_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url()/esc_html() above.
		);
	}

	/**
	 * A `quote` field is a plain `textarea`, not a WYSIWYG - so unlike
	 * `description` above it gets `esc_html()`, not `wp_kses_post()` -  but a
	 * quote with an attribution line (e.g. "...quote text."<br>- Jane Doe,
	 * Chair") needs *some* way to break the line, and a textarea has no rich
	 * formatting to do it with. Real quotes on this site are typed with a
	 * literal `<br>` for exactly that reason, which `esc_html()` alone turns
	 * into visible `&lt;br&gt;` text instead of a line break.
	 *
	 * `wp_kses()` allowing only `<br>` covers that literal-tag case (and
	 * strips anything else typed by accident, same trust boundary as
	 * `esc_html()` - it's still admin-only input); `nl2br()` on top covers a
	 * quote typed across multiple lines in the textarea itself, since a
	 * plain `\n` would otherwise collapse in the rendered `<p>`.
	 *
	 * @param string $quote Raw ACF field value.
	 * @return string Safe to echo inside a `<blockquote><p>`.
	 */
	private static function format_quote( string $quote ): string {
		return nl2br( wp_kses( $quote, array( 'br' => array() ) ) );
	}

	/**
	 * Generic placeholder mark for a Featured Partners row with no logo set
	 * yet - a plain building/org glyph, not a photo, so it never gets
	 * mistaken for a real (if oddly generic) partner logo. Static markup,
	 * nothing dynamic in it, so no escaping is needed on the way out.
	 *
	 * Sized/colored by the `.ryv-partner-logo-placeholder` rule in
	 * assets/src/input.css (both front ends load that stylesheet - see
	 * class docblock), not inline, so a Style-tab logo-size control on the
	 * widget resizes the placeholder exactly like a real logo.
	 *
	 * @return string
	 */
	public static function placeholder_logo_svg(): string {
		return '<span class="ryv-partner-logo ryv-partner-logo-placeholder" aria-hidden="true">'
			. '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
			. '<rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/>'
			. '<path d="M8 21V8.5C8 8.22386 8.22386 8 8.5 8H15.5C15.7761 8 16 8.22386 16 8.5V21" stroke="currentColor" stroke-width="1.5"/>'
			. '<path d="M10.5 11.5H13.5M10.5 14.5H13.5M10.5 17.5H13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'
			. '</svg>'
			. '</span>';
	}

	/**
	 * Visitor-facing wording for the state page, in the page's language.
	 * The site ships no compiled translations for this plugin's textdomain,
	 * so the Spanish edition's strings live here rather than in `__()`.
	 *
	 * @param string $english English text.
	 * @param string $spanish Spanish text.
	 * @return string
	 */
	public static function localized( string $english, string $spanish ): string {
		$lang = apply_filters( 'wpml_current_language', null );

		if ( ! is_string( $lang ) || '' === $lang ) {
			$lang = substr( determine_locale(), 0, 2 );
		}

		return 'es' === $lang ? $spanish : $english;
	}

	/**
	 * Same state resolution RYV_States_Template::get_state_id() uses -
	 * prefer the queried object over the (possibly Loop-Grid-swapped)
	 * global post. Shared by both front ends so neither has to duplicate
	 * it a third time.
	 *
	 * @return int
	 */
	public static function get_state_id(): int {
		$queried_id = (int) get_queried_object_id();

		if ( $queried_id && class_exists( 'RYV_States_CPT' ) && RYV_States_CPT::POST_TYPE === get_post_type( $queried_id ) ) {
			return $queried_id;
		}

		return (int) get_the_ID();
	}
}
