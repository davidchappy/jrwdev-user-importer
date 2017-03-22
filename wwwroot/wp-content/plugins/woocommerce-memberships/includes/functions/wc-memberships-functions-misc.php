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
 * Encode a variable into JSON via wp_json_encode() if available,
 * fall back to json_encode otherwise
 *
 * `json_encode()` may fail and return `null` in some environments,
 * especially in installations with character encoding issues
 *
 * @internal
 *
 * @since 1.6.0
 * @param mixed $data Variable (usually an array or object) to encode as JSON
 * @param int $options Optional. Options to be passed to json_encode(). Default 0
 * @param int $depth Optional. Maximum depth to walk through $data. Must be greater than 0. Default 512
 * @return bool|string The JSON encoded string, or false if it cannot be encoded
 */
function wc_memberships_json_encode( $data, $options = 0, $depth = 512 ) {

	// TODO deprecate this as part of WooCommerce 2.7 compatibility release {FN 2016-05-27}
	// _deprecated_function( 'wc_memberships_json_encode', '1.6.0', 'wp_json_encode' );

	return function_exists( 'wp_json_encode' ) ? wp_json_encode( $data, $options, $depth ) : json_encode( $data, $options, $depth );
}


/**
 * Creates a human readable list of an array
 *
 * @since 1.6.0
 * @param string[] $items array to list items of
 * @param string|void $conjunction optional. The word to join together the penultimate and last item. Defaults to 'or'
 * @return string e.g. "item1, item2, item3 or item4"
 */
function wc_memberships_list_items( $items, $conjunction = '' ) {

	if ( ! $conjunction ) {
		$conjunction = __( 'or', 'woocommerce-memberships' );
	}

	array_splice( $items, -2, 2, implode( ' ' . $conjunction . ' ', array_slice( $items, -2, 2 ) ) );

	return implode( ', ', $items );
}


/**
 * Get the label of a post type
 *
 * e.g. 'some_post-type' becomes 'Some Post Type Name'
 *
 * @since 1.6.2
 * @param \WP_Post $post
 * @return string
 */
function wc_memberships_get_content_type_name( $post ) {

	// sanity check
	if ( ! isset( $post->post_type ) ) {
		return '';
	}

	$post_type_object = get_post_type_object( $post->post_type );

	return ucwords( $post_type_object->labels->singular_name );
}
