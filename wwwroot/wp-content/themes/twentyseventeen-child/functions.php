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


add_filter( 'user_register', 'jrwdev_add_additional_customer_fields', 10, 3 );
function jrwdev_add_additional_customer_fields( $user_id ) {
    $user = get_userdata( $user_id );
    $user_meta = get_user_meta($user_id);

    if( count($user) > 0 && in_array('customer', $user->roles) ) {
        // write_log("Add meta fields to a customer...");
        // $user_meta = get_user_meta($user_id);

        // CUSTOMER_ID
        // If not an imported customer, use the incremented customer_id funcdtion
        if( !isset($user_meta['customer_id']) ) {
            add_incremented_customer_id( $user );
        }

        // COMPANY (blank string by default)
        if( !isset($user_meta['company']) ) {
            update_user_meta( $user_id, 'company', '' );
        }

        // PHONE (0 by default)
        if( !isset($user_meta['phone']) ) {
            update_user_meta( $user_id, 'phone', 0 );
        }

        // NOTES (blank string by default)
        if( !isset($user_meta['notes']) ) {
            update_user_meta( $user_id, 'notes', '' );
        }

        // CUSTOMER_GROUP (blank string by default)
        if( !isset($user_meta['customer_group']) ) {
            update_user_meta( $user_id, 'customer_group', '' );
        }

        $user_meta = get_user_meta($user_id);

        // write_log('$user_meta from jrwdev_add_additional_customer_fields after adding fields');
        // write_log($user_meta);

        // return true;
    }  
}

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
        // write_log('$latest_customer from add_incremented_customer_id');
        // write_log($latest_customer); 
        // write_log('$user from add_incremented_customer_id');
        // write_log($user); 
    }

    if( isset($latest_customer) && $latest_customer->ID != $user->ID ) {
        // If found, name this user as the latest and increment customer id by 1
        $latest_customer_meta = get_user_meta($latest_customer->ID);
        $highest_customer_id = intval($latest_customer_meta['customer_id']);
        // write_log('setting customer id in add_incremented_customer_id');
        update_user_meta( $latest_customer->ID, 'latest_customer', 'false' );
        $highest_customer_id += 1;
        update_user_meta( $user->ID, 'customer_id', $highest_customer_id, true );
        update_user_meta( $user->ID, 'latest_customer', 'true' );
    } else {
        // If not found, name this user latest and initialize customer id to 1 
        update_user_meta( $user->id, 'customer_id', 1, true );
        update_user_meta( $user->id, 'latest_customer', 'true' );
    }

    $updated_user = get_userdata($user->id);
    // write_log('$updated_user from add_incremented_customer_id');
    // write_log($updated_user);
}

// add_filter( 'wc_csv_import_suite_create_customer_data', 'jrwdev_update_additional_customer_fields', 10, 3 );
// add_action( 'wc_csv_import_suite_create_customer', 'inspect_new_customer', 10, 3 );
// function inspect_new_customer($id, $data, $options) {
//     write_log('This is $data from inspect_new_customer');
//     write_log($data);
// };



// 
// **** SHOW ADDITIONAL USER META FIELDS
// 

// Show extra fields on the user profile page
add_action( 'show_user_profile', 'jrwdev_show_extra_profile_fields' );
add_action( 'edit_user_profile', 'jrwdev_show_extra_profile_fields' );

function jrwdev_show_extra_profile_fields( $user ) { ?>

    <h3>Extra profile information</h3>

    <table class="form-table">

        <tr>
            <th><label for="random_attribute">Random Attribute</label></th>

            <td>
                <input type="text" name="random_attribute" id="random_attribute" value="<?php echo esc_attr( get_the_author_meta( 'random_attribute', $user->ID ) ); ?>" class="regular-text" /><br />
                <span class="description">This is Your Random Attribute.</span>
            </td>
        </tr>

    </table>
<?php }

add_action( 'personal_options_update', 'jrwdev_save_extra_fields' );
add_action( 'edit_user_profile_update', 'jrwdev_save_extra_fields' );

