<?php
/**
 * Computes a structural diff between two decoded `_ryv_form_schema` arrays,
 * for RYV_Edit_Log's audit trail. Deliberately shallow beyond the node
 * level - it says *which* nodes/fields changed and their old/new values,
 * not a line-level diff of nested arrays like `options`.
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
		$old_nodes = isset( $old['nodes'] ) && is_array( $old['nodes'] ) ? $old['nodes'] : array();
		$new_nodes = isset( $new['nodes'] ) && is_array( $new['nodes'] ) ? $new['nodes'] : array();

		$diff = array();

		$added   = array_values( array_diff( array_keys( $new_nodes ), array_keys( $old_nodes ) ) );
		$removed = array_values( array_diff( array_keys( $old_nodes ), array_keys( $new_nodes ) ) );

		if ( $added ) {
			$diff['nodes_added'] = $added;
		}
		if ( $removed ) {
			$diff['nodes_removed'] = $removed;
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
			$diff['nodes_changed'] = $nodes_changed;
		}

		$settings_changed = array();
		foreach ( array( 'title', 'description', 'entry_node' ) as $key ) {
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
	 * @param array $old_node One node's array from the old schema.
	 * @param array $new_node Same node id's array from the new schema.
	 * @return array Field name => { old, new }.
	 */
	private static function diff_node( array $old_node, array $new_node ): array {
		$changed = array();

		foreach ( array( 'type', 'title', 'step_name', 'content', 'next_node', 'display_style' ) as $field ) {
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
