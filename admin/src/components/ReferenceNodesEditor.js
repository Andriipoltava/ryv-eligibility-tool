import { __ } from '@wordpress/i18n';
import { Button, SelectControl } from '@wordpress/components';
import ConfirmButton from './ConfirmButton';

/**
 * Editor for `node.reference_node_ids` - an ORDERED list of other nodes
 * (typically Markup) whose content renders below this question's own
 * options, in the same card, in list order (see appendReferenceContent() in
 * renderer.js). Unlike OptionsEditor's rows (append/remove only - options
 * are never reordered), order here is meaningful and user-controlled, so
 * each row gets up/down buttons instead.
 */
export default function ReferenceNodesEditor({ referenceNodeIds, referenceNodeOptions, onChange }) {
  const ids = referenceNodeIds || [];

  const updateAt = (index, id) => {
    onChange(ids.map((existing, i) => (i === index ? id : existing)));
  };

  const removeAt = (index) => {
    onChange(ids.filter((_, i) => i !== index));
  };

  const addOne = () => {
    onChange([...ids, '']);
  };

  const move = (index, direction) => {
    const target = index + direction;
    if (target < 0 || target >= ids.length) return;

    const next = [...ids];
    [next[index], next[target]] = [next[target], next[index]];
    onChange(next);
  };

  return (
    <div className="ryv-builder-field ryv-builder-reference-nodes">
      <label className="components-base-control__label">{__('Reference Content Nodes', 'ryv-eligibility-tool')}</label>
      <p className="components-base-control__help" style={{ marginTop: 0 }}>
        {__(
          'Optional, ordered - points at other nodes (typically Markup) whose content renders below this question\'s own options, in the same card, in the order listed here, not as separate steps. Edit the actual content on each of those nodes - these are just pointers, so they always stay in sync. A YAML import adds to this automatically when a Drupal element shared a #states condition with this question and its options left no free slot to route to that element as its own step.',
          'ryv-eligibility-tool'
        )}
      </p>

      {ids.map((id, index) => (
        // eslint-disable-next-line react/no-array-index-key -- rows are reordered in place via move(), not keyed by id
        <div className="ryv-builder-reference-node-row" key={index}>
          <span className="ryv-builder-reference-node-position">{index + 1}</span>

          <SelectControl
            className="ryv-builder-reference-node-select"
            label={__('Node', 'ryv-eligibility-tool')}
            hideLabelFromVision
            value={id || ''}
            options={referenceNodeOptions}
            onChange={(value) => updateAt(index, value)}
          />

          <span className="ryv-builder-reference-node-reorder">
            <Button
              icon="arrow-up-alt2"
              isSmall
              onClick={() => move(index, -1)}
              disabled={index === 0}
              label={__('Move up', 'ryv-eligibility-tool')}
            />
            <Button
              icon="arrow-down-alt2"
              isSmall
              onClick={() => move(index, 1)}
              disabled={index === ids.length - 1}
              label={__('Move down', 'ryv-eligibility-tool')}
            />
          </span>

          <ConfirmButton
            label={__('Delete', 'ryv-eligibility-tool')}
            confirmLabel={__('Confirm?', 'ryv-eligibility-tool')}
            onConfirm={() => removeAt(index)}
            isSmall
          />
        </div>
      ))}

      <Button variant="secondary" onClick={addOne}>
        {__('+ Add Reference Node', 'ryv-eligibility-tool')}
      </Button>
    </div>
  );
}
