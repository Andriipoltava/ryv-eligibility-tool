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
 * Real RYV exports bundle *every* state into one file: a single state-select
 * element at the root, followed by ~50 sibling `container` elements, each
 * gated by `#states.visible` on that selector with a specific state value.
 * parse() auto-detects that shape and splits it into one subtree per state;
 * see build_subtree() for the per-state graph-building algorithm itself.
 *
 * Anything the translator can't confidently resolve (compound #states
 * conditions, unmapped element types, optgroups, top-level elements outside
 * any detected state container, etc.) is still imported - never silently
 * dropped - but flagged in `warnings`/`unassigned` for manual follow-up in
 * the Phase 5 visual builder.
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
	 * Parse a decoded Drupal webform structure into one or more per-state
	 * decision graphs.
	 *
	 * Auto-detects the "which state" selector (the first top-level select/
	 * radios element with #options) and treats every sibling `container`
	 * gated on that selector as one state's subtree. If no such shape is
	 * found (e.g. the file already *is* a single state's element tree, with
	 * no wrapping per-state containers), everything falls back into one
	 * subtree under the caller-supplied $fallback_state_id.
	 *
	 * @param array       $parsed             Output of parse_file() / Yaml::parse().
	 * @param string|null $fallback_state_id  State slug to use when auto-detection finds no per-state containers.
	 * @return array {
	 *     @type string|null $selector_id Detected state-selector element id, or null.
	 *     @type array        $states     state_id => { state_id, label, entry_node, nodes }.
	 *     @type string[]     $warnings   Ambiguous/unsupported constructs, for manual follow-up.
	 *     @type string[]     $skipped    Element keys dropped entirely (e.g. submit actions).
	 *     @type string[]     $unassigned Top-level elements that could not be assigned to any state.
	 * }
	 * @throws RuntimeException If no state containers are found and no fallback id was given.
	 */
	public function parse( array $parsed, ?string $fallback_state_id = null ): array {
		$warnings   = array();
		$skipped    = array();
		$unassigned = array();
		$states     = array();

		$elements_root = $this->resolve_elements_root( $parsed );
		$selector       = $this->find_state_selector( $elements_root );

		if ( null !== $selector ) {
			foreach ( $elements_root as $key => $def ) {
				$key = (string) $key;

				if ( ! is_array( $def ) || ! isset( $def['#type'] ) ) {
					continue;
				}

				if ( sanitize_key( $key ) === $selector['id'] || in_array( $def['#type'], self::SKIP_TYPES, true ) ) {
					continue;
				}

				if ( ! in_array( $def['#type'], self::CONTAINER_TYPES, true ) ) {
					$unassigned[] = $key;
					continue;
				}

				$condition = ! empty( $def['#states'] ) && is_array( $def['#states'] )
					? $this->extract_visibility_condition( $def['#states'], $key, $warnings )
					: null;

				if ( null === $condition || $condition['source_id'] !== $selector['id'] ) {
					$warnings[]   = "Container '{$key}': not gated on the detected state selector '{$selector['id']}' - not assigned to any state.";
					$unassigned[] = $key;
					continue;
				}

				$raw_value = $condition['value'];
				$label     = $selector['options'][ $raw_value ] ?? $raw_value;
				$state_id  = sanitize_title( $label );

				if ( '' === $state_id ) {
					$warnings[]   = "Container '{$key}': condition value '{$raw_value}' did not produce a usable state id - skipped.";
					$unassigned[] = $key;
					continue;
				}

				if ( isset( $states[ $state_id ] ) ) {
					$warnings[] = "Duplicate state container for '{$label}' ('{$key}') - it overwrites an earlier container for the same state.";
				}

				$children = $this->container_children( $def );

				if ( empty( $children ) ) {
					$warnings[]   = "Container '{$key}' ({$label}) has no child elements - skipped.";
					$unassigned[] = $key;
					continue;
				}

				$subtree = $this->build_subtree( $children, $warnings, $skipped );

				$states[ $state_id ] = array(
					'state_id'   => $state_id,
					'label'      => $label,
					'entry_node' => $subtree['entry_node'],
					'nodes'      => $subtree['nodes'],
				);
			}
		}

		if ( empty( $states ) ) {
			if ( null === $fallback_state_id || '' === $fallback_state_id ) {
				throw new RuntimeException( 'Could not auto-detect any per-state sections in this file (no state-selector + gated containers found). Provide a state override to import it as a single state.' );
			}

			$subtree = $this->build_subtree( $elements_root, $warnings, $skipped );

			$states[ $fallback_state_id ] = array(
				'state_id'   => $fallback_state_id,
				'label'      => RYV_US_States::label( $fallback_state_id ),
				'entry_node' => $subtree['entry_node'],
				'nodes'      => $subtree['nodes'],
			);
		}

		return array(
			'selector_id' => $selector['id'] ?? null,
			'states'      => $states,
			'warnings'    => $warnings,
			'skipped'     => $skipped,
			'unassigned'  => $unassigned,
		);
	}

	/**
	 * Find the state-selection element: the first top-level select/radios
	 * element (in document order) that carries its own #options list.
	 *
	 * @param array $elements_root Top-level element definitions.
	 * @return array{id:string,options:array}|null
	 */
	private function find_state_selector( array $elements_root ): ?array {
		foreach ( $elements_root as $key => $def ) {
			if ( ! is_array( $def ) || ! isset( $def['#type'] ) ) {
				continue;
			}

			if ( in_array( $def['#type'], array( 'select', 'radios' ), true ) && ! empty( $def['#options'] ) && is_array( $def['#options'] ) ) {
				return array(
					'id'      => sanitize_key( (string) $key ),
					'options' => $def['#options'],
				);
			}
		}

		return null;
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
	 * Build one flat, routed node graph from a set of sibling element
	 * definitions (either a whole file's root, or one state container's
	 * children). This is the core translation: document-order linear
	 * fallback, overridden per-option by any downstream #states gate.
	 *
	 * @param array    $elements Element definitions keyed by machine name.
	 * @param string[] $warnings Accumulator (shared across all subtrees in one parse() call).
	 * @param string[] $skipped  Accumulator (shared across all subtrees in one parse() call).
	 * @return array{entry_node:?string,nodes:array}
	 */
	private function build_subtree( array $elements, array &$warnings, array &$skipped ): array {
		$ordered = array();
		$this->flatten( $elements, $ordered, $warnings, $skipped );

		$nodes           = array();
		$gates_by_source = array(); // source_id => [ value => target_id ].

		foreach ( $ordered as $item ) {
			$id   = $item['id'];
			$def  = $item['def'];
			$node = $this->build_node( $id, $def, $warnings );

			$nodes[ $id ] = $node;

			if ( ! empty( $def['#states'] ) && is_array( $def['#states'] ) ) {
				$condition = $this->extract_visibility_condition( $def['#states'], $id, $warnings );

				if ( null !== $condition ) {
					$gates_by_source[ $condition['source_id'] ][ $condition['value'] ] = $id;
				}
			}
		}

		$ids = array_map(
			static function ( $item ) {
				return $item['id'];
			},
			$ordered
		);

		foreach ( $ids as $index => $id ) {
			$gated_targets = $gates_by_source[ $id ] ?? array();
			$default_next  = $this->find_default_next( $ids, $index, $gated_targets );

			if ( ! empty( $nodes[ $id ]['options'] ) ) {
				foreach ( $nodes[ $id ]['options'] as &$option ) {
					$option['next_node'] = $gated_targets[ $option['value'] ] ?? $default_next;
				}
				unset( $option );
			} else {
				$nodes[ $id ]['next_node'] = $default_next;
			}
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
	 * @param array    $elements Element definitions keyed by machine name.
	 * @param array    $ordered  Accumulator: list of [ id, def ].
	 * @param string[] $warnings Accumulator.
	 * @param string[] $skipped  Accumulator.
	 */
	private function flatten( array $elements, array &$ordered, array &$warnings, array &$skipped ): void {
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

			if ( in_array( $type, self::CONTAINER_TYPES, true ) ) {
				$children = array_filter(
					$def,
					static function ( $value, $prop_key ) {
						return is_array( $value ) && '#' !== substr( (string) $prop_key, 0, 1 );
					},
					ARRAY_FILTER_USE_BOTH
				);

				if ( empty( $children ) ) {
					$warnings[] = "Container '{$key}' ({$type}) has no child elements - skipped.";
					$skipped[]  = $key;
					continue;
				}

				$this->flatten( $children, $ordered, $warnings, $skipped );
				continue;
			}

			$ordered[] = array(
				'id'  => sanitize_key( $key ),
				'def' => $def,
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

		if ( ! empty( $def['#options'] ) && is_array( $def['#options'] ) ) {
			$node['options'] = $this->normalize_options( $def['#options'], $warnings, $id );
		}

		return $node;
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
	 * Translate a Drupal `#states` block into a single-condition gate, if
	 * it matches the one supported shape:
	 *   visible: { ':input[name="source_id"]': { value: 'x' } }
	 * `visible-slide` is treated identically to `visible` - it's the same
	 * condition, just with an animated reveal, and RYV widely uses it for
	 * per-state / per-answer branching.
	 *
	 * Anything more complex (multiple conditions, AND/OR arrays, 'invisible',
	 * operators other than a plain value match) is flagged and ignored -
	 * the element falls back to unconditional linear placement.
	 *
	 * @param array    $states     The element's #states array.
	 * @param string   $element_id Owning element id, for warning messages.
	 * @param string[] $warnings   Accumulator.
	 * @return array{source_id:string,value:string}|null
	 */
	private function extract_visibility_condition( array $states, string $element_id, array &$warnings ): ?array {
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

		if ( ! is_array( $visible ) || 1 !== count( $visible ) ) {
			$warnings[] = "Element '{$element_id}': #states.visible has a compound/multi-condition rule - not supported, kept as unconditional/linear.";
			return null;
		}

		$selector  = array_key_first( $visible );
		$condition = $visible[ $selector ];

		if ( ! preg_match( '/name="([^"\]]+)"/', (string) $selector, $matches ) ) {
			$warnings[] = "Element '{$element_id}': could not parse #states selector '{$selector}' - kept as unconditional/linear.";
			return null;
		}

		$source_id = rtrim( $matches[1], '[]' );

		if ( ! is_array( $condition ) || ! isset( $condition['value'] ) ) {
			$warnings[] = "Element '{$element_id}': #states condition on '{$source_id}' is not a simple value match - kept as unconditional/linear.";
			return null;
		}

		return array(
			'source_id' => sanitize_key( $source_id ),
			'value'     => (string) $condition['value'],
		);
	}

	/**
	 * Find the next element in document order that isn't exclusively
	 * reached via one of this element's own conditional gates.
	 *
	 * @param string[] $ids           Document-ordered node ids.
	 * @param int      $current_index Index of the current node within $ids.
	 * @param array    $gated_targets value => target_id map for this node's options.
	 * @return string|null
	 */
	private function find_default_next( array $ids, int $current_index, array $gated_targets ): ?string {
		$excluded = array_values( $gated_targets );
		$count    = count( $ids );

		for ( $j = $current_index + 1; $j < $count; $j++ ) {
			if ( ! in_array( $ids[ $j ], $excluded, true ) ) {
				return $ids[ $j ];
			}
		}

		return null;
	}
}
