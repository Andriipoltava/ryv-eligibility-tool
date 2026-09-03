<?php
/**
 * View: Import YAML admin page.
 *
 * Expects $result (array|null), $forms (WP_Post[]), and $form_node_counts
 * (int post_id => int node count) from RYV_Import_Page::render_page().
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Import Drupal Webform YAML', 'ryv-eligibility-tool' ); ?></h1>
	<p><?php esc_html_e( 'Upload a Drupal Webform element-tree YAML export. The whole file - every question, for every state it covers - is flattened into one decision graph and imported in a single pass.', 'ryv-eligibility-tool' ); ?></p>

	<?php if ( $result ) : ?>
		<?php if ( ! empty( $result['success'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: 1: node count, 2: edit link */
						esc_html__( 'Imported %1$d question(s). %2$s', 'ryv-eligibility-tool' ),
						(int) $result['node_count'],
						'<a href="' . esc_url( get_edit_post_link( $result['post_id'] ) ) . '">' . esc_html__( 'Edit form', 'ryv-eligibility-tool' ) . '</a>'
					);
					?>
				</p>
				<?php if ( ! empty( $result['title'] ) ) : ?>
					<p><?php esc_html_e( 'Title (from the file):', 'ryv-eligibility-tool' ); ?> <strong><?php echo esc_html( $result['title'] ); ?></strong></p>
				<?php endif; ?>
				<?php if ( ! empty( $result['description'] ) ) : ?>
					<p><?php esc_html_e( 'Description (from the file):', 'ryv-eligibility-tool' ); ?> <?php echo esc_html( $result['description'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $result['skipped'] ) ) : ?>
					<p><?php esc_html_e( 'Skipped elements (no eligibility logic, e.g. submit buttons or malformed entries):', 'ryv-eligibility-tool' ); ?> <code><?php echo esc_html( implode( ', ', $result['skipped'] ) ); ?></code></p>
				<?php endif; ?>
				<?php if ( ! empty( $result['warnings'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Needs manual review:', 'ryv-eligibility-tool' ); ?></strong></p>
					<ul style="list-style: disc; margin-left: 1.5em; max-height: 20em; overflow-y: auto;">
						<?php foreach ( $result['warnings'] as $warning ) : ?>
							<li><?php echo esc_html( $warning ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $result['health'] ) ) : ?>
				<?php
				$broken_count   = count( $result['health']['broken_links'] );
				$orphaned_count = count( $result['health']['orphaned_nodes'] );
				$cycle_count    = count( $result['health']['cycles'] ?? array() );
				$is_healthy     = 0 === $broken_count && 0 === $orphaned_count && 0 === $cycle_count;
				?>
				<div class="notice <?php echo $is_healthy ? 'notice-success' : 'notice-warning'; ?>" style="margin-top: 0;">
					<p>
						<strong><?php esc_html_e( 'Graph health check:', 'ryv-eligibility-tool' ); ?></strong>
						<?php if ( $is_healthy ) : ?>
							<?php
							printf(
								/* translators: %d: total node count */
								esc_html__( '0 broken links found, %d node(s) all reachable - tree integrity is healthy.', 'ryv-eligibility-tool' ),
								(int) $result['health']['total_nodes']
							);
							?>
						<?php else : ?>
							<?php
							printf(
								/* translators: 1: broken link count, 2: orphaned node count, 3: cycle count */
								esc_html__( '%1$d broken link(s), %2$d orphaned (unreachable) node(s), %3$d loop(s) found - see below.', 'ryv-eligibility-tool' ),
								(int) $broken_count,
								(int) $orphaned_count,
								(int) $cycle_count
							);
							?>
						<?php endif; ?>
					</p>

					<?php if ( $broken_count > 0 ) : ?>
						<p><strong><?php esc_html_e( 'Broken links:', 'ryv-eligibility-tool' ); ?></strong></p>
						<ul style="list-style: disc; margin-left: 1.5em; max-height: 14em; overflow-y: auto;">
							<?php foreach ( $result['health']['broken_links'] as $link ) : ?>
								<li>
									<code><?php echo esc_html( $link['node_id'] ); ?></code>
									<?php if ( null !== $link['option_value'] ) : ?>
										<?php /* translators: %s: option value */ ?>
										(<?php printf( esc_html__( 'option "%s"', 'ryv-eligibility-tool' ), esc_html( $link['option_value'] ) ); ?>)
									<?php endif; ?>
									&rarr; <code><?php echo esc_html( $link['target'] ); ?></code>
									<?php esc_html_e( '(does not exist)', 'ryv-eligibility-tool' ); ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ( $orphaned_count > 0 ) : ?>
						<p>
							<strong><?php esc_html_e( 'Orphaned (unreachable) nodes:', 'ryv-eligibility-tool' ); ?></strong>
							<code><?php echo esc_html( implode( ', ', $result['health']['orphaned_nodes'] ) ); ?></code>
						</p>
					<?php endif; ?>

					<?php if ( $cycle_count > 0 ) : ?>
						<p><strong><?php esc_html_e( 'Loops (a node eventually routes back to itself):', 'ryv-eligibility-tool' ); ?></strong></p>
						<ul style="list-style: disc; margin-left: 1.5em; max-height: 14em; overflow-y: auto;">
							<?php foreach ( $result['health']['cycles'] as $cycle ) : ?>
								<li><code><?php echo esc_html( implode( ' → ', array_merge( $cycle, array( $cycle[0] ) ) ) ); ?></code></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $result['review'] ) ) : ?>
				<div class="notice notice-warning" style="margin-top: 0;">
					<p>
						<strong>
							<?php
							printf(
								/* translators: %d: flagged element count */
								esc_html__( 'Review Imported Elements (%d flagged)', 'ryv-eligibility-tool' ),
								count( $result['review'] )
							);
							?>
						</strong>
					</p>
					<p class="description">
						<?php esc_html_e( 'These elements had a compound #states rule (multiple conditions combined with AND) that can\'t be represented as a single branch, so they were imported as linear/unconditional. Click "Edit in builder" to jump straight to that node and wire up its next_node manually.', 'ryv-eligibility-tool' ); ?>
					</p>
					<?php foreach ( $result['review'] as $item ) : ?>
						<details style="margin: 0.5em 0;">
							<summary style="cursor: pointer;">
								<code><?php echo esc_html( $item['element_id'] ); ?></code>
								&mdash; <?php echo esc_html( $item['summary'] ); ?>
							</summary>
							<div style="margin: 0.5em 0 0.5em 1.5em;">
								<p><?php echo esc_html( $item['note'] ); ?></p>
								<p>
									<a href="<?php echo esc_url( add_query_arg( 'ryv_node', $item['element_id'], get_edit_post_link( $result['post_id'] ) ) ); ?>">
										<?php esc_html_e( 'Edit in builder →', 'ryv-eligibility-tool' ); ?>
									</a>
								</p>
							</div>
						</details>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		<?php else : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $result['message'] ); ?></p>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<form method="post" enctype="multipart/form-data" action="">
		<?php wp_nonce_field( RYV_Import_Page::NONCE_ACTION, 'ryv_import_nonce' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ryv_target_form"><?php esc_html_e( 'Target Form', 'ryv-eligibility-tool' ); ?></label></th>
				<td>
					<select name="ryv_target_form" id="ryv_target_form">
						<option value="__new__"><?php esc_html_e( '+ Create New Form', 'ryv-eligibility-tool' ); ?></option>
						<?php foreach ( $forms as $form ) : ?>
							<?php $node_count = $form_node_counts[ $form->ID ] ?? 0; ?>
							<option value="<?php echo esc_attr( $form->ID ); ?>">
								<?php echo esc_html( $form->post_title ); ?> (#<?php echo esc_html( $form->ID ); ?>)
								<?php if ( $node_count > 0 ) : ?>
									&mdash; <?php echo esc_html( $node_count ); ?> <?php esc_html_e( 'existing nodes', 'ryv-eligibility-tool' ); ?>
								<?php endif; ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Importing into a form that already has content replaces its entire schema - it does not merge.', 'ryv-eligibility-tool' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ryv_new_form_title"><?php esc_html_e( 'New Form Title', 'ryv-eligibility-tool' ); ?></label></th>
				<td>
					<input type="text" name="ryv_new_form_title" id="ryv_new_form_title" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Voting Eligibility Questionnaire', 'ryv-eligibility-tool' ); ?>" />
					<p class="description"><?php esc_html_e( 'Only used when "Target Form" is set to "+ Create New Form". Leave blank to use the title from the uploaded file itself, if it has one.', 'ryv-eligibility-tool' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ryv_yaml_file"><?php esc_html_e( 'YAML File', 'ryv-eligibility-tool' ); ?></label></th>
				<td>
					<input type="file" name="ryv_yaml_file" id="ryv_yaml_file" accept=".yml,.yaml" required />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Confirm Replace', 'ryv-eligibility-tool' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="ryv_confirm_replace" value="1" />
						<?php esc_html_e( 'I understand this replaces this form\'s entire existing content.', 'ryv-eligibility-tool' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Only required when the selected target form already has content - ignored when creating a new form.', 'ryv-eligibility-tool' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Import', 'ryv-eligibility-tool' ), 'primary', 'ryv_import_submit' ); ?>
	</form>
</div>
