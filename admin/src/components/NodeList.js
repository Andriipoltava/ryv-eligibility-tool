import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, SelectControl, TextControl } from '@wordpress/components';
import ConfirmButton from './ConfirmButton';

const TYPE_LABELS = {
  radio: __('Radio', 'ryv-eligibility-tool'),
  checkbox: __('Checkbox', 'ryv-eligibility-tool'),
  text: __('Text', 'ryv-eligibility-tool'),
  textarea: __('Textarea', 'ryv-eligibility-tool'),
  markup: __('Markup', 'ryv-eligibility-tool'),
  lead_capture: __('Form Submission', 'ryv-eligibility-tool'),
  unsupported: __('Unsupported', 'ryv-eligibility-tool'),
};

function nextUniqueId(nodes, base) {
  let id = base;
  let n = 1;
  while (nodes[id]) {
    id = `${base}_${n}`;
    n += 1;
  }
  return id;
}

// Node ids carry their originating branch as a `{group}__{id}` prefix for
// legacy-migrated schemas (RYV_Schema_Migrator); freshly-imported schemas
// instead carry an explicit `node.group`, written by RYV_YAML_Parser (see
// its $group tagging in flatten()/build_subtree()) - this is purely a
// display-side grouping either way, it reads data the schema already
// carries rather than storing anything new here. 'general' is the literal
// value the parser itself writes for the entry-node picker (which isn't
// really part of any state's group) - reused here as the catch-all bucket
// for anything with neither an id prefix nor a group at all, so both cases
// land in the same "General" bucket instead of two different ones.
function groupKeyOf(id, node) {
  if (id.includes('__')) return id.split('__')[0];
  if (node && node.group) return node.group;
  return 'general';
}

function groupLabel(key) {
  if ('general' === key) return __('General', 'ryv-eligibility-tool');
  // RYV's real containers are machine-named "state_alabama" etc - strip a
  // leading "state_" so the "State: " prefix below doesn't double up into
  // "State: State Alabama".
  const stripped = key.replace(/^state[_ ]/i, '');
  const words = stripped.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  return sprintf(__('%s', 'ryv-eligibility-tool'), words);
}

/**
 * @param {object} graph RYV decision graph ({ entry_node, nodes }) - the whole form's flat node map, not scoped to any one branch.
 * @param {(updater: (graph: object) => object) => void} onChange Applies an update to the graph.
 */
