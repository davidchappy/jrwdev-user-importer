<?php
/**
 * Plugin Name: WooCommerce Customer/Order XML Export Suite
 * Plugin URI: http://www.woothemes.com/products/customerorder-xml-export-suite/
 * Description: Easily download customers & orders in XML format and automatically export FTP or HTTP POST on a recurring schedule
 * Author: WooThemes / SkyVerge
 * Author URI: http://www.woothemes.com
 * Version: 2.0.5
 * Text Domain: woocommerce-customer-order-xml-export-suite
 * Domain Path: /i18n/languages/
 *
 * Copyright: (c) 2013-2016 SkyVerge (info@skyverge.com)
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package     WC-Customer-Order-XML-Export-Suite
 * @author      SkyVerge
 * @category    Export
 * @copyright   Copyright (c) 2013-2016, SkyVerge, Inc.
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

// Required functions
if ( ! function_exists( 'woothemes_queue_update' ) ) {
	require_once( plugin_dir_path( __FILE__ ) . 'woo-includes/woo-functions.php' );
}

// Plugin updates
woothemes_queue_update( plugin_basename( __FILE__ ), '5c165d4e132d8cf5a6d6555daf358041', '187889' );

// WC active check
if ( ! is_woocommerce_active() ) {
	return;
}

// Required library class
if ( ! class_exists( 'SV_WC_Framework_Bootstrap' ) ) {
	require_once( plugin_dir_path( __FILE__ ) . 'lib/skyverge/woocommerce/class-sv-wc-framework-bootstrap.php' );
}

SV_WC_Framework_Bootstrap::instance()->register_plugin( '4.4.2', __( 'WooCommerce Customer/Order XML Export Suite', 'woocommerce-customer-order-xml-export-suite' ), __FILE__, 'init_woocommerce_customer_order_xml_export_suite', array(
	'minimum_wc_version'   => '2.4.13',
	'minimum_wp_version'   => '4.4',
	'backwards_compatible' => '4.4.0',
) );

function init_woocommerce_customer_order_xml_export_suite() {

/**
 * The main class for the Customer/Order XML export.
 *
 * @since 1.0.0
 */
class WC_Customer_Order_XML_Export_Suite extends SV_WC_Plugin {


	/** plugin version number */
	const VERSION = '2.0.5';

	/** @var WC_Customer_Order_XML_Export_Suite single instance of this plugin */
	protected static $instance;

	/** plugin id */
	const PLUGIN_ID = 'customer_order_xml_export_suite';

	/** plugin text domain, DEPRECATED as of 1.6.0 */
	const TEXT_DOMAIN = 'woocommerce-customer-order-xml-export-suite';

	/** @var \WC_Customer_Order_XML_Export_Suite_Admin instance */
	protected $admin;

	/** @var \WC_Customer_Order_XML_Export_Suite_Formats instance */
	protected $formats;

	/** @var \WC_Customer_Order_XML_Export_Suite_Methods instance */
	protected $methods;

	/** @var \WC_Customer_Order_XML_Export_Suite_Cron instance */
	protected $cron;

	/** @var \WC_Customer_Order_XML_Export_Suite_AJAX instance */
	protected $ajax;

	/** @var \WC_Customer_Order_XML_Export_Suite_Background_Export instance */
	protected $background_export;

	/** @var \WC_Customer_Order_XML_Export_Suite_Download_Handler instance */
	protected $download_handler;

	/** @var \WC_Customer_Order_XML_Export_Suite_Export_Handler instance */
	protected $export_handler;

