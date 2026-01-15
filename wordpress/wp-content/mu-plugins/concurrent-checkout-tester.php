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
	 * Whether the duplicate order fix can be toggled via the UI.
	 *
	 * Set to false when WC_DUPLICATE_ORDER_FIX is already defined,
	 * preventing UI toggling since the constant takes precedence.
	 *
	 * @var bool
	 */
	private static $can_toggle = true;

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
			self::$can_toggle = false;
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
				<?php if ( ! self::$can_toggle ) : ?>
					<span style="font-size:11px;padding:2px 8px;border-radius:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.025em;background:<?php echo $fix_enabled ? '#dcfce7' : '#fee2e2'; ?>;color:<?php echo $fix_enabled ? '#166534' : '#991b1b'; ?>;">
						Fix <?php echo $fix_enabled ? 'ON' : 'OFF'; ?>
					</span>
				<?php else : ?>
					<label for="cct-fix-toggle" style="display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none;margin:0;">
						<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.025em;color:<?php echo $fix_enabled ? '#166534' : '#991b1b'; ?>;">
							Duplicate Protection: <?php echo $fix_enabled ? 'Active' : 'Inactive'; ?>
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
				Sends <?php echo esc_html( $requests ); ?> concurrent checkout requests. Stripe orders are automatically set to Processing status. Note: New Stripe cards may fail in concurrent tests, but duplicate order prevention will still work correctly.
			</p>
			<button type="button" id="cct-run-test" class="button alt" style="width:100%;margin-top:10px;">Run Concurrent Test</button>
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

		$requests     = defined( 'WC_CONCURRENT_CHECKOUT_REQUESTS' ) ? (int) WC_CONCURRENT_CHECKOUT_REQUESTS : 5;
		$checkout_url = wc_get_checkout_url();
		$ajax_url     = admin_url( 'admin-ajax.php' );
		$nonce        = wp_create_nonce( 'cct_nonce' );
		?>
		<script>
		// Test button click handler.
		(function() {
			// Sync concurrent test button state with place order button.
			function syncTestButton() {
				const placeOrderBtn = document.getElementById('place_order');
				const testBtn = document.getElementById('cct-run-test');
				if (placeOrderBtn && testBtn) {
					testBtn.disabled = placeOrderBtn.disabled;
				}
			}

			// Initial sync and on checkout updates.
			syncTestButton();
			jQuery(document.body).on('updated_checkout', syncTestButton);

			document.body.addEventListener('click', async function(e) {
				if (!e.target.matches('#cct-run-test')) return;
				e.preventDefault(); // Prevent default form submission if button is inside a form
				const btn = e.target;
				const results = document.getElementById('cct-results');

				// Disable button immediately to prevent double-clicks
				if (btn.disabled) return;
				const originalText = btn.textContent;
				btn.disabled = true;
				btn.textContent = 'Preparing...';

				try {
					const REQUESTS = <?php echo (int) $requests; ?>;
					const form = document.querySelector('form.checkout');

					if (!form) {
						btn.disabled = false;
						btn.textContent = originalText;
						alert('Checkout form not found');
						return;
					}

					// Check if user is logged in (required for saved cards).
					const isLoggedIn = <?php echo is_user_logged_in() ? 'true' : 'false'; ?>;
					if (!isLoggedIn) {
						btn.disabled = false;
						btn.textContent = originalText;
						alert('Please log in to use saved payment methods.');
						return;
					}

				// Collect all form data properly, including radio buttons and checkboxes.
				const formDataObj = new FormData(form);

				// For new cards with Stripe UPE, we need to create the payment method ID first.
				// This is done by accessing the Stripe Elements instance and creating a payment method.
				const paymentMethod = form.querySelector('input[name="payment_method"]:checked')?.value;
				const isStripe = paymentMethod?.includes('stripe');
				const isNewCard = isStripe && form.querySelector('input[name^="wc-"][name$="-payment-token"]:checked')?.value === 'new';

				if (isNewCard) {
					// For new cards with Stripe UPE, we need to create the payment method ID.
					// Stripe UPE creates it when the form is submitted, but we're bypassing that.
					// Try to create it programmatically from Stripe Elements.
					const expectedPaymentMethodField = 'wc-' + paymentMethod + '-payment-method';
					let paymentMethodCreated = false;

					try {
						// Get billing details
						const billingDetails = {
							name: ((form.querySelector('#billing_first_name')?.value || '').trim() + ' ' + (form.querySelector('#billing_last_name')?.value || '').trim()).trim(),
							email: form.querySelector('#billing_email')?.value || '',
							phone: form.querySelector('#billing_phone')?.value || '',
							address: {
								line1: form.querySelector('#billing_address_1')?.value || '',
								line2: form.querySelector('#billing_address_2')?.value || '',
								city: form.querySelector('#billing_city')?.value || '',
								state: form.querySelector('#billing_state')?.value || '',
								postal_code: form.querySelector('#billing_postcode')?.value || '',
								country: form.querySelector('#billing_country')?.value || '',
							}
						};

						// Try to access Stripe Elements through various methods
						let paymentElement = null;
						let stripeInstance = null;

						// Get Stripe instance
						if (window.wc_stripe && typeof window.wc_stripe.getStripe === 'function') {
							stripeInstance = window.wc_stripe.getStripe();
						} else if (window.wc_stripe_params && window.wc_stripe_params.key && window.Stripe) {
							stripeInstance = window.Stripe(window.wc_stripe_params.key);
						}

						if (stripeInstance) {
							// Try to find the payment element - check multiple possible locations
							if (window.wc_stripe_upe_elements) {
								paymentElement = window.wc_stripe_upe_elements.card?.upeElement ||
								                window.wc_stripe_upe_elements.payment;
							}

							if (!paymentElement && window.wc_stripe && window.wc_stripe.elements) {
								paymentElement = window.wc_stripe.elements.card?.upeElement ||
								                window.wc_stripe.elements.payment;
							}

							if (!paymentElement && typeof jQuery !== 'undefined') {
								const upeContainer = jQuery('.wc-stripe-upe-element, [data-payment-method-type]').first();
								if (upeContainer.length) {
									paymentElement = upeContainer.data('upe-element') || upeContainer.data('payment-element');
								}
							}

							// If we found a payment element, create payment method from it
							if (paymentElement) {
								const paymentMethodResponse = await stripeInstance.createPaymentMethod({
									type: 'card',
									card: paymentElement,
									billing_details: billingDetails
								});

								if (paymentMethodResponse && !paymentMethodResponse.error && paymentMethodResponse.paymentMethod && paymentMethodResponse.paymentMethod.id) {
									formDataObj.set(expectedPaymentMethodField, paymentMethodResponse.paymentMethod.id);
									paymentMethodCreated = true;
								}
							}
						}

						// Try window.wc_stripe.createPaymentMethod if available
						if (!paymentMethodCreated && window.wc_stripe && typeof window.wc_stripe.createPaymentMethod === 'function') {
							const paymentMethodResponse = await window.wc_stripe.createPaymentMethod({ billing_details: billingDetails });

							if (paymentMethodResponse && !paymentMethodResponse.error && paymentMethodResponse.paymentMethod && paymentMethodResponse.paymentMethod.id) {
								formDataObj.set(expectedPaymentMethodField, paymentMethodResponse.paymentMethod.id);
								paymentMethodCreated = true;
							}
						}

						if (!paymentMethodCreated) {
							// Show helpful message but don't block - let test proceed
							console.warn('Could not create payment method ID. Test may fail for new cards.');
							// Continue anyway - the test will show errors if Stripe can't process it
						}
					} catch (error) {
						console.warn('Error creating payment method:', error);
						// Continue anyway - the test will show errors if Stripe can't process it
					}
				}

				// Ensure all radio buttons and checkboxes are included (FormData only includes checked ones).
				form.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(input => {
					if (input.name) {
						if (input.type === 'radio' && input.checked) {
							formDataObj.set(input.name, input.value);
						} else if (input.type === 'checkbox' && input.checked) {
							formDataObj.append(input.name, input.value);
						}
					}
				});

				// For Stripe, ensure payment method tokens/IDs are included.
				// paymentMethod and isStripe are already declared above

				if (isStripe && paymentMethod) {
					// Expected field name: wc-{gateway_id}-payment-token
					const expectedTokenField = 'wc-' + paymentMethod + '-payment-token';

					// Find the checked payment token input for this specific gateway.
					const tokenInputs = form.querySelectorAll(`input[name="${expectedTokenField}"], input[name^="wc-"][name$="-payment-token"]`);
					let savedTokenInput = null;

					// Find the checked token input (prefer exact match, fallback to any matching pattern).
					tokenInputs.forEach(input => {
						if (input.checked && input.value && input.value !== 'new') {
							// Prefer exact field name match.
							if (input.name === expectedTokenField) {
								savedTokenInput = input;
							} else if (!savedTokenInput) {
								// Fallback to any matching token.
								savedTokenInput = input;
							}
						}
					});

					if (savedTokenInput) {
						// For saved cards, ensure the payment token is explicitly set with correct field name.
						// Use the expected field name format even if the input has a slightly different name.
						formDataObj.delete(expectedTokenField);
						formDataObj.set(expectedTokenField, savedTokenInput.value);

						// Also remove from any other field name variations.
						if (savedTokenInput.name !== expectedTokenField) {
							formDataObj.delete(savedTokenInput.name);
						}
					} else {
						// If using new card, we need a payment method ID from Stripe Elements.
						// For UPE, the field name is 'wc-stripe-payment-method' (or 'wc-{gateway_id}-payment-method').
						const expectedPaymentMethodField = 'wc-' + paymentMethod + '-payment-method';

						// Check various possible locations for the payment method ID.
						let paymentMethodId =
							form.querySelector(`input[name="${expectedPaymentMethodField}"]`)?.value ||
							form.querySelector('input[name="wc-stripe-payment-method"]')?.value ||
							form.querySelector('input[name="stripe_payment_method_id"]')?.value ||
							form.querySelector('input[name="payment_method_id"]')?.value ||
							form.querySelector('input[name="stripe-payment-method"]')?.value ||
							form.querySelector('#stripe-payment-method-id')?.value ||
							(window.stripePaymentMethodId && window.stripePaymentMethodId);

						// Also check FormData (it might already be there).
						const formDataPaymentMethodId = formDataObj.get(expectedPaymentMethodField) || formDataObj.get('wc-stripe-payment-method');

						// Check if Stripe Elements has created a payment method ID.
						// For UPE, the payment method might be stored in the Stripe instance.
						if (!paymentMethodId && !formDataPaymentMethodId) {
							// Try to get it from WooCommerce Stripe's global variables.
							if (window.wc_stripe_params && window.wc_stripe_params.payment_method_id) {
								paymentMethodId = window.wc_stripe_params.payment_method_id;
							}

							// Check for Stripe Elements instance and try to get payment method.
							// WooCommerce Stripe UPE stores the payment method in various places.
							if (!paymentMethodId) {
								// Try accessing through jQuery if available (WooCommerce uses jQuery).
								if (typeof jQuery !== 'undefined' && jQuery.fn.wc_stripe) {
									try {
										const stripeData = jQuery(form).data('stripe');
										if (stripeData && stripeData.paymentMethodId) {
											paymentMethodId = stripeData.paymentMethodId;
										}
									} catch (e) {
										// Ignore errors
									}
								}

								// Try accessing through window.wc_stripe if available.
								if (!paymentMethodId && window.wc_stripe) {
									try {
										if (typeof window.wc_stripe.getPaymentMethod === 'function') {
											const pm = window.wc_stripe.getPaymentMethod();
											if (pm && pm.id) {
												paymentMethodId = pm.id;
											}
										}
										// Also check if there's a stored payment method ID.
										if (!paymentMethodId && window.wc_stripe.paymentMethodId) {
											paymentMethodId = window.wc_stripe.paymentMethodId;
										}
									} catch (e) {
										// Ignore errors
									}
								}

								// Check for hidden input that might be added by Stripe.
								if (!paymentMethodId) {
									const hiddenInputs = form.querySelectorAll('input[type="hidden"][name*="payment"], input[type="hidden"][name*="stripe"]');
									hiddenInputs.forEach(input => {
										if (input.value && input.value.startsWith('pm_')) {
											paymentMethodId = input.value;
										}
									});
								}
							}
						}

						const finalPaymentMethodId = paymentMethodId || formDataPaymentMethodId;

						// Set the payment method ID if we have it (and it's not empty).
						// Note: Stripe UPE creates the payment method ID dynamically on form submission.
						// If it's not found here, we must NOT send an empty value, as Stripe will try to fetch it and fail.
						// Instead, we should either:
						// 1. Not include the field at all (let Stripe create it)
						// 2. Or ensure we have a valid payment method ID before submitting
						if (finalPaymentMethodId && finalPaymentMethodId.trim() !== '') {
							formDataObj.set(expectedPaymentMethodField, finalPaymentMethodId);
						} else {
							// Remove any empty payment method field to prevent Stripe from trying to fetch an empty ID.
							formDataObj.delete(expectedPaymentMethodField);
							formDataObj.delete('wc-stripe-payment-method');

							// For new cards without a payment method ID, we need to ensure the token field is set to 'new'.
							// This tells Stripe to create the payment method from the card details.
							const tokenFieldName = 'wc-' + paymentMethod + '-payment-token';
							if (!formDataObj.has(tokenFieldName)) {
								formDataObj.set(tokenFieldName, 'new');
							}
						}

						// Also ensure stripe_token is set if available.
						const stripeToken = form.querySelector('input[name="stripe_token"]')?.value;
						if (stripeToken && stripeToken !== 'new') {
							formDataObj.set('stripe_token', stripeToken);
						}

						// For new cards, ensure 'new' is set for payment token if not already present.
						const tokenFieldName = 'wc-' + paymentMethod + '-payment-token';
						if (!formDataObj.has(tokenFieldName)) {
							formDataObj.set(tokenFieldName, 'new');
						}
					}
				}

				const formData = new URLSearchParams(formDataObj).toString();

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
				} catch (error) {
					console.error('Error in concurrent checkout test:', error);
					btn.disabled = false;
					btn.textContent = 'Run Concurrent Test';
					if (results) {
						results.innerHTML = '<div style="display:flex;flex:1 1 100%;box-sizing:border-box;padding:10px 14px;background:#f8d7da;border-radius:4px;align-items:center;gap:12px;"><span style="display:flex;align-items:center;justify-content:center;flex-shrink:0;width:20px;line-height:1;margin-top:-1px;">❌</span><span style="flex:1;">Error: ' + (error.message || 'Unknown error') + '</span></div>';
					}
				}
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
