/**
 * Admin JS for the States CPT edit screen.
 *
 * Auto-syncs the "Nodes" ACF repeater from the selected Form + State - no
 * "Load Nodes" button: as soon as both fields resolve to a value, the
 * matching nodes are fetched via AJAX and dropped into the repeater, and
 * switching either field re-syncs automatically. Also restyles the repeater
 * into a collapsed list / click-to-expand-detail layout that mirrors the
 * form builder's own NodeList/NodeEditor look (see admin/src/style.css and
 * admin/src/components/NodeList.js - the class names below are shared with
 * that stylesheet on purpose, not just visually similar) so editing a
 * state's questions here feels like the same tool, not a bolted-on ACF form.
 */
(function ($) {
	'use strict';

	var $status   = null;
	var $filter   = null;
	var $toolbar  = null;
	// "formId::stateSlug" for whatever the repeater currently holds, so a
	// field change only re-fetches when the combination actually changed -
	// re-running on every keystroke-driven select2 update (or on page load,
	// when the repeater already holds exactly this state's saved rows) would
	// otherwise flash-reload the list for no reason.
	var loadedSignature = null;
	var loadInFlight     = false;

	$(document).ready(function () {
		var repeater = getRepeaterField();
		if (!repeater) {
			return;
		}

		$toolbar = $('#ryv-load-nodes-toolbar');
		$status  = $('#ryv-load-nodes-status');
		// Built here rather than in PHP - ACF's `message` field strips
		// <input> from its output (wp_kses_post(), see class-ryv-states-acf.php).
		$filter  = $('<input type="search" id="ryv-sa-filter" class="ryv-sa-filter" placeholder="Filter questions by id or title…">');
		$toolbar.prepend($filter);

		enhanceExistingRows(repeater);

		var hasRows = repeater.$el.find('.acf-row:not(.acf-clone)').length > 0;
		var signature = currentSignature();

		if (hasRows) {
			// Trust the rows already on screen (they're what was last saved) -
			// just record their signature so a later field change is detected
			// as a real change rather than re-fetching immediately. They're
			// existing, already-reviewed content (unlike a fresh "Add Node"
			// row), so start collapsed the same way a just-synced row does.
			repeater.$el.find('.acf-row.ryv-sa-row').addClass('ryv-sa-collapsed');
			loadedSignature = signature;
			toggleToolbar(true);
		} else if (signature) {
			loadNodes(signature, false);
		}

		bindFieldWatchers();
		bindFilter();

		// ACF's own hook for "new content was appended to the DOM and needs
		// wiring up" - fires for both our AJAX-populated rows and a manually
		// clicked "Add Node" row, so both paths get the same collapse/expand
		// treatment without listening for two different events.
		acf.addAction('append', function ($el) {
			enhanceExistingRows(getRepeaterField(), $el);
		});
	});

	function getRepeaterField() {
		var field = acf.getField('field_ryv_form_nodes');
		if (field) {
			return field;
		}
		var $repeaterEl = $('[data-key="field_ryv_form_nodes"]');
		return $repeaterEl.length ? acf.getField($repeaterEl) : null;
	}

	function currentSignature() {
		var formId    = getFieldValue('ryv_form_connection');
		var stateSlug = getFieldValue('ryv_state_slug');
		if (!formId || !stateSlug) {
			return null;
		}
		return formId + '::' + stateSlug;
	}

	function bindFieldWatchers() {
		['ryv_form_connection', 'ryv_state_slug'].forEach(function (fieldName) {
			var $input = fieldInput(fieldName);
			if (!$input.length) {
				return;
			}
			$input.on('change', function () {
				var signature = currentSignature();

				if (!signature) {
					toggleToolbar(false);
					return;
				}

				if (signature === loadedSignature) {
					return;
				}

				loadNodes(signature, true);
			});
		});
	}

	function bindFilter() {
		if (!$filter || !$filter.length) {
			return;
		}
		$filter.on('input', function () {
			applyFilter($(this).val() || '');
		});
	}

	function applyFilter(query) {
		var repeater = getRepeaterField();
		if (!repeater) {
			return;
		}
		var q = query.trim().toLowerCase();
		repeater.$el.find('.acf-row.ryv-sa-row').each(function () {
			var $row = $(this);
			var haystack = ($row.data('ryv-node-id') + ' ' + $row.data('ryv-node-title')).toLowerCase();
			var matches = !q || haystack.indexOf(q) !== -1;
			$row.toggle(matches);
			// A match found while filtering should be readable immediately,
			// not hidden behind its own collapsed summary too.
			if (matches && q) {
				$row.removeClass('ryv-sa-collapsed');
			}
		});
	}

	/**
	 * Fetch the state's nodes over AJAX and replace the repeater's contents.
	 * No confirm() prompt - unlike the old manual "Load Nodes" button, this
	 * runs automatically in response to a field the admin just changed, so a
	 * blocking dialog here would just be in the way.
	 */
	function loadNodes(signature, isResync) {
		if (loadInFlight) {
			return;
		}

		var parts     = signature.split('::');
		var formId    = parts[0];
		var stateSlug = parts[1];

		loadInFlight = true;
		toggleToolbar(true);
		setStatus(isResync ? 'Syncing…' : 'Loading…', false);

		$.ajax({
			url:    ryvStatesAdmin.ajaxUrl,
			method: 'POST',
			data: {
				action:     'ryv_load_state_nodes',
				nonce:      ryvStatesAdmin.nonce,
				form_id:    formId,
				state_slug: stateSlug,
			},
			success: function (response) {
				loadInFlight = false;

				if (!response.success) {
					setStatus('Error: ' + (response.data || 'Unknown error'), true);
					return;
				}

				populateRepeater(response.data);
				loadedSignature = signature;
				setStatus('Synced ' + response.data.length + ' question' + (response.data.length === 1 ? '' : 's') + '.', false);
			},
			error: function () {
				loadInFlight = false;
				setStatus('Request failed - check your connection and try switching the State field again.', true);
			}
		});
	}

	function setStatus(text, isError) {
		if (!$status) {
			return;
		}
		$status.text(text).toggleClass('ryv-sa-status-error', !!isError);
	}

	function toggleToolbar(show) {
		if ($toolbar) {
			$toolbar.prop('hidden', !show);
		}
	}

	/**
	 * Get an ACF field's underlying jQuery input/select - works across ACF
	 * 5.x/6.x the same way getFieldValue() below does, since both need to
	 * locate the same element (one reads it, the other binds to it).
	 *
	 * A select2-enhanced field (post_object/select, both used here) renders
	 * a hidden accessibility `<input class="hidden">` *before* its real
	 * `<select>` in DOM order - `.find('select, input').first()` would grab
	 * that dummy input instead of the select that actually changes value,
	 * so a real `<select>` is preferred whenever one exists.
	 */
	function fieldInput(fieldName) {
		var $wrap = $('[data-name="' + fieldName + '"]').first();
		var $select = $wrap.find('select').first();
		return $select.length ? $select : $wrap.find('input').first();
	}

	/**
	 * Get an ACF field value by field name.
	 *
	 * Works across ACF 5.x and 6.x - the internal API surface changed
	 * between versions, so we try multiple approaches.
	 */
	function getFieldValue(fieldName) {
		// ACF 6.x: acf.findFields() returns a jQuery collection.
		if (typeof acf.findFields === 'function') {
			var $fields = acf.findFields({ name: fieldName });
			if ($fields && $fields.length) {
				var field = acf.getField($fields.first());
				if (field) {
					return field.val();
				}
			}
		}

		// ACF 5.x: acf.findField() is a different signature.
		if (typeof acf.findField === 'function') {
			try {
				var found = acf.findField(fieldName);
				if (found && found.length) {
					var f = acf.getField(found);
					if (f) {
						return f.val();
					}
				}
			} catch (e) {
				// Swallow - fall through to DOM lookup.
			}
		}

		// Fallback: read directly from the DOM.
		var $el = $('[data-name="' + fieldName + '"]');
		if ($el.length) {
			var $input = $el.find('select, input').first();
			return $input.val();
		}

		return '';
	}

	/**
	 * Clear the existing ACF repeater rows and populate with new node data.
	 */
	function populateRepeater(nodes) {
		var repeater = getRepeaterField();

		if (!repeater) {
			return;
		}

		var $existingRows = repeater.$el.find('.acf-row:not(.acf-clone)');
		if ($existingRows.length) {
			// Remove in reverse order to avoid index shifting.
			$($existingRows.get().reverse()).each(function () {
				repeater.remove($(this));
			});
		}

		// Add new rows.
		nodes.forEach(function (nodeData) {
			var $newRow = repeater.add();

			if (!$newRow || !$newRow.length) {
				return;
			}

			setSubFieldValue($newRow, 'node_id', nodeData.node_id);
			// node_original_id is what resolve_row_node_id() (PHP) uses to
			// tell "editing an existing node" apart from "brand-new row added
			// via Add Node" - node_to_acf_row() always sets it equal to the
			// node's own id. Never populating it here made every AJAX-synced
			// row look brand-new on save; since its typed id already existed,
			// resolve_row_node_id() silently skipped the whole row rather
			// than editing it - no field on a synced row could ever be
			// changed and saved (confirmed live: a typed Step Name never
			// persisted no matter how many times Update was clicked).
			setSubFieldValue($newRow, 'node_original_id', nodeData.node_original_id || nodeData.node_id);
			setSubFieldValue($newRow, 'node_type', nodeData.node_type);
			setSubFieldValue($newRow, 'node_title', nodeData.node_title);
			setSubFieldValue($newRow, 'node_step_name', nodeData.node_step_name);
			setSubFieldValue($newRow, 'node_help', nodeData.node_help);
			setSubFieldValue($newRow, 'node_display_style', nodeData.node_display_style);
			setSubFieldValue($newRow, 'node_hidden', nodeData.node_hidden ? 1 : 0);
			setSubFieldValue($newRow, 'node_next_node', nodeData.node_next_node);
			setSubFieldValue($newRow, 'node_reference_nodes', nodeData.node_reference_nodes);

			// Content requires WYSIWYG handling.
			setWysiwygValue($newRow, 'node_content', nodeData.node_content);

			// Options sub-repeater.
			if (nodeData.node_options && nodeData.node_options.length) {
				populateOptionsRepeater($newRow, nodeData.node_options);
			}

			// enhanceExistingRows() ran already (ACF's 'append' action fires
			// the moment repeater.add() creates the row, before the
			// setSubFieldValue() calls above have anything to summarize) -
			// refresh its summary now that the row actually has data, and
			// collapse it: unlike a blank row from clicking "Add Node",
			// this one just arrived with real synced content, so there's
			// nothing here the admin hasn't already reviewed.
			$newRow.data('ryv-refresh-summary') && $newRow.data('ryv-refresh-summary')();
			$newRow.data('ryv-refresh-type-conditional-fields') && $newRow.data('ryv-refresh-type-conditional-fields')();
			$newRow.addClass('ryv-sa-collapsed');
		});
	}

	/**
	 * Set a sub-field value within a repeater row.
	 */
	function setSubFieldValue($row, fieldName, value) {
		var $cell = $row.find('[data-name="' + fieldName + '"]').first();
		if (!$cell.length) {
			return;
		}

		var field = acf.getField($cell);
		if (field) {
			field.val(value);
			return;
		}

		// Fallback: set DOM input directly.
		var $input = $cell.find('input, select, textarea').first();
		if ($input.length) {
			$input.val(value).trigger('change');
		}
	}

	/**
	 * Show/hide every node_type-dependent field for a row based on its
	 * current type, in plain JS/CSS rather than ACF's own conditional_logic.
	 * None of these fields carry conditional_logic in
	 * class-ryv-states-acf.php any more - ACF only re-evaluates
	 * conditional_logic on a genuine 'change' event fired on the trigger
	 * field, and populateRepeater()'s field.val() calls (used for both the
	 * AJAX-synced rows this screen loads with and a freshly clicked "Add
	 * Node" row) never fire one. Left to ACF's own logic, a row showed
	 * whichever fields matched the *clone template's* default type instead
	 * of the row's actual type until the admin manually touched the Type
	 * dropdown - reported live as "wrong/missing fields on first load, fixed
	 * by switching type". Next Node is additionally select2-enhanced
	 * (`ui => 1`), where ACF's conditional_logic doesn't just hide a failed
	 * field but disables it - a disabled input never submits at all, which
	 * silently dropped real routing on save (see that field's own comment in
	 * class-ryv-states-acf.php). Called right after populateRepeater() sets
	 * a row's fields and again on every manual Type change, so it's never
	 * relying on ACF's own recalculation.
	 */
	function toggleTypeConditionalFields($row) {
		var type = $row.find('[data-name="node_type"] select').first().val() || '';
		var isChoice = type === 'radio' || type === 'checkbox';
		$row.find('[data-name="node_next_node"]').first().toggle(!isChoice);
		$row.find('[data-name="node_reference_nodes"]').first().toggle(isChoice);
		$row.find('[data-name="node_options"]').first().toggle(isChoice);
		$row.find('[data-name="node_display_style"]').first().toggle(type === 'radio');
		$row.find('[data-name="node_hidden"]').first().toggle(type === 'markup' || type === 'unsupported');
	}

	/**
	 * Set a WYSIWYG (TinyMCE / textarea) field value.
	 */
	function setWysiwygValue($row, fieldName, value) {
		var $cell = $row.find('[data-name="' + fieldName + '"]').first();
		if (!$cell.length) {
			return;
		}

		// Try TinyMCE editor.
		var $textarea = $cell.find('textarea').first();
		if ($textarea.length) {
			$textarea.val(value);

			var editorId = $textarea.attr('id');
			if (editorId && typeof tinymce !== 'undefined') {
				var editor = tinymce.get(editorId);
				if (editor) {
					editor.setContent(value || '');
				}
			}
		}
	}

	/**
	 * Populate the options sub-repeater within a node row.
	 */
	function populateOptionsRepeater($parentRow, options) {
		var $optRepeater = $parentRow.find('[data-name="node_options"]').first();
		if (!$optRepeater.length) {
			return;
		}

		var optRepeater = acf.getField($optRepeater);
		if (!optRepeater) {
			return;
		}

		options.forEach(function (optData) {
			var $optRow = optRepeater.add();
			if (!$optRow || !$optRow.length) {
				return;
			}

			setSubFieldValue($optRow, 'option_label', optData.option_label);
			setSubFieldValue($optRow, 'option_value', optData.option_value);
			setSubFieldValue($optRow, 'option_next_node', optData.option_next_node);
		});
	}

	// --- builder-style collapsed list / detail rows -----------------------

	var TYPE_LABELS = {
		radio: 'Radio',
		checkbox: 'Checkbox',
		text: 'Text',
		textarea: 'Textarea',
		markup: 'Markup',
		lead_capture: 'Form Submission',
		unsupported: 'Unsupported',
	};

	/**
	 * Turn each top-level node row into a collapsed summary (id + type badge
	 * + title, click to expand) - the same scanning-first pattern as the
	 * form builder's node list. Only ever touches direct rows of the
	 * top-level `field_ryv_form_nodes` repeater, never the nested per-node
	 * "Options" sub-repeater's own `.acf-row`s (those keep ACF's plain
	 * look - they're small enough not to need it, and a summary bar with no
	 * spare fields to summarize would be pure noise).
	 *
	 * @param {object|null} repeater  acf.getField() result for field_ryv_form_nodes.
	 * @param {jQuery} [$context] Newly-appended content only (acf.addAction('append')) - narrows the scan instead of re-walking every row on every add.
	 */
	function enhanceExistingRows(repeater, $context) {
		if (!repeater) {
			return;
		}

		var $rows = $context
			? $context.find('.acf-row').addBack('.acf-row')
			: repeater.$el.find('.acf-row');

		$rows.each(function () {
			var $row = $(this);

			if ($row.hasClass('acf-clone') || $row.data('ryv-enhanced')) {
				return;
			}

			// Only the top-level node rows - identified by carrying the
			// node_id field directly, which the Options sub-repeater's own
			// rows never have.
			var $idField = $row.children('.acf-fields').children('[data-name="node_id"]').first();
			if (!$idField.length) {
				return;
			}

			$row.data('ryv-enhanced', true);
			$row.addClass('ryv-sa-row');

			addSaveNodeIdButton($row, $idField);

			var $summary = $('<button type="button" class="ryv-sa-row-summary"></button>');
			var $idPill   = $('<code class="ryv-sa-row-id"></code>');
			var $typeBadge = $('<span class="ryv-builder-node-type"></span>');
			var $title    = $('<span class="ryv-builder-node-title"></span>');
			var $caret    = $('<span class="ryv-sa-row-caret" aria-hidden="true"></span>');

			$summary.append($idPill, $typeBadge, $title, $caret);
			// Prepended inside the existing .acf-fields <td>, not as a new
			// sibling <td>/bare <tr> child: either of those makes the
			// summary bar its own table column, which (a) sits outside the
			// table's column-width negotiation entirely when it's a bare
			// non-td child - shrink-to-fit against the title's full,
			// unwrapped text and blowing out the row/page width instead of
			// eliding it - and (b) even wrapped in a proper <td>, still
			// forces every row to share a column whose width is driven by
			// the (always-visible) summary content, squeezing the
			// (only-visible-when-expanded) real fields column down to a
			// sliver. Living inside .acf-fields keeps the summary bar and
			// the fields it toggles in the one column, sized normally.
			$row.children('.acf-fields').first().prepend($summary);

			var refreshSummary = function () {
				var id    = $row.find('[data-name="node_id"] input').first().val() || '(new node)';
				var type  = $row.find('[data-name="node_type"] select').first().val() || 'markup';
				var title = $row.find('[data-name="node_title"] input').first().val() || '';

				$idPill.text(id);
				$typeBadge
					.attr('class', 'ryv-builder-node-type ryv-builder-node-type--' + type)
					.text(TYPE_LABELS[type] || type);
				$title.text(title);

				$row.data('ryv-node-id', id);
				$row.data('ryv-node-title', title);
			};

			refreshSummary();
			toggleTypeConditionalFields($row);
			// populateRepeater() calls this once it has actually filled the
			// row's fields in - ACF's 'append' action (which is what drives
			// this function) fires the moment the row is created, before
			// any of its fields have a value yet.
			$row.data('ryv-refresh-summary', refreshSummary);
			$row.data('ryv-refresh-type-conditional-fields', function () {
				toggleTypeConditionalFields($row);
			});

			// Keep the summary in sync while a node is being edited - the
			// admin retyping a Title shouldn't need a collapse/expand cycle
			// to see it reflected in the row's own header.
			$row.on('input change', '[data-name="node_title"] input, [data-name="node_type"] select', refreshSummary);
			$row.on('input change', '[data-name="node_type"] select', function () {
				toggleTypeConditionalFields($row);
			});

			$summary.on('click', function () {
				$row.toggleClass('ryv-sa-collapsed');
			});
		});
	}

	/**
	 * Add a "Save Node ID" button under a top-level node row's Node ID
	 * field. Clicking it saves *just this node's id* into the connected
	 * form's schema over AJAX (creating it if it's a brand-new row, or
	 * safely renaming it if the id changed - see
	 * RYV_States_Sync::ajax_save_node_id()) and refreshes every Next Node
	 * dropdown on the page from the response, so the id becomes pickable
	 * immediately instead of only after the whole post is saved and the
	 * page reloaded.
	 *
	 * @param {jQuery} $row     The top-level node row.
	 * @param {jQuery} $idField The row's `[data-name="node_id"]` field wrapper.
	 */
	function addSaveNodeIdButton($row, $idField) {
		var $input = $idField.find('input').first();
		if (!$input.length || $idField.find('.ryv-sa-save-id-btn').length) {
			return;
		}

		var $btn = $('<button type="button" class="button ryv-sa-save-id-btn">Save Node ID</button>');
		var $msg = $('<span class="ryv-sa-save-id-msg" aria-live="polite"></span>');
		$idField.append($('<div class="ryv-sa-save-id-row"></div>').append($btn, $msg));

		$btn.on('click', function () {
			var typedId = ($input.val() || '').trim();
			var $origField = $row.children('.acf-fields').children('[data-name="node_original_id"]').first();
			var $origInput = $origField.find('input').first();
			var originalId = $origInput.length ? ($origInput.val() || '') : '';
			var postId = $('#post_ID').val();

			$msg.removeClass('ryv-sa-save-id-error');

			if (!typedId) {
				$msg.addClass('ryv-sa-save-id-error').text('Enter a Node ID first.');
				return;
			}
			if (!postId) {
				$msg.addClass('ryv-sa-save-id-error').text('Save this state as a draft first.');
				return;
			}

			$btn.prop('disabled', true);
			$msg.text('Saving…');

			$.ajax({
				url: ryvStatesAdmin.ajaxUrl,
				method: 'POST',
				data: {
					action: 'ryv_save_node_id',
					nonce: ryvStatesAdmin.saveNodeNonce,
					post_id: postId,
					node_id: typedId,
					node_original_id: originalId,
				},
				success: function (response) {
					$btn.prop('disabled', false);

					if (!response.success) {
						$msg.addClass('ryv-sa-save-id-error').text(response.data || 'Could not save that id.');
						return;
					}

					if ($origInput.length) {
						$origInput.val(response.data.node_id).trigger('change');
					}

					refreshNextNodeChoices(response.data.choices);
					$msg.text('Saved - ready to pick as a Next Node.');
				},
				error: function () {
					$btn.prop('disabled', false);
					$msg.addClass('ryv-sa-save-id-error').text('Request failed - check your connection and try again.');
				}
			});
		});
	}

	/**
	 * Rebuild every "Next Node" select on the page (both the node-level
	 * field and the one inside every node's Options sub-repeater, including
	 * the hidden `.acf-clone` row templates so a row added *after* this
	 * point also gets the fresh list) from a fresh `{id: label}` map,
	 * preserving whatever each select currently has chosen.
	 *
	 * @param {object} choices `{id: label}` map, as returned by
	 *                         RYV_States_Sync::ajax_save_node_id().
	 */
	function refreshNextNodeChoices(choices) {
		if (!choices) {
			return;
		}

		$('.acf-field[data-key="field_ryv_node_next_node"] select, .acf-field[data-key="field_ryv_option_next_node"] select').each(function () {
			var $select    = $(this);
			var currentVal = $select.val();
			var hasCurrent = false;

			$select.empty();

			Object.keys(choices).forEach(function (id) {
				if (id === currentVal) {
					hasCurrent = true;
				}
				$select.append($('<option></option>').attr('value', id).text(choices[id]));
			});

			// A selection that isn't in the fresh list (stale after some
			// other change) is kept rather than silently dropped - losing
			// an admin's saved choice would be worse than one extra option.
			if (currentVal && !hasCurrent) {
				$select.prepend($('<option></option>').attr('value', currentVal).text(currentVal));
			}

			$select.val(currentVal || '').trigger('change');
		});
	}

})(jQuery);
