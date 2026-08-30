import { useState, useEffect, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { TextControl, SelectControl, TabPanel, TextareaControl, ToggleControl } from '@wordpress/components';
import OptionsEditor from './OptionsEditor';
import ReferenceNodesEditor from './ReferenceNodesEditor';
import ClassicEditor from './ClassicEditor';
import FormFieldsEditor from './FormFieldsEditor';
import EmailSettingsEditor from './EmailSettingsEditor';

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
  // Text/Textarea/Checkbox nodes have no fields in the "Content & Behavior"
  // section below - skip the heading entirely for them rather than showing
  // an empty section between "Organization" and "Routing".
  const hasContentBehaviorFields = ['markup', 'unsupported', 'radio', 'lead_capture'].includes(node.type);

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

  const referenceNodeOptions = [
    { label: __('— None —', 'ryv-eligibility-tool'), value: '' },
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
      <div className="ryv-builder-node-editor-section-title">{__('Basic Info', 'ryv-eligibility-tool')}</div>

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

      <TextareaControl
        label={__('Tooltip (Help Text)', 'ryv-eligibility-tool')}
        value={node.help || ''}
        onChange={(help) => onChange({ ...node, help })}
        rows={2}
        help={__(
          'Optional - shown as a "?" icon right after the title; clicking it reveals this text. Same HTML trust level as Title. A YAML import sets this automatically from Drupal\'s #help.',
          'ryv-eligibility-tool'
        )}
      />

      <TextareaControl
        label={__('Description', 'ryv-eligibility-tool')}
        value={node.description || ''}
        onChange={(description) => onChange({ ...node, description })}
        rows={2}
        help={__(
          'Optional helper text shown below the title, above the options/input. Same HTML trust level as Title.',
          'ryv-eligibility-tool'
        )}
      />

      <div className="ryv-builder-node-editor-section-title">{__('Organization', 'ryv-eligibility-tool')}</div>

      <TextControl
        label={__('Step Name (shown to visitors)', 'ryv-eligibility-tool')}
        value={node.step_name || ''}
        onChange={(step_name) => onChange({ ...node, step_name })}
        help={__(
          'Optional label for the widget\'s step sidebar (when enabled). Leave blank to keep this question folded into the previous named step instead of starting a new one - unlike Group below, this is visitor-facing.',
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

      <TextControl
        label={__('CSS Class', 'ryv-eligibility-tool')}
        value={node.wrapper_class || ''}
        onChange={(wrapper_class) => onChange({ ...node, wrapper_class })}
        help={__(
          'Optional, space-separated custom class(es) added to this question\'s container on the frontend - e.g. "text-restrict-height" or "message--path-end", the same classes a YAML import can set automatically from Drupal\'s wrapper_attributes.',
          'ryv-eligibility-tool'
        )}
      />

      {hasContentBehaviorFields && (
        <div className="ryv-builder-node-editor-section-title">{__('Content & Behavior', 'ryv-eligibility-tool')}</div>
      )}

      {(node.type === 'markup' || node.type === 'unsupported') && (
        <ToggleControl
          label={__('Hidden', 'ryv-eligibility-tool')}
          checked={!!node.hidden}
          onChange={(hidden) => onChange({ ...node, hidden })}
          help={__(
            'When on, this node is skipped entirely on the frontend - no card is shown - but the flow still passes through it to its own "Next question" target, so routing keeps working. A YAML import turns this on automatically when an element had both a "visible" and an "invisible" Drupal condition that couldn\'t be safely combined (the invisible half was ignored rather than guessed) - review the original YAML and turn this off if the element should actually show.',
            'ryv-eligibility-tool'
          )}
        />
      )}

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

      {node.type === 'radio' && node.display_style === 'dropdown' && (
        <TextControl
          label={__('Placeholder', 'ryv-eligibility-tool')}
          value={node.placeholder || ''}
          onChange={(placeholder) => onChange({ ...node, placeholder })}
          help={__(
            'Shown before an option is chosen, for this question only. Leave blank to use the form-wide default ("— Select —", or whatever the Elementor widget\'s Labels tab sets it to).',
            'ryv-eligibility-tool'
          )}
        />
      )}

      {node.type === 'radio' && node.display_style === 'dropdown' && (
        <ToggleControl
          label={__('Searchable (Select2)', 'ryv-eligibility-tool')}
          checked={!!node.select2}
          onChange={(select2) => onChange({ ...node, select2 })}
          help={__(
            'When on, this dropdown becomes a type-to-filter combobox instead of a plain native dropdown - useful for long option lists (e.g. a state picker). A YAML import turns this on automatically when the source had "#select2: true".',
            'ryv-eligibility-tool'
          )}
        />
      )}

      {node.type === 'lead_capture' && (
        <div className="ryv-builder-field ryv-builder-lead-capture-settings">
          <TabPanel
            className="ryv-builder-lead-capture-tabs"
            tabs={[
              { name: 'fields', title: __('Form Fields', 'ryv-eligibility-tool') },
              { name: 'email', title: __('Email Settings', 'ryv-eligibility-tool') },
            ]}
          >
            {(tab) =>
              'fields' === tab.name ? (
                <>
                  <FormFieldsEditor
                    fields={node.form_fields || []}
                    onChange={(form_fields) => onChange({ ...node, form_fields })}
                  />
                  <TextareaControl
                    label={__('Success message', 'ryv-eligibility-tool')}
                    value={node.success_message || ''}
                    onChange={(success_message) => onChange({ ...node, success_message })}
                    help={__(
                      'Shown in place of the form after a successful submission. Leave blank to use the default: "Thank you! Your submission has been received."',
                      'ryv-eligibility-tool'
                    )}
                  />
                </>
              ) : (
                <EmailSettingsEditor
                  emailSettings={node.email_settings || {}}
                  formFields={node.form_fields || []}
                  onChange={(email_settings) => onChange({ ...node, email_settings })}
                />
              )
            }
          </TabPanel>
        </div>
      )}

      <div className="ryv-builder-node-editor-section-title">{__('Routing', 'ryv-eligibility-tool')}</div>

      {isChoice ? (
        <>
          <OptionsEditor
            options={node.options || []}
            nextNodeOptions={nextNodeOptions}
            onChange={(options) => onChange({ ...node, options })}
          />

          <ReferenceNodesEditor
            referenceNodeIds={node.reference_node_ids || []}
            referenceNodeOptions={referenceNodeOptions}
            onChange={(reference_node_ids) => onChange({ ...node, reference_node_ids })}
          />
        </>
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
