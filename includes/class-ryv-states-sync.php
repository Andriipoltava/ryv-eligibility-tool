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
	public function __construct() {
		add_action( 'wp_ajax_ryv_load_state_nodes', array( $this, 'ajax_load_nodes' ) );
		add_action( 'wp_ajax_ryv_save_node_id', array( $this, 'ajax_save_node_id' ) );
		add_action( 'acf/save_post', array( $this, 'sync_nodes_to_schema' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
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

		$form_id = (int) get_field( 'ryv_form_connection', $post_id );

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

		$resolved_id = self::resolve_row_node_id( $nodes, $schema, $typed_id, $original_id );

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
	 * @return string|null The resolved node id to write fields onto, or null
	 *                      if this row should be skipped entirely (empty id,
	 *                      or a create/rename that would collide with an
	 *                      unrelated existing node).
	 */
	private static function resolve_row_node_id( array &$nodes, array &$schema, string $typed_id, string $original_id ): ?string {
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
	 * Collect all node IDs belonging to a state by walking the graph from
	 * the state picker option's next_node.
	 *
	 * @param array       $nodes       The full nodes map from the schema.
	 * @param string|null $entry_node  The schema's entry_node.
	 * @param string      $state_slug  State slug to find.
	 * @return string[] Ordered list of node IDs.
	 */
	public static function collect_state_node_ids( array $nodes, ?string $entry_node, string $state_slug ): array {
		$start_node_id = self::find_start_node( $nodes, $entry_node, $state_slug );

		if ( ! $start_node_id ) {
			return array();
		}

		// BFS to collect all reachable nodes from the start.
		$collected = array();
		$queue     = array( $start_node_id );

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

		$rows = get_field( 'ryv_form_nodes', $post_id );

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		$schema = RYV_CPT::get_schema( $form_id );
		$nodes  = &$schema['nodes'];

		foreach ( $rows as $row ) {
			$typed_id    = trim( $row['node_id'] ?? '' );
			$original_id = trim( $row['node_original_id'] ?? '' );

			$node_id = self::resolve_row_node_id( $nodes, $schema, $typed_id, $original_id );

			if ( null === $node_id ) {
				continue;
			}

			// Update scalar fields.
			$nodes[ $node_id ]['type']  = $row['node_type'] ?? $nodes[ $node_id ]['type'];
			$nodes[ $node_id ]['title'] = $row['node_title'] ?? '';

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

			$display_style = $row['node_display_style'] ?? '';
			if ( '' !== $display_style || isset( $nodes[ $node_id ]['display_style'] ) ) {
				$nodes[ $node_id ]['display_style'] = $display_style;
			}

			// Hidden: only set if truthy, otherwise remove the key entirely
			// so nodes that were never hidden don't get a spurious `false`.
			if ( ! empty( $row['node_hidden'] ) ) {
				$nodes[ $node_id ]['hidden'] = true;
			} elseif ( isset( $nodes[ $node_id ]['hidden'] ) ) {
				unset( $nodes[ $node_id ]['hidden'] );
			}

			// Non-choice nodes: update next_node.
			if ( isset( $row['node_next_node'] ) ) {
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

			// Options sub-repeater.
			if ( isset( $row['node_options'] ) && is_array( $row['node_options'] ) ) {
				$new_options = array();
				foreach ( $row['node_options'] as $opt_row ) {
					$opt = array(
						'label'     => $opt_row['option_label'] ?? '',
						'value'     => $opt_row['option_value'] ?? '',
					);

					$next = trim( $opt_row['option_next_node'] ?? '' );
					if ( '' !== $next ) {
						$opt['next_node'] = $next;
					}

					$new_options[] = $opt;
				}
				$nodes[ $node_id ]['options'] = $new_options;
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