	/** @var array deprectaed filter mapping, old => new **/
	protected $deprecated_filters = array(
		'wc_customer_order_xml_export_suite_export_file_name'               => 'wc_customer_order_xml_export_suite_filename',
		'wc_customer_order_xml_export_suite_admin_query_args'               => 'wc_customer_order_xml_export_suite_query_args',
		'wc_customer_order_xml_export_suite_admin_user_query_args'          => 'wc_customer_order_xml_export_suite_user_query_args',
		'wc_customer_order_xml_export_suite_order_export_format'            => 'wc_customer_order_xml_export_suite_orders_xml_data',
		'wc_customer_order_xml_export_suite_order_export_order_list_format' => 'wc_customer_order_xml_export_suite_order_data',
		'wc_customer_order_xml_export_suite_order_export_line_item_format'  => 'wc_customer_order_xml_export_suite_order_line_item',
		'wc_customer_order_xml_export_suite_order_export_order_note_format' => 'wc_customer_order_xml_export_suite_order_note',
		'wc_customer_order_xml_export_suite_customer_export_data'           => 'wc_customer_order_xml_export_suite_customer_data',
		'wc_customer_order_xml_export_suite_customer_export_format'         => 'wc_customer_order_xml_export_suite_customers_xml_data',
	);


	/**
	 * Setup main plugin class
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		parent::__construct(
			self::PLUGIN_ID,
			self::VERSION
		);

		// required files
		$this->includes();

		// Set orders as not-exported when created
		add_action( 'wp_insert_post',  array( $this, 'mark_order_not_exported' ), 10, 2 );

		// Set users as not-exported when created
		add_action( 'user_register',  array( $this, 'mark_user_not_exported' ), 1 );

		// Admin
		if ( is_admin() ) {

			if ( ! is_ajax() ) {
				$this->admin_includes();
			} else {
				$this->ajax_includes();
			}
		}

		// Handle renamed filters
		foreach ( $this->deprecated_filters as $new_filter ) {

			// we need to pass all the args to the filter, but there's no way to tell apply_filters()
			// to pass them all to the function (why, WP, why?), so we'll need to use an arbitary
			// value which is great enough so that it covers all our arguments
			add_filter( $new_filter, array( $this, 'map_deprecated_filter' ), 10, 10 );
		}

		// clear schduled events on deactivation
		register_deactivation_hook( $this->get_file(), array( $this->get_cron_instance(), 'clear_scheduled_export' ) );
	}


	/**
	 * Map a deprecated/renamed filter to a new one
	 *
	 * This method works by hooking into the new, renamed version of the filter
	 * and checking if any filters are hooked into the old hook. It then runs
	 * these filters and applies the data modifications in the new filter, and
	 * finally deprecates the filter using `_deprecated_function`.
	 *
	 * It assumes that the filter arguments match. If the args do not match,
	 * consider deprecating using SV_WC_Hook_Deprecator instead.
	 *
	 * @since 2.0.0
	 * @return mixed
	 */
	public function map_deprecated_filter() {

		$args   = func_get_args();
		$data   = $args[0];
		$filter = current_filter();

		// check if there is a matching old filter for the current filter
		if ( $old_filter = array_search( $filter, $this->deprecated_filters ) ) {

			// check if there are any filters added to the old filter
			if ( has_filter( $old_filter ) ) {

				// prepend old filter name to the args
				array_unshift( $args, $old_filter );

				// apply the filters attached to the old filter hook to $data
				$data = call_user_func_array( 'apply_filters', $args );

				_deprecated_function( 'The ' . $old_filter . ' filter', '2.0.0', $filter );
			}
		}

		return $data;
	}


	/**
	 * Set each new order as not exported. This is done because querying orders that have a specific meta key / value
	 * is much more reliable than querying orders that don't have a specific meta key / value AND prevents accidental
	 * export of a massive set of old orders on first run
	 *
	 * @since 1.0.0
	 * @param int $post_id new order ID
	 * @param object $post the post object
	 */
	public function mark_order_not_exported( $post_id, $post ) {

		if ( $post->post_type == 'shop_order' ) {

			// force unique, because oddly this can be invoked when changing the status of an existing order
			add_post_meta( $post_id, '_wc_customer_order_xml_export_suite_is_exported', 0, true );
			add_post_meta( $post_id, '_wc_customer_order_xml_export_suite_customer_is_exported', 0, true );
		}
	}


