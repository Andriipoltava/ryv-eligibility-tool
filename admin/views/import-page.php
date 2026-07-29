<?php
/**
 * View: Import YAML admin page.
 *
 * Expects $result (array|null) and $forms (WP_Post[]) from RYV_Import_Page::render_page().
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Import Drupal Webform YAML', 'ryv-eligibility-tool' ); ?></h1>
	<p><?php esc_html_e( 'Upload a Drupal Webform element-tree YAML export. Files that bundle every state (a state-select element followed by per-state gated containers - the standard RYV export shape) are automatically split and every state is imported in one go.', 'ryv-eligibility-tool' ); ?></p>

	<?php if ( $result ) : ?>
		<?php if ( ! empty( $result['success'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: 1: state count, 2: edit link */
						esc_html__( 'Imported %1$d state(s). %2$s', 'ryv-eligibility-tool' ),
						count( $result['states'] ),
						'<a href="' . esc_url( get_edit_post_link( $result['post_id'] ) ) . '">' . esc_html__( 'Edit form', 'ryv-eligibility-tool' ) . '</a>'
					);
					?>
				</p>
				<?php if ( ! empty( $result['states'] ) ) : ?>
					<ul style="list-style: disc; margin-left: 1.5em;">
						<?php foreach ( $result['states'] as $state ) : ?>
							<li><?php echo esc_html( $state['label'] ); ?> (<code><?php echo esc_html( $state['id'] ); ?></code>) - <?php echo esc_html( $state['nodes'] ); ?> <?php esc_html_e( 'nodes', 'ryv-eligibility-tool' ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( ! empty( $result['unassigned'] ) ) : ?>
					<p><?php esc_html_e( 'Not assigned to any state (needs manual placement in the form builder):', 'ryv-eligibility-tool' ); ?> <code><?php echo esc_html( implode( ', ', $result['unassigned'] ) ); ?></code></p>
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
							<option value="<?php echo esc_attr( $form->ID ); ?>"><?php echo esc_html( $form->post_title ); ?> (#<?php echo esc_html( $form->ID ); ?>)</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'A form can hold multiple states - import each state\'s YAML into the same form.', 'ryv-eligibility-tool' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ryv_new_form_title"><?php esc_html_e( 'New Form Title', 'ryv-eligibility-tool' ); ?></label></th>
				<td>
					<input type="text" name="ryv_new_form_title" id="ryv_new_form_title" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Voting Eligibility Questionnaire', 'ryv-eligibility-tool' ); ?>" />
					<p class="description"><?php esc_html_e( 'Only used when "Target Form" is set to "+ Create New Form".', 'ryv-eligibility-tool' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ryv_state_override"><?php esc_html_e( 'State Override', 'ryv-eligibility-tool' ); ?></label></th>
				<td>
					<input type="text" name="ryv_state_override" id="ryv_state_override" class="regular-text" placeholder="alabama" />
					<p class="description"><?php esc_html_e( 'Leave blank for standard RYV exports - states are auto-detected from the file. Only set this to force the entire file to import as one named state (e.g. a hand-trimmed single-state file where auto-detection finds nothing).', 'ryv-eligibility-tool' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ryv_yaml_file"><?php esc_html_e( 'YAML File', 'ryv-eligibility-tool' ); ?></label></th>
				<td>
					<input type="file" name="ryv_yaml_file" id="ryv_yaml_file" accept=".yml,.yaml" required />
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Import', 'ryv-eligibility-tool' ), 'primary', 'ryv_import_submit' ); ?>
	</form>
</div>
