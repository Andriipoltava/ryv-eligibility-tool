<?php
/**
 * Elementor dynamic tags for the single blog-post template - thin wrappers
 * over RYV_Post_Meta, which holds the actual rules.
 *
 * - RYV: Post Topic        (text)  the featured topic category's name
 * - RYV: Post Topic URL    (url)   ... its archive link
 * - RYV: Reading Time      (text)  "4 min read" (format editable)
 * - RYV: Author Job Title  (text)  ACF "Job Title" on the author's profile
 * - RYV: Author Photo      (image) ACF "Photo", falling back to Gravatar
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Modules\DynamicTags\Module as Tags;

/**
 * RYV: Post Topic.
 */
class RYV_Tag_Post_Topic extends \Elementor\Core\DynamicTags\Tag {

	public function get_name() {
		return 'ryv-post-topic';
	}

	public function get_title() {
		return __( 'RYV: Post Topic', 'ryv-eligibility-tool' );
	}

	public function get_group() {
		return 'post';
	}

	public function get_categories() {
		return array( Tags::TEXT_CATEGORY );
	}

	public function render() {
		$topic = RYV_Post_Meta::get_primary_topic( (int) get_the_ID() );

		if ( $topic ) {
			echo esc_html( $topic->name );
		}
	}
}

/**
 * RYV: Post Topic URL.
 */
class RYV_Tag_Post_Topic_Url extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name() {
		return 'ryv-post-topic-url';
	}

	public function get_title() {
		return __( 'RYV: Post Topic URL', 'ryv-eligibility-tool' );
	}

	public function get_group() {
		return 'post';
	}

	public function get_categories() {
		return array( Tags::URL_CATEGORY );
	}

	public function get_value( array $options = array() ) {
		$topic = RYV_Post_Meta::get_primary_topic( (int) get_the_ID() );
		$link  = $topic ? get_term_link( $topic ) : '';

		return is_wp_error( $link ) ? '' : (string) $link;
	}
}

/**
 * RYV: Reading Time.
 */
class RYV_Tag_Reading_Time extends \Elementor\Core\DynamicTags\Tag {

	public function get_name() {
		return 'ryv-reading-time';
	}

	public function get_title() {
		return __( 'RYV: Reading Time', 'ryv-eligibility-tool' );
	}

	public function get_group() {
		return 'post';
	}

	public function get_categories() {
		return array( Tags::TEXT_CATEGORY );
	}

	protected function register_controls() {
		$this->add_control(
			'format',
			array(
				'label'       => __( 'Format', 'ryv-eligibility-tool' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( '%d min read', 'ryv-eligibility-tool' ),
				'description' => __( '%d is replaced with the number of minutes.', 'ryv-eligibility-tool' ),
			)
		);
	}

	public function render() {
		$format = (string) $this->get_settings( 'format' );

		echo esc_html( str_replace( '%d', (string) RYV_Post_Meta::get_reading_minutes( (int) get_the_ID() ), $format ) );
	}
}

/**
 * RYV: Author Job Title.
 */
class RYV_Tag_Author_Title extends \Elementor\Core\DynamicTags\Tag {

	public function get_name() {
		return 'ryv-author-title';
	}

	public function get_title() {
		return __( 'RYV: Author Job Title', 'ryv-eligibility-tool' );
	}

	public function get_group() {
		return 'author';
	}

	public function get_categories() {
		return array( Tags::TEXT_CATEGORY );
	}

	public function render() {
		echo esc_html( RYV_Post_Meta::get_author_title( (int) get_post_field( 'post_author', get_the_ID() ) ) );
	}
}

/**
 * RYV: Author Photo.
 */
class RYV_Tag_Author_Photo extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name() {
		return 'ryv-author-photo';
	}

	public function get_title() {
		return __( 'RYV: Author Photo', 'ryv-eligibility-tool' );
	}

	public function get_group() {
		return 'author';
	}

	public function get_categories() {
		return array( Tags::IMAGE_CATEGORY );
	}

	public function get_value( array $options = array() ) {
		return RYV_Post_Meta::get_author_photo( (int) get_post_field( 'post_author', get_the_ID() ) );
	}
}

