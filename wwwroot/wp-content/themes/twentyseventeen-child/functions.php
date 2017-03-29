<?php

// 
// **** GET PARENT STYLES AND SCRIPTS
//

// Get parent styles
function twentyseventeen_child_styles() {

    $parent_style = 'twentyseventeen-style';

    wp_enqueue_style( $parent_style, get_template_directory_uri() . '/style.css' );
    wp_enqueue_style( 'child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array( $parent_style )
    );

}
add_action( 'wp_enqueue_scripts', 'twentyseventeen_child_styles' );

// Get parent JS scripts
function twentyseventeen_child_javascripts() {
    wp_enqueue_script('html5', get_template_directory_uri() . '/assets/js/html5.js', array(), '3.7.3');
    wp_enqueue_script('twentyseventeen-skip-link-focus-fix', get_template_directory_uri() . '/assets/js/skip-link-focus-fix.js',  array(), '1.0', true );
    wp_enqueue_script('html5', get_template_directory_uri() . '/assets/js/html5.js', false);
    wp_enqueue_script('html5', get_template_directory_uri() . '/assets/js/html5.js', false);

    if ( has_nav_menu( 'top' ) ) {
        wp_enqueue_script( 'twentyseventeen-navigation', get_template_directory_uri() . '/assets/js/navigation.js', array(), '1.0', true );
        $twentyseventeen_l10n['expand']         = __( 'Expand child menu', 'twentyseventeen' );
        $twentyseventeen_l10n['collapse']       = __( 'Collapse child menu', 'twentyseventeen' );
        $twentyseventeen_l10n['icon']           = twentyseventeen_get_svg( array( 'icon' => 'angle-down', 'fallback' => true ) );
    }

    wp_enqueue_script( 'twentyseventeen-global', get_template_directory_uri() . '/assets/js/global.js', array( 'jquery' ), '1.0', true );

    wp_enqueue_script( 'jquery-scrollto', get_template_directory_uri() . '/assets/js/jquery.scrollTo.js', array( 'jquery' ), '2.1.2', true );

    wp_localize_script( 'twentyseventeen-skip-link-focus-fix', 'twentyseventeenScreenReaderText', $twentyseventeen_l10n );

    if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
        wp_enqueue_script( 'comment-reply' );
    }
}
add_action('wp_enqueue_scripts', 'twentyseventeen_child_javascripts');




// 
// **** ADD ADDITIONAL USER FIELDS
// 

// This modifies the username to the customer's fullname
// Comment out filter and function to use email instead
add_filter( 'wc_csv_import_suite_create_customer_data', 'jrwdev_add_custom_username', 10, 3 );
function jrwdev_add_custom_username( $data, $options, $this ) {
    $username = strtolower($data['first_name']) . strtolower($data['last_name']);  
    $data['username'] = $username;
    return $data;
}

// After registering user, adds non-WC fields
// This is for customers created manually (not through import)
add_action( 'user_register', 'jrwdev_add_additional_customer_fields', 10, 3 );
function jrwdev_add_additional_customer_fields( $user_id ) {
    $user = get_userdata( $user_id );
    $user_meta = get_user_meta($user_id);

    if( count($user) > 0 && in_array('customer', $user->roles) ) {
        // CUSTOMER_ID
        // If not an imported customer, use the incremented customer_id function
        if( !isset($user_meta['customer_id']) ) {
            add_incremented_customer_id( $user );
        }

        // COMPANY (blank string by default)
        if( !isset($user_meta['company']) ) {
            update_user_meta( $user_id, 'company', '' );
        }

        // PHONE (0 by default)
        if( !isset($user_meta['phone']) ) {
            update_user_meta( $user_id, 'phone', '' );
        }

        // NOTES (blank string by default)
        if( !isset($user_meta['notes']) ) {
            update_user_meta( $user_id, 'notes', '' );
        }

        // CUSTOMER_GROUP (blank string by default)
        if( !isset($user_meta['customer_group']) ) {
            update_user_meta( $user_id, 'customer_group', '' );
        }
    }  
}

