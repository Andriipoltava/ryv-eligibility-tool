/**
 * DOM builders for each step's "card". The whole questionnaire renders as a
 * growing vertical list of cards - one per answered (or currently-active)
 * question - with no separate Back/Continue chrome: every card stays
 * clickable/editable for as long as it's on screen, and changing an answer
 * just re-renders everything downstream of it (handled by engine.js).
 *
 * Class names here are semantic (.ryv-option-btn, .ryv-card, ...) and are
 * defined in assets/src/input.css using @apply on the prefixed Tailwind
 * utilities - this file never touches Tailwind's ryv-* utility classes
 * directly.
 */

/**
 * Every hardcoded piece of chrome text (not question content, which already
 * comes from the schema), with its current value as the default - the
 * widget's "Labels" controls override individual keys per-instance; engine.js
 * merges them over this before passing `labels` down to these functions, so
 * nothing here needs its own fallback.
 */
export const DEFAULT_LABELS = {
  loading: 'Loading…',
  error: 'Something went wrong.',
  retry: 'Try Again',
  startOver: 'Start Over',
  selectPlaceholder: '— Select —',
  select2NoResults: 'No matches found',
  submit: 'Submit',
  submitting: 'Submitting…',
  continue: 'Continue',
  leadSubmitted: 'Thank you! Your submission has been received.',
  leadError: 'Something went wrong submitting your information. Please try again.',
  // {current}/{total} placeholders - see renderer.js's updateStepIndicator().
  // stepWithTotal only applies once the widget's "Total Steps" setting is
  // filled in (class-ryv-widget.php's total_steps control); otherwise step
  // (no total) is used for every render.
  step: 'Step {current}',
  stepWithTotal: 'Step {current} of {total}',
};

/**
 * Optional icon for the Continue/Start Over/Retry buttons (the widget's
 * "Icon"/"Icon Position" controls on each - see class-ryv-widget.php's
 * register_label_controls() and get_icon_overrides()). Each value is either
 * `null` (no icon, today's plain-text button) or
 * `{ html: string, position: 'before' | 'after' }` - `html` is
 * server-rendered by Elementor's own `Icons_Manager::render_icon()`, the
 * same trust boundary as `node.content`/`node.title` elsewhere in this file
 * (admin-only, never visitor input), so it's applied via `innerHTML` here
 * rather than re-parsed.
 */
export const DEFAULT_ICONS = {
  continue: null,
  startOver: null,
  retry: null,
};

/**
 * Builds a button's children: a `.ryv-btn-text` span, plus (if `icon` is
 * set) a `.ryv-btn-icon` span holding Elementor's pre-rendered icon markup,
 * positioned before or after the text per `icon.position`. Returned as a
 * children array for `el('button', {...}, buttonChildren(...))` - buttons
 * with no icon get a single plain text span, same DOM shape as before this
 * existed.
 */
function buttonChildren(text, icon) {
  const textSpan = el('span', { className: 'ryv-btn-text', text });
  if (!icon || !icon.html) return [textSpan];

  const iconSpan = el('span', { className: 'ryv-btn-icon', attrs: { 'aria-hidden': 'true' } });
  iconSpan.innerHTML = icon.html;

  return icon.position === 'before' ? [iconSpan, textSpan] : [textSpan, iconSpan];
}

function el(tag, opts = {}, children = []) {
  const node = document.createElement(tag);
  if (opts.className) node.className = opts.className;
  if (opts.text !== undefined) node.textContent = opts.text;
  if (opts.attrs) {
    Object.entries(opts.attrs).forEach(([k, v]) => node.setAttribute(k, v));
  }
  if (opts.on) {
    Object.entries(opts.on).forEach(([evt, fn]) => node.addEventListener(evt, fn));
  }
  children.forEach((child) => child && node.appendChild(child));
  return node;
}

/**
 * A card's outer container, with the admin-configured `node.wrapper_class`
 * applied if set - NodeEditor.js's generic "CSS Class" field, or (for
 * `markup` nodes specifically) a YAML import's `#wrapper_attributes.class`
 * (see RYV_YAML_Parser::extract_wrapper_classes()). Same trust boundary as
 * `node.content`/`node.title` - only ever written by an admin with
 * manage_options, never user input.
 */
function cardWrap(node) {
  return el('div', node.wrapper_class ? { className: node.wrapper_class } : {});
}

export function createList() {
  return el('div', { className: 'ryv-list' });
}

