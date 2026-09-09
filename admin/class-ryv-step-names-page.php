<?php
/**
 * "Step Names" admin submenu page: bulk-assigns the wizard step-sidebar's
 * `step_name` field (see NodeEditor.js's "Step Name" field and
 * `engine.js`'s `updateStepSidebar()`) across an entire form's graph in one
 * pass, for the common 3-step shape every RYV questionnaire actually has -
 * "pick your state", "answer about your conviction" (a different node per
 * state, but the same conceptual step), "see your result" (also a
 * different node per branch) - rather than requiring an admin to open each
 * of a form's 400+ nodes in the builder one at a time to set the same
 * label.
 *
 * When WPML is active, picking one form auto-discovers every linked
 * translation (RYV_Translations_Page is the other half of that link) and
 * lets the admin type each language's own wording once, applying the exact
 * same structural rule to every language edition's own graph in a single
 * submit - each edition keeps its own node ids, so this is always run
 * per-edition, never by copying node ids across languages.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Step_Names_Page
 */
class RYV_Step_Names_Page {

	/**
	 * Capability required to view/change - same trust boundary as
	 * RYV_Import_Page/RYV_Translations_Page (rewrites every node's
	 * `step_name` in the target form(s)' schema).
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Hook slug for this submenu page.
	 */
	const PAGE_SLUG = 'ryv-step-names';

