<?php
/**
 * Dynamic Tag: one image sub-field from one row of an ACF repeater on the
 * current `ryv_states` post - see class-ryv-states-template.php's own
 * docblock for why Elementor Pro's built-in ACF tags can't reach this.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Tag_ACF_Repeater_Image
 */
class RYV_Tag_ACF_Repeater_Image extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name() {
		return 'ryv-acf-repeater-image';
	}

	public function get_title() {
		return __( 'RYV: ACF Repeater Image', 'ryv-eligibility-tool' );
	}

	public function get_group() {
		return 'image';
	}

	public function get_categories() {
		return array( \Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY );
	}

	protected function register_controls() {
		$this->add_control(
			'repeater_field',
			array(
				'label'       => __( 'Repeater Field Name', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'ryv_coalition_logos',
			)
		);

		$this->add_control(
			'row_index',
			array(
				'label'   => __( 'Row (0-based)', 'ryv-eligibility-tool' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 0,
				'min'     => 0,
			)
		);

		$this->add_control(
			'sub_field',
			array(
				'label'       => __( 'Sub Field Name', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'logo',
			)
		);
	}

	public function get_value( array $options = array() ) {
		$image_data = array(
			'id'  => null,
			'url' => '',
		);

		$repeater = trim( (string) $this->get_settings( 'repeater_field' ) );
		$sub      = trim( (string) $this->get_settings( 'sub_field' ) );

		if ( '' === $repeater || '' === $sub ) {
			return $image_data;
		}

		$row_index = max( 0, (int) $this->get_settings( 'row_index' ) );
		$post_id   = get_the_ID();
		if ( ! $post_id ) {
			return $image_data;
		}

		$meta_key       = $repeater . '_' . $row_index . '_' . $sub;
		$attachment_id  = (int) get_post_meta( $post_id, $meta_key, true );

		if ( $attachment_id ) {
			$image_data['id']  = $attachment_id;
			$image_data['url'] = wp_get_attachment_url( $attachment_id );
		}

		return $image_data;
	}
}