/**
 * A node's `title` is trusted HTML, same trust boundary as `content` (see
 * renderMarkupCard) - it only ever comes from a YAML import or the builder,
 * both gated to `manage_options`/`edit_post`. Rendered via innerHTML rather
 * than textContent so inline tags (<em>, <strong>, ...) actually format
 * instead of showing as literal text.
 *
 * `help` (NodeEditor.js's "Tooltip (Help Text)" field, or a YAML import's
 * Drupal `#help` - see RYV_YAML_Parser::build_node()) is optional and gets
 * a "?" icon appended right after the title text - see buildHelpTooltip().
 *
 * @param {string} title
 * @param {string|null} help
 */
function titleEl(title, help) {
  const h2 = document.createElement('h2');
  h2.className = 'ryv-question-title';

  const text = document.createElement('span');
  text.innerHTML = title || '';
  h2.appendChild(text);

  if (help) {
    h2.appendChild(buildHelpTooltip(help));
  }

  return h2;
}

/**
 * A "?" icon that reveals a small tooltip box with `help`'s (trusted) HTML
 * on hover/focus - matches how campaignlegal.org/restoreyourvote's own
 * Drupal webform_help icon behaves (a Tippy.js tooltip, `trigger:
 * 'mouseenter focus'` - hover for mouse users, focus for keyboard ones,
 * never a click-to-toggle). A short close delay (see `closeTimer` below)
 * is standard tooltip UX: it survives the brief gap while the pointer
 * crosses from the icon onto the tooltip itself, so `help` HTML containing
 * a link (real example: "Please email us if you have any questions.") stays
 * clickable instead of vanishing the instant the mouse leaves the icon.
 *
 * @param {string} help
 * @returns {HTMLElement}
 */
function buildHelpTooltip(help) {
  const wrap = el('span', { className: 'ryv-help' });

  const tooltipId = `ryv-help-tooltip-${Math.random().toString(36).slice(2)}`;
  const button = el('button', {
    className: 'ryv-help-icon',
    text: '?',
    attrs: { type: 'button', 'aria-describedby': tooltipId, 'aria-label': 'Help' },
  });

  const tooltip = el('span', { className: 'ryv-help-tooltip', attrs: { id: tooltipId, role: 'tooltip' } });
  tooltip.innerHTML = help;
  tooltip.hidden = true;

  let closeTimer = null;

  const show = () => {
    if (closeTimer) {
      clearTimeout(closeTimer);
      closeTimer = null;
    }
    tooltip.hidden = false;
  };

  const scheduleHide = () => {
    closeTimer = window.setTimeout(() => {
      tooltip.hidden = true;
      closeTimer = null;
    }, 150);
  };

  button.addEventListener('mouseenter', show);
  button.addEventListener('mouseleave', scheduleHide);
  button.addEventListener('focus', show);
  button.addEventListener('blur', scheduleHide);
  tooltip.addEventListener('mouseenter', show);
  tooltip.addEventListener('mouseleave', scheduleHide);

  button.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      tooltip.hidden = true;
      button.blur();
    }
  });

  wrap.appendChild(button);
  wrap.appendChild(tooltip);
  return wrap;
}

/**
 * A node's `description` (NodeEditor.js's "Description" field) is optional
 * helper text below the title - same trusted-HTML boundary as `title`, so
 * rendered via innerHTML the same way. Returns null when unset, so callers
 * can skip appending it entirely rather than adding an empty element.
 */
function descriptionEl(description) {
  if (!description) return null;

  const p = document.createElement('p');
  p.className = 'ryv-question-description';
  p.innerHTML = description;
  return p;
}

/**
 * Append `card` to `container`. New cards fade/slide in; cards being
 * rebuilt in place (an edited answer) skip the animation so the page
 * doesn't jump while you're still looking at what you just clicked.
 */
export function appendCard(container, card, animate = true) {
  if (animate) {
    card.classList.add('ryv-card', 'ryv-card-enter');
    container.appendChild(card);
    requestAnimationFrame(() => {
      requestAnimationFrame(() => card.classList.remove('ryv-card-enter'));
    });
  } else {
    card.classList.add('ryv-card');
    container.appendChild(card);
  }
  return card;
}

/**
 * The optional "Step N" (or "Step N of Total") indicator above the question
 * list (see class-ryv-widget.php's `show_steps`/`total_steps` controls, and
 * engine.js's updateStepIndicator() for what "N" actually counts - there's
 * no fixed step count in a branching DAG, so this is the visitor's own
 * current position, not a total the graph could compute itself).
 *
 * @param {object} labels
 * @returns {HTMLElement}
 */
export function renderStepIndicator(labels = DEFAULT_LABELS) {
  return el('div', { className: 'ryv-step-indicator', attrs: { role: 'status' } });
}

/**
 * @param {HTMLElement} stepEl From renderStepIndicator().
 * @param {number} current Count of real (non-sentinel, non-hidden) cards currently on screen.
 * @param {number} total The widget's "Total Steps" setting - 0/unset means no total is shown.
 * @param {object} labels
 */
