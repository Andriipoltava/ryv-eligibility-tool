<?php
/**
 * View: Help admin page. Plain-language documentation, no dynamic data -
 * static markup only, one big <div class="wrap">.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap ryv-help-page">
	<h1><?php esc_html_e( 'RYV Eligibility Tool - Help', 'ryv-eligibility-tool' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'A guide to creating and managing eligibility questionnaires with this plugin - no code required for day-to-day use.', 'ryv-eligibility-tool' ); ?>
	</p>

	<nav class="ryv-help-toc">
		<strong><?php esc_html_e( 'On this page:', 'ryv-eligibility-tool' ); ?></strong>
		<ul>
			<li><a href="#ryv-help-overview"><?php esc_html_e( 'Overview', 'ryv-eligibility-tool' ); ?></a></li>
			<li><a href="#ryv-help-new-form"><?php esc_html_e( 'Creating a New Form', 'ryv-eligibility-tool' ); ?></a></li>
			<li><a href="#ryv-help-builder"><?php esc_html_e( 'The Form Builder', 'ryv-eligibility-tool' ); ?></a></li>
			<li><a href="#ryv-help-states-nodes"><?php esc_html_e( 'Editing a State\'s Questions from Its Own Page', 'ryv-eligibility-tool' ); ?></a></li>
			<li><a href="#ryv-help-import"><?php esc_html_e( 'Importing a Drupal YAML Export', 'ryv-eligibility-tool' ); ?></a></li>
			<li><a href="#ryv-help-embed"><?php esc_html_e( 'Adding a Form to a Page', 'ryv-eligibility-tool' ); ?></a></li>
			<li><a href="#ryv-help-styles"><?php esc_html_e( 'Styling a Form', 'ryv-eligibility-tool' ); ?></a></li>
			<li><a href="#ryv-help-lead-capture"><?php esc_html_e( 'Collecting Contact Info (Form Submission Nodes)', 'ryv-eligibility-tool' ); ?></a></li>
			<li><a href="#ryv-help-activity"><?php esc_html_e( 'Activity Log & Submissions', 'ryv-eligibility-tool' ); ?></a></li>
			<li><a href="#ryv-help-tips"><?php esc_html_e( 'Tips & Troubleshooting', 'ryv-eligibility-tool' ); ?></a></li>
		</ul>
	</nav>

	<section id="ryv-help-overview" class="card ryv-help-card">
		<h2><?php esc_html_e( 'Overview', 'ryv-eligibility-tool' ); ?></h2>
		<p>
			<?php esc_html_e( 'This plugin builds decision-tree questionnaires - a visitor answers one question, and their answer decides which question they see next. Each question is a "node"; every form is one connected graph of nodes, from a starting question (the "entry node") through however many follow-up questions it takes to reach a result.', 'ryv-eligibility-tool' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'A form is its own post (post type: RYV Forms, in the left-hand menu). Each form has its own set of nodes, its own entry node, and gets embedded wherever you want via an Elementor widget or a shortcode - see "Adding a Form to a Page" below.', 'ryv-eligibility-tool' ); ?>
		</p>
	</section>

	<section id="ryv-help-new-form" class="card ryv-help-card">
		<h2><?php esc_html_e( 'Creating a New Form', 'ryv-eligibility-tool' ); ?></h2>
		<ol>
			<li><?php esc_html_e( 'Go to RYV Forms → Add New Form.', 'ryv-eligibility-tool' ); ?></li>
			<li><?php esc_html_e( 'Give it a title (shown as the form\'s heading on the frontend, if "Show Title" is enabled on the widget) and, optionally, a description.', 'ryv-eligibility-tool' ); ?></li>
			<li><?php esc_html_e( 'Build out the questions in the Form Builder (see below) - or use Import YAML if you\'re migrating an existing Drupal Webform export instead of starting from scratch.', 'ryv-eligibility-tool' ); ?></li>
			<li><?php esc_html_e( 'Set the status dropdown (top right) to Published once it\'s ready for real visitors. A Draft form is only visible to logged-in users who can edit it - handy for previewing a form on the live page before it goes public.', 'ryv-eligibility-tool' ); ?></li>
		</ol>
	</section>

	<section id="ryv-help-builder" class="card ryv-help-card">
		<h2><?php esc_html_e( 'The Form Builder', 'ryv-eligibility-tool' ); ?></h2>
		<p>
			<?php esc_html_e( 'Opening a form (from RYV Forms → All Forms, or "Add New Form") always opens this builder, not the normal WordPress editor. It has two panels: a filterable list of every node on the left, and a detail editor for whichever node is selected on the right.', 'ryv-eligibility-tool' ); ?>
		</p>

		<h3><?php esc_html_e( 'Node types', 'ryv-eligibility-tool' ); ?></h3>
		<ul>
			<li><strong><?php esc_html_e( 'Radio (single choice)', 'ryv-eligibility-tool' ); ?></strong> - <?php esc_html_e( 'pick one option; each option can route to a different next question.', 'ryv-eligibility-tool' ); ?></li>
			<li><strong><?php esc_html_e( 'Checkbox (multi choice)', 'ryv-eligibility-tool' ); ?></strong> - <?php esc_html_e( 'pick any number of options; always renders as a button list.', 'ryv-eligibility-tool' ); ?></li>
			<li><strong><?php esc_html_e( 'Text input / Textarea', 'ryv-eligibility-tool' ); ?></strong> - <?php esc_html_e( 'free-text answer; a single "Next question" applies once the visitor types something and moves on.', 'ryv-eligibility-tool' ); ?></li>
			<li><strong><?php esc_html_e( 'Markup (display only)', 'ryv-eligibility-tool' ); ?></strong> - <?php esc_html_e( 'a block of HTML with no question of its own - an explanation, a result message, a disclaimer. Advances automatically to its "Next question" unless it has none, in which case it\'s a terminal result (shows a "Start Over" button instead).', 'ryv-eligibility-tool' ); ?></li>
			<li><strong><?php esc_html_e( 'Form Submission', 'ryv-eligibility-tool' ); ?></strong> - <?php esc_html_e( 'collects contact details via a small form (name, email, etc.) - see "Collecting Contact Info" below.', 'ryv-eligibility-tool' ); ?></li>
			<li><strong><?php esc_html_e( 'Unsupported / needs review', 'ryv-eligibility-tool' ); ?></strong> - <?php esc_html_e( 'only ever appears from a YAML import that hit something it couldn\'t translate automatically - see the Import warnings.', 'ryv-eligibility-tool' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Fields every node shares', 'ryv-eligibility-tool' ); ?></h3>
		<ul>
			<li><strong><?php esc_html_e( 'Node ID', 'ryv-eligibility-tool' ); ?></strong> - <?php esc_html_e( 'the internal name other questions route to. Renaming it automatically rewrites every reference across the whole form, so it\'s always safe to rename - you can never accidentally break a link by doing so.', 'ryv-eligibility-tool' ); ?></li>
			<li><strong><?php esc_html_e( 'Title', 'ryv-eligibility-tool' ); ?></strong> - <?php esc_html_e( 'the question text shown to visitors. Basic HTML tags like <em> and <strong> work.', 'ryv-eligibility-tool' ); ?></li>
			<li><strong><?php esc_html_e( 'Tooltip (Help Text)', 'ryv-eligibility-tool' ); ?></strong> - <?php esc_html_e( 'optional - shows a small "?" icon after the title; hovering (or tabbing to it with a keyboard) reveals this text. Good for clarifying an ambiguous question without cluttering the title itself.', 'ryv-eligibility-tool' ); ?></li>
			<li><strong><?php esc_html_e( 'Description', 'ryv-eligibility-tool' ); ?></strong> - <?php esc_html_e( 'optional helper text shown between the title and the answer options - unlike the tooltip, this is always visible, not just on hover.', 'ryv-eligibility-tool' ); ?></li>
			<li><strong><?php esc_html_e( 'Group', 'ryv-eligibility-tool' ); ?></strong> - <?php esc_html_e( 'purely organizational - controls which collapsible bucket a node is filed under in the left-hand node list. Doesn\'t affect the live form at all. Leave it blank to fall back to a sensible default.', 'ryv-eligibility-tool' ); ?></li>
			<li><strong><?php esc_html_e( 'CSS Class', 'ryv-eligibility-tool' ); ?></strong> - <?php esc_html_e( 'optional, for advanced styling of one specific node\'s container - most forms never need this.', 'ryv-eligibility-tool' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Options & routing', 'ryv-eligibility-tool' ); ?></h3>
		<p>
			<?php esc_html_e( 'Radio and Checkbox nodes have an Options list - each option has a Value (stored internally), a Label (shown to the visitor), and a "Next question" dropdown. That dropdown only ever lists nodes that actually exist in this form, so it\'s impossible to accidentally route to something that\'s been deleted or misspelled. Leaving "Next question" as "— End —" makes that answer a dead end (nothing else renders once it\'s chosen).', 'ryv-eligibility-tool' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'A Radio question can also display as Radio buttons or a Dropdown instead of the default button list, via its "Display as" setting - useful for a long list of choices like a state picker.', 'ryv-eligibility-tool' ); ?>
		</p>

		<h3><?php esc_html_e( 'Reference Content Nodes', 'ryv-eligibility-tool' ); ?></h3>
		<p>
			<?php esc_html_e( 'Sometimes a question needs supporting material shown right alongside its own answer buttons - a reference list, a note, a disclaimer - without making that material its own separate step. Any Radio/Checkbox question has a "Reference Content Nodes" list for exactly this: pick one or more Markup nodes, in whatever order you want them shown, and their content renders below this question\'s own options, in the same card. The order can be changed at any time with the up/down buttons - nothing else needs to change.', 'ryv-eligibility-tool' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Edit the actual content on the Markup node itself, not here - this list only points at it, so the content always stays in sync no matter how many questions reference the same material.', 'ryv-eligibility-tool' ); ?>
		</p>

		<h3><?php esc_html_e( 'Hiding a node', 'ryv-eligibility-tool' ); ?></h3>
		<p>
			<?php esc_html_e( 'Markup and Unsupported nodes have a "Hidden" toggle. Turning it on skips rendering that node\'s card entirely on the frontend, while still passing straight through to its own "Next question" - useful for a node that should exist in the graph (something else may still point to it) but shouldn\'t actually be shown to visitors right now.', 'ryv-eligibility-tool' ); ?>
		</p>

		<h3><?php esc_html_e( 'The entry node', 'ryv-eligibility-tool' ); ?></h3>
		<p>
			<?php esc_html_e( '"Entry Node (First Question)", near the top of the builder, picks which node a visitor sees first when the form loads. Every other node just needs to be reachable from here (directly or through a chain of "Next question" links) to ever be seen.', 'ryv-eligibility-tool' ); ?>
		</p>

		<h3><?php esc_html_e( 'Saving', 'ryv-eligibility-tool' ); ?></h3>
		<p>
			<?php esc_html_e( 'The Save button writes the entire form in one go. There\'s no autosave and no per-node save - make your changes, then click Save when you\'re happy with them. Every save is recorded in the Activity Log with who made it and a summary of what changed.', 'ryv-eligibility-tool' ); ?>
		</p>
	</section>

	<section id="ryv-help-states-nodes" class="card ryv-help-card">
		<h2><?php esc_html_e( 'Editing a State\'s Questions from Its Own Page', 'ryv-eligibility-tool' ); ?></h2>
		<p>
			<?php esc_html_e( 'Every RYV States page (RYV States → All States) has its own "Form Nodes (Eligibility Questions)" section, near the bottom of the editor, below the state\'s own content fields. It\'s a second way to edit a subset of a form\'s questions - the ones belonging to that one state - without opening the full Form Builder and hunting for them in a list of hundreds. There\'s no separate copy of the data behind it: it reads from and writes straight back to that state\'s connected form (picked in the "Form Connection" box), the exact same schema the Form Builder edits.', 'ryv-eligibility-tool' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'The question list fills in automatically once both "Eligibility Form" and "State" are set, and refreshes again any time you change either one - there\'s no separate "Load" button. Click a question\'s row to expand it and edit its fields (Title, Content, Step Name, routing, and so on - the same fields the Form Builder shows for that node type); click it again to collapse it back down.', 'ryv-eligibility-tool' ); ?>
		</p>
		<p>
			<?php esc_html_e( '"Step Name" is worth calling out specifically: it\'s what labels this question\'s entry in the step sidebar for forms using the wizard (one-question-at-a-time) layout. Leaving it blank keeps this question folded into whichever named step came before it, rather than starting a new one - so most questions in a state\'s flow are deliberately left blank, with only the first question of each real step naming it.', 'ryv-eligibility-tool' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Renaming an existing question\'s Node ID here works the same as in the Form Builder - but because this screen only shows one state\'s slice of the form, use the "Save Node ID" button right under the field to apply a rename immediately, rather than waiting for the state\'s own Update/Save. That also makes the freshly renamed ID immediately pickable from any "Next question" dropdown on this page without a reload.', 'ryv-eligibility-tool' ); ?>
		</p>
		<p>
			<?php esc_html_e( '"Add Node" creates a brand-new question and files it under this state automatically - no need to set a Group by hand. Removing a question\'s row and saving deletes it from the form entirely, the same as deleting it in the Form Builder - so double-check nothing else still routes to it first (the Form Builder\'s node list is the easiest place to check "is this ID referenced anywhere else").', 'ryv-eligibility-tool' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Saving this screen (the state\'s own Update button) writes every change straight into the connected form\'s schema, exactly like a Form Builder save - it shows up in that form\'s own Activity Log the same way.', 'ryv-eligibility-tool' ); ?>
		</p>
	</section>

	<section id="ryv-help-import" class="card ryv-help-card">
		<h2><?php esc_html_e( 'Importing a Drupal YAML Export', 'ryv-eligibility-tool' ); ?></h2>
		<p>
			<?php esc_html_e( 'RYV Forms → Import YAML accepts a Drupal Webform export file (the kind you\'d get from that Webform\'s own "Export" / config-export screen) and automatically translates its questions and branching logic into a form here.', 'ryv-eligibility-tool' ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Importing into a form that already has content replaces its entire question set', 'ryv-eligibility-tool' ); ?></strong> -
			<?php esc_html_e( 'you\'ll be asked to explicitly confirm this before it happens, since there\'s no undo. Import into a fresh, empty form if you want to keep an existing one untouched.', 'ryv-eligibility-tool' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'After importing, always check the warnings list shown on that page. Most Drupal branching logic translates automatically, but a handful of patterns genuinely can\'t be inferred with full confidence (an unusual combination of conditions, an unrecognized field type, and similar edge cases) - those are imported as-is, flagged in the warnings, and worth a quick manual look in the builder rather than assumed correct.', 'ryv-eligibility-tool' ); ?>
		</p>
	</section>

	<section id="ryv-help-embed" class="card ryv-help-card">
		<h2><?php esc_html_e( 'Adding a Form to a Page', 'ryv-eligibility-tool' ); ?></h2>
		<h3><?php esc_html_e( 'Elementor widget', 'ryv-eligibility-tool' ); ?></h3>
		<p>
			<?php esc_html_e( 'Edit any page with Elementor and drag the "RYV Eligibility Tool" widget onto it. Its Content tab has a "Form" dropdown listing every form you\'ve created (Draft forms are labeled as such) - pick one, and that\'s it. The Content tab also has switches for showing the form\'s own title/description above it, and a Labels section for renaming built-in chrome text ("Start Over", the loading message, etc.).', 'ryv-eligibility-tool' ); ?>
		</p>
		<h3><?php esc_html_e( 'Shortcode', 'ryv-eligibility-tool' ); ?></h3>
		<p>
			<?php
			printf(
				/* translators: %s: shortcode example, wrapped in <code> */
				esc_html__( 'Anywhere shortcodes work (a normal post/page, a classic text widget, ...) you can instead use %s, replacing 123 with the form\'s post ID - visible in the builder\'s URL, or on the All Forms list.', 'ryv-eligibility-tool' ),
				'<code>[ryv_form id="123"]</code>' // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction -- static string literal, not user input.
			);
			?>
		</p>
	</section>

	<section id="ryv-help-styles" class="card ryv-help-card">
		<h2><?php esc_html_e( 'Styling a Form', 'ryv-eligibility-tool' ); ?></h2>
		<p>
			<?php esc_html_e( 'Styling works at two levels, and either is optional - an untouched form already looks complete without visiting either page.', 'ryv-eligibility-tool' ); ?>
		</p>
		<ul>
			<li>
				<strong><?php esc_html_e( 'RYV Forms → Global Styles', 'ryv-eligibility-tool' ); ?></strong> -
				<?php esc_html_e( 'plugin-wide defaults (primary color, text color, background color, border radius, font) applied to every form on the site that doesn\'t override them itself.', 'ryv-eligibility-tool' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'The Elementor widget\'s Style tab', 'ryv-eligibility-tool' ); ?></strong> -
				<?php esc_html_e( 'per-instance overrides for one specific placement of a form - colors, typography, button/dropdown/input appearance, and the help tooltip\'s look, all grouped into labeled sections. Anything left unset here falls back to the Global Styles default, which in turn falls back to the plugin\'s own built-in default.', 'ryv-eligibility-tool' ); ?>
			</li>
		</ul>
	</section>

	<section id="ryv-help-lead-capture" class="card ryv-help-card">
		<h2><?php esc_html_e( 'Collecting Contact Info (Form Submission Nodes)', 'ryv-eligibility-tool' ); ?></h2>
		<p>
			<?php esc_html_e( 'A "Form Submission" node lets a form collect contact details partway through (or at the end of) the questionnaire - e.g. "enter your email to get your results" - without leaving the page. Its editor has two tabs:', 'ryv-eligibility-tool' ); ?>
		</p>
		<ul>
			<li><strong><?php esc_html_e( 'Form Fields', 'ryv-eligibility-tool' ); ?></strong> - <?php esc_html_e( 'which fields to ask for (name, email, phone, a custom field, ...) and a success message shown once submitted.', 'ryv-eligibility-tool' ); ?></li>
			<li><strong><?php esc_html_e( 'Email Settings', 'ryv-eligibility-tool' ); ?></strong> - <?php esc_html_e( 'whether to save each submission to the database (visible later under Submissions), and whether to send a notification email - to an admin address, to the visitor themselves, or both.', 'ryv-eligibility-tool' ); ?></li>
		</ul>
	</section>

	<section id="ryv-help-activity" class="card ryv-help-card">
		<h2><?php esc_html_e( 'Activity Log & Submissions', 'ryv-eligibility-tool' ); ?></h2>
		<ul>
			<li>
				<strong><?php esc_html_e( 'RYV Forms → Activity Log', 'ryv-eligibility-tool' ); ?></strong> -
				<?php esc_html_e( 'two tables: every visitor\'s in-progress answer trail (anonymous - no name, email, or IP is ever recorded here, even if they never finish), and every time someone saved changes in the builder, with who and a summary of what changed.', 'ryv-eligibility-tool' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'RYV Forms → Submissions', 'ryv-eligibility-tool' ); ?></strong> -
				<?php esc_html_e( 'every Form Submission node\'s collected leads (only the ones with "save to database" turned on), across every form, with a CSV export.', 'ryv-eligibility-tool' ); ?>
			</li>
		</ul>
	</section>

	<section id="ryv-help-tips" class="card ryv-help-card">
		<h2><?php esc_html_e( 'Tips & Troubleshooting', 'ryv-eligibility-tool' ); ?></h2>
		<ul>
			<li><?php esc_html_e( 'A form shows nothing on the frontend until its status is Published (or you\'re logged in as someone who can edit it, for previewing a Draft).', 'ryv-eligibility-tool' ); ?></li>
			<li><?php esc_html_e( 'If a page still shows an old version of a form right after editing it, try a hard refresh - browsers can cache the form\'s data for a little while.', 'ryv-eligibility-tool' ); ?></li>
			<li><?php esc_html_e( 'Use the node list\'s search box (top of the left panel) to jump straight to a node by its ID or title once a form has more than a handful of questions - most real forms end up with far too many to browse by scrolling.', 'ryv-eligibility-tool' ); ?></li>
			<li><?php esc_html_e( 'Renaming a node ID is always safe - every reference to it updates automatically, everywhere in the form.', 'ryv-eligibility-tool' ); ?></li>
			<li><?php esc_html_e( 'Deleting a node is not automatically safe - anything still routing to it will point at a question that no longer exists. Check the Activity Log\'s diff after a save if you\'re ever unsure what a change actually affected.', 'ryv-eligibility-tool' ); ?></li>
		</ul>
	</section>
</div>
<style>
	.ryv-help-page .ryv-help-toc {
		background: #fff;
		border: 1px solid #dcdcde;
		border-left: 4px solid #2271b1;
		padding: 12px 16px;
		margin: 16px 0;
		max-width: 800px;
	}
	.ryv-help-page .ryv-help-toc ul {
		margin: 8px 0 0;
		columns: 2;
	}
	.ryv-help-page .ryv-help-card {
		max-width: 800px;
		margin-top: 20px;
		padding: 1px 20px 20px;
	}
	.ryv-help-page .ryv-help-card h3 {
		margin-bottom: 0.4em;
	}
	.ryv-help-page .ryv-help-card ul,
	.ryv-help-page .ryv-help-card ol {
		list-style: initial;
		margin-left: 1.5em;
	}
	.ryv-help-page .ryv-help-card li {
		margin-bottom: 0.4em;
	}
</style>
