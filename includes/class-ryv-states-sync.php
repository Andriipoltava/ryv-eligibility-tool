<?php
/**
 * Bidirectional sync between the States CPT's ACF node repeater and the
 * form's `_ryv_form_schema` postmeta.
 *
 * - AJAX handler:  loads all nodes for a given state from the form schema
 *                  and returns them as JSON for ACF repeater population.
 * - Save hook:     on `acf/save_post`, reads the repeater data and writes
 *                  changed nodes back into the schema via
 *                  RYV_CPT::save_schema().
 *
 * State ownership: a node "belongs to" a state when it is reachable from
 * the state picker option's `next_node`. We walk the graph depth-first,
 * collecting every reachable node — this is safer than prefix-matching
 * because node IDs are not guaranteed to follow a consistent naming
 * convention (legacy migrated schemas use `{state}__` while YAML imports
 * use abbreviation prefixes like `al_`).
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_States_Sync
 */
class RYV_States_Sync {

	/**
	 * Constructor.
	 */
	/**
	 * The Nodes repeater's rows exactly as submitted in this request,
	 * captured from raw $_POST before ACF's own save runs - keyed by
	 * post_id. See capture_submitted_rows() for why sync_nodes_to_schema()
	 * reads from this instead of get_field().
	 *
	 * @var array<int, array>
	 */
	private $submitted_rows = array();

