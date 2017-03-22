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
 * Member Discounts class
 *
 * This class handles all purchasing discounts for members
 *
 * @since 1.3.0
 */
class WC_Memberships_Member_Discounts {


	/** @var array Lazy loading for member product discount information */
	private $member_has_product_discount = array();

	/** @var array Memoization for product discounts */
	private $product_discount = array();

	/** @var array Memoization for variation product discounts */
	private $product_discount_variation = array();

	/** @var array Memoization for product discounts exclusion */
	private $product_excluded_from_discounts = array();

	/** @var bool Whether products on sale are excluded from discounts */
	private $exclude_on_sale_products = false;

	/** @var array Memoization for product on sale before discount */
	private $product_is_on_sale_before_discount = array();

	/** @var bool Whether the current user, maybe member, is logged in */
	private $member_is_logged_in = false;

	/** @var bool Whether discounts calculations are running for products  */
	private $applying_discounts = false;


	/**
	 * Set up member discounts: Welcome to the jungle.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {

		// bail out if in an admin ajax context
		if ( ! $this->is_wp_admin_ajax() ) {

			// init discounts so we don't hook to early
			add_action( 'init', array( $this, 'init' ) );
		}
	}


	/**
	 * Init member discounts
	 *
	 * We follow here a pattern common in many price-affecting extensions,
	 * due to the need to produce a "price before/after discount" type of HTML output,
	 * so shop customers can easily understand the deal they're being offered.
	 *
	 * To do so we need to juggle WooCommerce prices, we start off by instantiating
	 * this class with our discounts active, so we can be sure to always pass those
	 * to other extensions if a member is logged in. Then, when we want to show prices
	 * in front end we need to deactivate price modifications, compare the original
	 * price with the price resulting from discount calculations and if a discount is
	 * found (price difference) we strikethrough the original price to show what it was
	 * like before discount, so we reactivate price modifiers, and finally show prices
	 * after modifications.
	 *
	 * Extensions and third party code that need to know if Memberships price modifiers
	 * are being applied or not in these two phases, can use doing_action and hook into
	 * 'wc_memberships_discounts_enable_price_adjustments' and
	 * 'wc_memberships_discounts_disable_price_adjustments' (and their html counterparts)
	 * or call directly the callbacks found in this class, which we use to add and remove
	 * price modifier filters. Or, if there's need to deactivate or activate Memberships
	 * price modifiers directly, the public callback methods that these actions use could
	 * also be invoked for this purpose.
	 *
	 * @see \WC_Memberships_Member_Discounts::enable_price_adjustments()
	 * @see \WC_Memberships_Member_Discounts::disable_price_adjustments()
	 *
	 * @internal
	 *
	 * @since 1.7.1
	 */
	public function init() {

		$this->member_is_logged_in      = wc_memberships_is_user_member( get_current_user_id() );
		$this->exclude_on_sale_products = 'yes' === get_option( 'wc_memberships_exclude_on_sale_products_from_member_discounts', 'no' );

		// refreshes the mini cart upon member login
		add_action( 'wp_login', array( $this, 'refresh_cart_upon_member_login' ), 10, 2 );

		// member discount class methods are available on both frontend and backend
		// but the hooks below should run in frontend only for logged in members
		if ( ! ( is_admin() && ! is_ajax() ) ) {

			if ( $this->member_is_logged_in ) {

				$this->applying_discounts = true;

				// initialize discount actions that will be called in this class methods
				add_action( 'wc_memberships_discounts_enable_price_adjustments',       array( $this, 'enable_price_adjustments' ) );
				add_action( 'wc_memberships_discounts_enable_price_html_adjustments',  array( $this, 'enable_price_html_adjustments' ) );
				add_action( 'wc_memberships_discounts_disable_price_adjustments',      array( $this, 'disable_price_adjustments' ) );
				add_action( 'wc_memberships_discounts_disable_price_html_adjustments', array( $this, 'disable_price_html_adjustments' ) );

				// start off by activating discounts for logged in members
				do_action( 'wc_memberships_discounts_enable_price_adjustments' );
				do_action( 'wc_memberships_discounts_enable_price_html_adjustments' );

				// force calculations in cart
				add_filter( 'woocommerce_update_cart_action_cart_updated', '__return_true' );
				// adjust cart items prices
				add_filter( 'woocommerce_cart_item_price', array( $this, 'on_cart_item_price' ), 999, 2 );

				// member discount badges
				add_action( 'woocommerce_before_shop_loop_item_title',   'wc_memberships_show_product_loop_member_discount_badge' );
				add_action( 'woocommerce_before_single_product_summary', 'wc_memberships_show_product_member_discount_badge' );
				add_filter( 'wc_memberships_member_discount_badge',      array( $this, 'disable_discount_badge_for_excluded_products' ), 10, 3 );

				// handle price suffix
				add_filter( 'woocommerce_get_price_suffix', array( $this, 'on_get_price_suffix' ), 999, 2 );

				// make sure that the display of the "On Sale" badge is honoured
				add_filter( 'woocommerce_product_is_on_sale', array( $this, 'product_is_on_sale' ), 999, 2 );

				// if a product is on sale and has a member discount, optionally show the sale badge
				add_action( 'woocommerce_single_product_summary', array( $this, 'display_sale_badge_for_discounted_products' ), 1 );
				add_action( 'woocommerce_shop_loop_item_title',   array( $this, 'display_sale_badge_for_discounted_products' ), 1 );

			} else {

				$this->applying_discounts = false;

				// ensures 'wc_memberships_get_price_html' filter hook is fired nonetheless
				add_filter( 'woocommerce_get_price_html', array( $this, 'on_price_html' ), 999, 2 );
			}
		}
	}


	/**
	 * Determines if we are in an admin context where member discounts should not run.
	 *
	 * @since 1.7.3-6
	 * @return bool
	 */
	private function is_wp_admin_ajax() {

		// Check if any of the enhanced search product actions are being done.
		if ( is_admin() ) {

			/* @see WC_AJAX::add_ajax_events() */
			$search_products = array(
				'json_search_products',
				'json_search_products_and_variations',
				'json_search_grouped_products',
				'json_search_downloadable_products_and_variations',
			);

			foreach ( $search_products as $ajax_event ) {
				if ( did_action( 'wp_ajax_woocommerce_' . $ajax_event ) ) {
					return true;
				}
			}
		}

		return false;
	}


