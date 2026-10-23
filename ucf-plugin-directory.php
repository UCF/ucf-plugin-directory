<?php
/*
Plugin Name: UCF Plugin Directory
Description: UNDER DEVELOPMENT — not for production sites. Browse, install, and update UCF WordPress plugins from the UCF Composer repository, right from the Plugins screen.
Version: 0.1.0
Requires at least: 6.0
Requires PHP: 7.4
Author: UCF Web Communications
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Update URI: https://packagist.org/packages/ucf/ucf-plugin-directory
*/
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'UCF_PLUGIN_DIRECTORY__PLUGIN_FILE', __FILE__ );
define( 'UCF_PLUGIN_DIRECTORY__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UCF_PLUGIN_DIRECTORY__PLUGIN_URL', plugins_url( basename( __DIR__ ) ) );

/**
 * The Composer repository the catalog is read from. Defaults to packagist.org,
 * where UCF packages are published under the ucf vendor. Any Composer v2
 * repository (e.g. a self-hosted Satis mirror) can be used instead by
 * defining this in wp-config.php.
 */
if ( ! defined( 'UCF_PLUGIN_DIRECTORY_REPOSITORY' ) ) {
	define( 'UCF_PLUGIN_DIRECTORY_REPOSITORY', 'https://repo.packagist.org/' );
}

require_once UCF_PLUGIN_DIRECTORY__PLUGIN_DIR . 'includes/class-metadata-expander.php';
require_once UCF_PLUGIN_DIRECTORY__PLUGIN_DIR . 'includes/class-package.php';
require_once UCF_PLUGIN_DIRECTORY__PLUGIN_DIR . 'includes/class-repository-client.php';
require_once UCF_PLUGIN_DIRECTORY__PLUGIN_DIR . 'includes/class-catalog.php';
require_once UCF_PLUGIN_DIRECTORY__PLUGIN_DIR . 'includes/class-plugins-api.php';
require_once UCF_PLUGIN_DIRECTORY__PLUGIN_DIR . 'includes/class-updater.php';
require_once UCF_PLUGIN_DIRECTORY__PLUGIN_DIR . 'includes/class-upgrader.php';
require_once UCF_PLUGIN_DIRECTORY__PLUGIN_DIR . 'includes/class-settings.php';
require_once UCF_PLUGIN_DIRECTORY__PLUGIN_DIR . 'includes/class-plugin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once UCF_PLUGIN_DIRECTORY__PLUGIN_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'ucf', 'UCF_Plugin_Directory_CLI' );
}

register_activation_hook( __FILE__, array( 'UCF_Plugin_Directory', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'UCF_Plugin_Directory', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'UCF_Plugin_Directory', 'init' ) );
