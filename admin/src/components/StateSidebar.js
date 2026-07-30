import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl, SelectControl } from '@wordpress/components';
import ConfirmButton from './ConfirmButton';

const STATE_DISPLAY_STYLE_OPTIONS = [
  { label: __('Buttons', 'ryv-eligibility-tool'), value: 'buttons' },
  { label: __('Radio buttons', 'ryv-eligibility-tool'), value: 'radio' },
  { label: __('Dropdown', 'ryv-eligibility-tool'), value: 'dropdown' },
];

export default function StateSidebar({
  states,
  activeStateId,
  onSelect,
  onAdd,
  onRemove,
  stateDisplayStyle,
  onStateDisplayStyleChange,
  statePickerLabel,
  onStatePickerLabelChange,
}) {
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

      <TextControl
        label={__('Picker Label', 'ryv-eligibility-tool')}
        help={__(
          'The first question\'s title. Defaults to "Select your state" - change it if this form\'s first choice isn\'t really a state (e.g. "Select your category").',
          'ryv-eligibility-tool'
        )}
        placeholder={__('Select your state', 'ryv-eligibility-tool')}
        value={statePickerLabel || ''}
        onChange={onStatePickerLabelChange}
      />

      <SelectControl
        label={__('State picker display', 'ryv-eligibility-tool')}
        help={__('How visitors choose their state on the frontend.', 'ryv-eligibility-tool')}
        value={stateDisplayStyle || 'buttons'}
        options={STATE_DISPLAY_STYLE_OPTIONS}
        onChange={onStateDisplayStyleChange}
      />

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
