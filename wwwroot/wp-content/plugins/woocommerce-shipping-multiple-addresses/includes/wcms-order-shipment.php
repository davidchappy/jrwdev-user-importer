<?php

if ( ! defined( 'ABSPATH' ) )
    exit; // Exit if accessed directly

class WC_MS_Order_Shipment {

    private $wcms;

    public function __construct( WC_Ship_Multiple $wcms ) {
        $this->wcms = $wcms;

        add_action( 'woocommerce_order_status_changed', array( $this, 'inherit_order_status' ), 1, 3 );
    }

    public function create_from_package( $package, $package_index, $order_id ) {
        global $wpdb;

        // Give plugins the opportunity to create the shipment themselves
        if ( $shipment_id = apply_filters( 'wc_ms_create_shipment', null, $this ) ) {
            return $shipment_id;
        }

        try {
            $order = wc_get_order( $order_id );
            $packages_shipping_ids = get_post_meta( $order_id, '_packages_shipping_ids', true );

            // Start transaction if available
            $wpdb->query( 'START TRANSACTION' );

            $customer_notes = array();
            $package_note  = get_post_meta( $order_id, '_note_'. $package_index, true );
            $package_delivery_date = get_post_meta( $order_id, '_date_'. $package_index, true );

            if ( !empty( $order->post->post_excerpt ) ) {
                $customer_notes[] = $order->post->post_excerpt;
            }

            if ( !empty( $package_note ) ) {
                $customer_notes[] = __('Note', 'wc_shipping_multiple_address') .': '. $package_note;
            }

            if ( !empty( $package_delivery_date ) ) {
                $customer_notes[] = __('Shipping Date', 'wc_shipping_multiple_address') .': '. $package_delivery_date;
            }

            $shipment_data = array(
                'status'        => apply_filters( 'wc_ms_default_shipment_status', 'pending' ),
                'parent'        => $order_id,
                'customer_id'   => $order->customer_user, 
                'customer_note' => implode( "<br/>", $customer_notes ),
                'created_via'   => 'Multi-Shipping'
            );

            $shipment_id = $this->create_shipment( $shipment_data );
            $shipment    = wc_get_order( $shipment_id );

            if ( is_wp_error( $shipment_id ) ) {
                return $shipment_id;
            } else {
                do_action( 'wc_ms_new_shipment', $shipment_id );
            }

            // Store the line items
            foreach ( $package['contents'] as $item_key => $values ) {
                $item_id = $shipment->add_product(
                    $values['data'],
                    $values['quantity'],
                    array(
                        'variation' => $values['variation'],
                        'totals'    => array(
                            'subtotal'     => $values['line_subtotal'],
                            'subtotal_tax' => $values['line_subtotal_tax'],
                            'total'        => $values['line_total'],
                            'tax'          => $values['line_tax'],
                            'tax_data'     => $values['line_tax_data'] // Since 2.2
                        )
                    )
                );

                if ( ! $item_id ) {
                    throw new Exception( sprintf( __( 'Error %d: Unable to create shipment. Please try again.', 'wc_shipping_multiple_address' ), 402 ) );
                }

                // Allow plugins to add order item meta
                do_action( 'wc_ms_add_shipment_item_meta', $item_id, $values, $item_key );
                do_action( 'woocommerce_add_order_item_meta', $item_id, $values, $item_key );
            }

            // Store shipping for all packages
            $rates              = get_post_meta( $order_id, '_shipping_rates', true );
            $shipping_methods   = get_post_meta( $order_id, '_shipping_methods', true );
            $shipping_total     = 0;
            $shipping_tax_total = 0;

            if ( isset( $rates[ $package_index ][ $shipping_methods[ $package_index ]['id'] ] ) ) {
                $rate_id    = $shipping_methods[ $package_index ]['id'];
                $rate       = $rates[ $package_index ];

                $item_id = $shipment->add_shipping( $rate[ $rate_id ] );
                $shipping_total     = $rate[ $rate_id ]->cost;
                $shipping_tax_total = array_sum( $rate[ $rate_id ]->taxes );

                if ( ! $item_id ) {
                    throw new Exception( sprintf( __( 'Error %d: Unable to create shipment. Please try again.', 'wc_shipping_multiple_address' ), 404 ) );
                }

                // Allows plugins to add order item meta to shipping
                do_action( 'wc_ms_add_shipping_shipment_item', $shipment_id, $item_id, $package_index );
            }


            // Store tax rows
            $taxes = array();
            $tax_total = 0;
            foreach ( $package['contents'] as $line_item ) {
                if ( !empty( $line_item['line_tax_data']['total'] ) ) {
                    foreach ( $line_item['line_tax_data']['total'] as $tax_rate_id => $tax_amount ) {
                        if ( !isset( $taxes[ $tax_rate_id ] ) ) {
                            $taxes[ $tax_rate_id ] = 0;
                        }

                        $taxes[ $tax_rate_id ] += $tax_amount;
                        $tax_total += $tax_amount;
                    }
                }
            }

            foreach ( $taxes as $tax_rate_id => $amount  ) {
                if ( $tax_rate_id && ! $shipment->add_tax( $tax_rate_id, $amount ) && apply_filters( 'woocommerce_cart_remove_taxes_zero_rate_id', 'zero-rated' ) !== $tax_rate_id ) {
                    throw new Exception( sprintf( __( 'Error %d: Unable to create shipment. Please try again.', 'wc_shipping_multiple_address' ), 405 ) );
                }
            }

            // calculate total
            $shipment_total = max( 0, apply_filters( 'wc_ms_shipment_calculated_total', round( $package['contents_cost'] + $tax_total + $shipping_tax_total + $shipping_total, 2 ), $shipment, $package ) );

            // Billing address
            $billing_address = array();
            $meta = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value
                FROM {$wpdb->postmeta}
                WHERE post_id = %d
                AND meta_key LIKE '_billing_%%'",
                $order_id
            ), ARRAY_A);

