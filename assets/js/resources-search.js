/**
 * RYV Resources Search widget (RYV_Resources_Search_Widget).
 *
 * Two progressive enhancements over the widget's plain GET form, each
 * switchable per widget instance:
 *
 * 1. Searchable state dropdown (`select[data-searchable]`): a select2-style
 *    combobox built on top of the native <select>, which stays in the form
 *    (hidden) as the value holder - so submission, and the no-JS fallback,
 *    work exactly as before.
 *
 * 2. AJAX results (`form[data-ajax]`): instead of reloading, push the new
 *    URL, fetch that same page and swap in only its Loop Grid + Taxonomy
 *    Filter widgets. Fetching the real page (rather than Elementor Pro's
 *    refresh-loop endpoint) is deliberate: the server renders the grid AND
 *    re-computes which category tabs still have results, with the exact same
 *    code path as a normal page load, and no dependency on Elementor Pro's
 *    internal JS API.
 */
(() => {
	// The search form's own fields. Sorting lives in its own widget
	// (RYV Resources Sort) and isn't dropped by "Clear filters" - it's a view
	// choice, not a filter.
	const FILTER_PARAMS = ['ryv_search', 'ryv_state'];
	const RESULTS_SELECTOR = '.elementor-widget-loop-grid, .elementor-widget-taxonomy-filter';
	const LIVE_DELAY = 350;

	const isEditor = () => document.body.classList.contains('elementor-editor-active');

	/** AJAX is a setting of the page's search widget; the sort widget follows it. */
	const ajaxEnabled = () => !!document.querySelector('.ryv-resources-search[data-ajax]');

	const withoutPageNumbers = (url) => {
		[...url.searchParams.keys()]
			.filter((key) => key.startsWith('e-page-'))
			.forEach((key) => url.searchParams.delete(key));
		url.hash = '';
		return url;
	};

	const normalize = (text) =>
		String(text).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

	/* ------------------------------------------------------------------ */
	/* Searchable dropdown                                                 */
	/* ------------------------------------------------------------------ */

	let comboCount = 0;

	function enhanceSelect(select) {
		if (select.dataset.enhanced) {
			return;
		}
		select.dataset.enhanced = '1';

		const options = [...select.options].map((option) => ({
			value: option.value,
			label: option.textContent.trim(),
			key: normalize(option.textContent),
		}));
		const allLabel = options.find((option) => '' === option.value)?.label || '';
		const noResults = select.dataset.noResults || '';
		const menuId = `ryv-rs-combo-${++comboCount}`;

		const combo = document.createElement('div');
		combo.className = 'ryv-resources-search__combo';

		const input = document.createElement('input');
		input.type = 'text';
		input.className = 'ryv-resources-search__combo-input';
		input.autocomplete = 'off';
		input.spellcheck = false;
		input.placeholder = allLabel;
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-expanded', 'false');
		input.setAttribute('aria-controls', menuId);
		input.setAttribute('aria-label', select.labels?.[0]?.textContent.trim() || allLabel);

		const menu = document.createElement('ul');
		menu.className = 'ryv-resources-search__combo-menu';
		menu.id = menuId;
		menu.setAttribute('role', 'listbox');
		menu.hidden = true;

		combo.append(input, menu);
		select.after(combo);
		select.classList.add('ryv-resources-search__select--enhanced');
		select.tabIndex = -1;
		select.setAttribute('aria-hidden', 'true');

		let visible = [];
		let highlighted = -1;
		let blurTimer = 0;

		const currentLabel = () => {
			const option = options.find((item) => item.value === select.value);
			return option && option.value ? option.label : '';
		};

		const sync = () => {
			input.value = currentLabel();
		};

		const render = () => {
			menu.innerHTML = '';

			if (!visible.length) {
				const empty = document.createElement('li');
				empty.className = 'ryv-resources-search__combo-empty';
				empty.textContent = noResults;
				menu.append(empty);
				input.removeAttribute('aria-activedescendant');
				return;
			}

			visible.forEach((option, index) => {
				const item = document.createElement('li');
				item.className = 'ryv-resources-search__combo-option';
				item.id = `${menuId}-${index}`;
				item.textContent = option.label;
				item.setAttribute('role', 'option');
				item.setAttribute('aria-selected', option.value === select.value ? 'true' : 'false');
				if (index === highlighted) {
					item.classList.add('is-highlighted');
					input.setAttribute('aria-activedescendant', item.id);
				}
				// mousedown, not click: fires before the input's blur below,
				// so the pick lands before the menu closes and reverts.
				item.addEventListener('mousedown', (event) => {
					event.preventDefault();
					commit(option);
				});
				menu.append(item);
			});

			menu.querySelector('.is-highlighted')?.scrollIntoView({ block: 'nearest' });
		};

		const open = (query) => {
			const q = normalize(query);
			visible = q ? options.filter((option) => option.key.includes(q)) : options;
			if (highlighted >= visible.length) {
				highlighted = visible.length - 1;
			}
			render();
			menu.hidden = false;
			combo.classList.add('is-open');
			input.setAttribute('aria-expanded', 'true');
		};

		const close = () => {
			menu.hidden = true;
			highlighted = -1;
			combo.classList.remove('is-open');
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
		};

		const commit = (option) => {
			const changed = select.value !== option.value;
			select.value = option.value;
			sync();
			close();
			if (changed) {
				select.dispatchEvent(new Event('change', { bubbles: true }));
			}
		};

		input.addEventListener('focus', () => {
			clearTimeout(blurTimer);
			highlighted = Math.max(0, options.findIndex((option) => option.value === select.value));
			input.select();
			open('');
		});
		input.addEventListener('click', () => menu.hidden && open(''));
		input.addEventListener('input', () => {
			highlighted = 0;
			open(input.value);
		});
		input.addEventListener('keydown', (event) => {
			switch (event.key) {
				case 'ArrowDown':
					event.preventDefault();
					if (menu.hidden) {
						open(input.value);
						return;
					}
					highlighted = Math.min(highlighted + 1, visible.length - 1);
					render();
					break;
				case 'ArrowUp':
					event.preventDefault();
					highlighted = Math.max(highlighted - 1, 0);
					render();
					break;
				case 'Enter':
					// Never let Enter here submit the whole form.
					event.preventDefault();
					if (!menu.hidden && visible[highlighted]) {
						commit(visible[highlighted]);
					}
					break;
				case 'Escape':
					if (!menu.hidden) {
						event.preventDefault();
						close();
						sync();
					}
					break;
				case 'Tab':
					close();
					sync();
					break;
			}
		});
		input.addEventListener('blur', () => {
			clearTimeout(blurTimer);
			blurTimer = window.setTimeout(() => {
				// Focus already came back (a quick click out and in again) -
				// a stale timer must not close the freshly reopened list.
				if (document.activeElement === input) {
					return;
				}
				close();
				// A typed-but-unpicked value isn't a state - show the real one.
				sync();
			}, 100);
		});

		document.addEventListener('pointerdown', (event) => {
			if (!menu.hidden && !combo.contains(event.target)) {
				close();
				sync();
			}
		});

		// Lets the AJAX code below re-sync the label after Back/Forward.
		select.addEventListener('ryv:sync', sync);
		sync();
	}

	/* ------------------------------------------------------------------ */
	/* AJAX results                                                        */
	/* ------------------------------------------------------------------ */

	let controller = null;
	let lastUrl = location.href;

	function buildUrl(form) {
		const url = withoutPageNumbers(new URL(location.href));
		const data = new FormData(form);

		FILTER_PARAMS.forEach((param) => {
			const value = String(data.get(param) || '').trim();
			if (value) {
				url.searchParams.set(param, value);
			} else {
				url.searchParams.delete(param);
			}
		});

		return url;
	}

	function clearUrl() {
		const url = new URL(location.href);
		FILTER_PARAMS.forEach((param) => url.searchParams.delete(param));
		url.hash = '';
		return url;
	}

	function runElementorHandlers(element) {
		const handler = window.elementorFrontend?.elementsHandler;
		if (!handler) {
			return;
		}
		[element, ...element.querySelectorAll('.elementor-element')].forEach((el) => {
			handler.runReadyTrigger(el);
		});
	}

	/** Bring every search widget on the page in line with the URL. */
	function syncForms(url) {
		document.querySelectorAll('.ryv-resources-search-widget').forEach((widget) => {
			const form = widget.querySelector('.ryv-resources-search');
			const search = form?.querySelector('.ryv-resources-search__input');
			const select = form?.querySelector('.ryv-resources-search__select');
			const reset = widget.querySelector('.ryv-resources-search__reset');
			const q = url.searchParams.get('ryv_search') || '';
			const state = url.searchParams.get('ryv_state') || '';

			if (search && document.activeElement !== search) {
				search.value = q;
			}
			if (select) {
				select.value = state;
				select.dispatchEvent(new Event('ryv:sync'));
			}
			if (reset) {
				reset.hidden = !q && !state;
				const anchor = form?.dataset.anchor ? `#${form.dataset.anchor}` : '';
				reset.href = clearUrl().toString() + anchor;
			}
		});

		document.querySelectorAll('.ryv-resources-sort__select').forEach((sort) => {
			sort.value = url.searchParams.get('ryv_sort') || '';
		});
	}

	/**
	 * "N resources found": the number comes from the hidden `.ryv-rs-found`
	 * marker RYV_Resources_Search appends to the Loop Grid's own markup, so
	 * it's right after every kind of grid refresh - ours, a category tab
	 * click, the first page load.
	 */
	function updateCounts() {
		const marker = document.querySelector('.ryv-rs-found[data-found]');

		document.querySelectorAll('.ryv-resources-count').forEach((el) => {
			if (!marker) {
				el.textContent = '';
				return;
			}
			const num = (key) => parseInt(marker.dataset[key], 10) || 0;
			const total = num('found');
			const perPage = num('perPage') || total;
			const start = Math.min(total, (Math.max(1, num('page')) - 1) * perPage + 1);
			const end = Math.min(total, start + perPage - 1);
			const search = marker.dataset.search || '';

			let template = 0 === total ? el.dataset.none : 1 === total ? el.dataset.one : el.dataset.many;
			if (search && el.dataset.search) {
				template = (template || '') + el.dataset.search;
			}

			const values = {
				start: start.toLocaleString(),
				end: end.toLocaleString(),
				total: total.toLocaleString(),
				state: marker.dataset.state || el.dataset.all || '',
				search,
			};
			el.textContent = (template || '').replace(/\{(start|end|total|state|search)\}/g, (match, key) => values[key]);
		});
	}

	async function refresh(url, { push = true } = {}) {
		if (url.toString() === lastUrl) {
			return;
		}
		lastUrl = url.toString();

		if (push) {
			history.pushState({ ryvResourcesSearch: true }, '', url);
		}
		syncForms(url);

		const targets = [...document.querySelectorAll(RESULTS_SELECTOR)];
		targets.forEach((el) => el.classList.add('ryv-rs-loading'));
		document.querySelectorAll('.ryv-resources-search').forEach((form) => form.setAttribute('aria-busy', 'true'));

		controller?.abort();
		controller = new AbortController();

		try {
			const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal });
			if (!response.ok) {
				throw new Error(`HTTP ${response.status}`);
			}
			const doc = new DOMParser().parseFromString(await response.text(), 'text/html');

			targets.forEach((el) => {
				const fresh = el.dataset.id && doc.querySelector(`.elementor-element-${el.dataset.id}`);
				if (!fresh) {
					return;
				}
				const imported = document.importNode(fresh, true);
				el.replaceWith(imported);
				runElementorHandlers(imported);
			});
			updateCounts();
		} catch (error) {
			if ('AbortError' !== error.name) {
				// Anything unexpected: fall back to a normal page load.
				location.assign(url);
			}
			return;
		} finally {
			document.querySelectorAll('.ryv-rs-loading').forEach((el) => el.classList.remove('ryv-rs-loading'));
			document.querySelectorAll('.ryv-resources-search').forEach((form) => form.removeAttribute('aria-busy'));
		}
	}

	function scrollToResults(form) {
		const target = form.dataset.anchor && document.getElementById(form.dataset.anchor);
		if (!target) {
			return;
		}
		const top = target.getBoundingClientRect().top;
		if (top < 0 || top > window.innerHeight * 0.5) {
			target.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	}

	/* ------------------------------------------------------------------ */
	/* Wiring (delegated, so widgets Elementor re-renders keep working)    */
	/* ------------------------------------------------------------------ */

	const liveTimers = new WeakMap();

	// RYV State Picker: nothing picked yet -> open the list instead of
	// submitting (which would just land on the home page).
	document.addEventListener('submit', (event) => {
		const picker = event.target.closest?.('.ryv-state-picker');
		if (!picker || picker.querySelector('.ryv-resources-search__select')?.value) {
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		(picker.querySelector('.ryv-resources-search__combo-input') || picker.querySelector('.ryv-resources-search__select'))?.focus();
	});

	document.addEventListener('submit', (event) => {
		const form = event.target.closest?.('.ryv-resources-search');
		if (!form) {
			return;
		}
		if (isEditor()) {
			event.preventDefault();
			return;
		}
		if (!form.hasAttribute('data-ajax')) {
			return;
		}
		event.preventDefault();
		clearTimeout(liveTimers.get(form));
		refresh(buildUrl(form));
		scrollToResults(form);
	});

	document.addEventListener('change', (event) => {
		const sort = event.target.closest?.('.ryv-resources-sort__select');
		if (sort) {
			if (isEditor()) {
				return;
			}
			const url = withoutPageNumbers(new URL(location.href));
			if (sort.value) {
				url.searchParams.set('ryv_sort', sort.value);
			} else {
				url.searchParams.delete('ryv_sort');
			}
			if (ajaxEnabled()) {
				refresh(url);
			} else {
				location.assign(url);
			}
			return;
		}

		const select = event.target.closest?.('.ryv-resources-search__select');
		const form = select?.closest('form[data-auto-submit]');
		if (!form || isEditor()) {
			return;
		}
		if (form.hasAttribute('data-ajax')) {
			refresh(buildUrl(form));
		} else if (form.requestSubmit) {
			form.requestSubmit();
		} else {
			form.submit();
		}
	});

	document.addEventListener('input', (event) => {
		const input = event.target.closest?.('.ryv-resources-search__input');
		const form = input?.closest('form[data-live]');
		if (!form || isEditor()) {
			return;
		}
		const value = input.value.trim();
		// One letter matches nearly everything - wait for a second one.
		if (1 === value.length) {
			return;
		}
		clearTimeout(liveTimers.get(form));
		liveTimers.set(form, setTimeout(() => refresh(buildUrl(form)), LIVE_DELAY));
	});

	document.addEventListener('click', (event) => {
		const reset = event.target.closest?.('.ryv-resources-search__reset');
		const form = reset?.closest('.ryv-resources-search-widget')?.querySelector('form[data-ajax]');
		if (!form || isEditor()) {
			return;
		}
		event.preventDefault();
		const search = form.querySelector('.ryv-resources-search__input');
		if (search) {
			search.value = '';
		}
		refresh(clearUrl());
	});

	// The sort widget's own no-JS form submit (Enter on the select).
	document.addEventListener('submit', (event) => {
		if (event.target.closest?.('.ryv-resources-sort') && ajaxEnabled()) {
			event.preventDefault();
		}
	});

	window.addEventListener('popstate', () => {
		if (ajaxEnabled()) {
			refresh(new URL(location.href), { push: false });
		}
	});

	const init = (root = document) => {
		root.querySelectorAll('.ryv-resources-search__select[data-searchable]').forEach(enhanceSelect);
		updateCounts();
	};

	// Elementor Pro's own category-tab refresh swaps the grid in without
	// telling anyone - watch for a fresh count marker arriving with it.
	new MutationObserver((mutations) => {
		const arrived = mutations.some((mutation) =>
			[...mutation.addedNodes].some((node) =>
				1 === node.nodeType && (node.matches('.ryv-rs-found') || node.querySelector('.ryv-rs-found'))
			)
		);
		if (arrived) {
			updateCounts();
		}
	}).observe(document.documentElement, { childList: true, subtree: true });

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', () => init());
	} else {
		init();
	}

	// Elementor editor re-renders the widget on every control change.
	window.addEventListener('elementor/frontend/init', () => {
		window.elementorFrontend?.hooks?.addAction('frontend/element_ready/ryv_resources_search.default', ($scope) => {
			init($scope[0]);
		});
	});
})();
