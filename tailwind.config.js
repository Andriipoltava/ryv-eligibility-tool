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
  content: [
    './includes/**/*.php',
    './admin/**/*.php',
    './admin/views/**/*.php',
    './public/**/*.php',
    './public/js/src/**/*.js',
    './assets/js/**/*.js',
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
