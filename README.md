# Dynamic Eligibility & Questionnaire Flow Tool

A flexible, high-performance WordPress plugin for building dynamic decision-tree questionnaires,
eligibility tests, and multi-step forms.

Featuring a modern React-based admin SPA, dynamic lead capture with customizable form fields and
email notifications, a clean REST API, CSV exports, and global design settings. Rendering is
handled seamlessly via a vanilla-JS frontend engine embedded through a custom Elementor widget or
a standard WordPress shortcode.

**Hard runtime dependency:** [Elementor](https://elementor.com/) must be active. The plugin
gracefully no-ops with an admin notice if it isn't.

Plugin slug: `ryv-eligibility-tool`. Built for [Restore Your Vote](https://restoreyourvote.org)
to determine US voting eligibility by state and felony-conviction history, replacing a legacy
Drupal Webform implementation — but the decision-tree engine itself is domain-agnostic; nothing
in the schema format assumes voting eligibility specifically.

---

## Author

* **Developer:** Andrii Omelianenko

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- Elementor (any recent version)

---

## Installation

1. Place the plugin directory into your `wp-content/plugins/` folder.
2. Run `composer install` in the plugin root directory to pull in required dependencies (such as
   `symfony/yaml` for the YAML importer).
3. Activate the plugin from the **Plugins** menu in your WordPress admin dashboard.
4. A new **RYV Forms** menu will appear in the sidebar.

---

## Usage

### 1. Creating & Editing Forms
* Go to **RYV Forms** in your admin panel and click **Add New**.
* Use the visual SPA builder to structure your decision tree (add questions, markup blocks,
  choices, or lead capture forms). It's a flat node list with search/filter, not a drag-and-drop
  canvas — built to stay usable on forms with 400+ nodes.
* You can also bulk-import a real Drupal Webform YAML export instead of building from scratch (see
  **RYV Forms → Import**); branching is reconstructed automatically from its `#states` visibility
  gates, and anything the importer can't confidently translate is flagged for manual review rather
  than silently dropped.
* Configure global design defaults under **RYV Forms → Global Styles**.

### 2. Displaying Forms on the Frontend
You can embed your questionnaire anywhere on your site using two methods:

* **Elementor Widget:** Search for the **RYV Eligibility Tool** widget in the Elementor editor and
  drag it onto any page. Select your form from the dropdown.
* **Shortcode:** Paste the shortcode anywhere on your site (Gutenberg, classic editor, or page
  builders):
  ```text
  [ryv_form id="123"]
  ```

Both methods render the exact same frontend decision engine and mount point, so styling and
behavior are identical regardless of which one you use.

### 3. Customizing Design
* **Global Styles** (**RYV Forms → Global Styles**): set plugin-wide default colors, border
  radius, and font. Every Elementor widget instance falls back to these when its own Style tab
  controls are left blank.
* **Per-instance overrides:** any Style tab control you *do* set on a specific widget instance
  always wins over Global Styles for that one embed — nothing you configure globally locks out a
  one-off design change.

### 4. Lead Capture & Notifications
Add a `lead_capture` node anywhere in a form's flow to collect visitor input mid-questionnaire:
* Define custom form fields (text, email, textarea) per node.
* Optionally save submissions to the database, email an admin, and/or email the visitor — each
  with a templated subject/body that can reference the submitted field values.

### 5. Activity Log & Submissions
* **RYV Forms → Activity Log:** who changed what and when, with a structural diff of each save.
* **RYV Forms → Submissions:** every recorded lead capture, with CSV export.

---

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