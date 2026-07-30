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
  noStatesConfigured: 'No states are configured for this form yet.',
};

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

export function createList() {
  return el('div', { className: 'ryv-list' });
}

/**
 * A node's `title` is trusted HTML, same trust boundary as `content` (see
 * renderMarkupCard) - it only ever comes from a YAML import or the builder,
 * both gated to `manage_options`/`edit_post`. Rendered via innerHTML rather
 * than textContent so inline tags (<em>, <strong>, ...) actually format
 * instead of showing as literal text.
 */
function titleEl(title) {
  const h2 = document.createElement('h2');
  h2.className = 'ryv-question-title';
  h2.innerHTML = title || '';
  return h2;
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

export function renderLoadingCard(labels = DEFAULT_LABELS) {
  return el('div', { className: 'ryv-loading', text: labels.loading });
}

export function renderErrorCard(message, onRetry, labels = DEFAULT_LABELS) {
  const box = el('div', { className: 'ryv-error-box' });
  box.appendChild(el('p', { text: message || labels.error }));
  if (onRetry) {
    box.appendChild(
      el('button', {
        className: 'ryv-continue-btn ryv-mt-3',
        text: labels.retry,
        attrs: { type: 'button' },
        on: { click: onRetry },
      })
    );
  }
  return box;
}

export function renderStateCard(
  states,
  selectedStateId,
  onSelect,
  displayStyle = 'buttons',
  label = 'Select your state',
  labels = DEFAULT_LABELS
) {
  const wrap = el('div');
  wrap.appendChild(titleEl(label));

  if (!states || states.length === 0) {
    wrap.appendChild(el('p', { className: 'ryv-loading', text: labels.noStatesConfigured }));
    return wrap;
  }

  renderSingleChoiceOptions(
    wrap,
    states.map((state) => ({ value: state.id, label: state.label })),
    selectedStateId,
    onSelect,
    displayStyle,
    'ryv-state',
    labels
  );

  return wrap;
}

/**
 * Shared single-select rendering for the state picker and radio-type question
 * nodes: 'buttons' (default, current look), 'radio' (actual radio inputs), or
 * 'dropdown' (a native <select>). Checkbox nodes never call this - they
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
 */
function renderSingleChoiceOptions(wrap, options, selectedValue, onSelect, displayStyle, radioGroupName, labels = DEFAULT_LABELS) {
  if (displayStyle === 'dropdown') {
    const select = el('select', { className: 'ryv-select' });
    select.appendChild(el('option', { text: labels.selectPlaceholder, attrs: { value: '', disabled: true } }));
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
 */
export function renderQuestionCard(node, selectedValues, onChange, nodeId, labels = DEFAULT_LABELS) {
  const wrap = el('div');
  wrap.appendChild(titleEl(node.title));

  const isMulti = node.type === 'checkbox';
  const selected = new Set(selectedValues || []);

  // Checkbox nodes always render as the button list regardless of
  // display_style - the display_style setting only applies to single-choice
  // (radio) questions, same as the state picker.
  if (!isMulti && node.display_style && node.display_style !== 'buttons') {
    renderSingleChoiceOptions(
      wrap,
      node.options || [],
      selectedValues && selectedValues[0],
      (value) => onChange(value ? [value] : []),
      node.display_style,
      `ryv-node-${nodeId}`,
      labels
    );
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
      onChange(Array.from(selected));
    });

    list.appendChild(btn);
  });

  wrap.appendChild(list);
  return wrap;
}

export function renderInputCard(node, previousValue, onChange) {
  const wrap = el('div');
  wrap.appendChild(titleEl(node.title));

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
  return wrap;
}

/**
 * `node.content` is trusted HTML: it only ever comes from a YAML file
 * imported (or a schema edited) by an admin with `manage_options`, the same
 * trust boundary WordPress applies to post content - not user input.
 */
export function renderMarkupCard(node, isTerminal, onRestart, labels = DEFAULT_LABELS) {
  const wrap = el('div');
  if (node.title) {
    wrap.appendChild(titleEl(node.title));
  }

  const content = el('div', { className: 'ryv-content' });
  content.innerHTML = node.content || '';
  wrap.appendChild(content);

  if (isTerminal) {
    wrap.appendChild(
      el('button', {
        className: 'ryv-continue-btn',
        text: labels.startOver,
        attrs: { type: 'button' },
        on: { click: onRestart },
      })
    );
  }

  return wrap;
}

export function renderUnsupportedCard(node, isTerminal, onRestart, labels = DEFAULT_LABELS) {
  const wrap = el('div', { className: 'ryv-error-box' });
  wrap.appendChild(
    el('p', {
      text: `This step ("${node.title || node.source_type}") needs configuration before it can be shown.`,
    })
  );
  if (isTerminal) {
    wrap.appendChild(
      el('button', {
        className: 'ryv-continue-btn ryv-mt-3',
        text: labels.startOver,
        attrs: { type: 'button' },
        on: { click: onRestart },
      })
    );
  }
  return wrap;
}
