<?php
/**
 * Elementor integration for the "State Post" single template (the Theme
 * Builder template applied to `ryv_states`, condition
 * `include/singular/ryv_states`) - lets that template's widgets bind
 * directly to the ACF fields registered in RYV_States_ACF, and lets an
 * admin mark any container/section to disappear on the frontend when the
 * ACF field(s) it displays are empty for the current state.
 *
 * Two independent pieces, both purely additive (no change to how the
 * template itself is authored beyond using them):
 *
 * 1. Two small custom Dynamic Tags for ACF *repeater* rows
 *    (`RYV_Tag_ACF_Repeater_Text` / `RYV_Tag_ACF_Repeater_Image`).
 *    Elementor Pro's own built-in ACF dynamic tags
 *    (ElementorPro\Modules\DynamicTags\ACF) only ever list a field group's
 *    *top-level* fields (`Module::get_control_options()` calls
 *    `acf_get_fields( $group )`, which does not descend into a repeater's
 *    `sub_fields`) - there is no built-in way to point a widget at one
 *    specific row of `ryv_hero_stats`/`ryv_coalition_logos`. These two tags
 *    read the row directly via `get_post_meta( $post_id, "{repeater}_{row}_{sub}" )`
 *    - the flattened per-row meta key ACF always writes a repeater row's
 *    raw value under, regardless of the field's `return_format` (that
 *    formatting is applied by `get_field()`/`get_sub_field()` at read time,
 *    never at storage time) - rather than trying to coax
 *    `get_field_object()` into resolving a sub-field key outside of ACF's
 *    own row loop, which it is not designed to do.
 *
 * 2. `ryv-hide-empty--field-{acf_field_name}` /
 *    `ryv-hide-empty--repeater-{repeater_field_name}-{row_index}` - two
 *    marker prefixes an admin can add to any element's Advanced ▸ CSS
 *    Classes control in the template. Elementor has no built-in
 *    "conditionally render this container" feature (Display Conditions is
 *    Popups-only), so this buffers that one element's own render output
 *    between `elementor/frontend/before_render` / `after_render` (fired for
 *    every element type - container, section, column, widget - from
 *    `Element_Base::render()`) and discards the buffer instead of echoing
 *    it when the referenced field/row is empty for the current state.
 *    Nesting is handled for free by PHP's own output-buffer stack - no
 *    bookkeeping needed on this class's side.
 *
 * ACF-gated, same as every other class touching the `ryv_states` ACF field
 * groups.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_States_Template
 */
class RYV_States_Template {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'elementor/dynamic_tags/register', array( $this, 'register_dynamic_tags' ) );
		add_action( 'elementor/frontend/before_render', array( $this, 'maybe_start_buffer' ) );
		add_action( 'elementor/frontend/after_render', array( $this, 'maybe_flush_buffer' ) );
	}

	/**
	 * Register the ACF-repeater-row dynamic tags with Elementor.
	 *
	 * @param \Elementor\Core\DynamicTags\Manager $dynamic_tags_manager Elementor's dynamic tags manager.
	 */
	public function register_dynamic_tags( $dynamic_tags_manager ) {
		require_once __DIR__ . '/elementor-tags/class-ryv-tag-acf-repeater-text.php';
		require_once __DIR__ . '/elementor-tags/class-ryv-tag-acf-repeater-image.php';

		$dynamic_tags_manager->register( new RYV_Tag_ACF_Repeater_Text() );
		$dynamic_tags_manager->register( new RYV_Tag_ACF_Repeater_Image() );
	}

	/**
	 * The `ryv-hide-empty--...` prefix this whole mechanism keys off of.
	 */
	const MARKER_PREFIX = 'ryv-hide-empty--';

	/**
	 * Start an output buffer for an element marked `ryv-hide-empty--...`,
	 * so its rendered markup can be inspected (and possibly discarded)
	 * once `after_render` fires for the same element.
	 *
	 * @param \Elementor\Element_Base $element The element about to render.
	 */
	public function maybe_start_buffer( $element ) {
		if ( null !== $this->get_marker( $element ) ) {
			ob_start();
		}
	}

	/**
	 * Close out the buffer opened in maybe_start_buffer() for the same
	 * element, echoing it back only if the field/row it's gated on isn't
	 * empty for the current state.
	 *
	 * @param \Elementor\Element_Base $element The element that just rendered.
	 */
	public function maybe_flush_buffer( $element ) {
		$marker = $this->get_marker( $element );
		if ( null === $marker ) {
			return;
		}

		$buffer = ob_get_clean();

		if ( $this->is_marker_empty( $marker ) ) {
			return;
		}

		echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor's own already-escaped render output, replayed verbatim.
	}

	/**
	 * @param \Elementor\Element_Base $element The element to inspect.
	 * @return string|null The marker suffix (everything after the
	 *                      MARKER_PREFIX) if present, else null.
	 */
	private function get_marker( $element ) {
		$classes = (string) $element->get_settings( 'css_classes' );
		if ( '' === $classes ) {
			return null;
		}

		foreach ( preg_split( '/\s+/', trim( $classes ) ) as $class ) {
			if ( 0 === strpos( $class, self::MARKER_PREFIX ) ) {
				return substr( $class, strlen( self::MARKER_PREFIX ) );
			}
		}

		return null;
	}

	/**
	 * @param string $marker Marker suffix from get_marker(), e.g.
	 *                        "field-ryv_glance_body" or "repeater-ryv_coalition_logos-3".
	 * @return bool Whether the referenced field/row is empty (or the
	 *              marker/context couldn't be resolved at all - fails
	 *              closed, i.e. hides the element, rather than risking a
	 *              broken-looking section on a malformed marker).
	 */
	private function is_marker_empty( $marker ) {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return true;
		}

		if ( 0 === strpos( $marker, 'field-' ) ) {
			if ( ! function_exists( 'get_field' ) ) {
				return true;
			}
			$field_name = substr( $marker, strlen( 'field-' ) );
			$value      = get_field( $field_name, $post_id );
			return $this->is_value_empty( $value );
		}

		if ( 0 === strpos( $marker, 'repeater-' ) ) {
			$rest = substr( $marker, strlen( 'repeater-' ) );
			$sep  = strrpos( $rest, '-' );
			if ( false === $sep ) {
				return true;
			}
			$repeater_name = substr( $rest, 0, $sep );
			$row_index     = (int) substr( $rest, $sep + 1 );
			$row_count     = (int) get_post_meta( $post_id, $repeater_name, true );
			return $row_index >= $row_count;
		}

		return true;
	}

	/**
	 * @param mixed $value An ACF get_field() return value.
	 * @return bool
	 */
	private function is_value_empty( $value ) {
		if ( is_array( $value ) ) {
			// A WYSIWYG/text field never returns an array; an array here
			// means a repeater, relationship, gallery, or image
			// (return_format=array) field - all of which are "empty" iff
			// they have no entries, including an image field's own array
			// shape (which always has an 'ID' key present-but-empty when
			// no image is selected).
			if ( isset( $value['ID'] ) || isset( $value['id'] ) ) {
				return empty( $value['ID'] ) && empty( $value['id'] );
			}
			return empty( $value );
		}

		// A WYSIWYG field with only empty markup (e.g. "<p></p>") should
		// still count as empty for this purpose.
		return '' === trim( wp_strip_all_tags( (string) $value ) );
	}
}
