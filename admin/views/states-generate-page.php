<?php
/**
 * View: Generate States admin page.
 *
 * Expects $result (array|null), $sync_result (array|null),
 * $autopop_result (array|null), and $forms (WP_Post[]) from
 * RYV_States_Generate_Page::render_page().
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Generate States', 'ryv-eligibility-tool' ); ?></h1>
	<p><?php esc_html_e( 'Reads a form\'s state picker (its entry node) and creates one State post per option that doesn\'t already have one - matched by state slug, so states already created from this or any other form (e.g. a translated version of the same questionnaire) are never duplicated.', 'ryv-eligibility-tool' ); ?></p>

	<?php if ( $result ) : ?>
		<?php if ( ! empty( $result['success'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: 1: created count, 2: skipped count */
						esc_html__( 'Created %1$d state(s), skipped %2$d already-existing state(s).', 'ryv-eligibility-tool' ),
						count( $result['created'] ),
						count( $result['skipped'] )
					);
					?>
				</p>
			</div>

			<?php if ( ! empty( $result['errors'] ) ) : ?>
				<div class="notice notice-error" style="margin-top: 0;">
					<p><strong><?php esc_html_e( 'Errors:', 'ryv-eligibility-tool' ); ?></strong></p>
					<ul style="list-style: disc; margin-left: 1.5em;">
						<?php foreach ( $result['errors'] as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $result['created'] ) ) : ?>
				<h2><?php esc_html_e( 'Created', 'ryv-eligibility-tool' ); ?></h2>
				<table class="widefat striped" style="max-width: 600px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'State', 'ryv-eligibility-tool' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'ryv-eligibility-tool' ); ?></th>
							<th><?php esc_html_e( 'Post', 'ryv-eligibility-tool' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['created'] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['label'] ); ?></td>
								<td><code><?php echo esc_html( $row['slug'] ); ?></code></td>
								<td><a href="<?php echo esc_url( get_edit_post_link( $row['post_id'] ) ); ?>">#<?php echo esc_html( $row['post_id'] ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $result['skipped'] ) ) : ?>
				<h2><?php esc_html_e( 'Skipped (already exist)', 'ryv-eligibility-tool' ); ?></h2>
				<table class="widefat striped" style="max-width: 600px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'State', 'ryv-eligibility-tool' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'ryv-eligibility-tool' ); ?></th>
							<th><?php esc_html_e( 'Existing post', 'ryv-eligibility-tool' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['skipped'] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['label'] ); ?></td>
								<td><code><?php echo esc_html( $row['slug'] ); ?></code></td>
								<td><a href="<?php echo esc_url( get_edit_post_link( $row['existing_post_id'] ) ); ?>">#<?php echo esc_html( $row['existing_post_id'] ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php else : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $result['message'] ); ?></p>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( RYV_States_Generate_Page::NONCE_ACTION, 'ryv_generate_states_nonce' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ryv_generate_form_id"><?php esc_html_e( 'Form', 'ryv-eligibility-tool' ); ?></label></th>
				<td>
					<select name="ryv_generate_form_id" id="ryv_generate_form_id" required>
						<option value=""><?php esc_html_e( '- Select a form -', 'ryv-eligibility-tool' ); ?></option>
						<?php foreach ( $forms as $form ) : ?>
							<option value="<?php echo esc_attr( $form->ID ); ?>">
								<?php echo esc_html( $form->post_title ); ?> (#<?php echo esc_html( $form->ID ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The form\'s entry node (its state picker) is read to find every state to generate.', 'ryv-eligibility-tool' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'New post status', 'ryv-eligibility-tool' ); ?></th>
				<td>
					<label>
						<input type="radio" name="ryv_generate_status" value="draft" checked />
						<?php esc_html_e( 'Draft (review before publishing)', 'ryv-eligibility-tool' ); ?>
					</label>
					<br />
					<label>
						<input type="radio" name="ryv_generate_status" value="publish" />
						<?php esc_html_e( 'Publish immediately', 'ryv-eligibility-tool' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Generate States', 'ryv-eligibility-tool' ), 'primary', 'ryv_generate_states_submit' ); ?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Sync Category Colors to Map', 'ryv-eligibility-tool' ); ?></h2>
	<p><?php esc_html_e( 'Pushes every published state\'s Map Category (color/tooltip/mixed-rules) into the MapSVG regions table. Needed once for any state whose category was set outside a normal wp-admin Save (e.g. a bulk import) - editing a state\'s Map Category in the States screen itself already syncs automatically on save.', 'ryv-eligibility-tool' ); ?></p>

	<?php if ( $sync_result ) : ?>
		<?php if ( ! empty( $sync_result['success'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %d: number of states synced */
						esc_html__( 'Synced %d state(s) to the map.', 'ryv-eligibility-tool' ),
						(int) $sync_result['count']
					);
					?>
				</p>
			</div>
		<?php else : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $sync_result['message'] ); ?></p>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( RYV_States_Generate_Page::SYNC_NONCE_ACTION, 'ryv_sync_map_nonce' ); ?>
		<?php submit_button( __( 'Sync Category Colors to Map', 'ryv-eligibility-tool' ), 'secondary', 'ryv_sync_map_submit' ); ?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Auto-Populate Map Categories', 'ryv-eligibility-tool' ); ?></h2>
	<p><?php esc_html_e( 'Resets the map\'s 8 categories to the CLC reference colors/labels, re-assigns every matching state\'s category from the CLC reference map (overriding any manual edit), and syncs the result to MapSVG - one click instead of a manual DB edit per state. This also runs automatically once in the background the next time any wp-admin page loads.', 'ryv-eligibility-tool' ); ?></p>

	<?php if ( $autopop_result ) : ?>
		<?php if ( ! empty( $autopop_result['success'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Map categories and state assignments reset to the CLC reference defaults and synced to the map.', 'ryv-eligibility-tool' ); ?></p>
			</div>
		<?php else : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $autopop_result['message'] ); ?></p>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( RYV_States_Generate_Page::AUTOPOP_NONCE_ACTION, 'ryv_autopopulate_map_nonce' ); ?>
		<?php submit_button( __( 'Auto-Populate Map Categories', 'ryv-eligibility-tool' ), 'secondary', 'ryv_autopopulate_map_submit' ); ?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Repair Imported Events', 'ryv-eligibility-tool' ); ?></h2>

	<p class="description">
		<?php esc_html_e( 'Use this if events exist in the admin but appear nowhere on the site - not in a state\'s Events picker, not on a state page, not even on The Events Calendar\'s own list. Events written by a direct import can carry UTC dates that disagree with their local ones, which stops The Events Calendar from registering them at all. This recalculates those dates and asks it to rebuild. Safe to run more than once.', 'ryv-eligibility-tool' ); ?>
	</p>

	<?php $events_result = $this->consume_events_repair_result(); ?>
	<?php if ( $events_result ) : ?>
		<?php if ( ! empty( $events_result['messages'] ) ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( implode( ' ', $events_result['messages'] ) ); ?></p>
			</div>
		<?php else : ?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: 1: events checked, 2: events whose dates were corrected, 3: events rebuilt in The Events Calendar's tables */
						esc_html__( 'Checked %1$d event(s): corrected the dates on %2$d and rebuilt %3$d in The Events Calendar.', 'ryv-eligibility-tool' ),
						(int) $events_result['checked'],
						(int) $events_result['fixed'],
						(int) $events_result['rebuilt']
					);
					?>
				</p>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( RYV_States_Generate_Page::EVENTS_NONCE_ACTION, 'ryv_repair_events_nonce' ); ?>
		<?php submit_button( __( 'Repair Imported Events', 'ryv-eligibility-tool' ), 'secondary', 'ryv_repair_events_submit' ); ?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Repair State Post Template', 'ryv-eligibility-tool' ); ?></h2>

	<p class="description">
		<?php esc_html_e( 'Brings the State Post template to the configuration state pages need: removes Elementor Display Conditions (which crash the whole template when pointed at an Events or Resources field, leaving every state page blank below the header) and applies this plugin\'s own hide-when-empty classes instead. Template settings do not travel with a deploy, so run this once per environment. Safe to run more than once.', 'ryv-eligibility-tool' ); ?>
	</p>

	<?php $template_result = $this->consume_template_repair_result(); ?>
	<?php if ( $template_result ) : ?>
		<div class="notice notice-<?php echo empty( $template_result['dry_run'] ) ? 'success' : 'info'; ?> is-dismissible">
			<p>
				<?php if ( ! empty( $template_result['dry_run'] ) ) : ?>
					<strong><?php esc_html_e( 'Preview only - nothing was changed.', 'ryv-eligibility-tool' ); ?></strong><br />
				<?php endif; ?>
				<?php
				printf(
					/* translators: 1: number of display conditions, 2: number of classes */
					esc_html__( 'Display conditions removed: %1$d. Hide-when-empty classes set: %2$d.', 'ryv-eligibility-tool' ),
					(int) $template_result['conditions_removed'],
					(int) $template_result['markers_set']
				);
				?>
			</p>

			<?php if ( ! empty( $template_result['templates'] ) ) : ?>
				<ul style="margin-left:1.5em;list-style:disc;">
					<?php foreach ( $template_result['templates'] as $template ) : ?>
						<li>
							<?php echo esc_html( $template['title'] ); ?>
							<code>#<?php echo (int) $template['id']; ?></code>
							<?php foreach ( $template['markers_set'] as $element_id => $marker ) : ?>
								<br /><small><code><?php echo esc_html( $element_id ); ?></code> → <code><?php echo esc_html( $marker ? $marker : '(class removed)' ); ?></code></small>
							<?php endforeach; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $template_result['missing'] ) ) : ?>
				<p>
					<?php esc_html_e( 'Not found in any template (already correct, or that element has been rebuilt since):', 'ryv-eligibility-tool' ); ?>
					<code><?php echo esc_html( implode( ', ', $template_result['missing'] ) ); ?></code>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( RYV_States_Generate_Page::TEMPLATE_NONCE_ACTION, 'ryv_repair_template_nonce' ); ?>
		<?php submit_button( __( 'Preview Changes', 'ryv-eligibility-tool' ), 'secondary', 'ryv_preview_template_submit', false ); ?>
		<?php submit_button( __( 'Repair State Post Template', 'ryv-eligibility-tool' ), 'primary', 'ryv_repair_template_submit', false ); ?>
	</form>
</div>