	/**
	 * Set each new user as not exported. This is done because querying users that have a specific meta key / value
	 * is much more reliable than querying users that don't have a specific meta key / value AND prevents accidental
	 * export of a massive set of old customers on first run
	 *
	 * @since 2.0.0
	 * @param int $user_id new user ID
	 * @param object $post the post object
	 */
	public function mark_user_not_exported( $user_id ) {

		add_user_meta( $user_id, '_wc_customer_order_xml_export_suite_is_exported', 0, true );
	}


	/**
	 * Includes required classes
	 *
	 * @since 1.1.0
	 */
	public function includes() {

		// Background export must be loaded all the time, because
		// otherwise background jobs simply won't work
		require_once( $this->get_framework_path() . '/utilities/class-sv-wp-async-request.php' );
		require_once( $this->get_framework_path() . '/utilities/class-sv-wp-background-job-handler.php' );

		// handles exporting files in background
		$this->background_export = $this->load_class( '/includes/class-wc-customer-order-xml-export-suite-background-export.php', 'WC_Customer_Order_XML_Export_Suite_Background_Export' );

		// general interface for interacting with exports
		$this->export_handler = $this->load_class( '/includes/class-wc-customer-order-xml-export-suite-handler.php', 'WC_Customer_Order_XML_Export_Suite_Handler' );

		// formats definitions
		$this->formats = $this->load_class( '/includes/class-wc-customer-order-xml-export-suite-formats.php', 'WC_Customer_Order_XML_Export_Suite_Formats' );

		// export methods
		$this->methods = $this->load_class( '/includes/class-wc-customer-order-xml-export-suite-methods.php', 'WC_Customer_Order_XML_Export_Suite_Methods' );

		// handles exported file downloads
		$this->download_handler = $this->load_class( '/includes/class-wc-customer-order-xml-export-suite-download-handler.php', 'WC_Customer_Order_XML_Export_Suite_Download_Handler' );

		// handles scheduling and execution of automatic export / upload
		$this->cron = $this->load_class( '/includes/class-wc-customer-order-xml-export-suite-cron.php', 'WC_Customer_Order_XML_Export_Suite_Cron' );
	}


	/**
	 * Loads the Admin & AJAX classes
	 *
	 * @since 1.1.0
	 */
	public function admin_includes() {

		// loads the admin settings page and adds functionality to the order admin
		$this->admin = $this->load_class( '/includes/admin/class-wc-customer-order-xml-export-suite-admin.php', 'WC_Customer_Order_XML_Export_Suite_Admin' );

		// add message handler
		$this->admin->message_handler = $this->get_message_handler();
	}


	/**
	 * Loads the AJAX classes
	 *
	 * @since 2.0.0
	 */
	public function ajax_includes() {

		$this->ajax = $this->load_class( '/includes/class-wc-customer-order-xml-export-suite-ajax.php', 'WC_Customer_Order_XML_Export_Suite_AJAX' );
	}


	/**
	 * Return deprecated/removed hooks.
	 *
	 * @since 2.0.0
	 * @return array
	 */
	protected function get_deprecated_hooks() {

		return array(
			'wc_customer_order_xml_export_suite_auto_export_order_query_args' => array(
				'version'     => '2.0.0',
				'replacement' => 'wc_customer_order_xml_export_suite_query_args'
			),
			'wc_customer_order_xml_export_suite_order_ids' => array(
				'version'     => '2.0.0',
				'replacement' => 'wc_customer_order_xml_export_suite_ids'
			),
			'wc_customer_order_xml_export_suite_orders_exported' => array(
				'version'     => '2.0.0',
				'removed'     => true,
				'replacement' => 'wc_customer_order_xml_export_suite_order_exported'
			),
			'wc_customer_order_xml_export_suite_generated_xml' => array(
				'version'     => '2.0.0',
			),
		);

	}


