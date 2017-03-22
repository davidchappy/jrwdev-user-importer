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
 * Integration class for WooCommerce Subscriptions 2.0+
 *
 * TODO When we remove support for Subscriptions 1.5 we could merge this with the abstract parent class or move its methods into a Subscriptions Events Integration subclass {FN 2016-04-26}
 *
 * @since 1.6.0
 */
class WC_Memberships_Integration_Subscriptions extends WC_Memberships_Integration_Subscriptions_Abstract {


	/** @var \WC_Memberships_Integration_Subscriptions_CLI instance */
	protected $cli;


	/**
	 * Constructor
	 *
	 * @since 1.6.0
	 */
	public function __construct() {

		$this->subscription_meta_key_name = '_subscription_id';

		parent::__construct();

		// WP CLI support
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->cli = wc_memberships()->load_class( '/includes/integrations/subscriptions/class-wc-memberships-integration-subscriptions-cli.php', 'WC_Memberships_Integration_Subscriptions_CLI' );
		}

		// Subscriptions events
		add_action( 'woocommerce_subscription_status_updated', array( $this, 'handle_subscription_status_change' ), 10, 3 );
		add_action( 'woocommerce_subscription_date_updated',   array( $this, 'update_related_membership_dates' ), 10, 3 );
		add_action( 'trashed_post',                            array( $this, 'cancel_related_membership' ) );
		add_action( 'delete_post',                             array( $this, 'cancel_related_membership' ) );
	}


	/**
	 * Get Subscriptions WP CLI integration instance
	 *
	 * @since 1.7.0
	 * @return null|\WC_Memberships_Integration_Subscriptions_CLI
	 */
	public function get_cli_instance() {
		return $this->cli;
	}


	/**
	 * Handle Subscriptions status changes
	 *
	 * @since 1.6.0
	 * @param \WC_Subscription $subscription Subscription being changed
	 * @param string $new_subscription_status Subscription status changing to
	 * @param string $old_subscription_status Subscription status changing from
	 */
	public function handle_subscription_status_change( WC_Subscription $subscription, $new_subscription_status, $old_subscription_status ) {

		// get Memberships tied to the Subscription
		$user_memberships = $this->get_memberships_from_subscription( $subscription->id );

		// bail out if no memberships found
		if ( ! $user_memberships ) {
			return;
		}

		// update status of found memberships
		foreach ( $user_memberships as $user_membership ) {

			$this->update_related_membership_status( $subscription, $user_membership, $new_subscription_status );
		}
	}


	/**
	 * Update related membership upon subscription date change
	 *
	 * @internal
	 *
	 * @since 1.6.0
	 * @param \WC_Subscription $subscription
	 * @param string $date_type
	 * @param string $datetime
	 */
	public function update_related_membership_dates( WC_Subscription $subscription, $date_type, $datetime ) {

		if ( 'end' === $date_type ) {

			$user_memberships = $this->get_memberships_from_subscription( $subscription->id );

			if ( ! $user_memberships ) {
				return;
			}

			foreach ( $user_memberships as $user_membership ) {

				$subscription_plan_id = $user_membership->get_plan_id();

				if ( $subscription_plan_id && $this->plan_grants_access_while_subscription_active( $subscription_plan_id ) ) {

					$subscription_plan  = new WC_Memberships_Integration_Subscriptions_Membership_Plan( $subscription_plan_id );

					if ( $subscription_plan->is_access_length_type( 'subscription' ) ) {
						// membership length matches subscription length
						$end_date = ! empty( $datetime ) ? $datetime : '';
					} else {
						// membership length is decoupled from subscription length
						$end_date = $subscription_plan->get_expiration_date( current_time( 'timestamp', true ), array( 'product_id' => $user_membership->get_product_id() ) );
					}

					$user_membership->set_end_date( $end_date );
				}
			}
		}
	}


	/**
	 * Cancel User Membership when connected Subscription is deleted
	 *
	 * @internal
	 *
	 * @since 1.6.0
	 * @param int $post_id Id of the \WC_Subscription post being deleted
	 */
	public function cancel_related_membership( $post_id ) {

		// bail out if the post being deleted is not a subscription
		if ( 'shop_subscription' !== get_post_type( $post_id ) ) {
			return;
		}

		$user_memberships = $this->get_memberships_from_subscription( $post_id );

		if ( ! $user_memberships ) {
			return;
		}

		// get pertaining note
		switch ( current_filter() ) {

			case 'trashed_post':
				$note = __( 'Membership cancelled because subscription was trashed.', 'woocommerce-memberships' );
			break;

			case 'delete_post':
				$note = __( 'Membership cancelled because subscription was deleted.', 'woocommerce-memberships' );
			break;

			default:
				$note = null;
			break;
		}

		// cancel Memberships and add a note
		foreach ( $user_memberships as $user_membership ) {

			$user_membership->cancel_membership( $note );
		}
	}


	/** Internal & helper methods ******************************************/


	/**
	 * Get a Subscription status
	 *
	 * @since 1.5.4
	 * @param \WC_Subscription $subscription
	 * @return string
	 */
	public function get_subscription_status( $subscription ) {
		return $subscription instanceof WC_Subscription ? $subscription->get_status() : '';
	}


	/**
	 * Get a Subscription by order_id and product_id
	 *
	 * @since 1.6.0
	 * @param int $order_id WC_Order id
	 * @param int $product_id WC_Product id
	 * @return null|\WC_Subscription Subscription object or null if not found
	 */
	public function get_order_product_subscription( $order_id, $product_id ) {

		$subscriptions = wcs_get_subscriptions_for_order( $order_id, array(
			'product_id' => $product_id,
		) );

		$subscription = reset( $subscriptions );

		// find a subscription created from admin (no attached order, $order_id is from a WC_Subscription)
		if ( ! $subscription ) {

			$subscription = wcs_get_subscription( $order_id );
		}

		return $subscription;
	}


	/**
	 * Get a Subscription from a User Membership
	 *
	 * @since 1.6.0
	 * @param int|\WC_Memberships_User_Membership $user_membership Membership object or id
	 * @return null|\WC_Subscription The Subscription object, null if not found
	 */
	public function get_subscription_from_membership( $user_membership ) {

		$subscription_id = $this->get_user_membership_subscription_id( $user_membership );

		if ( ! $subscription_id ) {
			return null;
		}

		return wcs_get_subscription( $subscription_id );
	}


	/**
	 * Get User Memberships from a Subscription
	 *
	 * @since 1.6.0
	 * @param int|\WC_Subscription $subscription Subscription post object or id
	 * @return \WC_Memberships_User_Membership[] Array of user membership objects or empty array, if none found
	 */
	public function get_memberships_from_subscription( $subscription ) {

		$user_memberships = array();
		$subscription_id  = $this->get_subscription_id( $subscription );

		if ( ! $subscription_id ) {
			return $user_memberships;
		}

		// legacy key
		$subscription_key = wcs_get_old_subscription_key( wcs_get_subscription( $subscription_id ) );

		$user_membership_ids = new WP_Query( array(
			'post_type'        => 'wc_user_membership',
			'post_status'      => array_keys( wc_memberships_get_user_membership_statuses() ),
			'fields'           => 'ids',
			'nopaging'         => true,
			'suppress_filters' => 1,
			'meta_query'       => array(
				'relation' => 'OR',
				array(
					'key'   => '_subscription_id',
					'value' => $subscription_id,
					'type' => 'numeric',
				),
				array(
					'key'   => '_subscription_key',
					'value' => $subscription_key,
				),
			),
		) );

		if ( ! empty( $user_membership_ids->posts ) ) {

			$user_memberships = array();

			foreach ( $user_membership_ids->posts as $user_membership_id ) {

				$user_memberships[] = wc_memberships_get_user_membership( $user_membership_id );

				// ensure the `_subscription_id` meta exists (Subscriptions v2.0+)
				if ( ! metadata_exists( 'post', $user_membership_id, '_subscription_id' ) ) {
					update_post_meta( $user_membership_id, '_subscription_id', $subscription_id );
				}

				// delete the legacy `_subscription_key` meta (Subscriptions v1.5.x)
				if ( metadata_exists( 'post', $user_membership_id, '_subscription_key' ) ) {
					delete_post_meta( $user_membership_id, '_subscription_key' );
				}
			}
		}

		return $user_memberships;
	}


	/**
	 * Check if a Subscription associated to a Membership is renewable
	 *
	 * @since 1.6.0
	 * @param \WC_Subscription $subscription Subscription
	 * @param \WC_Memberships_User_Membership $user_membership User Membership
	 * @return bool
	 */
	public function is_subscription_linked_to_membership_renewable( $subscription, $user_membership ) {

		$is_renewable    = false;
		$user_membership = new WC_Memberships_Integration_Subscriptions_User_Membership( $user_membership->post );

		if (      $user_membership
		     &&   $user_membership->can_be_renewed()
		     && ! $user_membership->has_installment_plan()
			 &&   wcs_can_user_resubscribe_to( $subscription, $user_membership->get_user_id() ) ) {

		     	$is_renewable = true;
		}

		return $is_renewable;
	}


	/**
	 * Get a Subscription event date or time
	 *
	 * @since 1.6.0
	 * @param \WC_Subscription $subscription The Subscription to get the event for
	 * @param string $event The event to retrieve a date/time for
	 * @param string $format 'timestamp' for timestamp output or 'mysql' for date (default)
	 * @return int|string
	 */
	protected function get_subscription_event( $subscription, $event, $format = 'mysql' ) {

		$date = '';

		// sanity check
		if ( ! $subscription instanceof WC_Subscription ) {
			return $date;
		}

		$date = $subscription->get_date( $event );

		return 'timestamp' === $format && ! empty( $date ) ? strtotime( $date ) : $date;
	}


}
