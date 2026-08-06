<?php
/**
 * Converts a RYV flat decision graph back into a Drupal Webform-shaped YAML
 * export - the inverse direction of RYV_YAML_Parser.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Symfony\Component\Yaml\Yaml;

/**
 * Class RYV_Drupal_YAML_Exporter
 *
 * Reconstructs #states-gated Drupal containers/elements from a flat RYV
 * graph, so the result both re-imports cleanly through the EXISTING,
 * unmodified RYV_YAML_Parser and is plausible as a real Drupal Webform
 * config export.
 *
 * This is only ever a best-effort reversal, and is explicitly scoped to
 * forms that were originally imported via RYV_YAML_Parser and have since
 * been only lightly edited (title/content/option tweaks, renames) - not
 * arbitrary graphs authored purely in the admin builder. Two fields the
 * importer writes and nothing else ever touches make this possible:
 * `node.group` (which top-level Drupal container a node descended from -
 * see RYV_YAML_Parser::flatten()'s docblock) and `node.source_type` (the
 * exact original Drupal `#type`). Neither is guaranteed present or accurate
 * for a node created fresh in the builder - see build_base_element() and
 * validate_positional_edges() for how that's detected and flagged rather
 * than silently mishandled.
 *
 * Key asymmetry versus the forward parser: a CHOICE node's (radio/checkbox)
 * outgoing edges can always be reconstructed as explicit `#states.visible`
 * gates on the target, regardless of document position - see
 * build_states_visible(). A NON-choice node's (text/textarea/markup)
 * outgoing `next_node` has no such mechanism in Drupal's own model
 * (RYV_YAML_Parser only ever assigns it from document-order fallthrough,
 * never from a gate) - it can ONLY be encoded by placing the target
 * immediately after it within the same container, which is why grouping
 * nodes back into their original `#type: container` (keyed by `group`) is
 * load-bearing here, not cosmetic. validate_positional_edges() checks this
 * holds for the schema being exported and warns (never silently drops)
 * whenever it doesn't.
 *
 * Container-level `#states` (the gate a real Drupal export often ALSO
 * restates on the container itself, redundantly, alongside its own leaves'
 * gates - see RYV_YAML_Parser::flatten()'s docblock) is never reconstructed
 * here; every gate is written directly on the leaf that needs it, which
 * RYV_YAML_Parser resolves identically either way.
 *
 * One residual, UNDETECTABLE-at-export-time mismatch class, found via a
 * real 466-node round-trip test: RYV_YAML_Parser::build_subtree() tracks a
 * `$declared_conditions` map - which upstream condition(s) a node was
 * originally gated on, recorded even for a node that went on to LOSE that
 * gate slot to a duplicate-condition collision (see build_subtree()'s own
 * docblock) - purely as a transient, to bias find_default_next()'s
 * sibling-branch search for OTHER nodes nearby. That bias is never
 * persisted anywhere in the flat schema this class reads from, so a
 * markup/text node positioned immediately after one of these
 * gate-collision losers can occasionally resolve to a different (or no)
 * `next_node` on re-import than it originally had. Confirmed rare in
 * practice (6 of 466 nodes in the real `voting_rights` export, all
 * adjacent to a duplicate-#states-gate collision, zero new broken_links)
 * and inherent to what the schema retains - not fixable without storing
 * information RYV_YAML_Parser itself already discards.
 */
class RYV_Drupal_YAML_Exporter {

	/**
	 * Synthetic Drupal `#type` used to re-wrap a group of nodes that shared
	 * one `node.group` value - see RYV_YAML_Parser::CONTAINER_TYPES.
	 */
	const CONTAINER_TYPE = 'container';

