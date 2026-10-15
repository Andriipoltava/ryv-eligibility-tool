// See the matching comment in ../decision-engine.js - these query strings
// are the only thing cache-busting these nested imports; bump them with
// every RYV_VERSION change.
import { fetchForm, logSubmission, submitLead } from './api-client.js?ver=0.9.23';
import * as renderer from './renderer.js?ver=0.9.23';

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
    this.labels = { ...renderer.DEFAULT_LABELS, ...this.parseJSONAttribute(root, 'labels') };
    // `data-icons` is a JSON object of { continue, startOver, retry } ->
    // { html, position } | null, pre-rendered server-side by the widget's
    // Icon/Icon Position controls (class-ryv-widget.php's
    // get_icon_overrides()) - see renderer.js's DEFAULT_ICONS docblock for
    // the trust boundary.
    this.icons = { ...renderer.DEFAULT_ICONS, ...this.parseJSONAttribute(root, 'icons') };
    // Set by the Elementor widget's "Auto-Detect State From Page" mode
    // (class-ryv-widget.php's get_state_entry_override()) when this
    // placement is on a `ryv_states` page linked to a form: overrides where
    // rendering starts (and where restart() goes back to) so the visitor
    // lands straight on that state's first question instead of the full
    // multi-state picker. Empty/absent for every other placement.
    this.entryNodeOverride = root.dataset.entryNode || null;
    // 'none' (default) | 'counter' (legacy "Step N (of Total)" text) |
    // 'sidebar' (named checklist, see updateStepSidebar()). data-step-mode
    // replaces the old boolean data-show-steps attribute; class-ryv-widget.php
    // still only ever prints one of these three values.
    this.stepMode = root.dataset.stepMode || 'none';
    this.totalSteps = parseInt(root.dataset.totalSteps, 10) || 0;
    this.stepEl = null;
    this.stepSidebarEl = null;
    // Every named step in the graph, in reach order - the full checklist
    // the sidebar shows up front. Built once per form load, see
    // buildAllSteps().
    this.allSteps = [];
    // One-question-at-a-time layout (widget's "Layout" control): every
    // rendered card stays in cardElements/renderedOrder exactly as in
    // stacked mode (the DAG-walking logic never changes) - only their
    // visibility is toggled, in appendCard()/truncateFrom() below.
    this.wizardMode = root.dataset.questionLayout === 'wizard';
    // Wizard-mode-only widget setting ("Require Continue Button for
    // Single-Choice Questions", class-ryv-widget.php's
    // `require_choice_continue` control): off by default, meaning a
    // radio/dropdown/buttons pick (including the state picker) advances the
    // instant it's clicked, same as it always has in stacked layout. Turning
    // it on restores the pre-existing wizard behavior of waiting for an
    // explicit Continue click on every question type, same as checkbox
    // already requires (checkbox always defers in wizard mode regardless of
    // this setting - see buildCard()). Stacked layout ignores this entirely.
    this.requireChoiceContinue = root.dataset.requireChoiceContinue === '1';
    // Every language edition of this form the visitor may switch the tool
    // to, built server-side (class-ryv-widget.php's get_language_editions()).
    // Empty for a form with no linked translation, a single-language site,
    // or a placement with the switch turned off - which is what makes the
    // switch self-limiting rather than something to feature-detect here.
    this.languageEditions = this.parseJSONAttribute(root, 'languageEditions');
    if (!Array.isArray(this.languageEditions)) this.languageEditions = [];
    // Matched by form id rather than trusting the payload's order, so this
    // stays correct however the editions arrive.
    this.languageCode = (this.languageEditions.find((e) => String(e.form_id) === String(this.formId)) || {}).code || '';
    this.langSwitchEl = null;
    // "Start Over" button that replaces the old wizard-only Back button (see
    // mount()/positionStartOverButton()/restartKeepingState()) - unlike the
    // terminal card's own Start Over, it's available mid-flow, in both
    // layouts, and keeps the chosen state.
    this.startOverBtnEl = null;
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
    // Real (cardElements-having) node id -> the wizard-mode leading markup
    // nodes merged onto its card when first built (see renderFrom()) - kept
    // so a rebuild of that same card (onAnswerChange()) or a revisit
    // (goToStep()) can re-show the same merged content instead of
    // silently dropping it.
    this.leadingMarkupByNode = new Map();
  }

  /**
   * `data-labels`/`data-icons` are each a JSON object of overrides for a
   * subset of renderer.DEFAULT_LABELS/DEFAULT_ICONS (only keys the widget's
   * controls actually set are included - see class-ryv-widget.php's
   * get_label_overrides()/get_icon_overrides()). Malformed or absent JSON
   * falls back to no overrides rather than breaking the mount.
   */
  parseJSONAttribute(root, name) {
    try {
      return JSON.parse(root.dataset[name] || '{}');
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

    // Small "Selected State: X" badge - see updateStateBadge(). Mounted
    // first, above the step indicator/sidebar and the question list, and
    // stays empty/hidden until the visitor actually answers the form's
    // state-picker node (this.trueEntryNode, set once loadForm() fetches the
    // graph).
    this.stateBadgeEl = renderer.renderStateBadge();
    this.root.appendChild(this.stateBadgeEl);

    if (this.stepMode === 'counter') {
      this.stepEl = renderer.renderStepIndicator(this.labels);
      this.root.appendChild(this.stepEl);
    } else if (this.stepMode === 'sidebar') {
      // class-ryv-widget.php's render() nests this .ryv-app-wrapper and an
      // empty `.ryv-step-sidebar-slot` (alongside the header title/
      // description) as siblings inside one shared `.ryv-layout--sidebar`
      // container per widget instance - .closest() scopes the lookup to
      // *this* instance's own slot, so two widgets on the same page (even
      // for the same form) never cross-wire.
      const layout = this.root.closest('.ryv-layout--sidebar');
      const slot = layout ? layout.querySelector('.ryv-step-sidebar-slot') : null;
      if (slot) {
        this.stepSidebarEl = renderer.renderStepSidebar();
        slot.appendChild(this.stepSidebarEl);
      }
    }

    this.list = renderer.createList();
    this.root.appendChild(this.list);
    this.startOverBtnEl = renderer.renderStartOverButton(() => this.restartKeepingState(), this.labels);
    this.mountLanguageSwitch();
    this.loadForm();
  }

  /**
   * The language switch is built once and then *moved*, never rebuilt, by
   * positionLanguageSwitch() below - so it keeps its identity (and its
   * click handler) across every re-render.
   */
  mountLanguageSwitch() {
    const others = this.languageEditions.filter((e) => e.code !== this.languageCode);
    this.langSwitchEl = renderer.renderLanguageSwitch(others, (code) => this.switchLanguage(code));
    this.positionLanguageSwitch();
  }

  /**
   * Park the switch immediately after the current question's Continue
   * button, on the same line - not at the bottom of the widget.
   *
   * It can't simply be rendered inside the card, because a card is built
   * once by buildCard() and the switch has to outlive every truncate/
   * re-render: so it's a single long-lived element that gets re-homed after
   * each list mutation (appendCard()/truncateFrom(), the only two places
   * cards are added or removed). truncateFrom() removing the card it was
   * sitting in detaches it too - harmless, since the same call then
   * re-positions it into whatever card is now last.
   *
   * Falls back to the end of the last card when that card has no Continue
   * button of its own (a radio question advances on click; a terminal card
   * only has Start Over), and to the widget root when there's no card at
   * all yet (initial load, error state).
   */
  positionLanguageSwitch() {
    if (!this.langSwitchEl) return;

    let lastCard = null;
    this.renderedOrder.forEach((id) => {
      const element = this.cardElements.get(id);
      if (element) lastCard = element;
    });

    if (!lastCard) {
      this.langSwitchEl.classList.remove('ryv-lang-switch--inline');
      this.root.appendChild(this.langSwitchEl);
      return;
    }

    // Its own buttons are never `.ryv-continue-btn`, so this can't select
    // the switch itself however many times it's been re-homed.
    const continueBtns = lastCard.querySelectorAll('.ryv-continue-btn');
    const anchor = continueBtns.length ? continueBtns[continueBtns.length - 1] : null;

    this.langSwitchEl.classList.toggle('ryv-lang-switch--inline', !!anchor);

    if (anchor) anchor.insertAdjacentElement('afterend', this.langSwitchEl);
    else lastCard.appendChild(this.langSwitchEl);
  }

  /**
   * Re-render the questionnaire in another language edition of the same
   * form, without navigating: the page stays in whatever language it was,
   * only the tool changes. This is a plain re-fetch of a different form id -
   * nothing about the graph walk is language-aware.
   *
   * Two things have to move with it. The entry node, because each edition
   * has its own per-state node ids: on a state page the widget pre-resolved
   * each edition's own first question for *this* state (see
   * get_language_editions()), so the visitor stays on their state instead of
   * being dropped back at the full picker. And the UI strings (Continue,
   * Start Over, ...), which can't come from WPML string translation here -
   * that resolves against the *page's* language, which deliberately hasn't
   * changed - so the server ships the target language's set instead
   * (RYV_Form_Translations::UI_LABELS).
   *
   * The server-rendered title/description above the tool
   * (class-ryv-widget.php's render_header()) is deliberately *not* swapped
   * along with it, even though the REST fetch returns the new edition's
   * own title: a translated form's post title is an internal admin name
   * ("Voting Rights Spanish"), not visitor-facing copy, and its excerpt is
   * usually empty - swapping produced an admin label over a still-English
   * description. The header is page chrome and stays in the page's own
   * language, which is what "tool-only" means here.
   *
   * Answers are not carried across: the two editions' node ids differ per
   * state, so there's nothing to replay them against. The visitor restarts
   * in the new language, with a fresh session id so the abandoned English
   * trail stays on record as its own row rather than being overwritten.
   */
  switchLanguage(code) {
    const target = this.languageEditions.find((e) => e.code === code);
    if (!target || code === this.languageCode) return;

    this.languageCode = code;
    this.formId = String(target.form_id);
    this.entryNodeOverride = target.entry_node || null;
    this.labels = { ...renderer.DEFAULT_LABELS, ...(target.labels || {}) };
    this.answers = new Map();
    this.sessionId = this.generateSessionId();

    // Unlike the language switch button and every card (rebuilt below by
    // loadForm()), the mid-flow "Start Over" button is a single long-lived
    // element (see mount()) that outlives a language switch - relabel it in
    // place rather than rebuilding, or it would keep showing whatever
    // language it was first mounted in.
    if (this.startOverBtnEl) {
      this.startOverBtnEl.textContent = this.labels.startOver;
    }

    // Rebuilt rather than relabeled: the button set is "every edition except
    // the current one", which just changed.
    if (this.langSwitchEl) {
      this.langSwitchEl.remove();
      this.langSwitchEl = null;
    }
    this.mountLanguageSwitch();

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
      // The form's *real* entry node (the state picker), independent of
      // entryNodeOverride - buildAllSteps() needs it to synthesize the
      // "state selected" step below, since it's never itself walked once an
      // override skips straight past it.
      this.trueEntryNode = data.entry_node;
      this.entryNode = (this.entryNodeOverride && this.nodes[this.entryNodeOverride])
        ? this.entryNodeOverride
        : data.entry_node;
      // Depends on both `nodes` and `entryNode`, so it can only be built
      // here - and is rebuilt on every load, which is what keeps it correct
      // after a language switch (a different graph entirely).
      this.allSteps = this.buildAllSteps();
      this.truncateFrom(0);
      this.renderFrom(this.entryNode, true);
    } catch (err) {
      this.truncateFrom(0);
      this.appendCard(ERROR_CARD_ID, renderer.renderErrorCard(err.message, () => this.loadForm(), this.labels, this.icons));
    }
  }

  restart() {
    this.answers = new Map();
    // A fresh id, not a cleared one: without this, restarting would reuse
    // the same session row and RYV_Submission_Log::record() would overwrite
    // the trail that was just abandoned instead of leaving it on record.
    this.sessionId = this.generateSessionId();
    // Back to the full graph-wide checklist - clearing `answers` above also
    // forgets which state (if any) `this.allSteps` was last narrowed to, so
    // leaving it as-is would show a stale, wrong-shaped sidebar for however
    // long it takes the visitor to pick a state again.
    this.allSteps = this.buildAllSteps();
    this.truncateFrom(0);
    this.renderFrom(this.entryNode, true);
  }

  /**
   * "Start Over" that keeps the visitor on the state they already picked:
   * wipes every answer except the state picker's, then re-walks from the
   * entry node, which (with that one answer pre-filled) lands straight on
   * the state's first question instead of the full picker. Changing state is
   * still possible by re-picking it, as on any other card.
   *
   * Falls back to a full restart() when there's no picked state to keep - a
   * state-page placement (entryNodeOverride skips the picker, so it's never
   * answered and restart() already lands on that state's first question) or
   * a visitor who hasn't picked one yet.
   *
   * The state answer is set *after* truncateFrom(0), which forgets every
   * removed card's cached answer, the picker's included.
   */
  restartKeepingState() {
    const stateAnswer = this.answers.get(this.trueEntryNode);
    if (!stateAnswer || stateAnswer.length === 0) {
      this.restart();
      return;
    }

    // Fresh session id, same reasoning as restart(): the abandoned trail
    // stays on record as its own row.
    this.sessionId = this.generateSessionId();
    this.allSteps = this.buildAllSteps(stateAnswer);
    this.truncateFrom(0);
    this.answers.set(this.trueEntryNode, stateAnswer);
    this.renderFrom(this.entryNode, true);
    this.logProgress();
    this.root.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
   * Every named step reachable from the entry node, in the order a visitor
   * reaches them - so the sidebar can show the full checklist up front
   * (upcoming steps greyed) instead of revealing each entry only once the
   * visitor gets there.
   *
   * Breadth-first from the entry node, deduped by name on first discovery:
   * with the 3-step shape the Step Names page assigns (entry / everything
   * the entry routes to / every terminal - see class-ryv-step-names-page.php)
   * BFS visits those three tiers in exactly that order, whatever branch a
   * visitor eventually takes. Names, not nodes, are the unit here: dozens of
   * different per-state nodes all share the one "Tell us about your
   * conviction" label and must collapse to a single entry.
   *
   * `branchValue`, once a state's been picked, narrows the walk to that
   * state's own branch instead of the whole graph: at the state-picker node
   * itself (and only there - `id === this.trueEntryNode`), only the option
   * matching `branchValue` is followed, rather than every state's. Without
   * this a state whose branch has no separate conviction question at all
   * (the entry option routes straight to a "you can vote" verdict, e.g.
   * DC/Maine/Puerto Rico/Vermont) would still show every other state's
   * "Tell us about your conviction" entry - discovered via whichever state
   * the graph-wide BFS reaches first - as a permanently-upcoming, never-
   * checked-off step in *their* sidebar too, which reads as a skipped step
   * rather than a 2-step questionnaire. Called with no argument (initial
   * load, before any state is chosen) this still walks the whole graph, so
   * the generic 3-item preview shows before a visitor's own shape is known;
   * onAnswerChange() recomputes it with the chosen value once the state
   * question is answered or changed.
   *
   * Cached on `this.allSteps` (see loadForm()/onAnswerChange()) rather than
   * recomputed on every render - a real form is 450+ nodes and this would
   * otherwise run on every card change.
   */
  buildAllSteps(branchValue) {
    const steps = [];
    const seenNames = new Set();

    // On a state page, entryNodeOverride skips straight past the form's own
    // state-picker node (this.trueEntryNode) to that state's first question -
    // so the BFS below, which walks forward from wherever rendering actually
    // starts, never visits it and its named step ("Select Your State" or
    // whatever an admin typed on the Step Names page) never appears at all.
    // The state is already known on this kind of placement, so that step is
    // synthesized here as always-satisfied: `firstNodeId: null` keeps it
    // unclickable (there's nowhere on this page to jump back to), and
    // updateStepSidebar() marks anything before the visitor's real active
    // step done automatically, with no special-casing needed there.
    if (this.entryNodeOverride && this.entryNode !== this.trueEntryNode) {
      const trueEntry = this.nodes[this.trueEntryNode];
      if (trueEntry && trueEntry.step_name && !trueEntry.hidden) {
        seenNames.add(trueEntry.step_name);
        steps.push({ name: trueEntry.step_name, firstNodeId: null });
      }
    }

    const visited = new Set();
    const queue = [this.entryNode];
    const wantedValue = Array.isArray(branchValue) ? branchValue[0] : branchValue;

    while (queue.length) {
      const id = queue.shift();
      if (!id || visited.has(id)) continue;
      visited.add(id);

      const node = this.nodes[id];
      if (!node) continue;

      // `hidden` nodes never get a card, so one can't anchor a sidebar
      // entry - same reasoning as the Step Names page's own resolution.
      if (node.step_name && !node.hidden && !seenNames.has(node.step_name)) {
        seenNames.add(node.step_name);
        steps.push({ name: node.step_name, firstNodeId: null });
      }

      const options = node.options || [];
      if (wantedValue !== undefined && id === this.trueEntryNode) {
        const chosen = options.find((option) => option && option.value === wantedValue);
        if (chosen && chosen.next_node) queue.push(chosen.next_node);
      } else {
        options.forEach((option) => {
          if (option && option.next_node) queue.push(option.next_node);
        });
      }
      if (node.next_node) queue.push(node.next_node);
    }

    return steps;
  }

  /**
   * Renders the full step checklist (buildAllSteps()) and marks the
   * visitor's position in it: everything before their current step is done,
   * their current step is active, the rest are upcoming.
   *
   * Which step is current still comes from the visitor's *own* path, not
   * from the graph - `renderedOrder` is walked exactly as before, so a node
   * with no `step_name` stays folded into whichever named step is already
   * running (see NodeEditor.js's Step Name help text). That walk also
   * supplies each done step's `firstNodeId`: a step is only clickable back
   * to a card the visitor actually saw, never to whichever node BFS
   * happened to discover the name on.
   *
   * Falls back to the path-derived list when the graph has no named steps
   * reachable from the entry node, so a form that never ran through the
   * Step Names page behaves exactly as it did before.
   */
  updateStepSidebar() {
    if (!this.stepSidebarEl) return;

    const pathSteps = [];
    let activeIndex = -1;

    this.renderedOrder.forEach((id) => {
      const node = this.nodes[id];
      if (!node || !this.cardElements.has(id)) return; // sentinel, or node.hidden (no card)
      const last = pathSteps[pathSteps.length - 1];
      if (node.step_name && (!last || last.name !== node.step_name)) {
        pathSteps.push({ name: node.step_name, firstNodeId: id });
      }
    });

    const steps = (this.allSteps && this.allSteps.length) ? this.allSteps.map((step) => {
      const visited = pathSteps.find((s) => s.name === step.name);
      return { name: step.name, firstNodeId: visited ? visited.firstNodeId : null };
    }) : pathSteps;

    const currentName = pathSteps.length ? pathSteps[pathSteps.length - 1].name : null;
    if (currentName) activeIndex = steps.findIndex((s) => s.name === currentName);

    renderer.updateStepSidebar(this.stepSidebarEl, steps, activeIndex, (nodeId) => this.goToStep(nodeId));
  }

  /**
   * Jump back to a completed step's first question, clicked from the
   * sidebar (renderer.js's `onStepClick`). Stacked layout already shows
   * every card at once - nothing is hidden to reveal, so this just scrolls
   * it into view. Wizard layout truncates rendering back to that node (same
   * `truncateFrom()` any answer change already uses) and re-appends just
   * that one card - deliberately *not* the normal renderFrom() walk, which
   * would immediately recompute forward through the still-cached answers
   * and land right back where the visitor started; this leaves them on the
   * revisited card, pre-filled with their previous answer, until they
   * Continue again (replaying forward one Continue click at a time, same as
   * any other wizard step).
   */
  goToStep(nodeId) {
    const index = this.indexOf(nodeId);
    if (index < 0) return;

    if (!this.wizardMode) {
      const element = this.cardElements.get(nodeId);
      if (element) element.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    // Same ordering note as onAnswerChange(): read before truncateFrom() deletes it,
    // then restore it after - nothing else re-populates this map for a card
    // rebuilt here, so a later revisit/answer-change on this same node would
    // otherwise silently lose the merged content once.
    const leadingMarkup = this.leadingMarkupByNode.get(nodeId) || [];
    // truncateFrom() now also clears the cached answer for every card it
    // removes, nodeId's own included (see its own docblock) - read it first
    // and restore it after, so jumping back to a step still pre-fills what
    // the visitor answered there, same as before.
    const previousAnswer = this.answers.get(nodeId);
    this.truncateFrom(index);
    this.leadingMarkupByNode.set(nodeId, leadingMarkup);
    if (previousAnswer !== undefined) this.answers.set(nodeId, previousAnswer);
    // Same reasoning as restart(): jumping back to the state-picker step
    // itself (clicked from the sidebar) re-opens the state as a live
    // question again, so the checklist shouldn't stay narrowed to whichever
    // state's shape was last answered until they pick again.
    if (nodeId === this.trueEntryNode) {
      this.allSteps = this.buildAllSteps();
    }
    const node = this.nodes[nodeId];
    if (!node) return;
    this.appendCard(nodeId, node.hidden ? null : this.buildCard(nodeId, node, false, leadingMarkup), true);
  }

  /** Dispatches to whichever step display is active (or neither). */
  updateSteps() {
    if (this.stepMode === 'counter') this.updateStepIndicator();
    else if (this.stepMode === 'sidebar') this.updateStepSidebar();
  }

  /**
   * Shows/hides the "Selected State: X" badge based on the visitor's answer
   * to the form's actual state-picker node (`this.trueEntryNode` - the flat
   * DAG's `select_state`-shaped entry node, see CLAUDE.md; not
   * `this.entryNode`, which on a state-page placement skips the picker
   * entirely via `entryNodeOverride` and so is never answered at all - the
   * badge correctly stays hidden there, since there's no picker to reflect).
   * Called everywhere `updateSteps()` is, so it stays in sync with every
   * card mutation (new answer, truncation on edit, restart, language switch).
   *
   * Suppressed while the state-picker card itself is the one currently on
   * screen (last real card in `renderedOrder`) - showing "Selected State: X"
   * right alongside the "What state do you live in?" picker is redundant at
   * best and, worse, can visibly lag one step behind (e.g. after navigating
   * back to the picker and switching states) since the badge would otherwise
   * be reflecting whatever was last answered rather than what the visitor is
   * looking at right now. It reappears as soon as they move past the picker.
   */
  updateStateBadge() {
    if (!this.stateBadgeEl) return;

    let lastRealId = null;
    this.renderedOrder.forEach((id) => {
      if (this.cardElements.has(id)) lastRealId = id;
    });
    if (lastRealId === this.trueEntryNode) {
      renderer.updateStateBadge(this.stateBadgeEl, null, this.labels);
      return;
    }

    const stateNode = this.nodes[this.trueEntryNode];
    const answer = stateNode ? this.answers.get(this.trueEntryNode) : null;
    const value = Array.isArray(answer) ? answer[0] : null;
    const option = value ? (stateNode.options || []).find((o) => o.value === value) : null;

    renderer.updateStateBadge(this.stateBadgeEl, option ? option.label : null, this.labels);
  }

  /**
   * Wizard (one-question-at-a-time) layout: only the most recently
   * appended real card stays visible - every earlier one gets
   * `.ryv-card-hidden` (pure CSS, `display:none`). Cards stay fully
   * mounted in `cardElements`/`renderedOrder`/the DOM, same as stacked
   * mode - `truncateFrom()`/`indexOf()`/answer recomputation are
   * completely unaffected, only visibility changes.
   */
  updateWizardVisibility() {
    if (!this.wizardMode) return;
    let lastRealId = null;
    this.renderedOrder.forEach((id) => {
      if (this.cardElements.has(id)) lastRealId = id;
    });
    this.cardElements.forEach((el, id) => {
      el.classList.toggle('ryv-card-hidden', id !== lastRealId);
    });
  }

  /**
   * Places the mid-flow "Start Over" button and hides it when it would be
   * pointless: no question beyond the first is on screen yet, or the last card
   * is a terminal one that already carries its own Start Over (see
   * buildCard()). Deliberately not gated on the widget's "Show Start Over
   * Button" setting, which only governs that terminal button - the old Back
   * button this replaces was never gated either.
   *
   * Wizard layout shows it immediately above the single visible card, where
   * Back used to sit; stacked layout puts it after the last card, below the
   * whole growing list.
   */
  positionStartOverButton() {
    if (!this.startOverBtnEl) return;

    let lastRealId = null;
    this.renderedOrder.forEach((id) => {
      if (this.cardElements.has(id)) lastRealId = id;
    });
    const lastCard = lastRealId ? this.cardElements.get(lastRealId) : null;
    const lastNode = lastRealId ? this.nodes[lastRealId] : null;
    const terminalHasOwn = this.showStartOver && !!lastNode
      && (lastNode.type === 'markup' || lastNode.type === 'unsupported')
      && !lastNode.next_node;

    if (!lastCard || this.previousRealIndex() < 0 || terminalHasOwn) {
      this.startOverBtnEl.remove();
      return;
    }

    // At the true end of the workflow (a terminal result card) the button
    // reads as the primary red call-to-action, same as the homepage; mid-flow
    // it stays the quiet text link. Swapping the class (not stacking both)
    // keeps .ryv-back-btn's own !important rules from fighting the button's.
    const atEnd = !!lastNode
      && (lastNode.type === 'markup' || lastNode.type === 'unsupported')
      && !lastNode.next_node;
    this.startOverBtnEl.className = atEnd ? 'ryv-start-over-end-btn' : 'ryv-back-btn';
    // .ryv-list is a flex column, which would stretch the button to the full
    // card width - keep it sized to its label like a Continue button.
    this.startOverBtnEl.style.alignSelf = 'flex-start';

    if (this.wizardMode) lastCard.before(this.startOverBtnEl);
    else this.list.appendChild(this.startOverBtnEl);
  }

  /**
   * Index (in `renderedOrder`) of the real card immediately before the one
   * currently on screen, or -1 when the visible card is the first (or there
   * is none yet). Used by positionStartOverButton() (whether to show the
   * button).
   */
  previousRealIndex() {
    let lastRealIndex = -1;
    this.renderedOrder.forEach((id, i) => {
      if (this.cardElements.has(id)) lastRealIndex = i;
    });
    if (lastRealIndex <= 0) return -1;

    for (let i = lastRealIndex - 1; i >= 0; i -= 1) {
      if (this.cardElements.has(this.renderedOrder[i])) return i;
    }
    return -1;
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
    // Wizard layout: a visible markup node with somewhere to go no longer
    // gets its own card gated behind a Continue click (see the branch
    // below) - its content instead rides along onto whichever real card
    // follows, rendered together in one card via buildCard()'s
    // `leadingMarkup` param / renderer.js's prependLeadingContent().
    // Consecutive markup nodes accumulate here and are flushed together, in
    // document order, onto the next real card.
    let leadingMarkup = [];

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

      // `node.hidden` markup already skips its own card unconditionally
      // (via the ternary below) and keeps routing through transparently -
      // this only intercepts markup that WOULD otherwise have gotten its
      // own Continue-gated card.
      if (this.wizardMode && node.type === 'markup' && node.next_node && !node.hidden) {
        leadingMarkup.push(node);
        this.appendCard(current, null, animate);
        current = node.next_node;
        continue;
      }

      const next = this.computeNext(current, node);
      const isTerminal = !node.next_node && (node.type === 'markup' || node.type === 'unsupported');
      // `node.hidden` (see RYV_YAML_Parser's "visible_and_invisible" case)
      // still routes through normally - it's just never given a card, so a
      // hidden node's own next_node keeps the chain moving transparently
      // instead of dead-ending the flow.
      if (!node.hidden) {
        this.leadingMarkupByNode.set(current, leadingMarkup);
      }
      this.appendCard(current, node.hidden ? null : this.buildCard(current, node, isTerminal, leadingMarkup), animate);
      leadingMarkup = [];

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
    // Wizard layout no longer defers a visible markup node with somewhere
    // to go - renderFrom() intercepts that case before ever calling this
    // (see its `leadingMarkup` branch), so a markup node reaching here is
    // always either hidden or terminal, both of which auto-advance/dead-end
    // the same way in every layout.
    if (node.type === 'markup') {
      return node.next_node || null;
    }
    if (node.type === 'unsupported') {
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

  /**
   * @param {object[]} leadingMarkup Visible markup nodes (wizard mode only -
   *   see renderFrom()) that led into this one without their own
   *   Continue-gated card; their content is prepended onto this card
   *   instead, via renderer.js's prependLeadingContent(). Empty for stacked
   *   layout, or when nothing preceded this node.
   */
  buildCard(nodeId, node, isTerminal, leadingMarkup = []) {
    switch (node.type) {
      case 'radio':
      case 'checkbox':
        return renderer.renderQuestionCard(
          node,
          this.answers.get(nodeId) || [],
          (values) => this.onAnswerChange(nodeId, values),
          nodeId,
          this.labels,
          this.nodes,
          // Checkbox (multi-select) always defers in wizard mode - a single
          // click never fully answers it. A single-choice node only defers
          // when the widget's "Require Continue Button for Single-Choice
          // Questions" setting is on; by default it auto-advances instead.
          this.wizardMode && (node.type === 'checkbox' || this.requireChoiceContinue),
          this.icons,
          leadingMarkup
        );
      case 'text':
      case 'textarea':
        return renderer.renderInputCard(
          node,
          this.answers.get(nodeId) || '',
          (value) => this.onAnswerChange(nodeId, value),
          this.labels,
          this.wizardMode,
          this.icons,
          leadingMarkup
        );
      case 'lead_capture':
        return renderer.renderLeadCaptureCard(
          node,
          this.answers.get(nodeId) || null,
          (values) => this.onLeadSubmit(nodeId, values),
          () => this.onLeadContinue(nodeId),
          this.labels,
          this.icons,
          leadingMarkup
        );
      case 'markup':
        return renderer.renderMarkupCard(
          node,
          isTerminal && this.showStartOver,
          () => this.restartKeepingState(),
          this.labels,
          this.wizardMode && !isTerminal,
          () => this.onMarkupContinue(nodeId),
          this.icons,
          leadingMarkup
        );
      default:
        return renderer.renderUnsupportedCard(
          node,
          isTerminal && this.showStartOver,
          () => this.restartKeepingState(),
          this.labels,
          this.icons,
          leadingMarkup
        );
    }
  }

  onAnswerChange(nodeId, value) {
    const index = this.indexOf(nodeId);

    // Same ordering note as goToStep(): read before truncateFrom()
    // deletes this same id's entry (it's part of the range being removed).
    const leadingMarkup = this.leadingMarkupByNode.get(nodeId) || [];
    // truncateFrom() now also forgets the cached answer for every card it
    // removes - nodeId's old answer included, since index is inclusive -
    // which is exactly what's wanted here (every downstream answer from a
    // path the visitor is leaving becomes stale) but means nodeId's own new
    // answer has to be applied *after*, not before, or truncateFrom() would
    // immediately erase it.
    this.truncateFrom(index);
    this.leadingMarkupByNode.set(nodeId, leadingMarkup);

    const isEmpty = Array.isArray(value) ? value.length === 0 : !value;
    if (!isEmpty) this.answers.set(nodeId, value);

    // Re-narrow the sidebar checklist to the chosen state's own branch (see
    // buildAllSteps()'s own docblock) whenever the state question itself is
    // what just changed - including clearing it, which falls back to the
    // full graph-wide list since no branch is known again.
    if (nodeId === this.trueEntryNode) {
      this.allSteps = this.buildAllSteps(isEmpty ? undefined : value);
    }

    const node = this.nodes[nodeId];
    const next = this.computeNext(nodeId, node);
    const isTerminal = !next && (node.type === 'markup' || node.type === 'unsupported');
    this.appendCard(nodeId, node.hidden ? null : this.buildCard(nodeId, node, isTerminal, leadingMarkup), false);

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

  /**
   * Advances past a wizard-mode markup card once its own "Continue" button
   * is clicked - computeNext() deliberately defers markup nodes in wizard
   * mode (see there), so this is the only path that renders forward from
   * one in that layout. Same shape as onLeadContinue().
   */
  onMarkupContinue(nodeId) {
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
      this.updateSteps();
      this.updateStateBadge();
      return;
    }
    this.cardElements.set(id, element);
    renderer.appendCard(this.list, element, animate);
    this.updateSteps();
    this.updateStateBadge();
    this.updateWizardVisibility();
    this.positionStartOverButton();
    this.positionLanguageSwitch();
  }

  indexOf(id) {
    return this.renderedOrder.indexOf(id);
  }

  /**
   * Remove every rendered card from `index` (inclusive) to the end, and
   * forget their cached answers - so `this.answers` only ever holds answers
   * for a node still on screen (or about to be, via `nodeId`/renderFrom()'s
   * own walk), never a stale one left over from a path the visitor has since
   * abandoned. Without this, re-truncating past a node twice (e.g. clicking
   * "Back" repeatedly to the state picker, then picking a *different* state,
   * then picking the original one again) would silently replay whatever
   * answers were cached from the first visit instead of asking the visitor
   * again, since neither pass alone was in a position to notice the other's
   * now-orphaned cache entries.
   *
   * A caller that wants to keep pre-filling one specific node's answer after
   * truncating past it (goToStep(), re-showing exactly the node
   * they truncated to) has to explicitly restore it afterward - same pattern
   * already used here for `leadingMarkupByNode`.
   */
  truncateFrom(index) {
    if (index < 0) return;
    const removed = this.renderedOrder.splice(index);
    removed.forEach((id) => {
      const element = this.cardElements.get(id);
      if (element && element.parentNode) element.parentNode.removeChild(element);
      this.cardElements.delete(id);
      this.leadingMarkupByNode.delete(id);
      this.answers.delete(id);
    });
    this.updateSteps();
    this.updateStateBadge();
    this.updateWizardVisibility();
    this.positionStartOverButton();
    this.positionLanguageSwitch();
  }
}

export function initAll(root = document) {
  root.querySelectorAll('.ryv-app-wrapper[data-form-id]').forEach((wrapper) => {
    if (wrapper.dataset.ryvInitialized) return;
    wrapper.dataset.ryvInitialized = 'true';
    new RYVDecisionEngine(wrapper).mount();
  });
}
