<?php
/**
 * Converts a Drupal Webform element-tree YAML export into the RYV flat
 * decision-graph format.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Symfony\Component\Yaml\Yaml;

/**
 * Class RYV_YAML_Parser
 *
 * Drupal webforms are implicitly linear: elements render in document order
 * and branching is expressed indirectly via `#states.visible` conditions on
 * *other* elements. This class reconstructs that as an explicit graph: each
 * choice option gets a `next_node`, defaulting to "next element in document
 * order" unless a downstream element is conditionally gated on that specific
 * option's value, in which case the option routes straight to it.
 *
 * The whole document - including what used to be treated as a special
 * "state selector" - is flattened into ONE graph via build_subtree(). A
 * gated container (e.g. RYV's real exports: ~50 sibling `container`
 * elements, each visible only when a particular radio/select answer is
 * given) never becomes a node itself; its own #states gate is instead
 * carried down onto the first real leaf it produces, via flatten()'s
 * $container_gates side channel - see that method's docblock for exactly
 * why a side channel rather than mutating the leaf.
 *
 * Anything the translator can't confidently resolve (unmapped element types,
 * optgroups, etc.) is still imported - never silently dropped - but flagged
 * in `warnings` for manual follow-up in the form builder.
 *
 * Compound `#states` rules (Drupal's array-of-conditions-plus-'and'/'or'-
 * operator form, or a dict with more than one selector key) get the same
 * treatment, with one refinement: a rule that's a pure OR chain - even one
 * spanning several different upstream questions - maps exactly onto this
 * parser's existing single-edge-per-option gate model (see
 * resolve_condition_list()), so those are fully resolved into real gates,
 * not just flagged. Only a rule that requires two conditions to hold AT THE
 * SAME TIME (AND, anywhere in the chain) falls back to linear placement,
 * because a single graph edge can only ever depend on the ONE upstream
 * node's own answer that leads to it - see resolve_condition_dict()'s
 * docblock for why that's a real modeling limit, not just an unimplemented
 * case. Every unresolved rule's full condition list is captured
 * structurally in `review` (see parse()), not just as a warning string, so
 * an admin can see exactly what to wire up by hand.
 */
class RYV_YAML_Parser {

	/**
	 * Drupal #type => RYV node type.
	 */
	const TYPE_MAP = array(
		'radios'         => 'radio',
		'select'         => 'radio',
		'webform_buttons' => 'radio',
		'checkboxes'     => 'checkbox',
		'checkbox'       => 'checkbox',
		'textfield'      => 'text',
		'email'          => 'text',
		'tel'            => 'text',
		'number'         => 'text',
		'textarea'       => 'textarea',
		'webform_markup' => 'markup',
		'markup'         => 'markup',
		'processed_text' => 'markup',
	);

	/**
	 * Drupal #types treated as grouping containers - not imported as nodes
	 * themselves, their children are recursively flattened in place.
	 */
	const CONTAINER_TYPES = array(
		'webform_wizard_page',
		'fieldset',
		'details',
		'container',
		'webform_flexbox',
		'webform_section',
	);

	/**
	 * Drupal #types that carry no eligibility logic and are dropped
	 * entirely (quietly, no warning needed).
	 */
	const SKIP_TYPES = array( 'actions', 'webform_actions' );

	/**
	 * Drupal's built-in named/shared option sets this parser can resolve
	 * directly. `#options` is normally an inline `value: label` map, but
	 * Drupal also lets an element reference a reusable, separately-managed
	 * set by NAME instead (`#options: yes_no`) - only the name ends up in a
	 * webform's own YAML export; the set's actual value/label pairs live in
	 * a different config entity (`webform_options.*.yml`) this parser never
	 * sees. Only 'yes_no' is resolved - it's a stable Drupal core constant
	 * (confirmed against RYV's real exports, which also spell it out inline
	 * elsewhere as exactly `Yes: Yes, No: No`) - but this stays a lookup
	 * table rather than one hardcoded case so a future export referencing
	 * another core set has somewhere to go; see build_node()'s handling of
	 * an unrecognized reference for what happens otherwise.
	 */
	const NAMED_OPTION_SETS = array(
		'yes_no' => array(
			array(
				'value' => 'Yes',
				'label' => 'Yes',
			),
			array(
				'value' => 'No',
				'label' => 'No',
			),
		),
	);

