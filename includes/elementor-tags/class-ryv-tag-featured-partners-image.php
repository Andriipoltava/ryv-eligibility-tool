<?php
/**
 * Dynamic Tag: the Featured Partners section's own big photo -
 * `featured_partners_image` (RYV_States_ACF::register_partner_highlight_group()),
 * with a fallback to the legacy `ryv_partner_image` field for any state that
 * hasn't had a new-field image set yet. A plain ACF Image dynamic tag can
 * only ever point at one field, and this section deliberately needs "field
 * A, or field B if A is empty" - the same fallback shape
 * RYV_Featured_Partners_Renderer already uses for text fields, just for an
 * image instead.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Tag_Featured_Partners_Image
 */
class RYV_Tag_Featured_Partners_Image extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name() {
		return 'ryv-featured-partners-image';
	}

	public function get_title() {
		return __( 'RYV: Featured Partners Image', 'ryv-eligibility-tool' );
	}

	public function get_group() {
		return 'image';
	}

	public function get_categories() {
		return array( \Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY );
	}

	public function get_value( array $options = array() ) {
		$image_data = array(
			'id'  => null,
			'url' => '',
		);

		if ( ! function_exists( 'get_field' ) ) {
			return $image_data;
		}

		$post_id = class_exists( 'RYV_Featured_Partners_Renderer' )
			? RYV_Featured_Partners_Renderer::get_state_id()
			: (int) get_the_ID();

		if ( ! $post_id ) {
			return $image_data;
		}

		$attachment_id = (int) get_field( 'featured_partners_image', $post_id );

		if ( ! $attachment_id ) {
			// Legacy fallback - `ryv_partner_image` is return_format=array,
			// so it comes back as {ID, url, ...} rather than a bare id.
			$legacy = get_field( 'ryv_partner_image', $post_id );
			if ( is_array( $legacy ) && ! empty( $legacy['ID'] ) ) {
				$attachment_id = (int) $legacy['ID'];
			}
		}

		if ( $attachment_id ) {
			$image_data['id']  = $attachment_id;
			$image_data['url'] = wp_get_attachment_url( $attachment_id );
		}

		return $image_data;
	}
}
