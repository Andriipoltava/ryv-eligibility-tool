// See the matching comment in ../decision-engine.js - these query strings
// are the only thing cache-busting these nested imports; bump them with
// every RYV_VERSION change.
import { fetchForm, logSubmission, submitLead } from './api-client.js?ver=0.8.15';
import * as renderer from './renderer.js?ver=0.8.15';

const LOADING_CARD_ID = '__loading__';
const ERROR_CARD_ID = '__error__';

/**
 * One instance per `.ryv-app-wrapper[data-form-id]` on the page.
 *
 * The whole questionnaire - including the "select your state" step, an
 * ordinary radio node like any other - is one flat decision graph fetched
 * in a single request and rendered as a growing list of cards. Every
 * question ever reached stays visible and stays clickable. There is no
 * Back/Continue chrome: changing any answer just recomputes and re-renders
 * everything after it, using whatever the new answer routes to. See
 * onAnswerChange()/renderFrom().
 */
export class RYVDecisionEngine {
  constructor(root) {
    this.root = root;
    this.formId = root.dataset.formId;
    // The widget only omits this attribute for placements saved before this
    // setting existed - default to showing the button, matching how those
    // placements already behaved.
    this.showStartOver = root.dataset.showStartOver !== '0';
    this.labels = { ...renderer.DEFAULT_LABELS, ...this.parseLabelsAttribute(root) };
    // Set by the Elementor widget's "Auto-Detect State From Page" mode
    // (class-ryv-widget.php's get_state_entry_override()) when this
    // placement is on a `ryv_states` page linked to a form: overrides where
    // rendering starts (and where restart() goes back to) so the visitor
    // lands straight on that state's first question instead of the full
    // multi-state picker. Empty/absent for every other placement.
    this.entryNodeOverride = root.dataset.entryNode || null;
    this.showSteps = root.dataset.showSteps === '1';
    this.totalSteps = parseInt(root.dataset.totalSteps, 10) || 0;
    this.stepEl = null;
    // One id per attempt at the questionnaire - restart() rolls a new one so
    // an abandoned-then-restarted trail stays on record as its own row
    // (see RYV_Submission_Log) instead of being overwritten in place.
    this.sessionId = this.generateSessionId();
    this.list = null;
    this.nodes = {};
    this.entryNode = null;
    this.answers = new Map(); // node id -> string[] (radio/checkbox values) | string (text/textarea)
    this.renderedOrder = []; // ids (real node ids, or the sentinel ids above) in on-screen order
    this.cardElements = new Map(); // id -> DOM element
  }

  /**
   * `data-labels` is a JSON object of overrides for a subset of
   * renderer.DEFAULT_LABELS (only keys with a non-empty value in the widget's
   * "Labels" controls are included, see class-ryv-widget.php). Malformed or
   * absent JSON falls back to no overrides rather than breaking the mount.
   */
  parseLabelsAttribute(root) {
    try {
      return JSON.parse(root.dataset.labels || '{}');
    } catch (err) {
      return {};
    }
  }

