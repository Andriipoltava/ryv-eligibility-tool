<?php
/**
 * "Generate States" admin submenu page: creates a `ryv_states` post for
 * every state option in a chosen form's picker node that doesn't already
 * have one - see RYV_States_Generator for the actual generation logic.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_States_Generate_Page
 */
class RYV_States_Generate_Page {

	/**
	 * Capability required to generate states.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Nonce action/name.
	 */
	const NONCE_ACTION = 'ryv_generate_states';

	/**
	 * Nonce action/name for the "Sync Category Colors to Map" action.
	 */
	const SYNC_NONCE_ACTION = 'ryv_sync_map_categories';

	/**
	 * Nonce action/name for the "Auto-Populate Map Categories" action.
	 */
	const AUTOPOP_NONCE_ACTION = 'ryv_autopopulate_map_categories';

	/**
	 * Nonce action for the "Repair Imported Events" button.
	 */
	const EVENTS_NONCE_ACTION = 'ryv_repair_events';

	/**
	 * Nonce action for the "Repair State Post Template" button.
	 */
	const TEMPLATE_NONCE_ACTION = 'ryv_repair_state_template';

	/**
	 * Transient key prefix for post/redirect/get result passing.
	 */
	const RESULT_TRANSIENT_PREFIX = 'ryv_generate_states_result_';

	/**
	 * Transient key prefix for the map-sync action's result.
	 */
	const SYNC_RESULT_TRANSIENT_PREFIX = 'ryv_sync_map_result_';

	/**
	 * Transient key prefix for the auto-populate action's result.
	 */
	const AUTOPOP_RESULT_TRANSIENT_PREFIX = 'ryv_autopop_map_result_';

	/**
	 * Per-user transient prefix for the events-repair result.
	 */
	const EVENTS_RESULT_TRANSIENT_PREFIX = 'ryv_repair_events_result_';

	/**
	 * Per-user transient prefix for the template-repair result.
	 */
	const TEMPLATE_RESULT_TRANSIENT_PREFIX = 'ryv_repair_template_result_';

	/**
	 * Hook slug for this submenu page.
	 */
	const PAGE_SLUG = 'ryv-generate-states';

	/**
	 * Constructor.
	 */
	public function __construct() {
		RYV_States_Tools_Page::add_tab( self::PAGE_SLUG, __( 'Generate States', 'ryv-eligibility-tool' ), array( $this, 'render_page' ) );
		add_action( 'admin_init', array( $this, 'handle_submission' ) );
		add_action( 'admin_init', array( $this, 'handle_sync_submission' ) );
		add_action( 'admin_init', array( $this, 'handle_autopopulate_submission' ) );
		add_action( 'admin_init', array( $this, 'handle_events_repair_submission' ) );
		add_action( 'admin_init', array( $this, 'handle_template_repair_submission' ) );
	}

