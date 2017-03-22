<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              portfolio.dachapman.com
 * @since             1.0.0
 * @package           Jrwdev_User_Importer
 *
 * @wordpress-plugin
 * Plugin Name:       CSV to WooCommerce User Importer
 * Plugin URI:        https://github.com/davidchappy/jrwdev-user-importer.git
 * Description:       A plugin that imports a custom CSV file and generates WooCommerce users
 * Version:           1.0.0
 * Author:            David Chapman
 * Author URI:        portfolio.dachapman.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       jrwdev-user-importer
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-jrwdev-user-importer-activator.php
 */
function activate_jrwdev_user_importer() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-jrwdev-user-importer-activator.php';
	Jrwdev_User_Importer_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-jrwdev-user-importer-deactivator.php
 */
function deactivate_jrwdev_user_importer() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-jrwdev-user-importer-deactivator.php';
	Jrwdev_User_Importer_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_jrwdev_user_importer' );
register_deactivation_hook( __FILE__, 'deactivate_jrwdev_user_importer' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-jrwdev-user-importer.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_jrwdev_user_importer() {

	$plugin = new Jrwdev_User_Importer();
	$plugin->run();

}
run_jrwdev_user_importer();
