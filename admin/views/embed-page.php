<?php
/**
 * View: Embed & Share admin page.
 *
 * @var array  $settings    RYV_Embed::get_settings().
 * @var bool   $has_forms   Whether any published form exists.
 * @var string $ancestors   Current frame-ancestors value.
 * @var string $domains_txt Allow-list, one per line.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$opt = RYV_Embed::OPTION;
?>
<style>
	.ryv-embed-grid{display:grid;grid-template-columns:minmax(300px,420px) 1fr;gap:20px;align-items:start;margin-top:16px}
	@media(max-width:1100px){.ryv-embed-grid{grid-template-columns:1fr}}
	.ryv-embed-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin-bottom:20px}
	.ryv-embed-card h2{margin:0 0 12px;font-size:15px}
	.ryv-embed-field{margin:0 0 14px}
	.ryv-embed-field>label,.ryv-embed-field>.ryv-embed-label{display:block;font-weight:600;margin-bottom:4px}
	.ryv-embed-field select,.ryv-embed-field input[type=number]{width:100%;max-width:100%}
	.ryv-embed-checks label{display:block;margin-bottom:4px}
	.ryv-embed-colors{display:grid;grid-template-columns:1fr 1fr;gap:10px}
	.ryv-embed-copyrow{display:flex;gap:6px}
	.ryv-embed-copyrow input{flex:1;min-width:0}
	.ryv-embed-code{width:100%;font-family:Menlo,Consolas,monospace;font-size:12px;min-height:140px}
	.ryv-embed-devices{display:flex;gap:6px;margin-bottom:10px}
	.ryv-embed-devices .button.is-active{background:#2271b1;color:#fff;border-color:#2271b1}
	.ryv-embed-stage{background:#f0f0f1;border:1px dashed #c3c4c7;border-radius:4px;padding:12px;overflow:auto}
	.ryv-embed-stage iframe{display:block;margin:0 auto;border:0;width:100%;height:600px;background:#fff;transition:max-width .2s}
	.ryv-embed-warn{border-left:4px solid #dba617;background:#fcf9e8;padding:8px 12px;margin:8px 0 0}
</style>

<div class="wrap">
	<h1><?php esc_html_e( 'Embed & Share', 'ryv-eligibility-tool' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Give partners a link or a snippet of code to put the eligibility tool on their own website. It shows only the tool - no menus, header or footer of this site.', 'ryv-eligibility-tool' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ryv-help#ryv-help-partner-embed' ) ); ?>"><?php esc_html_e( 'Instructions', 'ryv-eligibility-tool' ); ?></a> |
		<a href="<?php echo esc_url( RYV_PLUGIN_URL . 'docs/partner-embed-guide.html' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Partner guide to send', 'ryv-eligibility-tool' ); ?></a>
	</p>

	<?php settings_errors( $opt ); ?>

	<?php if ( ! $has_forms ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'There are no published forms yet. Publish a form first, then come back here.', 'ryv-eligibility-tool' ); ?></p></div>
	<?php else : ?>
	<div class="ryv-embed-grid" id="ryv-embed-app">

		<div>
			<div class="ryv-embed-card">
				<h2><?php esc_html_e( '1. Customize', 'ryv-eligibility-tool' ); ?></h2>

				<div class="ryv-embed-field">
					<label for="ryv-embed-form"><?php esc_html_e( 'Form', 'ryv-eligibility-tool' ); ?></label>
					<select id="ryv-embed-form"></select>
				</div>

				<div class="ryv-embed-field" id="ryv-embed-state-field" style="display:none">
					<label for="ryv-embed-state"><?php esc_html_e( 'Start at', 'ryv-eligibility-tool' ); ?></label>
					<select id="ryv-embed-state"></select>
					<p class="description"><?php esc_html_e( 'Pick one state to skip the "Select your state" question - the tool opens on that state\'s first question.', 'ryv-eligibility-tool' ); ?></p>
				</div>

				<div class="ryv-embed-field">
					<label for="ryv-embed-look"><?php esc_html_e( 'Look', 'ryv-eligibility-tool' ); ?></label>
					<select id="ryv-embed-look">
						<option value="site"><?php esc_html_e( 'Same as the website (default) - title, steps and questions like the home page', 'ryv-eligibility-tool' ); ?></option>
						<option value="plain"><?php esc_html_e( 'Minimal - just the questions, simple styling', 'ryv-eligibility-tool' ); ?></option>
					</select>
				</div>

				<div class="ryv-embed-field">
					<label for="ryv-embed-layout"><?php esc_html_e( 'Layout', 'ryv-eligibility-tool' ); ?></label>
					<select id="ryv-embed-layout">
						<option value=""><?php esc_html_e( 'Like the website (default)', 'ryv-eligibility-tool' ); ?></option>
						<option value="stacked"><?php esc_html_e( 'All questions stacked', 'ryv-eligibility-tool' ); ?></option>
						<option value="wizard"><?php esc_html_e( 'One question at a time', 'ryv-eligibility-tool' ); ?></option>
					</select>
				</div>

				<div class="ryv-embed-field ryv-embed-checks">
					<span class="ryv-embed-label"><?php esc_html_e( 'Show', 'ryv-eligibility-tool' ); ?></span>
					<label><input type="checkbox" id="ryv-embed-title" checked> <?php esc_html_e( 'Form title', 'ryv-eligibility-tool' ); ?></label>
					<label><input type="checkbox" id="ryv-embed-description" checked> <?php esc_html_e( 'Form description', 'ryv-eligibility-tool' ); ?></label>
					<label class="ryv-embed-site-only"><input type="checkbox" id="ryv-embed-steps" checked> <?php esc_html_e( 'Steps (1, 2, 3 list)', 'ryv-eligibility-tool' ); ?></label>
					<label class="ryv-embed-site-only"><input type="checkbox" id="ryv-embed-show-note" checked> <?php esc_html_e( 'Note under the steps', 'ryv-eligibility-tool' ); ?></label>
					<label><input type="checkbox" id="ryv-embed-restart" checked> <?php esc_html_e( '"Start Over" button', 'ryv-eligibility-tool' ); ?></label>
					<label><input type="checkbox" id="ryv-embed-pad" checked> <?php esc_html_e( 'Padding around the tool', 'ryv-eligibility-tool' ); ?></label>
				</div>

				<div class="ryv-embed-field ryv-embed-texts">
					<span class="ryv-embed-label"><?php esc_html_e( 'Text (optional)', 'ryv-eligibility-tool' ); ?></span>
					<label for="ryv-embed-title-text"><small><?php esc_html_e( 'Title', 'ryv-eligibility-tool' ); ?></small></label>
					<input type="text" id="ryv-embed-title-text" class="widefat" maxlength="160">
					<label for="ryv-embed-desc-text"><small><?php esc_html_e( 'Description', 'ryv-eligibility-tool' ); ?></small></label>
					<textarea id="ryv-embed-desc-text" class="widefat" rows="3" maxlength="400"></textarea>
					<label for="ryv-embed-label-start-over"><small><?php esc_html_e( '"Start Over" button', 'ryv-eligibility-tool' ); ?></small></label>
					<input type="text" id="ryv-embed-label-start-over" class="widefat" maxlength="60" placeholder="Start Over">
					<label for="ryv-embed-label-continue"><small><?php esc_html_e( '"Continue" button', 'ryv-eligibility-tool' ); ?></small></label>
					<input type="text" id="ryv-embed-label-continue" class="widefat" maxlength="60" placeholder="Continue">
					<label for="ryv-embed-label-submit"><small><?php esc_html_e( '"Submit" button', 'ryv-eligibility-tool' ); ?></small></label>
					<input type="text" id="ryv-embed-label-submit" class="widefat" maxlength="60" placeholder="Submit">
					<label for="ryv-embed-label-placeholder"><small><?php esc_html_e( 'Dropdown placeholder', 'ryv-eligibility-tool' ); ?></small></label>
					<input type="text" id="ryv-embed-label-placeholder" class="widefat" maxlength="60" placeholder="&mdash; Select &mdash;">
					<label for="ryv-embed-note"><small><?php esc_html_e( 'Note under the steps (Same-as-website look)', 'ryv-eligibility-tool' ); ?></small></label>
					<textarea id="ryv-embed-note" class="widefat" rows="2" maxlength="400" data-default="<?php echo esc_attr( RYV_Embed::default_note() ); ?>"><?php echo esc_textarea( RYV_Embed::default_note() ); ?></textarea>
					<span class="description"><?php esc_html_e( 'Leave blank to use the form\'s own wording. Plain text only. The title and description show only if enabled above.', 'ryv-eligibility-tool' ); ?></span>
				</div>

				<div class="ryv-embed-field ryv-embed-plain-only" id="ryv-embed-plain-align">
					<label for="ryv-embed-align"><?php esc_html_e( 'Alignment', 'ryv-eligibility-tool' ); ?></label>
					<select id="ryv-embed-align">
						<option value="left"><?php esc_html_e( 'Left - title, description and questions in one column (default)', 'ryv-eligibility-tool' ); ?></option>
						<option value="center"><?php esc_html_e( 'Centered', 'ryv-eligibility-tool' ); ?></option>
					</select>
				</div>

				<div class="ryv-embed-field ryv-embed-plain-only" id="ryv-embed-plain-fit">
					<label for="ryv-embed-fit"><?php esc_html_e( 'Content width', 'ryv-eligibility-tool' ); ?></label>
					<select id="ryv-embed-fit">
						<option value="compact"><?php esc_html_e( 'Compact, centered (default)', 'ryv-eligibility-tool' ); ?></option>
						<option value="full"><?php esc_html_e( 'Fill the whole frame', 'ryv-eligibility-tool' ); ?></option>
					</select>
				</div>

				<div class="ryv-embed-field">
					<span class="ryv-embed-label"><?php esc_html_e( 'Colors (optional)', 'ryv-eligibility-tool' ); ?></span>
					<div class="ryv-embed-colors">
						<div><small><?php esc_html_e( 'Accent', 'ryv-eligibility-tool' ); ?></small><br><input type="text" class="ryv-embed-color" data-param="primary"></div>
						<div><small><?php esc_html_e( 'Secondary', 'ryv-eligibility-tool' ); ?></small><br><input type="text" class="ryv-embed-color" data-param="secondary"></div>
						<div><small><?php esc_html_e( 'Text', 'ryv-eligibility-tool' ); ?></small><br><input type="text" class="ryv-embed-color" data-param="text"></div>
						<div><small><?php esc_html_e( 'Background', 'ryv-eligibility-tool' ); ?></small><br><input type="text" class="ryv-embed-color" data-param="bg"></div>
					</div>
					<p class="description"><?php esc_html_e( 'Leave blank to use the site defaults. Background is transparent by default so the partner\'s page shows through.', 'ryv-eligibility-tool' ); ?></p>
				</div>

				<div class="ryv-embed-field">
					<span class="ryv-embed-label"><?php esc_html_e( 'Frame height', 'ryv-eligibility-tool' ); ?></span>
					<label><input type="radio" name="ryv-embed-height" value="auto" checked> <?php esc_html_e( 'Automatic (recommended) - adds one small script', 'ryv-eligibility-tool' ); ?></label><br>
					<label><input type="radio" name="ryv-embed-height" value="fixed"> <?php esc_html_e( 'Fixed height (iframe only, scrolls inside)', 'ryv-eligibility-tool' ); ?></label>
					<input type="number" id="ryv-embed-height-px" value="700" min="200" max="3000" step="10" style="margin-top:6px;display:none">
				</div>
			</div>

			<div class="ryv-embed-card">
				<h2><?php esc_html_e( '2. Share', 'ryv-eligibility-tool' ); ?></h2>

				<div class="ryv-embed-field">
					<label for="ryv-embed-link"><?php esc_html_e( 'Link (opens the tool on its own page)', 'ryv-eligibility-tool' ); ?></label>
					<div class="ryv-embed-copyrow">
						<input type="text" id="ryv-embed-link" readonly class="regular-text">
						<button type="button" class="button" data-copy="ryv-embed-link"><?php esc_html_e( 'Copy', 'ryv-eligibility-tool' ); ?></button>
						<a class="button" id="ryv-embed-open" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'ryv-eligibility-tool' ); ?></a>
					</div>
				</div>

				<div class="ryv-embed-field">
					<label for="ryv-embed-code"><?php esc_html_e( 'Embed code (paste into the partner\'s page HTML)', 'ryv-eligibility-tool' ); ?></label>
					<textarea id="ryv-embed-code" class="ryv-embed-code" readonly></textarea>
					<p><button type="button" class="button button-primary" data-copy="ryv-embed-code"><?php esc_html_e( 'Copy embed code', 'ryv-eligibility-tool' ); ?></button></p>
				</div>
			</div>
		</div>

		<div class="ryv-embed-card">
			<h2><?php esc_html_e( 'Live preview', 'ryv-eligibility-tool' ); ?></h2>
			<div class="ryv-embed-devices">
				<button type="button" class="button is-active" data-width="100%"><?php esc_html_e( 'Desktop', 'ryv-eligibility-tool' ); ?></button>
				<button type="button" class="button" data-width="768px"><?php esc_html_e( 'Tablet', 'ryv-eligibility-tool' ); ?></button>
				<button type="button" class="button" data-width="375px"><?php esc_html_e( 'Phone', 'ryv-eligibility-tool' ); ?></button>
			</div>
			<div class="ryv-embed-stage">
				<iframe id="ryv-embed-preview" data-ryv-embed title="<?php esc_attr_e( 'Preview', 'ryv-eligibility-tool' ); ?>"></iframe>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<form method="post" action="options.php" class="ryv-embed-card" style="max-width:820px">
		<?php settings_fields( RYV_Embed_Page::OPTION_GROUP ); ?>
		<h2><?php esc_html_e( 'Access & security', 'ryv-eligibility-tool' ); ?></h2>

		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>>
				<strong><?php esc_html_e( 'Allow the tool to be embedded and shared', 'ryv-eligibility-tool' ); ?></strong>
			</label><br>
			<span class="description"><?php esc_html_e( 'Turn off to disable every embed link and iframe at once (they will show a "not found" page). Administrators can still preview here.', 'ryv-eligibility-tool' ); ?></span>
		</p>

		<p>
			<strong><?php esc_html_e( 'Which websites may embed it', 'ryv-eligibility-tool' ); ?></strong><br>
			<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[mode]" value="any" <?php checked( 'any', $settings['mode'] ); ?>> <?php esc_html_e( 'Any website', 'ryv-eligibility-tool' ); ?></label><br>
			<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[mode]" value="allowlist" <?php checked( 'allowlist', $settings['mode'] ); ?>> <?php esc_html_e( 'Only the websites listed below (recommended once you know your partners)', 'ryv-eligibility-tool' ); ?></label>
		</p>

		<p>
			<label for="ryv-embed-domains"><strong><?php esc_html_e( 'Allowed websites', 'ryv-eligibility-tool' ); ?></strong></label><br>
			<textarea id="ryv-embed-domains" name="<?php echo esc_attr( $opt ); ?>[domains]" rows="6" class="large-text code" placeholder="partner.org&#10;*.another-partner.org"><?php echo esc_textarea( $domains_txt ); ?></textarea><br>
			<span class="description"><?php esc_html_e( 'One per line. Use "*.example.org" to include all subdomains. https:// is assumed. Your own site is always allowed.', 'ryv-eligibility-tool' ); ?></span>
		</p>
		<?php if ( 'allowlist' === $settings['mode'] && ! $settings['domains'] ) : ?>
			<div class="ryv-embed-warn"><?php esc_html_e( 'The list is empty, so only this site can currently embed the tool. Add your partners\' websites above.', 'ryv-eligibility-tool' ); ?></div>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'Currently enforced:', 'ryv-eligibility-tool' ); ?>
			<code>Content-Security-Policy: frame-ancestors <?php echo esc_html( $ancestors ); ?></code><br>
			<?php esc_html_e( 'Embedded pages are also hidden from search engines. If a partner sees a blank frame, a firewall/CDN setting on this site may be adding its own X-Frame-Options header - ask your host to exempt the /embed/ path.', 'ryv-eligibility-tool' ); ?>
		</p>

		<?php $ryv_ip = RYV_Rate_Limiter::resolve_ip(); ?>
		<p class="description" id="ryv-embed-ip-check">
			<strong><?php esc_html_e( 'Spam protection (rate limit):', 'ryv-eligibility-tool' ); ?></strong>
			<?php esc_html_e( 'visitors are counted by their address. Right now the site sees you as', 'ryv-eligibility-tool' ); ?>
			<code><?php echo esc_html( $ryv_ip['ip'] ); ?></code>
			<?php
			echo 'cloudflare' === $ryv_ip['source']
				? esc_html__( '(real address taken from Cloudflare).', 'ryv-eligibility-tool' )
				: esc_html__( '(direct connection address).', 'ryv-eligibility-tool' );
			?>
			<br>
			<?php esc_html_e( 'If this is not your own public address, every visitor is being counted as one person and could be blocked together - ask your developer to supply the real address (filter ryv_rate_limit_ip).', 'ryv-eligibility-tool' ); ?>
			<br>
			<small>
				<?php
				printf(
					/* translators: 1: connection address, 2: Cloudflare header value */
					esc_html__( 'Details: connection %1$s, Cloudflare header %2$s.', 'ryv-eligibility-tool' ),
					'<code>' . esc_html( '' !== $ryv_ip['remote_addr'] ? $ryv_ip['remote_addr'] : '-' ) . '</code>',
					'<code>' . esc_html( '' !== $ryv_ip['cf_header'] ? $ryv_ip['cf_header'] : '-' ) . '</code>'
				);
				?>
			</small>
		</p>

		<?php submit_button( __( 'Save access settings', 'ryv-eligibility-tool' ) ); ?>
	</form>
</div>
