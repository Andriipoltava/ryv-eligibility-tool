<?php
/**
 * Structural health check for a `{ entry_node, nodes }` decision graph -
 * independent of any one import path, so it can run against a freshly
 * parsed YAML result, a stored `_ryv_form_schema`, or a builder SPA save.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Graph_Validator
 */
class RYV_Graph_Validator {

	/**
	 * Find broken `next_node` links and orphaned (unreachable) nodes.
	 *
	 * A link is "broken" if it names a node id that isn't in `nodes` at all -
	 * this can only happen from hand-edited YAML or a parser bug, since
	 * RYV_YAML_Parser only ever routes to ids it just generated itself, and
	 * the builder SPA's `next_node` `<select>` only ever offers ids that
	 * already exist (see CLAUDE.md's admin builder section).
	 *
	 * A node is "orphaned" if nothing in the graph - including `entry_node`
	 * itself - ever points to it, i.e. a real visitor can never reach it by
	 * walking the graph from the start.
	 *
	 * @param array $schema { entry_node: string|null, nodes: array }.
	 * @return array{
	 *     broken_links: array<int, array{node_id:string, context:string, option_value:string|null, target:string}>,
	 *     orphaned_nodes: string[],
	 *     total_nodes: int,
	 * }
	 */
	public static function analyze( array $schema ): array {
		$nodes      = is_array( $schema['nodes'] ?? null ) ? $schema['nodes'] : array();
		$entry_node = $schema['entry_node'] ?? null;

		$broken_links = array();
		$referenced   = array();

		foreach ( $nodes as $node_id => $node ) {
			if ( ! empty( $node['options'] ) && is_array( $node['options'] ) ) {
				foreach ( $node['options'] as $option ) {
					self::check_link(
						$node_id,
						'option',
						$option['value'] ?? null,
						$option['next_node'] ?? null,
						$nodes,
						$broken_links,
						$referenced
					);
				}
				continue;
			}

			if ( array_key_exists( 'next_node', $node ) ) {
				self::check_link( $node_id, 'next_node', null, $node['next_node'], $nodes, $broken_links, $referenced );
			}
		}

		$orphaned = array();
		foreach ( array_keys( $nodes ) as $node_id ) {
			if ( $node_id === $entry_node ) {
				continue;
			}

			if ( empty( $referenced[ $node_id ] ) ) {
				$orphaned[] = $node_id;
			}
		}

		return array(
			'broken_links'   => $broken_links,
			'orphaned_nodes' => $orphaned,
			'total_nodes'    => count( $nodes ),
		);
	}

	/**
	 * Record one outgoing link as either broken (target missing) or a valid
	 * reference (target exists, marks it reachable).
	 *
	 * @param string      $node_id      Source node id.
	 * @param string      $context      'option' | 'next_node'.
	 * @param string|null $option_value The option's own value, when $context is 'option'.
	 * @param mixed       $target       The linked-to node id, or null/'' for "no link".
	 * @param array       $nodes        The full node map, for existence checks.
	 * @param array       $broken_links Accumulator (by reference).
	 * @param array       $referenced   Accumulator: target node id => true (by reference).
	 */
	private static function check_link( string $node_id, string $context, ?string $option_value, $target, array $nodes, array &$broken_links, array &$referenced ): void {
		if ( null === $target || '' === $target ) {
			return;
		}

		if ( ! isset( $nodes[ $target ] ) ) {
			$broken_links[] = array(
				'node_id'      => $node_id,
				'context'      => $context,
				'option_value' => $option_value,
				'target'       => (string) $target,
			);
			return;
		}

		$referenced[ $target ] = true;
	}
}