	/**
	 * Return admin class instance
	 *
	 * @since 1.8.0
	 * @return \WC_Customer_Order_XML_Export_Suite_Admin
	 */
	public function get_admin_instance() {
		return $this->admin;
	}


	/**
	 * Return cron class instance
	 *
	 * @since 1.8.0
	 * @return \WC_Customer_Order_XML_Export_Suite_Cron
	 */
	public function get_cron_instance() {
		return $this->cron;
	}


	/**
	 * Return formats class instance
	 *
	 * @since 2.0.0
	 * @return \WC_Customer_Order_XML_Export_Suite_Formats
	 */
	public function get_formats_instance() {
		return $this->formats;
	}


	/**
	 * Return methods class instance
	 *
	 * @since 2.0.0
	 * @return \WC_Customer_Order_XML_Export_Suite_Methods
	 */
	public function get_methods_instance() {
		return $this->methods;
	}


	/**
	 * Return ajax class instance
	 *
	 * @since 2.0.0
	 * @return \WC_Customer_Order_XML_Export_Suite_AJAX
	 */
	public function get_ajax_instance() {
		return $this->ajax;
	}


	/**
	 * Return background export class instance
	 *
	 * @since 2.0.0
	 * @return \WC_Customer_Order_XML_Export_Suite_Background_Export
	 */
	public function get_background_export_instance() {
		return $this->background_export;
	}


	/**
	 * Return download handler class instance
	 *
	 * @since 2.0.0
	 * @return \WC_Customer_Order_XML_Export_Suite_Download_Handler
	 */
	public function get_download_handler_instance() {
		return $this->download_handler;
	}


	/**
	 * Return export handler class instance
	 *
	 * @since 2.0.0
	 * @return \WC_Customer_Order_XML_Export_Suite_Handler
	 */
	public function get_export_handler_instance() {
		return $this->export_handler;
	}


	/**
	 * Returns the admin notice handler instance
	 *
	 * TODO: remove this when the method gets fixed in framework {IT 2016-09-02}
	 *
	 * @since 2.0.0
	 */
	public function get_admin_notice_handler() {

		require_once( $this->get_framework_path() . '/class-sv-wc-admin-notice-handler.php' );

		return parent::get_admin_notice_handler();
	}


	/**
	 * Backwards compat for changing the visibility of admin and cron instances.
	 *
	 * @TODO Remove this as part of WC 2.7 compat {IT 2016-05-19}
	 *
	 * @since 1.8.0
	 */
	public function __get( $name ) {

		switch ( $name ) {

			case 'admin':

				/* @deprecated since 1.8.0 */
				_deprecated_function( 'wc_customer_order_xml_export_suite()->admin', '1.8.0', 'wc_customer_order_xml_export_suite()->get_admin_instance()' );
				return $this->get_admin_instance();

			case 'cron':

				/* @deprecated since 1.8.0 */
				_deprecated_function( 'wc_customer_order_xml_export_suite()->cron', '1.8.0', 'wc_customer_order_xml_export_suite()->get_cron_instance()' );
				return $this->get_cron_instance();
		}

		// you're probably doing it wrong
		trigger_error( 'Call to undefined property ' . __CLASS__ . '::' . $name, E_USER_ERROR );
		return null;
	}


	/**
	 * Load plugin text domain.
	 *
	 * @since 1.1.0
	 * @see SV_WC_Plugin::load_translation()
	 */
	public function load_translation() {

		load_plugin_textdomain( 'woocommerce-customer-order-xml-export-suite', false, dirname( plugin_basename( $this->get_file() ) ) . '/i18n/languages' );
	}


	/** Helper Methods ******************************************************/


	/**
	 * Main Customer/Order XML Export Suite Instance, ensures only one instance is/can be loaded
	 *
	 * @since 1.4.0
	 * @see wc_customer_order_xml_export_suite()
	 * @return WC_Customer_Order_XML_Export_Suite
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Log messages/errors to WooCommerce error log if error logging is enabled
	 * Note that errors with orders will be logged as order notes regardless of error logging setting
	 *
	 * @since 1.0.0
	 * @param string $message message to log
	 * @param $_ unused
	 */
	public function log( $message, $_ = null ) {

		if ( 'on' == get_option( 'wc_customer_order_xml_export_suite_debug_mode' ) ) {

			parent::log( $message );
		}
	}


