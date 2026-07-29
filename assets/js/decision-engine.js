import { initAll } from './src/engine.js';

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => initAll());
} else {
  initAll();
}

// Elementor's editor injects widgets after DOMContentLoaded (AJAX preview
// re-renders) - re-scan whenever that happens so widgets added later still
// get initialized, without double-initializing ones that already are
// (initAll() guards on data-ryv-initialized).
if (window.elementorFrontend && window.elementorFrontend.hooks) {
  window.elementorFrontend.hooks.addAction('frontend/element_ready/global', () => initAll());
}
