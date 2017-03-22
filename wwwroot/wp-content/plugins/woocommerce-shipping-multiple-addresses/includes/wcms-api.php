<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}

class WC_MS_API {

    public function __construct() {
        add_filter( 'woocommerce_api_order_response', array( $this, 'add_shipping_packages' ), 10, 4 );
    }

    /**
     * Add shipping packages to the order response array
     *
     * @param array         $order_data
     * @param WC_Order      $order
     * @param array         $fields
     * @param WC_API_Server $server
     * @return array
     */
    public function add_shipping_packages( $order_data, $order, $fields, $server ) {
        $methods    = $order->shipping_methods;
        $packages   = $order->wcms_packages;
        $multiship  = count( $packages ) > 1;

        $order_data['multiple_shipping'] = $multiship;

        if ( !$multiship ) {
            return $order_data;
        }

        $shipping_packages = array();
        foreach ( $packages as $i => $package ) {
            $package['contents'] = array_values( $package['contents'] );
            foreach ( $package['contents'] as $item_key => $item ) {
                $package['contents'][ $item_key ]['name'] = $item['data']->get_title();

                unset( $package['contents'][ $item_key ]['data'], $package['full_address'] );

                $shipping_packages[] = $package;
            }

        }

        $order_data['shipping_packages'] = $shipping_packages;

        return $order_data;
    }

}