import { fetchForm, fetchFormState, logSubmission } from './api-client.js';
import * as renderer from './renderer.js';

const STATE_CARD_ID = '__state__';
const LOADING_CARD_ID = '__loading__';
const ERROR_CARD_ID = '__error__';

/**
 * One instance per `.ryv-app-wrapper[data-form-id]` on the page.
 *
 * The whole questionnaire is rendered as a single growing list of cards -
 * every question ever reached stays visible and stays clickable. There is
 * no Back/Continue chrome: changing an answer on any card (including the
 * state picker) just recomputes and re-renders everything after it, using
 * whatever the new answer routes to. See onAnswerChange()/renderFrom().
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
    // One id per attempt at the questionnaire - restart() rolls a new one so
    // an abandoned-then-restarted trail stays on record as its own row
    // (see RYV_Submission_Log) instead of being overwritten in place.
    this.sessionId = this.generateSessionId();
    this.list = null;
    this.nodes = {};
    this.entryNode = null;
    this.availableStates = [];
    this.stateDisplayStyle = 'buttons';
    this.statePickerLabel = 'Select your state';
    this.stateId = null;
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
   * Upsert this attempt's answer trail so far - called after every state
   * pick and every answer change. No-op until a state has been picked
   * (nothing meaningful to log before then).
   */
  logProgress() {
    if (!this.stateId) return;

    const lastRealNodeId = [...this.renderedOrder].reverse().find((id) => this.nodes[id]);
    const answers = {};
    this.answers.forEach((value, key) => {
      answers[key] = value;
    });

    logSubmission(this.formId, {
      session_id: this.sessionId,
      state_id: this.stateId,
      current_node: lastRealNodeId || null,
      answers,
    });
  }

  mount() {
    this.root.classList.add('ryv-app-wrapper');
    this.list = renderer.createList();
    this.root.appendChild(this.list);
    this.loadForm();
  }

  async loadForm() {
    this.appendCard(LOADING_CARD_ID, renderer.renderLoadingCard(this.labels));
    try {
      const data = await fetchForm(this.formId);
      this.availableStates = data.states || [];
      this.stateDisplayStyle = data.state_display_style || 'buttons';
      this.statePickerLabel = data.state_picker_label || 'Select your state';
      this.truncateFrom(0);
      this.renderStateCard();
    } catch (err) {
      this.truncateFrom(0);
      this.appendCard(ERROR_CARD_ID, renderer.renderErrorCard(err.message, () => this.loadForm(), this.labels));
    }
  }

  renderStateCard() {
    const card = renderer.renderStateCard(
      this.availableStates,
      this.stateId,
      (stateId) => this.onSelectState(stateId),
      this.stateDisplayStyle,
      this.statePickerLabel,
      this.labels
    );
    this.appendCard(STATE_CARD_ID, card, false);
  }

  async onSelectState(stateId) {
    const changed = stateId !== this.stateId;
    this.stateId = stateId;

    this.truncateFrom(this.indexOf(STATE_CARD_ID));
    this.renderStateCard();

    if (!changed) return;

    this.answers = new Map();
    this.appendCard(LOADING_CARD_ID, renderer.renderLoadingCard(this.labels));

    try {
      const data = await fetchFormState(this.formId, stateId);
      this.nodes = data.nodes || {};
      this.entryNode = data.entry_node;
      this.truncateFrom(this.indexOf(LOADING_CARD_ID));
      this.renderFrom(this.entryNode, true);
      this.logProgress();
    } catch (err) {
      this.truncateFrom(this.indexOf(LOADING_CARD_ID));
      this.appendCard(
        ERROR_CARD_ID,
        renderer.renderErrorCard(err.message, () => this.onSelectState(stateId), this.labels)
      );
    }
  }

  restart() {
    this.stateId = null;
    this.answers = new Map();
    this.nodes = {};
    // A fresh id, not a cleared one: without this, restarting would reuse
    // the same session row and RYV_Submission_Log::record() would overwrite
    // the trail that was just abandoned instead of leaving it on record.
    this.sessionId = this.generateSessionId();
    this.truncateFrom(0);
    this.renderStateCard();
  }

  /**
   * Render `nodeId` and everything that follows it per the current answers,
   * appending after whatever is already on screen.
   */
  renderFrom(nodeId, animate) {
    let current = nodeId;

    while (current) {
      const node = this.nodes[current];

      if (!node) {
        this.appendCard(current, renderer.renderErrorCard('This step could not be found.', null, this.labels), animate);
        return;
      }

      const next = this.computeNext(current, node);
      const isTerminal = !next && (node.type === 'markup' || node.type === 'unsupported');
      this.appendCard(current, this.buildCard(current, node, isTerminal), animate);

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
          this.labels
        );
      case 'text':
      case 'textarea':
        return renderer.renderInputCard(node, this.answers.get(nodeId) || '', (value) => this.onAnswerChange(nodeId, value));
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
    this.appendCard(nodeId, this.buildCard(nodeId, node, isTerminal), false);

    if (next) this.renderFrom(next, true);
    this.logProgress();
  }

  // --- rendered-list bookkeeping -------------------------------------

  appendCard(id, element, animate = true) {
    this.renderedOrder.push(id);
    this.cardElements.set(id, element);
    renderer.appendCard(this.list, element, animate);
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
  }
}

export function initAll(root = document) {
  root.querySelectorAll('.ryv-app-wrapper[data-form-id]').forEach((wrapper) => {
    if (wrapper.dataset.ryvInitialized) return;
    wrapper.dataset.ryvInitialized = 'true';
    new RYVDecisionEngine(wrapper).mount();
  });
}
