<?php
/**
 * View: Activity Log admin page.
 *
 * Expects $submissions (array[], from RYV_Submission_Log::recent()),
 * $edits (array[], from RYV_Edit_Log::recent()), $sub_page/$edit_page
 * (current 1-indexed page) and $submissions_pages/$edits_pages (total page
 * count) from RYV_Logs_Page::render_page().
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Activity Log', 'ryv-eligibility-tool' ); ?></h1>

	<h2><?php esc_html_e( 'Recent Visitor Submissions', 'ryv-eligibility-tool' ); ?></h2>
	<p class="description"><?php esc_html_e( 'One row per visitor per attempt at a questionnaire, updated as they answer - anonymous (no IP or other identifying info is recorded), most recent first.', 'ryv-eligibility-tool' ); ?></p>

	<?php if ( empty( $submissions ) ) : ?>
		<p><?php esc_html_e( 'No submissions recorded yet.', 'ryv-eligibility-tool' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Form', 'ryv-eligibility-tool' ); ?></th>
					<th><?php esc_html_e( 'State', 'ryv-eligibility-tool' ); ?></th>
					<th><?php esc_html_e( 'Current Step', 'ryv-eligibility-tool' ); ?></th>
					<th><?php esc_html_e( 'Answers', 'ryv-eligibility-tool' ); ?></th>
					<th><?php esc_html_e( 'Last Updated', 'ryv-eligibility-tool' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $submissions as $row ) : ?>
					<?php
					$answers = json_decode( (string) $row['answers'], true );
					$answers = is_array( $answers ) ? $answers : array();
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( (int) $row['form_id'] ) ); ?>">
								<?php echo esc_html( RYV_Logs_Page::form_title( (int) $row['form_id'] ) ); ?>
							</a>
						</td>
						<td><?php echo esc_html( $row['state_id'] ? $row['state_id'] : '—' ); ?></td>
						<td><?php echo esc_html( $row['current_node'] ? $row['current_node'] : '—' ); ?></td>
						<td>
							<?php if ( empty( $answers ) ) : ?>
								—
							<?php else : ?>
								<details>
									<summary>
										<?php
										printf(
											/* translators: %d: number of answered questions */
											esc_html( _n( '%d answer', '%d answers', count( $answers ), 'ryv-eligibility-tool' ) ),
											count( $answers )
										);
										?>
									</summary>
									<pre style="max-width: 32em; white-space: pre-wrap; overflow-wrap: anywhere;"><?php echo esc_html( wp_json_encode( $answers, JSON_PRETTY_PRINT ) ); ?></pre>
								</details>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( RYV_Logs_Page::format_datetime( $row['updated_at'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo RYV_Logs_Page::pagination_control( 'sub_page', $sub_page, $submissions_pages ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped HTML from a trusted internal method. ?>
	<?php endif; ?>

	<h2 style="margin-top: 2em;"><?php esc_html_e( 'Recent Builder Edits', 'ryv-eligibility-tool' ); ?></h2>
	<p class="description"><?php esc_html_e( 'One row per Save in the form builder - who and when, most recent first.', 'ryv-eligibility-tool' ); ?></p>

	<?php if ( empty( $edits ) ) : ?>
		<p><?php esc_html_e( 'No edits recorded yet.', 'ryv-eligibility-tool' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Form', 'ryv-eligibility-tool' ); ?></th>
					<th><?php esc_html_e( 'Edited By', 'ryv-eligibility-tool' ); ?></th>
					<th><?php esc_html_e( 'Edited At', 'ryv-eligibility-tool' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $edits as $row ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( (int) $row['form_id'] ) ); ?>">
								<?php echo esc_html( RYV_Logs_Page::form_title( (int) $row['form_id'] ) ); ?>
							</a>
						</td>
						<td><?php echo esc_html( RYV_Logs_Page::user_display_name( (int) $row['user_id'] ) ); ?></td>
						<td><?php echo esc_html( RYV_Logs_Page::format_datetime( $row['edited_at'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo RYV_Logs_Page::pagination_control( 'edit_page', $edit_page, $edits_pages ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped HTML from a trusted internal method. ?>
	<?php endif; ?>
</div>
