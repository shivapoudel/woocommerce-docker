<?php
/**
 * Plugin Name: Concurrent Checkout Tester
 * Description: Adds a button to checkout that sends concurrent requests to test for duplicate orders.
 * Author: Eachperson
 * Author URI: https://www.eachperson.com
 * Version: 1.0.0
 *
 * @package concurrent-checkout-tester
 */

defined( 'ABSPATH' ) || exit;

/**
 * Concurrent Checkout Tester.
 *
 * This plugin helps replicate duplicate order issues that occur in production
 * environments with load balancers (Kong, AWS ALB) by sending concurrent
 * checkout requests to trigger the race condition.
 *
 * @since 1.0.0
 */
class Concurrent_Checkout_Tester {

	/**
	 * Test order meta key.
	 *
	 * @var string
	 */
	const TEST_ORDER_META = '_cct_test_order';

	/**
	 * Whether the fix can be toggled via UI.
	 *
	 * @var bool
	 */
	private static $is_togglable = true;

	/**
	 * Initialize the plugin.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Register admin page.
		add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );

		// Handle AJAX actions.
		add_action( 'wp_ajax_cct_delete_orders', [ __CLASS__, 'ajax_delete_orders' ] );
		add_action( 'wp_ajax_cct_process_orders', [ __CLASS__, 'ajax_process_orders' ] );

		// Only run test hooks if enabled.
		if ( ! self::is_enabled() ) {
			return;
		}

		// Disable order restriction for testing.
		if ( ! defined( 'EACHPERSON_RESTRICT_ORDER_DISABLED' ) ) {
			define( 'EACHPERSON_RESTRICT_ORDER_DISABLED', true );
		}

		// Define duplicate order fix based on cookie.
		if ( ! defined( 'WC_DUPLICATE_ORDER_FIX' ) ) {
			define( 'WC_DUPLICATE_ORDER_FIX', ( $_COOKIE['wc_cct_fix_enabled'] ?? 'yes' ) !== 'no' );
		} else {
			self::$is_togglable = false;
		}

		// Tag test orders.
		add_action( 'woocommerce_new_order', [ __CLASS__, 'tag_test_order' ], 10, 2 );

		// Add test button to checkout.
		add_action( 'woocommerce_review_order_after_submit', [ __CLASS__, 'render_test_button' ] );
		add_action( 'wp_footer', [ __CLASS__, 'render_test_script' ] );
	}

	/**
	 * Check if concurrent checkout tester is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return defined( 'WC_CONCURRENT_CHECKOUT_TEST' ) && WC_CONCURRENT_CHECKOUT_TEST;
	}

	/**
	 * Get the lockout duration in seconds.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function get_lockout_duration() {
		return defined( 'WC_DUPLICATE_ORDER_LOCKOUT_DURATION' ) ? (int) WC_DUPLICATE_ORDER_LOCKOUT_DURATION : 60;
	}

	/**
	 * Add admin menu.
	 *
	 * @since 1.0.0
	 */
	public static function add_admin_menu() {
		add_submenu_page(
			'tools.php',
			'Concurrent Checkout Tester',
			'Concurrent Checkout',
			'manage_options',
			'concurrent-checkout-tester',
			[ __CLASS__, 'render_admin_page' ]
		);
	}