	/*
	 * Check if the logged in member has membership discounts for a product
	 *
	 * @since 1.6.4
	 * @param int|\WC_Product|\WC_Product_Variable|null $the_product Optional, a product id or object to check if it has member discounts
	 *                                                               (if not set, looks for a current product)
	 * @param int|\WP_User|null $the_user Optional, the user to check if has discounts for the product
	 *                                    (defaults to current user)
	 * @return bool
	 */
	public function user_has_member_discount( $the_product = null, $the_user = null ) {

		$has_discount = false;

		// get the product
		if ( is_numeric( $the_product ) ) {
			$the_product = wc_get_product( $the_product );
		} elseif ( null === $the_product ) {
			global $product;

			if ( $product instanceof WC_Product ) {
				$the_product = $product;
			}
		}

		// bail out if no product
		if ( ! $the_product instanceof WC_Product ) {
			return $has_discount;
		}

		// get the user id
		if ( null === $the_user ) {
			$member_id = get_current_user_id();
		} elseif ( is_numeric( $the_user ) ) {
			$member_id = (int) $the_user;
		} elseif ( isset( $the_user->ID ) ) {
			$member_id = (int) $the_user->ID;
		} else {
			return $has_discount;
		}

		// bail out if user is not logged in
		if ( 0 === $member_id ) {
			return $has_discount;
		}

		$product_id = SV_WC_Plugin_Compatibility::product_get_id( $the_product );

		// use memoized entry if found, or store a new one
		if ( isset( $this->member_has_product_discount[ $member_id ][ $product_id ] ) ) {

			$has_discount = $this->member_has_product_discount[ $member_id ][ $product_id ];

		} else {

			$has_discount = wc_memberships()->get_rules_instance()->user_has_product_member_discount( $member_id, $product_id );

			// if a variable product, before return false check for its variations
			if ( ! $has_discount && $the_product->has_child() ) {

				foreach ( $the_product->get_children() as $product_child_id ) {

					$has_discount = wc_memberships()->get_rules_instance()->user_has_product_member_discount( $member_id, $product_child_id );

					$this->member_has_product_discount[ $member_id ][ $product_child_id ] = $has_discount;

					// if one of the child variations has a discount, it's legit
					// to say that the parent variable product has member discounts
					if ( $has_discount ) {
						$this->member_has_product_discount[ $member_id ][ $product_id ] = $has_discount;
						break;
					// unlikely occurrence but if so we can break the loop earlier
					} elseif ( $product_id === $product_child_id ) {
						break;
					}
				}

			} else {

				$this->member_has_product_discount[ $member_id ][ $product_id ] = $has_discount;
			}
		}

		return $has_discount;
	}


	/**
	 * Check whether products on sale should be excluded from discount rules
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	public function excluding_on_sale_products_from_member_discounts() {
		return $this->exclude_on_sale_products;
	}


	/**
	 * Check if a product is to be excluded from discount rules
	 *
	 * Note: even if not excluded, discount rules may or may not still apply
	 *
	 * @since 1.7.0
	 * @param int|\WC_Product $product Product object or id
	 * @return bool
	 */
	public function is_product_excluded_from_member_discounts( $product ) {

		if ( is_numeric( $product ) ) {
			$product = wc_get_product( $product );
		} elseif ( $product instanceof WP_Post ) {
			$product = wc_get_product( $product );
		} elseif ( ! $product instanceof WC_Product ) {
			return false;
		}

		$product_id = SV_WC_Plugin_Compatibility::product_get_id( $product );

		// use memoization to speed up checks
		if ( isset( $this->product_excluded_from_discounts[ $product_id ] ) ) {

			$exclude = $this->product_excluded_from_discounts[ $product_id ];

		} else {

			// exclude if product-level setting is enabled to exclude this product
			$exclude_product = 'yes' === get_post_meta( $product_id, '_wc_memberships_exclude_discounts', true );
			// exclude if on sale and global-level setting is enabled to exclude all products on sale
			$exclude_on_sale = $this->excluding_on_sale_products_from_member_discounts() && $this->product_is_on_sale_before_discount( $product );

			/**
			 * Filter product from having discount rules applied
			 *
			 * @since 1.7.0
			 * @param bool $exclude Whether the product is excluded from discount rules
			 * @param \WC_Product $product The product object
			 */
			$exclude = (bool) apply_filters( 'wc_memberships_exclude_product_from_member_discounts', $exclude_product || $exclude_on_sale, $product );

			$this->product_excluded_from_discounts[ $product_id ] = $exclude;
		}

		return $exclude;
	}


	/**
	 * Check whether a variable product is discounted
	 *
	 * Notes: does not use discounts memoization, doesn't consider parent variable product discounts
	 *
	 * @since 1.7.2
	 * @param int $variable_product_id The variable product id
	 * @return bool
	 */
	private function variable_product_has_discount( $variable_product_id ) {
		return wc_memberships()->get_rules_instance()->user_has_product_member_discount( get_current_user_id(), $variable_product_id );
	}


	/**
	 * Handle sale status of products
	 *
	 * @since 1.6.2
	 * @param bool $on_sale Whether the product is on sale
	 * @param \WC_Product|\WC_Product_Variable $product The product object
	 * @return bool
	 */
	public function product_is_on_sale( $on_sale, $product ) {

		// bail out if any of the following:
		// - user is not logged in
		// - product is being excluded from member discounts
		// - global Memberships setting is excluding on sale products from discounts
		// - current user does not have a discount for the product that may be on sale
		if (    ! $this->member_is_logged_in
			 ||   $this->is_product_excluded_from_member_discounts( $product )
		     ||   ( $on_sale && $this->excluding_on_sale_products_from_member_discounts() )
		     ||   ( ! is_admin() && ( ! $product instanceof WC_Product || ( $this->member_is_logged_in && ! $this->user_has_member_discount( SV_WC_Plugin_Compatibility::product_get_id( $product ) ) ) ) ) ) {

			return $on_sale;
		}

		return $this->product_is_on_sale_before_discount( $product );
	}