	/**
	 * Read and YAML-decode a file on disk.
	 *
	 * @param string $file_path Absolute path to the uploaded .yml/.yaml file.
	 * @return array Decoded top-level YAML structure.
	 * @throws RuntimeException If the Yaml component isn't available or the file can't be parsed.
	 */
	public static function parse_file( string $file_path ): array {
		if ( ! class_exists( Yaml::class ) ) {
			throw new RuntimeException( 'Symfony Yaml component is not available. Run "composer install" in the plugin root.' );
		}

		$decoded = Yaml::parseFile( $file_path );

		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( 'The uploaded file did not decode to a YAML mapping/list.' );
		}

		return $decoded;
	}

	/**
	 * Parse a decoded Drupal webform structure into one flat decision graph.
	 *
	 * @param array $parsed Output of parse_file() / Yaml::parse().
	 * @return array {
	 *     @type string|null $title       The webform's own root `title:`, if present.
	 *     @type string|null $description The webform's own root `description:`, if present.
	 *     @type string|null $entry_node  First node id in document order, or null if the file had no elements.
	 *     @type array        $nodes      node_id => { type, title, content, source_type, options[], next_node? }.
	 *     @type string[]     $warnings   Ambiguous/unsupported constructs, for manual follow-up.
	 *     @type string[]     $skipped    Element keys dropped entirely (e.g. submit actions).
	 *     @type array[]      $review     Structured detail for every element left linear because of an
	 *                                    unresolvable compound `#states` rule - see build_review_entry().
	 *                                    A strict subset of `warnings` (every entry here also has a
	 *                                    matching warning string); this is the machine-readable form of
	 *                                    the same fact, for rendering an actual review UI instead of
	 *                                    just a string dump.
	 * }
	 */
	public function parse( array $parsed ): array {
		$warnings = array();
		$skipped  = array();
		$review   = array();

		$title       = isset( $parsed['title'] ) && is_string( $parsed['title'] ) ? $parsed['title'] : null;
		$description = isset( $parsed['description'] ) && is_string( $parsed['description'] ) ? $parsed['description'] : null;

		$elements_root = $this->resolve_elements_root( $parsed );
		$subtree       = $this->build_subtree( $elements_root, $warnings, $skipped, $review );

		return array(
			'title'       => $title,
			'description' => $description,
			'entry_node'  => $subtree['entry_node'],
			'nodes'       => $subtree['nodes'],
			'warnings'    => $warnings,
			'skipped'     => $skipped,
			'review'      => $review,
		);
	}

	/**
	 * Extract a container element's non-#-prefixed keys, i.e. its child
	 * element definitions.
	 *
	 * @param array $def Container element definition.
	 * @return array Child element definitions keyed by machine name.
	 */
	private function container_children( array $def ): array {
		return array_filter(
			$def,
			static function ( $value, $prop_key ) {
				return is_array( $value ) && '#' !== substr( (string) $prop_key, 0, 1 );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Build one flat, routed node graph from the whole document's top-level
	 * element definitions. Document-order linear fallback, overridden per
	 * option by any downstream #states gate (including gates that lived on
	 * a container rather than a leaf - see flatten()).
	 *
	 * @param array    $elements Element definitions keyed by machine name.
	 * @param string[] $warnings Accumulator.
	 * @param string[] $skipped  Accumulator.
	 * @param array[]  $review   Accumulator - see parse()'s docblock.
	 * @return array{entry_node:?string,nodes:array}
	 */
	private function build_subtree( array $elements, array &$warnings, array &$skipped, array &$review ): array {
		$ordered         = array();
		$container_gates = array(); // "source_id|value" => target_id, from container-level #states gates (see flatten()).
		$this->flatten( $elements, $ordered, $warnings, $skipped, $review, $container_gates, null, null );

		// Two different branches of the document could in principle produce
		// the same sanitized id (a generic Drupal machine name reused
		// elsewhere) - never let a later one silently overwrite an earlier
		// node. The first occurrence in document order keeps its id (so any
		// container gate already pointing at it - see flatten() - stays
		// valid); only later duplicates are renamed and flagged.
		$seen_ids = array();
		foreach ( $ordered as $index => $item ) {
			$id = $item['id'];

			if ( isset( $seen_ids[ $id ] ) ) {
				++$seen_ids[ $id ];
				$new_id     = $id . '_' . $seen_ids[ $id ];
				$warnings[] = "Element '{$id}': duplicate id - renamed to '{$new_id}'.";
				$ordered[ $index ]['id'] = $new_id;
			} else {
				$seen_ids[ $id ] = 1;
			}
		}

		$nodes           = array();
		$gates_by_source = array(); // source_id => [ value => target_id ].

		foreach ( $ordered as $item ) {
			$id   = $item['id'];
			$def  = $item['def'];
			$node = $this->build_node( $id, $def, $warnings );

			// Persisted purely for admin-UI grouping (the builder buckets the
			// node list by this, since a flat 400+-node form has no other
			// browsable structure) - build_subtree()'s own routing logic
			// (find_default_next() etc.) only ever reads $item['group'], not
			// this copy, so this has zero effect on graph behavior.
			$node['group'] = $item['group'];

			$nodes[ $id ] = $node;

			if ( ! empty( $def['#states'] ) && is_array( $def['#states'] ) ) {
				$conditions = $this->extract_visibility_condition( $def['#states'], $id, $warnings, $review );

				foreach ( $conditions ?? array() as $condition ) {
					$gates_by_source[ $condition['source_id'] ][ $condition['value'] ] = $id;
				}
			}
		}

		// Container-derived gates always win for their source/value slot
		// over whatever a leaf's own (often redundant, sometimes unrelated)
		// #states resolved to above - see flatten()'s docblock for why this
		// has to be a side channel rather than mutating the leaf's #states
		// in place.
		foreach ( $container_gates as $slot => $target_id ) {
			list( $source_id, $value ) = explode( '|', $slot, 2 );
			$gates_by_source[ $source_id ][ $value ] = $target_id;
		}

		$ids = array_map(
			static function ( $item ) {
				return $item['id'];
			},
			$ordered
		);

		// Every node also carries the id of whichever TOP-LEVEL source
		// element it descended from (see flatten()'s $group) - e.g. every
		// leaf anywhere inside one state's own container shares that
		// container's group, no matter how deeply nested. A node's default
		// (non-gated) fallthrough must never cross into a *different*
		// group: with every state flattened into one continuous document,
		// simply excluding gate TARGETS isn't enough - most of a state's
		// own content (everything past its first branch point) carries no
		// #states at all, so nothing stops a document-order walk from
		// sailing straight through the end of state A's content and into
		// state B's, e.g. a "you can vote!" result silently auto-advancing
		// into an unrelated state's first question instead of terminating.
		// Bounding the search to "the rest of my own group" reproduces
		// exactly what the old per-state build_subtree() call gave for
		// free by construction (each state only ever saw its own elements).
		$groups = array_map(
			static function ( $item ) {
				return $item['group'];
			},
			$ordered
		);

		foreach ( $ids as $index => $id ) {
			$gated_targets = $gates_by_source[ $id ] ?? array();
			$default_next  = $this->find_default_next( $ids, $groups, $index, $gated_targets );

			if ( ! empty( $nodes[ $id ]['options'] ) ) {
				foreach ( $nodes[ $id ]['options'] as &$option ) {
					$option['next_node'] = $gated_targets[ $option['value'] ] ?? $default_next;
				}
				unset( $option );
			} else {
				$nodes[ $id ]['next_node'] = $default_next;
			}
		}

		// The entry node (the state selector, in RYV's real exports) is a
		// standalone top-level leaf, not nested in any per-state container -
		// flatten() has nothing to group it under but its own id, which would
		// otherwise render as its own singleton "State: ..." bucket in the
		// builder. It isn't really part of any state's group, so give it the
		// same catch-all bucket as any other groupless node instead.
		if ( isset( $ids[0] ) ) {
			$nodes[ $ids[0] ]['group'] = 'general';
		}

		return array(
			'entry_node' => $ids[0] ?? null,
			'nodes'      => $nodes,
		);
	}

	/**
	 * Drupal's config export stores the element tree as a YAML-encoded
	 * *string* under the top-level `elements` key (a block scalar), not as
	 * nested YAML. Unwrap that double-encoding; otherwise assume the
	 * uploaded file already *is* the element tree.
	 *
	 * @param array $parsed Top-level decoded YAML.
	 * @return array Element definitions keyed by machine name.
	 */
	private function resolve_elements_root( array $parsed ): array {
		if ( isset( $parsed['elements'] ) ) {
			if ( is_string( $parsed['elements'] ) ) {
				$inner = Yaml::parse( $parsed['elements'] );
				return is_array( $inner ) ? $inner : array();
			}

			if ( is_array( $parsed['elements'] ) ) {
				return $parsed['elements'];
			}
		}

		return $parsed;
	}

	/**
	 * Recursively walk element definitions into a single document-ordered
	 * list, descending into container types and dropping skip types.
	 *
	 * A container's own #states gate (e.g. in RYV's real exports: "this
	 * whole per-state block is only visible when the state selector equals
	 * X") can't attach to the container itself - containers never become
	 * nodes - so it's carried down and attached to the first REAL leaf that
	 * container produces instead, via the $container_gates side channel
	 * (keyed "source_id|value" => that leaf's id).
	 *
	 * This is a side channel rather than writing a synthetic #states onto
	 * the leaf because, empirically (checked against both real RYV export
	 * files), a container's first child very often already carries its own
	 * #states - usually a redundant restatement of the exact same
	 * condition, occasionally an unrelated or unsupported one - and
	 * overwriting or fighting over that field is unsafe. A separate
	 * accumulator resolves correctly regardless of what the leaf's own
	 * #states says, and never touches it.
	 *
	 * $inherited_gate/$gate_consumed thread a gate through arbitrarily many
	 * levels of container nesting so it still lands on the eventual first
	 * leaf, however deep (real RYV exports are only one level deep today,
	 * but nothing here assumes that).
	 *
	 * Every leaf produced is also tagged with $group: the id of whichever
	 * TOP-LEVEL element (a direct child of the very first call's
	 * $elements, e.g. one state's own container, or the state selector
	 * itself) it descended from. $group is null on the top-level call and
	 * gets assigned fresh - one per top-level element - the first time
	 * flatten() recurses into it; every element nested inside that one
	 * (however deep) then inherits the exact same group. This is what lets
	 * build_subtree()'s default-fallthrough search stop at "the end of
	 * this element's own content" instead of sailing into a sibling
	 * top-level element's (e.g. a different state's) content once
	 * everything shares one flat document - see find_default_next().
	 *
	 * @param array      $elements        Element definitions keyed by machine name.
	 * @param array      $ordered         Accumulator: list of [ id, def, group ].
	 * @param string[]   $warnings        Accumulator.
	 * @param string[]   $skipped         Accumulator.
	 * @param array[]    $review          Accumulator - see parse()'s docblock.
	 * @param array      $container_gates Accumulator: "source_id|value" => target node id.
	 * @param array[]|null $inherited_gate Gate condition list propagated down from an enclosing container,
	 *                                     if any - a list because a container's own gate can itself be a
	 *                                     resolved OR chain (see extract_visibility_condition()), not just
	 *                                     ever a single condition.
	 * @param string|null $group          Group inherited from an enclosing call, or null at the true top level.
	 */
	private function flatten( array $elements, array &$ordered, array &$warnings, array &$skipped, array &$review, array &$container_gates, ?array $inherited_gate, ?string $group ): void {
		$gate_consumed = false;

		foreach ( $elements as $key => $def ) {
			if ( ! is_array( $def ) ) {
				continue; // Not an element definition (e.g. a stray scalar property).
			}

			$key = (string) $key;

			if ( ! isset( $def['#type'] ) ) {
				$warnings[] = "Skipped '{$key}': no '#type' present, not a recognizable element definition.";
				$skipped[]  = $key;
				continue;
			}

			$type = $def['#type'];

			if ( in_array( $type, self::SKIP_TYPES, true ) ) {
				$skipped[] = $key;
				continue;
			}

			// A null $group means every item at THIS level is its own
			// top-level element (the true top-level call); a non-null
			// $group means we're recursing inside an already-grouped
			// container, so every descendant shares that same group.
			$item_group = $group ?? sanitize_key( $key );

			if ( in_array( $type, self::CONTAINER_TYPES, true ) ) {
				$children = $this->container_children( $def );

				if ( empty( $children ) ) {
					$warnings[] = "Container '{$key}' ({$type}) has no child elements - skipped.";
					$skipped[]  = $key;
					continue;
				}

				$own_gate = ( ! empty( $def['#states'] ) && is_array( $def['#states'] ) )
					? $this->extract_visibility_condition( $def['#states'], $key, $warnings, $review )
					: null;

				// This container's own gate (if any) is more specific/nearer
				// than anything inherited from further up, so it wins; if it
				// has none of its own, keep threading the inherited one down
				// so it still reaches the eventual first leaf.
				$gate_to_pass = $own_gate ?? ( $gate_consumed ? null : $inherited_gate );
				$before_count = count( $ordered );

				$this->flatten( $children, $ordered, $warnings, $skipped, $review, $container_gates, $gate_to_pass, $item_group );

				if ( null !== $gate_to_pass ) {
					if ( count( $ordered ) > $before_count ) {
						$entry_id = $ordered[ $before_count ]['id'];

						// $gate_to_pass is a list because a resolved OR chain
						// (see extract_visibility_condition()) can carry
						// several source/value pairs, not just one - every
						// one of them routes to this same first leaf.
						foreach ( $gate_to_pass as $condition ) {
							$slot = $condition['source_id'] . '|' . $condition['value'];

							if ( isset( $container_gates[ $slot ] ) ) {
								$warnings[] = "Container '{$key}': duplicate gate for the same source/value as an earlier container - it overwrites the earlier target.";
							}

							$container_gates[ $slot ] = $entry_id;
						}
					} else {
						$warnings[] = "Container '{$key}' produced no leaf nodes - its gate has nowhere to route to.";
					}

					$gate_consumed = true;
				}

				continue;
			}

			$ordered[] = array(
				'id'    => sanitize_key( $key ),
				'def'   => $def,
				'group' => $item_group,
			);
		}
	}

	/**
	 * Build a single node definition from a Drupal element.
	 *
	 * @param string   $id       Sanitized node id.
	 * @param array    $def      Raw Drupal element definition.
	 * @param string[] $warnings Accumulator.
	 * @return array Node definition (without next_node/options routing filled in yet).
	 */
	private function build_node( string $id, array $def, array &$warnings ): array {
		$drupal_type = $def['#type'];
		$type        = self::TYPE_MAP[ $drupal_type ] ?? null;

		if ( null === $type ) {
			$warnings[] = "Element '{$id}': unmapped Drupal type '{$drupal_type}' - imported as 'unsupported', needs manual review in the form builder.";
			$type       = 'unsupported';
		}

		$node = array(
			'type'        => $type,
			'title'       => isset( $def['#title'] ) ? (string) $def['#title'] : '',
			'content'     => '',
			'source_type' => $drupal_type,
			'options'     => array(),
		);

		// Display-only elements (markup/processed_text) carry their body in
		// #markup or #text, not #title - #title is often unset for these.
		if ( isset( $def['#markup'] ) ) {
			$node['content'] = (string) $def['#markup'];
		} elseif ( isset( $def['#text'] ) ) {
			$node['content'] = (string) $def['#text'];
		}

		if ( ! empty( $def['#options'] ) ) {
			if ( is_array( $def['#options'] ) ) {
				$node['options'] = $this->normalize_options( $def['#options'], $warnings, $id );
			} elseif ( is_string( $def['#options'] ) ) {
				$node['options'] = $this->resolve_named_option_set( $def['#options'], $warnings, $id );
			}
		}

		return $node;
	}

	/**
	 * Resolve a Drupal named/shared `#options` reference (see
	 * NAMED_OPTION_SETS). Left silently unresolved, an element like this
	 * ends up with zero options - not just unable to branch on its answer,
	 * but structurally unable to route to a "next" node from any option at
	 * all, which previously stranded whatever it *would* have gated (e.g. a
	 * "you can vote!" result keyed off one specific answer) as an
	 * unreachable orphan with no warning pointing at the actual cause - see
	 * RYV_Graph_Validator for the diagnostic that surfaces the symptom.
	 *
	 * @param string   $reference  The named option set, e.g. 'yes_no'.
	 * @param string[] $warnings   Accumulator.
	 * @param string   $element_id Owning element id, for warning messages.
	 * @return array List of ['value' => string, 'label' => string].
	 */
	private function resolve_named_option_set( string $reference, array &$warnings, string $element_id ): array {
		if ( isset( self::NAMED_OPTION_SETS[ $reference ] ) ) {
			return self::NAMED_OPTION_SETS[ $reference ];
		}

		$warnings[] = "Element '{$element_id}': #options references an unrecognized named option set '{$reference}' (not an inline list) - imported with no options, needs manual review in the form builder.";

		return array();
	}

	/**
	 * Normalize a Drupal #options map (value => label, occasionally
	 * value => [nested optgroup]) into a flat list of {value, label}.
	 *
	 * @param array    $raw_options Raw #options array.
	 * @param string[] $warnings    Accumulator.
	 * @param string   $element_id  Owning element id, for warning messages.
	 * @return array List of ['value' => string, 'label' => string].
	 */
	private function normalize_options( array $raw_options, array &$warnings, string $element_id ): array {
		$out = array();

		foreach ( $raw_options as $value => $label ) {
			if ( is_array( $label ) ) {
				$warnings[] = "Element '{$element_id}': flattened optgroup '{$value}' - grouping was discarded, options merged into the parent list.";

				foreach ( $label as $sub_value => $sub_label ) {
					$out[] = array(
						'value' => (string) $sub_value,
						'label' => is_scalar( $sub_label ) ? (string) $sub_label : (string) $sub_value,
					);
				}
				continue;
			}

			$out[] = array(
				'value' => (string) $value,
				'label' => (string) $label,
			);
		}

		return $out;
	}

	/**
	 * Translate a Drupal `#states` block into a resolved list of gate
	 * conditions, if the rule's logic can be represented that way.
	 * `visible-slide` is treated identically to `visible` - it's the same
	 * condition, just with an animated reveal, and RYV widely uses it for
	 * per-state / per-answer branching. 'invisible'/'invisible-slide' are
	 * still not translated (that would need a true "else" branch, not just
	 * another gate) - flagged only when they're the sole directive present.
	 *
	 * `visible` itself comes in two shapes:
	 *   - a dict, `{ selector: condition, ... }` - one key is the plain
	 *     single-condition case; more than one key is Drupal's implicit AND.
	 *   - a sequential list alternating condition-dicts with 'and'/'or'
	 *     operator strings, Drupal's explicit-join form.
	 * See resolve_condition_dict()/resolve_condition_list() for what each
	 * shape can and can't become.
	 *
	 * @param array    $states     The element's #states array.
	 * @param string   $element_id Owning element id, for warning messages.
	 * @param string[] $warnings   Accumulator.
	 * @param array[]  $review     Accumulator - see parse()'s docblock.
	 * @return array<int,array{source_id:string,value:string}>|null List of
	 *         resolved conditions (every one of them an independent OR
	 *         branch onto the same target), or null if the rule couldn't be
	 *         resolved into gates at all.
	 */
	private function extract_visibility_condition( array $states, string $element_id, array &$warnings, array &$review ): ?array {
		if ( isset( $states['visible'] ) ) {
			$visible = $states['visible'];
		} elseif ( isset( $states['visible-slide'] ) ) {
			$visible = $states['visible-slide'];
		} else {
			if ( array_key_exists( 'invisible', $states ) || array_key_exists( 'invisible-slide', $states ) ) {
				$warnings[] = "Element '{$element_id}': unsupported #states directive (only 'visible'/'visible-slide' are translated) - kept as unconditional/linear.";
			}
			return null;
		}

		if ( ! is_array( $visible ) || empty( $visible ) ) {
			$warnings[] = "Element '{$element_id}': #states.visible is not a recognizable condition - kept as unconditional/linear.";
			return null;
		}

		if ( $this->is_sequential_list( $visible ) ) {
			return $this->resolve_condition_list( $visible, $element_id, $warnings, $review );
		}

		return $this->resolve_condition_dict( $visible, $element_id, $warnings, $review );
	}

	/**
	 * Resolve `#states.visible`'s dict shape: `{ selector: condition, ... }`.
	 * A single key is the plain, always-supported case. More than one key is
	 * Drupal Form API's implicit AND ("if there's more than one, all must be
	 * met") - and that's exactly what this parser's graph model can't
	 * express: `build_subtree()` gives an option exactly one `next_node`,
	 * chosen by that option's own value on ONE upstream node. An AND across
	 * two DIFFERENT upstream questions would need the engine to check a
	 * second node's answer too, which nothing here (or in the frontend
	 * decision engine - see CLAUDE.md's "UX model" section) does. So this
	 * case is deliberately left linear, with every condition it did manage
	 * to parse captured in `review` so an admin can wire it up by hand.
	 *
	 * @param array    $dict       The `visible` dict.
	 * @param string   $element_id Owning element id, for warning/review entries.
	 * @param string[] $warnings   Accumulator.
	 * @param array[]  $review     Accumulator.
	 * @return array<int,array{source_id:string,value:string}>|null
	 */
	private function resolve_condition_dict( array $dict, string $element_id, array &$warnings, array &$review ): ?array {
		$parsed = array();

		foreach ( $dict as $selector => $condition ) {
			$fail_reason = null;
			$parsed_condition = $this->parse_single_condition( (string) $selector, $condition, $fail_reason );

			if ( null === $parsed_condition ) {
				$message    = 'unparseable_selector' === $fail_reason
					? "could not parse #states selector '{$selector}'"
					: "#states condition on '{$selector}' is not a simple value match";
				$warnings[] = "Element '{$element_id}': {$message} - kept as unconditional/linear.";
				$review[]   = $this->build_review_entry( $element_id, 'unparseable', null, $parsed, "Selector '{$selector}' could not be parsed as a simple value-match condition." );
				return null;
			}

			$parsed[] = $parsed_condition;
		}

		if ( 1 === count( $parsed ) ) {
			return $parsed;
		}

		$warnings[] = "Element '{$element_id}': #states.visible requires " . count( $parsed ) . ' condition(s) to ALL match at once (AND) - not representable as a single branch, kept as unconditional/linear.';
		$review[]   = $this->build_review_entry( $element_id, 'and', 'and', $parsed, 'All listed conditions must match simultaneously - requires joining answers from more than one question.' );

		return null;
	}

	/**
	 * Resolve `#states.visible`'s sequential-list shape: alternating
	 * condition-dicts and 'and'/'or' operator strings, e.g.
	 *   [ {sel1: {value: a}}, or, {sel2: {value: b}}, or, {sel1: {value: c}} ]
	 * A rule that's a PURE OR chain - even across several different source
	 * questions - is fully resolvable: each condition just becomes its own
	 * independent gate onto the same target, which is exactly what
	 * build_subtree() already does with one gate per option/source. Any
	 * 'and' anywhere in the chain reintroduces the cross-question join
	 * problem described in resolve_condition_dict() and falls back to
	 * linear, with the full parsed condition list logged to `review`.
	 *
	 * @param array    $list       The `visible` list.
	 * @param string   $element_id Owning element id, for warning/review entries.
	 * @param string[] $warnings   Accumulator.
	 * @param array[]  $review     Accumulator.
	 * @return array<int,array{source_id:string,value:string}>|null
	 */
	private function resolve_condition_list( array $list, string $element_id, array &$warnings, array &$review ): ?array {
		$conditions = array();
		$operators  = array();

		foreach ( $list as $item ) {
			if ( is_string( $item ) ) {
				$op = strtolower( trim( $item ) );

				if ( ! in_array( $op, array( 'and', 'or' ), true ) ) {
					$warnings[] = "Element '{$element_id}': unrecognized #states list operator '{$item}' - kept as unconditional/linear.";
					$review[]   = $this->build_review_entry( $element_id, 'unsupported_operator', null, $conditions, "Unrecognized operator token '{$item}' in a compound rule." );
					return null;
				}

				$operators[] = $op;
				continue;
			}

			if ( ! is_array( $item ) || 1 !== count( $item ) ) {
				$warnings[] = "Element '{$element_id}': #states.visible list contains a nested/compound condition - not supported, kept as unconditional/linear.";
				$review[]   = $this->build_review_entry( $element_id, 'nested_unsupported', null, $conditions, 'A list item was itself a nested condition group rather than a single selector condition.' );
				return null;
			}

			$selector    = array_key_first( $item );
			$fail_reason = null;
			$condition   = $this->parse_single_condition( (string) $selector, $item[ $selector ], $fail_reason );

			if ( null === $condition ) {
				$message    = 'unparseable_selector' === $fail_reason
					? "could not parse selector '{$selector}'"
					: "condition on '{$selector}' is not a simple value match";
				$warnings[] = "Element '{$element_id}': {$message} inside a compound #states rule - kept as unconditional/linear.";
				$review[]   = $this->build_review_entry( $element_id, 'unparseable', null, $conditions, "Selector '{$selector}' inside the compound rule could not be parsed." );
				return null;
			}

			$conditions[] = $condition;
		}

		if ( empty( $conditions ) ) {
			$warnings[] = "Element '{$element_id}': #states.visible list had no usable conditions - kept as unconditional/linear.";
			$review[]   = $this->build_review_entry( $element_id, 'empty', null, array(), 'No parseable conditions were found in the list.' );
			return null;
		}

		$unique_ops = array_values( array_unique( $operators ) );

		// Zero or one operator token between >=1 conditions, and every token
		// (if any) is 'or' - a pure OR chain, fully resolvable.
		if ( count( $conditions ) < 2 || ( array( 'or' ) === $unique_ops ) ) {
			return $conditions;
		}

		$reason     = ( array( 'and' ) === $unique_ops ) ? 'and' : 'mixed';
		$op_summary = 'mixed' === $reason ? "'and'/'or'" : "'and'";
		$warnings[] = "Element '{$element_id}': #states.visible combines " . count( $conditions ) . " conditions with {$op_summary} - not supported, kept as unconditional/linear.";
		$review[]   = $this->build_review_entry( $element_id, $reason, $reason, $conditions, 'Conditions could not all be joined into a single branch because at least one was combined with AND.' );

		return null;
	}

	/**
	 * Parse one `{ ':input[name="x"]': { value: 'y' } }`-shaped condition.
	 *
	 * @param string      $selector    The selector key, e.g. `:input[name="x"]`.
	 * @param mixed       $condition   The selector's value, expected `{ value: ... }`.
	 * @param string|null $fail_reason Out param: 'unparseable_selector' or 'not_simple_value_match' on failure.
	 * @return array{source_id:string,value:string}|null
	 */
	private function parse_single_condition( string $selector, $condition, ?string &$fail_reason = null ): ?array {
		if ( ! preg_match( '/name="([^"\]]+)"/', $selector, $matches ) ) {
			$fail_reason = 'unparseable_selector';
			return null;
		}

		$source_id = rtrim( $matches[1], '[]' );

		if ( ! is_array( $condition ) || ! isset( $condition['value'] ) || ! is_scalar( $condition['value'] ) ) {
			$fail_reason = 'not_simple_value_match';
			return null;
		}

		return array(
			'source_id' => sanitize_key( $source_id ),
			'value'     => (string) $condition['value'],
		);
	}

	/**
	 * True if $arr is a sequential (Drupal YAML list) array rather than an
	 * associative (dict) one - distinguishes `#states.visible`'s two shapes.
	 * A `#states` selector key is never purely numeric, so integer-keyed
	 * `array_keys() === range(0, n-1)` reliably means "this is a list."
	 *
	 * @param array $arr
	 * @return bool
	 */
	private function is_sequential_list( array $arr ): bool {
		if ( empty( $arr ) ) {
			return true;
		}

		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * Build one structured `review` entry - the machine-readable record of
	 * exactly what a compound rule required, for a real review UI rather
	 * than a warning string an admin has to parse by eye.
	 *
	 * @param string      $element_id Element left linear because of this rule.
	 * @param string      $reason     Short machine tag: 'and' | 'mixed' | 'unparseable' | 'unsupported_operator' | 'nested_unsupported' | 'empty'.
	 * @param string|null $operator   'and' | 'mixed' | null (null when there's no meaningful join, e.g. a parse failure).
	 * @param array[]     $conditions Whatever conditions were successfully parsed, `{source_id, value}` each.
	 * @param string      $note       One-sentence human explanation of why this couldn't become a branch.
	 * @return array
	 */
	private function build_review_entry( string $element_id, string $reason, ?string $operator, array $conditions, string $note ): array {
		$parts = array();
		foreach ( $conditions as $condition ) {
			$parts[] = "{$condition['source_id']} = '{$condition['value']}'";
		}

		$joiner  = 'and' === $operator ? ' AND ' : ( 'mixed' === $operator ? ' [and/or] ' : ' OR ' );
		$summary = $parts ? implode( $joiner, $parts ) : '(no parseable conditions)';

		return array(
			'element_id' => $element_id,
			'reason'     => $reason,
			'operator'   => $operator,
			'conditions' => $conditions,
			'summary'    => $summary,
			'note'       => $note,
		);
	}

	/**
	 * Find the next element in document order that isn't exclusively
	 * reached via one of this element's own conditional gates, without
	 * crossing into a different top-level group (see flatten()'s $group -
	 * this is what keeps one state's default fallthrough from spilling
	 * into the next state's content once every state shares one document).
	 *
	 * @param string[] $ids           Document-ordered node ids.
	 * @param string[] $groups        Parallel array: each id's top-level group.
	 * @param int      $current_index Index of the current node within $ids.
	 * @param array    $gated_targets value => target_id map for this node's options.
	 * @return string|null
	 */
	private function find_default_next( array $ids, array $groups, int $current_index, array $gated_targets ): ?string {
		$excluded  = array_values( $gated_targets );
		$count     = count( $ids );
		$my_group  = $groups[ $current_index ];

		for ( $j = $current_index + 1; $j < $count; $j++ ) {
			if ( $groups[ $j ] !== $my_group ) {
				return null;
			}

			if ( ! in_array( $ids[ $j ], $excluded, true ) ) {
				return $ids[ $j ];
			}
		}

		return null;
	}
}
