<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       portfolio.dachapman.com
 * @since      1.0.0
 *
 * @package    Jrwdev_User_Importer
 * @subpackage Jrwdev_User_Importer/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Jrwdev_User_Importer
 * @subpackage Jrwdev_User_Importer/includes
 * @author     David Chapman <davidchappy@gmail.com>
 */
class Jrwdev_User_Importer_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'jrwdev-user-importer',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}



}
