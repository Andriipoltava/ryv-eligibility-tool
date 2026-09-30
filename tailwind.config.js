/** @type {import('tailwindcss').Config} */
module.exports = {
  // Scoped so RYV utilities can't collide with (or be overridden by) the
  // active theme's own CSS when this widget is dropped into an arbitrary page.
  prefix: 'ryv-',
  important: '.ryv-app-wrapper',
  corePlugins: {
    // Preflight resets global elements (h1, button, etc.) - unacceptable
    // inside a widget embedded in someone else's page/theme.
    preflight: false,
  },
  // Helper classes applied only from Elementor (Advanced > CSS Classes),
  // so they never appear in a scanned file - see input.css.
  safelist: ['ryv-search-panel', 'ryv-pagination-boxes', 'ryv-toc', 'ryv-post-tags--outline', 'ryv-article-body'],
  content: [
    './includes/**/*.php',
    './admin/**/*.php',
    './admin/views/**/*.php',
    './public/**/*.php',
    './public/js/src/**/*.js',
    './assets/js/**/*.js',
    // `widgets/class-ryv-widget.php` was missing here entirely - every
    // *other* Style-tab class has survived Tailwind's content-based
    // purging only because it's also referenced in a scanned JS file
    // (renderer.js builds class names as strings), never because PHP was
    // actually covered. The header title/description classes are the
    // first ones rendered purely server-side with no JS-side reference at
    // all, which is what surfaced this gap - see .ryv-header-title/
    // .ryv-header-description in input.css.
    './widgets/**/*.php',
  ],
  theme: {
    extend: {
      colors: {
        // Bridged to Elementor style controls (Phase 4) via CSS variables
        // set inline on the .ryv-app-wrapper element.
        'ryv-primary': 'var(--ryv-primary-color, #1d4ed8)',
        'ryv-secondary': 'var(--ryv-secondary-color, #64748b)',
        'ryv-text': 'var(--ryv-text-color, #1f2937)',
      },
    },
  },
  plugins: [],
};
