<?php
/**
 * View: Translations admin page.
 *
 * Expects $forms (WP_Post[], every ryv_forms post), $form_options
 * (int post_id => "Title (LANG)" string, for the two <select>s), $groups
 * (array<int|string, array{language_code:string, post:WP_Post}[]>, from
 * RYV_Translations_Page::group_by_trid()), $languages (WPML's
 * `wpml_active_languages` result), and $notice (array{success:bool,
 * message:string}|null) from RYV_Translations_Page::render_page().
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Form Translations', 'ryv-eligibility-tool' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Link two existing Eligibility Forms (one per language) as translations of each other. Use this when each language edition was created independently (e.g. imported from its own YAML file) instead of through WPML\'s "Add translation" flow.', 'ryv-eligibility-tool' ); ?>
	</p>

	<?php if ( $notice ) : ?>
		<div class="notice <?php echo $notice['success'] ? 'notice-success' : 'notice-error'; ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" style="margin: 1em 0;">
		<?php wp_nonce_field( RYV_Translations_Page::NONCE_ACTION_BACKFILL, 'ryv_backfill_nonce' ); ?>
		<?php submit_button( __( 'Assign Default Language', 'ryv-eligibility-tool' ), 'secondary', 'ryv_backfill_submit', false ); ?>
		<p class="description"><?php esc_html_e( 'Scans every Eligibility Form and assigns the site\'s default language to any that don\'t have a WPML language yet - a form missing one won\'t show up in WPML\'s own language-filtered lists.', 'ryv-eligibility-tool' ); ?></p>
	</form>

	<?php if ( count( $forms ) < 2 ) : ?>
		<p><?php esc_html_e( 'You need at least two Eligibility Forms before you can link translations.', 'ryv-eligibility-tool' ); ?></p>
	<?php else : ?>
		<h2><?php esc_html_e( 'Link Forms', 'ryv-eligibility-tool' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( RYV_Translations_Page::NONCE_ACTION, 'ryv_link_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="ryv_original_form"><?php esc_html_e( 'Form', 'ryv-eligibility-tool' ); ?></label>
						</th>
						<td>
							<select name="ryv_original_form" id="ryv_original_form" required>
								<option value=""><?php esc_html_e( '— Select —', 'ryv-eligibility-tool' ); ?></option>
								<?php foreach ( $form_options as $post_id => $label ) : ?>
									<option value="<?php echo esc_attr( $post_id ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The form the translation should join.', 'ryv-eligibility-tool' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ryv_translation_form"><?php esc_html_e( 'Is the translation of', 'ryv-eligibility-tool' ); ?></label>
						</th>
						<td>
							<select name="ryv_translation_form" id="ryv_translation_form" required>
								<option value=""><?php esc_html_e( '— Select —', 'ryv-eligibility-tool' ); ?></option>
								<?php foreach ( $form_options as $post_id => $label ) : ?>
									<option value="<?php echo esc_attr( $post_id ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The other-language form to link. Must be in a different language than "Form" above.', 'ryv-eligibility-tool' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( __( 'Link as Translations', 'ryv-eligibility-tool' ), 'primary', 'ryv_link_submit' ); ?>
		</form>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Current Groups', 'ryv-eligibility-tool' ); ?></h2>
	<?php if ( empty( $groups ) ) : ?>
		<p><?php esc_html_e( 'No forms found.', 'ryv-eligibility-tool' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Forms in this group', 'ryv-eligibility-tool' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $groups as $rows ) : ?>
					<tr>
						<td>
							<?php foreach ( $rows as $i => $row ) : ?>
								<?php if ( $i > 0 ) : ?> &nbsp;→&nbsp; <?php endif; ?>
								<a href="<?php echo esc_url( get_edit_post_link( $row['post']->ID ) ); ?>">
									<?php echo esc_html( $row['post']->post_title ); ?>
								</a>
								<code><?php echo esc_html( strtoupper( $row['language_code'] ) ); ?></code>
								<?php if ( count( $rows ) > 1 ) : ?>
									<form method="post" style="display:inline;" onsubmit="return confirm( <?php echo esc_js( __( 'Unlink this form from its translation group?', 'ryv-eligibility-tool' ) ); ?> );">
										<?php wp_nonce_field( RYV_Translations_Page::NONCE_ACTION_UNLINK, 'ryv_unlink_nonce' ); ?>
										<input type="hidden" name="ryv_unlink_form" value="<?php echo esc_attr( $row['post']->ID ); ?>" />
										<button type="submit" name="ryv_unlink_submit" class="button-link-delete" style="font-size: 12px;">
											<?php esc_html_e( 'Unlink', 'ryv-eligibility-tool' ); ?>
										</button>
									</form>
								<?php endif; ?>
							<?php endforeach; ?>
							<?php if ( 1 === count( $rows ) ) : ?>
								<span class="description"> — <?php esc_html_e( 'not linked to any other language yet', 'ryv-eligibility-tool' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