	/**
	 * Determine if a product is on sale before membership price adjustments
	 *
	 * This method contains code from
	 * @see WC_Product::is_on_sale()
	 * and
	 * @see WC_Product_Variable::is_on_sale()
	 * It was introduced mainly to avoid infinite loops between
	 * @see \WC_Memberships_Member_Discounts::product_is_on_sale()
	 * and
	 * @see \WC_Memberships_Member_Discounts::is_product_excluded_from_member_discounts()
	 * so it runs very late from
	 * @see \WC_Memberships_Member_Discounts::product_is_on_sale()
	 * and does not include itself the product on sale filter that would cause
	 * the infinite loop, and also will remove all other filters on
	 * 'woocommerce_product_is_on_sale' if our own filter is running
	 *
	 * @since 1.7.0
	 * @param int|\WC_Product|\WC_Product_Variable $product Product object or id
	 * @return bool
	 */
	public function product_is_on_sale_before_discount( $product ) {

		if ( is_numeric( $product ) ) {
			$product = wc_get_product( $product );
		}

		$on_sale = false;

		// sanity checks
		if ( ! $product instanceof WC_Product ) {
			return $on_sale;
		} elseif ( ! $this->member_is_logged_in ) {
			return $product->is_on_sale();
		}

		$product_id = SV_WC_Plugin_Compatibility::product_get_id( $product );

		if ( isset( $this->product_is_on_sale_before_discount[ $product_id ] ) ) {

			$on_sale = $this->product_is_on_sale_before_discount[ $product_id ];

		} else {

			// disable Memberships member discount adjustments
			do_action( 'wc_memberships_discounts_disable_price_adjustments' );

			/** @see WC_Product_Variable::is_on_sale() */
			if ( $product->is_type( array( 'variable', 'variable-subscription' ) ) ) {

				$prices  = $product->get_variation_prices();

				if ( $prices['regular_price'] !== $prices['sale_price'] && $prices['sale_price'] === $prices['price'] ) {
					$on_sale = true;
				}

			/** @see WC_Product::is_on_sale() */
			} else {

				$on_sale = $product->get_sale_price() !== $product->get_regular_price() && $product->get_sale_price() === $product->get_price();
			}

			// re-enable Memberships member discount adjustments
			do_action( 'wc_memberships_discounts_enable_price_adjustments' );

			$this->product_is_on_sale_before_discount[ $product_id ] = $on_sale;
		}

		// we need this to avoid conflicts with third party code that runs
		// again 'woocommerce_product_is_on_sale' filter - since Memberships
		// runs its own filter very late (priority 999) it's safe enough
		// to make Memberships' filter final
		if ( 'woocommerce_product_is_on_sale' === current_filter() ) {

			/** @see \WC_Memberships_Member_Discounts::__construct() */
			/** @see \WC_Memberships_Member_Discounts::product_is_on_sale)() */
			remove_all_filters( 'woocommerce_product_is_on_sale' );
		}

		return $on_sale;
	}


	/**
	 * Display sale badge for discounted products
	 *
	 * @since 1.7.0
	 */
	public function display_sale_badge_for_discounted_products() {
		global $post;

		$product = wc_get_product( $post );

		// sanity check
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		/**
		 * Controls whether or not member prices should display sale prices as well
		 *
		 * @since 1.3.0
		 * @param bool $display_sale_price Defaults to false
		 */
		$display_sale_price = (bool) apply_filters( 'wc_memberships_member_prices_display_sale_price', false );

		// determine whether the product is on sale in the first place
		$on_sale_before_discount = $this->product_is_on_sale_before_discount( $product );
		$member_has_discount     = $this->user_has_member_discount( $product );

		// show this badge if:
		// - user has a member discount, product is on sale and we want to display the sale price badge
		// - user does NOT have a member discount, but product is on sale before discount rules (compatibility)
		if (    (   $member_has_discount && $on_sale_before_discount && $display_sale_price )
		     || ( ( ! $member_has_discount || $this->is_product_excluded_from_member_discounts( $product ) ) && $on_sale_before_discount ) ) {

			add_filter( 'woocommerce_product_is_on_sale',    array( $this, 'enable_sale_price' ) );

			wc_get_template( 'single-product/sale-flash.php' );

			remove_filter( 'woocommerce_product_is_on_sale', array( $this, 'enable_sale_price' ) );
		}
	}


	/**
	 * Enable 'on sale' callback for a product that is on sale before discounts
	 *
	 * @internal
	 *
	 * @since 1.7.0
	 * @return true
	 */
	public function enable_sale_price() {
		return true;
	}


	/**
	 * Disable 'on sale' callback for a product that is on sale before discounts
	 *
	 * @internal
	 *
	 * @since 1.3.0
	 * @return true
	 */
	public function disable_sale_price() {
		return false;
	}


	/**
	 * Get product price before discount
	 *
	 * @since 1.7.0
	 * @param \WC_Product|\WC_Product_Variation $product Product
	 * @return float Price
	 */
	private function get_price_before_discount( $product ) {

		// temporarily disable price adjustments
		do_action( 'wc_memberships_discounts_disable_price_adjustments' );

		$price = $product->get_price();

		// re-enable price adjustments
		do_action( 'wc_memberships_discounts_enable_price_adjustments' );

		return $price;
	}


	/**
	 * Apply purchasing discounts to product price
	 *
	 * @internal
	 *
	 * @since 1.0.0
	 * @param string|int|float $price Price to discount (normally a float, maybe a string number)
	 * @param \WC_Product $product The product object
	 * @return float Price
	 */
	public function on_get_price( $price, $product ) {

		// bail out if any of the following is true:
		// - member is not logged in
		// - product is excluded from member discounts
		// - user has no member discount over the product
		if (    ! $this->member_is_logged_in
		     ||   $this->is_product_excluded_from_member_discounts( $product )
		     || ! $this->user_has_member_discount( $product ) ) {

			$price = $this->get_price_before_discount( $product );

		} else {

			// get the discounted price or return the default price if no discounts apply
			$discounted_price = $this->get_discounted_price( $price, $product );

			$price = is_numeric( $discounted_price ) ? $discounted_price : $this->get_price_before_discount( $product );
		}

		return (float) $price;
	}


