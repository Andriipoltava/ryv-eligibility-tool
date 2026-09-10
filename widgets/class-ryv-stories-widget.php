<?php
/**
 * Elementor widget: the "Real People, Real Impact" video-stories section -
 * one featured video (auto-plays muted once scrolled into view) beside a
 * list of shorter story rows. Each row - and the featured video's own play
 * button - opens that story's video in Elementor's own video lightbox (the
 * same modal its Video/Image widgets use, so the site's lightbox styling
 * applies for free), or in a new tab on YouTube, per the "Opening A Video"
 * control. There is deliberately still no in-page player of this widget's
 * own: the lightbox is Elementor's, wired up through data attributes only -
 * see get_lightbox_attributes().
 *
 * Backed by RYV_Stories_CPT (`ryv_stories`) - see that file for the field
 * set (YouTube URL, duration, location, quote, captioned/featured flags).
 * Renders outside `.ryv-app-wrapper`, so - like RYV_Map_Widget - its CSS in
 * assets/src/input.css is plain, unscoped rules rather than Tailwind
 * utilities under the `.ryv-app-wrapper` important-scope.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Stories_Widget
 */
class RYV_Stories_Widget extends \Elementor\Widget_Base {

	/**
	 * @return string
	 */
	public function get_name() {
		return 'ryv_stories';
	}

	/**
	 * @return string
	 */
	public function get_title() {
		return __( 'RYV Stories', 'ryv-eligibility-tool' );
	}

	/**
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-play-o';
	}

	/**
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'ryv' );
	}

	/**
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'stories', 'video', 'youtube', 'testimonial', 'impact' );
	}

	/**
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( RYV_Assets::STYLE_HANDLE );
	}

	/**
	 * Register Content + Style tab controls.
	 */
	protected function register_controls() {
		$this->register_header_controls();
		$this->register_query_controls();
		$this->register_behavior_controls();

		$this->register_header_style_controls();
		$this->register_featured_style_controls();
		$this->register_list_style_controls();
		$this->register_layout_style_controls();
	}

