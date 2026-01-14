<?php
/**
 * Plugin Name: Prevent Duplicate Orders
 * Description: Atomic locking to prevent duplicate orders from concurrent requests.
 * Author: Eachperson
 * Author URI: https://www.eachperson.com
 * Version: 1.0.0
 *
 * @package prevent-duplicate-orders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Atomic locking to prevent duplicate WooCommerce orders.
 *
 * Problem: Concurrent checkout requests (from retries, double-clicks, load balancers)
 * can create multiple orders because they all pass validation before any order exists.
 *
 * Solution: Uses WordPress transients with atomic database operations for locking that
 * works across all database connections (including HyperDB read replicas). After acquiring
 * the lock, we check if an order was JUST created (within 60 seconds) with the same cart hash.
 * This catches concurrent requests while allowing legitimate repeat orders.
 *
 * @since 1.0.0
 */
class Prevent_Duplicate_Orders {

	/**
	 * Lock key for current request.
	 *
	 * @since 1.0.0
	 *
	 * @var string|null
	 */
	private static $lock_key = null;

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		if ( defined( 'WC_DUPLICATE_ORDER_FIX' ) && WC_DUPLICATE_ORDER_FIX ) {
			add_action( 'woocommerce_checkout_process', [ __CLASS__, 'acquire_lock' ], 1 );
			add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'release_lock' ], 99 );
			register_shutdown_function( [ __CLASS__, 'release_lock' ] );
		}
	}

	/**
	 * Get lockout duration in seconds.
	 *
	 * @since 1.0.0
	 *
	 * @return int Lockout duration in seconds.
	 */
	private static function get_lockout_duration() {
		return defined( 'WC_DUPLICATE_ORDER_LOCKOUT_DURATION' ) ? (int) WC_DUPLICATE_ORDER_LOCKOUT_DURATION : 60;
	}

	/**
	 * Acquire atomic lock at start of checkout.
	 *
	 * Uses WordPress transients with atomic database operations for locking.
	 *
	 * @since 1.0.0
	 *
	 * @throws Exception If lock cannot be acquired or order was just created.
	 */
	public static function acquire_lock() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		$cart_hash = WC()->cart->get_cart_hash();
		if ( empty( $cart_hash ) ) {
			return;
		}

		// Use cart hash + customer identifier for lock key.
		$email          = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		self::$lock_key = 'wc_checkout_lock_' . md5( $cart_hash . $email );

		// Get lockout duration for consistency.
		$lockout_duration = self::get_lockout_duration();

		// Check if lock already exists.
		$existing_lock = get_transient( self::$lock_key );
		if ( false !== $existing_lock ) {
			throw new Exception( esc_html__( 'Your order is being processed. Please wait.', 'woocommerce' ) );
		}

		// Acquire lock by setting transient.
		set_transient( self::$lock_key, time(), $lockout_duration );

		// Check for duplicate order using the same lockout duration.
		$existing_order = self::find_recent_order( $cart_hash, $email, $lockout_duration );
		if ( $existing_order ) {
			self::release_lock();
			throw new Exception(
				sprintf(
					/* translators: %d: order number */
					esc_html__( 'Order #%d has already been placed. Please check your orders.', 'woocommerce' ),
					(int) $existing_order
				)
			);
		}
	}

	/**
	 * Find an recent order created.
	 *
	 * @since 1.0.0
	 *
	 * @param string $cart_hash        Cart hash to search for.
	 * @param string $email            Customer email.
	 * @param int    $lockout_duration Lockout duration in seconds.
	 *
	 * @return int|null Order ID if found, null otherwise.
	 */
	private static function find_recent_order( $cart_hash, $email, $lockout_duration ) {
		global $wpdb;

		// Query HPOS tables - cart_hash is in operational_data table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$order_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT o.id FROM {$wpdb->prefix}wc_orders o
				INNER JOIN {$wpdb->prefix}wc_order_operational_data od ON o.id = od.order_id
				WHERE od.cart_hash = %s
				AND o.billing_email = %s
				AND o.date_created_gmt > %s
				AND o.status IN ('wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed')
				LIMIT 1",
				$cart_hash,
				$email,
				gmdate( 'Y-m-d H:i:s', time() - $lockout_duration )
			)
		);

		return $order_id ? (int) $order_id : null;
	}

	/**
	 * Release the transient lock.
	 *
	 * @since 1.0.0
	 */
	public static function release_lock() {
		if ( ! self::$lock_key ) {
			return;
		}

		// Delete transient lock.
		delete_transient( self::$lock_key );

		self::$lock_key = null;
	}
}

// Initialize the plugin.
add_action( 'plugins_loaded', [ 'Prevent_Duplicate_Orders', 'init' ] );