	/**
	 * Returns the plugin name, localized
	 *
	 * @since 1.1.0
	 * @see SV_WC_Plugin::get_plugin_name()
	 * @return string the plugin name
	 */
	public function get_plugin_name() {

		return __( 'WooCommerce Customer/Order XML Export Suite', 'woocommerce-customer-order-xml-export-suite' );
	}


	/**
	 * Returns __FILE__
	 *
	 * @since 1.1.0
	 * @see SV_WC_Plugin::get_file()
	 * @return string the full path and filename of the plugin file
	 */
	protected function get_file() {

		return __FILE__;
	}


	/**
	 * Gets the URL to the settings page
	 *
	 * @since 1.1.0
	 * @see SV_WC_Plugin::is_plugin_settings()
	 * @param string $_ unused
	 * @return string URL to the settings page
	 */
	public function get_settings_url( $_ = '' ) {

		return admin_url( 'admin.php?page=wc_customer_order_xml_export_suite&tab=settings' );
	}


	/**
	 * Returns true if on the gateway settings page
	 *
	 * @since 1.1.0
	 * @see SV_WC_Plugin::is_plugin_settings()
	 * @return boolean true if on the settings page
	 */
	public function is_plugin_settings() {

		return ( isset( $_GET['page'] ) && 'wc_customer_order_xml_export_suite' == $_GET['page'] );
	}


	/**
	 * Returns conditional dependencies based on the FTP security selected
	 *
	 * @since 1.1.0
	 * @see SV_WC_Plugin::get_dependencies()
	 * @return array of dependencies
	 */
	protected function get_dependencies() {

		// check if FTP is one of the chosen export methods
		if ( ! in_array( 'ftp', $this->get_auto_export_methods(), true ) ) {
			return array();
		}

		$ftp_securities = $this->get_auto_export_ftp_securities();
		$dependencies   = array();

		if ( in_array( 'sftp', $ftp_securities, true ) ) {

			$dependencies[] = 'ssh2';
		}

		if ( in_array( 'ftp_ssl', $ftp_securities, true ) ) {

			$dependencies[] = 'curl';
		}

		if ( in_array( 'ftps', $ftp_securities, true ) ) {

			$dependencies[] = 'ftp';
			$dependencies[] = 'openssl';
		}

		return $dependencies;
	}


	/**
	 * Returns conditional function dependencies based on the FTP security selected
	 *
	 * @since 1.2.0
	 * @see SV_WC_Plugin::get_function_dependencies()
	 * @return array of dependencies
	 */
	protected function get_function_dependencies() {

		// check if FTP is one of the chosen export methods
		if ( ! in_array( 'ftp', $this->get_auto_export_methods(), true ) ) {
			return array();
		}

		$ftp_securities = $this->get_auto_export_ftp_securities();

		if ( in_array( 'ftps', $ftp_securities, true ) ) {

			return array( 'ftp_ssl_connect' );
		}

		return array();
	}


	/**
	 * Get auto export methods used by export types
	 *
	 * @since 2.0.0
	 * @return array
	 */
	private function get_auto_export_methods() {

		$export_types   = array( 'customers', 'orders' );
		$export_methods = array();

		foreach ( $export_types as $export_type ) {
			$export_methods[] = get_option( 'wc_customer_order_xml_export_suite_' . $export_type . '_auto_export_method' );
		}

		return $export_methods;
	}


	/**
	 * Get auto export methods used by export types
	 *
	 * @since 2.0.0
	 * @return array
	 */
	private function get_auto_export_ftp_securities() {

		$export_types = array( 'customers', 'orders' );
		$securities   = array();

		foreach ( $export_types as $export_type ) {
			$securities[] = get_option( 'wc_customer_order_xml_export_suite_' . $export_type . '_ftp_security' );
		}

		return $securities;
	}