	/**
	 * Get variable product price HTML before membership discounts
	 *
	 * @see \WC_Product_Variable::get_price_html()
	 *
	 * @since 1.7.1
	 * @param \WC_Product_Variable $product Variable product
	 * @param bool $display_sale_price Whether to display sale price
	 * @return string HTML
	 */
	private function get_variable_product_price_html_before_discount( $product, $display_sale_price ) {

		$prices = $product->get_variation_prices( true );

		if ( empty( $prices['price'] ) || '' === $product->get_price() ) {

			$html_before_discount = apply_filters( 'woocommerce_variable_empty_price_html', '', $product );

		} else {

			$regular_min = $sale_min = $product->get_variation_regular_price( 'min', true );
			$regular_max = $sale_max = $product->get_variation_regular_price( 'max', true );

			$regular_price = $regular_min !== $regular_max ? sprintf( _x( '%1$s&ndash;%2$s', 'Price range: from-to', 'woocommerce-memberships' ), wc_price( $regular_min ), wc_price( $regular_max ) ) : wc_price( $regular_min );

			if ( $on_sale = $this->product_is_on_sale_before_discount( $product ) ) {

				$sale_min = $product->get_variation_sale_price( 'min', true );
				$sale_max = $product->get_variation_sale_price( 'max', true );

				$sale_price = $sale_min !== $sale_max ? sprintf( _x( '%1$s&ndash;%2$s', 'Price range: from-to', 'woocommerce-memberships' ), wc_price( $sale_min ), wc_price( $sale_max ) ) : wc_price( $sale_min );

				if ( $this->user_has_member_discount( $product ) ) {

					$html_before_discount = $display_sale_price ? apply_filters( 'woocommerce_variable_sale_price_html', $sale_price, $product ) : $regular_price;

				} else {

					if ( $sale_min === $sale_max && ! $this->variable_product_has_discount( SV_WC_Plugin_Compatibility::product_get_id( $product ) ) ) {
						$sale_price = wc_price( $this->get_price_before_discount( $product ) );
					}

					$html_before_discount = $html = '<del>' . $regular_price . '</del> <ins>' . $sale_price . '</ins>';
					$html_before_discount = apply_filters( 'woocommerce_variable_sale_price_html', $html_before_discount, $product );
				}

			} else {

				$html_before_discount = $regular_price;
			}

			// sanity check for free products:
			// if min and max variations are 0, this product is free
			$min_price = (float) current( $prices['price'] );
			$max_price = (float) end( $prices['price'] );

			if ( empty( $min_price ) && empty( $max_price ) ) {
				$html_before_discount = apply_filters( 'woocommerce_variable_free_price_html', _x( 'Free!', 'Free product', 'woocommerce-memberships' ), $product );
			}
		}

		return $html_before_discount;
	}


	/**
	 *
	 * Get variable product price HTML before membership discounts
	 *
	 * @since 1.7.2
	 * @param \WC_Product_Variable $product Variable product
	 * @return string HTML price
	 */
	private function get_variable_product_price_html_after_discount( $product ) {

		$price_min = null;
		$price_max = null;

		if ( $this->variable_product_has_discount( $product->id ) ) {

			// temporarily disable membership price adjustments
			do_action( 'wc_memberships_discounts_disable_price_adjustments' );
			do_action( 'wc_memberships_discounts_disable_price_html_adjustments' );

			$price_min = $product->get_variation_price( 'min', true );
			$price_min = $price_min > 0 ? $this->get_discounted_price( $price_min, $product ) : 0;
			$price_max = $product->get_variation_price( 'max', true );
			$price_max = $price_max > 0 ? $this->get_discounted_price( $price_max, $product ) : 0;

			// re-enable membership price adjustments
			do_action( 'wc_memberships_discounts_enable_price_adjustments' );
			do_action( 'wc_memberships_discounts_enable_price_html_adjustments' );
		}

		// this may be a case when one or more product variations
		// have discounts but the parent variable product has none
		if ( ( empty( $price_min ) || empty( $price_max ) ) && ( $variations = $product->get_children() ) ) {

			$variation_prices = array();

			foreach ( $variations as $variation_id ) {
				$variation_prices[] = wc_get_product( $variation_id )->get_display_price();
			}

			if ( ! empty( $variation_prices ) ) {
				$price_min = min( $variation_prices );
				$price_max = max( $variation_prices );
			}
		}

		if ( $price_min !== $price_max ) {
			$html_after_discount = sprintf( _x( '%1$s&ndash;%2$s', 'Price range: from-to', 'woocommerce-memberships' ), wc_price( $price_min ), wc_price( $price_max ) );
		} elseif ( empty( $price_min ) && empty( $price_max ) ) {
			$html_after_discount = $this->get_void_product_price_html_before_discount( $product );
		} else {
			$html_after_discount = wc_price( $price_min );
		}

		$html_after_discount .= $product->get_price_suffix( $product->get_display_price() );

		return $html_after_discount;
	}


	/**
	 * Get void product price HTML before membership discounts
	 *
	 * @see \WC_Product::get_price_html()
	 *
	 * @since 1.7.1
	 * @param \WC_Product $product Product object
	 * @return string HTML
	 */
	private function get_void_product_price_html_before_discount( $product ) {

		$price = $product->get_price();

		if ( empty( $price ) && '' !== $price ) {

			if ( $this->product_is_on_sale_before_discount( $product ) && $product->get_regular_price() ) {

				$price_label = $product->get_price_html_from_to( $product->get_display_price( $product->get_regular_price() ), _x( 'Free!', 'Free product', 'woocommerce-memberships' ) );

				$html_before_discount = apply_filters( 'woocommerce_free_sale_price_html', $price_label, $this );

			} else {

				$html_before_discount = apply_filters( 'woocommerce_free_price_html', _x( 'Free!', 'Free product', 'woocommerce-memberships' ), $product );
			}

		} else {

			$html_before_discount = apply_filters( 'woocommerce_empty_price_html', '', $product );
		}

		return $html_before_discount;
	}


