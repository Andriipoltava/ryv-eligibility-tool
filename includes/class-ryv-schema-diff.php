<?php
/**
 * Computes a structural diff between two decoded `_ryv_form_schema` arrays,
 * for RYV_Edit_Log's audit trail. Deliberately shallow beyond the node
 * level - it says *which* states/nodes/fields changed and their old/new
 * values, not a line-level diff of nested arrays like `options`.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Schema_Diff
 */
class RYV_Schema_Diff {

	/**
	 * @param array $old Previously-stored schema (before this save).
	 * @param array $new Schema being saved.
	 * @return array Empty if nothing changed.
	 */
	public static function compute( array $old, array $new ): array {
		$old_states = isset( $old['states'] ) && is_array( $old['states'] ) ? $old['states'] : array();
		$new_states = isset( $new['states'] ) && is_array( $new['states'] ) ? $new['states'] : array();

		$diff = array();

		$added   = array_values( array_diff( array_keys( $new_states ), array_keys( $old_states ) ) );
		$removed = array_values( array_diff( array_keys( $old_states ), array_keys( $new_states ) ) );

		if ( $added ) {
			$diff['states_added'] = $added;
		}
		if ( $removed ) {
			$diff['states_removed'] = $removed;
		}

		$states_changed = array();
		foreach ( $new_states as $state_id => $new_state ) {
			if ( ! isset( $old_states[ $state_id ] ) || ! is_array( $old_states[ $state_id ] ) || ! is_array( $new_state ) ) {
				continue;
			}

			$state_diff = self::diff_state( $old_states[ $state_id ], $new_state );
			if ( $state_diff ) {
				$states_changed[ $state_id ] = $state_diff;
			}
		}
		if ( $states_changed ) {
			$diff['states_changed'] = $states_changed;
		}

		$settings_changed = array();
		foreach ( array( 'state_display_style', 'state_picker_label' ) as $key ) {
			$old_val = $old[ $key ] ?? null;
			$new_val = $new[ $key ] ?? null;
			if ( $old_val !== $new_val ) {
				$settings_changed[ $key ] = array(
					'old' => $old_val,
					'new' => $new_val,
				);
			}
		}
		if ( $settings_changed ) {
			$diff['form_settings_changed'] = $settings_changed;
		}

		return $diff;
	}

	/**
	 * @param array $old_state One state's array from the old schema.
	 * @param array $new_state Same state id's array from the new schema.
	 * @return array
	 */
	private static function diff_state( array $old_state, array $new_state ): array {
		$old_nodes = isset( $old_state['nodes'] ) && is_array( $old_state['nodes'] ) ? $old_state['nodes'] : array();
		$new_nodes = isset( $new_state['nodes'] ) && is_array( $new_state['nodes'] ) ? $new_state['nodes'] : array();

		$result = array();

		$added   = array_values( array_diff( array_keys( $new_nodes ), array_keys( $old_nodes ) ) );
		$removed = array_values( array_diff( array_keys( $old_nodes ), array_keys( $new_nodes ) ) );

		if ( $added ) {
			$result['nodes_added'] = $added;
		}
		if ( $removed ) {
			$result['nodes_removed'] = $removed;
		}

		$nodes_changed = array();
		foreach ( $new_nodes as $node_id => $new_node ) {
			if ( ! isset( $old_nodes[ $node_id ] ) || ! is_array( $old_nodes[ $node_id ] ) || ! is_array( $new_node ) ) {
				continue;
			}

			$field_diff = self::diff_node( $old_nodes[ $node_id ], $new_node );
			if ( $field_diff ) {
				$nodes_changed[ $node_id ] = $field_diff;
			}
		}
		if ( $nodes_changed ) {
			$result['nodes_changed'] = $nodes_changed;
		}

		foreach ( array( 'label', 'entry_node' ) as $key ) {
			$old_val = $old_state[ $key ] ?? null;
			$new_val = $new_state[ $key ] ?? null;
			if ( $old_val !== $new_val ) {
				$result[ $key ] = array(
					'old' => $old_val,
					'new' => $new_val,
				);
			}
		}

		return $result;
	}

	/**
	 * @param array $old_node One node's array from the old schema.
	 * @param array $new_node Same node id's array from the new schema.
	 * @return array Field name => { old, new }.
	 */
	private static function diff_node( array $old_node, array $new_node ): array {
		$changed = array();

		foreach ( array( 'type', 'title', 'content', 'next_node', 'display_style' ) as $field ) {
			$old_val = $old_node[ $field ] ?? null;
			$new_val = $new_node[ $field ] ?? null;
			if ( $old_val !== $new_val ) {
				$changed[ $field ] = array(
					'old' => $old_val,
					'new' => $new_val,
				);
			}
		}

		// `options` is an array of {value, label, next_node} - compared whole
		// rather than element-by-element, since a reordered/renumbered option
		// list is still meaningful to see as "this changed."
		$old_options = $old_node['options'] ?? null;
		$new_options = $new_node['options'] ?? null;
		if ( wp_json_encode( $old_options ) !== wp_json_encode( $new_options ) ) {
			$changed['options'] = array(
				'old' => $old_options,
				'new' => $new_options,
			);
		}

		return $changed;
	}
}