function jrwdev_save_extra_fields( $id ) {
    update_user_meta( $id, 'random_attribute', $_POST['random_attribute'] );
}

// add_action( 'personal_options_update', 'jrwdev_add_extra_profile_fields' );
// add_action( 'edit_user_profile_update', 'jrwdev_add_extra_profile_fields' );
add_action( 'woocommerce_customer_meta_fields', 'jrwdev_add_extra_profile_fields' );
function jrwdev_add_extra_profile_fields( $customer_fields ) {
    // write_log('THIS IS $customer_fields FROM jrwdev_add_extra_profile_fields');
    // write_log($customer_fields);

    // update_user_meta( $user_id, 'customer_id',      $_POST['customer_id'] );
    // update_user_meta( $user_id, 'company',          $_POST['company'] );
    // update_user_meta( $user_id, 'phone',            $_POST['phone'] );
    // update_user_meta( $user_id, 'notes',            $_POST['notes'] );
    // update_user_meta( $user_id, 'customer_group',   $_POST['customer_group'] );
    // update_user_meta( $user_id, 'date_joined',      $_POST['date_joined'] );
    // update_user_meta( $user_id, 'birth_date',       $_POST['birth_date'] );
    // $customer_fields['customer_id'] = array(
    //     'label'         => 'Customer ID',
    //     'description'   => ''
    // );
    return $customer_fields;
}






// 
// **** ALTER CSV IMPORT FIELD MAPPINGS
// 

// Alter WC's mappings to correctly auto-populate the import Field Mapping screen
add_filter( "wc_csv_import_suite_column_mapping_options", "jrwdev_filter_default_mappings", 11, 5 );

function jrwdev_filter_default_mappings( $mapping_options, $importer, $headers, $raw_headers, $columns ) {
    // write_log('THIS IS $mapping_options FROM jrwdev_filter_default_mappings');
    // write_log($mapping_options);

    // write_log('THIS IS $importer FROM jrwdev_filter_default_mappings');
    // write_log($importer);

    // write_log('THIS IS $headers FROM jrwdev_filter_default_mappings');
    // write_log($headers);

    // write_log('THIS IS $raw_headers FROM jrwdev_filter_default_mappings');
    // write_log($raw_headers);

    // write_log('THIS IS $columns FROM jrwdev_filter_default_mappings');
    // write_log($columns);
    // 

    $billing_prefix  = __( 'Billing: %s',  'woocommerce-csv-import-suite' );
    $shipping_prefix = __( 'Shipping: %s', 'woocommerce-csv-import-suite' );

    return array(

        __( 'User data', 'woocommerce-csv-import-suite' ) => array(
            'id'              => __( 'User ID', 'woocommerce-csv-import-suite' ),
            'email'           => __( 'Email', 'woocommerce-csv-import-suite' ),
            'password'        => __( 'Password', 'woocommerce-csv-import-suite' ),
            'first_name'      => __( 'First Name', 'woocommerce-csv-import-suite' ),  
            'last_name'       => __( 'Last Name', 'woocommerce-csv-import-suite' ), 
            'phone'           => __( 'Phone', 'woocommerce-csv-import-suite' ), 
            // 'date_registered' => __( 'Date Joined', 'woocommerce-csv-import-suite' ),
            // 'role'            => __( 'Role', 'woocommerce-csv-import-suite' ),
            // 'url'             => __( 'URL', 'woocommerce-csv-import-suite' ),
        ),

        __( 'Customer data', 'woocommerce-csv-import-suite' ) => array(
            'addresses'                 => __( 'Addresses', 'woocommerce-csv-import-suite' ),
            'customer_id'               => __( 'Customer ID', 'woocommerce-csv-import-suite' ),
            'company'                   => __( 'Company', 'woocommerce-csv-import-suite' ),
            'notes'                     => __( 'Notes', 'woocommerce-csv-import-suite' ),
            'customer_group'            => __( 'Customer Group', 'woocommerce-csv-import-suite' ),
            'birth_date'                => __( 'Birth Date', 'woocommerce-csv-import-suite' ),
            'tax_exempt_category'       => __( 'Tax Exempt Category', 'woocommerce-csv-import-suite' ),
            'date_joined'               => __( 'Date Joined', 'woocommerce-csv-import-suite' ),
            'receive_marketing_emails'  => __( 'Receive Marketing Emails', 'woocommerce-csv-import-suite' ),
            'customer_name'             => __( 'Customer Name', 'woocommerce-csv-import-suite' ),  
            'store_credit'              => __( 'Store Credit', 'woocommerce-csv-import-suite' ),     
        ),

    );
}

