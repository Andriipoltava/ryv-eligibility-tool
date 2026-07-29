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

export function renderLoadingCard() {
  return el('div', { className: 'ryv-loading', text: 'Loading…' });
}

export function renderErrorCard(message, onRetry) {
  const box = el('div', { className: 'ryv-error-box' });
  box.appendChild(el('p', { text: message || 'Something went wrong.' }));
  if (onRetry) {
    box.appendChild(
      el('button', {
        className: 'ryv-continue-btn ryv-mt-3',
        text: 'Try Again',
        attrs: { type: 'button' },
        on: { click: onRetry },
      })
    );
  }
  return box;
}

export function renderStateCard(states, selectedStateId, onSelect) {
  const wrap = el('div');
  wrap.appendChild(el('h2', { className: 'ryv-question-title', text: 'Select your state' }));

  if (!states || states.length === 0) {
    wrap.appendChild(el('p', { className: 'ryv-loading', text: 'No states are configured for this form yet.' }));
    return wrap;
  }

  states.forEach((state) => {
    const isSelected = state.id === selectedStateId;
    const btn = el('button', {
      className: 'ryv-option-btn',
      text: state.label,
      attrs: { type: 'button', 'aria-pressed': isSelected ? 'true' : 'false' },
      on: { click: () => onSelect(state.id) },
    });
    wrap.appendChild(btn);
  });

  return wrap;
}

/**
 * @param {object} node RYV node ({ type: 'radio'|'checkbox', title, options }).
 * @param {string[]} selectedValues Currently-recorded answer for this node (both radio and checkbox store an array).
 * @param {(values: string[]) => void} onChange Called with the full set of selected value(s) after every click -
 *   immediately, no separate Continue step. An empty array means the answer was cleared.
 */
export function renderQuestionCard(node, selectedValues, onChange) {
  const wrap = el('div');
  wrap.appendChild(el('h2', { className: 'ryv-question-title', text: node.title }));

  const isMulti = node.type === 'checkbox';
  const selected = new Set(selectedValues || []);
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

    wrap.appendChild(btn);
  });

  return wrap;
}

export function renderInputCard(node, previousValue, onChange) {
  const wrap = el('div');
  wrap.appendChild(el('h2', { className: 'ryv-question-title', text: node.title }));

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
export function renderMarkupCard(node, isTerminal, onRestart) {
  const wrap = el('div');
  if (node.title) {
    wrap.appendChild(el('h2', { className: 'ryv-question-title', text: node.title }));
  }

  const content = el('div', { className: 'ryv-content' });
  content.innerHTML = node.content || '';
  wrap.appendChild(content);

  if (isTerminal) {
    wrap.appendChild(
      el('button', {
        className: 'ryv-continue-btn',
        text: 'Start Over',
        attrs: { type: 'button' },
        on: { click: onRestart },
      })
    );
  }

  return wrap;
}

export function renderUnsupportedCard(node, isTerminal, onRestart) {
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
        text: 'Start Over',
        attrs: { type: 'button' },
        on: { click: onRestart },
      })
    );
  }
  return wrap;
}