	/**
	 * Fallback Drupal `#type` per RYV node type, used only when a node has
	 * no `source_type` (or it's the 'unsupported' import sentinel) - i.e. a
	 * node created directly in the builder, never round-tripped through
	 * RYV_YAML_Parser. Always flagged in `warnings` when used - see
	 * build_base_element().
	 */
	const HEURISTIC_FALLBACK_TYPE = array(
		'radio'    => 'webform_buttons',
		'checkbox' => 'checkboxes',
		'text'     => 'textfield',
		'textarea' => 'textarea',
		'markup'   => 'processed_text',
	);

	/**
	 * RYV node types that route via `options[].next_node` (a gate per
	 * option value) rather than a single node-level `next_node` (positional
	 * fallthrough only) - mirrors RYV_YAML_Parser::build_subtree()'s own
	 * branch on `! empty( $nodes[$id]['options'] )`.
	 */
	const CHOICE_TYPES = array( 'radio', 'checkbox' );

	/**
	 * Build the Drupal-shaped YAML export for one form's schema.
	 *
	 * @param array  $schema      {entry_node, nodes} - RYV_CPT::get_schema()'s shape.
	 * @param string $title       Form's post_title, applied to the export's own `title:`.
	 * @param string $description Form's post_excerpt, applied to the export's own `description:`.
	 * @param int    $post_id     Source post ID, used only to synthesize a stable-looking `id:`.
	 * @return array{yaml:string,warnings:string[]}
	 * @throws RuntimeException If the Yaml component isn't available.
	 */
	public function export( array $schema, string $title, string $description, int $post_id ): array {
		if ( ! class_exists( Yaml::class ) ) {
			throw new RuntimeException( 'Symfony Yaml component is not available. Run "composer install" in the plugin root.' );
		}

		$nodes    = $schema['nodes'] ?? array();
		$entry_id = $schema['entry_node'] ?? null;
		$warnings = array();

		if ( empty( $nodes ) || null === $entry_id || ! isset( $nodes[ $entry_id ] ) ) {
			return array(
				'yaml'     => $this->wrap_document( array(), $title, $description, $post_id ),
				'warnings' => array( 'Form has no nodes (or no valid entry_node) to export.' ),
			);
		}

		$incoming = $this->build_incoming_index( $nodes );
		$groups   = $this->bucket_and_order( $nodes );

		$defs_by_id = array();
		foreach ( $nodes as $id => $node ) {
			$defs_by_id[ $id ] = $this->build_base_element( (string) $id, $node, $warnings );
		}

		foreach ( $nodes as $id => $node ) {
			$conditions = $incoming[ $id ] ?? array();

			if ( ! empty( $conditions ) ) {
				$defs_by_id[ $id ]['#states'] = $this->build_states_visible( $conditions );
			}
		}

		$this->apply_reference_nodes( $nodes, $incoming, $defs_by_id, $warnings );
		$this->apply_hidden( $nodes, $defs_by_id, $warnings );
		$this->validate_positional_edges( $nodes, $groups, $warnings );

		$elements_tree = $this->assemble_elements( $nodes, (string) $entry_id, $groups, $defs_by_id );

		return array(
			'yaml'     => $this->wrap_document( $elements_tree, $title, $description, $post_id ),
			'warnings' => $warnings,
		);
	}

	/**
	 * Map every node to every `(source_id, value)` pair under which some
	 * OTHER node's option routes to it - the inverse of
	 * RYV_YAML_Parser::build_subtree()'s `$gates_by_source`. Feeds
	 * build_states_visible() (direct gates) and apply_reference_nodes()
	 * (which needs an owning choice node's OWN incoming condition(s) to
	 * copy onto its reference nodes).
	 *
	 * @param array $nodes Schema nodes, id => node.
	 * @return array<string,array<int,array{0:string,1:string}>> target id => deduped [source_id, value] pairs.
	 */
	private function build_incoming_index( array $nodes ): array {
		$incoming = array();

		foreach ( $nodes as $source_id => $node ) {
			if ( empty( $node['options'] ) || ! is_array( $node['options'] ) ) {
				continue;
			}

			foreach ( $node['options'] as $option ) {
				$target = $option['next_node'] ?? null;

				if ( empty( $target ) || ! isset( $nodes[ $target ] ) ) {
					continue;
				}

				$incoming[ $target ][] = array( (string) $source_id, (string) ( $option['value'] ?? '' ) );
			}
		}

		foreach ( $incoming as $target => $pairs ) {
			$seen   = array();
			$unique = array();

			foreach ( $pairs as $pair ) {
				$signature = $pair[0] . '|' . $pair[1];

				if ( isset( $seen[ $signature ] ) ) {
					continue;
				}

				$seen[ $signature ] = true;
				$unique[]           = $pair;
			}

			$incoming[ $target ] = $unique;
		}

		return $incoming;
	}

