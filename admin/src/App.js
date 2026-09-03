import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl, TextareaControl, SelectControl, Notice, Spinner } from '@wordpress/components';
import NodeList from './components/NodeList';
import NodeEditor from './components/NodeEditor';
import ShortcodeSnippet from './components/ShortcodeSnippet';
import { fetchForm, saveForm, createForm, updateFormStatus, updateFormMeta } from './api';

const STATUS_OPTIONS = [
  { label: __( 'Draft', 'ryv-eligibility-tool' ), value: 'draft' },
  { label: __( 'Published', 'ryv-eligibility-tool' ), value: 'publish' },
];

const settings = window.RYVBuilderSettings || {};

function emptySchema() {
  return { entry_node: null, nodes: {} };
}

export default function App() {
  const [postId] = useState(settings.postId || null);
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [status, setStatus] = useState('draft');
  const [statusSaving, setStatusSaving] = useState(false);
  const [metaSaving, setMetaSaving] = useState(false);
  // Last value actually persisted for title/description - lets onBlur skip
  // the PATCH entirely when nothing changed (e.g. clicking in and back out).
  const savedMetaRef = useRef({ title: '', description: '' });
  const [schema, setSchema] = useState(emptySchema());
  const [selectedNodeId, setSelectedNodeId] = useState(null);
  const [loading, setLoading] = useState(!!postId);
  const [saving, setSaving] = useState(false);
  const [creating, setCreating] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [newTitle, setNewTitle] = useState('');
  // Cycles found in the graph by RYV_Graph_Validator (see class-ryv-graph-
  // validator.php's find_cycles()) - a node reachable back to itself via
  // next_node/options[].next_node, which re-asks the same question(s)
  // forever once a visitor reaches it. Refreshed on load and after every
  // save so an edit that introduces one is flagged without blocking the
  // save itself - unlike a broken link, a cycle isn't necessarily wrong
  // (e.g. a deliberate "come back later" loop), so this is advisory only.
  const [cycles, setCycles] = useState([]);
  const [cyclesDismissed, setCyclesDismissed] = useState(false);

  useEffect(() => {
    if (!postId) return;

    fetchForm(postId)
      .then((data) => {
        setTitle(data.title);
        setDescription(data.description || '');
        savedMetaRef.current = { title: data.title, description: data.description || '' };
        setStatus(data.status || 'draft');
        const loadedSchema = data.schema && data.schema.nodes ? data.schema : emptySchema();
        setSchema(loadedSchema);

        // Deep link from the YAML import review notice ("Edit in builder →"
        // links to ?ryv_node=<id>) - land straight on the flagged node
        // instead of making the admin hunt for it in a 400+-node list.
        const requestedNodeId = new URLSearchParams(window.location.search).get('ryv_node');
        setSelectedNodeId(requestedNodeId && loadedSchema.nodes[requestedNodeId] ? requestedNodeId : null);

        setCycles((data.health && data.health.cycles) || []);
        setError(null);
      })
      .catch((err) => setError(err.message))
      .finally(() => setLoading(false));
  }, [postId]);

  /** Commits a title/description edit on blur - skipped entirely if unchanged since the last save. */
  const commitMeta = (field, value) => {
    if (savedMetaRef.current[field] === value) return;
    setMetaSaving(true);
    updateFormMeta(postId, { [field]: value })
      .then(() => {
        savedMetaRef.current[field] = value;
        setNotice(__('Saved.', 'ryv-eligibility-tool'));
        window.setTimeout(() => setNotice(null), 3000);
      })
      .catch((err) => setError(err.message))
      .finally(() => setMetaSaving(false));
  };

  const handleStatusChange = (newStatus) => {
    setStatusSaving(true);
    updateFormStatus(postId, newStatus)
      .then(() => {
        setStatus(newStatus);
        setNotice(
          'publish' === newStatus
            ? __( 'Published.', 'ryv-eligibility-tool' )
            : __( 'Switched to Draft.', 'ryv-eligibility-tool' )
        );
        window.setTimeout(() => setNotice(null), 3000);
      })
      .catch((err) => setError(err.message))
      .finally(() => setStatusSaving(false));
  };

  const handleCreate = () => {
    if (!newTitle.trim()) return;
    setCreating(true);
    createForm(newTitle.trim())
      .then((post) => {
        const url = new URL(window.location.href);
        url.searchParams.set('post_id', post.id);
        window.location.href = url.toString();
      })
      .catch((err) => {
        setError(err.message);
        setCreating(false);
      });
  };

  const updateGraph = useCallback((updater) => {
    setSchema((prev) => updater(prev));
    setDirty(true);
  }, []);

  const handleSave = () => {
    setSaving(true);
    saveForm(postId, schema)
      .then((data) => {
        setDirty(false);
        setNotice(__('Saved.', 'ryv-eligibility-tool'));
        window.setTimeout(() => setNotice(null), 3000);
        setCycles((data.health && data.health.cycles) || []);
        setCyclesDismissed(false);
      })
      .catch((err) => setError(err.message))
      .finally(() => setSaving(false));
  };

  /** Renaming a node id has to fix up every reference to it across the whole graph. */
  const renameNode = (oldId, newId) => {
    updateGraph((prev) => {
      if (prev.nodes[newId]) return prev; // collision guard (NodeEditor already checks, this is a second line of defense)

      const nodes = {};
      Object.entries(prev.nodes).forEach(([id, node]) => {
        const fixed = { ...node };
        if (Array.isArray(fixed.options) && fixed.options.length) {
          fixed.options = fixed.options.map((opt) => ({
            ...opt,
            next_node: opt.next_node === oldId ? newId : opt.next_node,
          }));
        } else if (fixed.next_node === oldId) {
          fixed.next_node = newId;
        }
        nodes[id === oldId ? newId : id] = fixed;
      });

      return {
        ...prev,
        nodes,
        entry_node: prev.entry_node === oldId ? newId : prev.entry_node,
      };
    });
    setSelectedNodeId(newId);
  };

  if (!postId) {
    return (
      <div className="ryv-builder-new">
        <h1>{__('New Eligibility Form', 'ryv-eligibility-tool')}</h1>
        {error && (
          <Notice status="error" onRemove={() => setError(null)}>
            {error}
          </Notice>
        )}
        <TextControl label={__('Title', 'ryv-eligibility-tool')} value={newTitle} onChange={setNewTitle} />
        <Button variant="primary" onClick={handleCreate} isBusy={creating} disabled={creating || !newTitle.trim()}>
          {__('Create', 'ryv-eligibility-tool')}
        </Button>
      </div>
    );
  }

  if (loading) {
    return <Spinner />;
  }

  return (
    <div className="ryv-builder">
      <div className="ryv-builder-header">
        <TextControl
          className="ryv-builder-title-input"
          label={__('Form title', 'ryv-eligibility-tool')}
          hideLabelFromVision
          value={title}
          onChange={setTitle}
          onBlur={() => commitMeta('title', title)}
          onKeyDown={(event) => {
            if ('Enter' === event.key) event.target.blur();
          }}
          __next40pxDefaultSize
          __nextHasNoMarginBottom
        />
        {metaSaving && <span className="ryv-builder-meta-saving">{__('Saving…', 'ryv-eligibility-tool')}</span>}
        <div className="ryv-builder-header-actions">
          {notice && (
            <Notice status="success" isDismissible={false}>
              {notice}
            </Notice>
          )}
          {error && (
            <Notice status="error" onRemove={() => setError(null)}>
              {error}
            </Notice>
          )}
          <SelectControl
            label={__('Status', 'ryv-eligibility-tool')}
            hideLabelFromVision
            value={status}
            options={STATUS_OPTIONS}
            onChange={handleStatusChange}
            disabled={statusSaving}
            __next40pxDefaultSize
            __nextHasNoMarginBottom
          />
          {settings.exportUrl && (
            <Button variant="secondary" href={settings.exportUrl}>
              {__('Export YAML', 'ryv-eligibility-tool')}
            </Button>
          )}
          <Button variant="primary" onClick={handleSave} isBusy={saving} disabled={saving || !dirty}>
            {dirty ? __('Save Changes', 'ryv-eligibility-tool') : __('Saved', 'ryv-eligibility-tool')}
          </Button>
        </div>
      </div>

      <ShortcodeSnippet postId={postId} />

      <TextareaControl
        className="ryv-builder-description-input"
        label={__('Description', 'ryv-eligibility-tool')}
        help={__('Shown on the frontend only if the Elementor widget’s "Show Description" setting is enabled.', 'ryv-eligibility-tool')}
        value={description}
        onChange={setDescription}
        onBlur={() => commitMeta('description', description)}
        rows={2}
        __nextHasNoMarginBottom
      />

      {cycles.length > 0 && !cyclesDismissed && (
        <Notice status="warning" onRemove={() => setCyclesDismissed(true)}>
          <p>
            <strong>
              {cycles.length === 1
                ? __('This form loops back on itself:', 'ryv-eligibility-tool')
                : __('This form has questions that loop back on themselves:', 'ryv-eligibility-tool')}
            </strong>
          </p>
          <p>
            {__(
              'A visitor who reaches one of these will be asked the same question(s) again, forever, instead of ever reaching a result. This is usually caused by a "Next" field pointing back at an earlier question - open each node below and check where it routes to.',
              'ryv-eligibility-tool'
            )}
          </p>
          <ul style={{ listStyle: 'disc', marginLeft: '1.5em' }}>
            {cycles.map((cycle, i) => (
              <li key={i}>
                {cycle.map((nodeId, j) => (
                  <span key={nodeId}>
                    {j > 0 && ' → '}
                    <a href="#" onClick={(e) => { e.preventDefault(); setSelectedNodeId(nodeId); }}>
                      <code>{nodeId}</code>
                    </a>
                  </span>
                ))}
                {' → '}
                <code>{cycle[0]}</code>
              </li>
            ))}
          </ul>
        </Notice>
      )}

      <div className="ryv-builder-body">
        <NodeList graph={schema} selectedNodeId={selectedNodeId} onSelect={setSelectedNodeId} onChange={updateGraph} />

        {selectedNodeId && schema.nodes[selectedNodeId] ? (
          <NodeEditor
            node={schema.nodes[selectedNodeId]}
            nodeId={selectedNodeId}
            allNodeIds={Object.keys(schema.nodes)}
            allNodes={schema.nodes}
            onRename={renameNode}
            onChange={(updatedNode) =>
              updateGraph((prev) => ({
                ...prev,
                nodes: { ...prev.nodes, [selectedNodeId]: updatedNode },
              }))
            }
          />
        ) : (
          <div className="ryv-builder-empty-state">
            <span className="dashicons dashicons-forms" aria-hidden="true" />
            <p>
              {Object.keys(schema.nodes).length
                ? __('Select a question from the list to edit it, or add a new one to get started.', 'ryv-eligibility-tool')
                : __('This form has no questions yet - click "+ Add Question" to create the first one.', 'ryv-eligibility-tool')}
            </p>
          </div>
        )}
      </div>
    </div>
  );
}