// Helper: ensures user has the latest customer_id
// Seems inefficient
function add_incremented_customer_id( $user ) {
    // Try to find the latest customer
    $args = array(
        'role'          => 'customer',
        'meta_key'      => 'latest_customer',
        'meta_value'    => 'true',
        'number'        => 1
    );
    $latest_customer_search = get_users( $args );

    $latest_customer = null;   
    if( count($latest_customer_search) > 0 ) {
        $latest_customer = $latest_customer_search[0];   
    }

    if( isset($latest_customer) && $latest_customer->ID != $user->ID ) {
        // If latest customer is found, get its customer id and name this user as the latest
        $latest_customer_meta = get_user_meta($latest_customer->ID);
        $raw_customer_id = $latest_customer_meta['customer_id'];
        $highest_customer_id = intval($raw_customer_id[0]);
        update_user_meta( $latest_customer->ID, 'latest_customer', 'false' );

        // Increment customer id, then check if it exists; loop until unique
        $id_exists = true;
        while ( $id_exists === true ) {
            $highest_customer_id += 1;
            $customer_id_search = get_users( array( 'meta_key' => 'customer_id', 'meta_value' => (string)$highest_customer_id ) );
            
            if( count($customer_id_search) === 0 ) {
                $id_exists = false;
            } 
        }

        update_user_meta( $user->ID, 'customer_id', $highest_customer_id );
        update_user_meta( $user->ID, 'latest_customer', 'true' );
    } else {
        // If not found, name this user latest and initialize customer id to 1 
        update_user_meta( $user->id, 'customer_id', 1 );
        update_user_meta( $user->id, 'latest_customer', 'true' );
    }
}


// 
// **** SHOW/UPDATE ADDITIONAL USER META FIELDS
// 

// Show extra fields on the user profile page
add_action( 'show_user_profile', 'jrwdev_show_extra_profile_fields' );
add_action( 'edit_user_profile', 'jrwdev_show_extra_profile_fields' );

function jrwdev_show_extra_profile_fields( $user ) { 
    // May want to restrict to admin access?

    $user_data = get_userdata( $user->ID );
    if( in_array( 'customer', $user_data->roles ) ) { ?> 

        <h3>Additional profile information</h3>

        <table class="form-table">

            <tr>
                <th><label for="customer_id">Customer ID</label></th>

                <td>
                    <input type="text" name="customer_id" id="customer_id" value="<?php echo esc_attr( get_the_author_meta( 'customer_id', $user->ID ) ); ?>" class="regular-text" /><br />
                    <span class="description"></span>
                </td>
            </tr>

            <tr>
                <th><label for="user_phone">Phone</label></th>

                <td>
                    <input type="text" name="user_phone" id="user_phone" value="<?php echo esc_attr( get_the_author_meta( 'phone', $user->ID ) ); ?>" class="regular-text" /><br />
                    <span class="description"></span>
                </td>
            </tr>

            <tr>
                <th><label for="user_company">Company</label></th>

                <td>
                    <input type="text" name="user_company" id="user_company" value="<?php echo esc_attr( get_the_author_meta( 'company', $user->ID ) ); ?>" class="regular-text" /><br />
                    <span class="description"></span>
                </td>
            </tr>

            <tr>
                <th><label for="user_notes">Notes</label></th>

                <td>
                    <textarea style="width:350px" type="text" name="user_notes" id="user_notes" class="regular-text" /><?php echo esc_attr( get_the_author_meta( 'notes', $user->ID ) ); ?></textarea><br />
                    <span class="description"></span>
                </td>
            </tr>

            <tr>
                <th><label for="customer_group">Customer Group</label></th>

                <td>
                    <input type="text" name="customer_group" id="customer_group" value="<?php echo esc_attr( get_the_author_meta( 'customer_group', $user->ID ) ); ?>" class="regular-text" /><br />
                    <span class="description"></span>
                </td>
            </tr>

            <tr>
                <th><label for="birth_date">Birth Date</label></th>

                <td>
                    <input type="text" name="birth_date" id="birth_date" value="<?php echo esc_attr( get_the_author_meta( 'birth_date', $user->ID ) ); ?>" class="regular-text" /><br />
                    <span class="description"></span>
                </td>
            </tr>
            
            <?php 
                $marketing_emails = get_the_author_meta( 'receive_marketing_emails', $user->ID );
                $marketing_emails_text = $marketing_emails === '1' ? 'Yes' : 'No'; 
            ?>
            <tr>
                <th><label for="receive_marketing_emails">Receive Marketing Emails?</label></th>

                <td>
                    <input type="text" name="receive_marketing_emails" id="receive_marketing_emails" value="<?php echo $marketing_emails_text; ?>" class="regular-text" /><br />
                    <span class="description"></span>
                </td>
            </tr>

            <tr>
                <th><label for="tax_exempt_category">Tax Exempt Category</label></th>

                <td>
                    <input type="text" name="tax_exempt_category" id="tax_exempt_category" value="<?php echo esc_attr( get_the_author_meta( 'tax_exempt_category', $user->ID ) ); ?>" class="regular-text" /><br />
                    <span class="description"></span>
                </td>
            </tr>

        </table>
<?php }    
}


