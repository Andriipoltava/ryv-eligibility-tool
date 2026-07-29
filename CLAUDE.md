# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A custom WordPress plugin ("Restore Your Vote" eligibility tool): a decision-tree
questionnaire that determines US voting eligibility by state and felony-conviction
history. It replaces a legacy Drupal Webform implementation. Rendering is a
vanilla-JS frontend mounted by a custom Elementor widget; all logic for a form
lives as one flat JSON graph in postmeta, not in nested field repeaters.

Hard runtime dependency: **Elementor** must be active. The whole plugin no-ops
(with an admin notice) if it isn't - see `includes/class-ryv-plugin.php`.

## Commands

Run from the plugin root (`wp-content/plugins/ryv-eligibility-tool/`):

```bash
composer install          # Symfony Yaml (used by the YAML importer/parser)
npm install                # Tailwind CLI
npm run build              # compile assets/src/input.css -> assets/css/app.css (minified, one-shot)
npm run watch               # same, watching for changes during CSS work
php -l path/to/file.php    # syntax-check a PHP file - there is no PHPCS/PHPUnit config in this repo yet
```

There is no automated test suite or linter configured. Verify PHP changes with
`php -l`; verify JS changes by loading the module in a browser (there's no
bundler - `assets/js/decision-engine.js` and `assets/js/src/*.js` are native
ES modules, imported by relative path exactly as shipped).

`vendor/` (Composer) is committed - WordPress plugins don't get a build step
at deploy time. `node_modules/` is gitignored - only the *compiled*
`assets/css/app.css` needs to ship, and it's committed.

## Architecture

### Data model: one JSON graph per form, keyed by state

A `ryv_forms` CPT post (see `includes/class-ryv-cpt.php`) holds its entire
decision tree in a single postmeta value, `_ryv_form_schema`:

```json
{
  "states": {
    "alabama": {
      "state_id": "alabama",
      "label": "Alabama",
      "entry_node": "al_felony_conviction",
      "nodes": {
        "al_felony_conviction": {
          "type": "radio",
          "title": "Have you been convicted of a felony?",
          "content": "",
          "options": [
            { "value": "Yes", "label": "Yes", "next_node": "al_mt_list" },
            { "value": "No",  "label": "No",  "next_node": "al_votes" }
          ]
        }
      }
    }
  }
}
```

One `ryv_forms` post can hold many states (this is why the REST API is
`/form/{id}/state/{state_id}`, not just `/form/{id}`). Node types:
`radio`, `checkbox`, `text`, `textarea`, `markup`, `unsupported` (an unmapped
Drupal element type, imported rather than dropped, flagged for manual fixup).
Choice types (`radio`/`checkbox`) route via `options[].next_node`; the rest
route via a node-level `next_node`. `content` (not `title`) holds the body of
`markup` nodes - it's trusted HTML, written only by `manage_options` users
(YAML import or, eventually, the form builder), rendered via `innerHTML` on
the same trust basis as WP post content.

### YAML importer/parser (`includes/class-ryv-yaml-parser.php`)

Real RYV exports (`assets/yaml/webform.webform.voting_rights*.yml` are actual
production Drupal Webform files, not fixtures) bundle **every state into one
file**: a single state-select element followed by ~50 sibling `container`
elements, each gated by `#states.visible` (or `visible-slide`, treated
identically) referencing that selector with a specific value. `parse()`
auto-detects this shape - `find_state_selector()` finds the first
select/radios element with `#options`, then every gated sibling container
becomes one state's subtree via `build_subtree()`. State id/label are derived
from the *selector's own* `#options` (label text), not guessed from the
condition value, because real data mixes full names and abbreviations
(`DC`, `NH`, `Alabama`, ...).

Drupal webforms are implicitly linear (elements render in document order);
branching is reconstructed by `build_subtree()`: each option defaults to
"next element in document order," overridden when a downstream sibling is
gated on that specific option value. `find_default_next()` is the key trick -
when computing an option's *default* target, it skips over any sibling
that's exclusively reached via one of this node's own gates, so the
non-matching options still rejoin the flow correctly instead of dead-ending.

Also note the double-YAML-encoding: Drupal's config export stores the
element tree as a YAML string *inside* the outer YAML (`elements: |...`), not
as nested YAML - `resolve_elements_root()` unwraps that.

Anything the parser can't confidently translate (compound `#states`
conditions, elements outside any detected state container, unmapped
`#type`s) is never silently dropped - it's imported as-is and reported in
`warnings`/`unassigned`, surfaced to the admin in `admin/views/import-page.php`.

If a file has no auto-detectable state-selector/container shape (e.g. a
hand-trimmed single-state file), pass a `$fallback_state_id` override -
`admin/class-ryv-import-page.php`'s "State Override" field does this.
Re-importing a file only touches the states present in it; others already
stored on the target post are preserved (`merge_states_into_post()`).

### REST + frontend: lazy per-state loading, no server-rendered form