export function updateStepIndicator(stepEl, current, total, labels = DEFAULT_LABELS) {
  if (!stepEl) return;

  // Nothing real has rendered yet (still loading, or the graph's first node
  // is itself hidden) - leave the indicator empty rather than showing "Step 0".
  if (current < 1) {
    stepEl.textContent = '';
    return;
  }

  const template = total > 0 ? labels.stepWithTotal : labels.step;
  stepEl.textContent = template.replace('{current}', current).replace('{total}', total);
}

/**
 * The alternate "step sidebar" indicator (widget's `step_mode: sidebar`
 * control) - an admin-named checklist instead of a bare "Step N" counter.
 * Unlike renderStepIndicator()'s fixed count, the list of entries is built
 * and grown dynamically by engine.js's updateStepSidebar() as the visitor's
 * own path reveals nodes carrying a `step_name` - there is no way to know
 * the full list in advance in a branching DAG (same reasoning as Total
 * Steps above), so an entry only appears once a node with that name has
 * actually been rendered.
 *
 * @returns {HTMLElement} A `<ul>` - populated/updated via updateStepSidebar().
 */
export function renderStepSidebar() {
  return el('ul', { className: 'ryv-step-list' });
}

/**
 * @param {HTMLElement} listEl From renderStepSidebar().
 * @param {{name: string, firstNodeId: string}[]} steps Distinct, ordered
 *   steps reached so far - `firstNodeId` is the node that started this step,
 *   the target for `onStepClick` (revisiting a step always lands on its
 *   first question, not wherever inside it the visitor last was).
 * @param {number} activeIndex Index into `steps` of the step the
 *   currently-active (last on-screen, unanswered) card belongs to - entries
 *   before it are marked done (checkmark), entries at it are marked current
 *   (plain number, unfilled). Both are clickable; entries after it are not.
 *   -1 (nothing named reached yet) marks every entry as upcoming.
 * @param {(nodeId: string) => void} [onStepClick] Called with a done or
 *   current step's `firstNodeId` when the visitor clicks it, to jump back
 *   and revisit it (a current step's own `firstNodeId` just re-lands on the
 *   same card - harmless, and keeps it from looking inert on hover next to
 *   a done step). Omitted/no-op for a sidebar that isn't interactive (e.g.
 *   no step_mode).
 */
export function updateStepSidebar(listEl, steps, activeIndex, onStepClick) {
  if (!listEl) return;

  listEl.textContent = '';
  steps.forEach((step, index) => {
    const isDone = activeIndex >= 0 && index < activeIndex;
    const isActive = index === activeIndex;
    const isClickable = isDone || isActive;
    const marker = el('span', {
      className: 'ryv-step-list-marker',
      text: isDone ? '✓' : String(index + 1),
    });
    const itemAttrs = isClickable ? { role: 'button', tabindex: '0' } : {};
    const item = el('li', { className: 'ryv-step-list-item', attrs: itemAttrs }, [
      marker,
      el('span', { text: step.name }),
    ]);
    if (isDone) item.classList.add('is-done');
    if (isActive) item.classList.add('is-active');

    if (isClickable && onStepClick) {
      item.addEventListener('click', () => onStepClick(step.firstNodeId));
      item.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          onStepClick(step.firstNodeId);
        }
      });
    }

    listEl.appendChild(item);
  });
}

export function renderLoadingCard(labels = DEFAULT_LABELS) {
  return el('div', { className: 'ryv-loading', text: labels.loading });
}

export function renderErrorCard(message, onRetry, labels = DEFAULT_LABELS, icons = DEFAULT_ICONS) {
  const box = el('div', { className: 'ryv-error-box' });
  box.appendChild(el('p', { text: message || labels.error }));
  if (onRetry) {
    box.appendChild(
      el(
        'button',
        {
          className: 'ryv-continue-btn ryv-mt-3',
          attrs: { type: 'button' },
          on: { click: onRetry },
        },
        buttonChildren(labels.retry, icons.retry)
      )
    );
  }
  return box;
}

/**
 * Dependency-free "search-enabled dropdown" - a text input that filters a
 * listbox of options as you type, committing on click/Enter. The vanilla-JS
 * equivalent of a Select2-enhanced `<select>` (triggered by a Drupal
 * `#select2: true` source element - see RYV_YAML_Parser::build_node())
 * without adding jQuery/Select2 as a new runtime dependency to an otherwise
 * dependency-free, no-bundler frontend (see CLAUDE.md). Most useful for a
 * long option list like RYV's real ~50-state picker, where scanning a
 * native `<select>` is slower than typing a few letters.
 *
 * @param {HTMLElement} wrap Container to append into.
 * @param {{value: string, label: string}[]} options
 * @param {string|null} selectedValue
 * @param {(value: string) => void} onSelect
 * @param {object} labels
 * @param {string|null} placeholder Per-question override (node.placeholder, NodeEditor.js's "Placeholder" field) - falls back to labels.selectPlaceholder when unset.
 */
