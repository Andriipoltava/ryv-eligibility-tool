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

		$rows = get_field( 'featured_partners', $post_id );

		if ( is_array( $rows ) && count( $rows ) > 0 ) {
			return self::render_cards( $rows );
		}

		// Legacy fallback (see class docblock): one card built from the old
		// single-partner fields (which is exactly what they were - one
		// partner's logo/name/location/description/quote/link, just not in
		// a repeater), so a state RYV_Partners_Migration hasn't reached yet
		// keeps rendering its existing content unchanged.
		$legacy_name = trim( (string) get_field( 'ryv_partner_name', $post_id ) );
		if ( '' === $legacy_name ) {
			return '';
		}

		return self::render_cards(
			array(
				array(
					'logo'        => null,
					'name'        => $legacy_name,
					'url'         => (string) get_field( 'ryv_partner_link_url', $post_id ),
					'location'    => (string) get_field( 'ryv_partner_location', $post_id ),
					'description' => (string) get_field( 'ryv_partner_description', $post_id ),
					'quote'       => (string) get_field( 'ryv_partner_quote', $post_id ),
				),
			)
		);
	}

	/**
	 * @param array $rows Repeater rows (or the single-row legacy fallback),
	 *                     each `{ logo: int|null, name, url, location,
	 *                     description, quote: string }`.
	 * @return string
	 */
	private static function render_cards( array $rows ): string {
		$cards = '';

		foreach ( $rows as $row ) {
			$card = self::render_card( $row );
			if ( '' !== $card ) {
				$cards .= $card;
			}
		}

		if ( '' === $cards ) {
			return '';
		}

		return '<div class="ryv-partner-cards">' . $cards . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $cards is pre-escaped per card in render_card().
	}

	/**
	 * One partner's card. Every piece past the name/logo header is
	 * independently optional - a row missing `description` just skips that
	 * paragraph rather than rendering an empty one, same for `quote`,
	 * `location`, and the "Visit" link (which additionally needs `url`, not
	 * just a non-empty field, since there's nowhere to send a click without
	 * one).
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

		$logo_id     = isset( $row['logo'] ) ? absint( $row['logo'] ) : 0;
		$url         = trim( (string) ( $row['url'] ?? '' ) );
		$location    = trim( (string) ( $row['location'] ?? '' ) );
		$description = trim( (string) ( $row['description'] ?? '' ) );
		$quote       = trim( (string) ( $row['quote'] ?? '' ) );

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

		$location_html = '' !== $location
			? sprintf( '<p class="ryv-partner-card-location">%s</p>', esc_html( $location ) )
			: '';

		// Trusted HTML, same boundary as every other rich-text ACF field in
		// this plugin (node.content/node.title, ryv_partner_description
		// before it) - admin-only (manage_options via the builder, or
		// edit_post via ACF on the state's own edit screen), never visitor
		// input.
		$description_html = '' !== $description
			? sprintf( '<div class="ryv-partner-card-description">%s</div>', wp_kses_post( $description ) )
			: '';

		$quote_html = '' !== $quote
			? sprintf( '<blockquote class="ryv-partner-card-quote"><p>%s</p></blockquote>', esc_html( $quote ) )
			: '';

		$link_html = '';
		if ( '' !== $url ) {
			$link_html = sprintf(
				'<a class="ryv-partner-card-link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s <span class="ryv-partner-card-link-arrow" aria-hidden="true">&rarr;</span></a>',
				esc_url( $url ),
				esc_html(
					sprintf(
						/* translators: %s: partner name */
						__( 'Visit %s', 'ryv-eligibility-tool' ),
						$name
					)
				)
			);
		}

		return sprintf(
			'<article class="ryv-partner-card">'
				. '<header class="ryv-partner-card-header">%1$s<div class="ryv-partner-card-heading">'
				. '<h3 class="ryv-partner-card-name">%2$s</h3>%3$s'
				. '</div></header>%4$s%5$s%6$s'
				. '</article>',
			$logo_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image()/placeholder_logo_svg(), both already-safe markup.
			esc_html( $name ),
			$location_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() above.
			$description_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() above.
			$quote_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() above.
			$link_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url()/esc_html() above.
		);
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
