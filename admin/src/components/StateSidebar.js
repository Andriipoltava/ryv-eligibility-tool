import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl } from '@wordpress/components';
import ConfirmButton from './ConfirmButton';

export default function StateSidebar({ states, activeStateId, onSelect, onAdd, onRemove }) {
  const [newId, setNewId] = useState('');
  const [newLabel, setNewLabel] = useState('');

  const handleAdd = () => {
    const id = newId.trim().toLowerCase().replace(/[^a-z0-9-]+/g, '-');
    if (!id || states[id]) return;
    onAdd(id, newLabel.trim() || id);
    setNewId('');
    setNewLabel('');
  };

  return (
    <div className="ryv-builder-states">
      <h2>{__('States', 'ryv-eligibility-tool')}</h2>

      <ul className="ryv-builder-state-list">
        {Object.keys(states).map((id) => (
          <li key={id}>
            <button
              type="button"
              className={id === activeStateId ? 'is-active' : ''}
              onClick={() => onSelect(id)}
            >
              {states[id].label || id}
            </button>
            <ConfirmButton
              label={__('Remove', 'ryv-eligibility-tool')}
              confirmLabel={__('Confirm?', 'ryv-eligibility-tool')}
              onConfirm={() => onRemove(id)}
              isSmall
            />
          </li>
        ))}
      </ul>

      <div className="ryv-builder-add-state">
        <TextControl
          label={__('State slug', 'ryv-eligibility-tool')}
          placeholder="alabama"
          value={newId}
          onChange={setNewId}
        />
        <TextControl
          label={__('Label', 'ryv-eligibility-tool')}
          placeholder="Alabama"
          value={newLabel}
          onChange={setNewLabel}
        />
        <Button variant="secondary" onClick={handleAdd} disabled={!newId.trim()}>
          {__('Add State', 'ryv-eligibility-tool')}
        </Button>
      </div>
    </div>
  );
}