	/**
	 * Get simple or variation product price before membership discounts
	 *
	 * @see \WC_Product::get_price_html()
	 * @see \WC_Product_Variation::get_price_html()
	 *
	 * @since 1.7.1
	 * @param \WC_Product|\WC_Product_Variation $product A simple product or a variation
	 * @param bool $display_sale_price Whether to display sale prices
	 * @return string HTML
	 */
	private function get_product_price_html_before_discount( $product, $display_sale_price ) {

		$on_sale      = $this->product_is_on_sale_before_discount( $product );
		$is_variation = $product->is_type( 'variation' );

		if ( $is_variation && '' === $product->get_price() ) {

			$html_before_discount = apply_filters( 'woocommerce_variation_empty_price_html', '', $product );

		} else {

			if ( $this->user_has_member_discount( $product ) && ! $this->is_product_excluded_from_member_discounts( $product ) )  {

				if ( $on_sale ) {
					$price_before_discount = $display_sale_price ? $product->get_display_price( $product->get_sale_price() ) : $product->get_display_price( $product->get_regular_price() );
					$html_before_discount  = wc_price( $price_before_discount );
				} else {
					$price_before_discount = $product->get_display_price( $product->get_regular_price() );
					$html_before_discount  = wc_price( $price_before_discount );
				}

			} else {

				if ( $on_sale ) {
					$html_before_discount  = $product->get_price_html_from_to( $product->get_display_price( $product->get_regular_price() ), $product->get_display_price( $product->get_sale_price() ) );
				} else {
					$price_before_discount = $product->get_display_price( $product->get_regular_price() );
					$html_before_discount  = wc_price( $price_before_discount );
				}
			}

			// maybe apply wc filters before returning
			if ( $on_sale ) {
				$sale_price_html_filter = $is_variation ? 'woocommerce_variation_sale_price_html' : 'woocommerce_sale_price_html';
				$html_before_discount   = apply_filters( $sale_price_html_filter, $html_before_discount, $product );
			} elseif ( $is_variation && ! $product->get_price() > 0 ) {
				$html_before_discount = apply_filters( 'woocommerce_variation_free_price_html', _x( 'Free!', 'Free product', 'woocommerce-memberships' ), $product );
			}
		}

		return $html_before_discount;
	}


	/**
	 * Get product HTML price before discount
	 *
	 * @since 1.7.0
	 * @param \WC_Product|\WC_Product_Variable|\WC_Product_Variation $product Product
	 * @return string HTML
	 */
	private function get_price_html_before_discount( $product ) {

		/**
		 * Controls whether or not member prices should display sale prices as well
		 *
		 * @since 1.3.0
		 * @param bool $display_sale_price Defaults to false
		 */
		$display_sale_price = (bool) apply_filters( 'wc_memberships_member_prices_display_sale_price', false );

		// temporarily disable membership price adjustments
		do_action( 'wc_memberships_discounts_disable_price_adjustments' );
		do_action( 'wc_memberships_discounts_disable_price_html_adjustments' );

		if ( ! $product->get_price() && ! $product->is_type( array( 'variable', 'variation' ) ) ) {
			// simple products with 0 or empty price
			$html_before_discount = $this->get_void_product_price_html_before_discount( $product );
		} elseif ( $product->is_type( 'variable' ) ) {
			// variable products
			$html_before_discount = $this->get_variable_product_price_html_before_discount( $product, $display_sale_price );
		} else {
			// simple products with non-void prices, product variations
			$html_before_discount = $this->get_product_price_html_before_discount( $product, $display_sale_price );
		}

		// re-enable membership price adjustments
		do_action( 'wc_memberships_discounts_enable_price_adjustments' );
		do_action( 'wc_memberships_discounts_enable_price_html_adjustments' );

		return $html_before_discount . $product->get_price_suffix( $this->get_price_before_discount( $product ) );
	}


	/**
	 * Adjust discounted product price HTML
	 *
	 * @internal
	 *
	 * @since 1.3.0
	 * @param string $html The price HTML maybe after discount
	 * @param \WC_Product|\WC_Product_Variable|\WC_Product_Variation $product The product object for which we may have discounts
	 * @return string The original price HTML if no discount or a new formatted string showing before/after discount
	 */
	public function on_price_html( $html, $product ) {

		/**
		 * Controls whether or not member prices should use discount format when displayed
		 *
		 * @since 1.3.0
		 * @param bool $use_discount_format Defaults to true
		 */
		$use_discount_format = (bool) apply_filters( 'wc_memberships_member_prices_use_discount_format', true );

		// bail out if any of the following conditions applies:
		// - custom code set to not to use discount format
		// - no member user is logged in
		// - product is excluded from discount rules
		// - current user has no discounts for the product
		// - product has no applicable member discount
		if (    ! $use_discount_format
		     || ! $this->member_is_logged_in
		     ||   $this->is_product_excluded_from_member_discounts( $product )
		     || ! $this->user_has_member_discount( $product ) ) {

			if ( $this->applying_discounts ) {
				$html = $this->get_price_html_before_discount( $product );
			}

		} else {

			// get string price BEFORE discount
			$html_before_discount = $this->get_price_html_before_discount( $product );

			// get string price AFTER discount
			$html_after_discount = $html;

			// special handling for variable products
			if ( $product->is_type( 'variable' ) ) {
				$html_after_discount = $this->get_variable_product_price_html_after_discount( $product );
			}

			// string prices do not match, we have a discount
			if ( $html_after_discount !== $html_before_discount ) {

				$html = '<del>' . $html_before_discount . '</del> <ins>' . $html_after_discount . '</ins>';

				// special handling for variable products
				if ( ! $product->is_type( 'variable' ) && $this->variable_product_has_discount( $product->id ) ) {

					$price_before_discount = $this->get_price_before_discount( $product );
					$price_after_discount  = $product->get_price();

					if ( empty( $price_before_discount ) && empty( $price_after_discount ) ) {

						$html = $html_after_discount;
					}
				}
			}

			// add a "Member Discount" badge for single variation prices
			if ( $product->is_type( 'variation' ) ) {

				// a "Member Discount" text label is shown when selecting this variation
				$html .= ' ' . $this->get_member_discount_badge( $product, true );
			}

			/**
			 * Filter the HTML price after member discounts have been applied.
			 *
			 * @since 1.7.2
			 * @param string $html The price HTML output.
			 * @param \WC_Product $product The product the discounted price is meant for.
			 * @param string $html_before_discount Original HTML before discounts.
			 * @param string $html_after_discount Original HTML after discounts.
			 *
			 */
			$html = (string) apply_filters( 'wc_memberships_get_discounted_price_html', $html, $product, $html_before_discount, $html_after_discount );
		}

		/**
		 * Filter the HTML price after member discounts may have been applied.
		 *
		 * @since 1.7.1
		 * @param string $html The price HTML.
		 * @param \WC_Product $product The product the price is meant for.
		 */
		return apply_filters( 'wc_memberships_get_price_html', $html, $product );
	}


