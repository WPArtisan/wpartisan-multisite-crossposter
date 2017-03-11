<?php
/**
 * Plugin Name: WPArtisan Multisite Crossposter
 * Description: Crosspost posts between blogs in a WordPress Multisite environment
 * Author: OzTheGreat (WPArtisan)
 * Author URI: https://wpartisan.me
 * Version: 0.1.0
 * Plugin URI: https://wpartisan.me/plugins/wpa-multisite-crossposter
 *
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define the current version.
define( 'MSCP_VERSION', '0.1.0' );

// Define the plugin base path.
define( 'MSCP_BASE_PATH', dirname( __FILE__ ) );

/**
 * A global function that kicks everything off.
 * Really just to stop everything polluting the
 * global namespace.
 *
 * @return void
 */
function mscp_initialise() {

	// Classes that register hooks and do stuff.

	// Being a multisite only plugin there's no point doing
	// anything if it's not a multisite.
	if ( is_multisite() ) {

		// Load the main admin section.
		require MSCP_BASE_PATH . '/includes/class-mscp-admin.php';
		$mscp_admin = new MSCP_Admin();

		// Load the main admin section.
		require MSCP_BASE_PATH . '/includes/class-mscp-aggregator.php';
		$mscp_aggregator = new MSCP_Aggregator();
	}

}

mscp_initialise();