add_action( 'personal_options_update', 'jrwdev_save_extra_profile_fields' );
add_action( 'edit_user_profile_update', 'jrwdev_save_extra_profile_fields' );

function jrwdev_save_extra_profile_fields( $user_id ) {
    update_user_meta( $user_id, 'customer_id',              $_POST['customer_id'] );
    update_user_meta( $user_id, 'user_company',             $_POST['user_company'] );
    update_user_meta( $user_id, 'user_phone',               $_POST['user_phone'] );
    update_user_meta( $user_id, 'user_notes',               $_POST['user_notes'] );
    update_user_meta( $user_id, 'customer_group',           $_POST['customer_group'] );
    update_user_meta( $user_id, 'birth_date',               $_POST['birth_date'] );
    update_user_meta( $user_id, 'receive_marketing_emails', $_POST['receive_marketing_emails'] );
    update_user_meta( $user_id, 'tax_exempt_category',      $_POST['tax_exempt_category'] );
}



// 
// **** ALTER CSV IMPORT FIELD MAPPINGS
// 

// Alter WC's mappings to correctly auto-populate the import Field Mapping screen
add_filter( "wc_csv_import_suite_column_mapping_options", "jrwdev_filter_default_mappings", 11, 5 );
function jrwdev_filter_default_mappings( $mapping_options, $importer, $headers, $raw_headers, $columns ) {

    return array(

        __( 'User data', 'woocommerce-csv-import-suite' ) => array(
            'id'              => __( 'User ID', 'woocommerce-csv-import-suite' ),
            'email'           => __( 'Email', 'woocommerce-csv-import-suite' ),
            'password'        => __( 'Password', 'woocommerce-csv-import-suite' ),
            'first_name'      => __( 'First Name', 'woocommerce-csv-import-suite' ),  
            'last_name'       => __( 'Last Name', 'woocommerce-csv-import-suite' ), 
            'phone'           => __( 'Phone', 'woocommerce-csv-import-suite' ), 
            'date_joined'     => __( 'Date Joined', 'woocommerce-csv-import-suite' ),
        ),

        __( 'Customer data', 'woocommerce-csv-import-suite' ) => array(
            'addresses'                 => __( 'Addresses', 'woocommerce-csv-import-suite' ),
            'customer_id'               => __( 'Customer ID', 'woocommerce-csv-import-suite' ),
            'company'                   => __( 'Company', 'woocommerce-csv-import-suite' ),
            'notes'                     => __( 'Notes', 'woocommerce-csv-import-suite' ),
            'customer_group'            => __( 'Customer Group', 'woocommerce-csv-import-suite' ),
            'birth_date'                => __( 'Birth Date', 'woocommerce-csv-import-suite' ),
            'tax_exempt_category'       => __( 'Tax Exempt Category', 'woocommerce-csv-import-suite' ),
            'receive_marketing_emails'  => __( 'Receive Marketing Emails', 'woocommerce-csv-import-suite' ),
            'customer_name'             => __( 'Customer Name', 'woocommerce-csv-import-suite' ),  
            'store_credit'              => __( 'Store Credit', 'woocommerce-csv-import-suite' ),     
        ),

    );
}

// 
// **** MANAGE IMPORTED DATA
// 

add_filter( 'wc_csv_import_suite_parsed_customer_data', 'jrwdev_manage_imported_data', 10, 4 );
function jrwdev_manage_imported_data( $user, $item, $options, $raw_headers ) {
    $user['user_meta']['customer_id']               = $item['customer_id'];
    $user['user_meta']['company']                   = $item['company'];
    $user['user_meta']['phone']                     = $item['phone'];
    $user['user_meta']['notes']                     = $item['notes'];
    $user['user_meta']['customer_group']            = $item['customer_group'];
    $user['user_meta']['receive_marketing_emails']  = $item['receive_marketing_emails'];
    $user['user_meta']['tax_exempt_category']       = $item['tax_exempt_category'];
    $user['date_registered']                        = $item['date_joined'];

    if( isset($item['addresses']) && $item['addresses'] != '' && $item['addresses'] != array() ) {
        $user = parse_imported_csv_addresses( $user, $item['addresses'] );
    }

    $store_credit = $item['store_credit'];
    if( floatval($store_credit) > 0 ) {
        create_store_credit_coupon( $user['email'], $store_credit ); 
    } 

    return $user;
}

