<?php
/**
 * View: Submissions admin page.
 *
 * Expects $leads (array[], from RYV_Leads::recent()), $paged (current
 * 1-indexed page), $total_pages (total page count), and $export_url from
 * RYV_Submissions_Page::render_page().
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