function renderSearchableSelect(wrap, options, selectedValue, onSelect, labels = DEFAULT_LABELS, placeholder = null) {
  const container = el('div', { className: 'ryv-select2', attrs: { role: 'combobox', 'aria-expanded': 'false' } });
  const currentOption = () => options.find((option) => option.value === selectedValue);

  const input = el('input', {
    className: 'ryv-select2-input',
    attrs: { type: 'text', role: 'searchbox', autocomplete: 'off', placeholder: placeholder || labels.selectPlaceholder },
  });
  input.value = currentOption() ? currentOption().label : '';

  const menu = el('div', { className: 'ryv-select2-menu' });
  menu.hidden = true;

  let visibleOptions = [];
  let highlightedIndex = -1;

  // Every `.ryv-card` gets its own stacking context (see .ryv-card's
  // `transform`, which CSS Transforms treats as `position: relative` for
  // stacking purposes even though the class never sets `position`) - so a
  // LATER card, having no explicit z-index of its own, still paints over
  // an EARLIER card's contents purely by DOM order, no matter what z-index
  // this dropdown's own menu carries: z-index only wins comparisons within
  // the stacking context it's actually created in, and this menu's context
  // is scoped to its own card, not the sibling list. Bumping the whole
  // ancestor card's z-index while its menu is open is what actually lets
  // it float above every card rendered after it.
  function setCardElevated(elevated) {
    const card = container.closest('.ryv-card');
    if (card) card.classList.toggle('ryv-card-elevated', elevated);
  }

  function closeMenu() {
    menu.hidden = true;
    container.setAttribute('aria-expanded', 'false');
    highlightedIndex = -1;
    setCardElevated(false);
  }

  function openMenu(query) {
    const q = query.trim().toLowerCase();
    visibleOptions = q ? options.filter((option) => option.label.toLowerCase().includes(q)) : options;

    setCardElevated(true);
    menu.innerHTML = '';
    if (!visibleOptions.length) {
      menu.appendChild(el('div', { className: 'ryv-select2-empty', text: labels.select2NoResults }));
    } else {
      visibleOptions.forEach((option, index) => {
        const optionEl = el('div', {
          className: `ryv-select2-option${index === highlightedIndex ? ' is-highlighted' : ''}`,
          text: option.label,
          attrs: { role: 'option', 'aria-selected': option.value === selectedValue ? 'true' : 'false' },
        });
        // mousedown (not click) fires before the input's blur handler below,
        // so selecting an option commits before closeMenu()'s revert logic
        // would otherwise wipe out the click.
        optionEl.addEventListener('mousedown', (event) => {
          event.preventDefault();
          commit(option);
        });
        menu.appendChild(optionEl);
      });
    }

    menu.hidden = false;
    container.setAttribute('aria-expanded', 'true');
  }

  function commit(option) {
    input.value = option.label;
    closeMenu();
    onSelect(option.value);
  }

  input.addEventListener('focus', () => openMenu(''));
  input.addEventListener('input', () => {
    highlightedIndex = -1;
    openMenu(input.value);
  });
  input.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      if (menu.hidden) { openMenu(input.value); return; }
      highlightedIndex = Math.min(highlightedIndex + 1, visibleOptions.length - 1);
      openMenu(input.value);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      highlightedIndex = Math.max(highlightedIndex - 1, 0);
      openMenu(input.value);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      if (!menu.hidden && visibleOptions[highlightedIndex]) commit(visibleOptions[highlightedIndex]);
    } else if (event.key === 'Escape') {
      closeMenu();
      input.value = currentOption() ? currentOption().label : '';
    }
  });
  input.addEventListener('blur', () => {
    // Deferred so a mousedown-selected option (see above) has already
    // committed by the time this reverts an unmatched typed value back to
    // the current answer - this is a single-choice field, not free text.
    window.setTimeout(() => {
      if (menu.hidden) return;
      closeMenu();
      input.value = currentOption() ? currentOption().label : '';
    }, 100);
  });

  container.appendChild(input);
  container.appendChild(menu);
  wrap.appendChild(container);
}