export default function NodeList({ graph, selectedNodeId, onSelect, onChange }) {
  const [filter, setFilter] = useState('');
  // Manually expanded/collapsed groups (user clicks) - independent of
  // whatever a search query forces open below.
  const [openGroups, setOpenGroups] = useState(() => new Set());

  const nodeIds = Object.keys(graph.nodes || {});

  // A flat DAG can easily hold hundreds of nodes (a real multi-branch form
  // flattens every branch's questions into this one list, with no grouping
  // left to browse by) - a plain unfiltered list of that size is close to
  // unusable, so filter by id or title substring, and bucket the rest into
  // collapsible groups to keep the closed-state list short.
  const query = filter.trim().toLowerCase();
  const matchesQuery = (id) => {
    const node = graph.nodes[id];
    return id.toLowerCase().includes(query) || (node.title || '').toLowerCase().includes(query);
  };

  const groupOrder = [];
  const groups = {};
  nodeIds.forEach((id) => {
    const key = groupKeyOf(id, graph.nodes[id]);
    if (!groups[key]) {
      groups[key] = [];
      groupOrder.push(key);
    }
    groups[key].push(id);
  });

  const selectedGroup = selectedNodeId ? groupKeyOf(selectedNodeId, graph.nodes[selectedNodeId] || {}) : null;

  // Auto-expand the selected node's group once, so picking a node from the
  // entry-node dropdown (or adding a new one) doesn't leave it hidden inside
  // a collapsed group. A one-shot nudge, not a standing constraint - after
  // this, the user's own manual toggles are the only source of truth, so
  // collapsing that group back down actually works.
  useEffect(() => {
    if (!selectedGroup) return;
    setOpenGroups((prev) => (prev.has(selectedGroup) ? prev : new Set(prev).add(selectedGroup)));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedNodeId]);

  // Deliberately not driven by <details>'s native `toggle` event: that event
  // also fires when `open` changes programmatically (e.g. the forced-open
  // during a search), which would otherwise leak a search-only expansion
  // into permanent manual state. Handling the click directly and preventing
  // the native toggle keeps `openGroups` as the one source of truth.
  const handleSummaryClick = (key) => (event) => {
    event.preventDefault();
    if (query) return;
    setOpenGroups((prev) => {
      const next = new Set(prev);
      if (next.has(key)) {
        next.delete(key);
      } else {
        next.add(key);
      }
      return next;
    });
  };

  const handleAdd = () => {
    const id = nextUniqueId(graph.nodes || {}, 'new_question');
    onChange((prev) => ({
      ...prev,
      nodes: {
        ...prev.nodes,
        [id]: { type: 'text', title: '', content: '', options: [], next_node: null },
      },
      entry_node: prev.entry_node || id,
    }));
    onSelect(id);
  };

  const handleRemove = (id) => {
    onChange((prev) => {
      const nodes = { ...prev.nodes };
      delete nodes[id];
      return {
        ...prev,
        nodes,
        entry_node: prev.entry_node === id ? null : prev.entry_node,
      };
    });
    if (selectedNodeId === id) onSelect(null);
  };

  return (
    <div className="ryv-builder-nodes">
      <SelectControl
        label={__('Entry node (first question)', 'ryv-eligibility-tool')}
        value={graph.entry_node || ''}
        options={[
          { label: __('— none —', 'ryv-eligibility-tool'), value: '' },
          ...nodeIds.map((id) => ({ label: id, value: id })),
        ]}
        onChange={(value) => onChange((prev) => ({ ...prev, entry_node: value || null }))}
      />

      <TextControl
        label={__('Filter questions', 'ryv-eligibility-tool')}
        placeholder={__('Search by id or title…', 'ryv-eligibility-tool')}
        value={filter}
        onChange={setFilter}
      />

      {(() => {
        // Filter once per group up front so the "no matches" message below
        // doesn't have to re-run matchesQuery over every id a second time.
        const visibleGroups = groupOrder
          .map((key) => ({ key, ids: groups[key], visibleIds: query ? groups[key].filter(matchesQuery) : groups[key] }))
          .filter((g) => !query || g.visibleIds.length > 0);

        return (
          <>
            <div className="ryv-builder-node-groups">
              {visibleGroups.map(({ key, ids, visibleIds }) => {
                // While searching, groups shown are already guaranteed to
                // have a hit - force them open so it isn't hidden behind a
                // collapsed <summary>.
                const isOpen = query ? true : openGroups.has(key);

                return (
                  <details key={key} className="ryv-builder-node-group" open={isOpen}>
                    <summary onClick={handleSummaryClick(key)}>
                      <span className="ryv-builder-group-label">{groupLabel(key)}</span>
                      <span className="ryv-builder-group-count">
                        {query ? sprintf('%d / %d', visibleIds.length, ids.length) : ids.length}
                      </span>
                    </summary>

                    <ul className="ryv-builder-node-list">
                      {visibleIds.map((id) => {
                        const node = graph.nodes[id];
                        return (
                          <li key={id} className={id === selectedNodeId ? 'is-active' : ''}>
                            <button type="button" onClick={() => onSelect(id)}>
                              <strong>{id}</strong>
                              <span className={`ryv-builder-node-type ryv-builder-node-type--${node.type}`}>
                                {TYPE_LABELS[node.type] || node.type}
                              </span>
                              <span className="ryv-builder-node-title">{node.title || node.content || ''}</span>
                            </button>
                            <ConfirmButton
                              label={__('Delete', 'ryv-eligibility-tool')}
                              confirmLabel={__('Confirm?', 'ryv-eligibility-tool')}
                              onConfirm={() => handleRemove(id)}
                              isSmall
                            />
                          </li>
                        );
                      })}
                    </ul>
                  </details>
                );
              })}
            </div>

            {query && visibleGroups.length === 0 && (
              <p className="ryv-builder-node-list-empty">{__('No questions match.', 'ryv-eligibility-tool')}</p>
            )}
          </>
        );
      })()}

      <Button variant="secondary" onClick={handleAdd}>
        {__('+ Add Question', 'ryv-eligibility-tool')}
      </Button>
    </div>
  );
}