	public function __construct() {
		add_action( 'wp_ajax_ryv_load_state_nodes', array( $this, 'ajax_load_nodes' ) );
		add_action( 'wp_ajax_ryv_save_node_id', array( $this, 'ajax_save_node_id' ) );
		add_action( 'acf/save_post', array( $this, 'capture_submitted_rows' ), 5 );
		add_action( 'acf/save_post', array( $this, 'sync_nodes_to_schema' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_filter( 'acf/load_value/name=ryv_form_nodes', array( $this, 'filter_stale_node_rows' ), 20, 3 );
	}

	/**
	 * Capture the Nodes repeater's rows straight from $_POST, keyed by ACF
	 * sub-field *key* (as submitted) rather than reading them back via
	 * get_field()/get_field()'s own acf/load_value filter chain.
	 *
	 * This exists because get_field('ryv_form_nodes', $post_id) called from
	 * sync_nodes_to_schema() (a later acf/save_post priority) turned out to
	 * be unreliable within the same save request: confirmed live via
	 * temporary logging that it can return something other than the array
	 * of the just-submitted rows - almost certainly ACF's own internal
	 * per-request value cache/filter-chain interacting with our
	 * acf/load_value/name=ryv_form_nodes filter in an inconsistent way for
	 * a nested repeater-of-repeaters field this size - causing
	 * sync_nodes_to_schema() to silently bail on `rows empty` and do
	 * nothing, however many times the admin saved. Reading straight from
	 * $_POST sidesteps all of that: it's already sanitized by ACF's own
	 * acf_save_post() (wp_kses_post_deep()) before `acf/save_post` fires at
	 * all, so this priority-5 hook sees exactly the same data ACF itself is
	 * about to persist.
	 *
	 * @param int $post_id The post ID being saved.
	 */
	public function capture_submitted_rows( $post_id ) {
		if ( get_post_type( $post_id ) !== RYV_States_CPT::POST_TYPE ) {
			return;
		}

		$raw = $_POST['acf']['field_ryv_form_nodes'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by ACF itself before acf/save_post fires.

		if ( ! is_array( $raw ) ) {
			return;
		}

		$rows = array();

		foreach ( $raw as $raw_row ) {
			if ( ! is_array( $raw_row ) ) {
				continue;
			}

			$row = array(
				'node_id'              => wp_unslash( $raw_row['field_ryv_node_id'] ?? '' ),
				'node_original_id'     => wp_unslash( $raw_row['field_ryv_node_original_id'] ?? '' ),
				'node_type'            => wp_unslash( $raw_row['field_ryv_node_type'] ?? '' ),
				'node_title'           => wp_unslash( $raw_row['field_ryv_node_title'] ?? '' ),
				'node_step_name'       => wp_unslash( $raw_row['field_ryv_node_step_name'] ?? '' ),
				'node_content'         => wp_unslash( $raw_row['field_ryv_node_content'] ?? '' ),
				'node_help'            => wp_unslash( $raw_row['field_ryv_node_help'] ?? '' ),
				'node_display_style'   => wp_unslash( $raw_row['field_ryv_node_display_style'] ?? '' ),
				'node_hidden'          => wp_unslash( $raw_row['field_ryv_node_hidden'] ?? '' ),
				'node_next_node'       => wp_unslash( $raw_row['field_ryv_node_next_node'] ?? '' ),
				'node_reference_nodes' => wp_unslash( $raw_row['field_ryv_node_reference_nodes'] ?? '' ),
				// A select2-enhanced field (`ui => 1`) populated via
				// states-admin.js's `field.val()` on an AJAX-added row (never
				// manually touched) can be entirely absent from $_POST, unlike
				// a genuinely-cleared field which submits as ''. Track
				// presence separately so sync_nodes_to_schema() can tell "key
				// missing - leave the existing value alone" apart from "key
				// present but blank - the admin actually cleared it," instead
				// of silently wiping real next_node routing on every save of
				// an untouched row (confirmed: wiped northam_exec/virginia_oos
				// on Virginia).
				'node_next_node_submitted' => array_key_exists( 'field_ryv_node_next_node', $raw_row ),
			);

			$options     = array();
			$raw_options = $raw_row['field_ryv_node_options'] ?? array();

			if ( is_array( $raw_options ) ) {
				foreach ( $raw_options as $raw_opt ) {
					if ( ! is_array( $raw_opt ) ) {
						continue;
					}
					$options[] = array(
						'option_label'               => wp_unslash( $raw_opt['field_ryv_option_label'] ?? '' ),
						'option_value'               => wp_unslash( $raw_opt['field_ryv_option_value'] ?? '' ),
						'option_next_node'           => wp_unslash( $raw_opt['field_ryv_option_next_node'] ?? '' ),
						'option_next_node_submitted' => array_key_exists( 'field_ryv_option_next_node', $raw_opt ),
					);
				}
			}

			$row['node_options'] = $options;
			$rows[]              = $row;
		}

		$this->submitted_rows[ $post_id ] = $rows;
	}

	/**
	 * Drop any repeater row whose node id no longer exists in the connected
	 * form's schema - e.g. because it was deleted from RYV Forms directly,
	 * not from this state's own repeater. Without this, a state's edit
	 * screen keeps showing a "ghost" row for a question that's already gone,
	 * confusingly forever (states-admin.js only re-syncs on a Form/State
	 * field *change*, not on every normal page load - see its own docblock -
	 * so a stale row otherwise never gets cleared on its own). This only
	 * filters what's *read*; the stored postmeta itself is untouched until
	 * the next real save, so nothing destructive happens just by opening
	 * the screen.
	 *
	 * @param mixed $value   The repeater's rows as loaded from postmeta.
	 * @param int   $post_id The state post being loaded.
	 * @param array $field   The ACF field array (unused).
	 * @return mixed
	 */
	public function filter_stale_node_rows( $value, $post_id, $field ) {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return $value;
		}

		if ( get_post_type( $post_id ) !== RYV_States_CPT::POST_TYPE ) {
			return $value;
		}

		$form_id = (int) get_field( 'ryv_form_connection', $post_id );

		if ( ! $form_id ) {
			return $value;
		}

		$schema = RYV_CPT::get_schema( $form_id );
		$nodes  = $schema['nodes'] ?? array();

		return array_values( array_filter( $value, function ( $row ) use ( $nodes ) {
			$id = trim( $row['node_id'] ?? '' );
			return '' !== $id && isset( $nodes[ $id ] );
		} ) );
	}

	/**
	 * Enqueue the admin JS/CSS that auto-syncs the node repeater from the
	 * selected form+state and restyles it to match the form builder's
	 * list/detail look (see assets/css/admin/states-admin.css).
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_admin_scripts( string $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || RYV_States_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'ryv-states-admin',
			RYV_PLUGIN_URL . 'assets/css/admin/states-admin.css',
			array(),
			RYV_VERSION
		);

		wp_enqueue_script(
			'ryv-states-admin',
			RYV_PLUGIN_URL . 'assets/js/admin/states-admin.js',
			array( 'jquery', 'acf-input' ),
			RYV_VERSION,
			true
		);

		wp_localize_script( 'ryv-states-admin', 'ryvStatesAdmin', array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'ryv_load_state_nodes' ),
			'saveNodeNonce' => wp_create_nonce( 'ryv_save_node_id' ),
		) );
	}

	/**
	 * AJAX: Load all nodes for a given form + state combination.
	 *
	 * Expects POST params: form_id, state_slug.
	 * Returns JSON array of node data ready for ACF repeater insertion.
	 */
	public function ajax_load_nodes() {
		check_ajax_referer( 'ryv_load_state_nodes', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.', 403 );
		}

		$form_id    = absint( $_POST['form_id'] ?? 0 );
		$state_slug = sanitize_text_field( $_POST['state_slug'] ?? '' );

		if ( ! $form_id || ! $state_slug ) {
			wp_send_json_error( 'Missing form_id or state_slug.' );
		}

		$schema = RYV_CPT::get_schema( $form_id );
		$nodes  = $schema['nodes'] ?? array();

		$state_node_ids = self::collect_state_node_ids( $nodes, $schema['entry_node'] ?? null, $state_slug );

		if ( empty( $state_node_ids ) ) {
			wp_send_json_error( 'No nodes found for state "' . $state_slug . '" in this form.' );
		}

		$result = array();
		foreach ( $state_node_ids as $node_id ) {
			if ( ! isset( $nodes[ $node_id ] ) ) {
				continue;
			}
			$node     = $nodes[ $node_id ];
			$result[] = self::node_to_acf_row( $node_id, $node );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: create (or safely rename) a single node's id in the connected
	 * form's schema immediately, without waiting for the whole state post to
	 * be saved - so a question added via "Add Node" becomes pickable in every
	 * Next Node dropdown on the page right away (states-admin.js's "Save
	 * Node ID" button), instead of only after a full page reload.
	 *
	 * Expects POST params: post_id (the ryv_states post), node_id,
	 * node_original_id (may be empty for a brand-new row).
	 * Returns the resolved node id plus the form's full up-to-date
	 * `{id: label}` choice map, so the client can rebuild every Next Node
	 * select on the page from one source of truth.
	 */
	public function ajax_save_node_id() {
		check_ajax_referer( 'ryv_save_node_id', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.', 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );

		if ( ! $post_id || get_post_type( $post_id ) !== RYV_States_CPT::POST_TYPE ) {
			wp_send_json_error( 'Invalid state post.' );
		}

		$form_id    = (int) get_field( 'ryv_form_connection', $post_id );
		$state_slug = get_field( 'ryv_state_slug', $post_id );

		if ( ! $form_id ) {
			wp_send_json_error( 'This state has no connected form yet.' );
		}

		$typed_id    = trim( sanitize_text_field( $_POST['node_id'] ?? '' ) );
		$original_id = trim( sanitize_text_field( $_POST['node_original_id'] ?? '' ) );

		if ( '' === $typed_id ) {
			wp_send_json_error( 'Node ID can\'t be empty.' );
		}

		$schema = RYV_CPT::get_schema( $form_id );
		$nodes  = &$schema['nodes'];

		$default_group = $state_slug ? 'state_' . sanitize_title( $state_slug ) : '';
		$resolved_id    = self::resolve_row_node_id( $nodes, $schema, $typed_id, $original_id, $default_group );

		if ( null === $resolved_id ) {
			wp_send_json_error( 'Couldn\'t save "' . $typed_id . '" - it may already be used by another question, or the question this row was loaded from no longer exists. Pick a different id and try again.' );
		}

		RYV_CPT::save_schema( $form_id, $schema );

		$choices = array( '' => '— End —' );
		foreach ( $nodes as $node_id => $node ) {
			$label = $node_id;
			if ( ! empty( $node['title'] ) ) {
				$label .= ' — ' . wp_strip_all_tags( $node['title'] );
			}
			$choices[ $node_id ] = $label;
		}

		wp_send_json_success( array(
			'node_id' => $resolved_id,
			'choices' => $choices,
		) );
	}

	/**
	 * Resolve a repeater row's typed Node ID + its original id into the
	 * actual key it should be saved under in $nodes, creating a brand-new
	 * node or performing a safe rename (rewriting every reference) as
	 * needed. Shared by sync_nodes_to_schema() (whole-post save) and
	 * ajax_save_node_id() (single-node AJAX save), so both take the exact
	 * same create/rename/collision decisions.
	 *
	 * @param array  $nodes       Nodes map, modified in place.
	 * @param array  $schema      Full schema, modified in place (entry_node
	 *                            on a rename).
	 * @param string $typed_id    The Node ID field's current value.
	 * @param string $original_id The id this row was loaded with (empty for
	 *                            a row that was never saved before).
	 * @param string $default_group Group to stamp on a brand-new node only
	 *                            (e.g. "state_vermont") - matches this
	 *                            state's own existing nodes' naming
	 *                            convention, since the States screen has no
	 *                            Group field of its own to set one from.
	 *                            Without this, a node created here had no
	 *                            group at all and fell into the Forms
	 *                            builder's catch-all "General" bucket
	 *                            instead of sitting with the rest of its
	 *                            state - confirmed live.
	 * @return string|null The resolved node id to write fields onto, or null
	 *                      if this row should be skipped entirely (empty id,
	 *                      or a create/rename that would collide with an
	 *                      unrelated existing node).
	 */
	private static function resolve_row_node_id( array &$nodes, array &$schema, string $typed_id, string $original_id, string $default_group = '' ): ?string {
		if ( '' === $typed_id ) {
			return null;
		}

		if ( '' === $original_id ) {
			// A row added via "Add Node" this session - never had an id
			// before, so this is a brand-new question. Only create it if
			// the typed id isn't already some other node's id (skip instead
			// of clobbering an unrelated node - the admin can just pick a
			// different id and save again).
			if ( isset( $nodes[ $typed_id ] ) ) {
				return null;
			}
			$nodes[ $typed_id ] = array();
			if ( '' !== $default_group ) {
				$nodes[ $typed_id ]['group'] = $default_group;
			}
			return $typed_id;
		}

		if ( ! isset( $nodes[ $original_id ] ) ) {
			// The node this row was loaded from no longer exists (e.g.
			// deleted elsewhere in the meantime) - nothing to attach to.
			return null;
		}

		if ( $original_id === $typed_id ) {
			// Ordinary edit of an existing node's own fields.
			return $original_id;
		}

		if ( isset( $nodes[ $typed_id ] ) ) {
			// Attempted rename onto an id already used by another node -
			// unsafe to guess which one wins, so keep editing the original
			// node under its original id instead of clobbering the other one.
			return null;
		}

		// Safe rename: move the node to its new id and rewrite every
		// reference to the old one across the whole graph, the same as the
		// form builder's own renameNode().
		self::rename_node_id( $nodes, $schema, $original_id, $typed_id );
		return $typed_id;
	}

	/**
	 * Whether $target_id is still reachable or linked from anywhere in the
	 * graph other than the nodes about to be removed alongside it - used to
	 * decide whether a node dropped from a state's repeater is safe to
	 * actually delete from the schema (see sync_nodes_to_schema()).
	 *
	 * @param array    $nodes             Nodes map (not yet mutated by this save's deletions).
	 * @param array    $schema            Full schema, for entry_node.
	 * @param string   $target_id         The node id being considered for deletion.
	 * @param string[] $ids_being_removed Every id in this same deletion batch -
	 *                                    a reference from one about-to-be-deleted
	 *                                    node to another doesn't count as keeping
	 *                                    $target_id alive.
	 * @return bool
	 */
	private static function is_node_referenced( array $nodes, array $schema, string $target_id, array $ids_being_removed ): bool {
		if ( ( $schema['entry_node'] ?? null ) === $target_id ) {
			return true;
		}

		foreach ( $nodes as $id => $node ) {
			if ( $id === $target_id || in_array( $id, $ids_being_removed, true ) ) {
				continue;
			}

			if ( ( $node['next_node'] ?? null ) === $target_id ) {
				return true;
			}

			if ( ! empty( $node['options'] ) && is_array( $node['options'] ) ) {
				foreach ( $node['options'] as $option ) {
					if ( ( $option['next_node'] ?? null ) === $target_id ) {
						return true;
					}
				}
			}

			if ( ! empty( $node['reference_node_ids'] ) && is_array( $node['reference_node_ids'] ) && in_array( $target_id, $node['reference_node_ids'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find the node id a state's picker option routes to - i.e. that
	 * state's own first question. Shared by collect_state_node_ids() (which
	 * walks forward from it) and RYV_Widget's "Auto-Detect State From Page"
	 * mode (which uses it directly as a decision-engine entry-node override
	 * so a state page can skip the picker and start right on its own first
	 * question).
	 *
	 * Schema option values may be title-case ("Alaska") while callers pass a
	 * slug ("alaska"), so both sides are compared via sanitize_title().
	 *
	 * @param array       $nodes       The full nodes map from the schema.
	 * @param string|null $entry_node  The schema's entry_node.
	 * @param string      $state_slug  State slug to find.
	 * @return string|null
	 */
	public static function find_start_node( array $nodes, ?string $entry_node, string $state_slug ): ?string {
		if ( $entry_node && isset( $nodes[ $entry_node ] ) ) {
			$picker = $nodes[ $entry_node ];
			if ( ! empty( $picker['options'] ) && is_array( $picker['options'] ) ) {
				foreach ( $picker['options'] as $option ) {
					$opt_val = sanitize_title( $option['value'] ?? '' );
					if ( $opt_val === $state_slug && ! empty( $option['next_node'] ) ) {
						return $option['next_node'];
					}
				}
			}
		}

		// Fallback: scan all nodes for a radio with a matching option value.
		foreach ( $nodes as $node ) {
			if ( ( $node['type'] ?? '' ) !== 'radio' || empty( $node['options'] ) ) {
				continue;
			}
			foreach ( $node['options'] as $option ) {
				$opt_val = sanitize_title( $option['value'] ?? '' );
				if ( $opt_val === $state_slug && ! empty( $option['next_node'] ) ) {
					return $option['next_node'];
				}
			}
		}

		return null;
	}

	/**
	 * Collect all node IDs belonging to a state: every node reachable by
	 * walking the graph from the state picker option's next_node, *plus*
	 * every node explicitly tagged with this state's own group (e.g.
	 * `state_virginia`) even if nothing currently routes to it.
	 *
	 * The group-tagged half matters on its own: a question that's part of a
	 * state's content but got disconnected from the graph (a bad edit
	 * elsewhere, a node left over from reorganizing that state's flow) is
	 * exactly the kind of thing an admin needs to *see* on the state's own
	 * screen to notice and fix - reachability alone would hide it, showing
	 * only whatever subset of the state's real 16 questions happen to still
	 * be wired in. Confirmed as a real gap: a form group with 16 nodes for a
	 * state showed fewer in the state screen once some of them fell out of
	 * the reachable chain.
	 *
	 * @param array       $nodes       The full nodes map from the schema.
	 * @param string|null $entry_node  The schema's entry_node.
	 * @param string      $state_slug  State slug to find.
	 * @return string[] Ordered list of node IDs - reachable ones first (in
	 *                   BFS order), any group-only extras appended after.
	 */
	public static function collect_state_node_ids( array $nodes, ?string $entry_node, string $state_slug ): array {
		$start_node_id = self::find_start_node( $nodes, $entry_node, $state_slug );
		$collected     = array();

		if ( $start_node_id ) {
			// BFS to collect all reachable nodes from the start.
			$queue = array( $start_node_id );

			while ( ! empty( $queue ) ) {
				$current = array_shift( $queue );

				if ( isset( $collected[ $current ] ) || ! isset( $nodes[ $current ] ) ) {
					continue;
				}

				// Don't cross into other states via the state picker itself.
				if ( $current === $entry_node ) {
					continue;
				}

				$collected[ $current ] = true;
				$node = $nodes[ $current ];

				// Follow option routes.
				if ( ! empty( $node['options'] ) && is_array( $node['options'] ) ) {
					foreach ( $node['options'] as $option ) {
						if ( ! empty( $option['next_node'] ) && ! isset( $collected[ $option['next_node'] ] ) ) {
							$queue[] = $option['next_node'];
						}
					}
				}

				// Follow node-level next_node.
				if ( ! empty( $node['next_node'] ) && ! isset( $collected[ $node['next_node'] ] ) ) {
					$queue[] = $node['next_node'];
				}

				// Follow reference nodes.
				if ( ! empty( $node['reference_node_ids'] ) && is_array( $node['reference_node_ids'] ) ) {
					foreach ( $node['reference_node_ids'] as $ref_id ) {
						if ( ! isset( $collected[ $ref_id ] ) ) {
							$queue[] = $ref_id;
						}
					}
				}
			}
		}

		// Every node tagged with this state's group, reachable or not -
		// matches the "state_{slug}" convention resolve_row_node_id()
		// stamps on a node created from this state's own screen.
		$expected_group = 'state_' . sanitize_title( $state_slug );

		foreach ( $nodes as $id => $node ) {
			if ( $id !== $entry_node && ( $node['group'] ?? '' ) === $expected_group ) {
				$collected[ $id ] = true;
			}
		}

		return array_keys( $collected );
	}

	/**
	 * Convert a schema node into an ACF repeater row array.
	 *
	 * @param string $node_id Node ID.
	 * @param array  $node    Node data from the schema.
	 * @return array
	 */
	private static function node_to_acf_row( string $node_id, array $node ): array {
		$options = array();
		if ( ! empty( $node['options'] ) && is_array( $node['options'] ) ) {
			foreach ( $node['options'] as $opt ) {
				$options[] = array(
					'option_label'     => $opt['label'] ?? '',
					'option_value'     => $opt['value'] ?? '',
					'option_next_node' => $opt['next_node'] ?? '',
				);
			}
		}

		$ref_nodes = '';
		if ( ! empty( $node['reference_node_ids'] ) && is_array( $node['reference_node_ids'] ) ) {
			$ref_nodes = implode( ', ', $node['reference_node_ids'] );
		}

		return array(
			'node_id'              => $node_id,
			// Snapshot of the id this row was loaded with, so
			// sync_nodes_to_schema() can tell a deliberate rename (original_id
			// present but different from node_id) apart from a brand-new row
			// added via "Add Node" (original_id never set).
			'node_original_id'     => $node_id,
			'node_type'            => $node['type'] ?? 'markup',
			'node_title'           => $node['title'] ?? '',
			'node_step_name'       => $node['step_name'] ?? '',
			'node_content'         => $node['content'] ?? '',
			'node_help'            => $node['help'] ?? '',
			'node_display_style'   => self::resolve_display_style( $node ),
			'node_hidden'          => ! empty( $node['hidden'] ) ? 1 : 0,
			'node_next_node'       => $node['next_node'] ?? '',
			'node_reference_nodes' => $ref_nodes,
			'node_options'         => $options,
		);
	}

	/**
	 * A node's explicit `display_style` (set at import time only for a
	 * Drupal `select`, see RYV_Yaml_Parser::build_node()) wins when present.
	 * Older imports of `radios`/`webform_buttons` elements never got an
	 * explicit style written - they render correctly today only because
	 * the frontend's own missing-style fallback happens to be 'buttons' -
	 * but that leaves this repeater's Display Style field blank instead of
	 * showing the style the source form element actually used. Derive it
	 * from `source_type` (the raw Drupal `#type`, always preserved) so the
	 * States screen shows/edits the same style the form is really using,
	 * without needing a data migration or re-import.
	 *
	 * @param array $node Node data from the schema.
	 * @return string
	 */
	private static function resolve_display_style( array $node ): string {
		if ( ! empty( $node['display_style'] ) ) {
			return $node['display_style'];
		}

		$source_type_map = array(
			'webform_buttons' => 'buttons',
			'radios'          => 'radio',
		);

		return $source_type_map[ $node['source_type'] ?? '' ] ?? '';
	}

	/**
	 * On save: read the ACF node repeater and merge changes back into the
	 * form's `_ryv_form_schema`.
	 *
	 * Only fires for ryv_states posts that have a form connection.
	 *
	 * @param int $post_id The post ID being saved.
	 */
	public function sync_nodes_to_schema( $post_id ) {
		if ( get_post_type( $post_id ) !== RYV_States_CPT::POST_TYPE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$form_id    = (int) get_field( 'ryv_form_connection', $post_id );
		$state_slug = get_field( 'ryv_state_slug', $post_id );

		if ( ! $form_id || ! $state_slug ) {
			return;
		}

		// Straight from $_POST (captured at priority 5, before ACF's own
		// save) rather than get_field( 'ryv_form_nodes', $post_id ) - see
		// capture_submitted_rows() for why the latter is unreliable here.
		$rows = $this->submitted_rows[ $post_id ] ?? null;

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		$schema = RYV_CPT::get_schema( $form_id );
		$nodes  = &$schema['nodes'];

		// A row the admin deleted from this state's repeater (rather than one
		// that was simply never loaded here) needs its node actually removed
		// from the schema, not just left out of this save's $rows - otherwise
		// it lingers as dead weight in the form forever, which reads as "I
		// deleted this and it didn't go away."
		//
		// "Belonged to this state before this save" is computed fresh from
		// the *current* schema via collect_state_node_ids() - every node
		// reachable from the state's picker, plus every node tagged with
		// this state's own group - rather than compared against a snapshot
		// of what was last actually saved to this post's own postmeta. That
		// snapshot approach (an earlier version of this code) silently
		// never detected a deletion at all on a state whose repeater had
		// never been saved with its full node list before - the very common
		// case where the on-screen rows came from the auto-resync AJAX call
		// (see states-admin.js) and the admin's first-ever save is the one
		// where they also delete a row: the "before" snapshot read back an
		// empty/stale value, so $removed_ids came out empty and nothing was
		// ever deleted, however many times they saved. Confirmed live on a
		// real Virginia state with 15 group-tagged questions. Recomputing
		// from the schema instead has no such gap, since it doesn't depend
		// on anything having been persisted by a previous save.
		$known_before = self::collect_state_node_ids( $nodes, $schema['entry_node'] ?? null, $state_slug );

		$kept_ids = array();
		foreach ( $rows as $row ) {
			$orig = trim( $row['node_original_id'] ?? '' );
			if ( '' !== $orig ) {
				$kept_ids[ $orig ] = true;
			}
		}

		$removed_ids = array_diff( $known_before, array_keys( $kept_ids ) );

		// Every node in $known_before - the state picker's own reachable
		// chain plus every group-tagged node - is this state's *internal*
		// wiring, not an external keep-alive. A deleted node is almost
		// always referenced by exactly one of these (the picker itself, if
		// it was the state's first question, or the sibling question that
		// led to it), so without excluding them the "still referenced,
		// leave it" check below would refuse to delete almost anything -
		// confirmed live: deleting a node wired in as the state's own first
		// question never actually removed it, because the state picker's
		// own Vermont option pointing at it counted as "still referenced."
		// Only a reference from *outside* this set (another state's
		// question sharing this node via reference_node_ids, the documented
		// cross-state case) should still block deletion.
		$internal_ids = $known_before;
		if ( ! empty( $schema['entry_node'] ) ) {
			$internal_ids[] = $schema['entry_node'];
		}
		$exclude_from_reference_check = array_unique( array_merge( $removed_ids, $internal_ids ) );

		foreach ( $removed_ids as $removed_id ) {
			// Only delete if nothing *outside this state's own wiring*
			// still points at it - see $exclude_from_reference_check above.
			// Leave a genuinely cross-referenced node as an orphan rather
			// than risk breaking a route owned elsewhere;
			// RYV_Graph_Validator will flag it either way.
			if ( self::is_node_referenced( $nodes, $schema, $removed_id, $exclude_from_reference_check ) ) {
				continue;
			}
			unset( $nodes[ $removed_id ] );
		}

		$default_group = 'state_' . sanitize_title( $state_slug );

		foreach ( $rows as $row ) {
			$typed_id    = trim( $row['node_id'] ?? '' );
			$original_id = trim( $row['node_original_id'] ?? '' );

			$node_id = self::resolve_row_node_id( $nodes, $schema, $typed_id, $original_id, $default_group );

			if ( null === $node_id ) {
				continue;
			}

			// Update scalar fields.
			$nodes[ $node_id ]['type']  = $row['node_type'] ?? $nodes[ $node_id ]['type'];
			$nodes[ $node_id ]['title'] = $row['node_title'] ?? '';

			// Choice-only fields (options, display_style) and non-choice-only
			// fields (next_node) are gated on the row's own current type, not
			// just written unconditionally - ACF's conditional_logic only
			// hides the *inapplicable* field group in the UI, it doesn't stop
			// a value already sitting in that hidden group from being
			// submitted and written. Without this gate, switching a node's
			// Type back and forth (or a stray edit while the wrong type was
			// selected) can leave e.g. a markup node with a leftover
			// `options` entry whose `next_node` silently routes back to the
			// node itself - confirmed live: exactly this turned
			// `vermont_you_can_vote` into a one-node infinite loop.
			$is_choice_type = in_array( $nodes[ $node_id ]['type'], array( 'radio', 'checkbox' ), true );

			// Only set content/help/display_style if the value is non-empty
			// or the key already exists — avoids polluting nodes with empty keys.
			$content = $row['node_content'] ?? '';
			if ( '' !== $content || isset( $nodes[ $node_id ]['content'] ) ) {
				$nodes[ $node_id ]['content'] = $content;
			}

			$help = $row['node_help'] ?? '';
			if ( '' !== $help || isset( $nodes[ $node_id ]['help'] ) ) {
				$nodes[ $node_id ]['help'] = $help;
			}

			$step_name = $row['node_step_name'] ?? '';
			if ( '' !== $step_name || isset( $nodes[ $node_id ]['step_name'] ) ) {
				$nodes[ $node_id ]['step_name'] = $step_name;
			}

			if ( 'radio' === $nodes[ $node_id ]['type'] ) {
				$display_style = $row['node_display_style'] ?? '';
				if ( '' !== $display_style || isset( $nodes[ $node_id ]['display_style'] ) ) {
					$nodes[ $node_id ]['display_style'] = $display_style;
				}
			} elseif ( isset( $nodes[ $node_id ]['display_style'] ) ) {
				unset( $nodes[ $node_id ]['display_style'] );
			}

			// Hidden: only set if truthy, otherwise remove the key entirely
			// so nodes that were never hidden don't get a spurious `false`.
			if ( ! empty( $row['node_hidden'] ) ) {
				$nodes[ $node_id ]['hidden'] = true;
			} elseif ( isset( $nodes[ $node_id ]['hidden'] ) ) {
				unset( $nodes[ $node_id ]['hidden'] );
			}

			// Non-choice nodes: update next_node. Choice nodes route via their
			// own options instead, so a leftover node-level next_node from
			// before a type change is cleared rather than left dangling.
			if ( $is_choice_type ) {
				if ( isset( $nodes[ $node_id ]['next_node'] ) ) {
					unset( $nodes[ $node_id ]['next_node'] );
				}
			} elseif ( ! empty( $row['node_next_node_submitted'] ) ) {
				// Only trust this field when its key was actually present in
				// $_POST - see the `node_next_node_submitted` comment in
				// capture_submitted_rows() for why it can legitimately be
				// missing on an untouched row.
				$next = trim( $row['node_next_node'] );
				if ( '' !== $next ) {
					$nodes[ $node_id ]['next_node'] = $next;
				} elseif ( isset( $nodes[ $node_id ]['next_node'] ) ) {
					// Only clear if the field was explicitly blanked.
					unset( $nodes[ $node_id ]['next_node'] );
				}
			}

			// Reference nodes: comma-separated string → array.
			if ( isset( $row['node_reference_nodes'] ) ) {
				$ref_str = trim( $row['node_reference_nodes'] );
				if ( '' !== $ref_str ) {
					$nodes[ $node_id ]['reference_node_ids'] = array_map(
						'trim',
						explode( ',', $ref_str )
					);
				} else {
					$nodes[ $node_id ]['reference_node_ids'] = array();
				}
			}

			// Options sub-repeater - only choice types route via options.
			if ( $is_choice_type && isset( $row['node_options'] ) && is_array( $row['node_options'] ) ) {
				$existing_options = $nodes[ $node_id ]['options'] ?? array();
				$new_options      = array();
				foreach ( $row['node_options'] as $i => $opt_row ) {
					$opt = array(
						'label' => $opt_row['option_label'] ?? '',
						'value' => $opt_row['option_value'] ?? '',
					);

					if ( ! empty( $opt_row['option_next_node_submitted'] ) ) {
						$next = trim( $opt_row['option_next_node'] ?? '' );
						if ( '' !== $next ) {
							$opt['next_node'] = $next;
						}
					} elseif ( isset( $existing_options[ $i ]['next_node'] ) ) {
						// Key missing from $_POST (same select2/AJAX-row quirk
						// as node-level next_node) - keep the existing route
						// instead of silently dropping it.
						$opt['next_node'] = $existing_options[ $i ]['next_node'];
					}

					$new_options[] = $opt;
				}
				$nodes[ $node_id ]['options'] = $new_options;
			} elseif ( ! $is_choice_type && isset( $nodes[ $node_id ]['options'] ) ) {
				unset( $nodes[ $node_id ]['options'] );
			}
		}

		RYV_CPT::save_schema( $form_id, $schema );
	}

	/**
	 * Move a node from $old_id to $new_id within $nodes and rewrite every
	 * reference to $old_id elsewhere in the graph - entry_node, every node's
	 * next_node, every option's next_node, and every reference_node_ids entry
	 * - so a rename made from this screen never leaves a dangling link. Same
	 * behavior as the admin builder SPA's own renameNode() (App.js).
	 *
	 * @param array $nodes  Nodes map, modified in place (already a reference
	 *                      into $schema['nodes'] at the call site).
	 * @param array $schema Full schema, modified in place for entry_node.
	 * @param string $old_id Existing node id.
	 * @param string $new_id New node id to rename to.
	 */
	private static function rename_node_id( array &$nodes, array &$schema, string $old_id, string $new_id ) {
		$nodes[ $new_id ] = $nodes[ $old_id ];
		unset( $nodes[ $old_id ] );

		if ( ( $schema['entry_node'] ?? null ) === $old_id ) {
			$schema['entry_node'] = $new_id;
		}

		foreach ( $nodes as &$node ) {
			if ( ( $node['next_node'] ?? null ) === $old_id ) {
				$node['next_node'] = $new_id;
			}

			if ( ! empty( $node['options'] ) && is_array( $node['options'] ) ) {
				foreach ( $node['options'] as &$option ) {
					if ( ( $option['next_node'] ?? null ) === $old_id ) {
						$option['next_node'] = $new_id;
					}
				}
				unset( $option );
			}

			if ( ! empty( $node['reference_node_ids'] ) && is_array( $node['reference_node_ids'] ) ) {
				foreach ( $node['reference_node_ids'] as &$ref_id ) {
					if ( $ref_id === $old_id ) {
						$ref_id = $new_id;
					}
				}
				unset( $ref_id );
			}
		}
		unset( $node );
	}
}
