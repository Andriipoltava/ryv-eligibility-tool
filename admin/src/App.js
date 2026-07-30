import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl, Notice, Spinner } from '@wordpress/components';
import StateSidebar from './components/StateSidebar';
import NodeList from './components/NodeList';
import NodeEditor from './components/NodeEditor';
import { fetchForm, saveForm, createForm } from './api';

const settings = window.RYVBuilderSettings || {};

function emptySchema() {
  return { states: {} };
}

export default function App() {
  const [postId] = useState(settings.postId || null);
  const [title, setTitle] = useState('');
  const [schema, setSchema] = useState(emptySchema());
  const [activeStateId, setActiveStateId] = useState(null);
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
        setSchema(data.schema && data.schema.states ? data.schema : emptySchema());
        setActiveStateId(Object.keys(data.schema?.states || {})[0] || null);
        setError(null);
      })
      .catch((err) => setError(err.message))
      .finally(() => setLoading(false));
  }, [postId]);

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

  const updateSchema = useCallback((updater) => {
    setSchema((prev) => updater(prev));
    setDirty(true);
  }, []);

  const updateActiveState = useCallback(
    (updater) => {
      updateSchema((prev) => ({
        ...prev,
        states: {
          ...prev.states,
          [activeStateId]: updater(prev.states[activeStateId]),
        },
      }));
    },
    [activeStateId, updateSchema]
  );

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

  const addState = (stateId, label) => {
    updateSchema((prev) => ({
      ...prev,
      states: {
        ...prev.states,
        [stateId]: { state_id: stateId, label, entry_node: null, nodes: {} },
      },
    }));
    setActiveStateId(stateId);
    setSelectedNodeId(null);
  };

  const removeState = (stateId) => {
    updateSchema((prev) => {
      const states = { ...prev.states };
      delete states[stateId];
      return { ...prev, states };
    });
    if (activeStateId === stateId) {
      setActiveStateId(null);
      setSelectedNodeId(null);
    }
  };

  /** Renaming a node id has to fix up every reference to it in the same state. */
  const renameNode = (oldId, newId) => {
    updateActiveState((state) => {
      if (state.nodes[newId]) return state; // collision guard (NodeEditor already checks, this is a second line of defense)

      const nodes = {};
      Object.entries(state.nodes).forEach(([id, node]) => {
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
        ...state,
        nodes,
        entry_node: state.entry_node === oldId ? newId : state.entry_node,
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

  const activeState = activeStateId ? schema.states[activeStateId] : null;

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
          <Button variant="primary" onClick={handleSave} isBusy={saving} disabled={saving || !dirty}>
            {dirty ? __('Save Changes', 'ryv-eligibility-tool') : __('Saved', 'ryv-eligibility-tool')}
          </Button>
        </div>
      </div>

      <StateSidebar
        states={schema.states}
        activeStateId={activeStateId}
        onSelect={(id) => {
          setActiveStateId(id);
          setSelectedNodeId(null);
        }}
        onAdd={addState}
        onRemove={removeState}
        stateDisplayStyle={schema.state_display_style}
        onStateDisplayStyleChange={(state_display_style) =>
          updateSchema((prev) => ({ ...prev, state_display_style }))
        }
        statePickerLabel={schema.state_picker_label}
        onStatePickerLabelChange={(state_picker_label) =>
          updateSchema((prev) => ({ ...prev, state_picker_label }))
        }
      />

      {activeState && (
        <div className="ryv-builder-body">
          <NodeList
            state={activeState}
            selectedNodeId={selectedNodeId}
            onSelect={setSelectedNodeId}
            onChange={updateActiveState}
          />

          {selectedNodeId && activeState.nodes[selectedNodeId] && (
            <NodeEditor
              node={activeState.nodes[selectedNodeId]}
              nodeId={selectedNodeId}
              allNodeIds={Object.keys(activeState.nodes)}
              onRename={renameNode}
              onChange={(updatedNode) =>
                updateActiveState((state) => ({
                  ...state,
                  nodes: { ...state.nodes, [selectedNodeId]: updatedNode },
                }))
              }
            />
          )}
        </div>
      )}
    </div>
  );
}
