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
 *    `ryv-hide-empty--any-{field_a},{field_b}` (empty only when every
 *    listed field is) /
 *    `ryv-hide-empty--repeater-{repeater_field_name}-{row_index}` - marker
 *    prefixes an admin can add to any element's Advanced ▸ CSS
 *    Classes control in the template. Elementor's own Display Conditions
 *    cannot be used for this at all: its comparator is typed `string` and
 *    fatals on any ACF field that returns an array (a relationship like
 *    `ryv_events`/`ryv_resources`), which takes the entire template's
 *    render down with it - a live incident, and the reason those
 *    conditions must never be re-added here.
 *
 *    Applied in two ways, because one alone doesn't cover the template:
 *    - `output_hide_empty_css()` emits a `display:none` rule per empty
 *      marker on `wp_head`. This is what actually gates the **sections**,
 *      since Elementor never fires its render hooks for containers (see
 *      that method's own docblock).
 *    - the output buffering between `elementor/frontend/before_render` /
 *      `after_render` removes a marked **widget's** markup outright.
 *      Nesting is handled for free by PHP's own output-buffer stack.
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
		add_action( 'wp_head', array( $this, 'output_hide_empty_css' ), 20 );
		add_action( 'save_post_elementor_library', array( __CLASS__, 'flush_marker_cache' ) );
		add_action( 'elementor/editor/after_save', array( __CLASS__, 'flush_marker_cache' ) );
	}

	/**
	 * The CSS half of the hide-empty mechanism: one `display: none` rule per
	 * marker whose field is empty for the state being viewed.
	 *
	 * This exists because the output-buffer half above **only ever reaches
	 * widgets**. Elementor renders a container's markup from its cached
	 * document content and only re-enters `Element_Base::print_element()` -
	 * the one place `elementor/frontend/before_render`/`after_render` fire -
	 * for widgets (verified by logging every element that reaches those
	 * hooks on a state page: 52 widgets, zero containers). Every marker in
	 * this template is on a *container*, so the buffering never had anything
	 * to act on, and the sections it was supposed to gate simply always
	 * rendered.
	 *
	 * Hiding in CSS rather than removing markup is the deliberate trade: it
	 * is immune to how (or whether) Elementor cached that element, needs no
	 * hook at render time at all, and the alternative - post-processing the
	 * whole document's HTML to delete nodes - is far more invasive for a
	 * section that is empty anyway. The buffering stays for widget-level
	 * markers, where it still removes markup outright.
	 *
	 * Markers are discovered from the site's own Elementor templates rather
	 * than guessed, so a marker an admin adds keeps working with no code
	 * change here.
	 */
	public function output_hide_empty_css() {
		if ( ! is_singular( RYV_States_CPT::POST_TYPE ) || ! function_exists( 'get_field' ) ) {
			return;
		}

		$post_id = $this->get_state_id();

		if ( ! $post_id ) {
			return;
		}

		$selectors = array();

		foreach ( $this->collect_markers() as $marker ) {
			if ( $this->is_marker_empty( $marker ) ) {
				// Escape only what can legally appear in a marker anyway
				// (word characters, comma, dash) - anything else is dropped
				// by collect_markers()' own pattern.
				$selectors[] = '.' . str_replace( ',', '\\,', self::MARKER_PREFIX . $marker );
			}
		}

		if ( ! $selectors ) {
			return;
		}

		printf(
			'<style id="ryv-hide-empty">%s{display:none !important}</style>' . "\n",
			implode( ',', $selectors ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from the constrained pattern in collect_markers().
		);
	}

	/**
	 * Every `ryv-hide-empty--...` marker used anywhere in the site's
	 * Elementor templates.
	 *
	 * Read from `elementor_library` posts (a handful, and only their
	 * `_elementor_data`) rather than from the document being rendered,
	 * because this runs on `wp_head` - before any of that markup exists.
	 * Cached for an hour, and flushed whenever a template is saved, so the
	 * usual request pays nothing.
	 *
	 * @return string[] Marker suffixes, e.g. "field-ryv_resources".
	 */
	private function collect_markers(): array {
		$cached = get_transient( self::MARKERS_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$template_ids = get_posts(
			array(
				'post_type'        => 'elementor_library',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		$markers = array();

		foreach ( $template_ids as $template_id ) {
			$data = (string) get_post_meta( $template_id, '_elementor_data', true );

			if ( '' === $data ) {
				continue;
			}

			if ( preg_match_all( '/' . preg_quote( self::MARKER_PREFIX, '/' ) . '([A-Za-z0-9_,\-]+)/', $data, $matches ) ) {
				foreach ( $matches[1] as $marker ) {
					$markers[ $marker ] = true;
				}
			}
		}

		$markers = array_keys( $markers );

		set_transient( self::MARKERS_TRANSIENT, $markers, HOUR_IN_SECONDS );

		return $markers;
	}

	/**
	 * Drop the marker cache when any Elementor template is saved, so a
	 * marker an admin just added takes effect on the next page view rather
	 * than up to an hour later.
	 */
	public static function flush_marker_cache() {
		delete_transient( self::MARKERS_TRANSIENT );
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
	 * Transient holding every marker found across Elementor templates.
	 */
	const MARKERS_TRANSIENT = 'ryv_hide_empty_markers';

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

		$hide = $this->is_marker_empty( $marker );

		/**
		 * Filters whether a `ryv-hide-empty--...` element is discarded.
		 *
		 * The decision is invisible by design - a hidden section leaves no
		 * trace in the markup - so this is the only way to see why one
		 * disappeared without editing this file.
		 *
		 * @param bool   $hide    Whether the element is about to be discarded.
		 * @param string $marker  Marker suffix, e.g. "field-ryv_resources".
		 * @param int    $post_id The state post the marker was evaluated against.
		 */
		$hide = (bool) apply_filters( 'ryv_hide_empty_should_hide', $hide, $marker, $this->get_state_id() );

		if ( $hide ) {
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
	 * The state post the template is rendering, resolved from the *query*
	 * rather than from the global post.
	 *
	 * `get_the_ID()` alone is wrong here, and silently so: a marked section
	 * can contain a Loop Grid, whose own rendering swaps the global post to
	 * each looped item. This method runs on `after_render`, i.e. once those
	 * children have already rendered, so the global post can be a resource
	 * (or an event) by then - and `get_field( 'ryv_resources', <a resource> )`
	 * is empty, which discarded the entire Resources section on states that
	 * plainly had resources. Falls back to the global post so a marker still
	 * works if the template is ever rendered outside a state query.
	 *
	 * @return int
	 */
	private function get_state_id(): int {
		$queried_id = (int) get_queried_object_id();

		if ( $queried_id && RYV_States_CPT::POST_TYPE === get_post_type( $queried_id ) ) {
			return $queried_id;
		}

		return (int) get_the_ID();
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
		$post_id = $this->get_state_id();
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

		// `any-field_a,field_b` - empty only when *every* listed field is,
		// i.e. "show this section if any of these has content".
		//
		// Needed because a section can hold two independently-populated
		// blocks: the Partner highlight section also carries the events
		// column, so keying it on the partner field alone would take the
		// events with it on a state that has events but no partner. This is
		// the same intent as an Elementor "display condition" with two OR'd
		// rules - which cannot be used here at all, since Elementor Pro's
		// comparator fatals on an ACF field that returns an array (a
		// relationship like `ryv_events`), taking the whole template's
		// render down with it.
		if ( 0 === strpos( $marker, 'any-' ) ) {
			if ( ! function_exists( 'get_field' ) ) {
				return true;
			}

			$field_names = array_filter( array_map( 'trim', explode( ',', substr( $marker, strlen( 'any-' ) ) ) ) );

			if ( ! $field_names ) {
				return true;
			}

			foreach ( $field_names as $field_name ) {
				if ( ! $this->is_value_empty( get_field( $field_name, $post_id ) ) ) {
					return false;
				}
			}

			return true;
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

			// A repeater's row count is plain postmeta, read directly rather
			// than through `get_field()` - so it never passes through ACF's
			// filters, and with them RYV_States_I18n's translated-page
			// fallback. Without this, a Spanish state page showed every
			// other section's English copy but hid all of its stats.
			if ( ! $row_count && class_exists( 'RYV_States_I18n' ) ) {
				$source_id = RYV_States_I18n::get_source_post_id( $post_id );
				if ( $source_id ) {
					$row_count = (int) get_post_meta( $source_id, $repeater_name, true );
				}
			}

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
