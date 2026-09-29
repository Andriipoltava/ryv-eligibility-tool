<?php
/**
 * Plugin Name:       RYV Eligibility Tool
 * Description:       Custom decision-tree voting-eligibility questionnaire for Restore Your Vote, rendered as a lightweight JS-driven Elementor widget.
 * Version:           0.8.78
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Restore Your Vote
 * Text Domain:       ryv-eligibility-tool
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'RYV_VERSION', '0.8.81' );
define( 'RYV_PLUGIN_FILE', __FILE__ );
define( 'RYV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RYV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RYV_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once RYV_PLUGIN_DIR . 'includes/class-ryv-plugin.php';

register_activation_hook( __FILE__, array( 'RYV_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RYV_Plugin', 'deactivate' ) );

/**
 * Load this plugin's translations. Not strictly required for a .mo dropped
 * in `wp-content/languages/plugins/` (WP has auto-discovered those by text
 * domain since 4.6, no matter what loads it or doesn't) - but Loco
 * Translate's own default "system" sync location, and any translation
 * bundled directly in this plugin's own `languages/` directory, still need
 * an explicit load_plugin_textdomain() call to be picked up. Covers the
 * admin UI's ~350 __()/_e() calls (see admin/*.php) - NOT the questionnaire
 * content itself, which lives as plain text/HTML inside the
 * `_ryv_form_schema` postmeta JSON blob and never passes through gettext at
 * all (see CLAUDE.md's "Data model" section).
 */
function ryv_eligibility_tool_load_textdomain() {
	load_plugin_textdomain( 'ryv-eligibility-tool', false, dirname( RYV_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'plugins_loaded', 'ryv_eligibility_tool_load_textdomain' );

RYV_Plugin::instance();