`includes/class-ryv-api.php` exposes two read-only routes:
- `GET /ryv/v1/form/{post_id}` - which states exist on this form (populates the initial picker)
- `GET /ryv/v1/form/{post_id}/state/{state_id}` - that state's full node graph, fetched only after a state is chosen

Draft forms are visible only to users who can `edit_post` that form (lets
editors preview via the widget before publishing).

The Elementor widget (`widgets/class-ryv-widget.php`) renders nothing but an
empty mount point: `<div class="ryv-app-wrapper" data-form-id="123">`.
`assets/js/decision-engine.js` finds every such wrapper and boots a
`RYVDecisionEngine` (`assets/js/src/engine.js`) on it.

**UX model - read this before changing engine.js**: this is *not* a
step-wizard with Back/Continue. The whole questionnaire renders as one
growing vertical list of cards (state picker, then every question reached),
and every card stays live and clickable indefinitely. Changing any answer -
including re-picking the state - calls `onAnswerChange()`, which truncates
every rendered card after that one and recomputes forward
(`renderFrom()`/`computeNext()`) from the new answer. There is deliberately
no separate "confirm"/"continue" step: radio advances on click, checkbox
recomputes on every toggle, markup auto-advances with no click at all
(showing "Start Over" only when it's a true terminal, i.e. no `next_node`),
and text/textarea commit on blur or Enter.

Watch out for the blur-on-removal trap in `renderInputCard`
(`assets/js/src/renderer.js`): committing an answer rebuilds/removes that
card's `<input>`, and removing a *focused* element fires a native `blur` on
it - which would otherwise re-fire the commit handler a second time with the
same value. The `lastCommitted` guard exists specifically for this; don't
remove it without re-solving that race.

### Tailwind scoping

`tailwind.config.js` uses `prefix: 'ryv-'` and `important: '.ryv-app-wrapper'`,
and disables `corePlugins.preflight`. This is deliberate and load-bearing:
the widget can land inside an arbitrary theme's page, so it must not reset
global elements (`h1`, `button`, ...) or lose CSS specificity fights against
the theme. Colors (`ryv-primary`/`ryv-secondary`/`ryv-text`) resolve through
CSS custom properties (`--ryv-primary-color` etc.) so the Elementor widget's
Style tab can set them per-instance via `selectors` - see
`register_color_controls()` in `widgets/class-ryv-widget.php`.

`includes/class-ryv-assets.php` registers (but does not enqueue) the
compiled CSS/JS on `wp_enqueue_scripts` at **priority 5**, deliberately
before Elementor's own dependency-enqueuing on the same hook - the widget
declares them via `get_script_depends()`/`get_style_depends()`, which only
works if registration already happened.

### Admin builder SPA (`admin/src/`)

React (via `@wordpress/scripts`) app that replaces the default post editor
for `ryv_forms`. `admin/class-ryv-builder-page.php` filters
`get_edit_post_link` and hooks `load-post.php`/`load-post-new.php` so every
entry point (list table, admin bar, direct URL, "Add New") lands on the
builder instead of Gutenberg/classic. It's a list/detail editor, not a
drag-and-drop graph canvas - a node list per state plus a detail panel where
each option's/node's `next_node` is a `<select>` populated from the current
state's actual node ids (so it's impossible to route to a nonexistent node
from the UI). Renaming a node id (`App.js`'s `renameNode()`) rewrites every
reference to it - `entry_node` and any option/node `next_node` - across the
whole state, not just the node being renamed.

The builder round-trips the **entire** schema on save (`includes/class-ryv-admin-api.php`,
`GET`/`POST /ryv/v1/admin/form/{post_id}`) rather than merging partial
updates server-side - it loads everything into memory once, edits happen
client-side, "Save" PUTs the whole thing back. Auth-gated by
`current_user_can('edit_post', $post_id)`, distinct from the public,
read-only `RYV_API`. New-post creation reuses core's already-REST-enabled
`ryv_forms` endpoint (`POST /wp/v2/ryv_forms`) rather than adding a bespoke
one - only the schema blob is genuinely custom.

Build it with `npm run admin:build` (or `admin:start` to watch). Output goes
to `admin/build/` and **is committed**, same as `assets/css/app.css` - no
build step at WP plugin deploy time. Note the CSS output is named
`style-index.css` (`@wordpress/scripts`'s frontend/editor-style-splitting
convention), not `index.css` - `class-ryv-builder-page.php`'s `enqueue()`
depends on that exact name.

## Status

Implemented: CPT + postmeta schema, YAML importer/parser, REST API, frontend
decision engine, Elementor widget (content + style controls), admin builder
SPA. All phases from the original plan are built. Elementor widget rendering
and the builder's REST-backed save/load have been verified by code review
and manual testing in a dev harness, but not yet inside a live Elementor
editor session (Elementor was downloaded locally but left deliberately
inactive - see git history around the Phase 4/5 boundary).
