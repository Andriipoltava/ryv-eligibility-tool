<?php
/**
 * View: Submissions admin page.
 *
 * Expects $leads (array[], from RYV_Leads::recent()), $paged (current
 * 1-indexed page), $total_pages (total page count), $export_url,
 * $import_action_url, $forms (WP_Post[]), $import_error (string|null), and
 * $imported_count (int|null) from RYV_Submissions_Page::render_page().
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>
		<?php esc_html_e( 'Submissions', 'ryv-eligibility-tool' ); ?>
		<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">
			<?php esc_html_e( 'Export to CSV', 'ryv-eligibility-tool' ); ?>
		</a>
	</h1>
	<p class="description">
		<?php esc_html_e( 'One row per successful "Form Submission" (lead_capture) node submit, recorded when that node\'s "Save submission to the database" setting is on - most recent first.', 'ryv-eligibility-tool' ); ?>
	</p>

	<?php if ( null !== $imported_count ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %d: number of imported leads */
					esc_html( _n( 'Successfully imported %d lead.', 'Successfully imported %d leads.', $imported_count, 'ryv-eligibility-tool' ) ),
					(int) $imported_count
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( $import_error ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( $import_error ); ?></p>
		</div>
	<?php endif; ?>

	<div class="card" style="max-width: 640px; margin: 1em 0;">
		<h2><?php esc_html_e( 'Import Leads from CSV', 'ryv-eligibility-tool' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Upload a CSV file - the header row becomes each imported lead\'s field names, and every subsequent row is stored as one lead against the form selected below.', 'ryv-eligibility-tool' ); ?>
		</p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $import_action_url ); ?>">
			<?php wp_nonce_field( RYV_Submissions_Page::IMPORT_NONCE_ACTION, RYV_Submissions_Page::IMPORT_NONCE_FIELD ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ryv_import_form_id"><?php esc_html_e( 'Form', 'ryv-eligibility-tool' ); ?></label></th>
					<td>
						<select name="ryv_import_form_id" id="ryv_import_form_id" required>
							<option value=""><?php esc_html_e( '— Select a form —', 'ryv-eligibility-tool' ); ?></option>
							<?php foreach ( $forms as $form ) : ?>
								<option value="<?php echo esc_attr( $form->ID ); ?>">
									<?php echo esc_html( $form->post_title ); ?> (#<?php echo esc_html( $form->ID ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Imported leads are recorded against this form.', 'ryv-eligibility-tool' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ryv_csv_file"><?php esc_html_e( 'CSV File', 'ryv-eligibility-tool' ); ?></label></th>
					<td>
						<input type="file" name="ryv_csv_file" id="ryv_csv_file" accept=".csv" required />
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Import CSV', 'ryv-eligibility-tool' ), 'primary', 'ryv_import_csv' ); ?>
		</form>
	</div>

	<?php if ( empty( $leads ) ) : ?>
		<p><?php esc_html_e( 'No submissions recorded yet.', 'ryv-eligibility-tool' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width: 4em;"><?php esc_html_e( 'ID', 'ryv-eligibility-tool' ); ?></th>
					<th><?php esc_html_e( 'Form', 'ryv-eligibility-tool' ); ?></th>
					<th style="width: 14em;"><?php esc_html_e( 'Date', 'ryv-eligibility-tool' ); ?></th>
					<th><?php esc_html_e( 'Form Data', 'ryv-eligibility-tool' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $leads as $row ) : ?>
					<?php
					$form_data = json_decode( (string) $row['form_data'], true );
					$form_data = is_array( $form_data ) ? $form_data : array();
					?>
					<tr>
						<td><?php echo esc_html( $row['id'] ); ?></td>
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( (int) $row['form_id'] ) ); ?>">
								<?php echo esc_html( RYV_Logs_Page::form_title( (int) $row['form_id'] ) ); ?>
							</a>
							<br />
							<span class="description"><?php echo esc_html( $row['node_id'] ); ?></span>
						</td>
						<td><?php echo esc_html( RYV_Logs_Page::format_datetime( $row['created_at'] ) ); ?></td>
						<td>
							<?php if ( empty( $form_data ) ) : ?>
								&mdash;
							<?php else : ?>
								<ul style="margin: 0;">
									<?php foreach ( $form_data as $field_name => $value ) : ?>
										<li>
											<strong><?php echo esc_html( $field_name ); ?>:</strong>
											<?php echo esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ); ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo RYV_Logs_Page::pagination_control( 'paged', $paged, $total_pages ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped HTML from a trusted internal method. ?>
	<?php endif; ?>
</div>