/**
 * Shared single-select rendering for radio-type question nodes (including
 * the "select your state" node - it's an ordinary radio node, not a special
 * case): 'buttons' (default, current look), 'radio' (actual radio inputs),
 * or 'dropdown' (a native <select>, or - when `searchable` is set - the
 * search-enabled combobox above). Checkbox nodes never call this - they
 * always render as the button list, regardless of a node's display_style.
 *
 * @param {HTMLElement} wrap Container to append into.
 * @param {{value: string, label: string}[]} options
 * @param {string|null} selectedValue
 * @param {(value: string) => void} onSelect
 * @param {string} displayStyle 'buttons' | 'radio' | 'dropdown'
 * @param {string} radioGroupName Unique `name` for the radio inputs, so multiple
 *   single-choice cards on the same page don't cross-select each other.
 * @param {object} labels
 * @param {boolean} searchable When displayStyle is 'dropdown', use the search-enabled combobox instead of a native <select> (node.select2).
 * @param {string|null} placeholder Per-question override (node.placeholder) - falls back to labels.selectPlaceholder when unset.
 */
function renderSingleChoiceOptions(wrap, options, selectedValue, onSelect, displayStyle, radioGroupName, labels = DEFAULT_LABELS, searchable = false, placeholder = null) {
  if (displayStyle === 'dropdown') {
    if (searchable) {
      renderSearchableSelect(wrap, options, selectedValue, onSelect, labels, placeholder);
      return;
    }

    const select = el('select', { className: 'ryv-select' });
    select.appendChild(el('option', { text: placeholder || labels.selectPlaceholder, attrs: { value: '', disabled: true } }));
    options.forEach((option) => {
      const opt = el('option', { text: option.label, attrs: { value: option.value } });
      if (option.value === selectedValue) opt.setAttribute('selected', 'selected');
      select.appendChild(opt);
    });
    if (!selectedValue) select.value = '';
    select.addEventListener('change', () => onSelect(select.value));
    wrap.appendChild(select);
    return;
  }

  if (displayStyle === 'radio') {
    options.forEach((option) => {
      const input = el('input', {
        className: 'ryv-radio-input',
        attrs: { type: 'radio', name: radioGroupName, value: option.value },
      });
      input.checked = option.value === selectedValue;
      input.addEventListener('change', () => onSelect(option.value));

      const label = el('label', { className: 'ryv-radio-option' }, [input, el('span', { text: option.label })]);
      wrap.appendChild(label);
    });
    return;
  }

  // 'buttons' (default) - unchanged look/behavior. Wrapped in its own flex
  // container (.ryv-options-list) so the Elementor widget's layout controls
  // (direction/wrap/gap/width) can target just the option list, not
  // whatever else is in `wrap` (the question title).
  const list = el('div', { className: 'ryv-options-list' });
  options.forEach((option) => {
    const isSelected = option.value === selectedValue;
    const btn = el('button', {
      className: 'ryv-option-btn',
      text: option.label,
      attrs: { type: 'button', 'aria-pressed': isSelected ? 'true' : 'false' },
      on: { click: () => onSelect(option.value) },
    });
    list.appendChild(btn);
  });
  wrap.appendChild(list);
}

/**
 * @param {object} node RYV node ({ type: 'radio'|'checkbox', title, options, display_style? }).
 * @param {string[]} selectedValues Currently-recorded answer for this node (both radio and checkbox store an array).
 * @param {(values: string[]) => void} onChange Called with the full set of selected value(s) after every click -
 *   immediately, no separate Continue step. An empty array means the answer was cleared.
 * @param {string} nodeId Node id, used to give the radio-input group a unique `name`.
 * @param {object} labels
 * @param {object} [allNodes] Full `{ id: node }` graph - only needed to resolve `node.reference_node_id`
 *   (see appendReferenceContent()). Optional so callers that never merge reference content can omit it.
 */
/**
 * @param {boolean} deferCommit Wizard (one-question-at-a-time) layout mode:
 *   a click/change only updates the visible selection - `onChange` isn't
 *   called until the visitor clicks the appended "Continue" button. Stacked
 *   (default) layout keeps today's behavior: `onChange` fires immediately,
 *   no Continue button is rendered at all.
 */
