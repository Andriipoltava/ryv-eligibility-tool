import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl, SelectControl, Notice, Spinner } from '@wordpress/components';
import NodeList from './components/NodeList';
import NodeEditor from './components/NodeEditor';
import { fetchForm, saveForm, createForm, updateFormStatus } from './api';

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
  const [status, setStatus] = useState('draft');
  const [statusSaving, setStatusSaving] = useState(false);
  const [schema, setSchema] = useState(emptySchema());
  const [selectedNodeId, setSelectedNodeId] = useState(null);
  const [loading, setLoading] = useState(!!postId);
  const [saving, setSaving] = useState(false);
  const [creating, setCreating] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [newTitle, setNewTitle] = useState('');

  useEffect(() => {
    if (!postId) return;

    fetchForm(postId)
      .then((data) => {
        setTitle(data.title);
        setStatus(data.status || 'draft');
        setSchema(data.schema && data.schema.nodes ? data.schema : emptySchema());
        setSelectedNodeId(null);
        setError(null);
      })
      .catch((err) => setError(err.message))
      .finally(() => setLoading(false));
  }, [postId]);

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
      .then(() => {
        setDirty(false);
        setNotice(__('Saved.', 'ryv-eligibility-tool'));
        window.setTimeout(() => setNotice(null), 3000);
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
        <h1>{title}</h1>
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
          <Button variant="primary" onClick={handleSave} isBusy={saving} disabled={saving || !dirty}>
            {dirty ? __('Save Changes', 'ryv-eligibility-tool') : __('Saved', 'ryv-eligibility-tool')}
          </Button>
        </div>
      </div>

      <div className="ryv-builder-body">
        <NodeList graph={schema} selectedNodeId={selectedNodeId} onSelect={setSelectedNodeId} onChange={updateGraph} />

        {selectedNodeId && schema.nodes[selectedNodeId] && (
          <NodeEditor
            node={schema.nodes[selectedNodeId]}
            nodeId={selectedNodeId}
            allNodeIds={Object.keys(schema.nodes)}
            onRename={renameNode}
            onChange={(updatedNode) =>
              updateGraph((prev) => ({
                ...prev,
                nodes: { ...prev.nodes, [selectedNodeId]: updatedNode },
              }))
            }
          />
        )}
      </div>
    </div>
  );
}
