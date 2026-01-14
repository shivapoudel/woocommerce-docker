<?php
/**
 * Plugin Name: Prevent Duplicate Orders
 * Description: Atomic locking to prevent duplicate orders from concurrent requests.
 * Author: Eachperson
 * Author URI: https://www.eachperson.com
 * Version: 1.0.0
 * Text Domain: prevent-duplicate-orders
 *
 * @package prevent-duplicate-orders
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prevents duplicate orders from concurrent checkout requests using atomic locking.
 *
 * Uses MySQL's GET_LOCK() to serialize checkout processing for the same cart.
 * If a concurrent request is detected (same cart hash within the lockout window),
 * it blocks the duplicate attempt ensuring data integrity.
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
	 * Initialize the plugin.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		if ( ! defined( 'WC_DUPLICATE_ORDER_FIX' ) || ! WC_DUPLICATE_ORDER_FIX ) {
			return;
		}

		// Registers actions to acquire/release locks during checkout.
		add_action( 'woocommerce_checkout_process', [ __CLASS__, 'acquire_lock' ], 1 );
		add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'release_lock' ], 99 );
		register_shutdown_function( [ __CLASS__, 'release_lock' ] );
	}

	/**
	 * Acquire an atomic lock at the start of the checkout process.
	 *
	 * This method utilizes MySQL's GET_LOCK() function to enforce atomic,
	 * connection-specific locking. This prevents race conditions where multiple
	 * concurrent requests could otherwise validate and process the same cart
	 * simultaneously.
	 *
	 * It also performs a critical check for existing orders created within the
	 * configured lockout duration to block duplicate attempts.
	 *
	 * @since 1.0.0
	 *
	 * @throws Exception If the lock cannot be obtained (timeout) or if a duplicate
	 *                   order is detected immediately after acquiring the lock.
	 */
	public static function acquire_lock() {
		global $wpdb;

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

		// Get lockout duration.
		$lockout_duration = defined( 'WC_DUPLICATE_ORDER_LOCKOUT_DURATION' ) ? (int) WC_DUPLICATE_ORDER_LOCKOUT_DURATION : 60;

		// Acquire MySQL lock atomically.
		// GET_LOCK() returns: 1 if lock acquired, 0 if timeout, NULL on error.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$acquired = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::$lock_key, $lockout_duration )
		);

		// Check if the lock was not acquired.
		if ( 1 !== (int) $acquired ) {
			throw new Exception( esc_html__( 'Your order is being processed. Please wait.', 'prevent-duplicate-orders' ) );
		}

		// Critical check: Ensure no order was created while waiting for the lock.
		// Query HPOS tables - cart_hash is in operational_data table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$time_threshold = gmdate( 'Y-m-d H:i:s', time() - $lockout_duration );
		$existing_order = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT o.id FROM {$wpdb->prefix}wc_orders o
				INNER JOIN {$wpdb->prefix}wc_order_operational_data od ON o.id = od.order_id
				WHERE od.cart_hash = %s
				AND o.billing_email = %s
				AND o.date_created_gmt > %s
				AND o.status IN ('wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed')
				ORDER BY o.date_created_gmt DESC
				LIMIT 1",
				$cart_hash,
				$email,
				$time_threshold
			)
		);

		if ( $existing_order ) {
			self::release_lock();
			throw new Exception(
				sprintf(
					/* translators: %d: order number */
					esc_html__( 'Order #%d has already been placed. Please check your orders.', 'prevent-duplicate-orders' ),
					(int) $existing_order
				)
			);
		}
	}

	/**
	 * Release the acquired MySQL lock.
	 *
	 * This method ensures the lock is released using MySQL's RELEASE_LOCK()
	 * function. It is safe to call multiple times as it checks if a lock key
	 * is currently held before attempting release.
	 *
	 * @since 1.0.0
	 */
	public static function release_lock() {
		global $wpdb;

		if ( ! self::$lock_key ) {
			return;
		}

		// RELEASE_LOCK() returns: 1 if released, 0 if lock wasn't held, NULL on error.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->get_var(
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::$lock_key )
		);

		self::$lock_key = null;
	}
}

// Initialize the plugin.
add_action( 'plugins_loaded', [ 'Prevent_Duplicate_Orders', 'init' ] );