// add_action( 'wc_csv_import_suite_before_import_column_mapper', 'jrwdev_filter_out_mappings');
// function jrwdev_filter_out_mappings( $csv_importer ) {
//     write_log('$csv_importer from jrwdev_filter_out_mappings');
//     write_log($csv_importer); 
//     // array_splice( $default_mapping['Customer data'], 9 );
// }


// 
// **** MANAGE IMPORTED DATA
// 


add_filter( 'wc_csv_import_suite_parsed_customer_data', 'jrwdev_manage_imported_data', 10, 4 );
function jrwdev_manage_imported_data( $user, $item, $options, $raw_headers ) {
    // write_log('user $user jrwdev_manage_imported_data');
    // write_log($user);  

    // write_log('user $item jrwdev_manage_imported_data');
    // write_log($item);  

    // write_log('user $options jrwdev_manage_imported_data');
    // write_log($options);     

    // write_log('user $raw_headers jrwdev_manage_imported_data');
    // write_log($raw_headers);

    $user['user_meta']['customer_id']               = $item['customer_id'];
    $user['user_meta']['company']                   = $item['company'];
    $user['user_meta']['phone']                     = $item['phone'];
    $user['user_meta']['notes']                     = $item['notes'];
    $user['user_meta']['customer_group']            = $item['customer_group'];
    $user['user_meta']['date_joined']               = $item['date_joined'];
    $user['user_meta']['receive_marketing_emails']  = $item['receive_marketing_emails'];
    $user['user_meta']['tax_exempt_category']       = $item['tax_exempt_category'];

    if( isset($item['addresses']) && $item['addresses'] != '' ) {
        $user = add_imported_csv_addresses( $user, $item['addresses'] );
    }

    $store_credit = $item['store_credit'];
    if( floatval($store_credit) > 0 ) {
        create_store_credit_coupon( $user['email'], $store_credit ); 
    }

    return $user;
}

// Parse addresses in CSV (delimiter => "|") and RETURN values as associate array
function add_imported_csv_addresses( $user, $raw_addresses ) {
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
            if( strpos( $field, 'address_' ) !== false ) {
                if( 'address_id' != $field ) {
                    $field = str_replace( 'address_', '', $field );
                }
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

            // Translate value names to WC API
            switch($value) {

                case 'United States':
                    $value = 'US';
                    break;
            }


            // If first address in list, set as both billing and shipping
            if($i === 0) {
                $user['billing_address'][$field] = $value;
                $user['shipping_address'][$field] = $value;
            } else {
                // Add additional addresses to meta data per WC api
                $adjusted_i = $i - 1;
                $other_shipping_addresses[$adjusted_i][$field] = $value;
            } 

        }
    }

    $user['user_meta']['wc_other_addresses'] = $other_shipping_addresses;

    write_log("Checking $user after add_imported_csv_addresses function");
    write_log($user);
    
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

// add_action( 'init', 'jrwdev_user_data_testing' );
// function jrwdev_user_data_testing() {
//     $args = array(
//         'role' => 'customer'
//     );
//     $all_customers = get_users( $args );

//     foreach ($all_customers as $index => $customer) {
//         $customer_user_data = get_userdata($customer->ID);
//         write_log('user data for customer ' . $customer->ID . ' from jrwdev_show_user_meta');
//         write_log($customer_user_data);   

//         $customer_user_meta = get_user_meta($customer->ID);
//         write_log('user meta data for customer ' . $customer->ID . ' from jrwdev_show_user_meta');
//         write_log($customer_user_meta);
//     }
// }


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