	/**
	 * Adjust variation price
	 *
	 * @internal
	 *
	 * @since 1.3.0
	 * @param float $price The product price, maybe discounted
	 * @param \WC_Product|\WC_Product_Variation $product The product object
	 * @param string $min_or_max Min-max prices of variations
	 * @param bool $display If to be displayed
	 * @return float
	 */
	public function on_get_variation_price( $price, $product, $min_or_max, $display ) {

		// bail out if any of the following applies:
		// - no user member is logged in
		// - the product is excluded from receiving member discounts
		// - logged in user has no member discount over the product
		if (    ! $this->member_is_logged_in
		     || ! $product instanceof WC_Product
		     ||   $this->is_product_excluded_from_member_discounts( $product )
		     || ! $this->user_has_member_discount( $product ) ) {

			$price_float = (float) $price;

			return $this->applying_discounts && empty( $price_float ) ? $this->get_price_before_discount( $product ) : $price;
		}

		$product_id = SV_WC_Plugin_Compatibility::product_get_id( $product );

		// use memoized entry if available to speed up return
		if ( isset( $this->product_discount_variation[ $product_id ][ $min_or_max ] ) ) {

			$price = $this->product_discount_variation[ $product_id ][ $min_or_max ];

		} else {

			// defaults
			$calc_price = $price;
			$min_price  = $price;
			$max_price  = $price;

			// get variation ids
			$children = $product->get_children();

			if ( ! empty( $children ) ) {

				foreach ( $children as $variation_id ) {

					// make sure we start from the normal un-discounted price
					do_action( 'wc_memberships_discounts_disable_price_adjustments' );

					if ( $display ) {

						if ( $variation = $product->get_child( $variation_id ) ) {

							// in display mode, we need to account for taxes
							$base_price = $variation->get_price() > 0 ? $variation->get_display_price() : 0;
							$calc_price = $base_price;

							// try getting the discounted price for the variation
							$discounted_price = $this->get_discounted_price( $base_price, $variation->id );

							// if there's a difference, grab discounted price
							if ( is_numeric( $discounted_price ) && $base_price !== $discounted_price ) {
								$calc_price = $discounted_price;
							}
						}

					} else {

						$calc_price = (float) get_post_meta( $variation_id, '_price', true );

						// try getting the discounted price for the variation
						$discounted_price = $this->get_discounted_price( $calc_price, $variation_id );

						// if there's a difference, grab discounted price
						if ( is_numeric( $discounted_price ) && $calc_price !== $discounted_price ) {
							$calc_price = $discounted_price;
						}
					}

					// re-enable discounts in pricing flow
					do_action( 'wc_memberships_discounts_enable_price_adjustments' );

					if ( $min_price === null || $calc_price < $min_price ) {
						$min_price = $calc_price;
					}

					if ( $max_price === null || $calc_price > $max_price ) {
						$max_price = $calc_price;
					}
				}
			}

			if ( $min_or_max === 'min' ) {
				$price = (float) $min_price;
			} elseif ( $min_or_max === 'max' ) {
				$price = (float) $max_price;
			}

			$this->product_discount_variation[ $product_id ][ $min_or_max ] = $price;
		}

		return (float) $price;
	}


	/**
	 * Adjust discounted cart item price HTML
	 *
	 * @internal
	 *
	 * @since 1.3.0
	 * @param string $html Price HTML
	 * @param array $cart_item The cart item data
	 * @return string
	 */
	public function on_cart_item_price( $html, $cart_item ) {

		// get the product
		$product = ! empty( $cart_item['data'] ) ? $cart_item['data'] : false;

		// bail out if any of the following applies:
		// - no user member is logged in
		// - no product (sanity check)
		// - the product is excluded from receiving member discounts
		// - logged in user has no member discount over the product
		if (    ! $this->member_is_logged_in
		     || ! $product instanceof WC_Product
		     ||   $this->is_product_excluded_from_member_discounts( $product )
		     || ! $this->user_has_member_discount( $product ) ) {

			return $html;
		}

		// temporarily disable our price adjustments
		do_action( 'wc_memberships_discounts_disable_price_adjustments' );

		// so we can get the base price without member discounts
		// also, in cart we need to account for tax display
		$price = $product->get_display_price();

		// re-enable disable our price adjustments
		do_action( 'wc_memberships_discounts_enable_price_adjustments' );

		if ( $this->has_discounted_price( $price, $product ) ) {

			// in cart, we need to account for tax display
			$discounted_price = $product->get_display_price();

			/** This filter is documented in class-wc-memberships-member-discounts.php **/
			$use_discount_format = apply_filters( 'wc_memberships_use_discount_format', true );

			// output html price before/after discount
			if ( $use_discount_format && $discounted_price < $price ) {
				$html = '<del>' . wc_price( $price ) . '</del> <ins>' . wc_price( $discounted_price ) . '</ins>';
			}
		}

		return $html;
	}


	/**
	 * Add the current user ID to the variation prices hash for caching.
	 *
	 * @internal
	 *
	 * @since 1.3.2
	 * @param array $data The existing hash data
	 * @param \WC_Product $product The current product variation
	 * @return array $data The hash data with a user ID added if applicable
	 */
	public function set_user_variation_prices_hash( $data, $product ) {

		// bail out if member is not logged in
		// or logged in user has no membership discount over the product
		if (      $this->member_is_logged_in
		     && ! $this->is_product_excluded_from_member_discounts( $product )
		     &&   $this->user_has_member_discount( $product ) ) {

			$data[] = get_current_user_id();
		}

		return $data;
	}


