<?php

if ( ! defined( 'ABSPATH' ) )
    exit; // Exit if accessed directly

class WC_MS_Order {

    private $wcms;

    public function __construct(WC_Ship_Multiple $wcms) {
        $this->wcms = $wcms;

        // package status
        add_action( 'wp_ajax_wcms_update_package_status', array($this, 'update_package_status') );
        add_action( 'woocommerce_order_status_completed', array($this, 'update_package_on_completed_order') );

        // admin order page shipping address override
        add_action( 'woocommerce_admin_order_data_after_shipping_address', array($this, 'override_order_shipping_address') );
        add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'order_data_shipping_address' ), 90 );

        add_filter( 'wcms_order_shipping_packages_table', array($this, 'display_order_shipping_addresses'), 9 );

        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'show_multiple_addresses_line' ), 1 );

        // meta box
        add_action( 'add_meta_boxes', array( $this, 'order_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_css' ) );
        add_action( 'woocommerce_process_shop_order_meta', array( $this, 'update_order_addresses' ), 10, 2 );
        add_action( 'woocommerce_saved_order_items', array( $this, 'update_order_taxes' ), 1, 2 );

        add_filter( 'woocommerce_order_get_items', array( $this, 'order_item_taxes' ), 30, 2 );

        // WC PIP
        add_filter( 'woocommerce_pip_template_body', array( $this, 'pip_template_body' ), 10, 3 );
    }

    public function update_package_status() {
        global $wpdb;

        $pkg_idx    = $_POST['package'];
        $order      = $_POST['order'];
        $packages   = get_post_meta( $order, '_wcms_packages', true );
        $email      = $_POST['email'];

        foreach ( $packages as $x => $package ) {
            if ( $x == $pkg_idx ) {
                $packages[$x]['status'] = $_POST['status'];

                if ( $_POST['status'] == 'Completed' && $email ) {
                    self::send_package_email( $order, $pkg_idx );
                }

                break;
            }
        }

        update_post_meta( $order, '_wcms_packages', $packages );

        die($_POST['status']);

    }

    public function update_package_on_completed_order( $order_id ) {
        $packages = get_post_meta( $order_id, '_wcms_packages', true );

        if ( $packages ) {
            foreach ( $packages as $x => $package ) {
                $packages[$x]['status'] = 'Completed';
            }

            update_post_meta( $order_id, '_wcms_packages', $packages );
        }
    }

    public function override_order_shipping_address( $order ) {

        $packages  = get_post_meta( $order->id, '_wcms_packages', true );
        $multiship = get_post_meta( $order->id, '_multiple_shipping', true);

        if ( (! $order->get_formatted_shipping_address() && count($packages) > 1 ) || $multiship == 'yes' ):
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var $order_data = $("div.order_data_column").eq(2);

                    $order_data.find("a.edit_address").remove();
                    $order_data.find("div.address").html('<a href="#wc_multiple_shipping"><?php _e('Ships to multiple addresses', 'woocommerce'); ?></a>');
                });
            </script>
        <?php
        endif;
    }

    function order_data_shipping_address() {
        global $thepostid;

        $order  = wc_get_order( $thepostid );
        $custom = $order->order_custom_fields;

        if ( isset($custom['_shipping_addresses']) && isset($custom['_shipping_addresses'][0]) && !empty($custom['_shipping_addresses'][0]) ) {
            echo <<<EOD
<script type="text/javascript">
jQuery(jQuery("div.address")[1]).html("<p><a href=\"#wc_multiple_shipping\">Multiple Shipping Addresses</a></p>");
jQuery(jQuery("a.edit_address")[1]).remove();
jQuery(jQuery("div.edit_address")[1]).remove();
</script>
EOD;
        }
    }

    function display_order_shipping_addresses( $order ) {
        $order_id           = $order->id;
        $methods            = get_post_meta($order_id, '_shipping_methods', true);
        $packages           = get_post_meta($order_id, '_wcms_packages', true);
        $available_methods  = WC()->shipping->shipping_methods;

        if ( !$packages || count($packages) == 1 ) {
            return;
        }

        echo '<p><strong>'. __( 'This order ships to multiple addresses.', 'wc_shipping_multiple_address' ) .'</strong></p>';
        echo '<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1" bordercolor="#eee">';
        echo '<thead><tr>';
        echo '<th scope="col" style="text-align:left; border: 1px solid #eee;">'. __( 'Product', 'woocommerce' ) .'</th>';
        echo '<th scope="col" style="text-align:left; border: 1px solid #eee;">'. __( 'Qty', 'woocommerce' ) .'</th>';
        echo '<th scope="col" style="text-align:left; border: 1px solid #eee;">'. __( '', 'woocommerce' ) .'</th>';
        echo '</thead><tbody>';

        foreach ( $packages as $x => $package ) {
            $products   = $package['contents'];
            $method     = $methods[$x]['label'];

            foreach ( $available_methods as $ship_method ) {
                if ($ship_method->id == $method) {
                    $method = $ship_method->get_title();
                    break;
                }
            }

            $address = ( isset( $package['destination'] ) && ! empty( $package['destination'] ) )
                ? WC()->countries->get_formatted_address( $package['destination'] )
                : '';

            foreach ( $products as $i => $product ) {
                echo '<tr>';
                echo '<td style="text-align:left; vertical-align:middle; border: 1px solid #eee;">'. apply_filters( 'wcms_product_title', get_the_title($product['data']->id), $product ) .'<br />'. self::get_item_data($product, true) .'</td>';
                echo '<td style="text-align:left; vertical-align:middle; border: 1px solid #eee;">'. $product['quantity'] .'</td>';
                echo '<td style="text-align:left; vertical-align:middle; border: 1px solid #eee;">'.  $address .'<br/><em>( '. $method .' )</em></td>';
                echo '</tr>';
            }
        }
        echo '</table>';
    }

    function show_multiple_addresses_line( $column ) {
        global $post, $the_order;

        if ( empty( $the_order ) || $the_order->id != $post->ID ) {
            $the_order = wc_get_order( $post->ID );
        }

        if ( $column == 'shipping_address' ) {

            $packages = get_post_meta( $post->ID, '_wcms_packages', true );

            if (! $the_order->get_formatted_shipping_address() && count($packages) > 1 ) {
                _e('Ships to multiple addresses ', 'woocommerce');
            }

        }
    }

    function order_meta_box($type) {
        global $post;

        $addresses  = get_post_meta($post->ID, '_shipping_addresses', true);
        $methods    = get_post_meta($post->ID, '_shipping_methods', true);
        $multiship  = get_post_meta($post->ID, '_multiple_shipping', true);
        $shipments  = WC_MS_Order_Shipment::get_by_order( $post->ID );

        if ( $multiship == 'yes' || count( $methods ) > 1 ) {
            add_meta_box(
                'wc_multiple_shipping',
                __( 'Order Shipping Addresses', 'wc_shipping_multiple_address' ),
                array( $this, 'packages_meta_box' ),
                'shop_order' ,
                'normal',
                'core'
            );
            /*if ( count( $shipments ) == 0 ) {
                add_meta_box(
                    'wc_multiple_shipping',
                    __( 'Order Shipping Addresses', 'wc_shipping_multiple_address' ),
                    array( $this, 'packages_meta_box' ),
                    'shop_order' ,
                    'normal',
                    'core'
                );
            } else {
                add_meta_box(
                    'wc_order_shipments',
                    __( 'Order Shipments', 'wc_shipping_multiple_address' ),
                    array( $this, 'shipments_meta_box' ),
                    'shop_order' ,
                    'normal',
                    'core'
                );
            }*/
        }

    }

    function admin_css() {
        $screen = get_current_screen();

        if ( $screen->id == 'shop_order' ) {
            wp_enqueue_style( 'wc-ms-admin-css', plugins_url( 'css/admin.css', WC_Ship_Multiple::FILE ) );
        }

    }

    public function shipments_meta_box( $post ) {
        include dirname( WC_Ship_Multiple::FILE ) .'/templates/order-shipment-metabox.php';
    }

    function packages_meta_box($post) {
        $order                  = wc_get_order($post->ID);
        $packages               = get_post_meta($post->ID, '_wcms_packages', true);
        $methods                = get_post_meta($post->ID, '_shipping_methods', true);
        $order_shipping_methods = $order->get_shipping_methods();
        $shipping_settings      = get_option('woocommerce_multiple_shipping_settings', array());
        $partial_orders         = false;
        $send_email             = false;

        if ( isset($shipping_settings['partial_orders']) && $shipping_settings['partial_orders'] == 'yes' ) {
            $partial_orders = true;
        }

        if ( isset($shipping_settings['partial_orders_email']) && $shipping_settings['partial_orders_email'] == 'yes' ) {
            $send_email = true;
        }

        if ( !$packages ) {
            return;
        }

        $shipFields = WC()->countries->get_address_fields( WC()->countries->get_base_country(), 'shipping_' );

        echo '<div class="item-addresses-holder">';

        foreach ( $packages as $x => $package ) {
            $products           = $package['contents'];
            echo '<div class="item-address-box package-'. $x .'-box">';

            if ( $partial_orders && isset($package['status']) && $package['status'] == 'Completed' ) {
                echo '<span class="complete">&nbsp;</span>';
            }

            foreach ( $products as $i => $product ) {
                $attributes = apply_filters( 'wc_ms_package_item_data', self::get_item_data( $product, true ), $product, $package );

                echo '<h4 style="margin: 0;">'. apply_filters( 'wcms_product_title', get_the_title($product['data']->id), $product ) .' &times; '. $product['quantity'];

                if ( ! empty( $attributes ) ) {
                    echo '<small style="display: block; margin: 5px 0 10px 10px;">'. str_replace( "\n", "<br/>", $attributes ) .'</small>';
                }
                echo '</h4>';
            }

            do_action( 'wc_ms_order_package_block_before_address', $order, $package, $x );

            if ( isset( $package['destination'] ) && !empty( $package['destination'] ) ) {
                echo '
                    <div class="shipping_data">
                        <div class="address">
                            <p>
                                ' . WC()->countries->get_formatted_address( $package['destination'] ) . '
                            </p>
                        </div><br />';

                do_action( 'wc_ms_order_package_block_after_address', $order, $package, $x );

                if ( isset( $package['destination']['notes'] ) && ! empty($package['destination']['notes'] ) ) {
                    echo '<blockquote>Shipping Notes:<br /><em>&#8220;' . $package['destination']['notes'] . '&#8221;</em></blockquote>';
                }

                echo '<a class="edit_shipping_address" href="#">( '. __( 'Edit', 'woocommerce' ) .' )</a><br />';

                // Display form
                echo '<div class="edit_shipping_address" style="display:none;">';

                if ( $shipFields ) foreach ( $shipFields as $key => $field ) :
                    $key        = str_replace( 'shipping_', '', $key);
                    $addr_key   = $key;
                    $key        = 'pkg_'. $key .'_'. $x;

                    if (!isset($field['type'])) $field['type'] = 'text';
                    if (!isset($field['label'])) $field['label'] = '';
                    switch ($field['type']) {
                        case "select" :
                            woocommerce_wp_select( array( 'id' => $key, 'label' => $field['label'], 'options' => $field['options'], 'value' => $package['destination'][$addr_key] ) );
                            break;
                        default :
                            woocommerce_wp_text_input( array( 'id' => $key, 'label' => $field['label'], 'value' => $package['destination'][$addr_key] ) );
                            break;

                    }
                endforeach;
                echo '<input type="hidden" name="edit_address[]" value="'. $x .'" />';
                echo '</div></div>';
            }

            if (! is_array($methods) ) {
                $order_method = current( $order_shipping_methods );
                $methods = array(
                    $x => array(
                        'id' => $order_method['method_id'],
                        'name' => $order_method['name']
                    )
                );
            }
            $method = $methods[$x]['label'];

            if ( isset($methods[ $x ]['id']) ) {
                foreach ( $order_shipping_methods as $ship_method ) {
                    if ($ship_method['method_id'] == $methods[ $x ]['id']) {
                        $method = $ship_method['name'];
                        break;
                    }
                }
            }

            echo '<em>'. $method .'</em>';

            if ( $partial_orders ) {
                $current_status = (isset($package['status'])) ? $package['status'] : 'Pending';

                if ( $current_status == 'Completed' ) {
                    $select_css = 'display: none;';
                    $status_css = '';
                } else {
                    $select_css = '';
                    $status_css = 'display: none;';
                }

                echo '<p id="package_'. $x .'_select_p" style="'. $select_css .'">
                            <select id="package_'. $x .'_status">
                                <option value="Pending" '. selected($current_status, 'Pending', false) .'>Pending</option>
                                <option value="Completed" '. selected($current_status, 'Completed', false) .'>Completed</option>
                            </select>
                            <a class="button save-package-status" data-order="'. $post->ID .'" data-package="'. $x .'" href="#" title="Apply">GO</a>
                        </p>';

                echo '<p id="package_'. $x .'_status_p" style="'. $status_css .'"><strong>Completed</strong> (<a href="#" class="edit_package" data-package="'. $x .'">'. __('Change', 'wc_shipping_multiple_address') .'</a>)</p>';
            }

            do_action( 'wc_ms_order_package_block', $order, $package, $x );

            echo '</div>';
        }
        echo '</div>';
        echo '<div class="clear"></div>';


        $email_enabled = ($send_email) ? 'true' : 'false';
        $inline_js = '
                var email_enabled = '. $email_enabled .';
                jQuery(".shipping_data a.edit_shipping_address").click(function(e) {
                    e.preventDefault();
                    jQuery(this).closest(".shipping_data").find("div.edit_shipping_address").show();
                });

                jQuery(".save-package-status").click(function(e) {
                    e.preventDefault();
                    var pkg_id      = jQuery(this).data("package");
                    var order_id    = jQuery(this).data("order");
                    var status      = jQuery("#package_"+ pkg_id +"_status").val();
                    var email       = false;

                    if ( status == "Completed" && email_enabled ) {
                        if ( confirm("Do you want to send an email to the customer?") ) {
                            email = true;
                        }
                    }

                    jQuery(".package-"+ pkg_id +"-box").block({ message: null, overlayCSS: { background: "#fff url('. WC()->plugin_url() .'/assets/images/ajax-loader.gif) no-repeat center", opacity: 0.6 } });

                    jQuery.post(ajaxurl, {action: "wcms_update_package_status", "status": status, package: pkg_id, order: order_id, email: email}, function(resp) {
                        if ( resp == "Completed" ) {
                            jQuery(".package-"+ pkg_id +"-box").prepend("<span class=\'complete\'>&nbsp;</span>");

                            jQuery("#package_"+ pkg_id +"_status_p").show();
                            jQuery("#package_"+ pkg_id +"_select_p").hide();
                        } else {
                            jQuery(".package-"+ pkg_id +"-box").find("span.complete").remove();
                        }

                        jQuery(".package-"+ pkg_id +"-box").unblock();
                    });

                });

                jQuery(".edit_package").click(function(e) {
                    e.preventDefault();

                    var pkg_id = jQuery(this).data("package");

                    jQuery("#package_"+ pkg_id +"_status_p").hide();
                    jQuery("#package_"+ pkg_id +"_select_p").show();
                });
            ';

            wc_enqueue_js( $inline_js );
    }

    function update_order_addresses( $post_id, $post ) {

        $packages = get_post_meta($post_id, '_wcms_packages', true);

        if ( $packages && isset($_POST['edit_address']) && count($_POST['edit_address']) > 0 ) {
            foreach ( $_POST['edit_address'] as $idx ) {
                if (! isset($packages[$idx]) ) continue;

                $address = array(
                    'first_name'        => isset($_POST['pkg_first_name_'. $idx]) ? $_POST['pkg_first_name_'. $idx] : '',
                    'last_name'         => isset($_POST['pkg_last_name_'. $idx]) ? $_POST['pkg_last_name_'. $idx] : '',
                    'company'           => isset($_POST['pkg_company_'. $idx]) ? $_POST['pkg_company_'. $idx] : '',
                    'address_1'         => isset($_POST['pkg_address_1_'. $idx]) ? $_POST['pkg_address_1_'. $idx] : '',
                    'address_2'         => isset($_POST['pkg_address_2_'. $idx]) ? $_POST['pkg_address_2_'. $idx] : '',
                    'city'              => isset($_POST['pkg_city_'. $idx]) ? $_POST['pkg_city_'. $idx] : '',
                    'state'             => isset($_POST['pkg_state_'. $idx]) ? $_POST['pkg_state_'. $idx] : '',
                    'postcode'          => isset($_POST['pkg_postcode_'. $idx]) ? $_POST['pkg_postcode_'. $idx] : '',
                    'country'           => isset($_POST['pkg_country_'. $idx]) ? $_POST['pkg_country_'. $idx] : '',
                );

                $packages[$idx]['destination'] = $address;
            }
            update_post_meta( $post_id, '_wcms_packages', $packages );
        }
    }

    function update_order_taxes( $order_id, $items ) {
        $order_taxes = isset($items['order_taxes']) ? $items['order_taxes'] : array();
        $tax_total = array();
        $packages = get_post_meta( $order_id, '_wcms_packages', true );

        if ( !is_array( $packages ) ) {
            return;
        }

        foreach ( $order_taxes as $item_id => $rate_id ) {
            foreach ( $packages as $package ) {
                foreach ( $package['contents'] as $item ) {
                    if ( isset( $item['line_tax_data']['total'][ $rate_id ] ) ) {
                        if ( !isset( $tax_total[ $item_id ] ) ) {
                            $tax_total[ $item_id ] = 0;
                        }
                        $tax_total[ $item_id ] += $item['line_tax_data']['total'][ $rate_id ];
                    }
                }
            }
        }

        $total_tax = 0;
        foreach ( $tax_total as $item_id => $total ) {
            $total_tax += $total;
            wc_update_order_item_meta( $item_id, 'tax_amount', $tax_total[ $item_id ] );
        }

        $old_total_tax = get_post_meta( $order_id, '_order_tax', true );

        if ( $total_tax > $old_total_tax ) {
            $order_total = get_post_meta( $order_id, '_order_total', true );
            $order_total -= $old_total_tax;
            $order_total += $total_tax;

            update_post_meta( $order_id, '_order_total', $order_total );
        }

        update_post_meta( $order_id, '_order_tax', $total_tax );

    }

    function order_item_taxes( $items, $order ) {
        if ( get_post_meta( $order->id, '_multiple_shipping', true ) != 'yes' ) {
            return $items;
        }

        $packages = get_post_meta( $order->id, '_wcms_packages', true );

        if ( !$packages ) {
            return $items;
        }

        foreach ( $items as $item_id => $item ) {
            if ( $item['type'] != 'line_item' ) {
                continue;
            }

            if ( $item['qty'] == 1 ) {
                continue;
            }

            $item_tax_subtotal  = 0;
            $item_tax_total     = 0;
            $item_tax_data      = array();
            $modified           = false;

            $item_line_tax_data = unserialize( $item['line_tax_data'] );
            $tax_rate_ids       = array_keys( $item_line_tax_data['total'] );

            foreach ( $packages as $package ) {
                foreach ( $package['contents'] as $package_item ) {

                    if ( (int)$item['product_id'] == (int)$package_item['product_id'] && (int)$item['variation_id'] == (int)$package_item['variation_id'] ) {
                        $modified = true;

                        $item_tax_subtotal  += $package_item['line_subtotal_tax'];
                        $item_tax_total     += $package_item['line_tax'];

                    }

                }
            }

            if ( $modified && is_array( $tax_rate_ids ) ) {
                foreach ( $tax_rate_ids as $rate_id ) {
                    if ( !isset( $item_tax_data['total'][ $rate_id ] ) ) {
                        $item_tax_data['total'][ $rate_id ] = 0;
                    }

                    $item_tax_data['total'][ $rate_id ] += $item_tax_total;
                }

                $items[ $item_id ]['line_tax'] = $item_tax_total;
                $items[ $item_id ]['line_subtotal_tax'] = $item_tax_subtotal;
                $items[ $item_id ]['line_tax_data'] = serialize($item_tax_data);
            }

        }

        return $items;
    }

    /**
     * Load a custom template body for orders with multishipping
     * @param string    $template
     * @param WC_Order  $order
     * @param int       $order_loop
     * @return string $template
     */
    public function pip_template_body( $template, $order, $order_loop ) {
        $packages = get_post_meta( $order->id, '_shipping_packages', true );

        if ( $packages && count( $packages ) > 1 ) {
            $template = dirname(WC_Ship_Multiple::FILE) .'/templates/pip-template-body.php';
        }

        return $template;
    }

    public static function send_package_email( $order_id, $package_index ) {
        $settings   = get_option( 'woocommerce_multiple_shipping_settings', array() );
        $order      = wc_get_order( $order_id );

        $subject    = ( isset($settings['email_subject']) && !empty($settings['email_subject']) ) ? $settings['email_subject'] : __('Part of your order has been shipped', 'wc_shipping_multiple_address');
        $message    = ( isset($settings['email_message']) && !empty($settings['email_message']) ) ? $settings['email_message'] : false;

        if (! $message ) {
            $message = self::get_default_email_body();
        }

        $mailer     = WC()->mailer();
        $message    = $mailer->wrap_message( $subject, $message );

        $ts         = strtotime( $order->order_date );
        $order_date = date(get_option('date_format'), $ts);
        $order_time = date(get_option('time_format'), $ts);

        $search         = array('{order_id}', '{order_date}', '{order_time}', '{customer_first_name}', '{customer_last_name}', '{products_table}', '{addresses_table}');
        $replacements   = array(
            $order->get_order_number(),
            $order_date,
            $order_time,
            $order->billing_first_name,
            $order->billing_last_name,
            self::render_products_table( $order, $package_index ),
            self::render_addresses_table( $order, $package_index )
        );
        $message    = str_replace($search, $replacements, $message);

        $mailer->send($order->billing_email, $subject, $message);

    }

    public static function get_default_email_body() {
        ob_start();
        ?>
        <p><?php printf( __( "Hi there. Part of your recent order on %s has been completed. Your order details are shown below for your reference:", 'woocommerce' ), get_option( 'blogname' ) ); ?></p>

        <h2><?php echo __( 'Order:', 'woocommerce' ) . ' {order_id}'; ?></h2>

        {products_table}

        {addresses_table}

        <?php
        $contents = ob_get_clean();

        return $contents;
    }

    public static function render_products_table( $order, $idx ) {
        $packages   = get_post_meta( $order->id, '_wcms_packages', true );
        $package    = $packages[$idx];
        $products   = $package['contents'];

        ob_start();
        ?>
        <table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1" bordercolor="#eee">
            <thead>
            <tr>
                <th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Product', 'woocommerce' ); ?></th>
                <th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Quantity', 'woocommerce' ); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php
            foreach ( $products as $item ):
                $_product = (function_exists('get_product')) ? get_product($item['product_id']) : new WC_Product($item['product_id']);
                $attachment_image_src = wp_get_attachment_image_src( get_post_thumbnail_id( $_product->id ), 'thumbnail' );
                $image = ($attachment_image_src) ? '<img src="' . current( $attachment_image_src ) . '" alt="Product Image" height="32" width="32" style="vertical-align:middle; margin-right: 10px;" />' : '';
                ?>
                <tr>
                    <td style="text-align:left; vertical-align:middle; border: 1px solid #eee; word-wrap:break-word;"><?php

                        // Show title/image etc
                        echo    apply_filters( 'woocommerce_order_product_image', $image, $_product, true);

                        // Product name
                        echo    apply_filters( 'woocommerce_order_product_title', $_product->get_title(), $_product );


                        // SKU
                        echo    ($_product->get_sku()) ? ' (#' . $_product->get_sku() . ')' : '';

                        // File URLs
                        if ( $_product->exists() && $_product->is_downloadable() ) {

                            $download_file_urls = $order->get_downloadable_file_urls( $item['product_id'], $item['variation_id'], $item );

                            $i = 0;

                            foreach ( $download_file_urls as $file_url => $download_file_url ) {
                                echo '<br/><small>';

                                $filename = wc_get_filename_from_url( $file_url );

                                if ( count( $download_file_urls ) > 1 ) {
                                    echo sprintf( __('Download %d:', 'woocommerce' ), $i + 1 );
                                } elseif ( $i == 0 )
                                    echo __( 'Download:', 'woocommerce' );

                                echo ' <a href="' . $download_file_url . '" target="_blank">' . $filename . '</a></small>';

                                $i++;
                            }
                        }

                        ?></td>
                    <td style="text-align:left; vertical-align:middle; border: 1px solid #eee;"><?php echo $item['quantity'] ;?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php

        $contents = ob_get_clean();

        return $contents;
    }

    public static function render_addresses_table( $order, $idx ) {
        $packages   = get_post_meta( $order->id, '_wcms_packages', true );
        $package    = $packages[$idx];

        ob_start();
        ?>
        <table cellspacing="0" cellpadding="0" style="width: 100%; vertical-align: top;" border="0">
            <tr>
                <td valign="top" width="50%">

                    <h3><?php _e( 'Billing address', 'woocommerce' ); ?></h3>

                    <p><?php echo $order->get_formatted_billing_address(); ?></p>

                </td>

                <td valign="top" width="50%">

                    <h3><?php _e( 'Shipping address', 'woocommerce' ); ?></h3>

                    <?php
                    echo '<div class="shipping_data"><div class="address">'. WC()->countries->get_formatted_address( $package['destination'] ) .'</div><br />';

                    if ( isset($package['destination']['notes']) && !empty($package['destination']['notes']) ) {
                        echo '<blockquote>Shipping Notes:<br /><em>&#8220;'. $package['destination']['notes'] .'&#8221;</em></blockquote>';
                    }
                    ?>

                </td>

            </tr>

        </table>
        <?php

        $contents = ob_get_clean();

        return $contents;
    }

    /**
     * Gets and formats a list of cart item data + variations for display on the frontend.
     *
     * @param array $item
     * @param bool $flat (default: false)
     * @return string
     */
    public static function get_item_data( $item, $flat = false ) {
        $item_data = array();

        // Variation data
        if ( ! empty( $item['data']->variation_id ) && is_array( $item['variation'] ) ) {

            foreach ( $item['variation'] as $name => $value ) {

                if ( '' === $value )
                    continue;

                $taxonomy = wc_attribute_taxonomy_name( str_replace( 'attribute_pa_', '', urldecode( $name ) ) );

                // If this is a term slug, get the term's nice name
                if ( taxonomy_exists( $taxonomy ) ) {
                    $term = get_term_by( 'slug', $value, $taxonomy );
                    if ( ! is_wp_error( $term ) && $term && $term->name ) {
                        $value = $term->name;
                    }
                    $label = wc_attribute_label( $taxonomy );

                    // If this is a custom option slug, get the options name
                } else {
                    $value              = apply_filters( 'woocommerce_variation_option_name', $value );
                    $product_attributes = $item['data']->get_attributes();
                    if ( isset( $product_attributes[ str_replace( 'attribute_', '', $name ) ] ) ) {
                        $label = wc_attribute_label( $product_attributes[ str_replace( 'attribute_', '', $name ) ]['name'] );
                    } else {
                        $label = $name;
                    }
                }

                $item_data[] = array(
                    'key'   => $label,
                    'value' => $value
                );
            }
        }

        // Filter item data to allow 3rd parties to add more to the array
        $item_data = apply_filters( 'woocommerce_get_item_data', $item_data, $item );

        // Format item data ready to display
        foreach ( $item_data as $key => $data ) {
            // Set hidden to true to not display meta on cart.
            if ( ! empty( $data['hidden'] ) ) {
                unset( $item_data[ $key ] );
                continue;
            }
            $item_data[ $key ]['key']     = ! empty( $data['key'] ) ? $data['key'] : $data['name'];
            $item_data[ $key ]['display'] = ! empty( $data['display'] ) ? $data['display'] : $data['value'];
        }

        // Output flat or in list format
        if ( sizeof( $item_data ) > 0 ) {
            ob_start();

            if ( $flat ) {
                foreach ( $item_data as $data ) {
                    echo esc_html( $data['key'] ) . ': ' . wp_kses_post( $data['display'] ) . "\n";
                }
            } else {
                wc_get_template( 'cart/cart-item-data.php', array( 'item_data' => $item_data ) );
            }

            return ob_get_clean();
        }

        return '';
    }

    private function round_up( $number, $precision = 2) {
        $fig = pow( 10, $precision );
        return ( ceil( $number * $fig ) / $fig );
    }

}