<?php
/**
 * "Embed & Share" admin page (RYV Forms -> Embed & Share).
 *
 * Two jobs, both for the people who run the site rather than developers:
 * 1. A code generator + live preview: pick a form and a few presentation
 *    options, get a clean share link and copy-paste <iframe> code for partners
 *    (see RYV_Embed for the route itself).
 * 2. The access/security settings: embedding on/off and which websites may
 *    frame the tool (feeds the `frame-ancestors` header).
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Embed_Page
 */
class RYV_Embed_Page {

	const CAPABILITY   = 'manage_options';
	const PAGE_SLUG    = 'ryv-embed';
	const OPTION_GROUP = 'ryv_embed_settings_group';

	/**
	 * Hook suffix returned by add_submenu_page().
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Submenu under RYV Forms.
	 */
	public function register_menu() {
		$this->hook_suffix = add_submenu_page(
			'edit.php?post_type=' . RYV_CPT::POST_TYPE,
			__( 'Embed & Share', 'ryv-eligibility-tool' ),
			__( 'Embed & Share', 'ryv-eligibility-tool' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the access settings option.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			RYV_Embed::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize the settings form. Every domain line is normalized through
	 * RYV_Embed::normalize_origin(); rejected lines are reported, not kept.
	 *
	 * @param mixed $input Raw posted value.
	 * @return array{enabled:int,mode:string,domains:string[]}
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();

		$domains  = array();
		$rejected = array();
		$lines    = preg_split( '/[\r\n,\s]+/', isset( $input['domains'] ) && is_string( $input['domains'] ) ? $input['domains'] : '' );

		foreach ( (array) $lines as $line ) {
			if ( '' === trim( (string) $line ) ) {
				continue;
			}
			$clean = RYV_Embed::normalize_origin( (string) $line );
			if ( '' === $clean ) {
				$rejected[] = sanitize_text_field( (string) $line );
			} else {
				$domains[] = $clean;
			}
		}

		if ( $rejected ) {
			add_settings_error(
				RYV_Embed::OPTION,
				'ryv_embed_bad_domains',
				sprintf(
					/* translators: %s: comma-separated list of rejected entries */
					__( 'These entries are not valid website addresses and were not saved: %s', 'ryv-eligibility-tool' ),
					implode( ', ', array_slice( $rejected, 0, 10 ) )
				),
				'warning'
			);
		}

		return array(
			'enabled' => ! empty( $input['enabled'] ) ? 1 : 0,
			'mode'    => ( isset( $input['mode'] ) && 'allowlist' === $input['mode'] ) ? 'allowlist' : 'any',
			'domains' => array_slice( array_values( array_unique( $domains ) ), 0, 100 ),
		);
	}

	/**
	 * Admin assets - this screen only.
	 *
	 * @param string $hook_suffix Current screen hook.
	 */
	public function enqueue( $hook_suffix ) {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		// The same loader partners use, so the preview auto-sizes exactly like a real embed.
		wp_enqueue_script( RYV_Embed::LOADER_HANDLE, RYV_PLUGIN_URL . 'assets/js/embed.js', array(), RYV_VERSION, true );
		wp_enqueue_script(
			'ryv-embed-admin',
			RYV_PLUGIN_URL . 'assets/js/admin/embed-page.js',
			array( 'jquery', 'wp-color-picker' ),
			RYV_VERSION,
			true
		);

		$forms = array();
		foreach ( $this->get_forms() as $form ) {
			$lang    = RYV_Form_Translations::get_language( (int) $form->ID );
			$forms[] = array(
				'states' => $this->get_states( (int) $form->ID ),
				'id'    => (int) $form->ID,
				'title' => $form->post_title,
				'desc'  => $form->post_excerpt,
				'label' => $form->post_title . ( '' !== $lang ? ' (' . strtoupper( $lang ) . ')' : '' ),
				'url'   => RYV_Embed::build_url( $form ),
			);
		}

		wp_localize_script(
			'ryv-embed-admin',
			'RYVEmbedAdmin',
			array(
				'forms'     => $forms,
				'loaderUrl' => RYV_Embed::loader_url(),
				'i18n'      => array(
					'copied' => __( 'Copied!', 'ryv-eligibility-tool' ),
					'copy'   => __( 'Copy', 'ryv-eligibility-tool' ),
					'allStates' => __( 'The state picker (all states)', 'ryv-eligibility-tool' ),
				),
			)
		);
	}

	/**
	 * The state-picker options of a form ([slug, label]), for the "single
	 * state" embed. Empty when the form has no picker or ACF's states sync
	 * (which owns the lookup the embed route uses) isn't loaded.
	 *
	 * @param int $form_id Form ID.
	 * @return array<int,array{slug:string,label:string}>
	 */
	private function get_states( int $form_id ): array {
		if ( ! class_exists( 'RYV_States_Sync' ) ) {
			return array();
		}

		$schema = RYV_CPT::get_schema( $form_id );
		$entry  = $schema['entry_node'] ?? '';
		$states = array();

		foreach ( (array) ( $schema['nodes'][ $entry ]['options'] ?? array() ) as $option ) {
			if ( empty( $option['next_node'] ) || ! isset( $option['value'] ) ) {
				continue;
			}
			$states[] = array(
				'slug'  => sanitize_title( (string) $option['value'] ),
				'label' => (string) ( $option['label'] ?? $option['value'] ),
			);
		}

		return $states;
	}

	/**
	 * Published forms, every language edition.
	 *
	 * @return WP_Post[]
	 */
	private function get_forms(): array {
		return get_posts(
			array(
				'post_type'        => RYV_CPT::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
	}

	/**
	 * Render the page.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'ryv-eligibility-tool' ) );
		}

		$settings    = RYV_Embed::get_settings();
		$has_forms   = (bool) $this->get_forms();
		$ancestors   = RYV_Embed::frame_ancestors();
		$domains_txt = implode( "\n", $settings['domains'] );

		require RYV_PLUGIN_DIR . 'admin/views/embed-page.php';
	}
}