	/**
	 * Content: eyebrow / heading / "All Stories" link above the section,
	 * matching the design's "STORIES / Real People, Real Impact / All
	 * Stories →" header row.
	 */
	private function register_header_controls() {
		$this->start_controls_section(
			'header_section',
			array(
				'label' => __( 'Header', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_eyebrow',
			array(
				'label'        => __( 'Show Eyebrow Label', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'Hide', 'ryv-eligibility-tool' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'eyebrow_text',
			array(
				'label'       => __( 'Eyebrow Text', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Stories', 'ryv-eligibility-tool' ),
				'label_block' => true,
				'condition'   => array( 'show_eyebrow' => 'yes' ),
			)
		);

		$this->add_control(
			'heading_text',
			array(
				'label'       => __( 'Heading', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Real People, Real Impact', 'ryv-eligibility-tool' ),
				'label_block' => true,
			)
		);

		// These two used to be hardcoded `__()` strings in the render
		// methods. As controls they're translatable the same way every other
		// visitor-facing string on RYV's widgets is - per widget instance,
		// through WPML's translation editor (see wpml-config.xml) - rather
		// than needing a compiled .mo file for this plugin's own textdomain,
		// which the site doesn't ship.
		$this->add_control(
			'featured_label_text',
			array(
				'label'       => __( 'Featured Video Label', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Featured Video Story', 'ryv-eligibility-tool' ),
				'label_block' => true,
				'description' => __( 'The small label above the featured story\'s name. Leave blank to hide it.', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'captioned_text',
			array(
				'label'       => __( '"Captioned" Wording', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'captioned', 'ryv-eligibility-tool' ),
				'description' => __( 'Shown beside the duration on a story marked as captioned.', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'show_all_link',
			array(
				'label'        => __( 'Show "All Stories" Link', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'Hide', 'ryv-eligibility-tool' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'all_link_text',
			array(
				'label'     => __( 'Link Text', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'All Stories', 'ryv-eligibility-tool' ),
				'condition' => array( 'show_all_link' => 'yes' ),
			)
		);

		$this->add_control(
			'all_link_url',
			array(
				'label'       => __( 'Link URL', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'default'     => array(
					'url'         => '',
					'is_external' => false,
					'nofollow'    => false,
				),
				'label_block' => true,
				'condition'   => array( 'show_all_link' => 'yes' ),
			)
		);

		$this->add_control(
			'show_divider',
			array(
				'label'        => __( 'Show Divider Line', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'Hide', 'ryv-eligibility-tool' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content: which stories to pull and how many, from RYV_Stories_CPT.
	 */
	private function register_query_controls() {
		$this->start_controls_section(
			'query_section',
			array(
				'label' => __( 'Stories', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'query_notice',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => __( 'Add/edit stories under RYV Stories in the admin menu. Check "Featured" on exactly one story to use as the large video - the widget falls back to the first story (by Order) if none is marked.', 'ryv-eligibility-tool' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		$this->add_control(
			'list_count',
			array(
				'label'   => __( 'Number of List Stories', 'ryv-eligibility-tool' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 20,
				'default' => 3,
				'description' => __( 'How many stories to show in the list, not counting the featured video.', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'order_by',
			array(
				'label'   => __( 'Order By', 'ryv-eligibility-tool' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'menu_order' => __( 'Manual (Order field)', 'ryv-eligibility-tool' ),
					'date'       => __( 'Publish Date', 'ryv-eligibility-tool' ),
					'title'      => __( 'Title', 'ryv-eligibility-tool' ),
				),
				'default' => 'menu_order',
			)
		);

		$this->add_control(
			'order',
			array(
				'label'   => __( 'Order Direction', 'ryv-eligibility-tool' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'ASC'  => __( 'Ascending', 'ryv-eligibility-tool' ),
					'DESC' => __( 'Descending', 'ryv-eligibility-tool' ),
				),
				'default' => 'ASC',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content: playback behavior - the muted autoplay preview, and what a
	 * click on a story actually does (Elementor's video lightbox, or a new
	 * tab on YouTube).
	 */
	private function register_behavior_controls() {
		$this->start_controls_section(
			'behavior_section',
			array(
				'label' => __( 'Video Behavior', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'featured_layout',
			array(
				'label'   => __( 'Featured Video Layout', 'ryv-eligibility-tool' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'diagonal' => __( 'Diagonal Split (photo cut on an angle, caption beside it)', 'ryv-eligibility-tool' ),
					'bottom'   => __( 'Full-Bleed Photo (caption over a bottom gradient)', 'ryv-eligibility-tool' ),
				),
				'default' => 'diagonal',
				'description' => __( 'The diagonal split matches the reference design but can crowd a busy or portrait-oriented photo - switch to full-bleed if a particular story\'s image doesn\'t suit it.', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'autoplay_on_scroll',
			array(
				'label'        => __( 'Autoplay Featured Video (Muted) On Scroll', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'No', 'ryv-eligibility-tool' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'When the featured video scrolls into view, it starts playing muted in place. Visitors click it (or any list item) to watch it with sound - see "Opening A Video" below.', 'ryv-eligibility-tool' ),
			)
		);

		// Elementor's own lightbox, not a player of this widget's own: the
		// featured play button and every list row hand their video to
		// `data-elementor-lightbox` (see get_lightbox_attributes()), which
		// is the same modal Elementor's Video/Image widgets open, so it
		// inherits the site's lightbox styling and behavior for free.
		$this->add_control(
			'video_click_action',
			array(
				'label'       => __( 'Opening A Video', 'ryv-eligibility-tool' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'lightbox' => __( 'Elementor Lightbox (in a modal)', 'ryv-eligibility-tool' ),
					'new_tab'  => __( 'New Tab (on YouTube)', 'ryv-eligibility-tool' ),
				),
				'default'     => 'lightbox',
				'description' => __( 'The modal is Elementor\'s standard video lightbox. Either way the link still points at the video itself, so it keeps working if scripts are blocked.', 'ryv-eligibility-tool' ),
			)
		);

		$this->add_control(
			'lightbox_aspect_ratio',
			array(
				'label'     => __( 'Lightbox Aspect Ratio', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				// Elementor's own ratio keys - the lightbox maps these to its
				// modal classes, so only these five values mean anything here.
				'options'   => array(
					'169' => '16:9',
					'219' => '21:9',
					'43'  => '4:3',
					'11'  => '1:1',
					'916' => '9:16',
				),
				'default'   => '169',
				'condition' => array( 'video_click_action' => 'lightbox' ),
			)
		);

		$this->add_control(
			'show_video_overlay',
			array(
				'label'        => __( 'Show Dark Overlay On Photo', 'ryv-eligibility-tool' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'ryv-eligibility-tool' ),
				'label_off'    => __( 'Hide', 'ryv-eligibility-tool' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'A dark wash near the caption text so it stays legible over a bright photo. Turn off if the photo is already dark enough on its own.', 'ryv-eligibility-tool' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style: eyebrow / heading / all-stories link / divider.
	 */
	private function register_header_style_controls() {
		$this->start_controls_section(
			'header_style_section',
			array(
				'label' => __( 'Header', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'eyebrow_color',
			array(
				'label'     => __( 'Eyebrow Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#DC2626',
				'condition' => array( 'show_eyebrow' => 'yes' ),
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-eyebrow' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'      => 'eyebrow_typography',
				'selector'  => '{{WRAPPER}} .ryv-stories-widget-eyebrow',
				'condition' => array( 'show_eyebrow' => 'yes' ),
			)
		);

		$this->add_control(
			'heading_color',
			array(
				'label'     => __( 'Heading Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#0F172A',
				'separator' => 'before',
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-heading' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'heading_typography',
				'selector' => '{{WRAPPER}} .ryv-stories-widget-heading',
			)
		);

		$this->add_control(
			'all_link_color',
			array(
				'label'     => __( 'All Stories Link Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#0F172A',
				'separator' => 'before',
				'condition' => array( 'show_all_link' => 'yes' ),
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-all-link' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'all_link_hover_color',
			array(
				'label'     => __( 'All Stories Link Hover Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#DC2626',
				'condition' => array( 'show_all_link' => 'yes' ),
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-all-link:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'      => 'all_link_typography',
				'selector'  => '{{WRAPPER}} .ryv-stories-widget-all-link',
				'condition' => array( 'show_all_link' => 'yes' ),
			)
		);

		$this->add_control(
			'divider_color',
			array(
				'label'     => __( 'Divider Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#E5E7EB',
				'separator' => 'before',
				'condition' => array( 'show_divider' => 'yes' ),
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-divider' => '--ryv-stories-divider-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'divider_width',
			array(
				'label'      => __( 'Divider Thickness', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 8,
					),
				),
				'condition'  => array( 'show_divider' => 'yes' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-divider' => '--ryv-stories-divider-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'header_spacing',
			array(
				'label'      => __( 'Spacing Below Header', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 80,
					),
				),
				'separator'  => 'before',
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-intro' => '--ryv-stories-intro-spacing: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style: the large featured-video panel - image/overlay/play button and
	 * its own caption text.
	 */
	private function register_featured_style_controls() {
		$this->start_controls_section(
			'featured_style_section',
			array(
				'label' => __( 'Featured Video', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'featured_bg_color',
			array(
				'label'     => __( 'Panel Background Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#0F172A',
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-video' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'featured_border_radius',
			array(
				'label'      => __( 'Border Radius', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-video' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;',
				),
			)
		);

		// Responsive (not a plain control): the widest ratios crop a video
		// far too short once the column narrows on a phone, so each
		// breakpoint gets to pick its own. The desktop value keeps the base
		// `featured_aspect_ratio` id it already had, so placements saved
		// before this was responsive carry their ratio over untouched.
		$this->add_responsive_control(
			'featured_aspect_ratio',
			array(
				'label'   => __( 'Aspect Ratio', 'ryv-eligibility-tool' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'1092/520' => '1092:520',
					'16/9'     => '16:9',
					'4/3'      => '4:3',
					'1/1'      => '1:1',
					'3/4'      => '3:4 (Portrait)',
				),
				'default' => '16/9',
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-video' => '--ryv-stories-video-ratio: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'diagonal_heading',
			array(
				'label'     => __( 'Diagonal Split', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => array( 'featured_layout' => 'diagonal' ),
			)
		);

		$this->add_control(
			'photo_width',
			array(
				'label'      => __( 'Photo Width', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( '%' ),
				'range'      => array(
					'%' => array(
						'min' => 40,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => '%',
					'size' => 78,
				),
				'condition'  => array( 'featured_layout' => 'diagonal' ),
				'description' => __( 'How much of the panel the photo covers, anchored to the right edge - the rest stays the plain panel background as the caption column.', 'ryv-eligibility-tool' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-video' => '--ryv-stories-photo-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'cut_angle',
			array(
				'label'      => __( 'Diagonal Cut Angle', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( '%' ),
				'range'      => array(
					'%' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'default'    => array(
					'unit' => '%',
					'size' => 14,
				),
				'condition'  => array( 'featured_layout' => 'diagonal' ),
				'description' => __( '0% is a straight vertical edge; higher values slant the cut further.', 'ryv-eligibility-tool' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-video' => '--ryv-stories-cut-angle: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'caption_width',
			array(
				'label'      => __( 'Caption Column Width', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( '%' ),
				'range'      => array(
					'%' => array(
						'min' => 20,
						'max' => 60,
					),
				),
				'default'    => array(
					'unit' => '%',
					'size' => 40,
				),
				'condition'  => array( 'featured_layout' => 'diagonal' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-video' => '--ryv-stories-caption-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'overlay_heading',
			array(
				'label'     => __( 'Photo Overlay', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => array( 'show_video_overlay' => 'yes' ),
			)
		);

		$this->add_control(
			'overlay_color',
			array(
				'label'     => __( 'Overlay Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'rgba(15, 23, 42, 0.6)',
				'condition' => array( 'show_video_overlay' => 'yes' ),
				'description' => __( 'Use a color with transparency (drag the alpha slider in the color picker) - a fully opaque color would hide the photo entirely.', 'ryv-eligibility-tool' ),
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-video' => '--ryv-stories-overlay-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'play_button_heading',
			array(
				'label'     => __( 'Play Button', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'play_button_color',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#DC2626',
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-play' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'play_button_icon_color',
			array(
				'label'     => __( 'Icon Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-play' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'play_button_size',
			array(
				'label'      => __( 'Size', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 32,
						'max' => 120,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-play' => '--ryv-stories-play-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'play_button_x',
			array(
				'label'      => __( 'Horizontal Position', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 400,
					),
					'%' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 80,
				),
				'condition'  => array( 'featured_layout' => 'diagonal' ),
				'description' => __( 'Only applies to the Diagonal Split layout - the Full-Bleed layout always centers the play button.', 'ryv-eligibility-tool' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-video' => '--ryv-stories-play-x: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'play_button_y',
			array(
				'label'      => __( 'Vertical Position', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 400,
					),
					'%' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'condition'  => array( 'featured_layout' => 'diagonal' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-video' => '--ryv-stories-play-y: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'caption_heading',
			array(
				'label'     => __( 'Caption Text', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'caption_top',
			array(
				'label'      => __( 'Position From Top', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 300,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 90,
				),
				'condition'  => array( 'featured_layout' => 'diagonal' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-video' => '--ryv-stories-caption-top: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'caption_left',
			array(
				'label'      => __( 'Position From Left', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 300,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 80,
				),
				'condition'  => array( 'featured_layout' => 'diagonal' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-video' => '--ryv-stories-caption-left: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'caption_padding',
			array(
				'label'      => __( 'Padding', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'default'    => array(
					'top'    => '24',
					'right'  => '16',
					'bottom' => '24',
					'left'   => '28',
					'unit'   => 'px',
				),
				'condition'  => array( 'featured_layout' => 'bottom' ),
				'description' => __( 'The Diagonal Split layout positions the caption with Position From Top/Left above instead of padding.', 'ryv-eligibility-tool' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-caption' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'caption_label_color',
			array(
				'label'     => __( '"Featured Video Story" Label Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-caption-label' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'caption_name_color',
			array(
				'label'     => __( 'Name Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-caption-name' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'caption_name_typography',
				'selector' => '{{WRAPPER}} .ryv-stories-widget-caption-name',
			)
		);

		$this->add_control(
			'caption_meta_color',
			array(
				'label'     => __( 'Duration / Captioned Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#CBD5E1',
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-caption-meta' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style: the list of story rows on the right.
	 */
	private function register_list_style_controls() {
		$this->start_controls_section(
			'list_style_section',
			array(
				'label' => __( 'Story List', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'list_item_border_color',
			array(
				'label'     => __( 'Row Divider Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#E5E7EB',
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-list' => '--ryv-stories-item-border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'list_item_hover_bg',
			array(
				'label'     => __( 'Row Hover Background', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#F8FAFC',
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-item:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'list_item_padding',
			array(
				'label'      => __( 'Row Vertical Padding', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-list' => '--ryv-stories-item-padding-y: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'list_item_padding_x',
			array(
				'label'      => __( 'Row Horizontal Padding', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-list' => '--ryv-stories-item-padding-x: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'list_item_gap',
			array(
				'label'      => __( 'Gap Between Avatar & Text', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 32,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-list' => '--ryv-stories-item-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'row_radius',
			array(
				'label'      => __( 'Row Corner Rounding (Hover Box)', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 24,
					),
				),
				'description' => __( 'Corner rounding of the row\'s own hover-background box.', 'ryv-eligibility-tool' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-item' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'avatar_heading',
			array(
				'label'     => __( 'Avatar', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'avatar_size',
			array(
				'label'      => __( 'Size', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 24,
						'max' => 96,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-item-avatar' => '--ryv-stories-avatar-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'avatar_bg_color',
			array(
				'label'     => __( 'Background Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#DBEAFE',
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-item-avatar' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'avatar_icon_color',
			array(
				'label'     => __( 'Icon Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#1D4ED8',
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-item-avatar' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'avatar_border_radius',
			array(
				'label'      => __( 'Corner Rounding', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 48,
					),
					'%'  => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'default'    => array(
					'unit' => '%',
					'size' => 50,
				),
				'description' => __( '50% is a full circle; 0% is a square avatar.', 'ryv-eligibility-tool' ),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-item-avatar' => '--ryv-stories-avatar-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'text_heading',
			array(
				'label'     => __( 'Text', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'item_meta_color',
			array(
				'label'     => __( 'Duration / Location Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#1D4E7A',
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-item-meta' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'item_name_color',
			array(
				'label'     => __( 'Name Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#0F172A',
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-item-name' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'item_name_typography',
				'selector' => '{{WRAPPER}} .ryv-stories-widget-item-name',
			)
		);

		$this->add_control(
			'item_quote_color',
			array(
				'label'     => __( 'Quote Color', 'ryv-eligibility-tool' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#334155',
				'selectors' => array(
					'{{WRAPPER}} .ryv-stories-widget-item-quote' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'item_quote_typography',
				'selector' => '{{WRAPPER}} .ryv-stories-widget-item-quote',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style: the two-column layout itself (featured video vs. list widths).
	 */
	private function register_layout_style_controls() {
		$this->start_controls_section(
			'layout_style_section',
			array(
				'label' => __( 'Layout', 'ryv-eligibility-tool' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'body_gap',
			array(
				'label'      => __( 'Gap Between Video & List', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-body' => '--ryv-stories-body-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'list_width',
			array(
				'label'      => __( 'List Column Width', 'ryv-eligibility-tool' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( '%' ),
				'range'      => array(
					'%' => array(
						'min' => 20,
						'max' => 60,
					),
				),
				'default'    => array(
					'unit' => '%',
					'size' => 34,
				),
				'selectors'  => array(
					'{{WRAPPER}} .ryv-stories-widget-list-col' => '--ryv-stories-list-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Frontend (and Elementor editor preview) render.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		if ( ! class_exists( 'RYV_Stories_CPT' ) ) {
			return;
		}

		list( $featured, $list ) = $this->get_stories( $settings );

		if ( ! $featured && empty( $list ) ) {
			if ( $this->is_edit_mode() ) {
				echo '<div class="ryv-stories-widget-placeholder">';
				esc_html_e( 'No stories yet. Add some under RYV Stories in the admin menu.', 'ryv-eligibility-tool' );
				echo '</div>';
			}
			return;
		}

		echo '<div class="ryv-stories-widget">';

		$this->render_intro( $settings );

		echo '<div class="ryv-stories-widget-body">';

		if ( $featured ) {
			$this->render_featured( $featured, $settings );
		}

		if ( ! empty( $list ) ) {
			echo '<div class="ryv-stories-widget-list-col">';
			echo '<ul class="ryv-stories-widget-list">';
			foreach ( $list as $story ) {
				$this->render_list_item( $story, $settings );
			}
			echo '</ul>';
			echo '</div>';
		}

		echo '</div>'; // .ryv-stories-widget-body

		echo '</div>'; // .ryv-stories-widget

		$this->enqueue_frontend_script( $settings );
	}

	/**
	 * Resolves the featured story plus the list stories to display, honoring
	 * the widget's own count/order controls.
	 *
	 * @param array $settings Widget settings.
	 * @return array{0:?WP_Post,1:WP_Post[]}
	 */
	private function get_stories( array $settings ): array {
		$featured_query = new WP_Query(
			array(
				'post_type'        => RYV_Stories_CPT::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'meta_key'         => RYV_Stories_CPT::META_FEATURED,
				'meta_value'       => '1',
				'orderby'          => 'menu_order date',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		$featured = $featured_query->have_posts() ? $featured_query->posts[0] : null;

		$order_by = in_array( $settings['order_by'] ?? '', array( 'menu_order', 'date', 'title' ), true ) ? $settings['order_by'] : 'menu_order';
		$order    = 'DESC' === ( $settings['order'] ?? '' ) ? 'DESC' : 'ASC';
		$count    = max( 1, absint( $settings['list_count'] ?? 3 ) );

		$exclude = array();

		if ( ! $featured ) {
			// No story is flagged featured: fall back to the first story by
			// the same manual order, and keep it out of the list below it.
			$fallback_query = new WP_Query(
				array(
					'post_type'        => RYV_Stories_CPT::POST_TYPE,
					'post_status'      => 'publish',
					'posts_per_page'   => 1,
					'orderby'          => 'menu_order date',
					'order'            => 'ASC',
					'suppress_filters' => false,
				)
			);
			$featured = $fallback_query->have_posts() ? $fallback_query->posts[0] : null;
		}

		if ( $featured ) {
			$exclude[] = $featured->ID;
		}

		$list_query = new WP_Query(
			array(
				'post_type'        => RYV_Stories_CPT::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => $count,
				'post__not_in'     => $exclude,
				'orderby'          => $order_by,
				'order'            => $order,
				'suppress_filters' => false,
			)
		);

		return array( $featured, $list_query->posts );
	}

	/**
	 * @param array $settings Widget settings.
	 */
	private function render_intro( array $settings ) {
		$show_eyebrow  = 'yes' === ( $settings['show_eyebrow'] ?? '' ) && '' !== trim( (string) ( $settings['eyebrow_text'] ?? '' ) );
		$heading_text  = trim( (string) ( $settings['heading_text'] ?? '' ) );
		$show_all_link = 'yes' === ( $settings['show_all_link'] ?? '' ) && '' !== trim( (string) ( $settings['all_link_text'] ?? '' ) );
		$show_divider  = 'yes' === ( $settings['show_divider'] ?? '' );

		echo '<div class="ryv-stories-widget-intro">';

		if ( $heading_text || $show_all_link ) {
			echo '<div class="ryv-stories-widget-heading-row">';
			echo '<div>';
			if ( $show_eyebrow ) {
				printf( '<div class="ryv-stories-widget-eyebrow">%s</div>', esc_html( $settings['eyebrow_text'] ) );
			}
			if ( $heading_text ) {
				printf( '<h2 class="ryv-stories-widget-heading">%s</h2>', esc_html( $heading_text ) );
			}
			echo '</div>';

			if ( $show_all_link ) {
				$url = $settings['all_link_url']['url'] ?? '';
				printf(
					'<a class="ryv-stories-widget-all-link" href="%1$s"%2$s>%3$s <span aria-hidden="true">&rarr;</span></a>',
					esc_url( $url ?: '#' ),
					! empty( $settings['all_link_url']['is_external'] ) ? ' target="_blank" rel="' . esc_attr( ! empty( $settings['all_link_url']['nofollow'] ) ? 'noopener nofollow' : 'noopener' ) . '"' : '',
					esc_html( $settings['all_link_text'] )
				);
			}
			echo '</div>';
		}

		if ( $show_divider ) {
			echo '<hr class="ryv-stories-widget-divider" />';
		}

		echo '</div>';
	}

	/**
	 * @param WP_Post $story    The featured story post.
	 * @param array   $settings Widget settings.
	 */
	private function render_featured( \WP_Post $story, array $settings ) {
		$youtube_url = get_post_meta( $story->ID, RYV_Stories_CPT::META_YOUTUBE_URL, true );
		$video_id    = self::get_youtube_id( $youtube_url );
		$duration    = get_post_meta( $story->ID, RYV_Stories_CPT::META_DURATION, true );
		$captioned   = (bool) get_post_meta( $story->ID, RYV_Stories_CPT::META_CAPTIONED, true );
		$autoplay    = 'yes' === ( $settings['autoplay_on_scroll'] ?? '' );

		$captioned_text = trim( (string) ( $settings['captioned_text'] ?? '' ) );
		$meta_parts     = array_filter( array( $duration, $captioned ? $captioned_text : '' ) );

		if ( has_post_thumbnail( $story ) ) {
			$thumb_url = get_the_post_thumbnail_url( $story, 'large' );
		} elseif ( $video_id ) {
			$thumb_url = sprintf( 'https://img.youtube.com/vi/%s/hqdefault.jpg', $video_id );
		} else {
			$thumb_url = '';
		}

		$layout      = 'bottom' === ( $settings['featured_layout'] ?? '' ) ? 'bottom' : 'diagonal';
		$show_overlay = 'yes' === ( $settings['show_video_overlay'] ?? 'yes' );

		echo '<div class="ryv-stories-widget-featured">';
		printf(
			'<div class="ryv-stories-widget-video ryv-stories-widget-video--%1$s"%2$s>',
			esc_attr( $layout ),
			$video_id ? sprintf( ' data-youtube-id="%1$s" data-autoplay="%2$s"', esc_attr( $video_id ), $autoplay ? '1' : '0' ) : ''
		);

		if ( $thumb_url ) {
			printf( '<img class="ryv-stories-widget-video-thumb" src="%1$s" alt="" loading="lazy" />', esc_url( $thumb_url ) );
		}

		if ( $show_overlay ) {
			echo '<div class="ryv-stories-widget-video-overlay"></div>';
		}

		echo '<div class="ryv-stories-widget-caption">';
		$featured_label = trim( (string) ( $settings['featured_label_text'] ?? '' ) );
		if ( '' !== $featured_label ) {
			printf( '<div class="ryv-stories-widget-caption-label">%s</div>', esc_html( $featured_label ) );
		}
		printf( '<div class="ryv-stories-widget-caption-name">%s</div>', esc_html( get_the_title( $story ) ) );
		if ( $meta_parts ) {
			printf( '<div class="ryv-stories-widget-caption-meta">%s</div>', esc_html( implode( ' · ', $meta_parts ) ) );
		}
		echo '</div>';

		if ( $youtube_url ) {
			// href/target stay exactly as they were even in lightbox mode:
			// Elementor's handler calls preventDefault() on the click, so
			// they only ever come into play as the fallback when its script
			// hasn't loaded (or a visitor opens the link in a new tab
			// deliberately).
			$lightbox_attrs = $this->get_lightbox_attributes( $youtube_url, 'featured-' . $this->get_id(), $settings );

			printf(
				'<a class="ryv-stories-widget-play" href="%1$s" target="_blank" rel="noopener noreferrer" aria-label="%2$s"%4$s>%3$s</a>',
				esc_url( $youtube_url ),
				esc_attr__( 'Watch this story', 'ryv-eligibility-tool' ),
				'<svg viewBox="0 0 24 24" width="1em" height="1em" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>',
				$lightbox_attrs // Escaped per-attribute in get_lightbox_attributes().
			);
		}

		echo '</div>'; // .ryv-stories-widget-video
		echo '</div>'; // .ryv-stories-widget-featured
	}

	/**
	 * @param WP_Post $story    A list-row story post.
	 * @param array   $settings Widget settings.
	 */
	private function render_list_item( \WP_Post $story, array $settings = array() ) {
		$youtube_url = get_post_meta( $story->ID, RYV_Stories_CPT::META_YOUTUBE_URL, true );
		$duration    = get_post_meta( $story->ID, RYV_Stories_CPT::META_DURATION, true );
		$location    = get_post_meta( $story->ID, RYV_Stories_CPT::META_LOCATION, true );
		$quote       = get_post_meta( $story->ID, RYV_Stories_CPT::META_QUOTE, true );
		$meta_parts  = array_filter( array( $duration, $location ) );

		echo '<li class="ryv-stories-widget-item-wrap">';
		printf(
			'<a class="ryv-stories-widget-item" href="%1$s"%2$s%3$s>',
			esc_url( $youtube_url ?: '#' ),
			$youtube_url ? ' target="_blank" rel="noopener noreferrer"' : '',
			// Each row opens in its own modal id - a shared one would have
			// Elementor reuse the first row's already-built modal for every
			// subsequent story.
			$this->get_lightbox_attributes( (string) $youtube_url, 'item-' . $story->ID, $settings ) // Escaped per-attribute there.
		);

		echo '<span class="ryv-stories-widget-item-avatar">';
		if ( has_post_thumbnail( $story ) ) {
			echo get_the_post_thumbnail( $story, 'thumbnail', array( 'loading' => 'lazy' ) );
		} else {
			// Placeholder for a story with no photo: a shoulders-up
			// silhouette filling the whole avatar, not a small glyph
			// centered in it.
			//
			// Deliberately just the two body shapes - the source artwork's
			// own rounded-rect backplate and its `clipPath` are both
			// dropped, because the container already paints the background
			// and clips to `--ryv-stories-avatar-radius` (an SVG also clips
			// to its own viewBox, so the ellipse still crops the same way).
			// That keeps the "Border Radius" control honest - a square
			// avatar setting would otherwise still show a circle inside it -
			// and avoids repeating one `clipPath` id on every row that has
			// no photo, which duplicate ids in the document would be.
			//
			// `currentColor` so the existing "Icon Color" control (which
			// writes `color` on the container) still drives it.
			echo '<svg class="ryv-stories-widget-item-avatar-placeholder" viewBox="0 0 72 72" fill="currentColor" aria-hidden="true" focusable="false"><circle cx="36.4" cy="27" r="13"/><ellipse cx="36.4" cy="66" rx="26" ry="20"/></svg>';
		}
		echo '</span>';

		echo '<span class="ryv-stories-widget-item-content">';
		if ( $meta_parts ) {
			printf( '<span class="ryv-stories-widget-item-meta">%s</span>', esc_html( implode( ' · ', $meta_parts ) ) );
		}
		printf( '<span class="ryv-stories-widget-item-name">%s</span>', esc_html( get_the_title( $story ) ) );
		if ( $quote ) {
			printf( '<span class="ryv-stories-widget-item-quote">&ldquo;%s&rdquo;</span>', esc_html( $quote ) );
		}
		echo '</span>';

		echo '</a>';
		echo '</li>';
	}

	/**
	 * The `data-*` attributes that make a link open in Elementor's own video
	 * lightbox, ready to print inside a tag - or '' when the widget is set to
	 * "New Tab" or the URL isn't a video Elementor can embed.
	 *
	 * Three attributes are needed, and each for its own reason (all verified
	 * against elementor/assets/js, not assumed):
	 * - `data-elementor-open-lightbox="yes"` - LightboxManager's
	 *   `isLightboxLink()` opt-in.
	 * - `data-elementor-lightbox-video` - that same check *rejects* an
	 *   `<a>` whose href isn't an image file unless this is present.
	 *   Elementor's Video widget never hits this because it hangs the
	 *   lightbox off a `<div>` overlay; here the trigger is a real link (so
	 *   it still works with JS blocked, and stays keyboard-focusable), which
	 *   makes this attribute load-bearing rather than decorative.
	 * - `data-elementor-lightbox` - the JSON payload `createLightbox()`
	 *   passes straight to `showModal()` when `type` is set, which is what
	 *   actually opens a video rather than an image modal.
	 *
	 * The URL inside the payload has to be the *embed* URL, not the watch
	 * URL, so it's built by Elementor's own `Embed::get_embed_url()` rather
	 * than hand-assembled - that also means Vimeo works here for free, even
	 * though the story meta field is labelled YouTube.
	 *
	 * @param string $video_url Raw video URL from post meta.
	 * @param string $modal_id  Unique-per-element id for the modal.
	 * @param array  $settings  Widget settings.
	 * @return string Attribute string with a leading space, or ''.
	 */
	private function get_lightbox_attributes( string $video_url, string $modal_id, array $settings ): string {
		if ( 'lightbox' !== ( $settings['video_click_action'] ?? 'lightbox' ) || '' === trim( $video_url ) ) {
			return '';
		}

		if ( ! class_exists( '\Elementor\Embed' ) ) {
			return '';
		}

		$embed_url = \Elementor\Embed::get_embed_url( $video_url, array( 'autoplay' => 1 ), array() );

		if ( ! $embed_url ) {
			return '';
		}

		$properties  = \Elementor\Embed::get_video_properties( $video_url );
		$video_type  = $properties['provider'] ?? 'youtube';
		$ratio       = $settings['lightbox_aspect_ratio'] ?? '169';

		$options = array(
			'type'         => 'video',
			'videoType'    => $video_type,
			'url'          => $embed_url,
			'autoplay'     => 'yes',
			'modalOptions' => array(
				'id'               => 'elementor-lightbox-ryv-' . $modal_id,
				'videoAspectRatio' => $ratio,
			),
		);

		return sprintf(
			' data-elementor-open-lightbox="yes" data-elementor-lightbox-video="%1$s" data-elementor-lightbox="%2$s"',
			esc_url( $embed_url ),
			esc_attr( wp_json_encode( $options ) )
		);
	}

	/**
	 * Parses a YouTube video ID out of any of the common URL shapes an admin
	 * might paste (watch?v=, youtu.be/, embed/, shorts/) - returns '' if the
	 * URL isn't recognized as YouTube rather than guessing.
	 *
	 * @param string $url Raw YouTube URL from post meta.
	 * @return string
	 */
	public static function get_youtube_id( string $url ): string {
		if ( '' === trim( $url ) ) {
			return '';
		}

		if ( preg_match( '#(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?v=|embed/|shorts/|v/))([A-Za-z0-9_-]{6,})#', $url, $m ) ) {
			return $m[1];
		}

		return '';
	}

	/**
	 * Loads the scroll-triggered muted-autoplay behavior for the featured
	 * video. Only enqueued when there's an actual video id to observe, so a
	 * page with no valid YouTube URL yet never pays for the script.
	 *
	 * @param array $settings Widget settings.
	 */
	private function enqueue_frontend_script( array $settings ) {
		if ( 'yes' !== ( $settings['autoplay_on_scroll'] ?? '' ) ) {
			return;
		}

		wp_enqueue_script(
			'ryv-stories-widget',
			RYV_PLUGIN_URL . 'assets/js/stories-widget.js',
			array(),
			RYV_VERSION,
			true
		);
	}

	/**
	 * @return bool
	 */
	private function is_edit_mode(): bool {
		return \Elementor\Plugin::$instance->editor->is_edit_mode();
	}
}
