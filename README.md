# RYV Eligibility Tool

A custom WordPress plugin for [Restore Your Vote](https://restoreyourvote.org): a decision-tree
questionnaire that determines US voting eligibility by state and felony-conviction history. It
replaces a legacy Drupal Webform implementation. Rendering is a vanilla-JS frontend mounted by a
custom Elementor widget (or the `[ryv_form]` shortcode); all logic for a form lives as one flat
JSON graph in postmeta, not in nested field repeaters.

**Hard runtime dependency: [Elementor](https://elementor.com/) must be active.** The plugin
no-ops with an admin notice if it isn't.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- Elementor (any recent version)

## Installation

1. Place this directory in `wp-content/plugins/`.
2. Run `composer install` (pulls in `symfony/yaml`, used by the YAML importer).
3. Activate the plugin from **Plugins** in wp-admin.
4. A new **RYV Forms** menu appears. Import a form (see below), then drag the **RYV Eligibility
   Tool** widget onto any Elementor page, or embed it anywhere with `[ryv_form id="123"]`.

## Features

- **Flat decision-graph builder** — a React admin SPA (under **RYV Forms → [a form]**) replaces
  the default post editor for authoring/editing a form's question graph, with node search/filter
  for large forms (400+ nodes).
- **Drupal Webform YAML importer** — bulk-imports real production Drupal exports
  (`webform.webform.*.yml`), reconstructing branching logic from `#states` visibility gates and
  flagging anything it can't confidently translate.
- **Elementor widget + `[ryv_form id]` shortcode** — two ways to embed a form; both render the
  same frontend decision engine.
- **Global Styles** (**RYV Forms → Global Styles**) — plugin-wide default colors/border-radius/
  font, which every Elementor widget instance falls back to when its own Style controls are left
  blank, while still allowing per-instance overrides.
- **Lead capture** — `lead_capture` nodes can save submissions to the database, email an admin,
  and/or email the visitor, with templated subject/body using submitted field values.
- **Activity & submissions logging** — an anonymous, session-scoped visitor progress log, a
  builder edit log (who changed what, with a structural diff), and a Submissions admin page with
  CSV export.

## Development

Run these from the plugin root:

```bash
composer install       # Symfony Yaml (YAML importer/parser)
npm install             # Tailwind CLI + @wordpress/scripts

npm run build            # compile assets/src/input.css -> assets/css/app.css (minified, one-shot)
npm run watch             # same, watching for changes during CSS work
npm run admin:build       # build the admin builder SPA -> admin/build/
npm run admin:start       # same, watching for changes

php -l path/to/file.php  # syntax-check a PHP file
```

There is no automated test suite or linter configured yet. Verify PHP changes with `php -l`;
verify JS changes by loading the module in a browser — there's no bundler for the frontend engine
(`assets/js/decision-engine.js` and `assets/js/src/*.js` are native ES modules, imported by
relative path exactly as shipped).

`vendor/` (Composer) and the compiled `assets/css/app.css` / `admin/build/` (npm) are committed —
WordPress plugins don't get a build step at deploy time. `node_modules/` is gitignored.

**`RYV_VERSION` in `ryv-eligibility-tool.php` must be bumped on any JS/CSS change.** It's the
cache-busting query string on every enqueued asset — without a bump, browsers that already loaded
the plugin keep serving the old file indefinitely.

## Architecture

See [`CLAUDE.md`](./CLAUDE.md) for a detailed write-up of the data model (flat decision graph per
form), the YAML importer/flattening algorithm, the REST API surface, the frontend decision
engine's UX model, the Elementor widget's CSS-variable styling bridge and Global Styles fallback
chain, and the admin builder SPA.

## Project structure

```
ryv-eligibility-tool.php     Plugin bootstrap (constants, activation hooks)
includes/                    Core: CPT, REST API, YAML importer/parser, schema migration/diff,
                              activity logging, Elementor integration, shortcode
admin/                       Admin-only pages (builder SPA, import, activity log, submissions,
                              global styles) + admin/src (React source) and admin/build (compiled)
widgets/                     The Elementor widget
assets/                      Frontend: assets/src/input.css (Tailwind source) -> assets/css/app.css,
                              assets/js (decision engine), assets/yaml (sample Drupal exports)
```