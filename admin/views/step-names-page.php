<?php
/**
 * View: Step Names admin page.
 *
 * Expects $forms (WP_Post[], every ryv_forms post), $anchor_id (int, 0 if
 * none picked yet), $group (array<string,WP_Post>, from
 * RYV_Step_Names_Page::get_translation_group() - empty until a form is
 * picked), and $notice (array{success:bool,message:string}|null) from
 * RYV_Step_Names_Page::render_page().
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Step Names', 'ryv-eligibility-tool' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Bulk-assign the wizard sidebar\'s 3 named steps (Entry question, Second question, Final step) across an entire form\'s graph - instead of opening every node in the builder one at a time. If this form has linked translations (see the Translations page), each language\'s own graph gets the same rule applied with its own wording, in one go.', 'ryv-eligibility-tool' ); ?>
	</p>

	<?php if ( $notice ) : ?>
		<div class="notice <?php echo $notice['success'] ? 'notice-success' : 'notice-error'; ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $forms ) ) : ?>
		<p><?php esc_html_e( 'No forms found.', 'ryv-eligibility-tool' ); ?></p>
		<?php return; ?>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Choose a Form', 'ryv-eligibility-tool' ); ?></h2>
	<form method="get">
		<input type="hidden" name="post_type" value="<?php echo esc_attr( RYV_States_CPT::POST_TYPE ); ?>" />
		<input type="hidden" name="page" value="<?php echo esc_attr( RYV_Step_Names_Page::PAGE_SLUG ); ?>" />
		<select name="ryv_form">
			<option value=""><?php esc_html_e( '— Select —', 'ryv-eligibility-tool' ); ?></option>
			<?php foreach ( $forms as $form ) : ?>
				<option value="<?php echo esc_attr( $form->ID ); ?>" <?php selected( $anchor_id, $form->ID ); ?>>
					<?php echo esc_html( $form->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php submit_button( __( 'Continue', 'ryv-eligibility-tool' ), 'secondary', '', false ); ?>
	</form>

	<?php if ( ! empty( $group ) ) : ?>
		<h2><?php esc_html_e( 'Step Wording, per Language', 'ryv-eligibility-tool' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( RYV_Step_Names_Page::NONCE_ACTION, 'ryv_step_names_nonce' ); ?>
			<input type="hidden" name="ryv_anchor_form" value="<?php echo esc_attr( $anchor_id ); ?>" />

			<?php if ( count( $group ) > 1 ) : ?>
				<p>
					<label>
						<input type="checkbox" name="ryv_include_translations" value="1" checked="checked" />
						<?php esc_html_e( 'Also apply to this form\'s linked translations (uncheck to only change the form selected above).', 'ryv-eligibility-tool' ); ?>
					</label>
				</p>
			<?php endif; ?>

			<?php foreach ( $group as $lang_code => $form ) : ?>
				<h3>
					<code><?php echo esc_html( strtoupper( $lang_code ) ); ?></code>
					<?php echo esc_html( $form->post_title ); ?>
				</h3>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="ryv_step1_<?php echo esc_attr( $lang_code ); ?>"><?php esc_html_e( 'Step 1 (entry question)', 'ryv-eligibility-tool' ); ?></label>
							</th>
							<td>
								<input type="text" class="regular-text" id="ryv_step1_<?php echo esc_attr( $lang_code ); ?>" name="ryv_labels[<?php echo esc_attr( $lang_code ); ?>][step1]" placeholder="<?php esc_attr_e( 'Choose the state where you live', 'ryv-eligibility-tool' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ryv_step2_<?php echo esc_attr( $lang_code ); ?>"><?php esc_html_e( 'Step 2 (second question)', 'ryv-eligibility-tool' ); ?></label>
							</th>
							<td>
								<input type="text" class="regular-text" id="ryv_step2_<?php echo esc_attr( $lang_code ); ?>" name="ryv_labels[<?php echo esc_attr( $lang_code ); ?>][step2]" placeholder="<?php esc_attr_e( 'Tell us about your conviction', 'ryv-eligibility-tool' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ryv_step3_<?php echo esc_attr( $lang_code ); ?>"><?php esc_html_e( 'Step 3 (final step)', 'ryv-eligibility-tool' ); ?></label>
							</th>
							<td>
								<input type="text" class="regular-text" id="ryv_step3_<?php echo esc_attr( $lang_code ); ?>" name="ryv_labels[<?php echo esc_attr( $lang_code ); ?>][step3]" placeholder="<?php esc_attr_e( 'Get your next steps', 'ryv-eligibility-tool' ); ?>" />
							</td>
						</tr>
					</tbody>
				</table>
			<?php endforeach; ?>

			<p class="description">
				<?php esc_html_e( 'This resets every node\'s Step Name in each form above, then sets exactly these three. Type each language\'s wording yourself - nothing is auto-translated.', 'ryv-eligibility-tool' ); ?>
			</p>
			<?php submit_button( __( 'Apply Step Names', 'ryv-eligibility-tool' ), 'primary', 'ryv_step_names_submit' ); ?>
		</form>
	<?php endif; ?>
</div>