	/**
	 * Bucket nodes by `node.group` (missing/empty treated as `'general'`,
	 * matching the builder's own NodeList.js fallback), preserving each
	 * bucket's members in overall schema order.
	 *
	 * @param array $nodes Schema nodes, id => node.
	 * @return array{general:string[],by_group:array<string,string[]>,group_order:string[]}
	 */
	private function bucket_and_order( array $nodes ): array {
		$general     = array();
		$by_group    = array();
		$group_order = array();

		foreach ( $nodes as $id => $node ) {
			$group = $this->group_of( $node );

			if ( 'general' === $group ) {
				$general[] = (string) $id;
				continue;
			}

			if ( ! isset( $by_group[ $group ] ) ) {
				$by_group[ $group ] = array();
				$group_order[]      = $group;
			}

			$by_group[ $group ][] = (string) $id;
		}

		return array(
			'general'     => $general,
			'by_group'    => $by_group,
			'group_order' => $group_order,
		);
	}

	/**
	 * @param array $node One schema node.
	 * @return string
	 */
	private function group_of( array $node ): string {
		$group = $node['group'] ?? '';
		return ( '' !== $group ) ? (string) $group : 'general';
	}

	/**
	 * Build one node's Drupal element def, everything except `#states`
	 * (added separately once every node's def exists, since reference-node
	 * gates need to point at defs that already exist - see apply_reference_nodes()).
	 *
	 * @param string   $id       Node id.
	 * @param array    $node     Node definition.
	 * @param string[] $warnings Accumulator.
	 * @return array
	 */
	private function build_base_element( string $id, array $node, array &$warnings ): array {
		$ryv_type    = $node['type'] ?? 'unsupported';
		$source_type = $node['source_type'] ?? null;

		if ( ! empty( $source_type ) && 'unsupported' !== $source_type ) {
			$drupal_type = $source_type;
		} else {
			$drupal_type = self::HEURISTIC_FALLBACK_TYPE[ $ryv_type ] ?? 'webform_markup';

			if ( 'radio' === $ryv_type && 'dropdown' === ( $node['display_style'] ?? '' ) ) {
				$drupal_type = 'select';
			}

			$warnings[] = "Node '{$id}': no recorded source_type - guessed Drupal #type '{$drupal_type}'. This node was likely created directly in the builder rather than imported; review before relying on this file for a real Drupal import.";
		}

		$def = array( '#type' => $drupal_type );

		if ( ! empty( $node['title'] ) ) {
			$def['#title'] = $node['title'];
		}

		if ( ! empty( $node['options'] ) && in_array( $ryv_type, self::CHOICE_TYPES, true ) ) {
			$options = array();

			foreach ( $node['options'] as $option ) {
				$options[ (string) ( $option['value'] ?? '' ) ] = $option['label'] ?? '';
			}

			$def['#options'] = $options;
		}

		if ( ! empty( $node['help'] ) ) {
			$def['#help'] = $node['help'];
		}

		if ( 'markup' === $ryv_type ) {
			$content = $node['content'] ?? '';

			if ( 'processed_text' === $drupal_type ) {
				$def['#text']   = $content;
				$def['#format'] = 'basic_html';
			} else {
				$def['#markup'] = $content;
			}

			if ( ! empty( $node['wrapper_class'] ) ) {
				$def['#wrapper_attributes'] = array(
					'class' => preg_split( '/\s+/', trim( (string) $node['wrapper_class'] ) ),
				);
			}
		}

		if ( 'select' === $drupal_type && ! empty( $node['placeholder'] ) ) {
			$def['#empty_option'] = $node['placeholder'];
		}

		if ( ! empty( $node['select2'] ) ) {
			$def['#select2'] = true;
		}

		return $def;
	}

