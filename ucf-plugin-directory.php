<?php
/*
Plugin Name: UCF Plugin Directory
Description: UNDER DEVELOPMENT — not for production sites. Browse, install, and update UCF WordPress plugins from the UCF Composer repository, right from the Plugins screen.
Version: 0.1.0
Requires at least: 6.0
Requires PHP: 7.4
Author: UCF Web Communications
License: GPL3
Update URI: https://packagist.org/packages/ucf/ucf-plugin-directory
*/
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'UCF_PLUGIN_DIRECTORY__VERSION', '0.1.0' );
define( 'UCF_PLUGIN_DIRECTORY__PLUGIN_FILE', __FILE__ );
define( 'UCF_PLUGIN_DIRECTORY__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UCF_PLUGIN_DIRECTORY__PLUGIN_URL', plugins_url( basename( __DIR__ ) ) );

/**
 * The Composer repository the catalog is read from. Defaults to the current
 * UCF Satis index. Set to https://repo.packagist.org/ once the ucf vendor is
 * live on packagist.org. Can be overridden in wp-config.php.
 */
if ( ! defined( 'UCF_PLUGIN_DIRECTORY_REPOSITORY' ) ) {
	define( 'UCF_PLUGIN_DIRECTORY_REPOSITORY', 'https://ucf.github.io/ucf-packagist/' );
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

register_activation_hook( __FILE__, array( 'UCF_Plugin_Directory', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'UCF_Plugin_Directory', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'UCF_Plugin_Directory', 'init' ) );