	/**
	 * Adjust the optional display price suffix
	 *
	 * @internal
	 *
	 * @since 1.7.3
	 * @param string $price_display_suffix The display price suffix string
	 * @param \WC_Product $product The product object
	 * @return string The price suffix
	 */
	public function on_get_price_suffix( $price_display_suffix, $product ) {

		$price_suffix = $price_display_suffix;

		// Special handling if:
		// - member is not logged in
		// - product is excluded from member discounts
		// - user has no member discount over the product
		if (    ! $this->member_is_logged_in
		     ||   $this->is_product_excluded_from_member_discounts( $product )
		     || ! $this->user_has_member_discount( $product ) ) {

			$price_display_suffix_raw  = get_option( 'woocommerce_price_display_suffix' );
			$price_suffix_merge_tags   = array(
				'{price_including_tax}',
				'{price_excluding_tax}',
			);

			if ( in_array( $price_display_suffix_raw, $price_suffix_merge_tags, true ) ) {

				do_action( 'wc_memberships_discounts_disable_price_adjustments' );

				$replace = array(
					wc_price( $product->get_price_including_tax() ),
					wc_price( $product->get_price_excluding_tax() ),
				);

				$price_suffix = str_replace( $price_suffix_merge_tags, $replace, $price_display_suffix_raw );

				do_action( 'wc_memberships_discounts_enable_price_adjustments' );

				$price_suffix = ' <small class="woocommerce-price-suffix">' . $price_suffix . '</small>';
			}
		}

		return $price_suffix;
	}


	/**
	 * Get member discount badge
	 *
	 * @since 1.6.4
	 * @param \WC_Product $product The product object to output a badge for (passed to filter)
	 * @param bool $variation Whether to output a discount badge specific for a product variation (default false)
	 * @return string
	 */
	public function get_member_discount_badge( $product, $variation = false ) {

		$label = __( 'Member discount!', 'woocommerce-memberships' );

		// we have a slight different output for badge classes and filter
		if ( true !== $variation ) {
			global $post;

			// used in filter for backwards compatibility reasons
			$the_post = $post;

			if ( ! $the_post instanceof WP_Post ) {
				$the_post = $product->post;
			}

			$badge = '<span class="onsale wc-memberships-member-discount">' . esc_html( $label ) . '</span>';

			/**
			 * Filter the member discount badge
			 *
			 * @since 1.0.0
			 * @param string $badge The badge HTML
			 * @param \WP_Post $post The product post object
			 * @param \WC_Product_Variation $variation The product variation
			 */
			$badge = apply_filters( 'wc_memberships_member_discount_badge', $badge, $the_post, $product );

		} else {

			$badge = '<span class="wc-memberships-variation-member-discount">' . esc_html( $label ) . '</span>';

			/**
			 * Filter the variation member discount badge
			 *
			 * @since 1.3.2
			 * @param string $badge The badge HTML
			 * @param \WC_Product|\WC_Product_Variation $variation The product variation
			 */
			$badge = apply_filters( 'wc_memberships_variation_member_discount_badge', $badge, $product );

		}

		return $badge;
	}


	/**
	 * Filter the member discount badge for products excluded
	 * from member discount rules
	 *
	 * @internal
	 *
	 * @since 1.7.0
	 * @param string $badge Badge HTML
	 * @param \WP_Post $post The post object
	 * @param \WC_Product $product The product object
	 * @return string Empty string if product is excluded from member discounts
	 */
	public function disable_discount_badge_for_excluded_products( $badge, $post, $product ) {
		return $this->is_product_excluded_from_member_discounts( $product ) ? '' : $badge;
	}


	/**
	 * Get product discounted price for member
	 *
	 * @since 1.3.0
	 * @param float $base_price Original price
	 * @param int|\WC_Product $product Product ID or product object
	 * @param int|null $member_id Optional, defaults to current user id
	 * @return float|null The discounted price or null if no discount applies
	 */
	public function get_discounted_price( $base_price, $product, $member_id = null ) {

		if ( ! $member_id ) {
			$member_id = get_current_user_id();
		}

		if ( ! is_object( $product ) ) {
			$product = wc_get_product( $product );
		}

		$price          = null;
		$product_id     = null;
		$discount_rules = array();

		// we need a product and a user to get a member discounted price
		if ( $product instanceof WC_Product && $member_id > 0 ) {

			$product_id     = SV_WC_Plugin_Compatibility::product_get_id( $product );
			$discount_rules = wc_memberships()->get_rules_instance()->get_user_product_purchasing_discount_rules( $member_id, $product_id );
		}

		if ( $product_id && ! empty( $discount_rules ) ) {

			if ( isset( $this->product_discount[ $member_id ][ $product_id ] ) ) {

				$price = $this->product_discount[ $member_id ][ $product_id ];

			} else {

				/**
				 * Filter whether to allow stacking product discounts
				 * for members of multiple plans with overlapping discount rules
				 * for the same products
				 *
				 * @since 1.7.0
				 *
				 * @param bool $allow_cumulative_discounts Default true (allow)
				 * @param int $member_id The user id discounts are calculated for
				 * @param \WC_Product $product The product object being discounted
				 */
				$allow_cumulative_discounts = apply_filters( 'wc_memberships_allow_cumulative_member_discounts', true, $member_id, $product );

				$price  = (float) $base_price;
				$prices = array();

				// find out the discounted price for the current user
				foreach ( $discount_rules as $rule ) {

					switch ( $rule->get_discount_type() ) {

						case 'percentage':
							$discounted_price = $price * ( 100 - $rule->get_discount_amount() ) / 100;
						break;

						case 'amount':
							$discounted_price = $price - $rule->get_discount_amount();
						break;
					}

					// make sure that the lowest price gets applied and doesn't become negative
					if ( isset( $discounted_price ) && $discounted_price < $price ) {

						if ( true === $allow_cumulative_discounts ) {
							$price = max( $discounted_price, 0 );
						} else {
							$prices[] = max( $discounted_price, 0 );
						}
					}
				}

				// pick the lowest price
				if ( ! empty( $prices ) ) {
					$price = min( $prices );
				}

				// sanity check
				if ( $price >= $base_price ) {
					$price = null;
				}

				$this->product_discount[ $product_id ] = $price;
			}
		}

		/**
		 * Filter discounted price of a membership product
		 *
		 * @since 1.7.1
		 * @param null|float $price The discounted price or null if no discount applies
		 * @param float $base_price The original price (not discounted by Memberships)
		 * @param int $product_id The id of the product (or variation) the price is for
		 * @param int $member_id The id of the logged in member (it's zero for non logged in users)
		 * @param \WC_Product $product The product object for the price being discounted
		 */
		return apply_filters( 'wc_memberships_get_discounted_price', $price, $base_price, $product_id, $member_id, $product );
	}