	/**
	 * One `{ ':input[name="x"]': { value: y } }` condition.
	 *
	 * @param array{0:string,1:string} $pair [source_id, value].
	 * @return array
	 */
	private function format_condition( array $pair ): array {
		return array(
			sprintf( ':input[name="%s"]', $pair[0] ) => array( 'value' => $pair[1] ),
		);
	}

	/**
	 * Build a `#states` array with just a `visible` key from one or more
	 * resolved incoming conditions - a single condition becomes the plain
	 * dict shape, two or more become a pure-OR sequential list (the only
	 * compound shape RYV_YAML_Parser::resolve_condition_list() fully
	 * resolves, regardless of how many distinct source nodes are involved).
	 *
	 * @param array<int,array{0:string,1:string}> $conditions Deduped [source_id, value] pairs.
	 * @return array
	 */
	private function build_states_visible( array $conditions ): array {
		if ( 1 === count( $conditions ) ) {
			return array( 'visible' => $this->format_condition( $conditions[0] ) );
		}

		$list = array();

		foreach ( $conditions as $index => $condition ) {
			if ( $index > 0 ) {
				$list[] = 'or';
			}

			$list[] = $this->format_condition( $condition );
		}

		return array( 'visible' => $list );
	}

	/**
	 * Give every markup node collected in some choice node's
	 * `reference_node_ids` the same `#states.visible` condition as that
	 * choice node's own first resolved incoming condition - the inverse of
	 * RYV_YAML_Parser::build_subtree()'s duplicate-gate-collision handling
	 * (see that method's docblock). Only the FIRST condition is copied when
	 * the owner has more than one (an OR chain) - which of several original
	 * slots a given reference node collided on isn't recoverable from the
	 * flat schema, so this is a documented best-effort default, not a fully
	 * lossless reversal.
	 *
	 * Also asserts the reference node is positioned AFTER its owner in
	 * document order, since the collision-to-reference conversion on
	 * re-import only fires when the SECOND-encountered element of a
	 * colliding pair is markup and the FIRST is the choice node - if a
	 * builder edit reordered them, this can no longer round-trip correctly
	 * and is flagged rather than silently emitted wrong.
	 *
	 * @param array                                        $nodes      Schema nodes, id => node.
	 * @param array<string,array<int,array{0:string,1:string}>> $incoming   From build_incoming_index().
	 * @param array                                        $defs_by_id Element defs, id => def (mutated in place).
	 * @param string[]                                     $warnings   Accumulator.
	 */
	private function apply_reference_nodes( array $nodes, array $incoming, array &$defs_by_id, array &$warnings ): void {
		$position = array_flip( array_map( 'strval', array_keys( $nodes ) ) );

		foreach ( $nodes as $owner_id => $node ) {
			if ( empty( $node['reference_node_ids'] ) || ! is_array( $node['reference_node_ids'] ) ) {
				continue;
			}

			$owner_id    = (string) $owner_id;
			$owner_pairs = $incoming[ $owner_id ] ?? array();

			if ( empty( $owner_pairs ) ) {
				$warnings[] = "Node '{$owner_id}': has reference_node_ids ('" . implode( "', '", $node['reference_node_ids'] ) . "') but no reconstructable incoming #states condition of its own to copy onto them - exported with no gate, so they'll be unconditionally visible on re-import instead of tied to '{$owner_id}'.";
				continue;
			}

			$condition = $owner_pairs[0];

			foreach ( $node['reference_node_ids'] as $ref_id ) {
				$ref_id = (string) $ref_id;

				if ( ! isset( $defs_by_id[ $ref_id ] ) ) {
					$warnings[] = "Node '{$owner_id}': reference_node_ids includes '{$ref_id}', which no longer exists in this form's nodes - skipped.";
					continue;
				}

				if ( isset( $position[ $ref_id ], $position[ $owner_id ] ) && $position[ $ref_id ] < $position[ $owner_id ] ) {
					$warnings[] = "Node '{$owner_id}': reference node '{$ref_id}' now appears BEFORE it in document order (likely moved by a builder edit) - the #states-collision that re-creates this reference relationship on import only resolves correctly when the choice node comes first, so this file will not reconstruct '{$ref_id}' as reference content and may route it differently instead.";
				}

				$defs_by_id[ $ref_id ]['#states'] = array( 'visible' => $this->format_condition( $condition ) );
			}
		}
	}

