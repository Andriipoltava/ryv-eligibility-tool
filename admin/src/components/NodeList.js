import { __ } from '@wordpress/i18n';
import { Button, SelectControl } from '@wordpress/components';
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
 * @param {object} state RYV state object ({ entry_node, nodes }).
 * @param {(updater: (state: object) => object) => void} onChange Applies an update to the active state.
 */
export default function NodeList({ state, selectedNodeId, onSelect, onChange }) {
  const nodeIds = Object.keys(state.nodes || {});

  const handleAdd = () => {
    const id = nextUniqueId(state.nodes || {}, 'new_question');
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
        value={state.entry_node || ''}
        options={[
          { label: __('— none —', 'ryv-eligibility-tool'), value: '' },
          ...nodeIds.map((id) => ({ label: id, value: id })),
        ]}
        onChange={(value) => onChange((prev) => ({ ...prev, entry_node: value || null }))}
      />

      <ul className="ryv-builder-node-list">
        {nodeIds.map((id) => {
          const node = state.nodes[id];
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

      <Button variant="secondary" onClick={handleAdd}>
        {__('+ Add Question', 'ryv-eligibility-tool')}
      </Button>
    </div>
  );
}
