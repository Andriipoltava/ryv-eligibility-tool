import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, SelectControl, TextControl } from '@wordpress/components';
import ConfirmButton from './ConfirmButton';

const TYPE_LABELS = {
  radio: __('Radio', 'ryv-eligibility-tool'),
  checkbox: __('Checkbox', 'ryv-eligibility-tool'),
  text: __('Text', 'ryv-eligibility-tool'),
  textarea: __('Textarea', 'ryv-eligibility-tool'),
  markup: __('Markup', 'ryv-eligibility-tool'),
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

/**
 * @param {object} graph RYV decision graph ({ entry_node, nodes }) - the whole form's flat node map, not scoped to any one branch.
 * @param {(updater: (graph: object) => object) => void} onChange Applies an update to the graph.
 */
export default function NodeList({ graph, selectedNodeId, onSelect, onChange }) {
  const [filter, setFilter] = useState('');

  const nodeIds = Object.keys(graph.nodes || {});

  // A flat DAG can easily hold hundreds of nodes (a real multi-branch form
  // flattens every branch's questions into this one list, with no grouping
  // left to browse by) - a plain unfiltered list of that size is close to
  // unusable, so filter by id or title substring.
  const query = filter.trim().toLowerCase();
  const visibleIds = query
    ? nodeIds.filter((id) => {
        const node = graph.nodes[id];
        return id.toLowerCase().includes(query) || (node.title || '').toLowerCase().includes(query);
      })
    : nodeIds;

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

      <ul className="ryv-builder-node-list">
        {visibleIds.map((id) => {
          const node = graph.nodes[id];
          return (
            <li key={id} className={id === selectedNodeId ? 'is-active' : ''}>
              <button type="button" onClick={() => onSelect(id)}>
                <strong>{id}</strong>
                <span className="ryv-builder-node-type">{TYPE_LABELS[node.type] || node.type}</span>
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

      {query && visibleIds.length === 0 && (
        <p className="ryv-builder-node-list-empty">{__('No questions match.', 'ryv-eligibility-tool')}</p>
      )}

      <Button variant="secondary" onClick={handleAdd}>
        {__('+ Add Question', 'ryv-eligibility-tool')}
      </Button>
    </div>
  );
}