	/**
	 * Pair a `hidden: true` node's already-reconstructed `#states.visible`
	 * with a bare `invisible` rule, so RYV_YAML_Parser::build_subtree()'s
	 * hidden-flag check (a plain `isset($states['visible']) &&
	 * isset($states['invisible'])`, which never inspects `invisible`'s
	 * actual contents) sets `hidden: true` again on re-import. A node
	 * marked hidden with no reconstructable `visible` condition has nothing
	 * to pair - exported without the flag, warned, rather than fabricating
	 * a fake gate.
	 *
	 * @param array $nodes      Schema nodes, id => node.
	 * @param array $defs_by_id Element defs, id => def (mutated in place).
	 * @param string[] $warnings Accumulator.
	 */
	private function apply_hidden( array $nodes, array &$defs_by_id, array &$warnings ): void {
		foreach ( $nodes as $id => $node ) {
			if ( empty( $node['hidden'] ) ) {
				continue;
			}

			$id = (string) $id;

			if ( ! empty( $defs_by_id[ $id ]['#states']['visible'] ) ) {
				$defs_by_id[ $id ]['#states']['invisible'] = array();
			} else {
				$warnings[] = "Node '{$id}': marked hidden but has no reconstructable #states.visible condition to pair an invisible rule with - exported WITHOUT the hidden flag; it will render normally (not hidden) if this file is re-imported.";
			}
		}
	}

	/**
	 * A non-choice node's `next_node` has no gate mechanism - it can only
	 * be encoded by document position (see this class's own docblock).
	 * Warns (never silently drops) whenever a node's `next_node` isn't
	 * exactly the next member of its own container in the chosen emission
	 * order, or the node has no container ('general' group) at all - both
	 * mean this edge cannot survive the export and the re-imported file
	 * will diverge from the live form for that branch.
	 *
	 * @param array $nodes    Schema nodes, id => node.
	 * @param array $groups   From bucket_and_order().
	 * @param string[] $warnings Accumulator.
	 */
	private function validate_positional_edges( array $nodes, array $groups, array &$warnings ): void {
		$position_in_group = array();

		foreach ( $groups['by_group'] as $group => $members ) {
			foreach ( $members as $index => $id ) {
				$position_in_group[ $id ] = array( $index, $members );
			}
		}

		foreach ( $nodes as $id => $node ) {
			$type = $node['type'] ?? '';
			$next = $node['next_node'] ?? null;

			if ( in_array( $type, self::CHOICE_TYPES, true ) || empty( $next ) ) {
				continue;
			}

			$id    = (string) $id;
			$next  = (string) $next;
			$group = $this->group_of( $node );

			if ( 'general' === $group ) {
				$warnings[] = "Node '{$id}': has no container group and a next_node ('{$next}') - non-choice edges can only be encoded by position within a shared container, so this edge will be LOST on re-import. Likely a node added directly in the builder rather than via YAML import.";
				continue;
			}

			if ( ! isset( $position_in_group[ $id ] ) ) {
				continue;
			}

			list( $index, $members ) = $position_in_group[ $id ];
			$expected_next = $members[ $index + 1 ] ?? null;

			if ( $expected_next !== $next ) {
				$destination = $expected_next ? "'{$expected_next}'" : 'nothing (end of branch)';
				$warnings[]  = "Node '{$id}': next_node ('{$next}') is not the element immediately following it in container '{$group}' - Drupal-format export can only encode this edge by position, so re-importing this file will route '{$id}' to {$destination} instead. The live form differs from the exported file for this branch.";
			}
		}
	}