export function renderQuestionCard(
  node,
  selectedValues,
  onChange,
  nodeId,
  labels = DEFAULT_LABELS,
  allNodes = null,
  deferCommit = false,
  icons = DEFAULT_ICONS
) {
  const wrap = cardWrap(node);
  wrap.appendChild(titleEl(node.title, node.help));
  if (node.description) wrap.appendChild(descriptionEl(node.description));

  const isMulti = node.type === 'checkbox';
  const selected = new Set(selectedValues || []);

  let continueBtn = null;
  let pending = Array.from(selected);
  const commit = (values) => {
    pending = values;
    if (!deferCommit) {
      onChange(values);
      return;
    }
    if (continueBtn) continueBtn.disabled = pending.length === 0;
  };
  const appendContinueButton = () => {
    if (!deferCommit) return;
    continueBtn = el(
      'button',
      {
        className: 'ryv-continue-btn',
        attrs: { type: 'button' },
        on: { click: () => onChange(pending) },
      },
      buttonChildren(labels.continue, icons.continue)
    );
    continueBtn.disabled = pending.length === 0;
    wrap.appendChild(continueBtn);
  };

  // Checkbox nodes always render as the button list regardless of
  // display_style - the display_style setting only applies to single-choice
  // (radio) questions, same as the state picker.
  if (!isMulti && node.display_style && node.display_style !== 'buttons') {
    renderSingleChoiceOptions(
      wrap,
      node.options || [],
      selectedValues && selectedValues[0],
      (value) => commit(value ? [value] : []),
      node.display_style,
      `ryv-node-${nodeId}`,
      labels,
      !!node.select2,
      node.placeholder || null
    );
    appendReferenceContent(wrap, node, allNodes);
    appendContinueButton();
    return wrap;
  }

  const list = el('div', { className: 'ryv-options-list' });
  const buttons = new Map();

  (node.options || []).forEach((option) => {
    const btn = el('button', {
      className: 'ryv-option-btn',
      text: option.label,
      attrs: { type: 'button', 'aria-pressed': selected.has(option.value) ? 'true' : 'false' },
    });
    buttons.set(option.value, btn);

    btn.addEventListener('click', () => {
      if (isMulti) {
        const pressed = btn.getAttribute('aria-pressed') === 'true';
        btn.setAttribute('aria-pressed', pressed ? 'false' : 'true');
        if (pressed) {
          selected.delete(option.value);
        } else {
          selected.add(option.value);
        }
      } else {
        selected.clear();
        selected.add(option.value);
        buttons.forEach((otherBtn, value) => {
          otherBtn.setAttribute('aria-pressed', value === option.value ? 'true' : 'false');
        });
      }
      commit(Array.from(selected));
    });

    list.appendChild(btn);
  });

  wrap.appendChild(list);
  appendReferenceContent(wrap, node, allNodes);
  appendContinueButton();
  return wrap;
}

/**
 * `node.reference_node_ids` on a question node (radio/checkbox) is an
 * ordered list of other nodes - typically `markup` nodes - whose content
 * should render inline below this question's own options, in the same
 * card, in list order, rather than as their own separate steps. Set by
 * RYV_YAML_Parser::build_subtree() when a markup node and a choice node
 * shared the exact same #states condition and the question's own options
 * left no free slot to route to the markup node on its own (see its
 * docblock - confirmed against the real, live site: e.g. Alabama's felony
 * checklist renders right below its question's own Yes/No/Maybe/FOS
 * buttons, not as a separate click-through step). Each entry is a pointer,
 * not a copy - a referenced node's own `content`/`wrapper_class` stay the
 * single source of truth (editable in the builder like any other markup
 * node), so this always reflects the latest edit with nothing to keep in
 * sync. Trusted HTML, same boundary as `title`/`content` elsewhere.
 */
function appendReferenceContent(wrap, node, allNodes) {
  const ids = node.reference_node_ids;
  if (!ids || !ids.length || !allNodes) return;

  ids.forEach((id) => {
    const refNode = allNodes[id];
    if (!refNode || !refNode.content) return;

    const className = refNode.wrapper_class ? `ryv-content ${refNode.wrapper_class}` : 'ryv-content';
    const content = el('div', { className });
    content.innerHTML = refNode.content;
    wrap.appendChild(content);
  });
}

/**
 * @param {boolean} deferCommit Wizard layout mode: also renders a "Continue"
 *   button that calls the same `commit()` as blur/Enter - harmless to leave
 *   blur/Enter active too, since committing the same value twice is a no-op
 *   (see `lastCommitted` below).
 */
export function renderInputCard(node, previousValue, onChange, labels = DEFAULT_LABELS, deferCommit = false, icons = DEFAULT_ICONS) {
  const wrap = cardWrap(node);
  wrap.appendChild(titleEl(node.title, node.help));
  if (node.description) wrap.appendChild(descriptionEl(node.description));

  const tag = node.type === 'textarea' ? 'textarea' : 'input';
  const input = el(tag, {
    className: 'ryv-input',
    attrs: node.type === 'textarea' ? {} : { type: 'text' },
  });
  if (previousValue) input.value = previousValue;

  // Enter commits immediately, which rebuilds/replaces this card - and
  // removing a focused element fires a native 'blur' on it too, which
  // would otherwise re-fire onChange a second time with the same value.
  // Committing only when the value actually changed makes that harmless,
  // while still letting the user come back and edit this card later.
  let lastCommitted = previousValue || '';
  const commit = () => {
    if (input.value === lastCommitted) return;
    lastCommitted = input.value;
    onChange(input.value);
  };

  input.addEventListener('blur', commit);
  if (tag === 'input') {
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        commit();
      }
    });
  }

  wrap.appendChild(input);

  if (deferCommit) {
    const continueBtn = el(
      'button',
      {
        className: 'ryv-continue-btn',
        attrs: { type: 'button' },
        on: { click: commit },
      },
      buttonChildren(labels.continue, icons.continue)
    );
    wrap.appendChild(continueBtn);
  }

  return wrap;
}

