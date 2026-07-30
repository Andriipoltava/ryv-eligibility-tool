/**
 * DOM builders for each step's "card". The whole questionnaire renders as a
 * growing vertical list of cards - one per answered (or currently-active)
 * question - and every card stays clickable/editable for as long as it's on
 * screen: changing an answer just re-renders everything downstream of it
 * (handled by engine.js). The Back button (createControls()) is a shortcut
 * for the same truncate-and-recompute mechanism, not a separate mode.
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
 * A small persistent control bar (currently just the Back button) rendered
 * once, above the card list. `_backBtn` is stashed on the returned element
 * so setBackEnabled() doesn't need a second lookup mechanism.
 */
export function createControls(onBack) {
  const wrap = el('div', { className: 'ryv-controls' });
  const backBtn = el('button', {
    className: 'ryv-back-btn ryv-hidden',
    text: '← Back',
    attrs: { type: 'button', disabled: 'disabled' },
    on: { click: onBack },
  });
  wrap.appendChild(backBtn);
  wrap._backBtn = backBtn;
  return wrap;
}

/** Toggle the Back button rendered by createControls(). */
export function setBackEnabled(controls, enabled) {
  const backBtn = controls && controls._backBtn;
  if (!backBtn) return;
  backBtn.disabled = !enabled;
  backBtn.classList.toggle('ryv-hidden', !enabled);
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
 * Fade/slide `element` out, then remove it. Used by truncateFrom() so
 * cards dropped off the end of the list (an edited answer, Back) leave the
 * same way new ones arrive, instead of just vanishing.
 */
export function removeCard(element) {
  if (!element || !element.parentNode) return;
  element.classList.add('ryv-card-leave');
  window.setTimeout(() => {
    if (element.parentNode) element.parentNode.removeChild(element);
  }, 200);
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

/**
 * Any node with an `options` array renders as this button group, whatever
 * its `type` claims to be (radio/checkbox/webform_buttons/...) - the engine
 * is dumb about presentation, it only cares that `isMulti` tells it whether
 * to toggle one button at a time or many.
 *
 * @param {object} node RYV node ({ type, title, options }).
 * @param {string[]} selectedValues Currently-recorded answer for this node (both single- and multi-select store an array).
 * @param {boolean} isMulti Whether more than one option can be selected at once.
 * @param {(values: string[]) => void} onChange Called with the full set of selected value(s) after every click -
 *   immediately, no separate Continue step. An empty array means the answer was cleared.
 */
export function renderChoiceCard(node, selectedValues, isMulti, onChange) {
  const wrap = el('div');
  wrap.appendChild(el('h2', { className: 'ryv-question-title', text: node.title }));

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

/**
 * `type: 'select'` gets a native dropdown instead of a button group -
 * same contract as renderChoiceCard (options[].next_node), just a single
 * scalar value instead of an array.
 */
export function renderSelectCard(node, selectedValue, onChange) {
  const wrap = el('div');
  wrap.appendChild(el('h2', { className: 'ryv-question-title', text: node.title }));

  const select = el('select', { className: 'ryv-input' });
  select.appendChild(el('option', { text: '— Select —', attrs: { value: '' } }));

  (node.options || []).forEach((option) => {
    const opt = el('option', { text: option.label, attrs: { value: option.value } });
    select.appendChild(opt);
  });
  select.value = selectedValue || '';

  select.addEventListener('change', () => onChange(select.value));

  wrap.appendChild(select);
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
 * Content-only node (`markup`, `processed_text`, or anything else with no
 * `options` and no text-input semantics): renders its HTML and, per
 * engine.js's computeNext(), either auto-advances or - if terminal - shows
 * "Start Over".
 *
 * The HTML (`node.content` or `node.text` - the importer and the new flat
 * schema example use different field names for the same thing) is trusted:
 * it only ever comes from a YAML file imported (or a schema edited) by an
 * admin with `manage_options`, the same trust boundary WordPress applies to
 * post content - not user input.
 */
export function renderContentCard(node, isTerminal, onRestart) {
  const wrap = el('div');
  if (node.title) {
    wrap.appendChild(el('h2', { className: 'ryv-question-title', text: node.title }));
  }

  const content = el('div', { className: 'ryv-content' });
  content.innerHTML = node.content || node.text || '';
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
