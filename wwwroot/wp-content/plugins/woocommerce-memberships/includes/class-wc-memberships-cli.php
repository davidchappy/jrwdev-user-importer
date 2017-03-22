<?php
/**
 * WooCommerce Memberships
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@skyverge.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade WooCommerce Memberships to newer
 * versions in the future. If you wish to customize WooCommerce Memberships for your
 * needs please refer to http://docs.woothemes.com/document/woocommerce-memberships/ for more information.
 *
 * @package   WC-Memberships/Classes
 * @author    SkyVerge
 * @copyright Copyright (c) 2014-2016, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

/**
 * Manage WooCommerce Memberships with WP CLI
 *
 * @link https://wp-cli.org/
 * @see WC_CLI
 *
 * @since 1.7.0
 */
class WC_Memberships_CLI extends WP_CLI_Command {
}

include_once __DIR__ . '/cli/class-wc-memberships-cli-command.php';
include_once __DIR__ . '/cli/class-wc-memberships-cli-membership-plan.php';
include_once __DIR__ . '/cli/class-wc-memberships-cli-user-membership.php';

WP_CLI::add_command( 'wc memberships',            'WC_Memberships_CLI' );
WP_CLI::add_command( 'wc memberships membership', 'WC_Memberships_CLI_User_Membership' );
WP_CLI::add_command( 'wc memberships plan',       'WC_Memberships_CLI_Membership_Plan' );
