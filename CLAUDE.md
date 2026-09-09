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
npm run admin:build         # build the admin builder SPA -> admin/build/
npm run admin:start         # same, watching for changes
php -l path/to/file.php    # syntax-check a PHP file - there is no PHPCS/PHPUnit config in this repo yet
```

There is no automated test suite or linter configured. Verify PHP changes with
`php -l`; verify JS changes by loading the module in a browser (there's no
bundler - `assets/js/decision-engine.js` and `assets/js/src/*.js` are native
ES modules, imported by relative path exactly as shipped).

`vendor/` (Composer) is committed - WordPress plugins don't get a build step
at deploy time. `node_modules/` is gitignored - only the *compiled*
`assets/css/app.css` and `admin/build/` need to ship, and both are committed.

**`RYV_VERSION` (`ryv-eligibility-tool.php`) must be bumped on any JS/CSS
change.** It's the cache-busting query string on every enqueued asset - without
a bump, browsers that already loaded the plugin keep serving the old file
indefinitely. This has bitten real testing sessions more than once.

That bump alone isn't sufficient for the frontend engine, though:
`wp_enqueue_script()` only cache-busts `assets/js/decision-engine.js` itself -
every relative `import` it (and `engine.js` in turn) makes is a URL the
browser resolves and caches **on its own**, and Apache serves these static
`.js` files with `Last-Modified` but no `Cache-Control`, so heuristic
freshness can let a browser keep serving an old cached copy of `engine.js`/
`renderer.js`/`api-client.js` indefinitely - surviving even a hard reload in
practice. `decision-engine.js` and `engine.js` version-stamp their own
relative imports with a literal `?ver=X.Y.Z` query string for exactly this
reason (e.g. `from './src/engine.js?ver=0.8.7'`) - **bump every one of these
to match `RYV_VERSION` whenever it bumps**, or an edit to any of those three
files can silently fail to reach a visitor who already loaded the form once.

## Architecture

### Data model: one flat decision graph per form

A `ryv_forms` CPT post (see `includes/class-ryv-cpt.php`) holds its entire
decision tree in a single postmeta value, `_ryv_form_schema`:

```json
{
  "title": "Voting Rights",
  "description": "",
  "entry_node": "select_state",
  "nodes": {
    "select_state": {
      "type": "radio",
      "title": "Select your state",
      "display_style": "buttons",
      "options": [
        { "value": "alabama", "label": "Alabama", "next_node": "al_felony_conviction" }
      ]
    },
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
```

**There is no per-state partition in the schema.** "Select your state" is an
ordinary `radio` node like any other question - its `options[].next_node`
routes straight to each state's original first question, exactly the same
mechanism any other branching question uses. This is a deliberate, explicit
architectural choice (a prior attempt at exactly this flat model was reverted
once earlier in this project's history before being redone properly - see git
log/PR history around that if touching this area). Node types: `radio`,
`checkbox`, `text`, `textarea`, `markup`, `unsupported` (an unmapped Drupal
element type, imported rather than dropped, flagged for manual fixup). Choice
types (`radio`/`checkbox`) route via `options[].next_node`; the rest route via
a node-level `next_node`. `content` (not `title`) holds the body of `markup`
nodes - it's trusted HTML, written only by `manage_options` users (YAML import
or the form builder), rendered via `innerHTML` on the same trust basis as WP
post content.

`RYV_CPT::get_schema( $post_id )` / `RYV_CPT::save_schema( $post_id, $schema )`
are the **one canonical read/write path** for this postmeta - every other
class (REST API, admin API, import page) goes through them rather than
hand-rolling `get_post_meta()`/`json_decode()`/`wp_slash(wp_json_encode())`.
`get_schema()` also transparently migrates a legacy per-state schema
(`{states: {...}}`, from before this flat-DAG rewrite) via
`RYV_Schema_Migrator::migrate()` the first time it's read, persisting the
migrated result immediately so it only ever runs once per post - the migrator
folds every old state's nodes into one map (prefixing ids `{state_id}__{id}`
so cross-state id collisions are structurally impossible) and synthesizes a
`select_state`-style picker node whose options route to each state's original
entry node, sorted alphabetically by label to match the old picker's order.

### YAML importer/parser (`includes/class-ryv-yaml-parser.php`)

Real RYV exports (`assets/yaml/webform.webform.voting_rights*.yml` are actual
production Drupal Webform files, not fixtures) bundle **every state into one
file**: a state-select element followed by ~50 sibling `container` elements,
each gated by `#states.visible` (or `visible-slide`, treated identically)
referencing that selector with a specific value. `parse()` does **not**
special-case the selector at all - the whole document, selector included, is
flattened into one graph by `build_subtree()`/`flatten()` in a single pass.

Drupal webforms are implicitly linear (elements render in document order);
branching is reconstructed by `build_subtree()`: each option defaults to "next
element in document order," overridden when a downstream sibling is gated on
that specific option value. `find_default_next()` is the key trick - when
computing an option's *default* target, it skips over any sibling that's
exclusively reached via a gate, so non-matching options still rejoin the flow
correctly instead of dead-ending or (the specific bug this flattening
introduced and fixes) spilling into a *different* state's content once every
state shares one document.

A per-state **container**'s own `#states.visible` gate (e.g. "this whole block
is only visible when the state selector equals X") can't attach to the
container itself - containers never become nodes - so `flatten()` carries it
down onto the first real leaf that container produces, via a side-channel
accumulator (`$container_gates`, keyed `"source_id|value" => target node id`)
rather than by mutating that leaf's own `#states`. This matters: empirically,
across both real export files, a state's first child very often *already*
carries its own `#states` (a redundant restatement of the same condition, or
sometimes an unrelated/unsupported one) - writing over it would be unsafe, and
a naive "only inject a gate if the leaf has none" check silently misroutes
every state whose first child doesn't restate the condition (confirmed several
real states do exactly this). `build_subtree()` overlays `$container_gates` on
top of the leaf-derived gate map after building it, so container-established
routing always wins for its source/value slot.

**Also load-bearing**: every node additionally carries the id of whichever
top-level source element it descended from (`flatten()`'s `$group`, one per
state's container, inherited by everything nested inside it however deep).
`find_default_next()` refuses to return a default target from a *different*
group - without this, a node whose own branches are exhausted (the true end of
one state's content) would otherwise keep walking forward in document order
and land on the *next* state's gated entry point instead of terminating (e.g.
a "you can vote!" result silently auto-advancing into an unrelated state's
first question). This reproduces, via an explicit group tag, exactly the
isolation the old per-state `build_subtree()` call used to get for free by
only ever being invoked on one state's own elements.

Also note the double-YAML-encoding: Drupal's config export stores the element
tree as a YAML string *inside* the outer YAML (`elements: |...`), not as
nested YAML - `resolve_elements_root()` unwraps that. The root YAML's own
`title:`/`description:` keys (siblings of `elements:`) are read directly by
`parse()` and applied to the target post's `post_title`/`post_excerpt` on
import (see `RYV_Import_Page::apply_title_and_description()`).

Anything the parser can't confidently translate (compound `#states`
conditions, unmapped `#type`s, optgroups) is never silently dropped - it's
imported as-is and reported in `warnings`, surfaced to the admin in
`admin/views/import-page.php`.

**Two independent nodes can legitimately gate on the exact same
`source=value`** - `#states` is a "become visible" flag, not an exclusive
router, so both could be visible at once in the original Drupal form (real
example: Alabama's `al_moral_turpitude_felonies`, a Yes/No/Maybe/FOS
question, and `al_mt_list`, its reference felony list, both gate on
`al_felony_conviction=Yes` and render as one step on the live site - the
question with its own buttons, the list shown right below them as
supporting material, not a separate click-through step). This flat DAG has
no way to show two independent nodes "at once," but a markup node's content
can be *pointed at* from the colliding choice node instead of trying to
route to it as a next step - which also sidesteps a real structural dead
end, since a choice node's options are usually all already individually
routed elsewhere, leaving no free "default" slot to chain a second step into
anyway. `build_subtree()` handles this by appending the losing markup
node's id onto the winning choice node's `reference_node_ids` (an ordered
array, not a single slot - more than one markup node can collide onto the
same question this way, and each is appended in document order) rather than
copying its content: `reference_node_ids` is a list of *pointers*, resolved
at render time by `appendReferenceContent()` in `renderer.js`, so editing
the markup node's own content later (in the builder or a re-import) never
needs a separate sync step. The builder exposes this as a "Reference Content
Nodes" list on choice nodes (`admin/src/components/ReferenceNodesEditor.js`)
with up/down reordering, since render order there is meaningful and
user-controlled, unlike `OptionsEditor.js`'s options which are never
reordered.

A **`visible` rule alongside an `invisible` one on the same element** is a
narrower, unsafe-to-guess case of the same "declarative, not exclusive"
`#states` model: safely inverting `invisible` into "visible unless X" would
require knowing every other possible answer to X's source question, which
this parser doesn't attempt (real examples: `alabama_splc_project`,
`az_on_the_ground`, `fl_am4` - reference blurbs meant to disappear once a
felony question is answered, gated `visible` on the state and `invisible` on
that question's answer values). `extract_visibility_condition()` only ever
reads the `visible` half, so the `invisible` half would otherwise be
silently dropped with no trace at all - unlike the *invisible-only* case
(nothing to route on, `warnings`-only), *both present* additionally sets
`node.hidden = true` on the resulting node: it stays in the graph and
reachable via its `visible` gate, but `engine.js`'s `renderFrom()` skips
giving it a card at all (still passing straight through to its own
`next_node`, so the chain keeps moving) unless an admin flips the builder's
"Hidden" toggle back off after reviewing the original YAML. Hiding by
default is the deliberately conservative choice here: safe over showing
content past the point it should have disappeared.

Since one file now represents the *entire* flat graph, importing into a form
that already has content **replaces its whole schema** rather than merging one
state's worth of nodes - `admin/class-ryv-import-page.php` requires an
explicit confirmation checkbox before allowing that.

### REST + frontend: one fetch, no server-rendered form

`includes/class-ryv-api.php` exposes one read-only route -
`GET /ryv/v1/form/{post_id}` returns the entire `{entry_node, nodes}` graph in
one response, plus the form's `title`/`description` - and two write routes:
`POST /ryv/v1/form/{post_id}/log`, for anonymous progress logging (see below),
and `POST /ryv/v1/form/{post_id}/submit`, for `lead_capture` node submissions
(see "Lead capture" below). There is no more per-state lazy-loading route;
with a single universal DAG there's no natural boundary left to preserve one.

Draft forms are visible only to users who can `edit_post` that form (lets
editors preview via the widget before publishing).

The Elementor widget (`widgets/class-ryv-widget.php`) renders an optional
server-side title/description (`show_title`/`show_description` Content-tab
switches, reading the post's own `post_title`/`post_excerpt` directly - no
REST round trip needed for this part) followed by an empty mount point:
`<div class="ryv-app-wrapper" data-form-id="123">`. `assets/js/decision-engine.js`
finds every such wrapper and boots a `RYVDecisionEngine`
(`assets/js/src/engine.js`) on it, which fetches the whole graph once and
walks it with `renderFrom()`/`computeNext()`.

**UX model - read this before changing engine.js**: this is *not* a
step-wizard with Back/Continue. The whole questionnaire renders as one growing
vertical list of cards (state picker as the first ordinary node, then every
question reached), and every card stays live and clickable indefinitely.
Changing any answer - including re-picking the state - calls
`onAnswerChange()`, which truncates every rendered card after that one and
recomputes forward (`renderFrom()`/`computeNext()`) from the new answer. There
is deliberately no separate "confirm"/"continue" step: radio advances on
click, checkbox recomputes on every toggle, markup auto-advances with no click
at all (showing "Start Over" only when it's a true terminal, i.e. no
`next_node`), and text/textarea commit on blur or Enter. `restart()` just
re-walks the same graph from `entry_node` - no special "back to the picker"
case, since the picker is just the first node.

**A second layout mode now exists alongside the growing-list model above**:
`engine.js`'s `wizardMode` (set from `root.dataset.questionLayout === 'wizard'`,
a per-widget-instance Elementor "Question Layout" control, `stacked` [the
model just described] vs. `wizard` - see `widgets/class-ryv-widget.php`
around `question_layout`) shows only the single most recent card plus a named
step sidebar, rather than every card ever reached. This is layered on top of
the same underlying graph walk, not a separate engine: `computeNext()` still
defers markup nodes with a `next_node` set until their own "Continue" button
is clicked (`advanceWizardMarkup()`) instead of auto-advancing, since
auto-advancing would otherwise skip straight past a step the visitor is
supposed to see one-at-a-time. Don't assume `stacked` behavior (every card
always mounted) holds when touching `renderFrom()`/`computeNext()` - check
`this.wizardMode` first.

Watch out for the blur-on-removal trap in `renderInputCard`
(`assets/js/src/renderer.js`): committing an answer rebuilds/removes that
card's `<input>`, and removing a *focused* element fires a native `blur` on
it - which would otherwise re-fire the commit handler a second time with the
same value. The `lastCommitted` guard exists specifically for this; don't
remove it without re-solving that race.

**Every `.ryv-card` creates its own CSS stacking context** (from the
`transform` its enter transition applies - CSS Transforms treats a
transformed element as if it were positioned, for stacking purposes, even
though the class never sets `position` itself), which is a trap for anything
absolutely-positioned inside one card that needs to visually float above a
*later* card - a `z-index` set on that inner element only wins comparisons
within its own card's stacking context, not against a sibling card's, so a
later card with no explicit `z-index` of its own still paints over it purely
by DOM order. This bit the searchable dropdown's own popup
(`renderSearchableSelect()`'s `.ryv-select2-menu`): its open state now
toggles a `.ryv-card-elevated` class (`z-index: 20`) on the *ancestor card*
itself via `setCardElevated()`, not just the menu - that's what actually
lets it paint above every card rendered after it. Any future
absolutely-positioned popover/menu inside a card needs the same treatment,
not just a bigger `z-index` on the popover itself.

`node.help` (optional, on any node - a YAML import sets it automatically
from Drupal's `#help`) renders as a small "?" icon after the title
(`titleEl()`'s `help` param, `buildHelpTooltip()` in `renderer.js`) that
reveals `help`'s trusted HTML on hover/focus, matching
campaignlegal.org/restoreyourvote's own Tippy.js-based tooltip - not a
click-to-toggle. A short (150ms) close delay on `mouseleave`/`blur`,
canceled if the pointer re-enters either the icon or the tooltip itself, is
what lets `help` content containing a link (real example: "Please email us
if you have any questions.") stay clickable instead of vanishing the instant
the cursor leaves the icon.

**Elementor editor preview**: the widget's DOM doesn't exist yet at the exact
moment `decision-engine.js`'s deferred module script runs inside Elementor's
editor iframe (Elementor renders widget markup on its own schedule), and
`elementorFrontend` itself isn't guaranteed to exist that early either, so a
`frontend/element_ready` hook listener can miss it too. `decision-engine.js`
instead uses a `MutationObserver`, scoped to the editor only via the native
`window.frameElement.id === 'elementor-preview-iframe'` check (no dependency
on any Elementor JS being loaded yet) - real frontend visitors never pay for
the observer.

### Visitor + admin activity logging

Two custom tables (`includes/class-ryv-db.php`, created/upgraded via
`RYV_DB::maybe_upgrade()` on every request - cheap no-op once current, same
pattern as the schema migration above):
- `wp_ryv_submissions` (`includes/class-ryv-submission-log.php`) - one row per
  `(form_id, session_id)`, upserted by the frontend as a visitor answers
  (`POST /ryv/v1/form/{id}/log`), so a browse-away mid-questionnaire still
  leaves a partial trail. Deliberately anonymous - no IP or other identifying
  field exists in the schema.
- `wp_ryv_edit_log` (`includes/class-ryv-edit-log.php`) - one row per builder
  Save, who/when plus a structural diff (`includes/class-ryv-schema-diff.php`'s
  `RYV_Schema_Diff::compute()` - nodes added/removed/changed, not a
  line-level diff of nested arrays like `options`).

Both are surfaced, paginated, on the "Activity Log" admin page
(`admin/class-ryv-logs-page.php` / `admin/views/logs-page.php`).

### Tailwind scoping

`tailwind.config.js` uses `prefix: 'ryv-'` and `important: '.ryv-app-wrapper'`,
and disables `corePlugins.preflight`. This is deliberate and load-bearing: the
widget can land inside an arbitrary theme's page, so it must not reset global
elements (`h1`, `button`, ...) or lose CSS specificity fights against the
theme. Colors (`ryv-primary`/`ryv-secondary`/`ryv-text`) resolve through CSS
custom properties (`--ryv-primary-color` etc.) so the Elementor widget's Style
tab can set them per-instance via `selectors` - see `register_color_controls()`
in `widgets/class-ryv-widget.php`. **`content` must include every PHP file
that can introduce a new class name, including `widgets/**/*.php`** - it was
missing for a while and every Style-tab class added during that time only
survived Tailwind's purge because it *also* happened to be referenced in a
scanned JS file (`renderer.js` builds class names as strings); a class used
purely server-side (like the header title/description below) gets silently
stripped from the compiled CSS if its PHP file isn't in this list.

Every `var(--x, <default>)` fallback in `input.css` (colors, borders,
padding, the select2 dropdown's arrow/menu, the "?" help tooltip, the
`text-restrict-height`/`message--path-end` verbatim-class treatments below)
is deliberately set to match campaignlegal.org/restoreyourvote - the real,
live production form this plugin targets - measured directly via
`getComputedStyle()` on that site, not eyeballed from a screenshot. Treat it
as the reference design for any future default-styling change: an untouched
widget instance should look like that site without an admin ever opening the
Style tab.

Not every Style control is CSS-var-bridged the same way: most non-typography
properties write a custom property onto `{{WRAPPER}} .ryv-app-wrapper` (read
in `input.css` with a `var(--x, <default>)` fallback), but the header
title/description controls (`register_header_style_controls()`) write theirs
onto `{{WRAPPER}}` alone, since that markup renders as `.ryv-app-wrapper`'s
*preceding sibling*, not a descendant - a custom property set on
`.ryv-app-wrapper` would be invisible to it (custom properties only cascade to
descendants).

**This exact mistake recurred, inverted, in `register_step_sidebar_style_controls()`**:
`step_sidebar_active_color`/`step_sidebar_gap`/`step_sidebar_header_gap`/
`step_sidebar_marker_size`/`step_sidebar_marker_color` all wrote their custom
properties onto `{{WRAPPER}} .ryv-app-wrapper`, but `.ryv-step-list`/
`.ryv-step-list-marker` (the wizard step-sidebar's own markup) render inside
`.ryv-layout-sidebar`, a *sibling* of `.ryv-app-wrapper` under
`.ryv-layout--sidebar` (see `render()`) - not a descendant of it either way.
Every one of those five controls silently no-op'd as a result (confirmed live:
"Gap Between Steps" and "Step Number Size" visibly did nothing), until fixed
to target plain `{{WRAPPER}}` - an ancestor of both siblings, same as the
header fix above. If a future Style control needs to reach markup outside
`.ryv-app-wrapper`'s own subtree (anything under `.ryv-layout-sidebar`, or the
header block), write it onto bare `{{WRAPPER}}`, never
`{{WRAPPER}} .ryv-app-wrapper` - and verify with a live `getComputedStyle()`
check in the browser, not just "the Elementor control saved without an error,"
since a wrong-target custom property fails completely silently.

**Spacing above the Continue button and above a question's choice buttons**
is controllable too now (`continue_button_margin_top` in "Continue Button",
`button_margin_top` in "Choice Buttons" - both correctly target
`{{WRAPPER}} .ryv-app-wrapper`, since `.ryv-continue-btn`/`.ryv-options-list`
*are* inside `.ryv-app-wrapper`, unlike the step-sidebar case above). Adding
`continue_button_margin_top` meant unifying what was previously an
inconsistent default: `renderer.js` hardcoded a Tailwind `ryv-mt-3` utility
class on only two of its many `.ryv-continue-btn` render sites (the
error-box retry button, the unsupported-node restart button) and left every
other one (a question's own Continue, a checkbox list's deferred Continue)
with no top margin at all. Both hardcoded instances were removed in favor of
one CSS default (`margin-top: var(--ryv-continue-btn-margin-top, 0.75rem)`
in `.ryv-continue-btn.ryv-continue-btn`, alongside its existing `!important`
declarations) - every Continue button now gets the same 0.75rem by default,
adjustable from one place, instead of an admin discovering the control only
affects some of them.

Same "Top Spacing" idea extends to the Dropdown (`dropdown_margin_top` -
`--ryv-select-margin-top`, shared by both `.ryv-select` and the select2
variant `.ryv-select2`, same as every other `--ryv-select-*` var) and Radio
Buttons (`radio_margin_top` - `--ryv-radio-margin-top`) sections. Radio's is
the trickier of the two: `renderSingleChoiceOptions()` in `renderer.js`
appends each `.ryv-radio-option` label straight to the card with no
enclosing group wrapper, so a plain margin-top on the class would add space
between *every* option, not just above the first - the CSS rule targets
`.ryv-radio-option:first-of-type` specifically to avoid that.

The Step Sidebar's "Step Number" controls gained **Font Size**
(`step_sidebar_marker_font_size` - `--ryv-step-sidebar-marker-font-size`,
replacing a hardcoded `ryv-text-xs`) and **Border Width**
(`step_sidebar_marker_border_width` - `--ryv-step-sidebar-marker-border-width`)
alongside the pre-existing Size/Number Color - `.ryv-step-list-marker`'s
`border: 1px solid ...` shorthand was split into separate
`border-style`/`border-width`/`border-color` declarations so width could be
driven by its own var independently of color.

`includes/class-ryv-assets.php` registers (but does not enqueue) the compiled
CSS/JS on `wp_enqueue_scripts` at **priority 5**, deliberately before
Elementor's own dependency-enqueuing on the same hook - the widget declares
them via `get_script_depends()`/`get_style_depends()`, which only works if
registration already happened.

### Admin builder SPA (`admin/src/`)

React (via `@wordpress/scripts`) app that replaces the default post editor for
`ryv_forms`. `admin/class-ryv-builder-page.php` filters `get_edit_post_link`
and hooks `load-post.php`/`load-post-new.php` so every entry point (list
table, admin bar, direct URL, "Add New") lands on the builder instead of
Gutenberg/classic - **but only for the default `edit` action**; Trash/Restore/
Delete Permanently are also `post.php?action=...` requests through the same
hook, and redirecting those too (as this once did) silently breaks deletion
entirely, since WordPress's own handler for them never runs.

It's a flat list/detail editor, not a drag-and-drop graph canvas - a single
node list across the *whole* form (no more per-state grouping, since there's
no more structural state partition) plus a detail panel where each option's/
node's `next_node` is a `<select>` populated from the current graph's actual
node ids (so it's impossible to route to a nonexistent node from the UI). A
real multi-branch form can flatten to 400+ nodes with no grouping left to
browse by, so `NodeList.js` has a client-side id/title filter - necessary, not
optional, at that scale. Renaming a node id (`App.js`'s `renameNode()`)
rewrites every reference to it - `entry_node` and any option/node `next_node` -
across the whole graph, not just the node being renamed.

The builder round-trips the **entire** schema on save (`includes/class-ryv-admin-api.php`,
`GET`/`POST /ryv/v1/admin/form/{post_id}`) rather than merging partial updates
server-side - it loads everything into memory once, edits happen client-side,
"Save" PUTs the whole thing back. Auth-gated by
`current_user_can('manage_options')` - not `edit_post`/`edit_posts` - since
`nodes[].content` is raw HTML rendered via `innerHTML` on the public frontend,
the same trust boundary as the YAML importer; this is enforced in both
`RYV_Admin_API::check_permission()` and the `_ryv_form_schema` postmeta's own
`auth_callback` (`RYV_CPT::register_meta()`), since that meta is also
independently writable through core's `/wp/v2/ryv_forms/{id}` endpoint.
Distinct from the public, read-only
`RYV_API`. New-post creation reuses core's already-REST-enabled `ryv_forms`
endpoint (`POST /wp/v2/ryv_forms`) rather than adding a bespoke one; status
changes (draft/publish) similarly ride on core's `PATCH /wp/v2/ryv_forms/{id}`
- the builder fully replaces the normal post editor, so without this there
would be no way to ever publish a form.

Build it with `npm run admin:build` (or `admin:start` to watch). Output goes
to `admin/build/` and **is committed**, same as `assets/css/app.css` - no
build step at WP plugin deploy time. Note the CSS output is named
`style-index.css` (`@wordpress/scripts`'s frontend/editor-style-splitting
convention), not `index.css` - `class-ryv-builder-page.php`'s `enqueue()`
depends on that exact name.

`NodeEditor.js` exposes the graph-level concepts above as plain form
controls, not just `content`/`options`: `ReferenceNodesEditor.js` (a choice
node's ordered "Reference Content Nodes" list, described under the YAML
importer above) and a "Hidden" `ToggleControl` (Markup/Unsupported nodes
only - hiding a choice/text/lead_capture node would strand a visitor with no
way to ever answer it, so the control simply doesn't apply there).

### Lead capture (`lead_capture` node type)

A choice/text/markup-adjacent node type whose schema carries `form_fields`
(admin-defined `{ label, name, type, required }[]`) and `email_settings`
(`save_to_db`, and independently-toggleable admin-notification /
visitor-confirmation emails with `{field_name}`/`{all_fields}` template
placeholders). Submitted via `POST /ryv/v1/form/{post_id}/submit`
(`RYV_API::submit_lead()`), which reads the node's own `form_fields`/
`email_settings` out of the stored schema rather than trusting them from the
request - only `node_id`/`form_data`/`session_id` come from the client, and
`form_data` is sanitized key-by-key against the schema's declared fields
(unrecognized keys silently dropped, each value sanitized per its declared
`type` - `email`/`textarea`/plain text - before storage or template
interpolation). Rows land in `wp_ryv_leads` (`includes/class-ryv-leads.php`,
via `RYV_DB`) only when `email_settings.save_to_db` is on, and are surfaced,
paginated, on **RYV Forms → Submissions** (`admin/class-ryv-submissions-page.php`
/ `admin/views/submissions-page.php`) alongside the anonymous progress-log
submissions.

### States, Resources, and the interactive map

Two more CPTs sit alongside `ryv_forms`, both registered in
`RYV_Plugin::includes()`/`init_hooks()` unconditionally (not gated on
`is_admin()`, since `ryv_states` posts are public-facing state pages):

- **`ryv_states`** (`includes/class-ryv-states-cpt.php`) - one post per US
  state. When ACF Pro is active, `includes/class-ryv-states-acf.php` adds two
  field groups: state-page content (matching the public site's design), and a
  form-node editor (form + state selector + a node repeater). The repeater is
  **not** the source of truth - `includes/class-ryv-states-sync.php`
  bidirectionally syncs it against the actual `_ryv_form_schema` postmeta on
  `acf/save_post`, resolving "which nodes belong to this state" by walking the
  graph depth-first from the state picker's option for that state (id-prefix
  matching was rejected as unsafe - see the file's own docblock - since
  migrated vs. YAML-imported schemas use different node-id conventions).
  `includes/class-ryv-states-generator.php` bulk-creates one `ryv_states` post
  per state-picker option (keyed uniquely by `sanitize_title()` of the
  option's untranslated `value`, safe to re-run against a translated form
  edition without duplicating) and can auto-populate each state's map
  category from a hardcoded NCSL reference table
  (`CLC_CATEGORY_STATES`) - run from **RYV Forms → Generate States**
  (`admin/class-ryv-states-generate-page.php`, ACF-gated).
- **`ryv_resources`** (`includes/class-ryv-resources-cpt.php`) - standalone
  guides/forms/videos/toolkits; a state links to its resources via an ACF
  relationship field rather than embedding them as repeater rows.
- **MapSVG sync** (`includes/class-ryv-mapsvg-sync.php`, ACF-gated) pushes
  each state's ACF category/color/tooltip/link onto the MapSVG plugin's own
  tables on `acf/save_post`. MapSVG 6.x's region table has no per-region
  color column at all (confirmed via `DESCRIBE`, not assumed) - a region's
  `status` is just an index into the *map's own* `options` JSON
  `regionStatuses` array, so writing a state's color is a two-part
  read-modify-write: ensure the map's `regionStatuses` has an entry for that
  category, then point the region's `status`/`status_text` at it.
  `widgets/class-ryv-map-widget.php` is the Elementor widget embedding that
  MapSVG map, with a fuller Style-tab surface than MapSVG's own bundled
  widget plus a styled "Or pick from a list" state-dropdown fallback.

### Non-Elementor embed: `[ryv_form]` shortcode

`includes/class-ryv-shortcode.php` renders the exact same
`.ryv-app-wrapper[data-form-id]` mount point as the Elementor widget, so a
form can be embedded in any post/page content without Elementor - same
draft-visibility rule as `RYV_API::get_viewable_form()`. It enqueues the same
registered `RYV_Assets` handles the widget depends on (never its own copies),
so a page using both the widget and the shortcode still only loads one copy
of the CSS/JS. `admin/class-ryv-shortcode-hint.php` adds a read-only meta box
to the post/page editor listing every published form and its shortcode, so an
author doesn't need to already know a form's post ID.

### Drupal YAML exporter (`includes/class-ryv-drupal-yaml-exporter.php`)

The inverse of `RYV_YAML_Parser`: reconstructs `#states`-gated Drupal
containers/elements from a flat RYV graph so the result both re-imports
cleanly through the unmodified parser and passes as a plausible Drupal
Webform config export. Explicitly a best-effort reversal scoped to forms that
were originally YAML-imported and only lightly edited since - it depends on
two fields the importer writes and nothing else touches, `node.group`
(originating Drupal container) and `node.source_type` (original Drupal
`#type`), neither guaranteed present/accurate on a node authored fresh in the
builder. A non-choice node's outgoing edge can only be encoded by document
position (immediately following it in the same reconstructed container) since
Drupal has no gate mechanism for it, so `validate_positional_edges()` checks
that positional assumption still holds for the schema being exported and
warns (never silently drops) when it doesn't. Reachable from **RYV Forms →
Export** (`admin/class-ryv-export-page.php`).

