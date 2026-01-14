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
	 * Request ID for logging (unique per request).
	 *
	 * @since 1.0.0
	 *
	 * @var string|null
	 */
	private static $request_id = null;

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		if ( defined( 'WC_DUPLICATE_ORDER_FIX' ) && WC_DUPLICATE_ORDER_FIX ) {
			add_action( 'woocommerce_checkout_process', [ __CLASS__, 'acquire_lock' ], 1 );
			add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'on_order_created' ], 99 );
			register_shutdown_function( [ __CLASS__, 'release_lock' ] );
		}
	}

	/**
	 * Get WC Logger instance.
	 *
	 * @since 1.0.0
	 *
	 * @return WC_Logger
	 */
	private static function get_logger() {
		return wc_get_logger();
	}

	/**
	 * Get server/pod identifier for multi-server environments.
	 *
	 * @since 1.0.0
	 *
	 * @return array Server identification information.
	 */
	private static function get_server_info() {
		$info = [];

		// Client IP addresses from all headers.
		$info['client_ips'] = self::get_all_ips();

		// Server hostname (useful for identifying which pod/server).
		if ( function_exists( 'gethostname' ) ) {
			$info['hostname'] = gethostname();
		}

		// Server IP (if available).
		if ( ! empty( $_SERVER['SERVER_ADDR'] ) ) {
			$info['server_addr'] = sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) );
		}

		// Process ID (useful for identifying concurrent requests on same server).
		$info['process_id'] = getmypid();

		// Database host (to identify which DB connection is being used).
		global $wpdb;
		if ( isset( $wpdb->dbh ) && is_resource( $wpdb->dbh ) ) {
			$info['db_connection'] = 'active';
		} elseif ( isset( $wpdb->dbh ) ) {
			$info['db_connection'] = get_class( $wpdb->dbh );
		}

		// Check if HyperDB is being used.
		if ( class_exists( 'hyperdb' ) && $wpdb instanceof hyperdb ) {
			$info['db_type'] = 'HyperDB';
			// Try to get current database connection info.
			if ( isset( $wpdb->dbhname ) ) {
				$info['db_connection_name'] = $wpdb->dbhname;
			}
		} else {
			$info['db_type'] = 'Standard wpdb';
		}

		// Session information.
		if ( WC()->session ) {
			$info['session_id'] = WC()->session->get_customer_id();
		}

		// Customer ID if logged in.
		if ( is_user_logged_in() ) {
			$info['customer_id'] = get_current_user_id();
		}

		return $info;
	}

	/**
	 * Get all IP addresses from various headers.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of IP addresses with their source headers.
	 */
	private static function get_all_ips() {
		$ip_keys = [
			'HTTP_CF_CONNECTING_IP' => 'Cloudflare',
			'HTTP_X_REAL_IP'        => 'X-Real-IP',
			'HTTP_X_FORWARDED_FOR'  => 'X-Forwarded-For',
			'REMOTE_ADDR'           => 'Remote-Addr',
		];

		$ips = [];

		foreach ( $ip_keys as $key => $label ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// Handle comma-separated IPs (X-Forwarded-For can have multiple).
				$ip_list   = explode( ',', $value );
				$valid_ips = [];
				foreach ( $ip_list as $ip ) {
					$ip = trim( $ip );
					if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
						$valid_ips[] = $ip;
					}
				}
				if ( ! empty( $valid_ips ) ) {
					$ips[ $label ] = count( $valid_ips ) === 1 ? $valid_ips[0] : $valid_ips;
				}
			}
		}

		return empty( $ips ) ? [ 'unknown' => 'No IP found' ] : $ips;
	}

	/**
	 * Log message with context.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @param string $level   Log level (info, warning, error).
	 */
	private static function log( $message, $context = [], $level = 'info' ) {
		if ( ! self::$request_id ) {
			self::$request_id = uniqid( 'req_', true );
		}

		$context['request_id']  = self::$request_id;
		$context['lock_key']    = self::$lock_key;
		$context['timestamp']   = microtime( true );
		$context['server_info'] = self::get_server_info();

		// Format context data beautifully using wc_print_r for better debugging.
		$formatted_context = wc_print_r( $context, true );

		self::get_logger()->log(
			$level,
			$message . "\n" . $formatted_context,
			[ 'source' => 'prevent-duplicate-orders' ]
		);
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
			self::log( 'Cart is empty, skipping lock acquisition', [], 'info' );
			return;
		}

		$cart_hash = WC()->cart->get_cart_hash();
		if ( empty( $cart_hash ) ) {
			self::log( 'Cart hash is empty, skipping lock acquisition', [], 'info' );
			return;
		}

		// Use cart hash + customer identifier for lock key.
		$email          = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		self::$lock_key = 'wc_checkout_lock_' . md5( $cart_hash . $email );

		// Get lockout duration for consistency.
		$lockout_duration = self::get_lockout_duration();

		// Get cart info for debugging.
		$cart_info = [
			'cart_hash'        => $cart_hash,
			'cart_item_count'  => WC()->cart->get_cart_contents_count(),
			'cart_total'       => WC()->cart->get_total( 'edit' ),
			'email'            => $email,
			'lockout_duration' => $lockout_duration,
		];

		self::log(
			'Attempting to acquire lock',
			$cart_info
		);

		// Check if lock already exists.
		$lock_check_start = microtime( true );
		$existing_lock    = get_transient( self::$lock_key );
		$lock_check_time  = microtime( true ) - $lock_check_start;

		if ( false !== $existing_lock ) {
			self::log(
				'Lock already exists, request blocked',
				[
					'existing_lock_value' => $existing_lock,
					'existing_lock_age'   => time() - (int) $existing_lock,
					'lock_check_time_ms'  => round( $lock_check_time * 1000, 2 ),
				],
				'warning'
			);
			throw new Exception( esc_html__( 'Your order is being processed. Please wait.', 'woocommerce' ) );
		}

		// Acquire lock by setting transient.
		$lock_set_start = microtime( true );
		$lock_set       = set_transient( self::$lock_key, time(), $lockout_duration );
		$lock_set_time  = microtime( true ) - $lock_set_start;

		// Verify lock was actually set immediately after.
		$verify_start = microtime( true );
		$verify_lock  = get_transient( self::$lock_key );
		$verify_time  = microtime( true ) - $verify_start;

		self::log(
			'Lock acquisition attempt',
			[
				'lock_set'         => $lock_set,
				'lock_value'       => time(),
				'lockout_duration' => $lockout_duration,
				'lock_set_time_ms' => round( $lock_set_time * 1000, 2 ),
				'verify_lock'      => $verify_lock,
				'verify_time_ms'   => round( $verify_time * 1000, 2 ),
			]
		);

		// Critical check: if lock was set but immediately not found, we have a problem.
		if ( false === $verify_lock ) {
			self::log(
				'CRITICAL: Lock was set but immediately not found - possible race condition or DB replication lag',
				[
					'lock_set_result'  => $lock_set,
					'lock_set_time_ms' => round( $lock_set_time * 1000, 2 ),
					'verify_time_ms'   => round( $verify_time * 1000, 2 ),
				],
				'error'
			);
			throw new Exception( esc_html__( 'Your order is being processed. Please wait.', 'woocommerce' ) );
		}

		// Check for duplicate order using the same lockout duration.
		$duplicate_check_start = microtime( true );
		$existing_order        = self::find_recent_order( $cart_hash, $email, $lockout_duration );
		$duplicate_check_time  = microtime( true ) - $duplicate_check_start;

		if ( $existing_order ) {
			self::log(
				'Duplicate order found, releasing lock',
				[
					'existing_order_id'           => $existing_order,
					'cart_hash'                   => $cart_hash,
					'email'                       => $email,
					'duplicate_check_time_ms'     => round( $duplicate_check_time * 1000, 2 ),
					'time_since_lock_acquired_ms' => round( ( microtime( true ) - $lock_set_start ) * 1000, 2 ),
				],
				'warning'
			);
			self::release_lock();
			throw new Exception(
				sprintf(
					/* translators: %d: order number */
					esc_html__( 'Order #%d has already been placed. Please check your orders.', 'woocommerce' ),
					(int) $existing_order
				)
			);
		}

		self::log(
			'Lock acquired successfully, no duplicate orders found',
			[
				'cart_hash'                   => $cart_hash,
				'email'                       => $email,
				'duplicate_check_time_ms'     => round( $duplicate_check_time * 1000, 2 ),
				'time_since_lock_acquired_ms' => round( ( microtime( true ) - $lock_set_start ) * 1000, 2 ),
			]
		);
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

		$time_threshold = gmdate( 'Y-m-d H:i:s', time() - $lockout_duration );
		$query_start    = microtime( true );

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
				ORDER BY o.date_created_gmt DESC
				LIMIT 1",
				$cart_hash,
				$email,
				$time_threshold
			)
		);

		$query_time = microtime( true ) - $query_start;
		$result     = $order_id ? (int) $order_id : null;

		// Get database connection info for HyperDB debugging.
		$db_info = [];
		global $wpdb;
		if ( class_exists( 'hyperdb' ) && $wpdb instanceof hyperdb && isset( $wpdb->dbhname ) ) {
			$db_info['db_connection_name'] = $wpdb->dbhname;
			$db_info['db_type']            = 'HyperDB';
		}

		self::log(
			'Duplicate order check completed',
			array_merge(
				[
					'cart_hash'      => $cart_hash,
					'email'          => $email,
					'time_threshold' => $time_threshold,
					'found_order_id' => $result,
					'query_time_ms'  => round( $query_time * 1000, 2 ),
					'rows_affected'  => $wpdb->rows_affected ?? 'N/A',
					'last_query'     => $wpdb->last_query ?? 'N/A',
				],
				$db_info
			)
		);

		return $result;
	}

	/**
	 * Handle order created event.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_order_created( $order_id ) {
		// Ensure we have a numeric order ID.
		$order_id = is_object( $order_id ) && method_exists( $order_id, 'get_id' ) ? $order_id->get_id() : (int) $order_id;

		$order      = wc_get_order( $order_id );
		$order_info = [
			'order_id'     => $order_id,
			'order_status' => $order ? $order->get_status() : 'unknown',
			'order_date'   => $order ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : 'unknown',
		];

		// Get order cart hash if available.
		if ( $order && method_exists( $order, 'get_cart_hash' ) ) {
			$order_info['order_cart_hash'] = $order->get_cart_hash();
		}

		self::log(
			'Order created successfully',
			$order_info
		);
		self::release_lock();
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

		// Verify lock still exists before releasing.
		$lock_value   = get_transient( self::$lock_key );
		$lock_deleted = delete_transient( self::$lock_key );

		// Only log if we actually had a lock (not just shutdown cleanup for blocked requests).
		if ( false !== $lock_value ) {
			self::log(
				'Lock released successfully',
				[
					'lock_value_before_release' => $lock_value,
					'lock_deleted'              => $lock_deleted,
				]
			);
		}

		self::$lock_key = null;
	}
}

// Initialize the plugin.
add_action( 'plugins_loaded', [ 'Prevent_Duplicate_Orders', 'init' ] );