	/**
	 * Handle the form submission (Post/Redirect/Get, same pattern as
	 * RYV_Import_Page).
	 */
	public function handle_submission() {
		if ( ! isset( $_POST['ryv_generate_states_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to generate states.', 'ryv-eligibility-tool' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'ryv_generate_states_nonce' );

		$form_id = absint( $_POST['ryv_generate_form_id'] ?? 0 );
		$post    = $form_id ? get_post( $form_id ) : null;

		if ( ! $post || RYV_CPT::POST_TYPE !== $post->post_type ) {
			$this->store_result(
				array(
					'success' => false,
					'message' => __( 'Please choose a valid form.', 'ryv-eligibility-tool' ),
				)
			);
		} else {
			$status = ( 'publish' === ( $_POST['ryv_generate_status'] ?? '' ) ) ? 'publish' : 'draft';
			$result = RYV_States_Generator::generate( $form_id, $status );

			$this->store_result(
				array(
					'success' => true,
					'form_id' => $form_id,
					'created' => $result['created'],
					'skipped' => $result['skipped'],
					'errors'  => $result['errors'],
				)
			);
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . RYV_States_CPT::POST_TYPE . '&page=' . RYV_States_Tools_Page::PAGE_SLUG . '&tab=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Handle the "Sync Category Colors to Map" button: pushes every
	 * published state's ryv_map_category/tooltip/mixed_rules fields into
	 * the MapSVG regions table via RYV_MapSVG_Sync::sync_all().
	 *
	 * Needed as a manual trigger for any state whose fields were set
	 * without going through a normal wp-admin Save (RYV_MapSVG_Sync's own
	 * `acf/save_post` hook already keeps the map in sync automatically for
	 * every future edit made in the States screen itself).
	 */
	public function handle_sync_submission() {
		if ( ! isset( $_POST['ryv_sync_map_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to sync map data.', 'ryv-eligibility-tool' ) );
		}

		check_admin_referer( self::SYNC_NONCE_ACTION, 'ryv_sync_map_nonce' );

		if ( ! class_exists( 'RYV_MapSVG_Sync' ) ) {
			set_transient(
				self::SYNC_RESULT_TRANSIENT_PREFIX . get_current_user_id(),
				array(
					'success' => false,
					'message' => __( 'RYV_MapSVG_Sync is not available (ACF/MapSVG not active?).', 'ryv-eligibility-tool' ),
				),
				MINUTE_IN_SECONDS * 5
			);
		} else {
			$count = RYV_MapSVG_Sync::sync_all();

			set_transient(
				self::SYNC_RESULT_TRANSIENT_PREFIX . get_current_user_id(),
				array(
					'success' => true,
					'count'   => $count,
				),
				MINUTE_IN_SECONDS * 5
			);
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . RYV_States_CPT::POST_TYPE . '&page=' . RYV_States_Tools_Page::PAGE_SLUG . '&tab=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Handle the "Auto-Populate Map Categories" button: force-reconciles
	 * the options page's 8 canonical categories (fixing any wrong
	 * color/label), (re-)assigns every CLC-reference-matched state's
	 * category, and pushes the result to MapSVG - the same self-healing
	 * logic RYV_States_ACF::maybe_seed_map_defaults() runs automatically
	 * once, exposed here as a repeatable manual action (e.g. to re-apply
	 * after intentionally editing a state's category back to "wrong" for
	 * testing, or after a future reference-table change before its version
	 * bump ships).
	 */
	public function handle_autopopulate_submission() {
		if ( ! isset( $_POST['ryv_autopopulate_map_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to modify map data.', 'ryv-eligibility-tool' ) );
		}

		check_admin_referer( self::AUTOPOP_NONCE_ACTION, 'ryv_autopopulate_map_nonce' );

		if ( ! class_exists( 'RYV_States_ACF' ) || ! class_exists( 'RYV_States_Generator' ) ) {
			set_transient(
				self::AUTOPOP_RESULT_TRANSIENT_PREFIX . get_current_user_id(),
				array(
					'success' => false,
					'message' => __( 'RYV_States_ACF/RYV_States_Generator is not available (ACF not active?).', 'ryv-eligibility-tool' ),
				),
				MINUTE_IN_SECONDS * 5
			);
		} else {
			// force=true: also re-assigns any state that already had a
			// (possibly manually-overridden) category, not just uncategorized
			// ones - this button is the explicit "reset to CLC defaults"
			// action, unlike the automatic admin_init hook which only ever
			// fills in what's missing.
			RYV_States_ACF::maybe_seed_map_defaults( true );

			set_transient(
				self::AUTOPOP_RESULT_TRANSIENT_PREFIX . get_current_user_id(),
				array( 'success' => true ),
				MINUTE_IN_SECONDS * 5
			);
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . RYV_States_CPT::POST_TYPE . '&page=' . RYV_States_Tools_Page::PAGE_SLUG . '&tab=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Pop (read + delete) this user's pending auto-populate result, if any.
	 *
	 * @return array|null
	 */
	private function consume_autopopulate_result(): ?array {
		$key    = self::AUTOPOP_RESULT_TRANSIENT_PREFIX . get_current_user_id();
		$result = get_transient( $key );

		if ( false === $result ) {
			return null;
		}

		delete_transient( $key );
		return $result;
	}

	/**
	 * Pop (read + delete) this user's pending map-sync result, if any.
	 *
	 * @return array|null
	 */
	private function consume_sync_result(): ?array {
		$key    = self::SYNC_RESULT_TRANSIENT_PREFIX . get_current_user_id();
		$result = get_transient( $key );

		if ( false === $result ) {
			return null;
		}

		delete_transient( $key );
		return $result;
	}

	/**
	 * Stash the outcome in a short-lived, per-user transient so it survives
	 * the Post/Redirect/Get round trip.
	 *
	 * @param array $data Result payload.
	 */
	private function store_result( array $data ) {
		set_transient( self::RESULT_TRANSIENT_PREFIX . get_current_user_id(), $data, MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * Pop (read + delete) this user's pending result, if any.
	 *
	 * @return array|null
	 */
	private function consume_result(): ?array {
		$key    = self::RESULT_TRANSIENT_PREFIX . get_current_user_id();
		$result = get_transient( $key );

		if ( false === $result ) {
			return null;
		}

		delete_transient( $key );
		return $result;
	}

	/**
	 * Render the submenu page: result notice (if any) + form picker.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'ryv-eligibility-tool' ) );
		}

		$result         = $this->consume_result();
		$sync_result    = $this->consume_sync_result();
		$autopop_result = $this->consume_autopopulate_result();
		$forms          = get_posts(
			array(
				'post_type'      => RYV_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		require RYV_PLUGIN_DIR . 'admin/views/states-generate-page.php';
	}

	/**
	 * "Repair Imported Events": hands every `tribe_events` post to
	 * RYV_Events_Repair, which recomputes the UTC/timezone meta an import
	 * left inconsistent and asks The Events Calendar to rebuild its own
	 * custom tables from it.
	 *
	 * Lives on this page rather than one of its own because it is the same
	 * kind of one-button data maintenance as the two above, and because the
	 * only thing on this site that reads events is a state page.
	 */
	public function handle_events_repair_submission() {
		if ( ! isset( $_POST['ryv_repair_events_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to modify event data.', 'ryv-eligibility-tool' ) );
		}

		check_admin_referer( self::EVENTS_NONCE_ACTION, 'ryv_repair_events_nonce' );

		$result = RYV_Events_Repair::repair_all();

		set_transient(
			self::EVENTS_RESULT_TRANSIENT_PREFIX . get_current_user_id(),
			$result,
			MINUTE_IN_SECONDS * 5
		);

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . RYV_States_CPT::POST_TYPE . '&page=' . RYV_States_Tools_Page::PAGE_SLUG . '&tab=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Pop (read + delete) this user's pending events-repair result, if any.
	 *
	 * @return array|null
	 */
	public function consume_events_repair_result(): ?array {
		$key    = self::EVENTS_RESULT_TRANSIENT_PREFIX . get_current_user_id();
		$result = get_transient( $key );

		if ( false === $result ) {
			return null;
		}

		delete_transient( $key );

		return $result;
	}

	/**
	 * "Repair State Post Template": hands every Elementor template to
	 * RYV_States_Template_Repair, which strips the Display Conditions that
	 * blank state pages and applies this plugin's own hide-empty markers
	 * instead.
	 *
	 * Exists because those are *template data*, not code - so unlike
	 * everything else in this plugin they don't travel with a deploy, and
	 * would otherwise have to be re-authored by hand in the editor on every
	 * environment.
	 */
	public function handle_template_repair_submission() {
		if ( ! isset( $_POST['ryv_repair_template_submit'] ) && ! isset( $_POST['ryv_preview_template_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to modify templates.', 'ryv-eligibility-tool' ) );
		}

		check_admin_referer( self::TEMPLATE_NONCE_ACTION, 'ryv_repair_template_nonce' );

		// The preview button reports what would change without writing -
		// worth having for a one-click action that edits a template someone
		// may have re-authored since.
		$dry_run = isset( $_POST['ryv_preview_template_submit'] );
		$result  = RYV_States_Template_Repair::repair( $dry_run );

		$result['dry_run'] = $dry_run;

		set_transient(
			self::TEMPLATE_RESULT_TRANSIENT_PREFIX . get_current_user_id(),
			$result,
			MINUTE_IN_SECONDS * 5
		);

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . RYV_States_CPT::POST_TYPE . '&page=' . RYV_States_Tools_Page::PAGE_SLUG . '&tab=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Pop (read + delete) this user's pending template-repair result.
	 *
	 * @return array|null
	 */
	public function consume_template_repair_result(): ?array {
		$key    = self::TEMPLATE_RESULT_TRANSIENT_PREFIX . get_current_user_id();
		$result = get_transient( $key );

		if ( false === $result ) {
			return null;
		}

		delete_transient( $key );

		return $result;
	}
}