### Graph validator (`includes/class-ryv-graph-validator.php`)

A structural health check - `RYV_Graph_Validator::analyze()` takes any
`{ entry_node, nodes }` schema and reports broken `next_node`/option links
(naming an id absent from `nodes`) and orphaned nodes (unreachable from
`entry_node` by any path). Independent of any one import path, so it runs the
same way against freshly parsed YAML, a stored schema, or a builder save.

### Global Styles (`admin/class-ryv-settings-page.php`)

A `manage_options` Settings API page (**RYV Forms → Global Styles**, single
`ryv_global_styles` option) providing plugin-wide default colors/typography
for every form, layered *underneath* any Elementor Style-tab override on a
given widget instance. Unlike every other `admin/*-page.php` controller, it's
instantiated unconditionally rather than inside `RYV_Plugin`'s `is_admin()`
block, because it also feeds the frontend CSS-variable cascade
(`build_css()`/`output_style_vars()`) that the widget and `[ryv_form]`
shortcode both render through - its `admin_menu`/`admin_init` hooks are just
no-ops on a frontend request.

### WPML translation support

`wpml-config.xml` pre-registers `ryv_forms` and its `_ryv_form_schema`
postmeta so WPML's translation editor can offer them; widget Content-tab
labels are marked translatable by default
(`register_string_translation()`/ICL string translation) rather than left as
plain hardcoded control defaults.