/**
 * RYV: Byline Meta - "Director, Restore Your Vote · Mar 4, 2026 · 4 min
 * read" as one line, each part optional, empty parts (an author with no
 * Job Title) dropped along with their separator.
 */
class RYV_Tag_Byline_Meta extends \Elementor\Core\DynamicTags\Tag {

	public function get_name() {
		return 'ryv-byline-meta';
	}

	public function get_title() {
		return __( 'RYV: Byline Meta', 'ryv-eligibility-tool' );
	}

	public function get_group() {
		return 'post';
	}

	public function get_categories() {
		return array( Tags::TEXT_CATEGORY );
	}

	protected function register_controls() {
		$this->add_control(
			'show_title',
			array(
				'label'        => __( 'Author Job Title', 'ryv-eligibility-tool' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'show_format',
			array(
				'label'        => __( 'Resource Format', 'ryv-eligibility-tool' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'description'  => __( 'RYV Resources only: the ticked Resource Types, e.g. Guide · PDF.', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'show_date',
			array(
				'label'        => __( 'Date', 'ryv-eligibility-tool' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'date_format',
			array(
				'label'       => __( 'Date Format', 'ryv-eligibility-tool' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'M j, Y',
				'description' => __( 'PHP date format, e.g. "M j, Y" = Mar 4, 2026.', 'ryv-eligibility-tool' ),
				'condition'   => array( 'show_date' => 'yes' ),
			)
		);

		$this->add_control(
			'show_reading',
			array(
				'label'        => __( 'Reading Time', 'ryv-eligibility-tool' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'reading_format',
			array(
				'label'     => __( 'Reading Time Format', 'ryv-eligibility-tool' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( '%d min read', 'ryv-eligibility-tool' ),
				'condition' => array( 'show_reading' => 'yes' ),
			)
		);

		$this->add_control(
			'separator',
			array(
				'label'   => __( 'Separator', 'ryv-eligibility-tool' ),
				'type'    => Controls_Manager::TEXT,
				'default' => '  ·  ',
			)
		);
	}

	public function render() {
		$settings = $this->get_settings();
		$post_id  = (int) get_the_ID();
		$parts    = array();

		if ( 'yes' === $settings['show_title'] ) {
			$parts[] = RYV_Post_Meta::get_author_title( (int) get_post_field( 'post_author', $post_id ) );
		}
		if ( ! empty( $settings['show_format'] ) ) {
			// The ticked Resource Types ("Guide · PDF"); the older free-text
			// Format field only when none are ticked yet.
			$types = taxonomy_exists( RYV_Resources_CPT::TYPE_TAXONOMY ) ? get_the_terms( $post_id, RYV_Resources_CPT::TYPE_TAXONOMY ) : array();
			if ( is_array( $types ) && $types ) {
				$parts[] = implode( ' · ', array_map( array( 'RYV_Resources_CPT', 'get_term_label' ), $types ) );
			} elseif ( function_exists( 'get_field' ) ) {
				$parts[] = (string) get_field( 'ryv_resource_format', $post_id );
			}
		}
		if ( 'yes' === $settings['show_date'] ) {
			$parts[] = get_the_date( (string) $settings['date_format'], $post_id );
		}
		if ( 'yes' === $settings['show_reading'] ) {
			$parts[] = str_replace( '%d', (string) RYV_Post_Meta::get_reading_minutes( $post_id ), (string) $settings['reading_format'] );
		}

		echo esc_html( implode( (string) $settings['separator'], array_filter( array_map( 'trim', $parts ), 'strlen' ) ) );
	}
}

/**
 * RYV: Resource Link - where a resource's CTA button goes: its uploaded
 * File when there is one, else its URL field.
 */
class RYV_Tag_Resource_Link extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name() {
		return 'ryv-resource-link';
	}

	public function get_title() {
		return __( 'RYV: Resource File / URL', 'ryv-eligibility-tool' );
	}

	public function get_group() {
		return 'post';
	}

	public function get_categories() {
		return array( Tags::URL_CATEGORY );
	}

	public function get_value( array $options = array() ) {
		return RYV_Resources_CPT::get_target_url( (int) get_the_ID() );
	}
}
