<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Prepare Local Order
 *
 * @class    WC_PaysonCheckout_WC_Order
 * @version  1.0.0
 * @package  WC_Gateway_PaysonCheckout/Classes
 * @category Class
 * @author   Krokedil
 */
class WC_PaysonCheckout_WC_Order {
	/**
	 * Prepares local order.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 */
	 
	public function update_or_create_local_order( $customer_email = '' ) {
		
		if ( WC()->session->get( 'ongoing_payson_order' ) && wc_get_order( WC()->session->get( 'ongoing_payson_order' ) ) ) {
			$orderid = WC()->session->get( 'ongoing_payson_order' );
			$order   = wc_get_order( $orderid );
		} else {
			// Create order in WooCommerce if we have an email
			$order = $this->create_order();
			//update_post_meta( $order->id, '_kco_incomplete_customer_email', $customer_email, true );
			WC()->session->set( 'ongoing_payson_order', $order->id );
		}
		
		WC_Gateway_PaysonCheckout::log( 'Update local order. Order ID: ' . $order->id );
		
		// If there's an order at this point, proceed
		if ( isset( $order ) ) {
			// Need to clean up the order first, to avoid duplicate items
			$order->remove_order_items();
			// Add order items
			$this->add_order_items( $order );
			// Add order fees
			$this->add_order_fees( $order );
			// Add order shipping
			$this->add_order_shipping( $order );
			// Add order taxes
			$this->add_order_tax_rows( $order );
			// Store coupons
			$this->add_order_coupons( $order );
			// Store payment method
			$this->add_order_payment_method( $order );
			// Calculate order totals
			$this->set_order_totals( $order );
			// Tie this order to a user
			
			if ( email_exists( $customer_email ) ) {
				$user    = get_user_by( 'email', $customer_email );
				$user_id = $user->ID;
				update_post_meta( $order->id, '_customer_user', $user_id );
			}
			
			// Let plugins add meta
			do_action( 'woocommerce_checkout_update_order_meta', $order->id, array() );
			
			return $order->id;
		}
	}
	
	
	/**
	 * Create WC order.
	 *
	 * @since  1.0.0
	 * @access public
	 */
	public function create_order() {
		
		global $woocommerce;
		// Customer accounts
		$customer_id = apply_filters( 'woocommerce_checkout_customer_id', get_current_user_id() );
		// Order data
		$order_data = array(
			'status'      => apply_filters( 'payson_checkout_incomplete_order_status', 'payson-incomplete' ),
			'customer_id' => $customer_id,
			'created_via' => 'payson_checkout'
		);
		
		// Create the order
		$order = wc_create_order( $order_data );
		
		if ( is_wp_error( $order ) ) {
			throw new Exception( __( 'Error: Unable to create order. Please try again.', 'woocommerce' ) );
		}
		
		return $order;
	}
	
	/**
	 * Adds order items to local order.
	 *
	 * @since  2.0.0
	 * @access public
	 *
	 * @param  object $order Local WC order.
	 *
	 * @throws Exception
	 */
	public function add_order_items( $order ) {
		$order->remove_order_items();
		
		global $woocommerce;
		$order_id = $order->id;
		foreach ( $woocommerce->cart->get_cart() as $cart_item_key => $values ) {
			$item_id = $order->add_product( $values['data'], $values['quantity'], array(
				'variation' => $values['variation'],
				'totals'    => array(
					'subtotal'     => $values['line_subtotal'],
					'subtotal_tax' => $values['line_subtotal_tax'],
					'total'        => $values['line_total'],
					'tax'          => $values['line_tax'],
					'tax_data'     => $values['line_tax_data'] // Since 2.2
				)
			) );
			if ( ! $item_id ) {
				WC_Gateway_PaysonCheckout::log( 'Unable to add order item' );
				throw new Exception( __( 'Error: Unable to add order item. Please try again.', 'woocommerce' ) );
			}
			// Allow plugins to add order item meta
			do_action( 'woocommerce_add_order_item_meta', $item_id, $values, $cart_item_key );
		}
	}
	
	/**
	 * Adds order fees to local order.
	 *
	 * @since  2.0.0
	 * @access public
	 *
	 * @param  object $order Local WC order.
	 *
	 * @throws Exception
	 */
	public function add_order_fees( $order ) {
		
		global $woocommerce;
		$order_id = $order->id;
		foreach ( $woocommerce->cart->get_fees() as $fee_key => $fee ) {
			$item_id = $order->add_fee( $fee );
			if ( ! $item_id ) {
				WC_Gateway_PaysonCheckout::log( 'Unable to add order fee.' );
				throw new Exception( __( 'Error: Unable to create order. Please try again.', 'woocommerce' ) );
			}
			// Allow plugins to add order item meta to fees
			do_action( 'woocommerce_add_order_fee_meta', $order_id, $item_id, $fee, $fee_key );
		}
	}
	
