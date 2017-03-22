<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_MS_Front {

    private $wcms;

    public function __construct( WC_Ship_Multiple $wcms ) {
        $this->wcms = $wcms;

        /* WCMS Front */
        add_filter( 'body_class', array( $this, 'output_body_class' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'front_scripts' ), 1 );
        add_action( 'woocommerce_view_order', array( $this, 'show_multiple_addresses_notice' ) );
        add_action( 'woocommerce_email_after_order_table', array( $this, 'email_shipping_table' ) );
        add_action( 'woocommerce_order_details_after_order_table', array($this, 'list_order_item_addresses') );

        // cleanup
        add_action( 'wp_logout', array( $this->wcms, 'clear_session' ) );

	    add_action( 'plugins_loaded', array( $this, 'load_account_addresses' ) );

        // inline script
        add_action( 'wp_footer', array( $this, 'inline_scripts' ) );
    }

	public function load_account_addresses() {
		// my account
		if ( version_compare( WC_VERSION, '2.6', '>=' ) ) {
			add_filter( 'woocommerce_my_account_get_addresses', array( $this, 'account_address_labels' ), 10, 2 );
			add_filter( 'woocommerce_my_account_my_address_formatted_address', array( $this, 'account_address_formatted' ), 10, 3 );

			add_filter( 'woocommerce_my_account_edit_address_field_value', array( $this, 'edit_address_field_value' ), 10, 3 );
			add_action( 'template_redirect', array( $this, 'save_address' ), 1 );
		} else {
			add_action( 'woocommerce_after_my_account', array( $this, 'my_account' ) );
		}
	}

    /**
     * Add woocommerce and woocommerce-page classes to the body tag of WCMS pages
     *
     * @param array $classes
     * @return array
     */
    public function output_body_class( $classes ) {
        if ( is_page( wc_get_page_id( 'multiple_addresses' ) ) || is_page( wc_get_page_id( 'account_addresses' ) ) ) {
            $classes[] = 'woocommerce';
            $classes[] = 'woocommerce-page';
        }

        return $classes;
    }

    /**
     * Enqueue scripts and styles for the frontend
     */
    public function front_scripts() {
        global $post;

        $page_ids = array(
            wc_get_page_id( 'account_addresses' ),
            wc_get_page_id( 'multiple_addresses' ),
            wc_get_page_id( 'myaccount' ),
            wc_get_page_id( 'checkout' ),
            wc_get_page_id( 'cart')
        );

        if ( !$post || ( $post && !in_array( $post->ID, $page_ids ) ) ) {
            return;
        }

        $user = wp_get_current_user();

        wp_enqueue_script( 'jquery',                null );
        wp_enqueue_script( 'jquery-ui-core',        null, array( 'jquery' ) );
        wp_enqueue_script( 'jquery-ui-mouse',       null, array( 'jquery-ui-core' ) );
        wp_enqueue_script( 'jquery-ui-draggable',   null, array( 'jquery-ui-core' ) );
        wp_enqueue_script( 'jquery-ui-droppable',   null, array( 'jquery-ui-core' ) );
        wp_enqueue_script( 'jquery-ui-datepicker',  null, array( 'jquery-ui-core' ) );
        wp_enqueue_script( 'jquery-masonry',        null, array('jquery-ui-core') );
        wp_enqueue_script( 'thickbox' );
        wp_enqueue_style(  'thickbox' );
        wp_enqueue_script( 'jquery-blockui' );

        // touchpunch to support mobile browsers
        wp_enqueue_script( 'jquery-ui-touch-punch', plugins_url( 'js/jquery.ui.touch-punch.min.js', WC_Ship_Multiple::FILE ), array('jquery-ui-mouse', 'jquery-ui-widget') );

        if ($user->ID != 0) {
            wp_enqueue_script( 'multiple_shipping_script', plugins_url( 'js/front.js', WC_Ship_Multiple::FILE) );

            wp_localize_script( 'multiple_shipping_script', 'WC_Shipping', array(
                    // URL to wp-admin/admin-ajax.php to process the request
                    'ajaxurl' => admin_url( 'admin-ajax.php' )
                )
            );

            $page_id = wc_get_page_id( 'account_addresses' );
            $url = get_permalink($page_id);
            $url = add_query_arg( 'height', '400', add_query_arg( 'width', '400', add_query_arg( 'addressbook', '1', $url)));
            ?>
            <script type="text/javascript">
                var address = null;
                var wc_ship_url = '<?php echo $url; ?>';
            </script>
        <?php
        }

        wp_enqueue_script( 'jquery-tiptip', plugins_url( 'js/jquery.tiptip.js', WC_Ship_Multiple::FILE ), array('jquery', 'jquery-ui-core') );

        wp_enqueue_script( 'modernizr', plugins_url( 'js/modernizr.js', WC_Ship_Multiple::FILE ) );
        wp_enqueue_script( 'multiple_shipping_checkout', plugins_url( 'js/woocommerce-checkout.js', WC_Ship_Multiple::FILE), array( 'woocommerce', 'jquery-ui-draggable', 'jquery-ui-droppable', 'jquery-ui-mouse', ) );

        if ( function_exists('wc_add_notice') ) {
            wp_localize_script( 'multiple_shipping_checkout', 'WCMS', apply_filters( 'wc_ms_multiple_shipping_checkout_locale', array(
                    // URL to wp-admin/admin-ajax.php to process the request
                    'ajaxurl'   => admin_url( 'admin-ajax.php' ),
                    'base_url'  => plugins_url( '', WC_Ship_Multiple::FILE),
                    'wc_url'    => WC()->plugin_url(),
                    'countries' => json_encode( array_merge( WC()->countries->get_allowed_country_states(), WC()->countries->get_shipping_country_states() ) ),
                    'select_state_text' => esc_attr__( 'Select an option&hellip;', 'woocommerce' ),
                ) )
            );

            if ( WC_MS_Compatibility::is_wc_version_gte('2.3') ) {
                wp_enqueue_script('select2', '//cdnjs.cloudflare.com/ajax/libs/select2/3.5.2/select2.min.js', array('jquery'), '3.5.2' );
                wp_enqueue_style('select2', '//cdnjs.cloudflare.com/ajax/libs/select2/3.5.2/select2.min.css', array(), '3.5.2' );
            }

            wp_register_script( 'wcms-country-select', plugins_url() .'/woocommerce-shipping-multiple-addresses/js/country-select.js', array( 'jquery' ), WC_VERSION, true );
            wp_localize_script( 'wcms-country-select', 'wcms_country_select_params', apply_filters( 'wc_country_select_params', array(
                'countries'              => json_encode( array_merge( WC()->countries->get_allowed_country_states(), WC()->countries->get_shipping_country_states() ) ),
                'i18n_select_state_text' => esc_attr__( 'Select an option&hellip;', 'woocommerce' ),
            ) ) );
            wp_enqueue_script('wcms-country-select');
        } else {
            wp_localize_script( 'multiple_shipping_checkout', 'WCMS', array(
                    // URL to wp-admin/admin-ajax.php to process the request
                    'ajaxurl'   => admin_url( 'admin-ajax.php' ),
                    'base_url'  => plugins_url( '', WC_Ship_Multiple::FILE),
                    'wc_url'    => WC()->plugin_url(),
                    'countries' => json_encode( WC()->countries->get_allowed_country_states() ),
                    'select_state_text' => esc_attr__( 'Select an option&hellip;', 'woocommerce' ),
                )
            );
        }

        wp_enqueue_style( 'multiple_shipping_styles', plugins_url( 'css/front.css', WC_Ship_Multiple::FILE) );
        wp_enqueue_style( 'tiptip', plugins_url( 'css/jquery.tiptip.css', WC_Ship_Multiple::FILE) );

        global $wp_scripts;
        $ui_version = $wp_scripts->registered['jquery-ui-core']->ver;
        wp_enqueue_style("jquery-ui-css", "//ajax.googleapis.com/ajax/libs/jqueryui/{$ui_version}/themes/ui-lightness/jquery-ui.min.css");

        // address validation support
        if ( class_exists('WC_Address_Validation') && is_page( wc_get_page_id('multiple_addresses') ) ) {
            $this->enqueue_address_validation_scripts();
        }

        // on the thank you page, remove the Shipping Address block if the order ships to multiple addresses
        if ( isset($_GET['order-received']) || isset($_GET['view-order']) ) {
            $order_id = isset($_GET['order-received']) ? intval( $_GET['order-received'] ) : intval( $_GET['view-order'] );
            $packages = get_post_meta($order_id, '_wcms_packages', true);
            $multiship= get_post_meta($order_id, '_multiple_shipping', true);

            if ( ($packages && count($packages) > 1) || $multiship == 'yes' ) {
                wp_enqueue_script( 'wcms_shipping_address_override', plugins_url( 'js/address-override.js', WC_Ship_Multiple::FILE ), array( 'jquery' ) );
            }
        }
    }

    /**
     * Address Validation scripts
     */
    public function enqueue_address_validation_scripts() {
        $suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

	    if ( function_exists( 'wc_address_validation' ) ) {
		    $validator  = wc_address_validation();
		    $handler    = $validator->get_handler_instance();
	    } else {
		    $validator  = $GLOBALS['wc_address_validation'];
		    $handler    = $validator->handler;
	    }

        $params = array(
            'nonce'                 => wp_create_nonce( 'wc_address_validation' ),
            'debug_mode'            => 'yes' == get_option( 'wc_address_validation_debug_mode' ),
            'force_postcode_lookup' => 'yes' == get_option( 'wc_address_validation_force_postcode_lookup' ),
            'ajax_url'              => admin_url( 'admin-ajax.php', 'relative' ),
        );

        // load postcode lookup JS
        $provider = $handler->get_active_provider();

        if ( $provider && $provider->supports( 'postcode_lookup') ) {

            wp_enqueue_script( 'wc_address_validation_postcode_lookup', $validator->get_plugin_url() . '/assets/js/frontend/wc-address-validation-postcode-lookup' . $suffix . '.js', array( 'jquery', 'woocommerce' ), WC_Address_Validation::VERSION, true );

            wp_localize_script( 'wc_address_validation_postcode_lookup', 'wc_address_validation_postcode_lookup', $params );
        }

        // load address validation JS
        if ( $provider && $provider->supports( 'address_validation' ) && 'WC_Address_Validation_Provider_SmartyStreets' == get_class( $provider ) ) {

            // load SmartyStreets LiveAddress jQuery plugin
            wp_enqueue_script( 'wc_address_validation_smarty_streets', '//d79i1fxsrar4t.cloudfront.net/jquery.liveaddress/2.4/jquery.liveaddress.min.js', array( 'jquery' ), '2.4', true );

            wp_enqueue_script( 'wcms_address_validation', plugins_url( 'js/address-validation.js', WC_Ship_Multiple::FILE ), array('jquery') );

            $params['smarty_streets_key'] = $provider->html_key;

            wp_localize_script( 'wcms_address_validation', 'wc_address_validation', $params );

            // add a bit of CSS to fix address correction popup from expanding to page width because of Chosen selects
            echo '<style type="text/css">.chzn-done{position:absolute!important;visibility:hidden!important;display:block!important;width:120px!important;</style>';
        }

        // allow other providers to load JS
        do_action( 'wc_address_validation_load_js', $provider, $handler, $suffix );
    }

    /**
     * Display a note if the order ships to multiple addresses
     *
     * @param int $order_id
     */
    public function show_multiple_addresses_notice($order_id) {
        $packages  = get_post_meta( $order_id, '_wcms_packages', true );

        if ( count($packages) <= 1 ) {
            return;
        }

        $page_id    = wc_get_page_id( 'multiple_addresses' );
        $url        = add_query_arg( 'order_id', $order_id, get_permalink( $page_id ) );
        ?>
        <div class="woocommerce_message woocommerce-message">
            <?php printf( __( 'This order ships to multiple addresses.  <a class="button" href="%s">View Addresses</a>', 'wc_shipping_multiple_address' ), $url ); ?>
        </div>
        <?php
    }

    /**
     * Include the shipping addresses in the order emails
     *
     * @param WC_Order $order
     */
    public function email_shipping_table($order) {
        $this->list_order_item_addresses( $order );
    }

    /**
     * Prints the table of items and their shipping addresses
     *
     * @param int|WC_Order $order_id
     */
    public function list_order_item_addresses( $order_id ) {

        if ( false == apply_filters( 'wcms_list_order_item_addresses', true, $order_id ) )
            return;

        if ( $order_id instanceof WC_Order ) {
            $order      = $order_id;
            $order_id   = $order->id;
        } else {
            $order = wc_get_order( $order_id );
        }

        $methods            = get_post_meta($order_id, '_shipping_methods', true);
        $shipping_methods   = $order->get_shipping_methods();
        $packages           = get_post_meta($order_id, '_wcms_packages', true);
        $multiship          = get_post_meta($order_id, '_multiple_shipping', true);

        if ( ( !$packages || count($packages) == 1 ) )
            return;

        // load the address fields
        $this->wcms->cart->load_cart_files();

        $cart = new WC_Cart();

        echo '<p><strong>'. __( 'This order ships to multiple addresses.', 'wc_shipping_multiple_address' ) .'</strong></p>';
        echo '<table class="shop_table shipping_packages" cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1" bordercolor="#eee">';
        echo '<thead><tr>';
        echo '<th scope="col" style="text-align:left; border: 1px solid #eee;">'. __( 'Products', 'woocommerce' ) .'</th>';
        echo '<th scope="col" style="text-align:left; border: 1px solid #eee;">'. __( 'Address', 'woocommerce' ) .'</th>';
        do_action( 'wc_ms_shop_table_head' );
        echo '<th scope="col" style="text-align:left; border: 1px solid #eee;">'. __( 'Notes', 'woocommerce' ) .'</th>';
        echo '</tr></thead><tbody>';

        foreach ( $packages as $x => $package ) {
            $products   = $package['contents'];
            $method     = $methods[$x]['label'];

            foreach ( $shipping_methods as $ship_method ) {
                if ($ship_method['method_id'] == $method) {
                    $method = $ship_method['name'];
                    break;
                }
            }

            $address = '';

            if ( ! empty( $package['destination'] ) ) {
	            $address = wcms_get_formatted_address( $package['destination'] );
            }

            ?>
            <tr>
                <td style="text-align:left; vertical-align:middle; border: 1px solid #eee;"><ul>
                        <?php foreach ( $products as $i => $product ): ?>
                            <li><?php echo apply_filters( 'wcms_product_title', get_the_title( $product['data']->id ), $product ) . ' &times; ' . $product['quantity'] . '<br />' . $cart->get_item_data( $product, true ); ?></li>
                        <?php endforeach; ?>
                    </ul></td>
                <td style="text-align:left; vertical-align:middle; border: 1px solid #eee;">
                    <?php echo $address; ?>
                    <br/>
                    <em>(<?php echo $method; ?>)</em>
                </td>
                <?php do_action( 'wc_ms_shop_table_row', $package, $order_id ); ?>
                <td style="text-align:left; vertical-align:middle; border: 1px solid #eee;">
                    <?php
                    if ( !empty( $package['note'] ) ) {
                        echo $package['note'];
                    } else {
                        echo '&ndash;';
                    }

                    if ( !empty( $package['date'] ) ) {
                        echo '<p>'. sprintf(__('Delivery date: %s', 'wc_shipping_multiple_address'), $package['date']) .'</p>';
                    }
                    ?>
                </td>
            </tr>
        <?php
        }
        echo '</table>';
    }

    /**
     * Show the current user's addresses in the my-account page
     */
    public function my_account() {
        $user = wp_get_current_user();

        if ($user->ID == 0) {
            return;
        }

        $page_id    = wc_get_page_id( 'account_addresses' );
        $form_link  = get_permalink( $page_id );
        $otherAddr  = $this->wcms->address_book->get_user_addresses( $user );

        wc_get_template(
            'my-account-addresses.php',
            array(
                'user'          => $user,
                'addresses'     => $otherAddr,
                'form_url'      => $form_link
            ),
            'multi-shipping',
            dirname( WC_Ship_Multiple::FILE ) .'/templates/'
        );
    }

	public function account_address_labels( $labels, $customer_id ) {
		$user = get_user_by( 'id', $customer_id );
		$addresses = $this->wcms->address_book->get_user_addresses( $user, false );

		$address_id = 0;

		foreach ( $addresses as $index => $address ) {
			$address_id++;

			$labels[ 'wcms_address_' . $index ] = sprintf( __('Shipping Address %d', 'wc_shipping_multiple_address' ), $address_id );
		}

		return $labels;
	}

	public function account_address_formatted( $address, $customer_id, $address_id ) {
		if ( strpos( $address_id, 'wcms_address_' ) === 0 ) {
			$user = get_user_by( 'id', $customer_id );
			$addresses = $this->wcms->address_book->get_user_addresses( $user, false );

			$parts = explode( '_', $address_id );
			$index = $parts[2];

			if ( isset( $addresses[ $index ] ) ) {
				$account_address = $addresses[ $index ];

				foreach ( $account_address as $key => $value ) {
					$key = str_replace( 'shipping_', '', $key );
					$account_address[ $key ] = $value;
				}

				$address = $account_address;
			}
		}

		return $address;
	}

	public function edit_address_field_value( $value, $key, $load_address ) {
		if ( strpos( $load_address, 'wcms_address_' ) === 0 ) {
			$user_id = get_current_user_id();
			$user = new WP_User( $user_id );
			$addresses = $this->wcms->address_book->get_user_addresses( $user, false );

			$parts = explode( '_', $load_address );
			$index = $parts[2];

			if ( ! isset( $addresses[ $index ] ) ) {
				return $value;
			}

			$key = str_replace( $load_address, 'shipping', $key );
			$value = $addresses[ $index ][ $key ];
		}

		return $value;
	}

	/**
	 * Save and and update a billing or shipping address if the
	 * form was submitted through the user account page.
	 */
	public function save_address() {
		global $wp;

		if ( 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}

		if ( empty( $_POST['action'] ) || 'edit_address' !== $_POST['action'] || empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'woocommerce-edit_address' ) ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		$load_address = isset( $wp->query_vars['edit-address'] ) ? wc_edit_address_i18n( sanitize_title( $wp->query_vars['edit-address'] ), true ) : 'billing';

		if ( strpos( $load_address, 'wcms_address_' ) !== 0 ) {
			return;
		}

		$address = WC()->countries->get_address_fields( esc_attr( $_POST[ $load_address . '_country' ] ), $load_address . '_' );

		foreach ( $address as $key => $field ) {

			if ( ! isset( $field['type'] ) ) {
				$field['type'] = 'text';
			}

			// Get Value.
			switch ( $field['type'] ) {
				case 'checkbox' :
					$_POST[ $key ] = isset( $_POST[ $key ] ) ? 1 : 0;
					break;
				default :
					$_POST[ $key ] = isset( $_POST[ $key ] ) ? wc_clean( $_POST[ $key ] ) : '';
					break;
			}

			// Hook to allow modification of value.
			$_POST[ $key ] = apply_filters( 'woocommerce_process_myaccount_field_' . $key, $_POST[ $key ] );

			// Validation: Required fields.
			if ( ! empty( $field['required'] ) && empty( $_POST[ $key ] ) ) {
				wc_add_notice( $field['label'] . ' ' . __( 'is a required field.', 'woocommerce' ), 'error' );
			}

			if ( ! empty( $_POST[ $key ] ) ) {

				// Validation rules
				if ( ! empty( $field['validate'] ) && is_array( $field['validate'] ) ) {
					foreach ( $field['validate'] as $rule ) {
						switch ( $rule ) {
							case 'postcode' :
								$_POST[ $key ] = strtoupper( str_replace( ' ', '', $_POST[ $key ] ) );

								if ( ! WC_Validation::is_postcode( $_POST[ $key ], $_POST[ $load_address . '_country' ] ) ) {
									wc_add_notice( __( 'Please enter a valid postcode/ZIP.', 'woocommerce' ), 'error' );
								} else {
									$_POST[ $key ] = wc_format_postcode( $_POST[ $key ], $_POST[ $load_address . '_country' ] );
								}
								break;
							case 'phone' :
								$_POST[ $key ] = wc_format_phone_number( $_POST[ $key ] );

								if ( ! WC_Validation::is_phone( $_POST[ $key ] ) ) {
									wc_add_notice( '<strong>' . $field['label'] . '</strong> ' . __( 'is not a valid phone number.', 'woocommerce' ), 'error' );
								}
								break;
							case 'email' :
								$_POST[ $key ] = strtolower( $_POST[ $key ] );

								if ( ! is_email( $_POST[ $key ] ) ) {
									wc_add_notice( '<strong>' . $field['label'] . '</strong> ' . __( 'is not a valid email address.', 'woocommerce' ), 'error' );
								}
								break;
						}
					}
				}
			}
		}

		if ( wc_notice_count( 'error' ) > 0 ) {
			wp_safe_redirect( wc_get_endpoint_url( 'edit-address', $load_address ) );
			exit;
		}

		$user       = new WP_User( $user_id );
		$addresses  = $this->wcms->address_book->get_user_addresses( $user );

		$parts = explode( '_', $load_address );
		$index = $parts[2];

		if ( ! isset( $addresses[ $index ] ) ) {
			return;
		}

		$user_address = $addresses[ $index ];

		foreach ( $address as $key => $field ) {
			$new_key  = str_replace( $load_address, 'shipping', $key );
			$flat_key = str_replace( $load_address . '_', '', $key );
			$user_address[ $new_key ]  = $_POST[ $key ];
			$user_address[ $flat_key ] = $_POST[ $key ];
		}

		$addresses[ $index ] = $user_address;

		$this->wcms->address_book->save_user_addresses( $user_id, $addresses );

		wc_add_notice( __( 'Address changed successfully.', 'woocommerce' ) );

		do_action( 'woocommerce_customer_save_address', $user_id, $load_address );

		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}

    /**
     * Generate inline scripts for wp_footer
     */
    public function inline_scripts() {
        $order_id = (isset($_GET['order'])) ? $_GET['order'] : false;

        if ( $order_id ):
            $order = wc_get_order( $order_id );

            if (method_exists($order, 'get_checkout_order_received_url')) {
                $page_id = $order->get_checkout_order_received_url();
            } else {
                $page_id = wc_get_page_id( 'thanks' );
            }

            $custom = $order->order_custom_fields;

            if ( is_page($page_id) && isset($custom['_shipping_addresses']) && isset($custom['_shipping_addresses'][0]) && !empty($custom['_shipping_addresses'][0]) ) {
                $html       = '<div>';
                $packages   = get_post_meta( $order_id, '_wcms_packages', true );

                foreach ( $packages as $package ) {
                    $html .= '<address>' . wcms_get_formatted_address( $package['destination'] ) . '</address><br /><hr/>';
                }
                $html .= '</div>';
                $html = str_replace( '"', '\"', $html);
                $html = str_replace("\n", " ", $html);
                ?>
                <script type="text/javascript">
                    jQuery(document).ready(function() {
                        jQuery(jQuery("address")[1]).replaceWith("<?php echo $html; ?>");
                    });
                </script>
            <?php
            }
        endif;
    }

}