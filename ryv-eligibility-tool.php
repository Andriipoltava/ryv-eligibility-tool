<?php
/**
 * Plugin Name:       RYV Eligibility Tool
 * Description:       Custom decision-tree voting-eligibility questionnaire for Restore Your Vote, rendered as a lightweight JS-driven Elementor widget.
 * Version:           0.7.0
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

define( 'RYV_VERSION', '0.7.0' );
define( 'RYV_PLUGIN_FILE', __FILE__ );
define( 'RYV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RYV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RYV_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once RYV_PLUGIN_DIR . 'includes/class-ryv-plugin.php';

register_activation_hook( __FILE__, array( 'RYV_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RYV_Plugin', 'deactivate' ) );

RYV_Plugin::instance();
