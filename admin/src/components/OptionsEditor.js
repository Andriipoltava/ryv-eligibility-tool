import { __ } from '@wordpress/i18n';
import { Button, TextControl, SelectControl } from '@wordpress/components';
import ConfirmButton from './ConfirmButton';

export default function OptionsEditor({ options, nextNodeOptions, onChange }) {
  const updateOption = (index, patch) => {
    onChange(options.map((opt, i) => (i === index ? { ...opt, ...patch } : opt)));
  };

  const removeOption = (index) => {
    onChange(options.filter((_, i) => i !== index));
  };

  const addOption = () => {
    onChange([...options, { value: '', label: '', next_node: null }]);
  };

  return (
    <div className="ryv-builder-options">
      <h3>{__('Options', 'ryv-eligibility-tool')}</h3>

      {options.map((option, index) => (
        // eslint-disable-next-line react/no-array-index-key -- options are never reordered/dragged, only appended/removed
        <div className="ryv-builder-option-row" key={index}>
          <TextControl
            label={__('Value', 'ryv-eligibility-tool')}
            value={option.value || ''}
            onChange={(value) => updateOption(index, { value })}
          />
          <TextControl
            label={__('Label', 'ryv-eligibility-tool')}
            value={option.label || ''}
            onChange={(label) => updateOption(index, { label })}
          />
          <SelectControl
            label={__('Next question', 'ryv-eligibility-tool')}
            value={option.next_node || ''}
            options={nextNodeOptions}
            onChange={(next_node) => updateOption(index, { next_node: next_node || null })}
          />
          <ConfirmButton
            label={__('Delete', 'ryv-eligibility-tool')}
            confirmLabel={__('Confirm?', 'ryv-eligibility-tool')}
            onConfirm={() => removeOption(index)}
            isSmall
          />
        </div>
      ))}

      <Button variant="secondary" onClick={addOption}>
        {__('+ Add Option', 'ryv-eligibility-tool')}
      </Button>
    </div>
  );
}