**Linking two independently-created language editions**
(`admin/class-ryv-translations-page.php`, **RYV Forms → Translations**,
only registered when `has_action('wpml_set_element_language_details')` etc.
detect WPML's API) exists because the normal WPML ways of doing this don't
reach this CPT: `RYV_Builder_Page` fully replaces the standard post edit
screen, so WPML's classic "Languages" meta box (the usual place to point an
existing post at another as its translation) never renders for a
`ryv_forms` post; the list table's own "+"/pencil icon in the Languages
column instead routes through WPML's Translation Management job queue,
which commissions a *new* translation rather than linking two posts that
were each already created independently (e.g. one YAML-imported per
language edition - exactly how the English/Spanish editions of a form
typically come to exist). This page lets an admin pick "Form A is the
translation of Form B" from two `<select>`s and performs the link entirely
through WPML's own public API (`wpml_element_trid`,
`wpml_post_language_details`, `wpml_set_element_language_details`) - it
never touches `wp_icl_translations` directly, unlike a manual SQL fix.

That same page also neutralizes WPML's own translate-link icon
(`a.js-wpml-translate-link`) on the `ryv_forms` list table
(`disable_list_table_translate_link()`, hooked on `admin_footer-edit.php`,
scoped to that one screen): an admin clicking it - expecting the usual
"jump to the linked post" behavior it has for ordinary posts/pages - can
otherwise silently queue a duplicate-in-progress Translation Management job
instead (the exact failure mode this page exists to avoid). The fix strips
the link's `href` (so neither click nor keyboard Enter can reach WPML's
queue) and rewrites its click handler to land on this Translations page
instead - a JS fix, not `pointer-events` CSS alone, since that wouldn't
stop keyboard activation.