// Parse addresses in CSV (delimiter => "|") and RETURN values as associate array
// REQUIRES WooCommerce extension Multiple Shipping Addresses
function parse_imported_csv_addresses( $user, $raw_addresses ) {
    $other_shipping_addresses = array();
    $addresses = explode('|', $raw_addresses);

    foreach ($addresses as $i => $address) {
        $address_elements = explode(",", $address);
        
        foreach($address_elements as $address_element) {

            // Separate and clean up address fields and values
            list($raw_field, $raw_value) = explode(":", $address_element);
            $field = strtolower(str_replace(" ", "_", trim($raw_field))); 
            $value = trim($raw_value);

            // Remove unnecessary "address_" prefix
            if( strpos( $field, 'address_' ) !== false && 'address_id' != $field ) {
                $field = str_replace( 'address_', '', $field );
            }

            // Translate field names to WC API
            switch($field) {

                case 'line_1':
                    $field = 'address_1';
                    break;
                case 'line_2':
                    $field = 'address_2';
                    break;
                case 'city/suburb':
                    $field = 'city';
                    break;
                case 'state/province':
                    $field = 'state';
                    break;
                case 'state_abbreviation':
                    $field = 'state';
                    break;
                case 'zip/postcode':
                    $field = 'postcode';
                    break;

            }

            if($value === 'United States') {
                $value = 'US';
            }

            // If first address in list, set as both billing and shipping
            if($i === 0) {
                // Hack: the standard address country field seems to prefer abbv. country name
                $user['billing_address'][$field] = $value;
                $user['shipping_address'][$field] = $value;
            } else {
                // Add additional addresses to meta data per WC api
                $adjusted_i = $i - 1;

                // The Multiple Shipping Addresses extension requires adding "shipping_" prefix
                $field = 'shipping_' . $field;
                $other_shipping_addresses[$adjusted_i][$field] = $value;
            } 

        }
    }

    $user['user_meta']['wc_other_addresses'] = $other_shipping_addresses;
    
    return $user;
}

// Copied from WC_Store_Credit_Plus_Admin -> 
// REQUIRES WooCommerce Store Credit Plugin
function create_store_credit_coupon( $email, $amount ) {
    $coupon_code   = uniqid( sanitize_title( $email ) );
    $new_credit_id = wp_insert_post( array(
        'post_title' => $coupon_code,
        'post_content' => '',
        'post_status' => 'publish',
        'post_author' => 1,
        'post_type'  => 'shop_coupon'
    ) );

    // Add meta
    update_post_meta( $new_credit_id, 'discount_type', 'store_credit' );
    update_post_meta( $new_credit_id, 'coupon_amount', $amount );
    update_post_meta( $new_credit_id, 'individual_use', get_option( 'woocommerce_store_credit_individual_use', 'no' ) );
    update_post_meta( $new_credit_id, 'product_ids', '' );
    update_post_meta( $new_credit_id, 'exclude_product_ids', '' );
    update_post_meta( $new_credit_id, 'usage_limit', '' );
    update_post_meta( $new_credit_id, 'expiry_date', '' );
    update_post_meta( $new_credit_id, 'apply_before_tax', get_option( 'woocommerce_store_credit_apply_before_tax', 'no' ) );
    update_post_meta( $new_credit_id, 'free_shipping', 'no' );

    // Meta for coupon owner
    update_post_meta( $new_credit_id, 'customer_email', array( $email ) );

    return $coupon_code;
}


//
// **** TOOLS AND TESTS
// 

add_action( 'init', 'jrwdev_user_data_testing' );
function jrwdev_user_data_testing() {
    // write_log('$_POST data at init');
    // write_log($_POST);

    $args = array(
        'role' => 'customer'
    );
    $all_customers = get_users( $args );

    foreach ($all_customers as $index => $customer) {
        $customer_user_data = get_userdata($customer->ID);
        write_log('user data for customer ' . $customer->ID . ' from jrwdev_show_user_meta');
        write_log($customer_user_data);   

        $customer_user_meta = get_user_meta($customer->ID);
        write_log('user meta data for customer ' . $customer->ID . ' from jrwdev_show_user_meta');
        write_log($customer_user_meta);
    }
}


// ** Short cut link to csv import field mapping 
// http://wp-wads-user-importer.dev/wp-admin/admin.php?import=woocommerce_customer_csv&step=3&file=%2FUsers%2Fdavidchappy%2FGoogleDrive%2FCode%2Fhtdocs%2Fsites%2Fwp-wads-user-importer%2Fwwwroot%2Fwp-content%2Fuploads%2F2017%2F03%2FGoal-Csv-Table-1.csv-4.txt&options%5Binsert_non_matching%5D=1&options%5Bdelimiter%5D=%2C

// logging to debug.log
if (!function_exists('write_log')) {
    function write_log ( $log ) {
        if ( true === WP_DEBUG ) {
            if ( is_array($log) || is_object( $log ) ) {
                error_log( print_r($log, true ) );
            } else {
                error_log( $log );
            }
        }
    }
}
?>