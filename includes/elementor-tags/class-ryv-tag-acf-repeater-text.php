<?php
/**
 * Dynamic Tag: one text sub-field from one row of an ACF repeater on the
 * current `ryv_states` post - see class-ryv-states-template.php's own
 * docblock for why Elementor Pro's built-in ACF tags can't reach this.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Tag_ACF_Repeater_Text
 */
class RYV_Tag_ACF_Repeater_Text extends \Elementor\Core\DynamicTags\Tag {

	public function get_name() {
		return 'ryv-acf-repeater-text';
	}

	public function get_title() {
		return __( 'RYV: ACF Repeater Text', 'ryv-eligibility-tool' );
	}

	public function get_group() {
		return 'text';
	}

	public function get_categories() {
		return array( \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY, \Elementor\Modules\DynamicTags\Module::NUMBER_CATEGORY );
	}

	protected function register_controls() {
		$this->add_control(
			'repeater_field',
			array(
				'label'       => __( 'Repeater Field Name', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'ryv_hero_stats',
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
				'placeholder' => 'stat_number',
			)
		);

		$this->add_control(
			'extract',
			array(
				'label'   => __( 'Extract', 'ryv-eligibility-tool' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'none',
				'options' => array(
					'none'        => __( 'Full value', 'ryv-eligibility-tool' ),
					// For a field like "470,000+": the counter widget this
					// tag feeds needs the numeric part and the trailing
					// symbol as two separate settings (`ending_number` /
					// `suffix`) - these two modes let one text field drive
					// both without an admin having to maintain them as
					// separate ACF fields.
					'digits_only' => __( 'Leading digits only (for a Counter widget\'s Number)', 'ryv-eligibility-tool' ),
					'suffix'      => __( 'Trailing non-digit suffix (for a Counter widget\'s Suffix)', 'ryv-eligibility-tool' ),
				),
			)
		);
	}

	public function render() {
		$repeater = trim( (string) $this->get_settings( 'repeater_field' ) );
		$sub      = trim( (string) $this->get_settings( 'sub_field' ) );

		if ( '' === $repeater || '' === $sub ) {
			return;
		}

		$row_index = max( 0, (int) $this->get_settings( 'row_index' ) );
		$post_id   = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$meta_key = $repeater . '_' . $row_index . '_' . $sub;
		$value    = (string) get_post_meta( $post_id, $meta_key, true );

		// This reads the flattened row meta directly, so it bypasses ACF's
		// filters - and with them RYV_States_I18n's fallback. Apply the same
		// rule here, or a translated state page would show every other
		// section's English copy but blank stats.
		if ( '' === $value && class_exists( 'RYV_States_I18n' ) ) {
			$source_id = RYV_States_I18n::get_source_post_id( (int) $post_id );
			if ( $source_id ) {
				$value = (string) get_post_meta( $source_id, $meta_key, true );
			}
		}

		switch ( $this->get_settings( 'extract' ) ) {
			case 'digits_only':
				$value = preg_replace( '/[^0-9]/', '', $value );
				break;
			case 'suffix':
				$value = preg_replace( '/^[\d,.\s]+/', '', $value );
				break;
		}

		echo wp_kses_post( $value );
	}
}