### Step Names (`admin/class-ryv-step-names-page.php`)

Bulk-assigns the wizard step-sidebar's `step_name` field (see "A second
layout mode" above, `NodeEditor.js`'s "Step Name" field, and `engine.js`'s
`updateStepSidebar()`) across an entire form's graph in one pass, for the
3-step shape every RYV questionnaire actually has: "pick your state",
"answer about your conviction" (a different node per state, but the same
conceptual step), "see your result" (also a different node per branch) -
rather than requiring an admin to open a form's 400+ nodes one at a time in
the builder to set the same label repeatedly. Reachable from **RYV States
→ Step Names** (deliberately nested under the States CPT's menu, not
Forms', even though it reads/writes `ryv_forms` schemas - this is normally
set up right after a state's own content is finished).

Given one "anchor" form, `apply_step_names()` resets every node's
`step_name` to empty, then sets exactly three: the entry node (step 1),
every node the entry node's options route to - resolved past any `hidden`
nodes in between, since a hidden node never gets a card and so can't
usefully anchor a sidebar entry (step 2), and every true-terminal node (no
outgoing `next_node`/`options[].next_node` at all) not already claimed by
step 1/2 (step 3). Every other node is left with an empty `step_name` on
purpose - `updateStepSidebar()` only starts a new sidebar entry when a
node's `step_name` differs from the currently-active one, so leaving it
empty just keeps the visitor folded into whichever named step they're
already in.

When WPML is active, picking one form auto-discovers every linked
translation via `wpml_get_element_translations` (`RYV_Translations_Page` is
the other half of that link) and renders one row of Step 1/2/3 fields per
language, all under a single submit - each language's own graph gets the
rule applied independently with its own typed wording (never
machine-translated; each edition keeps its own node ids, so this is always
re-run per-edition, not copied across languages). Since a form and its
translation are still two separately-linked forms rather than one, an
"Also apply to this form's linked translations" checkbox (checked by
default, only shown when a group has more than one member) lets an admin
restrict a submit to just the one form picked above - e.g. to re-word or
fix a single language's step names without touching its sibling editions.

### Current Assignments (map categories, `admin/class-ryv-states-generate-page.php`)

Sits directly below the "Auto-Populate Map Categories" button described
above, on the same **RYV States → Generate States** page, and exists for
the opposite need: that button only ever *bulk-resets everything* to the
CLC reference defaults, with no way to see the result or hand-adjust one
state afterward without opening its own edit screen. This section instead
lists every `ryv_states` post with a `<select>` of every live map category
(sourced from `RYV_States_ACF::get_map_categories()` - the same options-page
repeater the `ryv_map_category` ACF field itself reads its choices from, so
this table can never drift from what an admin would see on a state's own
edit screen) pre-selected to that state's current value, all under one
"Save Category Assignments" submit
(`RYV_States_Generate_Page::handle_move_submission()`). Only rows whose
selection actually changed get `update_field()`'d - comparing against each
state's current value first, rather than blindly writing every row on every
submit - and if anything changed, `RYV_MapSVG_Sync::sync_all()` runs once
afterward (not per-row) to push the new colors to the map, the same manual
trigger the "Sync Category Colors to Map" button above uses, since
`update_field()` alone doesn't fire the `acf/save_post` hook that class
normally syncs on.

### Help admin page (`admin/class-ryv-help-page.php`)

A static, read-only documentation page (`admin/views/help-page.php`) under
**RYV Forms → Help**, last in the submenu - plain-language usage
instructions (creating a form, the builder's fields, YAML import, embedding,
styling, lead capture, activity log/submissions) aimed at the
`manage_options` admins who actually run this plugin day to day, not at a
future developer reading this file. No dynamic data, no state, no
capability beyond `manage_options` for consistency with every other
`admin/*-page.php` controller - keep it that way; if it ever needs to render
something dynamic it's no longer just documentation.

## Status

Implemented: CPT + postmeta schema (flat DAG), YAML importer/parser + its
Drupal YAML exporter inverse, graph validator, REST API (form fetch,
progress logging, lead-capture submission), frontend decision engine with
both stacked and one-question-at-a-time wizard/step-sidebar layouts,
Elementor widget (content + style controls, including title/description) and
`[ryv_form]` shortcode embed, admin builder SPA, lead capture (form fields +
email notifications + Submissions page), visitor/admin activity logging,
`ryv_states`/`ryv_resources` CPTs with ACF field groups, bidirectional
states⟷schema node sync, states bulk-generator, MapSVG map sync + Elementor
map widget, Global Styles admin page, WPML translation support plus a
Translations admin page for manually linking independently-created language
editions, a Step Names admin page for bulk-assigning the wizard sidebar's
3-step structure across a form (and its linked translations) in one pass,
a Current Assignments table for viewing/hand-adjusting every state's map
category in one place, Help admin page. All phases from the original plan are built, plus the later flat-DAG
rewrite (states-keyed → single universal graph) and its lazy migration path
for existing forms, a pass reconciling the whole frontend's default look 1:1
against campaignlegal.org/restoreyourvote (colors/typography/dropdown/
buttons/help-tooltip), the `reference_node_ids`/`hidden` gate-collision
handling, and the later states/resources/map/lead-capture/wizard-layout
build-out described above. The old custom `ryv_events` CPT
(`includes/class-ryv-events-cpt.php`) has been removed, superseded by The
Events Calendar's `tribe_events`. Verified against both real production YAML
exports and live browser testing (Elementor editor included, not just code
review).