	/**
	 * Check if the product is discounted for the user
	 *
	 * @since 1.3.0
	 * @param float $base_price Original price
	 * @param int|\WC_Product $product Product ID or object
	 * @param null|int $user_id Optional, defaults to current user id
	 * @return bool
	 */
	public function has_discounted_price( $base_price, $product, $user_id = null ) {

		if ( ! is_object( $product ) ) {
			$product = wc_get_product( $product );
		}

		$has_discounted_price = is_numeric( $this->get_discounted_price( $base_price, $product, $user_id ) );

		if ( ! $has_discounted_price && $product->is_type( 'variable' ) && ( $variations = $product->get_children() ) ) {

			$variations_discounts = array();

			foreach ( $variations as $variation_id ) {

				$variations_discounts[] = $this->has_discounted_price( $base_price, $variation_id, $user_id );
			}

			$has_discounted_price = in_array( true, $variations_discounts, true );
		}

		return $has_discounted_price;
	}


	/**
	 * Refresh cart fragments upon member login
	 *
	 * This is useful if a non-logged in member added items to cart
	 * which should have otherwise membership discounts applied
	 *
	 * @internal
	 *
	 * @see \WC_Cart::reset()
	 *
	 * @since 1.6.4
	 * @param string $user_login User login name
	 * @param \WP_User $user User that just logged in
	 */
	public function refresh_cart_upon_member_login( $user_login, $user ) {

		// small "hack" to trigger a refresh in cart contents
		// that will set any membership discounts to products that apply
		if ( $user_login && wc_memberships_is_user_active_member( $user, null, false ) ) {

			$this->reset_cart_session_data();
		}
	}


	/**
	 * Reset cart session data
	 *
	 * @see \WC_Cart::reset() private method
	 *
	 * @since 1.6.4
	 */
	private function reset_cart_session_data() {

		$wc = WC();

		// some very zealous sanity checks here
		if ( $wc && isset( $wc->cart->cart_session_data ) ) {

			$session_data = $wc->cart->cart_session_data;

			if ( ! empty( $session_data ) ) {

				foreach ( $session_data as $key => $default ) {

					if ( isset( $wc->session->$key ) ) {
						unset( $wc->session->$key );
					}
				}
			}

			// WooCommerce core filter
			do_action( 'woocommerce_cart_reset', $wc->cart, true );
		}
	}


	/**
	 * Enable price adjustments
	 *
	 * Calling this method will **enable** Membership adjustments
	 * for product prices that have member discounts for logged in members
	 *
	 * @see \WC_Memberships_Member_Discounts::__construct() docblock for additional notes
	 * @see \WC_Memberships_Member_Discounts::enable_price_html_adjustments() which you'll probably want to use too
	 *
	 * @since 1.3.0
	 */
	public function enable_price_adjustments() {

		// apply membership discount to product price
		add_filter( 'woocommerce_get_price',                 array( $this, 'on_get_price' ), 999, 2 );
		add_filter( 'woocommerce_variation_prices_price',    array( $this, 'on_get_price' ), 999, 2 );
		add_filter( 'woocommerce_get_variation_price',       array( $this, 'on_get_variation_price' ), 999, 4 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'set_user_variation_prices_hash' ), 999, 2 );
	}


	/**
	 * Disable price adjustments
	 *
	 * Calling this method will **disable** Membership adjustments
	 * for product prices that have member discounts for logged in members
	 *
	 * @see \WC_Memberships_Member_Discounts::__construct() docblock for additional notes
	 * @see \WC_Memberships_Member_Discounts::disable_price_html_adjustments() which you'll probably want to use too
	 *
	 * @since 1.3.0
	 */
	public function disable_price_adjustments() {

		// restore price to original amount before membership discount
		remove_filter( 'woocommerce_get_price',                 array( $this, 'on_get_price' ), 999 );
		remove_filter( 'woocommerce_variation_prices_price',    array( $this, 'on_get_price' ), 999 );
		remove_filter( 'woocommerce_get_variation_price',       array( $this, 'on_get_variation_price' ), 999 );
		remove_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'set_user_variation_prices_hash' ), 999 );
	}


	/**
	 * Enable price HTML adjustments
	 *
	 * @see \WC_Memberships_Member_Discounts::__construct() docblock for additional notes
	 * @see \WC_Memberships_Member_Discounts::enable_price_adjustments() which you'll probably want to use too
	 *
	 * @since 1.3.0
	 */
	public function enable_price_html_adjustments() {

		// filter the prices to apply member discounts
		add_filter( 'woocommerce_variation_price_html', array( $this, 'on_price_html' ), 999, 2 );
		add_filter( 'woocommerce_get_price_html',       array( $this, 'on_price_html' ), 999, 2 );
	}


	/**
	 * Disable price HTML adjustments
	 *
	 * @see \WC_Memberships_Member_Discounts::__construct() docblock for additional notes
	 * @see \WC_Memberships_Member_Discounts::disable_price_adjustments() which you'll probably want to use too
	 *
	 * @since 1.3.0
	 */
	public function disable_price_html_adjustments() {

		// so we can display prices before discount
		remove_filter( 'woocommerce_get_price_html',       array( $this, 'on_price_html' ), 999 );
		remove_filter( 'woocommerce_variation_price_html', array( $this, 'on_price_html' ), 999 );
	}


}
