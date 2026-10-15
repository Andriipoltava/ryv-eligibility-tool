<?php
/**
 * `[ryv_state_field]` shortcode: outputs one ACF field from the current
 * `ryv_states` post, falling back to the post's own title when that field is
 * empty - built for the Hero Section's headline, which was rendering the
 * state's post_title ("Kentucky") for every state, because the Heading
 * widget in the "State Post" template was bound to Elementor's own Post
 * Title dynamic tag rather than to `ryv_hero_headline`
 * (see RYV_States_ACF::register_hero_group()) at all - a plain ACF Field
 * dynamic tag would fix the binding but still render nothing once an admin
 * *did* want that fallback for a state with no custom headline typed in yet.
 *
 * Meant to be dropped into a widget's text control via Elementor's own core
 * "Shortcode" dynamic tag (the small dynamic-content icon on any text
 * field), not as raw content in the editor - `[ryv_state_field]` with no
 * attributes at all already covers the headline case, since both defaults
 * below are the headline field and "fall back to the title".
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_States_Field_Shortcode
 */
class RYV_States_Field_Shortcode {

	/**
	 * Shortcode tag.
	 */
	const TAG = 'ryv_state_field';

	/**
	 * Shortcode tag for the "Source: <link>" line under the hero stats.
	 */
	const SOURCE_TAG = 'ryv_stats_source';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_shortcode' ) );
	}

	/**
	 * Register the [ryv_state_field] shortcode.
	 */
	public function register_shortcode() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_shortcode( self::SOURCE_TAG, array( $this, 'render_stats_source' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * `field`    - ACF field name on the current `ryv_states` post. Defaults
	 *              to `ryv_hero_headline`, the field this shortcode exists
	 *              for.
	 * `fallback` - "title" (default: the post's own post_title) or any
	 *              literal string to use instead when the field is empty.
	 *
	 * @param array|string $atts Shortcode attributes: field, fallback.
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'field'    => 'ryv_hero_headline',
				'fallback' => 'title',
			),
			$atts,
			self::TAG
		);

		$field_name = trim( (string) $atts['field'] );

		if ( '' === $field_name ) {
			return $this->error_markup( __( '[ryv_state_field] needs a "field" attribute (an ACF field name).', 'ryv-eligibility-tool' ) );
		}

		$post_id = $this->get_state_id();

		if ( ! $post_id || ! class_exists( 'RYV_States_CPT' ) || RYV_States_CPT::POST_TYPE !== get_post_type( $post_id ) ) {
			return $this->error_markup( __( '[ryv_state_field] must be used on (or inside a loop over) a State post.', 'ryv-eligibility-tool' ) );
		}

		$value = function_exists( 'get_field' ) ? get_field( $field_name, $post_id ) : '';
		$value = is_string( $value ) ? trim( $value ) : $value;

		if ( '' === $value || null === $value || false === $value ) {
			$value = ( 'title' === $atts['fallback'] ) ? get_the_title( $post_id ) : $atts['fallback'];
		}

		return wp_kses_post( (string) $value );
	}

	/**
	 * `[ryv_stats_source]` - "Source: <linked name>" lines for the hero
	 * stats (ACF repeater `ryv_hero_stats_sources`, one row per source).
	 * Renders nothing when there are no sources; a name renders unlinked if
	 * its row has no URL. Place it in a Shortcode widget inside the stats
	 * container and style that widget to match the stat descriptions.
	 *
	 * @return string
	 */
	public function render_stats_source(): string {
		$post_id = $this->get_state_id();

		if ( ! $post_id || ! function_exists( 'get_field' ) ) {
			return '';
		}

		$lines = '';

		foreach ( self::get_sources( $post_id ) as $source ) {
			$label = esc_html( $source['name'] );

			if ( '' !== $source['url'] ) {
				$label = sprintf( '<a href="%s" target="_blank" rel="noopener" style="color:inherit;text-decoration:underline">%s</a>', esc_url( $source['url'] ), $label );
			}

			$lines .= sprintf(
				'<div class="ryv-stats-source">%s %s</div>',
				esc_html( class_exists( 'RYV_Featured_Partners_Renderer' ) ? RYV_Featured_Partners_Renderer::localized( __( 'Source:', 'ryv-eligibility-tool' ), 'Fuente:' ) : __( 'Source:', 'ryv-eligibility-tool' ) ),
				$label
			);
		}

		return $lines;
	}

	/**
	 * The state's sources: the `ryv_hero_stats_sources` rows, or - while
	 * that is empty - the old single name/URL pair, read straight from
	 * postmeta (it was never an array, and a translated page's fallback to
	 * the original language is applied by hand, since this bypasses ACF's
	 * filters).
	 *
	 * @param int $post_id A `ryv_states` post.
	 * @return array<int,array{name:string,url:string}> Nameless rows dropped.
	 */
	public static function get_sources( int $post_id ): array {
		$sources = array();
		$rows    = class_exists( 'RYV_States_I18n' ) ? RYV_States_I18n::get_value( 'ryv_hero_stats_sources', $post_id ) : get_field( 'ryv_hero_stats_sources', $post_id );

		if ( class_exists( 'RYV_States_I18n' ) ) {
			$rows = RYV_States_I18n::name_rows(
				$rows,
				array(
					'field_ryv_hero_stats_source_row_name' => 'name',
					'field_ryv_hero_stats_source_row_url'  => 'url',
				)
			);
		}

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name = trim( (string) ( $row['name'] ?? '' ) );

			if ( '' !== $name ) {
				$sources[] = array(
					'name' => $name,
					'url'  => trim( (string) ( $row['url'] ?? '' ) ),
				);
			}
		}

		if ( $sources ) {
			return $sources;
		}

		$ids = array( $post_id );

		if ( class_exists( 'RYV_States_I18n' ) && RYV_States_I18n::get_source_post_id( $post_id ) ) {
			$ids[] = RYV_States_I18n::get_source_post_id( $post_id );
		}

		foreach ( $ids as $id ) {
			$name = trim( (string) get_post_meta( $id, 'ryv_hero_stats_source_name', true ) );

			if ( '' !== $name ) {
				return array(
					array(
						'name' => $name,
						'url'  => trim( (string) get_post_meta( $id, 'ryv_hero_stats_source_url', true ) ),
					),
				);
			}
		}

		return array();
	}

	/**
	 * Same resolution RYV_States_Template::get_state_id() uses (that class
	 * stays ACF-gated so isn't always loaded, hence a private copy here
	 * rather than a shared call): prefer the queried object over the
	 * (possibly Loop-Grid-swapped) global post, so a related-resources loop
	 * elsewhere on the same state page can't make this shortcode resolve to
	 * the wrong post.
	 *
	 * @return int
	 */
	private function get_state_id(): int {
		$queried_id = (int) get_queried_object_id();

		if ( $queried_id && class_exists( 'RYV_States_CPT' ) && RYV_States_CPT::POST_TYPE === get_post_type( $queried_id ) ) {
			return $queried_id;
		}

		return (int) get_the_ID();
	}

	/**
	 * Only rendered for whoever could actually act on it - matches the
	 * [ryv_form]/[ryv_resource_button_text] shortcodes' own edit-mode-only
	 * error convention.
	 *
	 * @param string $message Error message.
	 * @return string
	 */
	private function error_markup( string $message ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		return sprintf( '<div class="ryv-error-box">%s</div>', esc_html( $message ) );
	}
}
