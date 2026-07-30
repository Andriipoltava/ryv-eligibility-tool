import { fetchForm } from './api-client.js';
import * as renderer from './renderer.js';

const LOADING_CARD_ID = '__loading__';
const ERROR_CARD_ID = '__error__';

function hasOptions(node) {
  return Array.isArray(node.options) && node.options.length > 0;
}

function isMultiChoice(node) {
  return node.type === 'checkbox' || node.multiple === true;
}

function isTextInput(node) {
  return node.type === 'text' || node.type === 'textarea';
}

/**
 * One instance per `.ryv-app-wrapper[data-form-id]` on the page.
 *
 * This engine is intentionally "dumb": it knows nothing about what a node's
 * first branch represents (state, category, anything else) - it just reads
 * `schema.start_node`, renders that node, and on every answer looks up
 * `next_node` off the chosen option (or the node itself, for auto-advancing
 * content) to decide what to render next. There is no per-state fetch - the
 * whole graph is fetched once up front.
 *
 * The whole questionnaire renders as a single growing list of cards - every
 * node ever reached stays visible and stays clickable, and changing an
 * answer on any card truncates/recomputes everything after it
 * (onAnswerChange() / renderFrom()). The Back button is a shortcut for the
 * same thing: it just undoes the single most-recently-visited node.
 */
export class RYVDecisionEngine {
  constructor(root) {
    this.root = root;
    this.formId = root.dataset.formId;
    this.controls = null;
    this.list = null;
    this.nodes = {};
    this.startNode = null;
    this.answers = new Map(); // node id -> string[] (choice) | string (text/textarea/select)
    this.renderedOrder = []; // ids (real node ids, or the sentinel ids above) in on-screen order
    this.cardElements = new Map(); // id -> DOM element
  }

  mount() {
    this.root.classList.add('ryv-app-wrapper');
    this.controls = renderer.createControls(() => this.goBack());
    this.root.appendChild(this.controls);
    this.list = renderer.createList();
    this.root.appendChild(this.list);
    this.loadForm();
  }

  async loadForm() {
    this.appendCard(LOADING_CARD_ID, renderer.renderLoadingCard());
    try {
      const data = await fetchForm(this.formId);
      this.nodes = data.nodes || {};
      this.startNode = data.start_node || null;
      this.answers = new Map();
      this.truncateFrom(0);

      if (!this.startNode || !this.nodes[this.startNode]) {
        this.appendCard(ERROR_CARD_ID, renderer.renderErrorCard('This form has not been configured yet.'));
        return;
      }

      this.renderFrom(this.startNode, true);
    } catch (err) {
      this.truncateFrom(0);
      this.appendCard(ERROR_CARD_ID, renderer.renderErrorCard(err.message, () => this.loadForm()));
    }
  }

  restart() {
    this.answers = new Map();
    this.truncateFrom(0);
    if (this.startNode) this.renderFrom(this.startNode, true);
  }

  /** Node ids currently on screen, in visited order (sentinels excluded). */
  get history() {
    return this.renderedOrder.filter((id) => Boolean(this.nodes[id]));
  }

  /** Undo the single most-recently-visited node, exposing the one before it. */
  goBack() {
    const history = this.history;
    if (history.length < 2) return;

    const lastNodeId = history[history.length - 1];
    this.answers.delete(lastNodeId);
    this.truncateFrom(this.indexOf(lastNodeId));
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
        this.appendCard(current, renderer.renderErrorCard('This step could not be found.'), animate);
        return;
      }

      const next = this.computeNext(current, node);
      const isTerminal = this.isTerminalNode(node, next);
      this.appendCard(current, this.buildCard(current, node, isTerminal), animate);

      if (!next) return;
      current = next;
    }
  }

  /**
   * Next node id given this node's currently recorded answer, or null if
   * the node auto-advances with nothing configured, has no next_node, is a
   * terminal (`is_final`), or is still awaiting user input.
   */
  computeNext(nodeId, node) {
    if (node.is_final) return null;

    if (hasOptions(node)) {
      const answer = this.answers.get(nodeId);

      if (node.type === 'select') {
        if (!answer) return null;
        const chosen = node.options.find((option) => option.value === answer);
        return chosen ? chosen.next_node || null : null;
      }

      // radio/checkbox/webform_buttons: answer is the array of selected value(s).
      if (!answer || answer.length === 0) return null;
      const chosen = node.options.find((option) => answer.includes(option.value));
      return chosen ? chosen.next_node || null : null;
    }

    if (isTextInput(node)) {
      const answer = this.answers.get(nodeId);
      return answer ? node.next_node || null : null;
    }

    // Content-only node (markup/processed_text/unsupported): auto-advances.
    return node.next_node || null;
  }

  /** A node is terminal when it's flagged `is_final`, or it's a content-only node with nowhere to go next. */
  isTerminalNode(node, next) {
    if (node.is_final) return true;
    if (hasOptions(node) || isTextInput(node)) return false;
    return !next;
  }

  buildCard(nodeId, node, isTerminal) {
    if (hasOptions(node)) {
      if (node.type === 'select') {
        return renderer.renderSelectCard(node, this.answers.get(nodeId) || '', (value) => this.onAnswerChange(nodeId, value));
      }
      return renderer.renderChoiceCard(node, this.answers.get(nodeId) || [], isMultiChoice(node), (values) =>
        this.onAnswerChange(nodeId, values)
      );
    }

    if (isTextInput(node)) {
      return renderer.renderInputCard(node, this.answers.get(nodeId) || '', (value) => this.onAnswerChange(nodeId, value));
    }

    if (node.type === 'unsupported') {
      return renderer.renderUnsupportedCard(node, isTerminal, () => this.restart());
    }

    return renderer.renderContentCard(node, isTerminal, () => this.restart());
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
    const isTerminal = this.isTerminalNode(node, next);
    this.appendCard(nodeId, this.buildCard(nodeId, node, isTerminal), false);

    if (next) this.renderFrom(next, true);
  }

  // --- rendered-list bookkeeping -------------------------------------

  appendCard(id, element, animate = true) {
    this.renderedOrder.push(id);
    this.cardElements.set(id, element);
    renderer.appendCard(this.list, element, animate);
    this.updateControls();
  }

  indexOf(id) {
    return this.renderedOrder.indexOf(id);
  }

  /** Remove every rendered card from `index` (inclusive) to the end. */
  truncateFrom(index) {
    if (index < 0) return;
    const removed = this.renderedOrder.splice(index);
    removed.forEach((id) => {
      renderer.removeCard(this.cardElements.get(id));
      this.cardElements.delete(id);
    });
    this.updateControls();
  }

  updateControls() {
    renderer.setBackEnabled(this.controls, this.history.length > 1);
  }
}

export function initAll(root = document) {
  root.querySelectorAll('.ryv-app-wrapper[data-form-id]').forEach((wrapper) => {
    if (wrapper.dataset.ryvInitialized) return;
    wrapper.dataset.ryvInitialized = 'true';
    new RYVDecisionEngine(wrapper).mount();
  });
}