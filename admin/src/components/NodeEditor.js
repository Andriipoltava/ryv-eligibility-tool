import { useState, useEffect, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { TextControl, SelectControl, CheckboxControl, TextareaControl } from '@wordpress/components';
import OptionsEditor from './OptionsEditor';
import ClassicEditor from './ClassicEditor';

const TYPE_OPTIONS = [
  { label: __('Radio (single choice)', 'ryv-eligibility-tool'), value: 'radio' },
  { label: __('Checkbox (multi choice)', 'ryv-eligibility-tool'), value: 'checkbox' },
  { label: __('Text input', 'ryv-eligibility-tool'), value: 'text' },
  { label: __('Textarea', 'ryv-eligibility-tool'), value: 'textarea' },
  { label: __('Markup (display only)', 'ryv-eligibility-tool'), value: 'markup' },
  { label: __('Form Submission', 'ryv-eligibility-tool'), value: 'lead_capture' },
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
 *   next_node) across the whole graph - see App.js's renameNode().
 */
export default function NodeEditor({ node, nodeId, allNodeIds, allNodes, onChange, onRename }) {
  const [idDraft, setIdDraft] = useState(nodeId);

  useEffect(() => {
    setIdDraft(nodeId);
  }, [nodeId]);

  const isChoice = node.type === 'radio' || node.type === 'checkbox';

  // Suggestions only, via <datalist> - group is a free-text field (see
  // NodeList.js's groupKeyOf(), which falls back to an id prefix or
  // 'general' when it's unset), so existing values are offered but never
  // enforced as an enum.
  const groupSuggestions = useMemo(() => {
    const groups = new Set();
    Object.values(allNodes || {}).forEach((n) => {
      if (n.group) groups.add(n.group);
    });
    return Array.from(groups).sort();
  }, [allNodes]);

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
        help={__(
          'Rendered as HTML on the frontend - inline tags like <em>word</em> or <strong>word</strong> work.',
          'ryv-eligibility-tool'
        )}
      />

      <TextControl
        label={__('Group', 'ryv-eligibility-tool')}
        value={node.group || ''}
        onChange={(group) => onChange({ ...node, group })}
        list="ryv-group-suggestions"
        help={__(
          'Controls which collapsible bucket this question is filed under in the node list. Leave blank to fall back to the id prefix (before "__") or "General".',
          'ryv-eligibility-tool'
        )}
      />
      <datalist id="ryv-group-suggestions">
        {groupSuggestions.map((group) => (
          <option key={group} value={group} />
        ))}
      </datalist>

      {node.type === 'markup' && (
        <div className="ryv-builder-field">
          <label className="components-base-control__label">{__('Content (HTML)', 'ryv-eligibility-tool')}</label>
          <ClassicEditor
            key={nodeId}
            id={`ryv-node-content-${nodeId}`}
            value={node.content || ''}
            onChange={(content) => onChange({ ...node, content })}
          />
        </div>
      )}

      {node.type === 'radio' && (
        <SelectControl
          label={__('Display as', 'ryv-eligibility-tool')}
          value={node.display_style || 'buttons'}
          options={DISPLAY_STYLE_OPTIONS}
          onChange={(display_style) => onChange({ ...node, display_style })}
        />
      )}

      {node.type === 'lead_capture' && (
        <div className="ryv-builder-field ryv-builder-lead-capture-settings">
          <h3>{__('On submit, do the following:', 'ryv-eligibility-tool')}</h3>

          <CheckboxControl
            label={__('Save submission to the database', 'ryv-eligibility-tool')}
            checked={!!(node.actions && node.actions.save_to_db)}
            onChange={(save_to_db) => onChange({ ...node, actions: { ...node.actions, save_to_db } })}
          />

          <CheckboxControl
            label={__('Email the admin', 'ryv-eligibility-tool')}
            checked={!!(node.actions && node.actions.email_admin)}
            onChange={(email_admin) => onChange({ ...node, actions: { ...node.actions, email_admin } })}
          />

          {node.actions && node.actions.email_admin && (
            <TextControl
              label={__('Admin email address', 'ryv-eligibility-tool')}
              value={(node.actions && node.actions.admin_email_address) || ''}
              onChange={(admin_email_address) => onChange({ ...node, actions: { ...node.actions, admin_email_address } })}
            />
          )}

          <CheckboxControl
            label={__('Email the user', 'ryv-eligibility-tool')}
            checked={!!(node.actions && node.actions.email_user)}
            onChange={(email_user) => onChange({ ...node, actions: { ...node.actions, email_user } })}
          />

          {node.actions && node.actions.email_user && (
            <TextareaControl
              label={__('Message to send the user (with their results)', 'ryv-eligibility-tool')}
              value={(node.actions && node.actions.user_email_message) || ''}
              onChange={(user_email_message) => onChange({ ...node, actions: { ...node.actions, user_email_message } })}
            />
          )}
        </div>
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