	/**
	 * Adds order shipping to local order.
	 *
	 * @since  2.0.0
	 * @access public
	 *
	 * @param  object $order Local WC order.
	 *
	 * @throws Exception
	 * @internal param object $klarna_order Klarna order.
	 */
	public function add_order_shipping( $order ) {
		
		global $woocommerce;
		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}
		//$woocommerce->cart->calculate_shipping();
		//$woocommerce->cart->calculate_fees();
		//$woocommerce->cart->calculate_totals();
		$order_id              = $order->id;
		$this_shipping_methods = $woocommerce->session->get( 'chosen_shipping_methods' );
		//WC_Gateway_PaysonCheckout::log( 'Adding order shipping' . var_export($this_shipping_methods, true) );
		// Store shipping for all packages
		foreach ( $woocommerce->shipping->get_packages() as $package_key => $package ) {
			if ( isset( $package['rates'][ $this_shipping_methods[ $package_key ] ] ) ) {
				$item_id = $order->add_shipping( $package['rates'][ $this_shipping_methods[ $package_key ] ] );
				if ( ! $item_id ) {
					WC_Gateway_PaysonCheckout::log( 'Unable to add shipping item.' );
					throw new Exception( __( 'Error: Unable to add shipping item. Please try again.', 'woocommerce' ) );
				}
				// Allows plugins to add order item meta to shipping
				do_action( 'woocommerce_add_shipping_order_item', $order_id, $item_id, $package_key );
			}
		}
	}
	
	/**
	 * Adds order tax rows to local order.
	 *
	 * @since  2.0.0
	 * @access public
	 *
	 * @param  object $order Local WC order.
	 */
	public function add_order_tax_rows( $order ) {
		/*if ( $this->klarna_debug == 'yes' ) {
			$this->klarna_log->add( 'klarna', 'Adding order tax...' );
		}*/
		// Store tax rows
		foreach ( array_keys( WC()->cart->taxes + WC()->cart->shipping_taxes ) as $tax_rate_id ) {
			if ( $tax_rate_id && ! $order->add_tax( $tax_rate_id, WC()->cart->get_tax_amount( $tax_rate_id ), WC()->cart->get_shipping_tax_amount( $tax_rate_id ) ) && apply_filters( 'woocommerce_cart_remove_taxes_zero_rate_id', 'zero-rated' ) !== $tax_rate_id ) {
				WC_Gateway_PaysonCheckout::log( 'Unable to add taxes.' );
				throw new Exception( sprintf( __( 'Error %d: Unable to create order. Please try again.', 'woocommerce' ), 405 ) );
			}
		}
	}
	/**
	 * Adds order coupons to local order.
	 *
	 * @since  2.0.0
	 * @access public
	 *
	 * @param  object $order Local WC order.
	 *
	 * @throws Exception
	 */
	public function add_order_coupons( $order ) {
		/*if ( $this->klarna_debug == 'yes' ) {
			$this->klarna_log->add( 'klarna', 'Adding order coupons...' );
		}*/
		global $woocommerce;
		foreach ( $woocommerce->cart->get_coupons() as $code => $coupon ) {
			if ( ! $order->add_coupon( $code, $woocommerce->cart->get_coupon_discount_amount( $code ) ) ) {
				WC_Gateway_PaysonCheckout::log( 'Unable to create order.' );
				throw new Exception( __( 'Error: Unable to create order. Please try again.', 'woocommerce' ) );
			}
		}
	}
	/**
	 * Adds payment method to local order.
	 *
	 * @since  2.0.0
	 * @access public
	 *
	 * @param  object $order Local WC order.
	 *
	 * @internal param object $klarna_order Klarna order.
	 */
	public function add_order_payment_method( $order ) {
		/*if ( $this->klarna_debug == 'yes' ) {
			$this->klarna_log->add( 'klarna', 'Adding order payment method...' );
		}*/
		global $woocommerce;
		$available_gateways = $woocommerce->payment_gateways->payment_gateways();
		$payment_method     = $available_gateways['paysoncheckout'];
		$order->set_payment_method( $payment_method );
	}
	/**
	 * Set local order totals.
	 *
	 * @since  2.0.0
	 * @access public
	 *
	 * @param  object $order Local WC order.
	 */
	public function set_order_totals( $order ) {
		/*if ( $this->klarna_debug == 'yes' ) {
			$this->klarna_log->add( 'klarna', 'Setting order totals...' );
		}*/
		global $woocommerce;
		if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			define( 'WOOCOMMERCE_CHECKOUT', true );
		}
		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}
		$woocommerce->cart->calculate_shipping();
		$woocommerce->cart->calculate_fees();
		$woocommerce->cart->calculate_totals();
		$order->set_total( $woocommerce->cart->shipping_total, 'shipping' );
		$order->set_total( $woocommerce->cart->get_cart_discount_total(), 'order_discount' );
		$order->set_total( $woocommerce->cart->get_cart_discount_total(), 'cart_discount' );
		$order->set_total( $woocommerce->cart->tax_total, 'tax' );
		$order->set_total( $woocommerce->cart->shipping_tax_total, 'shipping_tax' );
		$order->set_total( $woocommerce->cart->total );
	}
}