	/**
	 * Gets the plugin documentation url
	 *
	 * @since 1.5.0
	 * @see SV_WC_Plugin::get_documentation_url()
	 * @return string documentation URL
	 */
	public function get_documentation_url() {
		return 'http://docs.woothemes.com/document/woocommerce-customer-order-xml-export-suite/';
	}


	/**
	 * Gets the plugin support URL
	 *
	 * @since 1.5.0
	 * @see SV_WC_Plugin::get_support_url()
	 * @return string
	 */
	public function get_support_url() {
		return 'http://support.woothemes.com/';
	}


	/** Lifecycle Methods ******************************************************/


	/**
	 * Install default settings
	 *
	 * @since 1.0.0
	 * @see SV_WC_Plugin::install()
	 */
	protected function install() {

		// install default settings
		require_once( $this->get_plugin_path() . '/includes/admin/class-wc-customer-order-xml-export-suite-admin-settings.php' );

		foreach ( WC_Customer_Order_XML_Export_Suite_Admin_Settings::get_settings() as $section => $settings ) {

			foreach ( $settings as $setting ) {

				if ( isset( $setting['default'] ) ) {

					update_option( $setting['id'], $setting['default'] );
				}
			}
		}

		// install default custom format settings
		require_once( $this->get_plugin_path() . '/includes/admin/class-wc-customer-order-xml-export-suite-admin-custom-format-builder.php' );

		foreach ( WC_Customer_Order_XML_Export_Suite_Admin_Custom_Format_Builder::get_settings() as $section => $settings ) {

			foreach ( $settings as $setting ) {

				if ( isset( $setting['default'] ) ) {

					update_option( $setting['id'], $setting['default'] );
				}
			}
		}

		self::create_files();
	}


	/**
	 * Create files/directories
	 *
	 * Based on WC_Install::create_files()
	 *
	 * @since 2.0.0
	 */
	private static function create_files() {

		// Install files and folders for exported files and prevent hotlinking
		$upload_dir      = wp_upload_dir();
		$download_method = get_option( 'woocommerce_file_download_method', 'force' );

		$files = array(
			array(
				'base'    => $upload_dir['basedir'] . '/xml_exports',
				'file'    => 'index.html',
				'content' => ''
			),
		);

		if ( 'redirect' !== $download_method ) {
			$files[] = array(
				'base'    => $upload_dir['basedir'] . '/xml_exports',
				'file'    => '.htaccess',
				'content' => 'deny from all'
			);
		}

		foreach ( $files as $file ) {

			if ( wp_mkdir_p( $file['base'] ) && ! file_exists( trailingslashit( $file['base'] ) . $file['file'] ) ) {

				if ( $file_handle = @fopen( trailingslashit( $file['base'] ) . $file['file'], 'w' ) ) {

					fwrite( $file_handle, $file['content'] );
					fclose( $file_handle );
				}
			}
		}
	}