/**
 * HTML5 input `type` attribute for each supported `form_fields[].type` -
 * 'textarea' isn't in here since it's a different tag entirely, handled
 * separately in buildFieldControl().
 */
const FIELD_INPUT_TYPES = new Set(['text', 'email', 'date', 'tel']);

/**
 * One `<label>`-wrapped input/textarea for a single `node.form_fields[]`
 * entry - the label text is the admin-authored `field.label`, the control's
 * `name`/map key is `field.name` (also what `{field_name}` template
 * placeholders reference server-side in RYV_API::render_template()).
 */
function buildFieldControl(field) {
  const tag = field.type === 'textarea' ? 'textarea' : 'input';
  const attrs = { name: field.name };
  if (tag === 'input') {
    attrs.type = FIELD_INPUT_TYPES.has(field.type) ? field.type : 'text';
  }
  if (field.required) attrs.required = 'required';
  if (field.placeholder) attrs.placeholder = field.placeholder;

  const wrapLabel = el('label', { className: 'ryv-field-label' });
  wrapLabel.appendChild(el('span', { className: 'ryv-field-label-text', text: field.label || field.name }));
  const control = el(tag, { className: 'ryv-input', attrs });
  wrapLabel.appendChild(control);

  return { wrapLabel, control };
}

/**
 * `lead_capture` node: a form built entirely from `node.form_fields`
 * (label/name/type/required/placeholder, authored in the builder) - what the
 * form does with a submission (`node.email_settings`: save_to_db/admin &
 * user emails/templates) is never read here, only interpreted server-side by
 * RYV_API::submit_lead(), since it decides backend behavior, not how the
 * form itself looks.
 *
 * Unlike every other node type, this one never auto-advances on its own -
 * once submitted, it shows `node.success_message` (falling back to
 * `labels.leadSubmitted`) and, only if `node.next_node` is set, a "Continue"
 * button the visitor must click themselves (see engine.js's computeNext()/
 * onLeadContinue()). A blank `next_node` halts the flow right there, same as
 * a terminal markup node.
 *
 * @param {object} node RYV node ({ type: 'lead_capture', title, form_fields, success_message, next_node }).
 * @param {Object<string,string>|null} submittedValue Set once this node has
 *   been successfully submitted (field name -> submitted value) - swaps the
 *   form out for the success message (+ Continue button) instead of
 *   re-showing empty inputs, same idea as renderInputCard() carrying
 *   forward `previousValue`.
 * @param {(values: Object<string,string>) => Promise<any>} onSubmit Called
 *   with `{ [field.name]: trimmed value }` for every field; engine.js wires
 *   this to submitLead() followed by recording the answer. The returned
 *   promise's rejection is caught here to show an inline error and
 *   re-enable the form.
 * @param {() => void} onContinue Called when the visitor clicks "Continue"
 *   after a successful submission; engine.js wires this to advance to
 *   `node.next_node`. Never called if `node.next_node` is empty.
 * @param {object} labels
 */
