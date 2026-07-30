<?php
/**
 * One-time, lazy migration from the old per-state schema shape
 * ({states: {state_id: {label, entry_node, nodes}}, state_display_style,
 * state_picker_label}) to the current flat DAG ({entry_node, nodes}).
 * Invoked from RYV_CPT::get_schema() the first time a legacy-shaped schema
 * is read, and persisted immediately so it never runs again for that post.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Schema_Migrator
 */
class RYV_Schema_Migrator {

	/**
	 * @param array $old_schema Decoded legacy schema (has a `states` key).
	 * @return array Flat `{entry_node, nodes}` schema.
	 */
	public static function migrate( array $old_schema ): array {
		$states = isset( $old_schema['states'] ) && is_array( $old_schema['states'] ) ? $old_schema['states'] : array();

		$nodes         = array();
		$state_options = array();

		foreach ( $states as $state_id => $state ) {
			if ( ! is_array( $state ) ) {
				continue;
			}

			$state_nodes = isset( $state['nodes'] ) && is_array( $state['nodes'] ) ? $state['nodes'] : array();

			// Node ids were only ever unique *within* one state - two
			// different states could in principle reuse the same generic
			// id. Prefixing makes collisions structurally impossible
			// regardless of what naming convention (if any) the original
			// data followed.
			$prefix = $state_id . '__';
			$id_map = array();
			foreach ( array_keys( $state_nodes ) as $old_id ) {
				$id_map[ $old_id ] = $prefix . $old_id;
			}

			foreach ( $state_nodes as $old_id => $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}

				$new_id = $id_map[ $old_id ];

				if ( ! empty( $node['options'] ) && is_array( $node['options'] ) ) {
					foreach ( $node['options'] as &$option ) {
						if ( isset( $option['next_node'], $id_map[ $option['next_node'] ] ) ) {
							$option['next_node'] = $id_map[ $option['next_node'] ];
						}
					}
					unset( $option );
				} elseif ( isset( $node['next_node'], $id_map[ $node['next_node'] ] ) ) {
					$node['next_node'] = $id_map[ $node['next_node'] ];
				}

				$nodes[ $new_id ] = $node;
			}

			$entry        = $state['entry_node'] ?? null;
			$mapped_entry = ( null !== $entry && isset( $id_map[ $entry ] ) ) ? $id_map[ $entry ] : null;

			$state_options[] = array(
				'value'     => (string) $state_id,
				'label'     => ! empty( $state['label'] ) ? (string) $state['label'] : (string) $state_id,
				'next_node' => $mapped_entry,
			);
		}

		// Matches RYV_API::get_form()'s existing alphabetical-by-label sort,
		// so the state picker's order doesn't silently change for visitors
		// on the first request after this migration runs.
		usort(
			$state_options,
			static function ( $a, $b ) {
				return strcmp( $a['label'], $b['label'] );
			}
		);

		$picker_id = 'select_state';
		while ( isset( $nodes[ $picker_id ] ) ) {
			$picker_id .= '_';
		}

		$nodes[ $picker_id ] = array(
			'type'          => 'radio',
			'title'         => ! empty( $old_schema['state_picker_label'] ) && is_string( $old_schema['state_picker_label'] )
				? $old_schema['state_picker_label']
				: __( 'Select your state', 'ryv-eligibility-tool' ),
			'content'       => '',
			'source_type'   => 'select',
			'display_style' => ! empty( $old_schema['state_display_style'] ) && is_string( $old_schema['state_display_style'] )
				? $old_schema['state_display_style']
				: 'buttons',
			'options'       => $state_options,
		);

		return array(
			'entry_node' => $picker_id,
			'nodes'      => $nodes,
		);
	}
}