	/**
	 * Assemble the final Drupal element tree: the entry node placed first
	 * (RYV_YAML_Parser sets `entry_node` to the very first top-level
	 * element it encounters), every other `'general'`-group node inline at
	 * its original position, and every other group wrapped in a synthetic
	 * `#type: container` the first time one of its members is reached.
	 *
	 * @param array  $nodes      Schema nodes, id => node.
	 * @param string $entry_id   Schema's entry_node id.
	 * @param array  $groups     From bucket_and_order().
	 * @param array  $defs_by_id Fully-built element defs, id => def.
	 * @return array
	 */
	private function assemble_elements( array $nodes, string $entry_id, array $groups, array $defs_by_id ): array {
		$elements = array();

		if ( isset( $defs_by_id[ $entry_id ] ) ) {
			$elements[ $entry_id ] = $defs_by_id[ $entry_id ];
		}

		$emitted_group = array();

		foreach ( $nodes as $id => $node ) {
			$id = (string) $id;

			if ( $id === $entry_id ) {
				continue;
			}

			$group = $this->group_of( $node );

			if ( 'general' === $group ) {
				$elements[ $id ] = $defs_by_id[ $id ];
				continue;
			}

			if ( isset( $emitted_group[ $group ] ) ) {
				continue;
			}

			$emitted_group[ $group ] = true;

			$container = array( '#type' => self::CONTAINER_TYPE );

			foreach ( $groups['by_group'][ $group ] as $member_id ) {
				$container[ $member_id ] = $defs_by_id[ $member_id ];
			}

			$elements[ $group ] = $container;
		}

		return $elements;
	}

	/**
	 * Wrap an element tree in the full Drupal config-export envelope
	 * (uuid/langcode/status/... /elements) so the file is plausible as a
	 * real Drupal Webform import, not just a bare element map - and
	 * double-encode `elements:` as a YAML string, matching real exports and
	 * RYV_YAML_Parser::resolve_elements_root()'s expected shape (which also
	 * accepts a plain array here, but the double-encoded string is what a
	 * real Drupal config export actually produces).
	 *
	 * @param array  $elements_tree Built by assemble_elements().
	 * @param string $title         Form title.
	 * @param string $description   Form description.
	 * @param int    $post_id       Source post ID, for a stable-looking synthetic `id:`.
	 * @return string
	 */
	private function wrap_document( array $elements_tree, string $title, string $description, int $post_id ): string {
		$elements_yaml = Yaml::dump( $elements_tree, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK );

		$doc = array(
			'uuid'         => wp_generate_uuid4(),
			'langcode'     => 'en',
			'status'       => 'open',
			'dependencies' => array(),
			'weight'       => 0,
			'open'         => null,
			'close'        => null,
			'uid'          => 1,
			'template'     => false,
			'archive'      => false,
			'id'           => 'ryv_export_' . $post_id,
			'title'        => $title,
			'description'  => $description,
			'categories'   => array(),
			'elements'     => $elements_yaml,
		);

		return Yaml::dump( $doc, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK );
	}
}