            foreach ( $meta as $row ) {
                $field = str_replace('_billing_', '', $row['meta_key'] );
                $billing_address[ $field ] = $row['meta_value'];
            }

            // Shipping address.
            $shipping_address = array();

            foreach ( $package['destination'] as $field => $value ) {
                $shipping_address[ $field ] = $value;
            }

            $shipment->set_address( $billing_address, 'billing' );
            $shipment->set_address( $shipping_address, 'shipping' );
            $shipment->set_payment_method( $order->payment_method );

            $shipment->set_total( $shipping_total, 'shipping' );
            $shipment->set_total( $tax_total, 'tax' );
            $shipment->set_total( $shipping_tax_total, 'shipping_tax' );
            $shipment->set_total( $shipment_total );

            // Let plugins add meta
            do_action( 'wc_ms_checkout_update_shipment_meta', $shipment, $order, $package, $package_index );

            if ( WC_MS_Gifts::is_enabled() ) {
                if ( 1 == get_post_meta( $order_id, '_gift_'. $package_index, true ) ) {
                    update_post_meta( $shipment_id, '_gift', true );
                }
            }

            // If we got here, the order was created without problems!
            $wpdb->query( 'COMMIT' );

        } catch ( Exception $e ) {
            // There was an error adding order data!
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'shipment-error', $e->getMessage() );
        }

        return $shipment_id;
    }

    /**
     * Create a new Order Shipment
     *
     * @param array $args
     * @return int|WP_Error
     */
    public function create_shipment( $args ) {
        $default_args = array(
            'status'        => '',
            'customer_id'   => null,
            'customer_note' => null,
            'created_via'   => '',
            'parent'        => 0
        );

        $args           = wp_parse_args( $args, $default_args );
        $shipment_data  = array();

        if ( empty( $args['parent'] ) ) {
            return new WP_Error( 'create_shipment', __('Cannot create a shipment without an Order ID', 'wc_shipping_multiple_address') );
        }

        $order_id = $args['parent'];
        $wc_order = wc_get_order( $order_id );

        $updating                    = false;
        $shipment_data['post_type']     = 'order_shipment';
        $shipment_data['post_status']   = 'wc-' . apply_filters( 'wc_ms_default_shipment_status', 'pending' );
        $shipment_data['ping_status']   = 'closed';
        $shipment_data['post_author']   = 1;
        $shipment_data['post_password'] = $wc_order->post->post_password;
        $shipment_data['post_title']    = sprintf( __( 'Shipment &ndash; %s', 'wc_shipping_multiple_address' ), strftime( _x( '%b %d, %Y @ %I:%M %p', 'Order date parsed by strftime', 'wc_shipping_multiple_address' ) ) );
        $shipment_data['post_parent']   = $order_id;

        if ( $args['status'] ) {
            if ( ! in_array( 'wc-' . $args['status'], array_keys( wc_get_order_statuses() ) ) ) {
                return new WP_Error( 'woocommerce_invalid_order_status', __( 'Invalid shipment status', 'wc_shipping_multiple_address' ) );
            }
            $shipment_data['post_status']  = 'wc-' . $args['status'];
        }

        if ( ! is_null( $args['customer_note'] ) ) {
            $shipment_data['post_excerpt'] = $args['customer_note'];
        }

        $shipment_id = wp_insert_post( apply_filters( 'wc_ms_new_shipment_data', $shipment_data ), true );


        if ( is_wp_error( $shipment_id ) ) {
            return $shipment_id;
        }

        update_post_meta( $shipment_id, '_shipment_key', 'wc_' . apply_filters( 'wc_ms_generate_shipment_key', uniqid( 'shipment_' ) ) );
        update_post_meta( $shipment_id, '_created_via', sanitize_text_field( $args['created_via'] ) );

        $shipment = wc_get_order( $shipment_id );
        $shipment->add_order_note( 'Shipment for Order '. $wc_order->get_order_number() );

        return $shipment_id;
    }

    public function inherit_order_status( $order_id, $old_status, $new_status ) {
        global $wpdb;

        if ( get_post_type( $order_id ) != 'shop_order' ) {
            return;
        }

        $shipment_ids = self::get_by_order( $order_id );

        foreach ( $shipment_ids as $shipment_id ) {
            wp_update_post( array( 'ID' => $shipment_id, 'post_status' => 'wc-' . $new_status ) );
        }

    }

    public static function get_by_order( $order_id ) {
        global $wpdb;

        $shipment_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID
            FROM {$wpdb->posts}
            WHERE post_type = 'order_shipment'
            AND post_parent = %d",
            $order_id
        ));

        return $shipment_ids;
    }

}