export function renderLeadCaptureCard(node, submittedValue, onSubmit, onContinue, labels = DEFAULT_LABELS, icons = DEFAULT_ICONS) {
  const wrap = cardWrap(node);
  if (node.title) wrap.appendChild(titleEl(node.title, node.help));
  if (node.description) wrap.appendChild(descriptionEl(node.description));

  if (submittedValue) {
    wrap.appendChild(el('div', { className: 'ryv-content', text: node.success_message || labels.leadSubmitted }));

    if (node.next_node) {
      const continueBtn = el(
        'button',
        {
          className: 'ryv-continue-btn',
          attrs: { type: 'button' },
        },
        buttonChildren(labels.continue, icons.continue)
      );
      continueBtn.addEventListener('click', () => {
        continueBtn.disabled = true;
        onContinue();
      });
      wrap.appendChild(continueBtn);
    }

    return wrap;
  }

  const fields = (node.form_fields || []).filter((field) => field && field.name);
  const form = el('form', { className: 'ryv-lead-form' });
  const controls = new Map(); // field name -> input/textarea element

  fields.forEach((field) => {
    const { wrapLabel, control } = buildFieldControl(field);
    controls.set(field.name, control);
    form.appendChild(wrapLabel);
  });

  const errorBox = el('p', { className: 'ryv-lead-form-error' });
  errorBox.style.display = 'none';

  const submitBtn = el('button', {
    className: 'ryv-continue-btn',
    text: labels.submit,
    attrs: { type: 'submit' },
  });

  form.appendChild(errorBox);
  form.appendChild(submitBtn);

  form.addEventListener('submit', (event) => {
    event.preventDefault();

    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    errorBox.style.display = 'none';
    submitBtn.disabled = true;
    submitBtn.textContent = labels.submitting;

    const values = {};
    controls.forEach((control, name) => {
      values[name] = control.value.trim();
    });

    Promise.resolve(onSubmit(values)).catch((err) => {
      submitBtn.disabled = false;
      submitBtn.textContent = labels.submit;
      errorBox.textContent = (err && err.message) || labels.leadError;
      errorBox.style.display = '';
    });
  });

  wrap.appendChild(form);
  return wrap;
}

/**
 * `node.content` is trusted HTML: it only ever comes from a YAML file
 * imported (or a schema edited) by an admin with `manage_options`, the same
 * trust boundary WordPress applies to post content - not user input.
 *
 * `node.wrapper_class` carries the same trust boundary and is applied
 * directly rather than re-validated, whether it came from a YAML import
 * (allowlist-sanitized server-side - see
 * RYV_YAML_Parser::extract_wrapper_classes(), only 'text-restrict-height'/
 * 'message--path-end' ever reach the schema that way) or was typed directly
 * into NodeEditor.js's free-text "CSS Class" field (no allowlist there -
 * same trust level as `content`/`title` above, so no extra validation adds
 * real safety). Applied to the `.ryv-content` div specifically, not the
 * outer card wrap like every other card type (see cardWrap()) - e.g.
 * `text-restrict-height` needs to scope to just the scrollable content, not
 * the title above it too.
 *
 * @param {boolean} deferCommit Wizard (one-question-at-a-time) layout mode:
 *   a non-terminal markup card (one with a `next_node`) stops here and
 *   renders its own "Continue" button instead of engine.js's renderFrom()
 *   walking straight through it - otherwise the card would be built, hidden
 *   by updateWizardVisibility(), and replaced by its next_node's card all
 *   within the same synchronous pass, so the visitor would never actually
 *   see it painted. Stacked (default) layout keeps today's behavior: no
 *   button, the whole chain renders and stays visible at once.
 * @param {() => void} [onContinue] Called when the Continue button is
 *   clicked. Only used when `deferCommit` is true and the card isn't
 *   terminal.
 */
export function renderMarkupCard(
  node,
  isTerminal,
  onRestart,
  labels = DEFAULT_LABELS,
  deferCommit = false,
  onContinue = null,
  icons = DEFAULT_ICONS
) {
  const wrap = el('div');
  if (node.title) {
    wrap.appendChild(titleEl(node.title, node.help));
  }
  if (node.description) {
    wrap.appendChild(descriptionEl(node.description));
  }

  const contentClass = node.wrapper_class ? `ryv-content ${node.wrapper_class}` : 'ryv-content';
  const content = el('div', { className: contentClass });
  content.innerHTML = node.content || '';
  wrap.appendChild(content);

  if (isTerminal) {
    wrap.appendChild(
      el(
        'button',
        {
          className: 'ryv-continue-btn',
          attrs: { type: 'button' },
          on: { click: onRestart },
        },
        buttonChildren(labels.startOver, icons.startOver)
      )
    );
  } else if (deferCommit) {
    wrap.appendChild(
      el(
        'button',
        {
          className: 'ryv-continue-btn',
          attrs: { type: 'button' },
          on: { click: onContinue },
        },
        buttonChildren(labels.continue, icons.continue)
      )
    );
  }

  return wrap;
}

export function renderUnsupportedCard(node, isTerminal, onRestart, labels = DEFAULT_LABELS, icons = DEFAULT_ICONS) {
  const wrap = el('div', { className: 'ryv-error-box' });
  wrap.appendChild(
    el('p', {
      text: `This step ("${node.title || node.source_type}") needs configuration before it can be shown.`,
    })
  );
  if (isTerminal) {
    wrap.appendChild(
      el(
        'button',
        {
          className: 'ryv-continue-btn ryv-mt-3',
          attrs: { type: 'button' },
          on: { click: onRestart },
        },
        buttonChildren(labels.startOver, icons.startOver)
      )
    );
  }
  return wrap;
}