	/**
	 * Render admin page.
	 *
	 * @since 1.0.0
	 */
	public static function render_admin_page() {
		$test_orders = self::get_test_orders();
		$fix_enabled = defined( 'WC_DUPLICATE_ORDER_FIX' ) && WC_DUPLICATE_ORDER_FIX;
		?>
		<div class="wrap">
			<h1>Concurrent Checkout Tester</h1>

			<!-- How to Use -->
			<div class="card" style="max-width:900px;padding:20px;margin-top:20px;background:#f0f6fc;border-left:4px solid #0073aa;">
				<h2 style="margin-top:0;">📖 How to Replicate Duplicate Orders</h2>
				<p>This plugin sends <strong>concurrent checkout requests</strong> to demonstrate the race condition that causes duplicate orders in multi-pod environments.</p>

				<h3>Root Cause</h3>
				<p>When multiple checkout requests arrive simultaneously (from AWS ALB retries, browser retries, or concurrent tabs), WooCommerce lacks atomic locking to prevent duplicate order creation:</p>
				<table class="widefat" style="max-width:100%;margin-top:10px;">
					<thead>
						<tr>
							<th>The Race Condition</th>
							<th>Result</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>Request 1 → Check cart valid ✓ → Create order</td>
							<td>Order #101 created</td>
						</tr>
						<tr>
							<td>Request 2 → Check cart valid ✓ → Create order (before #101 completes)</td>
							<td>Order #102 created (DUPLICATE!)</td>
						</tr>
						<tr>
							<td>Request 3 → Check cart valid ✓ → Create order (before #101 completes)</td>
							<td>Order #103 created (DUPLICATE!)</td>
						</tr>
					</tbody>
				</table>

				<h3>Quick Start</h3>
				<ol style="margin-left:20px;">
					<li><strong>Disable duplicate order fix</strong></li>
					<li><strong>Add a product to shopping cart</strong></li>
					<li><strong>Go to checkout page</strong> - Fill in billing details</li>
					<li><strong>Click "Run Concurrent Test"</strong> - Sends concurrent requests</li>
					<li><strong>Check results</strong> - Multiple order IDs = duplicates!</li>
					<li><strong>Enable the fix</strong> and repeat - Only 1 order created</li>
				</ol>

				<h3>The Fix: Atomic MySQL Locking</h3>
				<p>The <code>prevent-duplicate-orders.php</code> plugin uses MySQL <code>GET_LOCK()</code> to serialize requests. Only one request proceeds at a time - others wait, then fail naturally because the cart is empty.</p>
			</div>

			<!-- Configuration -->
			<div class="card" style="max-width:900px;padding:20px;margin-top:20px;">
				<h2 style="margin-top:0;">Configurations</h2>
				<table class="widefat" style="max-width:600px;">
				<tr>
						<td><code>WC_DUPLICATE_ORDER_FIX</code></td>
						<td><?php echo $fix_enabled ? '✅ Enabled' : '❌ Disabled'; ?></td>
					</tr>
					<tr>
						<td><code>WC_CONCURRENT_CHECKOUT_TEST</code></td>
						<td><?php echo self::is_enabled() ? '✅ Enabled' : '❌ Disabled'; ?></td>
					</tr>
					<tr>
						<td><code>WC_CONCURRENT_CHECKOUT_REQUESTS</code></td>
						<td><?php echo defined( 'WC_CONCURRENT_CHECKOUT_REQUESTS' ) ? esc_html( WC_CONCURRENT_CHECKOUT_REQUESTS ) . ' requests' : '5 (default)'; ?></td>
					</tr>
				</table>

				<?php if ( ! self::is_enabled() ) : ?>
					<div style="margin-top:15px;padding:15px;background:#fff3cd;border-left:4px solid #856404;">
						<strong>⚠️ Plugin is disabled!</strong>
						<p style="margin:5px 0 0;">Add the constants above to wp-config.php to enable concurrent checkout testing.</p>
					</div>
				<?php endif; ?>
			</div>

			<!-- Delete Test Orders -->
			<div class="card" style="max-width:900px;padding:20px;margin-top:20px;">
				<h2 style="margin-top:0;">Delete Test Orders</h2>
				<p>
					Found <strong><?php echo count( $test_orders ); ?></strong> test orders (tagged with _cct_test_order meta)
				</p>
				<?php if ( ! empty( $test_orders ) ) : ?>
					<p>
						<strong>Order IDs:</strong>
						<?php echo esc_html( implode( ', ', array_slice( $test_orders, 0, 20 ) ) ); ?>
						<?php if ( count( $test_orders ) > 20 ) : ?>
							... and <?php echo count( $test_orders ) - 20; ?> more
						<?php endif; ?>
					</p>
					<p>
						<button id="cct-delete-orders" class="button button-secondary" style="color:#a00;">
							Delete All <?php echo count( $test_orders ); ?> Test Orders
						</button>
					</p>
					<div id="cct-delete-results"></div>
				<?php else : ?>
					<p style="color:#666;">No test orders found.</p>
				<?php endif; ?>
			</div>
		</div>

		<script>
		(function($) {
			var AJAX_URL = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
			var NONCE = '<?php echo esc_js( wp_create_nonce( 'cct_nonce' ) ); ?>';

			// Delete orders.
			$('#cct-delete-orders').on('click', function() {
				if (!confirm('Are you sure you want to permanently delete all test orders?')) return;

				var btn = $(this);
				btn.prop('disabled', true).text('Deleting...');

				$.post(AJAX_URL, {
					action: 'cct_delete_orders',
					nonce: NONCE
				}, function(response) {
					if (response.success) {
						$('#cct-delete-results').html('<div style="color:#080;padding:10px;background:#efe;margin-top:10px;">✅ ' + response.data.message + '</div>');
						setTimeout(function() { location.reload(); }, 1500);
					} else {
						$('#cct-delete-results').html('<div style="color:#a00;padding:10px;background:#fee;margin-top:10px;">❌ ' + response.data + '</div>');
						btn.prop('disabled', false);
					}
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * Get test orders.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_test_orders() {
		return wc_get_orders(
			[
				'meta_key' => self::TEST_ORDER_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'limit'    => -1,
				'return'   => 'ids',
			]
		);
	}

	/**
	 * Delete test orders.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function delete_test_orders() {
		$deleted   = 0;
		$order_ids = self::get_test_orders();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete( true );
				++$deleted;
			}
		}

		if ( class_exists( '\Automattic\WooCommerce\Caches\OrderCountCache' ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCountCache::class )->flush();
		}

		return $deleted;
	}

	/**
	 * AJAX: Delete test orders.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_delete_orders() {
		check_ajax_referer( 'cct_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$deleted = self::delete_test_orders();

		wp_send_json_success(
			[
				'deleted' => $deleted,
				'message' => sprintf( 'Deleted %d test orders.', $deleted ),
			]
		);
	}

	/**
	 * AJAX: Update orders to processing status.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_process_orders() {
		check_ajax_referer( 'cct_nonce', 'nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$order_ids = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) $_POST['order_ids'] ) : [];

		if ( empty( $order_ids ) ) {
			wp_send_json_error( 'No order IDs provided.' );
		}

		$processed = 0;
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order && 'pending' === $order->get_status() ) {
				$order->payment_complete();
				++$processed;
			}
		}

		wp_send_json_success(
			[
				'processed' => $processed,
				'message'   => sprintf( 'Updated %d orders to Processing.', $processed ),
			]
		);
	}

	/**
	 * Check if there is a recent order for the current cart/customer.
	 *
	 * @since 1.0.0
	 * @return array|null Order data if found, null otherwise.
	 */
	public static function get_recent_order_data() {
		if ( defined( 'WC_DUPLICATE_ORDER_FIX' ) && ! WC_DUPLICATE_ORDER_FIX ) {
			return null;
		}

		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return null;
		}

		$cart_hash = WC()->cart->get_cart_hash();
		if ( empty( $cart_hash ) ) {
			return null;
		}

		$email = WC()->checkout->get_value( 'billing_email' );
		if ( empty( $email ) ) {
			return null;
		}

		global $wpdb;
		// Look for order in HPOS with same cart hash and billing email within last window.
		$table_orders     = $wpdb->prefix . 'wc_orders';
		$table_operational = $wpdb->prefix . 'wc_order_operational_data';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT o.id, UNIX_TIMESTAMP(o.date_created_gmt) as timestamp
				FROM $table_orders AS o
				INNER JOIN $table_operational AS op ON o.id = op.order_id
				WHERE op.cart_hash = %s
				AND o.billing_email = %s
				AND o.status NOT IN ('wc-cancelled', 'wc-failed')
				AND UNIX_TIMESTAMP(o.date_created_gmt) > %d
				LIMIT 1",
				$cart_hash,
				$email,
				time() - self::get_lockout_duration()
			)
		);

		if ( ! $row ) {
			return null;
		}

		return [
			'id'        => (int) $row->id,
			'timestamp' => (int) $row->timestamp,
		];
	}

	/**
	 * Tag order as test order.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 */
	public static function tag_test_order( $order_id, $order ) {
		$order->update_meta_data( self::TEST_ORDER_META, time() );
		$order->update_meta_data( '_cct_timestamp', microtime( true ) );
		$order->save();
	}

	/**
	 * Render test button on checkout page.
	 *
	 * @since 1.0.0
	 */
	public static function render_test_button() {
		$requests    = defined( 'WC_CONCURRENT_CHECKOUT_REQUESTS' ) ? (int) WC_CONCURRENT_CHECKOUT_REQUESTS : 5;
		$fix_enabled = defined( 'WC_DUPLICATE_ORDER_FIX' ) && WC_DUPLICATE_ORDER_FIX;
		?>
		<div id="cct-panel" style="float:right;display:flex;flex-direction:column;width:100%;margin-top:20px;padding:16px;background:#fefce8;border:1px solid #fbbf24;border-radius:4px;box-sizing:border-box;">
			<div class="cct-panel-header" style="display:flex;justify-content:space-between;align-items:center;width:100%;">
				<span style="font-size:14px;font-weight:600;">Concurrent Test</span>
				<?php if ( ! self::$is_togglable ) : ?>
					<span style="font-size:11px;padding:2px 8px;border-radius:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.025em;background:<?php echo $fix_enabled ? '#dcfce7' : '#fee2e2'; ?>;color:<?php echo $fix_enabled ? '#166534' : '#991b1b'; ?>;">
						Fix <?php echo $fix_enabled ? 'ON' : 'OFF'; ?>
					</span>
				<?php else : ?>
					<label for="cct-fix-toggle" style="display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none;margin:0;">
						<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.025em;color:<?php echo $fix_enabled ? '#166534' : '#991b1b'; ?>;">
							Fix <?php echo $fix_enabled ? 'ON' : 'OFF'; ?>
						</span>
						<div style="position:relative;display:inline-block;width:34px;height:18px;">
							<input type="checkbox" id="cct-fix-toggle" style="opacity:0;width:0;height:0;" <?php echo $fix_enabled ? 'checked' : ''; ?>>
							<span style="position:absolute;top:0;left:0;right:0;bottom:0;background-color:<?php echo $fix_enabled ? '#22c55e' : '#cbd5e1'; ?>;transition:.2s;border-radius:18px;"></span>
							<span style="position:absolute;height:14px;width:14px;left:<?php echo $fix_enabled ? '18px' : '2px'; ?>;bottom:2px;background-color:white;transition:.2s;border-radius:50%;box-shadow:0 1px 2px rgba(0,0,0,0.1);"></span>
						</div>
					</label>
				<?php endif; ?>
			</div>
			<p style="margin:8px 0 0;font-size:12px;color:#92400e;line-height:1.4;">
				Sends <?php echo esc_html( $requests ); ?> concurrent requests. Stripe orders auto-update to Processing.
			</p>
			<button type="button" id="cct-run-test" class="button alt" style="width:100%;margin-top:10px;">Run Concurrent Test</button>
			<div id="cct-lockout-notice" style="display:none;width:100%;"></div>
			<div id="cct-results" style="display:flex;flex-wrap:wrap;width:100%;margin-top:10px;font-size:12px;line-height:1.5;gap:6px;"></div>
		</div>
		<?php
	}

	/**
	 * Render JavaScript for concurrent test.
	 *
	 * @since 1.0.0
	 */
	public static function render_test_script() {
		if ( ! is_checkout() ) {
			return;
		}

		$requests      = defined( 'WC_CONCURRENT_CHECKOUT_REQUESTS' ) ? (int) WC_CONCURRENT_CHECKOUT_REQUESTS : 5;
		$fix_enabled   = defined( 'WC_DUPLICATE_ORDER_FIX' ) && WC_DUPLICATE_ORDER_FIX;
		$checkout_url  = wc_get_checkout_url();
		$ajax_url      = admin_url( 'admin-ajax.php' );
		$nonce         = wp_create_nonce( 'cct_nonce' );
		?>
		<script>
		// Handle recent order locking and countdown.
		(function() {
			const fixEnabled = <?php echo $fix_enabled ? 'true' : 'false'; ?>;
			let countdownInterval = null;
			let localRecentOrder = <?php echo ($recent = self::get_recent_order_data()) ? json_encode($recent) : 'null'; ?>;
			let currentRemaining = 0;

			// Calculate time offset once on page load.
			const serverTimeAtLoad = <?php echo time(); ?>;
			const clientTimeAtLoad = Math.floor(Date.now() / 1000);
			const timeOffset = serverTimeAtLoad - clientTimeAtLoad;
			const lockoutDuration = <?php echo (int) self::get_lockout_duration(); ?>;

			window.cctUpdateLockout = (customData) => {
				if (customData) localRecentOrder = customData;
				const order = localRecentOrder;

				if (!fixEnabled) {
					toggleButtons(false);
					return;
				}

				if (countdownInterval) clearInterval(countdownInterval);
				if (!order) {
					toggleButtons(false);
					return;
				}

				const disableButtons = () => {
					const currentServerTime = Math.floor(Date.now() / 1000) + timeOffset;
					const remaining = Math.min(lockoutDuration, Math.max(0, lockoutDuration - (currentServerTime - order.timestamp)));
					currentRemaining = remaining;

					const lockoutNotice = document.getElementById('cct-lockout-notice');

					if (remaining > 0) {
						toggleButtons(true);
						if (lockoutNotice) {
							const html = `<div style="display:flex;flex:1 1 100%;box-sizing:border-box;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:4px;color:#991b1b;font-weight:600;margin-top:10px;align-items:center;gap:12px;"><span style="display:flex;align-items:center;justify-content:center;flex-shrink:0;width:20px;line-height:1;margin-top:-1px;">🚨</span><span style="flex:1;">Order #${order.id} has already been placed. Retry in ${remaining}s...</span></div>`;
							if (lockoutNotice.innerHTML !== html) {
								lockoutNotice.innerHTML = html;
								lockoutNotice.style.display = 'flex';
							}
						}
					} else {
						toggleButtons(false);
						if (lockoutNotice) {
							lockoutNotice.innerHTML = '';
							lockoutNotice.style.display = 'none';
						}
						if (countdownInterval) clearInterval(countdownInterval);
					}
				};

				disableButtons();
				countdownInterval = setInterval(disableButtons, 1000);
			};

			function toggleButtons(disabled) {
				const btnMain = document.getElementById('place_order');
				if (btnMain) btnMain.disabled = disabled;
			}

			// Initial run.
			window.cctUpdateLockout();

			// Handle AJAX updates (re-apply current lockout state if any).
			jQuery(document.body).on('updated_checkout', () => {
				window.cctUpdateLockout();
			});

			// Test button click handler.
			document.body.addEventListener('click', async function(e) {
				if (!e.target.matches('#cct-run-test')) return;
				e.preventDefault(); // Prevent default form submission if button is inside a form
				const btn = e.target;
				const results = document.getElementById('cct-results');

				const REQUESTS = <?php echo (int) $requests; ?>;
				const form = document.querySelector('form.checkout');

				if (!form) return alert('Checkout form not found');

				const formData = new URLSearchParams(new FormData(form)).toString();
				const isStripe = form.querySelector('input[name="payment_method"]:checked')?.value?.includes('stripe');

				btn.disabled = true;
				btn.textContent = 'Running...';
				results.innerHTML = '<div style="display:flex;flex:1 1 100%;box-sizing:border-box;padding:10px 14px;background:#e7f3ff;border-radius:4px;margin-bottom:8px;align-items:center;gap:12px;"><span style="display:flex;align-items:center;justify-content:center;flex-shrink:0;width:20px;line-height:1;margin-top:-1px;">🔄</span><span style="flex:1;">Sending ' + REQUESTS + ' concurrent requests...</span></div>';

				const sendRequest = async (id) => {
					try {
						const res = await fetch('<?php echo esc_url( $checkout_url ); ?>?wc-ajax=checkout', {
							method: 'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body: formData,
							credentials: 'same-origin'
						});
						const data = await res.json();
						const orderId = data.order_id || (data.redirect?.match(/order-received\/(\d+)/)?.[1]);
						return { id, orderId, error: orderId ? null : (data.messages?.replace(/<[^>]*>/g, '').trim() || 'Failed') };
					} catch (e) {
						return { id, orderId: null, error: 'Network error' };
					}
				};

				// Launch all requests simultaneously.
				const responses = await Promise.all([...Array(REQUESTS)].map((_, i) => sendRequest(i + 1)));
				const orderIds = responses.map(r => r.orderId).filter(Boolean);
				const unique = [...new Set(orderIds)];

				// Build results HTML.
				let html = '<div style="display:flex;flex-wrap:wrap;width:100%;font-size:13px;gap:6px;">';
				responses.forEach(r => {
					let color = '#f8d7da';
					let icon = '❌';
					let label = r.error;

					if (r.orderId) {
						color = '#d4edda';
						icon = '✅';
						label = 'Order #' + r.orderId;
					} else if (r.error && r.error.includes('already been placed')) {
						color = '#fff3cd';
						icon = '⚠️';
						label = r.error;
					}

					html += `<div style="display:flex;flex:1 1 100%;box-sizing:border-box;padding:6px 14px;background:${color};border-radius:4px;align-items:center;gap:12px;"><span style="display:flex;align-items:center;justify-content:center;flex-shrink:0;width:20px;line-height:1;margin-top:-1px;">${icon}</span><span style="flex:1;">[${r.id}] ${label}</span></div>`;
				});
				html += '</div>';

				// Summary.
				html += '<div style="display:flex;flex:1 1 100%;box-sizing:border-box;margin-top:12px;padding:10px 14px;border-radius:6px;font-weight:600;align-items:center;gap:12px;';
				if (unique.length === 0) {
					html += 'background:#fff3cd;color:#856404;"><span style="display:flex;align-items:center;justify-content:center;flex-shrink:0;width:20px;line-height:1;margin-top:-1px;">⚠️</span><span style="flex:1;">No orders created</span></div>';
				} else if (unique.length === 1) {
					html += 'background:#d4edda;color:#155724;"><span style="display:flex;align-items:center;justify-content:center;flex-shrink:0;width:20px;line-height:1;margin-top:-1px;">✅</span><span style="flex:1;">FIX WORKING - Only 1 order: #' + unique[0] + '</span></div>';
				} else {
					html += 'background:#f8d7da;color:#721c24;"><span style="display:flex;align-items:center;justify-content:center;flex-shrink:0;width:20px;line-height:1;margin-top:-1px;">🚨</span><span style="flex:1;">DUPLICATES! ' + unique.length + ' orders: #' + unique.join(', #') + '</span></div>';
				}

				results.innerHTML = html;

				// Trigger immediate lockout if an order was created.
				if (unique.length > 0) {
					window.cctUpdateLockout({
						id: unique[0],
						timestamp: Math.floor(Date.now() / 1000)
					});
				}

				// Auto-update to Processing for Stripe payments.
				if (unique.length > 0 && isStripe) {
					results.innerHTML += '<div style="display:flex;flex:1 1 100%;box-sizing:border-box;margin-top:8px;padding:10px 14px;background:#e7f3ff;border-radius:4px;align-items:center;gap:12px;"><span style="display:flex;align-items:center;justify-content:center;flex-shrink:0;width:20px;line-height:1;margin-top:-1px;">🔄</span><span style="flex:1;">Updating orders to Processing...</span></div>';

					try {
						const formBody = new URLSearchParams();
						formBody.append('action', 'cct_process_orders');
						formBody.append('nonce', '<?php echo esc_js( $nonce ); ?>');
						unique.forEach(id => formBody.append('order_ids[]', id));

						const res = await fetch('<?php echo esc_url( $ajax_url ); ?>', {
							method: 'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body: formBody.toString(),
							credentials: 'same-origin'
						});
						const data = await res.json();

						if (data.success) {
							results.innerHTML += '<div style="display:flex;flex:1 1 100%;box-sizing:border-box;margin-top:4px;padding:10px 14px;background:#d4edda;border-radius:4px;align-items:center;gap:12px;"><span style="display:flex;align-items:center;justify-content:center;flex-shrink:0;width:20px;line-height:1;margin-top:-1px;">✅</span><span style="flex:1;">' + data.data.message + '</span></div>';
						} else {
							results.innerHTML += '<div style="display:flex;flex:1 1 100%;box-sizing:border-box;margin-top:4px;padding:10px 14px;background:#f8d7da;border-radius:4px;align-items:center;gap:12px;"><span style="display:flex;align-items:center;justify-content:center;flex-shrink:0;width:20px;line-height:1;margin-top:-1px;">❌</span><span style="flex:1;">Failed to update orders</span></div>';
						}
					} catch (e) {
						results.innerHTML += '<div style="display:flex;flex:1 1 100%;box-sizing:border-box;margin-top:4px;padding:10px 14px;background:#f8d7da;border-radius:4px;align-items:center;gap:12px;"><span style="display:flex;align-items:center;justify-content:center;flex-shrink:0;width:20px;line-height:1;margin-top:-1px;">❌</span><span style="flex:1;">Network error updating orders</span></div>';
					}
				}

				btn.disabled = false;
				btn.textContent = 'Run Concurrent Test';
			});

			// Handle fix toggle using event delegation.
			// This ensures the toggle works even after WooCommerce AJAX replaces the fragments.
			document.body.addEventListener('change', function(e) {
				if (e.target.id !== 'cct-fix-toggle') return;

				const toggle = e.target;
				const value = toggle.checked ? 'yes' : 'no';
				const d = new Date();
				d.setTime(d.getTime() + (30*24*60*60*1000));

				// Set cookie with broad path.
				document.cookie = "wc_cct_fix_enabled=" + value + ";expires=" + d.toUTCString() + ";path=/";

				// Simple reload without cache-buster.
				toggle.disabled = true;
				window.location.reload();
			});
		})();
		</script>
		<?php
	}
}

// Initialize plugin.
add_action( 'plugins_loaded', [ 'Concurrent_Checkout_Tester', 'init' ] );
