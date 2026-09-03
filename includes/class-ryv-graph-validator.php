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
	 *     cycles: array<int, string[]>,
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
			'cycles'         => self::find_cycles( $nodes ),
			'total_nodes'    => count( $nodes ),
		);
	}

	/**
	 * Find every cycle in the graph - a node reachable, by following
	 * `next_node`/`options[].next_node` edges, back to itself. A cyclic
	 * questionnaire re-asks the same question(s) forever (the frontend's
	 * `renderFrom()` cycle guard in engine.js only stops one render pass
	 * from spinning forever; it does nothing to stop the *visitor* clicking
	 * "Continue" back into the same loop indefinitely), so this is worth
	 * surfacing even though - unlike a broken link - it's not necessarily a
	 * mistake (a "you're already registered, come back next year" loop
	 * pointing at itself could in principle be intentional). Warn, don't
	 * block.
	 *
	 * Standard three-colour (white/gray/black) DFS cycle detection: a node
	 * is gray while it's an ancestor of the node currently being explored
	 * (i.e. still on the call stack) and black once fully explored with no
	 * remaining back-edges. An edge into a gray node closes a cycle - the
	 * portion of the stack from that node to the top is the loop itself.
	 * Broken links (target not in `$nodes`) are skipped here; those are
	 * already reported separately by the caller.
	 *
	 * @param array $nodes Full node map.
	 * @return array<int, string[]> One entry per cycle found, each an ordered list of node ids (last one links back to the first).
	 */
	private static function find_cycles( array $nodes ): array {
		$cycles = array();
		$state  = array(); // node_id => 'gray' | 'black'.
		$stack  = array();

		foreach ( array_keys( $nodes ) as $start ) {
			if ( ! isset( $state[ $start ] ) ) {
				self::walk_for_cycles( $start, $nodes, $state, $stack, $cycles );
			}
		}

		return $cycles;
	}

	/**
	 * @param string $node_id Current node.
	 * @param array  $nodes   Full node map.
	 * @param array  $state   node_id => 'gray'|'black' (by reference).
	 * @param array  $stack   Current DFS path (by reference).
	 * @param array  $cycles  Accumulator (by reference).
	 */
	private static function walk_for_cycles( string $node_id, array $nodes, array &$state, array &$stack, array &$cycles ): void {
		$state[ $node_id ] = 'gray';
		$stack[]            = $node_id;

		foreach ( self::outgoing_targets( $nodes[ $node_id ] ?? array(), $nodes ) as $target ) {
			if ( 'gray' === ( $state[ $target ] ?? null ) ) {
				$pos        = array_search( $target, $stack, true );
				$cycles[] = false === $pos ? array( $target ) : array_slice( $stack, $pos );
			} elseif ( ! isset( $state[ $target ] ) ) {
				self::walk_for_cycles( $target, $nodes, $state, $stack, $cycles );
			}
		}

		array_pop( $stack );
		$state[ $node_id ] = 'black';
	}

	/**
	 * @param array $node  A single node.
	 * @param array $nodes Full node map, to skip already-broken links (reported separately).
	 * @return string[] Node ids this node routes to.
	 */
	private static function outgoing_targets( array $node, array $nodes ): array {
		$targets = array();

		if ( ! empty( $node['options'] ) && is_array( $node['options'] ) ) {
			foreach ( $node['options'] as $option ) {
				$target = $option['next_node'] ?? null;
				if ( $target && isset( $nodes[ $target ] ) ) {
					$targets[] = $target;
				}
			}
			return $targets;
		}

		$target = $node['next_node'] ?? null;
		if ( $target && isset( $nodes[ $target ] ) ) {
			$targets[] = $target;
		}

		return $targets;
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
