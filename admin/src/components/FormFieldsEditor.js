import { __ } from '@wordpress/i18n';
import { Button, TextControl, SelectControl, CheckboxControl } from '@wordpress/components';
import ConfirmButton from './ConfirmButton';

const FIELD_TYPE_OPTIONS = [
  { label: __('Text', 'ryv-eligibility-tool'), value: 'text' },
  { label: __('Email', 'ryv-eligibility-tool'), value: 'email' },
  { label: __('Date', 'ryv-eligibility-tool'), value: 'date' },
  { label: __('Phone', 'ryv-eligibility-tool'), value: 'tel' },
  { label: __('Textarea', 'ryv-eligibility-tool'), value: 'textarea' },
];

/**
 * Loose "still typing" slug - collapses invalid characters into underscores
 * without collapsing repeats or trimming the ends, so a trailing underscore
 * doesn't eat the next keystroke while the admin is still typing a
 * multi-word name. finalizeSlug() below does the real cleanup, on blur.
 */
function liveSlug(value) {
  return (value || '').toLowerCase().replace(/[^a-z0-9_]+/g, '_');
}

function finalizeSlug(value) {
  return liveSlug(value).replace(/_+/g, '_').replace(/^_+|_+$/g, '');
}

/** Appends `_2`, `_3`, ... until `base` doesn't collide with another field's name. */
function nextUniqueName(fields, base, skipIndex) {
  const root = base || 'field';
  let name = root;
  let n = 2;
  const taken = (candidate) => fields.some((f, i) => i !== skipIndex && f.name === candidate);
  while (taken(name)) {
    name = `${root}_${n}`;
    n += 1;
  }
  return name;
}

/**
 * Repeater for a `lead_capture` node's `form_fields` (label/name/type/
 * required). `name` auto-follows `label`'s slug until the admin edits `name`
 * directly, same idea as NodeEditor's own Node ID field - once it diverges
 * from the label's slug, further label edits stop overwriting it.
 */
export default function FormFieldsEditor({ fields, onChange }) {
  const updateField = (index, patch) => {
    onChange(fields.map((f, i) => (i === index ? { ...f, ...patch } : f)));
  };

  const removeField = (index) => {
    onChange(fields.filter((_, i) => i !== index));
  };

  const addField = () => {
    onChange([...fields, { label: '', name: nextUniqueName(fields, 'field'), type: 'text', required: false }]);
  };

  const moveField = (index, direction) => {
    const target = index + direction;
    if (target < 0 || target >= fields.length) return;
    const next = [...fields];
    [next[index], next[target]] = [next[target], next[index]];
    onChange(next);
  };

  const handleLabelChange = (index, label) => {
    const field = fields[index];
    const followsLabel = !field.name || field.name === finalizeSlug(field.label || '');
    const patch = { label };
    if (followsLabel) {
      patch.name = nextUniqueName(fields, finalizeSlug(label), index);
    }
    updateField(index, patch);
  };

  return (
    <div className="ryv-builder-form-fields">
      {fields.length === 0 && (
        <p className="ryv-builder-form-fields-empty">
          {__('No fields yet - add one below.', 'ryv-eligibility-tool')}
        </p>
      )}

      {fields.map((field, index) => (
        // eslint-disable-next-line react/no-array-index-key -- rows carry no local component state, so reordering is safe with an index key
        <div className="ryv-builder-form-field-row" key={index}>
          <div className="ryv-builder-form-field-reorder">
            <Button
              icon="arrow-up-alt2"
              label={__('Move up', 'ryv-eligibility-tool')}
              onClick={() => moveField(index, -1)}
              disabled={index === 0}
              isSmall
            />
            <Button
              icon="arrow-down-alt2"
              label={__('Move down', 'ryv-eligibility-tool')}
              onClick={() => moveField(index, 1)}
              disabled={index === fields.length - 1}
              isSmall
            />
          </div>

          <TextControl
            label={__('Label', 'ryv-eligibility-tool')}
            value={field.label || ''}
            onChange={(label) => handleLabelChange(index, label)}
          />

          <TextControl
            label={__('Field name', 'ryv-eligibility-tool')}
            value={field.name || ''}
            onChange={(name) => updateField(index, { name: liveSlug(name) })}
            onBlur={() => updateField(index, { name: nextUniqueName(fields, finalizeSlug(field.name || ''), index) })}
            help={__('Used as the {field_name} placeholder in email templates.', 'ryv-eligibility-tool')}
          />

          <SelectControl
            label={__('Type', 'ryv-eligibility-tool')}
            value={field.type || 'text'}
            options={FIELD_TYPE_OPTIONS}
            onChange={(type) => updateField(index, { type })}
          />

          <CheckboxControl
            label={__('Required', 'ryv-eligibility-tool')}
            checked={!!field.required}
            onChange={(required) => updateField(index, { required })}
          />

          <ConfirmButton
            label={__('Delete', 'ryv-eligibility-tool')}
            confirmLabel={__('Confirm?', 'ryv-eligibility-tool')}
            onConfirm={() => removeField(index)}
            isSmall
          />
        </div>
      ))}

      <Button variant="secondary" onClick={addField}>
        {__('+ Add Field', 'ryv-eligibility-tool')}
      </Button>
    </div>
  );
}