	/**
	 * Nonce action/name.
	 */
	const NONCE_ACTION = 'ryv_apply_step_names';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_submission' ) );
	}

	/**
	 * Parent menu slug this page's submenu link is nested under.
	 *
	 * Deliberately RYV_States_CPT's menu, not RYV_CPT's (unlike every other
	 * `ryv_forms`-related page in this file's family) - this is where an
	 * admin is already working state-by-state, and step names are typically
	 * set up once right after a state's content is finished, not from the
	 * flat "All Forms" list. The feature itself is unchanged - it still
	 * reads/writes `ryv_forms` schemas, only its menu location differs.
	 */
	const PARENT_SLUG = 'edit.php?post_type=' . RYV_States_CPT::POST_TYPE;

	/**
	 * Register the submenu page under the ryv_states CPT menu.
	 */
	public function register_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Step Names', 'ryv-eligibility-tool' ),
			__( 'Step Names', 'ryv-eligibility-tool' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Whether WPML's translation-linking API is present - same check as
	 * RYV_Translations_Page::is_available(), plus the one extra filter this
	 * page also needs to enumerate a group's members.
	 *
	 * @return bool
	 */
	private function is_wpml_available(): bool {
		return has_filter( 'wpml_element_trid' )
			&& has_filter( 'wpml_get_element_translations' )
			&& has_filter( 'wpml_post_language_details' );
	}

	/**
	 * Every language edition of $post_id's form, keyed by language code -
	 * always includes $post_id itself. Falls back to a single-entry group
	 * (keyed 'default') when WPML isn't active or this form isn't linked to
	 * any translation, so the rest of the page works identically either
	 * way instead of branching on WPML's presence everywhere else.
	 *
	 * @param int $post_id Anchor form's post ID.
	 * @return array<string, WP_Post> language_code => post.
	 */
	private function get_translation_group( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post || RYV_CPT::POST_TYPE !== $post->post_type ) {
			return array();
		}

		if ( ! $this->is_wpml_available() ) {
			return array( 'default' => $post );
		}

		$lang_details = apply_filters( 'wpml_post_language_details', null, $post_id );
		$own_code     = ! empty( $lang_details['language_code'] ) ? $lang_details['language_code'] : 'default';

		$trid = apply_filters( 'wpml_element_trid', false, $post_id, 'post_' . RYV_CPT::POST_TYPE );

		if ( ! $trid ) {
			return array( $own_code => $post );
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . RYV_CPT::POST_TYPE );
		$group        = array();

		if ( is_array( $translations ) ) {
			foreach ( $translations as $lang_code => $translation ) {
				$element_id = is_object( $translation ) && isset( $translation->element_id ) ? (int) $translation->element_id : 0;
				$member     = $element_id ? get_post( $element_id ) : null;

				if ( $member && RYV_CPT::POST_TYPE === $member->post_type ) {
					$group[ $lang_code ] = $member;
				}
			}
		}

		return ! empty( $group ) ? $group : array( $own_code => $post );
	}

	/**
	 * Handle the "apply" form submission (Post/Redirect/Get, same pattern
	 * as RYV_Import_Page::handle_submission()). Applies the rule to every
	 * language row submitted, independently, so one language's typed text
	 * (or its form having an unexpected structure) never blocks another's.
	 */
	public function handle_submission() {
		if ( ! isset( $_POST['ryv_step_names_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this form.', 'ryv-eligibility-tool' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'ryv_step_names_nonce' );

		$anchor_id             = isset( $_POST['ryv_anchor_form'] ) ? absint( wp_unslash( $_POST['ryv_anchor_form'] ) ) : 0;
		$labels                = isset( $_POST['ryv_labels'] ) && is_array( $_POST['ryv_labels'] ) ? wp_unslash( $_POST['ryv_labels'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- unslashed above; each string is sanitized individually below.
		$include_translations = isset( $_POST['ryv_include_translations'] );

		$group = $this->get_translation_group( $anchor_id );

		// Unchecking "Also apply to linked translations" restricts the whole
		// operation to just the anchor form itself, dropping every other
		// language row that get_translation_group() found - for an admin who
		// wants to re-word (or fix) one language's step names without
		// touching its sibling editions at all.
		if ( ! $include_translations ) {
			foreach ( $group as $lang_code => $post ) {
				if ( (int) $post->ID !== $anchor_id ) {
					unset( $group[ $lang_code ] );
				}
			}
		}

		$messages = array();
		$success  = false;

		foreach ( $group as $lang_code => $post ) {
			$row = isset( $labels[ $lang_code ] ) && is_array( $labels[ $lang_code ] ) ? $labels[ $lang_code ] : array();

			$step1 = isset( $row['step1'] ) ? sanitize_text_field( $row['step1'] ) : '';
			$step2 = isset( $row['step2'] ) ? sanitize_text_field( $row['step2'] ) : '';
			$step3 = isset( $row['step3'] ) ? sanitize_text_field( $row['step3'] ) : '';

			$result     = $this->apply_step_names( $post->ID, $step1, $step2, $step3 );
			$success    = $success || $result['success'];
			$messages[] = $result['message'];
		}

		if ( empty( $messages ) ) {
			$messages[] = __( 'No form was selected.', 'ryv-eligibility-tool' );
		}

		$notice = array(
			'success' => $success,
			'message' => implode( ' ', $messages ),
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'ryv_form'   => $anchor_id,
					'ryv_notice' => rawurlencode( wp_json_encode( $notice ) ),
				),
				admin_url( self::PARENT_SLUG . '&page=' . self::PAGE_SLUG )
			)
		);
		exit;
	}

	/**
	 * A node's own direct routing targets, regardless of type: an
	 * `options[].next_node` list for choice nodes (radio/checkbox), or the
	 * single node-level `next_node` for everything else. Empty means the
	 * node is terminal (see is_terminal()).
	 *
	 * @param array $node Node data.
	 * @return string[] Target node ids (deduplicated for choice nodes,
	 *   since several options can share one target).
	 */
	private function direct_targets( array $node ): array {
		if ( in_array( $node['type'] ?? '', array( 'radio', 'checkbox' ), true ) ) {
			$targets = array();
			foreach ( (array) ( $node['options'] ?? array() ) as $option ) {
				if ( ! empty( $option['next_node'] ) ) {
					$targets[] = (string) $option['next_node'];
				}
			}
			return array_values( array_unique( $targets ) );
		}

		return ! empty( $node['next_node'] ) ? array( (string) $node['next_node'] ) : array();
	}

	/**
	 * A node with no outgoing routing at all (per direct_targets()) is a
	 * true terminal - matches engine.js's own "no next_node" definition of
	 * a terminal card (the one that shows "Start Over" instead of
	 * advancing).
	 *
	 * @param array $node Node data.
	 * @return bool
	 */
	private function is_terminal( array $node ): bool {
		return empty( $this->direct_targets( $node ) );
	}

	/**
	 * Follow a chain of `hidden` nodes (Markup/Unsupported nodes only - see
	 * CLAUDE.md's gate-collision section) forward to the first node that
	 * actually gets a card, since a hidden node never appears in the wizard
	 * sidebar (engine.js's updateStepSidebar() skips it - "no card") and so
	 * can never usefully carry a `step_name` itself; the visible step
	 * really begins wherever the visitor's eyes land.
	 *
	 * @param array  $nodes Full node map.
	 * @param string $id    Node id to resolve.
	 * @param int    $depth Recursion guard against a cyclic graph.
	 * @return string|null First non-hidden node id reachable, or null if
	 *   the id doesn't exist or the hidden chain dead-ends.
	 */
	private function resolve_visible( array $nodes, string $id, int $depth = 0 ): ?string {
		if ( $depth > 200 || ! isset( $nodes[ $id ] ) ) {
			return null;
		}

		$node = $nodes[ $id ];

		if ( empty( $node['hidden'] ) ) {
			return $id;
		}

		$targets = $this->direct_targets( $node );

		if ( empty( $targets ) ) {
			return null;
		}

		return $this->resolve_visible( $nodes, $targets[0], $depth + 1 );
	}

	/**
	 * Reset every node's `step_name` in the target form, then set exactly
	 * three: the entry node (step 1), every node the entry node's options
	 * route to - resolved past any hidden nodes in between - (step 2), and
	 * every true-terminal node not already claimed by step 1/2 (step 3).
	 * Everything else (the many un-named nodes in between, e.g. follow-up
	 * questions within one state's own branch) is left with an empty
	 * `step_name` on purpose: engine.js's updateStepSidebar() only starts a
	 * new sidebar entry when a node's `step_name` differs from the
	 * currently-active one, so an unset step_name just keeps the visitor
	 * folded into whichever named step they're already in.
	 *
	 * @param int    $post_id Form post ID.
	 * @param string $step1   Step 1 label (applied to the entry node).
	 * @param string $step2   Step 2 label (applied to every node directly
	 *                        reachable from the entry node).
	 * @param string $step3   Step 3 label (applied to every remaining
	 *                        terminal node).
	 * @return array{success: bool, message: string}
	 */
	private function apply_step_names( int $post_id, string $step1, string $step2, string $step3 ): array {
		$post = get_post( $post_id );

		if ( ! $post || RYV_CPT::POST_TYPE !== $post->post_type ) {
			return array(
				'success' => false,
				'message' => __( 'Form not found.', 'ryv-eligibility-tool' ),
			);
		}

		if ( '' === trim( $step1 ) || '' === trim( $step2 ) || '' === trim( $step3 ) ) {
			return array(
				'success' => false,
				/* translators: %s: form title */
				'message' => sprintf( __( '"%s": all three step names are required - skipped.', 'ryv-eligibility-tool' ), $post->post_title ),
			);
		}

		$schema   = RYV_CPT::get_schema( $post_id );
		$nodes    = $schema['nodes'];
		$entry_id = $schema['entry_node'] ?? null;

		if ( ! $entry_id || ! isset( $nodes[ $entry_id ] ) ) {
			return array(
				'success' => false,
				/* translators: %s: form title */
				'message' => sprintf( __( '"%s": no entry node to start from - skipped.', 'ryv-eligibility-tool' ), $post->post_title ),
			);
		}

		foreach ( $nodes as $id => &$node ) {
			$node['step_name'] = '';
		}
		unset( $node );

		$second_ids = array();
		foreach ( $this->direct_targets( $nodes[ $entry_id ] ) as $target_id ) {
			$visible_id = $this->resolve_visible( $nodes, $target_id );
			if ( $visible_id && $entry_id !== $visible_id ) {
				$second_ids[ $visible_id ] = true;
			}
		}

		$terminal_ids = array();
		foreach ( $nodes as $id => $node ) {
			if ( empty( $node['hidden'] )
				&& $entry_id !== $id
				&& ! isset( $second_ids[ $id ] )
				&& $this->is_terminal( $node )
			) {
				$terminal_ids[ $id ] = true;
			}
		}

		$nodes[ $entry_id ]['step_name'] = $step1;

		foreach ( array_keys( $second_ids ) as $id ) {
			$nodes[ $id ]['step_name'] = $step2;
		}

		foreach ( array_keys( $terminal_ids ) as $id ) {
			$nodes[ $id ]['step_name'] = $step3;
		}

		$schema['nodes'] = $nodes;
		RYV_CPT::save_schema( $post_id, $schema );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: form title, 2: count of step-2 nodes, 3: count of step-3 nodes */
				__( '"%1$s": 1 entry node, %2$d second-question node(s), %3$d final-step node(s) - every other node\'s Step Name was cleared.', 'ryv-eligibility-tool' ),
				$post->post_title,
				count( $second_ids ),
				count( $terminal_ids )
			),
		);
	}

	/**
	 * Render the submenu page: phase 1 (pick a form) if none is selected
	 * yet, otherwise phase 2 (one row of Step 1/2/3 fields per language in
	 * that form's translation group).
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'ryv-eligibility-tool' ) );
		}

		$notice = null;
		if ( isset( $_GET['ryv_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, just decoding the redirect payload.
			$decoded = json_decode( wp_unslash( $_GET['ryv_notice'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- json_decode() into a fixed {success,message} shape, escaped on output below.
			if ( is_array( $decoded ) && isset( $decoded['success'], $decoded['message'] ) ) {
				$notice = $decoded;
			}
		}

		$forms = get_posts(
			array(
				'post_type'      => RYV_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$anchor_id = isset( $_GET['ryv_form'] ) ? absint( wp_unslash( $_GET['ryv_form'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, no state change.
		$group     = $anchor_id ? $this->get_translation_group( $anchor_id ) : array();

		include RYV_PLUGIN_DIR . 'admin/views/step-names-page.php';
	}
}