	/**
	 * Upgrade
	 *
	 * @since 1.1.0
	 * @see SV_WC_Plugin::install()
	 */
	protected function upgrade( $installed_version ) {

		// upgrade to version 1.1
		if ( version_compare( $installed_version, '1.1', '<' ) ) {

			// wc_customer_order_xml_export_suite_export_file_name > wc_customer_order_xml_export_suite_orders_filename
			$export_filename = get_option( 'wc_customer_order_xml_export_suite_export_file_name' );
			delete_option( 'wc_customer_order_xml_export_suite_export_file_name' );

			// wc_customer_order_xml_export_suite_auto_export_orders > wc_customer_order_xml_export_suite_auto_export_method ~ `post` > `http_post`, `no` > `disabled`
			$auto_export_method = get_option( 'wc_customer_order_xml_export_suite_auto_export_orders' );
			delete_option( 'wc_customer_order_xml_export_suite_auto_export_orders' );

			if ( 'post' == $auto_export_method ) {

				$auto_export_method = 'http_post';

			} elseif ( 'no' == $auto_export_method ) {

				$auto_export_method = 'disabled';
			}

			// wc_customer_order_xml_export_suite_auto_export_pending, etc > wc_customer_order_xml_export_suite_auto_export_statuses ~ simple array of order statuses to include in export
			$order_statuses = array( 'pending', 'on-hold', 'processing', 'completed', 'failed', 'cancelled', 'refunded' );
			foreach ( $order_statuses as $key => $order_status ) {

				$option_key = "wc_customer_order_xml_export_suite_auto_export_{$order_status}";

				if ( 'no' == get_option( $option_key ) ) {
					unset( $order_statuses[ $key ] );
				}

				delete_option( $option_key );
			}

			// wc_customer_order_xml_export_suite_log_errors (yes/no) > wc_customer_order_xml_export_suite_debug_mode (on/off)
			$debug_mode = ( 'yes' == get_option( 'wc_customer_order_xml_export_suite_log_errors' ) ) ? 'on' : 'off';
			delete_option( 'wc_customer_order_xml_export_suite_log_errors' );

			// initial path wc_customer_order_xml_export_suite_ftp_initial_path > wc_customer_order_xml_export_suite_ftp_path
			$initial_path = get_option( 'wc_customer_order_xml_export_suite_ftp_initial_path' );
			delete_option( 'wc_customer_order_xml_export_suite_ftp_initial_path' );

			// add new options
			update_option( 'wc_customer_order_xml_export_suite_orders_filename', $export_filename );
			update_option( 'wc_customer_order_xml_export_suite_auto_export_method', $auto_export_method );
			update_option( 'wc_customer_order_xml_export_suite_auto_export_statuses', $order_statuses );
			update_option( 'wc_customer_order_xml_export_suite_debug_mode', $debug_mode );
			update_option( 'wc_customer_order_xml_export_suite_ftp_path', $initial_path );
		}

		// upgrade to 1.1.2
		if ( version_compare( $installed_version, '1.1.2', '<' ) ) {

			// wc_customer_order_xml_export_suite_passive_mode > wc_customer_order_xml_export_suite_ftp_passive_mode
			update_option( 'wc_customer_order_xml_export_suite_ftp_passive_mode', get_option( 'wc_customer_order_xml_export_suite_passive_mode' ) );
			delete_option( 'wc_customer_order_xml_export_suite_passive_mode' );
		}

		// upgrade to 1.2.4
		if ( version_compare( $installed_version, '1.2.4', '<' ) ) {

			// update order statuses for 2.2+
			$order_status_options = array( 'wc_customer_order_xml_export_suite_statuses', 'wc_customer_order_xml_export_suite_auto_export_statuses' );

			foreach ( $order_status_options as $option ) {

				$order_statuses     = (array) get_option( $option );
				$new_order_statuses = array();

				foreach ( $order_statuses as $status ) {
					$new_order_statuses[] = 'wc-' . $status;
				}

				update_option( $option, $new_order_statuses );
			}
		}


		// upgrade to 2.0.0
		if ( version_compare( $installed_version, '2.0.0', '<' ) ) {

			// install defaults for customer auto-export settings, this must be done before
			// updating renamed options, otherwise defaults will override the previously set options
			require_once( $this->get_plugin_path() . '/includes/admin/class-wc-customer-order-xml-export-suite-admin-settings.php' );

			foreach ( WC_Customer_Order_XML_Export_Suite_Admin_Settings::get_settings( 'customers' ) as $setting ) {

				if ( isset( $setting['default'] ) ) {

					update_option( $setting['id'], $setting['default'] );
				}
			}

			// set up xml exports folder
			self::create_files();

			// install defaults for new settings
			update_option( 'wc_customer_order_xml_export_suite_orders_add_note', 'yes' );
			update_option( 'wc_customer_order_xml_export_suite_orders_auto_export_trigger', 'schedule' );

			// make sure existing installations use legacy format, so that the upgrade doesn't break anything
			update_option( 'wc_customer_order_xml_export_suite_orders_format', 'legacy' );
			update_option( 'wc_customer_order_xml_export_suite_customers_format', 'legacy' );

			// rename settings
			$renamed_options = array(
				'wc_customer_order_xml_export_suite_auto_export_method'     => 'wc_customer_order_xml_export_suite_orders_auto_export_method',
				'wc_customer_order_xml_export_suite_auto_export_start_time' => 'wc_customer_order_xml_export_suite_orders_auto_export_start_time',
				'wc_customer_order_xml_export_suite_auto_export_interval'   => 'wc_customer_order_xml_export_suite_orders_auto_export_interval',
				'wc_customer_order_xml_export_suite_auto_export_statuses'   => 'wc_customer_order_xml_export_suite_orders_auto_export_statuses',
				'wc_customer_order_xml_export_suite_ftp_server'             => 'wc_customer_order_xml_export_suite_orders_ftp_server',
				'wc_customer_order_xml_export_suite_ftp_username'           => 'wc_customer_order_xml_export_suite_orders_ftp_username',
				'wc_customer_order_xml_export_suite_ftp_password'           => 'wc_customer_order_xml_export_suite_orders_ftp_password',
				'wc_customer_order_xml_export_suite_ftp_port'               => 'wc_customer_order_xml_export_suite_orders_ftp_port',
				'wc_customer_order_xml_export_suite_ftp_path'               => 'wc_customer_order_xml_export_suite_orders_ftp_path',
				'wc_customer_order_xml_export_suite_ftp_security'           => 'wc_customer_order_xml_export_suite_orders_ftp_security',
				'wc_customer_order_xml_export_suite_ftp_passive_mode'       => 'wc_customer_order_xml_export_suite_orders_ftp_passive_mode',
				'wc_customer_order_xml_export_suite_http_post_url'          => 'wc_customer_order_xml_export_suite_orders_http_post_url',
				'wc_customer_order_xml_export_suite_email_recipients'       => 'wc_customer_order_xml_export_suite_orders_email_recipients',
				'wc_customer_order_xml_export_suite_email_subject'          => 'wc_customer_order_xml_export_suite_orders_email_subject',
			);

			foreach ( $renamed_options as $old => $new ) {

				update_option( $new, get_option( $old ) );
				delete_option( $old );
			}

			// install default custom column format settings
			require_once( $this->get_plugin_path() . '/includes/admin/class-wc-customer-order-xml-export-suite-admin-custom-format-builder.php' );

			foreach ( WC_Customer_Order_XML_Export_Suite_Admin_Custom_Format_Builder::get_settings() as $section => $settings ) {

				foreach ( $settings as $setting ) {

					if ( isset( $setting['default'] ) ) {

						update_option( $setting['id'], $setting['default'] );
					}
				}
			}

			// handle renamed cron schedule
			if ( $start_timestamp = wp_next_scheduled( 'wc_customer_order_xml_export_suite_auto_export_interval' ) ) {

				wp_clear_scheduled_hook( 'wc_customer_order_xml_export_suite_auto_export_interval' );

				wp_schedule_event( $start_timestamp, 'wc_customer_order_xml_export_suite_orders_auto_export_interval', 'wc_customer_order_xml_export_suite_auto_export_orders' );
			}
		}
	}


} // end \WC_Customer_Order_XML_Export_Suite class


/**
 * Returns the One True Instance of Customer/Order XML Export Suite
 *
 * @since 1.4.0
 * @return <WC_Customer_Order_XML_Export_Suite
 */
function wc_customer_order_xml_export_suite() {
	return WC_Customer_Order_XML_Export_Suite::instance();
}


// fire it up!
wc_customer_order_xml_export_suite();

} // init_woocommerce_customer_order_xml_export_suite()
