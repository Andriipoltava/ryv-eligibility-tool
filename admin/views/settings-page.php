<?php
/**
 * View: Global Styles admin page. Standard Settings API form - all fields
 * are registered/rendered by RYV_Settings_Page itself; this file just
 * provides the surrounding <form>/submit_button() chrome.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Global Styles', 'ryv-eligibility-tool' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Plugin-wide default colors and typography for every RYV form. The Elementor widget\'s own Style tab controls, when set, still take precedence per-instance over these.', 'ryv-eligibility-tool' ); ?>
	</p>

	<form method="post" action="options.php">
		<?php
		settings_fields( RYV_Settings_Page::OPTION_GROUP );
		do_settings_sections( RYV_Settings_Page::PAGE_SLUG );
		submit_button();
		?>
	</form>
</div>