  generateSessionId() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    return `ryv-${Date.now()}-${Math.random().toString(36).slice(2)}`;
  }

  /**
   * Upsert this attempt's answer trail so far - called after every answer
   * change. No-op until at least one answer has been recorded (nothing
   * meaningful to log before then).
   */
  logProgress() {
    if (this.answers.size === 0) return;

    const lastRealNodeId = [...this.renderedOrder].reverse().find((id) => this.nodes[id]);
    const answers = {};
    this.answers.forEach((value, key) => {
      answers[key] = value;
    });

    logSubmission(this.formId, {
      session_id: this.sessionId,
      current_node: lastRealNodeId || null,
      answers,
    });
  }

  mount() {
    this.root.classList.add('ryv-app-wrapper');
    if (this.showSteps) {
      this.stepEl = renderer.renderStepIndicator(this.labels);
      this.root.appendChild(this.stepEl);
    }
    this.list = renderer.createList();
    this.root.appendChild(this.list);
    this.loadForm();
  }

  async loadForm() {
    this.appendCard(LOADING_CARD_ID, renderer.renderLoadingCard(this.labels));
    try {
      const data = await fetchForm(this.formId);
      this.nodes = data.nodes || {};
      // Only use the override if it actually resolves in this form's own
      // graph - a stale override (e.g. the state page's linked form was
      // repointed after this widget instance was configured) should fall
      // back to the form's normal entry_node rather than render nothing.
      this.entryNode = (this.entryNodeOverride && this.nodes[this.entryNodeOverride])
        ? this.entryNodeOverride
        : data.entry_node;
      this.truncateFrom(0);
      this.renderFrom(this.entryNode, true);
    } catch (err) {
      this.truncateFrom(0);
      this.appendCard(ERROR_CARD_ID, renderer.renderErrorCard(err.message, () => this.loadForm(), this.labels));
    }
  }

  restart() {
    this.answers = new Map();
    // A fresh id, not a cleared one: without this, restarting would reuse
    // the same session row and RYV_Submission_Log::record() would overwrite
    // the trail that was just abandoned instead of leaving it on record.
    this.sessionId = this.generateSessionId();
    this.truncateFrom(0);
    this.renderFrom(this.entryNode, true);
  }

  /**
   * "Current step" has no fixed meaning in a branching DAG (see CLAUDE.md -
   * different answers reach different numbers of questions), so this counts
   * however many real, on-screen question cards exist right now - i.e. how
   * far into *this* visitor's own path they've gotten. Excludes the
   * loading/error sentinel cards and any `node.hidden` node (never given a
   * card at all - see renderFrom()), same as what's actually visible.
   */
  updateStepIndicator() {
    if (!this.stepEl) return;
    let count = 0;
    this.cardElements.forEach((el, id) => {
      if (this.nodes[id]) count += 1;
    });
    renderer.updateStepIndicator(this.stepEl, count, this.totalSteps, this.labels);
  }

  /**
   * Render `nodeId` and everything that follows it per the current answers,
   * appending after whatever is already on screen.
   */
  renderFrom(nodeId, animate) {
    let current = nodeId;
    // Nothing in the builder or the YAML importer prevents next_node from
    // routing back into an ancestor (only a direct self-reference is
    // blocked, in NodeEditor.js) - without this guard, a cyclic graph would
    // spin this loop forever and hang the visitor's tab.
    const visited = new Set();

    while (current) {
      if (visited.has(current)) {
        console.warn(`RYV: cycle detected in decision graph at node "${current}" - stopping.`);
        break;
      }
      visited.add(current);

      const node = this.nodes[current];

      if (!node) {
        this.appendCard(current, renderer.renderErrorCard('This step could not be found.', null, this.labels), animate);
        return;
      }

      const next = this.computeNext(current, node);
      const isTerminal = !next && (node.type === 'markup' || node.type === 'unsupported');
      // `node.hidden` (see RYV_YAML_Parser's "visible_and_invisible" case)
      // still routes through normally - it's just never given a card, so a
      // hidden markup node's own next_node keeps the chain moving
      // transparently instead of dead-ending the flow.
      this.appendCard(current, node.hidden ? null : this.buildCard(current, node, isTerminal), animate);

      if (!next) return;
      current = next;
    }
  }

  /**
   * Next node id given this node's currently recorded answer, or null if
   * the node auto-advances (markup/unsupported), has no configured target,
   * or is still awaiting user input.
   */
  computeNext(nodeId, node) {
    if (node.type === 'markup' || node.type === 'unsupported') {
      return node.next_node || null;
    }

    // lead_capture never auto-advances, even once submitted - the only way
    // forward is the explicit "Continue" click handled by onLeadContinue(),
    // so a card that gets rebuilt (e.g. an ancestor answer changing and
    // this node being reached again) waits for that click again rather than
    // silently skipping past itself.
    if (node.type === 'lead_capture') {
      return null;
    }

    const answer = this.answers.get(nodeId);

    if (node.type === 'text' || node.type === 'textarea') {
      return answer ? node.next_node || null : null;
    }

    // radio/checkbox: answer is the array of selected value(s).
    if (!answer || answer.length === 0) return null;

    const chosen = (node.options || []).find((option) => answer.includes(option.value));
    return chosen ? chosen.next_node || null : null;
  }

  buildCard(nodeId, node, isTerminal) {
    switch (node.type) {
      case 'radio':
      case 'checkbox':
        return renderer.renderQuestionCard(
          node,
          this.answers.get(nodeId) || [],
          (values) => this.onAnswerChange(nodeId, values),
          nodeId,
          this.labels,
          this.nodes
        );
      case 'text':
      case 'textarea':
        return renderer.renderInputCard(node, this.answers.get(nodeId) || '', (value) => this.onAnswerChange(nodeId, value));
      case 'lead_capture':
        return renderer.renderLeadCaptureCard(
          node,
          this.answers.get(nodeId) || null,
          (values) => this.onLeadSubmit(nodeId, values),
          () => this.onLeadContinue(nodeId),
          this.labels
        );
      case 'markup':
        return renderer.renderMarkupCard(node, isTerminal && this.showStartOver, () => this.restart(), this.labels);
      default:
        return renderer.renderUnsupportedCard(node, isTerminal && this.showStartOver, () => this.restart(), this.labels);
    }
  }

  onAnswerChange(nodeId, value) {
    const isEmpty = Array.isArray(value) ? value.length === 0 : !value;
    if (isEmpty) {
      this.answers.delete(nodeId);
    } else {
      this.answers.set(nodeId, value);
    }

    this.truncateFrom(this.indexOf(nodeId));

    const node = this.nodes[nodeId];
    const next = this.computeNext(nodeId, node);
    const isTerminal = !next && (node.type === 'markup' || node.type === 'unsupported');
    this.appendCard(nodeId, node.hidden ? null : this.buildCard(nodeId, node, isTerminal), false);

    if (next) this.renderFrom(next, true);
    this.logProgress();
  }

  /**
   * POSTs a `lead_capture` node's dynamic field values to the backend (which
   * reads that node's `email_settings` config - save_to_db/admin & user
   * emails/templates - and acts on it) before recording the answer. Left
   * un-caught here so renderLeadCaptureCard's own `.catch()` can show an
   * inline error and leave the form re-submittable, instead of silently
   * advancing.
   */
  onLeadSubmit(nodeId, values) {
    return submitLead(this.formId, { node_id: nodeId, form_data: values }).then(() => {
      this.onAnswerChange(nodeId, values);
    });
  }

  /**
   * Advances past an already-submitted `lead_capture` node once its
   * "Continue" button is clicked (see renderLeadCaptureCard) - computeNext()
   * deliberately never does this on its own, so this is the only path that
   * ever renders forward from one. No-op if `next_node` is unset; the
   * renderer only shows the button at all when it's set, but this guards
   * against a stale card from an already-rendered (and now out of date)
   * DOM node.
   */
  onLeadContinue(nodeId) {
    const node = this.nodes[nodeId];
    if (!node || !node.next_node) return;

    this.truncateFrom(this.indexOf(nodeId) + 1);
    this.renderFrom(node.next_node, true);
    this.logProgress();
  }

  // --- rendered-list bookkeeping -------------------------------------

  appendCard(id, element, animate = true) {
    this.renderedOrder.push(id);
    // `element` is null for a `node.hidden` node - still tracked in
    // renderedOrder (truncateFrom()/indexOf() need it to find its place in
    // the sequence when an earlier answer changes) but never inserted into
    // cardElements/the DOM, since there's nothing to remove later either.
    if (!element) {
      this.updateStepIndicator();
      return;
    }
    this.cardElements.set(id, element);
    renderer.appendCard(this.list, element, animate);
    this.updateStepIndicator();
  }

  indexOf(id) {
    return this.renderedOrder.indexOf(id);
  }

  /** Remove every rendered card from `index` (inclusive) to the end. */
  truncateFrom(index) {
    if (index < 0) return;
    const removed = this.renderedOrder.splice(index);
    removed.forEach((id) => {
      const element = this.cardElements.get(id);
      if (element && element.parentNode) element.parentNode.removeChild(element);
      this.cardElements.delete(id);
    });
    this.updateStepIndicator();
  }
}

export function initAll(root = document) {
  root.querySelectorAll('.ryv-app-wrapper[data-form-id]').forEach((wrapper) => {
    if (wrapper.dataset.ryvInitialized) return;
    wrapper.dataset.ryvInitialized = 'true';
    new RYVDecisionEngine(wrapper).mount();
  });
}
