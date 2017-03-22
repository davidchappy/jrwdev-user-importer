<?php

if ( ! defined( 'ABSPATH' ) )
    exit; // Exit if accessed directly

class WC_MS_Checkout {

    private $wcms;

    public function __construct( WC_Ship_Multiple $wcms ) {

        $this->wcms = $wcms;

        // free shipping minimum order
        add_filter( 'woocommerce_shipping_free_shipping_is_available', array( $this, 'free_shipping_is_available_for_package' ), 10, 2 );

        add_filter( 'woocommerce_package_rates', array($this, 'remove_multishipping_from_methods'), 10, 2 );
        add_action( 'woocommerce_before_checkout_shipping_form', array( $this, 'before_shipping_form' ) );
        add_action( 'woocommerce_before_checkout_shipping_form', array( $this, 'render_user_addresses_dropdown' ) );
        add_action( 'woocommerce_before_checkout_form', array( $this, 'before_checkout_form' ) );

        add_action( 'woocommerce_after_checkout_validation', array( $this, 'checkout_validation' ) );

        add_action( 'woocommerce_add_order_item_meta', array( $this, 'store_item_id' ), 10, 3 );
        add_action( 'woocommerce_add_shipping_order_item', array( $this, 'store_shipping_item_id' ), 10, 3 );
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'checkout_process' ) );


        add_filter( 'woocommerce_order_item_meta', array( $this, 'add_item_meta' ), 10, 2 );

        // handle order review events
        add_action( 'woocommerce_checkout_update_order_review', array( $this, 'update_order_review' ) );
        add_action( 'woocommerce_calculate_totals', array( $this, 'calculate_totals' ), 10 );

        // modify a cart item's subtotal to include taxes
        add_action( 'woocommerce_cart_item_subtotal', array( $this, 'subtotal_include_taxes' ), 10, 3 );

        add_action( 'woocommerce_checkout_order_processed', array($wcms, 'clear_session') );

        // split order
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'create_order_shipments' ), 10, 2 );

        // stop WC from updating the customer's shipping address
        // instead, store it in the address book if it's a new shipping address
        add_filter( 'woocommerce_checkout_update_customer_data', array( $this, 'prevent_customer_data_update' ), 90, 2 );
        add_action( 'woocommerce_checkout_update_user_meta', array( $this, 'maybe_store_shipping_address' ), 10, 2 );
    }

    public function free_shipping_is_available_for_package( $is_available, $package ) {
        $options = get_option('woocommerce_free_shipping_settings', array());

        $min_amount = isset( $options['min_amount'] ) ? $options['min_amount'] : '' ;
        $requires   = isset( $options['requires'] ) ? $options['requires'] : '';

        if ( in_array( $requires, array( 'min_amount', 'either', 'both' ) ) && isset( $package['contents_cost'] ) ) {
            $total = $package['contents_cost'];

            if ( $total >= $min_amount ) {
                $is_available = true;
            } else {
                $is_available = false;
            }

        }

        return $is_available;
    }

    function remove_multishipping_from_methods( $rates ) {

        if ( !wcms_session_isset( 'wcms_packages' ) && isset($rates['multiple_shipping']) ) {
            unset($rates['multiple_shipping']);
        }

        return $rates;
    }

    function before_shipping_form($checkout = null) {

        if ( !$this->wcms->cart->cart_is_eligible_for_multi_shipping() ) {
            return;
        }

        $id = wc_get_page_id( 'multiple_addresses' );

        $sess_item_address = wcms_session_get( 'cart_item_addresses' );
        $sess_cart_address = wcms_session_get( 'cart_addresses' );
        $has_item_address = (!wcms_session_isset( 'cart_item_addresses' ) || empty($sess_item_address)) ? false : true;
        $has_cart_address = (!wcms_session_isset( 'cart_addresses' ) || empty($sess_cart_address)) ? false : true;
        $inline = false;

        if ( $has_item_address ) {
            $inline = 'jQuery(function() {
                    var col = jQuery("#customer_details .col-2");

                    jQuery("#shiptobilling").hide();
                    jQuery("form.checkout").find("#shiptobilling-checkbox")
                        .attr("checked", true)
                        .hide();

                    // WC2.1+
                    jQuery("form.checkout").find("#ship-to-different-address-checkbox")
                        .attr("checked", false)
                        .hide();
                    jQuery("form.checkout").find("h3#ship-to-different-address")
                        .hide();
                    jQuery("form.checkout").prepend("<h3 id=\'ship-to-multiple\'>'. __('Shipping Address', 'wc_shipping_multiple_address') .'</h3>");

                    jQuery("form.checkout").find(".shipping_address").remove();

                    jQuery(\'<p><a href=\"'. get_permalink($id) .'\" class=\"button button-primary\">'. __( 'Modify/Add Address', 'wc_shipping_multiple_address' ) .'</a></p>\').insertAfter("#customer_details .col-2 h3:first");
                });';

        } elseif ( $has_cart_address ) {
            $inline = 'jQuery(function() {
                    var col = jQuery("#customer_details .col-2");

                    jQuery(col).find("#shiptobilling-checkbox")
                        .attr("checked", true)
                        .hide();

                    // WC2.1+
                    jQuery("form.checkout").find("#ship-to-different-address-checkbox")
                        .attr("checked", false)
                        .hide();
                    jQuery("form.checkout").find("h3#ship-to-different-address")
                        .hide();
                    jQuery("form.checkout").prepend("<h3 id="ship-to-multiple">'. __('Shipping Address', 'wc_shipping_multiple_address') .'</h3>");

                    jQuery("form.checkout").find(".shipping_address").remove();

                    jQuery(\'<p><a href=\"'. add_query_arg( 'cart', 1, get_permalink($id)) .'\" class=\"button button-primary\">'. __( 'Modify/Add Address', 'wc_shipping_multiple_address' ) .'</a></p>\').insertAfter("#customer_details .col-2 h3:first");

                });';

        }

        if ( $inline ) {
            wc_enqueue_js( $inline );
        }
    }

    public function render_user_addresses_dropdown() {

        //if ( !$this->wcms->cart_is_eligible_for_multi_shipping() ) {
            $addresses = $this->wcms->address_book->get_user_addresses( wp_get_current_user() );

            if ( count( $addresses ) ):
                ?>
                <p id="ms_shipping_addresses_field" class="form-row form-row-wide ms-addresses-field">
                    <label class=""><?php _e('Stored Addresses', 'wc_multiple_shipping_address'); ?></label>
                    <select class="" id="ms_addresses">
                        <option value=""><?php _e('Select an address to use...', 'wc_shipping_multiple_address'); ?></option>
                        <?php
                        foreach ( $addresses as $key => $address ) {
                            $formatted_address = $address['shipping_first_name'] .' '. $address['shipping_last_name'] .', '. $address['shipping_address_1'] .', '. $address['shipping_city'];
                            echo '<option value="'. $key .'"';
                            foreach ( $address as $key => $value ) {
                                echo ' data-'. $key .'="'. esc_attr( $value ) .'"';
                            }
                            echo '>'. $formatted_address .'</option>';
                        }
                        ?>
                    </select>
                </p>
            <?php
            endif;
        //}
    }

    public function before_checkout_form() {

        $sess_item_address  = wcms_session_get( 'cart_item_addresses' );
        $has_item_address   = (!wcms_session_isset( 'cart_item_addresses' ) || empty( $sess_item_address )) ? false : true;

        if ( !$has_item_address && WC()->cart->needs_shipping() )  {
            $css        = 'style="display:none;"';
            $data       = 0;
            $page_id    = wc_get_page_id( 'multiple_addresses' );

            if ( $this->wcms->is_multiship_enabled() && $this->wcms->cart->cart_is_eligible_for_multi_shipping() ) {
                $css = '';
                $data = 1;
            } else {
                // clear all session so we don't use old cart addresses in case
                // the customer adds more valid products to the cart
                $this->wcms->clear_session();
            }

            echo '
                <div id="wcms_message" '. $css .'>
                    <p class="woocommerce-info woocommerce_message" id="wcms_message" '. $css .' data-allowed="'. $data .'">
                        '. WC_Ship_Multiple::$lang['notification'] .'
                        <a class="button" href="'. get_permalink($page_id) .'">'. WC_Ship_Multiple::$lang['btn_items'] .'</a>
                    </p>
                </div>';
        }

    }

    public function store_item_id( $item_id, $values, $cart_key ) {
        global $wpdb;

        // get the order id
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT order_id
            FROM {$wpdb->prefix}woocommerce_order_items
            WHERE order_item_id = %d",
            $item_id
        ));

        if ( !$order_id ) {
            return;
        }

        $item_ids = get_post_meta( $order_id, '_packages_item_ids', true );

        if ( !is_array( $item_ids ) ) {
            $item_ids = array();
        }

        $item_ids[ $cart_key ] = $item_id;

        update_post_meta( $order_id, '_packages_item_ids', $item_ids );

    }

    public function store_shipping_item_id( $order_id, $item_id, $package_key ) {
        $packages = wcms_session_get( 'wcms_packages' );

        $shipping_ids = get_post_meta( $order_id, '_packages_shipping_ids', true );

        if ( !is_array( $shipping_ids ) ) {
            $shipping_ids = array();
        }

        $shipping_ids[ $package_key ] = $item_id;

        update_post_meta( $order_id, '_packages_shipping_ids', $shipping_ids );
    }

    public function checkout_process($order_id) {

        $sess_item_address = wcms_session_get( 'cart_item_addresses' );
        $has_item_address = (!wcms_session_isset( 'cart_item_addresses' ) || empty($sess_item_address)) ? false : true;

        do_action( 'wc_ms_before_checkout_process', $order_id );

        $packages = WC()->cart->get_shipping_packages();

        $sess_item_address  = wcms_session_isset( 'cart_item_addresses' ) ? wcms_session_get( 'cart_item_addresses' ) : false;
        $sess_packages      = wcms_session_isset( 'wcms_packages' ) ? wcms_session_get( 'wcms_packages' ) : false;
        $sess_methods       = wcms_session_isset( 'shipping_methods' ) ? wcms_session_get( 'shipping_methods' ) : false;
        $sess_rates         = wcms_session_isset( 'wcms_package_rates' ) ? wcms_session_get( 'wcms_package_rates' ) : false;

        // Allow outside code to modify session data one last time
        $sess_item_address  = apply_filters( 'wc_ms_checkout_session_item_address', $sess_item_address );
        $sess_packages      = apply_filters( 'wc_ms_checkout_session_packages', $sess_packages );
        $sess_methods       = apply_filters( 'wc_ms_checkout_session_methods', $sess_methods);
        $sess_rates         = apply_filters( 'wc_ms_checkout_session_rates', $sess_rates);

        if ( $has_item_address ) {
            update_post_meta( $order_id, '_multiple_shipping', 'yes' );
        }

        // update the taxes
        $packages       = $this->calculate_taxes( null, $packages , true);
        $sess_packages  = $this->calculate_taxes( null, $sess_packages, true );

        if ( $packages ) {
            update_post_meta( $order_id, '_shipping_packages', $packages );
        }

        if ($sess_item_address !== false && !empty($sess_item_address)) {
            update_post_meta( $order_id, '_shipping_addresses', $sess_item_address );
            //wcms_session_delete( 'cart_item_addresses' );

            if ( $sess_packages ) {
                if ( $has_item_address ) {
                    $shipping_address = array(
                        'first_name'    => '',
                        'last_name'     => '',
                        'company'       => '',
                        'address_1'     => '',
                        'address_2'     => '',
                        'city'          => '',
                        'postcode'      => '',
                        'country'       => '',
                        'state'         => ''
                    );

                    if ( count( $sess_packages ) == 1 ) {
                        $current_package = current( $sess_packages );
                        $shipping_address = $current_package['destination'];
                    }

                    // remove the shipping address
                    update_post_meta( $order_id, '_shipping_first_name', $shipping_address['first_name'] );
                    update_post_meta( $order_id, '_shipping_last_name', $shipping_address['last_name'] );
                    update_post_meta( $order_id, '_shipping_company', $shipping_address['company'] );
                    update_post_meta( $order_id, '_shipping_address_1', $shipping_address['address_1'] );
                    update_post_meta( $order_id, '_shipping_address_2', $shipping_address['address_2'] );
                    update_post_meta( $order_id, '_shipping_city', $shipping_address['city'] );
                    update_post_meta( $order_id, '_shipping_postcode', $shipping_address['postcode'] );
                    update_post_meta( $order_id, '_shipping_country', $shipping_address['country'] );
                    update_post_meta( $order_id, '_shipping_state', $shipping_address['state'] );
                }
            }

        }

        if ( $sess_packages !== false && !empty($sess_packages) && $has_item_address ) {
            update_post_meta( $order_id, '_wcms_packages', $sess_packages);
        }

        if ( $sess_methods !== false && !empty($sess_methods) && $has_item_address ) {
            $methods = $sess_methods;
            update_post_meta( $order_id, '_shipping_methods', $methods );
        } else {
            $order = wc_get_order( $order_id );

            $methods = $order->get_shipping_methods();
            $ms_methods = array();

            if ( $sess_packages ) {
                foreach ( $sess_packages as $pkg_idx => $package ) {
                    foreach ( $methods as $method ) {
                        $ms_methods[ $pkg_idx ] = array(
                            'id'    => $method['method_id'],
                            'label' => $method['name']
                        );
                        continue 2;
                    }
                }
            }

            update_post_meta( $order_id, '_shipping_methods', $ms_methods );

        }

        if ( $sess_rates !== false ) {
            update_post_meta( $order_id, '_shipping_rates', $sess_rates );
        }

        do_action( 'wc_ms_after_checkout_process', $order_id );
    }

    public function checkout_validation( $post ) {

        if ( empty($post['shipping_method']) || $post['shipping_method'] == 'multiple_shipping' || ( is_array($post['shipping_method']) && count($post['shipping_method']) > 1 ) ) {
            $packages   = wcms_session_get('wcms_packages');
            $has_empty  = false;

            foreach ( $packages as $package ) {
                if ( isset($package['bundled_by']) && !empty($package['bundled_by']) ) {
                    continue;
                }

                if ( $this->wcms->is_address_empty( $package['destination'] ) ) {
                    $has_empty = true;
                }
            }

            if ( $has_empty ) {
                if ( function_exists('wc_add_notice') ) {
                    wc_add_notice( __( 'One or more items has no shipping address.', 'wc_followup_emails' ), 'error' );
                } else {
                    WC()->add_error( __( 'One or more items has no shipping address.', 'wc_followup_emails' ) );
                }

            }

        }

    }

    public function add_item_meta( $meta, $values ) {

        $packages   = wcms_session_get( 'wcms_packages' );
        $methods    = wcms_session_isset( 'shipping_methods' ) ? wcms_session_get( 'shipping_methods' ) : false;

        if ( $methods !== false && !empty($methods) ) {
            if ( isset($values['package_idx']) && isset($packages[$values['package_idx']]) ) {
                $meta->add( 'Shipping Method', $methods[$values['package_idx']]['label']);
            }
        }

    }

    public function update_order_review($post) {
        // #39: Not processing single-package shipment causes an infinite loop - check for an empty session instead
        //if (! wcms_session_isset( 'wcms_packages' ) || count( wcms_session_get( 'wcms_packages' ) ) == 1 ) {
        if (! wcms_session_isset( 'wcms_packages' ) || empty( wcms_session_get( 'wcms_packages' ) ) ) {
		    return;
	    }

        $ship_methods   = array();
        $data           = array();
        $field          = (function_exists('wc_add_notice')) ? 'shipping_method' : 'shipping_methods';
        parse_str($post, $data);

        if (isset($data[$field]) && is_array($data[$field])) {
            foreach ($data[$field] as $x => $method) {
                $ship_methods[$x] = array( 'id' => $method, 'label' => $method);
            }

            wcms_session_set( 'shipping_methods', $ship_methods );
        }
    }

    function calculate_totals($cart) {

        if (isset($_POST['action']) && $_POST['action'] == 'woocommerce_update_shipping_method') {
	        return $cart;
        }

        $shipping_total     = 0;
        $shipping_taxes     = array();

        if (! wcms_session_isset( 'wcms_packages' )) return $cart;
        if (! wcms_session_isset( 'shipping_methods' )) return $cart;

        $methods    = wcms_session_get( 'shipping_methods' );
        $packages   = wcms_session_get( 'wcms_packages' );
        $rates      = array();

        if ( ! $packages ) {
            $packages = WC()->cart->get_shipping_packages();
            WC()->shipping->calculate_shipping( $packages );
        }

        $packages = WC()->shipping->get_packages();

        foreach ($packages as $x => $package) {
            $chosen = isset( $methods[ $x ] ) ? $methods[ $x ]['id'] : '';

            if ( $chosen ) {
                WC()->customer->calculated_shipping( true );
                WC()->customer->set_shipping_location(
                    $package['destination']['country'],
                    $package['destination']['state'],
                    $package['destination']['postcode']
                );

                $ship = $chosen;

                if ( isset($package['rates'] ) ) {
                    if ( !isset( $package['rates'][ $ship ] ) ) {
                        $rate = wcms_get_cheapest_shipping_rate( $package['rates'] );

                        if ( $rate ) {
                            $ship = $rate['id'];
                        }
                    }

                    if ( isset( $package['rates'][ $ship ]) ) {
                        $rate        = $package['rates'][ $ship ];
                        $rates[ $x ] = $package['rates'];
                        $shipping_total += $rate->cost;

                        // calculate tax
                        foreach ( array_keys( $shipping_taxes + $rate->taxes ) as $key ) {
                            $shipping_taxes[ $key ] = ( isset( $rate->taxes[ $key ] ) ? $rate->taxes[ $key ] : 0 ) + ( isset( $shipping_taxes[ $key ] ) ? $shipping_taxes[ $key ] : 0 );
                        }
                    }
                }

            }

            $packages[ $x ] = $package;

        }

        $cart->shipping_taxes       = $shipping_taxes;
        $cart->shipping_total       = $shipping_total;
        $cart->shipping_tax_total   = (is_array($shipping_taxes)) ? array_sum($shipping_taxes) : 0;

        // store the shipping rates
        wcms_session_set( 'wcms_package_rates', $rates );

        $this->calculate_taxes( $cart, $packages );

    }

    public function calculate_taxes( $cart = null, $packages = null, $return_packages = false ) {

        if ( get_option( 'woocommerce_calc_taxes', 0 ) != 'yes' ) {
            if ( $return_packages ) {
                return $packages;
            }

            return;
        }

        $merge = false;
        if ( !is_object( $cart ) ) {
            $cart = WC()->cart;
            $merge = true;
        }

        if (isset($_POST['action']) && $_POST['action'] == 'woocommerce_update_shipping_method') {
            return $cart;
        }

        if ( !$packages )
            $packages = $cart->get_shipping_packages();

        if ( empty($packages) ) {
            return;
        }

        // clear the taxes arrays remove tax totals from the grand total
        $old_taxes                  = $cart->taxes;
        $old_tax_total              = $cart->tax_total;
        $old_shipping_taxes         = $cart->shipping_taxes;
        $old_shipping_tax_total     = $cart->shipping_tax_total;
        $old_total                  = $cart->total;
        $cart_total_without_taxes   = $old_total - ($old_tax_total + $old_shipping_tax_total);

        // deduct taxes from the subtotal
        $cart->subtotal -= $old_tax_total;

        $item_taxes     = array();
        $cart_taxes     = array();

        foreach ( $packages as $idx => $package ) {
            if ( isset($package['destination']) && !$this->wcms->is_address_empty( $package['destination'] ) ) {
                WC()->customer->calculated_shipping( true );
                WC()->customer->set_shipping_location(
                    $package['destination']['country'],
                    $package['destination']['state'],
                    $package['destination']['postcode']
                );
            }

            $tax_rates      = array();
            $shop_tax_rates = array();

            /**
             * Calculate subtotals for items. This is done first so that discount logic can use the values.
             */
            foreach ( $package['contents'] as $cart_item_key => $values ) {
                $_product = $values['data'];

                // Prices
                $line_price         = $_product->get_price() * $values['quantity'];
                $line_subtotal      = 0;
                $line_subtotal_tax  = 0;

                // WC Composite Products
                if ( isset( $values['composite_data'] ) ) {
                    $line_price = 0;

                    foreach ( $values['composite_data'] as $composite ) {
                        $line_price += $composite['price'];
                    }

                    $line_price *= $values['quantity'];
                }

                if ( ! $_product->is_taxable() ) {
                    $line_subtotal = $line_price;
                } elseif ( $cart->prices_include_tax ) {

                    // Get base tax rates
                    if ( empty( $shop_tax_rates[ $_product->tax_class ] ) ) {
                        $shop_tax_rates[ $_product->tax_class ] = WC_Tax::get_shop_base_rate( $_product->tax_class );
                    }

                    // Get item tax rates
                    if ( empty( $tax_rates[ $_product->get_tax_class() ] ) ) {
                        $tax_rates[ $_product->get_tax_class() ] = WC_Tax::get_rates( $_product->get_tax_class() );
                    }

                    $base_tax_rates = $shop_tax_rates[ $_product->tax_class ];
                    $item_tax_rates = $tax_rates[ $_product->get_tax_class() ];

                    /**
                     * ADJUST TAX - Calculations when base tax is not equal to the item tax
                     */
                    if ( $item_tax_rates !== $base_tax_rates ) {

                        // Work out a new base price without the shop's base tax
                        $taxes                 = $cart->tax->calc_tax( $line_price, $base_tax_rates, true, true );

                        // Now we have a new item price (excluding TAX)
                        $line_subtotal         = $line_price - array_sum( $taxes );

                        // Now add modifed taxes
                        $tax_result            = $cart->tax->calc_tax( $line_subtotal, $item_tax_rates );
                        $line_subtotal_tax     = array_sum( $tax_result );

                        /**
                         * Regular tax calculation (customer inside base and the tax class is unmodified
                         */
                    } else {

                        // Calc tax normally
                        $taxes                 = WC_Tax::calc_tax( $line_price, $item_tax_rates, true );
                        $line_subtotal_tax     = array_sum( $taxes );
                        $line_subtotal         = $line_price - array_sum( $taxes );

                    }

                    /**
                     * Prices exclude tax
                     *
                     * This calculation is simpler - work with the base, untaxed price.
                     */
                } else {

                    // Get item tax rates
                    if ( empty( $tax_rates[ $_product->get_tax_class() ] ) ) {
                        $tax_rates[ $_product->get_tax_class() ] = WC_Tax::get_rates( $_product->get_tax_class() );
                    }

                    $item_tax_rates        = $tax_rates[ $_product->get_tax_class() ];

                    // Base tax for line before discount - we will store this in the order data
                    $taxes                 = WC_Tax::calc_tax( $line_price, $item_tax_rates );
                    $line_subtotal_tax     = array_sum( $taxes );
                    $line_subtotal         = $line_price;
                }

                // Add to main subtotal
                $cart->subtotal += $line_subtotal_tax;

            }

            /**
             * Calculate totals for items
             */
            foreach ( $package['contents'] as $cart_item_key => $values ) {

                $_product = $values['data'];

                // Prices
                $base_price = $_product->get_price();
                $line_price = $_product->get_price() * $values['quantity'];

                // WC Composite Products
                if ( isset( $values['composite_data'] ) ) {
                    $line_price = 0;

                    foreach ( $values['composite_data'] as $composite ) {
                        $line_price += $composite['price'];
                    }
                    $base_price = $line_price;
                    $line_price *= $values['quantity'];
                }

                // Tax data
                $taxes = array();
                $discounted_taxes = array();

                if ( ! $_product->is_taxable() ) {
                    // Discounted Price (price with any pre-tax discounts applied)
                    $discounted_price      = $cart->get_discounted_price( $values, $base_price, false );
                    $line_subtotal_tax     = 0;
                    $line_subtotal         = $line_price;
                    $line_tax              = 0;
                    $line_total            = WC_Tax::round_tax( $discounted_price * $values['quantity'] );

                    /**
                     * Prices include tax
                     */
                } elseif ( $cart->prices_include_tax ) {

                    $base_tax_rates = $shop_tax_rates[ $_product->tax_class ];
                    $item_tax_rates = $tax_rates[ $_product->get_tax_class() ];

                    /**
                     * ADJUST TAX - Calculations when base tax is not equal to the item tax
                     */
                    if ( $item_tax_rates !== $base_tax_rates ) {

                        // Work out a new base price without the shop's base tax
                        $taxes             = $cart->tax->calc_tax( $line_price, $base_tax_rates, true, true );

                        // Now we have a new item price (excluding TAX)
                        $line_subtotal     = woocommerce_round_tax_total( $line_price - array_sum( $taxes ) );

                        // Now add modifed taxes
                        $taxes             = $cart->tax->calc_tax( $line_subtotal, $item_tax_rates );
                        $line_subtotal_tax = array_sum( $taxes );

                        // Adjusted price (this is the price including the new tax rate)
                        $adjusted_price    = ( $line_subtotal + $line_subtotal_tax ) / $values['quantity'];

                        // Apply discounts
                        $discounted_price  = $cart->get_discounted_price( $values, $adjusted_price, false );
                        $discounted_taxes  = $cart->tax->calc_tax( $discounted_price * $values['quantity'], $item_tax_rates, true );
                        $line_tax          = array_sum( $discounted_taxes );
                        $line_total        = ( $discounted_price * $values['quantity'] ) - $line_tax;

                        /**
                         * Regular tax calculation (customer inside base and the tax class is unmodified
                         */
                    } else {

                        // Work out a new base price without the shop's base tax
                        $taxes             = WC_Tax::calc_tax( $line_price, $item_tax_rates, true );

                        // Now we have a new item price (excluding TAX)
                        $line_subtotal     = $line_price - array_sum( $taxes );
                        $line_subtotal_tax = array_sum( $taxes );

                        // Calc prices and tax (discounted)
                        $discounted_price = $cart->get_discounted_price( $values, $base_price, false );
                        $discounted_taxes = WC_Tax::calc_tax( $discounted_price * $values['quantity'], $item_tax_rates, true );
                        $line_tax         = array_sum( $discounted_taxes );
                        $line_total       = ( $discounted_price * $values['quantity'] ) - $line_tax;
                    }

                    // Tax rows - merge the totals we just got
                    foreach ( array_keys( $cart_taxes + $discounted_taxes ) as $key ) {
                        $cart_taxes[ $key ] = ( isset( $discounted_taxes[ $key ] ) ? $discounted_taxes[ $key ] : 0 ) + ( isset( $cart_taxes[ $key ] ) ? $cart_taxes[ $key ] : 0 );
                    }

                    /**
                     * Prices exclude tax
                     */
                } else {

                    $item_tax_rates        = $tax_rates[ $_product->get_tax_class() ];

                    // Work out a new base price without the shop's base tax
                    $taxes                 = WC_Tax::calc_tax( $line_price, $item_tax_rates );

                    // Now we have the item price (excluding TAX)
                    $line_subtotal         = $line_price;
                    $line_subtotal_tax     = array_sum( $taxes );

                    // Now calc product rates
                    $discounted_price      = $cart->get_discounted_price( $values, $base_price, false );
                    $discounted_taxes      = WC_Tax::calc_tax( $discounted_price * $values['quantity'], $item_tax_rates );
                    $discounted_tax_amount = array_sum( $discounted_taxes );
                    $line_tax              = $discounted_tax_amount;
                    $line_total            = $discounted_price * $values['quantity'];

                    // Tax rows - merge the totals we just got
                    foreach ( array_keys( $cart_taxes + $discounted_taxes ) as $key ) {
                        $cart_taxes[ $key ] = ( isset( $discounted_taxes[ $key ] ) ? $discounted_taxes[ $key ] : 0 ) + ( isset( $cart_taxes[ $key ] ) ? $cart_taxes[ $key ] : 0 );
                    }
                }

                // Store costs + taxes for lines
                if ( !isset( $item_taxes[ $cart_item_key ] ) ) {
                    $item_taxes[ $cart_item_key ]['line_total']         = $line_total;
                    $item_taxes[ $cart_item_key ]['line_tax']           = $line_tax;
                    $item_taxes[ $cart_item_key ]['line_subtotal']      = $line_subtotal;
                    $item_taxes[ $cart_item_key ]['line_subtotal_tax']  = $line_subtotal_tax;
                    $item_taxes[ $cart_item_key ]['line_tax_data']      = array('total' => $discounted_taxes, 'subtotal' => $taxes );
                } else {
                    $item_taxes[ $cart_item_key ]['line_total']                 += $line_total;
                    $item_taxes[ $cart_item_key ]['line_tax']                   += $line_tax;
                    $item_taxes[ $cart_item_key ]['line_subtotal']              += $line_subtotal;
                    $item_taxes[ $cart_item_key ]['line_subtotal_tax']          += $line_subtotal_tax;
                    $item_taxes[ $cart_item_key ]['line_tax_data']['total']     += $discounted_taxes;
                    $item_taxes[ $cart_item_key ]['line_tax_data']['subtotal']  += $taxes;
                }

                $packages[ $idx ]['contents'][ $cart_item_key ]['line_total']       = $line_total;
                $packages[ $idx ]['contents'][ $cart_item_key ]['line_tax']         = $line_tax;
                $packages[ $idx ]['contents'][ $cart_item_key ]['line_subtotal']    = $line_subtotal;
                $packages[ $idx ]['contents'][ $cart_item_key ]['line_subtotal_tax']= $line_subtotal_tax;
                $packages[ $idx ]['contents'][ $cart_item_key ]['line_tax_data']    = array('total' => $discounted_taxes, 'subtotal' => $taxes);
            }
        }

        foreach ( $item_taxes as $cart_item_key => $taxes ) {
            if ( !isset($cart->cart_contents[ $cart_item_key ]) )
                continue;

            $product_id = $cart->cart_contents[ $cart_item_key ]['product_id'];
            WC()->cart->recurring_cart_contents = array();

            $cart->cart_contents[ $cart_item_key ]['line_total']        = $taxes['line_total'];
            $cart->cart_contents[ $cart_item_key ]['line_tax']          = $taxes['line_tax'];
            $cart->cart_contents[ $cart_item_key ]['line_subtotal']     = $taxes['line_subtotal'];
            $cart->cart_contents[ $cart_item_key ]['line_subtotal_tax'] = $taxes['line_subtotal_tax'];
            $cart->cart_contents[ $cart_item_key ]['line_tax_data']     = $taxes['line_tax_data'];

            // Set recurring taxes for subscription products
            if ( class_exists('WC_Subscriptions_Product') && WC_Subscriptions_Product::is_subscription( $product_id ) ) {
                WC()->cart->recurring_cart_contents[ $product_id ]['recurring_line_total']        = $taxes['line_total'];
                WC()->cart->recurring_cart_contents[ $product_id ]['recurring_line_tax']          = $taxes['line_tax'];
                WC()->cart->recurring_cart_contents[ $product_id ]['recurring_line_subtotal']     = $taxes['line_subtotal'];
                WC()->cart->recurring_cart_contents[ $product_id ]['recurring_line_subtotal_tax'] = $taxes['line_subtotal_tax'];
            }
        }

        // Total up/round taxes and shipping taxes
        if ( $cart->round_at_subtotal ) {
            $cart->tax_total          = WC_Tax::get_tax_total( $cart_taxes );
            $cart->taxes              = array_map( 'WC_Tax::round', $cart_taxes );
        } else {
            $cart->tax_total          = array_sum( $cart_taxes );
            $cart->taxes              = array_map( 'WC_Tax::round', $cart_taxes );
        }

        if ( $merge ) {
            WC()->cart = $cart;
        }

        // Setting an empty default customer shipping location prevents
        // subtotal calculation from applying the incorrect taxes based
        // on the shipping address. But do not remove the shipping country
        // to satisfy the validation done on WC_Checkout
        WC()->customer->calculated_shipping( false );
        WC()->customer->set_shipping_location(
            WC()->customer->get_shipping_country(),
            '',
            ''
        );

        if ( $return_packages ) {
            return $packages;
        }

        // store the modified packages array
        wcms_session_set( 'wcms_packages', $packages );

        return $cart;

    }

    public function subtotal_include_taxes( $product_subtotal, $cart_item, $cart_item_key ) {

        $packages = wcms_session_get( 'wcms_packages' );
        $tax_based_on = get_option( 'woocommerce_tax_based_on', 'billing' );

        // only process subtotal if multishipping is being used
        if ( count($packages) <= 1 || $tax_based_on != 'shipping' )
            return $product_subtotal;

        $subtotal   = $this->wcms->get_cart_item_subtotal( $cart_item );
        $taxable    = $cart_item['data']->is_taxable();

        if ( $taxable && $subtotal < ($cart_item['line_total'] + $cart_item['line_tax']) ) {
            if ( WC()->cart->tax_display_cart == 'excl' ) {
                $row_price = $cart_item['line_total'];

                $product_subtotal = wc_price( $row_price );

                if ( WC()->cart->prices_include_tax && $cart_item['line_tax'] > 0 ) {
                    $product_subtotal .= ' <small class="tax_label">' . WC()->countries->ex_tax_or_vat() . '</small>';
                }
            } else {
                $row_price = $cart_item['line_total'] + $cart_item['line_tax'];

                $product_subtotal = wc_price( $row_price );

                if ( ! WC()->cart->prices_include_tax && $cart_item['line_tax'] > 0 ) {
                    $product_subtotal .= ' <small class="tax_label">' . WC()->countries->inc_tax_or_vat() . '</small>';
                }
            }


        }

        return $product_subtotal;
    }

    public function create_order_shipments( $order_id, $posted = null ) {
        $multishipping  = get_post_meta( $order_id, '_multiple_shipping', true );
        $created        = get_post_meta( $order_id, '_shipments_created', true );
        $packages       = get_post_meta( $order_id, '_wcms_packages', true );
        $shipment       = $this->wcms->shipments;

        if ( $multishipping != 'yes' || $created == 'yes' ) {
            return;
        }

        foreach ( $packages as $i => $package ) {
            $shipment->create_from_package( $package, $i, $order_id );
        }

        update_post_meta( $order_id, '_shipments_created', 'yes' );

    }

    public function prevent_customer_data_update( $update, $wc_checkout ) {
        return false;
    }

    public function maybe_store_shipping_address( $customer_id, $posted ) {
        $packages = WC()->cart->get_shipping_packages();

        if ( count( $packages ) > 1 ) {
            return;
        }

        $checkout   = WC_Checkout::instance();
        $addresses = $this->wcms->address_book->get_user_addresses( $customer_id );

        // Shipping address.
        $shipping_address = array();
        $hash = '';
        if ( $checkout->checkout_fields['shipping'] ) {
            foreach ( array_keys( $checkout->checkout_fields['shipping'] ) as $field ) {
                $field_name = str_replace( 'shipping_', '', $field );
                $value      = $checkout->get_posted_address_data( $field_name, 'shipping' );
                $hash       .= $value;
                $shipping_address[ 'shipping_'. $field_name ] = $value;
            }
            $hash = md5($hash);
        }

        if ( empty( $shipping_address ) ) {
            return;
        }

        foreach ($addresses as $addr) {
            $vals = '';

            if( !is_array($addr) ) {
                continue;
            }

            foreach ($addr as $key => $value) {
                $vals .= $value;
            }
            $address_hash = md5($vals);

            if ($hash == $address_hash) {
                // duplicate address! do not store
                return;
            }
        }

        // store the shipping address
        unset( $addresses[0] );
        $addresses[] = $shipping_address;
        $this->wcms->address_book->save_user_addresses( $customer_id, $addresses );
    }

}