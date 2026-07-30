import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { TextControl, TextareaControl, SelectControl } from '@wordpress/components';
import OptionsEditor from './OptionsEditor';

const TYPE_OPTIONS = [
  { label: __('Radio (single choice)', 'ryv-eligibility-tool'), value: 'radio' },
  { label: __('Checkbox (multi choice)', 'ryv-eligibility-tool'), value: 'checkbox' },
  { label: __('Text input', 'ryv-eligibility-tool'), value: 'text' },
  { label: __('Textarea', 'ryv-eligibility-tool'), value: 'textarea' },
  { label: __('Markup (display only)', 'ryv-eligibility-tool'), value: 'markup' },
  { label: __('Unsupported / needs review', 'ryv-eligibility-tool'), value: 'unsupported' },
];

/**
 * Only applies to 'radio' nodes - a checkbox (multi-choice) question always
 * renders as the button list on the frontend, since 'radio'/'dropdown' only
 * make sense for a single choice. See renderQuestionCard() in renderer.js.
 */
const DISPLAY_STYLE_OPTIONS = [
  { label: __('Buttons', 'ryv-eligibility-tool'), value: 'buttons' },
  { label: __('Radio buttons', 'ryv-eligibility-tool'), value: 'radio' },
  { label: __('Dropdown', 'ryv-eligibility-tool'), value: 'dropdown' },
];

/**
 * @param {(oldId: string, newId: string) => void} onRename Renaming fixes up
 *   every reference to this node (entry_node + every option's/node's
 *   next_node) across the active state - see App.js's renameNode().
 */
export default function NodeEditor({ node, nodeId, allNodeIds, onChange, onRename }) {
  const [idDraft, setIdDraft] = useState(nodeId);

  useEffect(() => {
    setIdDraft(nodeId);
  }, [nodeId]);

  const isChoice = node.type === 'radio' || node.type === 'checkbox';
  const nextNodeOptions = [
    { label: __('— End —', 'ryv-eligibility-tool'), value: '' },
    ...allNodeIds.filter((id) => id !== nodeId).map((id) => ({ label: id, value: id })),
  ];

  const commitId = () => {
    const cleaned = idDraft.trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '_');

    if (!cleaned || cleaned === nodeId) {
      setIdDraft(nodeId);
      return;
    }

    if (allNodeIds.includes(cleaned)) {
      setIdDraft(nodeId); // collision - revert silently, the id field just snaps back
      return;
    }

    onRename(nodeId, cleaned);
  };

  return (
    <div className="ryv-builder-node-editor">
      <TextControl
        label={__('Node ID', 'ryv-eligibility-tool')}
        value={idDraft}
        onChange={setIdDraft}
        onBlur={commitId}
        help={__(
          'Used as the routing target for other questions - changing it updates every reference automatically.',
          'ryv-eligibility-tool'
        )}
      />

      <SelectControl
        label={__('Type', 'ryv-eligibility-tool')}
        value={node.type}
        options={TYPE_OPTIONS}
        onChange={(type) => onChange({ ...node, type })}
      />

      <TextControl
        label={__('Title', 'ryv-eligibility-tool')}
        value={node.title || ''}
        onChange={(title) => onChange({ ...node, title })}
      />

      {node.type === 'markup' && (
        <TextareaControl
          label={__('Content (HTML)', 'ryv-eligibility-tool')}
          value={node.content || ''}
          onChange={(content) => onChange({ ...node, content })}
          rows={6}
        />
      )}

      {node.type === 'radio' && (
        <SelectControl
          label={__('Display as', 'ryv-eligibility-tool')}
          value={node.display_style || 'buttons'}
          options={DISPLAY_STYLE_OPTIONS}
          onChange={(display_style) => onChange({ ...node, display_style })}
        />
      )}

      {isChoice ? (
        <OptionsEditor
          options={node.options || []}
          nextNodeOptions={nextNodeOptions}
          onChange={(options) => onChange({ ...node, options })}
        />
      ) : (
        <SelectControl
          label={__('Next question', 'ryv-eligibility-tool')}
          value={node.next_node || ''}
          options={nextNodeOptions}
          onChange={(next_node) => onChange({ ...node, next_node: next_node || null })}
        />
      )}
    </div>
  );
}
