<?php
/**
 * Plugin Name: Multisite Crossposter
 * Description: Crosspost posts between blogs in a WordPress Multisite environment
 * Author: TheGreatWPCompany
 * Author URI: https://TheGreatWPCompany.com
 * Version: 0.0.1
 * Plugin URI: https://TheGreatWPCompany.com/plugins/multisite-cross-poster
 *
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

// Define the current version
define( 'MSCP_VERSION', '0.0.1' );

// Define the plugin base path
define( 'MSCP_BASE_PATH', dirname( __FILE__ ) );

/**
 * A global function that kicks everything off.
 * Really just to stop everything polluting the
 * global namespace.
 *
 * @return null
 */
function mscp_initialise() {

	//
	// Classes that register hooks and do stuff
	//

	// Being a multisite only plugin there's no point doing
	// anything if it's not a multisite
	if ( is_multisite() ) {

		// Load the main admin section
		require MSCP_BASE_PATH . '/includes/class-mscp-admin.php';
		$mscp_admin = new MSCP_Admin();

		// Load the main admin section
		require MSCP_BASE_PATH . '/includes/class-mscp-aggregator.php';
		$mscp_aggregator = new MSCP_Aggregator();

	}

}

mscp_initialise();