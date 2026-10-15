// The `?ver=` query strings below are NOT decorative - wp_enqueue_script()
// only cache-busts THIS file (the one registered with RYV_VERSION); every
// relative import a module makes is a URL the browser resolves and caches
// on its own, and Apache serves these static .js files with Last-Modified
// but no Cache-Control, so heuristic freshness lets a browser keep serving
// an old cached copy indefinitely - surviving even a hard reload in
// practice. Bump every one of these to match RYV_VERSION whenever it bumps
// (same rule as ryv-eligibility-tool.php's own version comment), or edits
// to engine.js/renderer.js/api-client.js can silently fail to reach anyone
// who already loaded the form once.
import { initAll } from './src/engine.js?ver=0.9.24';

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => initAll());
} else {
  initAll();
}

// Verified by direct DOM inspection inside Elementor's editor preview
// iframe: `.ryv-app-wrapper` genuinely does not exist yet at the point this
// deferred module script runs its initial pass - Elementor renders widget
// markup into the iframe on its own schedule. A `frontend/element_ready`
// hook listener looked like the fix, but missed it too: `elementorFrontend`
// itself isn't guaranteed to exist yet when this deferred module runs (a
// race this script can lose), so the listener sometimes never even gets
// registered in time. A MutationObserver watching for the wrapper itself
// sidesteps both races - it needs no Elementor API to exist yet, just the
// native `window.frameElement` check (available before any script runs) to
// scope it to Elementor's own preview iframe, so real visitors on the
// published page - where the wrapper is always static server-rendered HTML
// already present at parse time - never pay for an observer they'll never
// need.
const inElementorPreview = window.frameElement && window.frameElement.id === 'elementor-preview-iframe';

if (inElementorPreview) {
  new MutationObserver(() => initAll()).observe(document.documentElement, { childList: true, subtree: true });
}
