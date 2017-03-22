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

defined( 'ABSPATH' ) or exit;


/**
 * Get Users Memberships from a Subscription
 *
 * Returns empty array if no User Memberships are found or Subscriptions is inactive
 *
 * @since 1.5.4
 * @param int|\WP_Post $subscription A Subscription post object or id
 * @return \WC_Memberships_User_Membership[] Array of User Membership objects or empty array if none found
 */
function wc_memberships_get_memberships_from_subscription( $subscription ) {

	$integrations = wc_memberships()->get_integrations_instance();

	if ( ! $integrations || true !== $integrations->is_subscriptions_active() ) {
		return array();
	}

	$subscriptions = $integrations->get_subscriptions_instance();

	return $subscriptions ? $subscriptions->get_memberships_from_subscription( $subscription ) : array();
}
