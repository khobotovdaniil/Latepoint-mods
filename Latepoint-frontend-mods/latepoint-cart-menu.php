<?php
/**
 * Plugin Name: ISU LatePoint Header Cart
 * Description: Adds a LatePoint cart button with item count next to the Contact Us header button and opens the LatePoint checkout lightbox.
 *
 * The button is injected on the frontend only, reads LatePoint's current cart
 * state through a small AJAX endpoint, and opens checkout from the Verify Order
 * Details step by creating an order intent for the active cart.
 * Version: 1.0.0
 * Author: ISU
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ISU_LatePoint_Header_Cart {
	private const AJAX_ACTION = 'isu_latepoint_cart_menu_state';
	private const NONCE_ACTION = 'isu_latepoint_cart_menu';

	/**
	 * Registers the AJAX state endpoint and frontend footer assets.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'ajax_state' ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ __CLASS__, 'ajax_state' ] );
		add_action( 'wp_footer', [ __CLASS__, 'render_footer_assets' ], 30 );
	}

	/**
	 * Returns the current LatePoint cart state to the header button script.
	 */
	public static function ajax_state(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$state = self::get_cart_state( ! empty( $_POST['create_intent'] ) );
		wp_send_json_success( $state );
	}

	/**
	 * Reads the active LatePoint cart and optionally creates an order intent for checkout.
	 */
	private static function get_cart_state( bool $create_intent = false ): array {
		$empty_state = [
			'count'            => 0,
			'can_checkout'     => false,
			'order_intent_key' => '',
			'checkout_route'   => class_exists( 'OsRouterHelper' ) ? OsRouterHelper::build_route_name( 'steps', 'start_from_order_intent' ) : '',
			'checkout_step'    => self::get_checkout_step_code(),
		];

		if ( ! class_exists( 'OsCartsHelper' ) || ! class_exists( 'OsRouterHelper' ) ) {
			return $empty_state;
		}

		if ( ! OsCartsHelper::get_cart_uuid() ) {
			return $empty_state;
		}

		$cart  = OsCartsHelper::get_or_create_cart();
		$count = $cart ? self::get_cart_item_count( $cart ) : 0;

		$state = [
			'count'            => $count,
			'can_checkout'     => $count > 0,
			'order_intent_key' => '',
			'checkout_route'   => OsRouterHelper::build_route_name( 'steps', 'start_from_order_intent' ),
			'checkout_step'    => self::get_checkout_step_code(),
		];

		if ( $create_intent && $count > 0 && class_exists( 'OsOrderIntentHelper' ) ) {
			$items = $cart->get_items();
			$count = is_array( $items ) ? count( $items ) : $count;
			$state['count'] = $count;
			$state['can_checkout'] = $count > 0;

			if ( $count > 0 ) {
				$order_intent = OsOrderIntentHelper::create_or_update_order_intent( $cart );
				if ( $order_intent && ! empty( $order_intent->intent_key ) ) {
					$state['order_intent_key'] = $order_intent->intent_key;
				}
			}
		}

		return $state;
	}

	/**
	 * Counts cart items without building every booking or bundle model for badge refreshes.
	 */
	private static function get_cart_item_count( $cart ): int {
		if ( ! $cart || empty( $cart->id ) || ! defined( 'LATEPOINT_TABLE_CART_ITEMS' ) ) {
			return 0;
		}

		global $wpdb;

		if ( ! $wpdb ) {
			return 0;
		}

		return max( 0, (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . LATEPOINT_TABLE_CART_ITEMS . ' WHERE cart_id = %d',
				(int) $cart->id
			)
		) );
	}

	/**
	 * Returns the LatePoint step code where the cart checkout lightbox should open.
	 */
	private static function get_checkout_step_code(): string {
		$step_code = 'verify';

		return (string) apply_filters( 'isu_latepoint_header_cart_checkout_step', $step_code );
	}

	/**
	 * Prints scoped CSS and JavaScript that inserts and controls the header cart button.
	 */
	public static function render_footer_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$config = [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::AJAX_ACTION,
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
		];
		?>
		<style>
			.isu-lp-cart-button {
				position: relative;
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 32px;
				height: 32px;
				margin-left: 10px;
				margin-top: 5px;
				border: 0;
				border-radius: 50%;
				background: #fff;
				color: #161229;
				cursor: pointer;
				transition: transform .2s ease, background-color .2s ease, color .2s ease;
			}
			.isu-lp-cart-button:hover,
			.isu-lp-cart-button:focus {
				background: #f72585;
				color: #fff;
				transform: translateY(-1px);
				outline: none;
			}
			.isu-lp-cart-button[hidden] {
				display: none !important;
			}
			.isu-lp-cart-button.is-loading {
				pointer-events: none;
				opacity: .7;
			}
			.isu-lp-cart-button svg {
				width: 17px;
				height: 17px;
			}
			.isu-lp-cart-spinner {
				position: absolute;
				width: 18px;
				height: 18px;
				background: conic-gradient(from 0deg, transparent 0 25%, currentColor 35% 100%);
				border-radius: 50%;
				opacity: 0;
				transform: scale(.8);
				animation: isu-lp-cart-spin .7s linear infinite;
			}
			.isu-lp-cart-spinner::after {
				content: "";
				position: absolute;
				inset: 3px;
				border-radius: 50%;
				background: inherit;
			}
			.isu-lp-cart-button .isu-lp-cart-spinner::after {
				background: #fff;
			}
			.isu-lp-cart-button:hover .isu-lp-cart-spinner::after,
			.isu-lp-cart-button:focus .isu-lp-cart-spinner::after {
				background: #f72585;
			}
			.isu-lp-cart-button.is-loading svg,
			.isu-lp-cart-button.is-loading .isu-lp-cart-count {
				opacity: 0;
			}
			.isu-lp-cart-button.is-loading .isu-lp-cart-spinner {
				opacity: 1;
				transform: scale(1);
			}
			@keyframes isu-lp-cart-spin {
				from {
					transform: rotate(0deg);
				}
				to {
					transform: rotate(360deg);
				}
			}
			.isu-lp-cart-count {
				position: absolute;
				top: -5px;
				right: -5px;
				min-width: 16px;
				height: 16px;
				padding: 0 4px;
				border-radius: 999px;
				background: #f72585;
				color: #fff;
				font-size: 10px;
				font-weight: 700;
				line-height: 16px;
				text-align: center;
			}
			.isu-lp-cart-button:hover .isu-lp-cart-count,
			.isu-lp-cart-button:focus .isu-lp-cart-count {
				background: #161229;
			}
			@media (max-width: 792px) {
				.isu-lp-cart-button {
					margin-right: 5px;
				}
			}
		</style>
		<script>
			window.isuLatepointCartMenu = <?php echo wp_json_encode( $config ); ?>;
			(function($) {
				'use strict';

				var $button;
				var refreshTimer;
				var cachedState = {};
				var stateRequest = null;
				var checkoutOpening = false;

				// Creates the header button once and inserts it after Contact Us.
				function ensureButton() {
					if ($button && $button.length) return $button;

					var $contactButton = $('.header__buttons__group .button_contact').first();
					if (!$contactButton.length) return $();

					$button = $(
						'<button type="button" class="isu-lp-cart-button" hidden aria-label="Open checkout">' +
							'<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
								'<circle cx="9" cy="21" r="1"></circle>' +
								'<circle cx="20" cy="21" r="1"></circle>' +
								'<path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"></path>' +
							'</svg>' +
							'<span class="isu-lp-cart-spinner" aria-hidden="true"></span>' +
							'<span class="isu-lp-cart-count">0</span>' +
						'</button>'
					);

					$contactButton.after($button);
					$button.on('click', openCheckout);

					return $button;
				}

				// Applies the AJAX cart state to badge text and button visibility.
				function applyState(state) {
					var count = parseInt(state && state.count ? state.count : 0, 10);
					var $cartButton = ensureButton();

					if (!$cartButton.length) return;

					cachedState = state || {};
					$cartButton.find('.isu-lp-cart-count').text(count > 99 ? '99+' : count);
					$cartButton.prop('hidden', count < 1);
				}

				// Requests the current cart state, optionally creating an order intent.
				function requestState(createIntent) {
					var cfg = window.isuLatepointCartMenu || {};
					var request;

					if (!createIntent && stateRequest) {
						return stateRequest;
					}

					request = $.ajax({
						type: 'post',
						dataType: 'json',
						url: cfg.ajaxUrl,
						data: {
							action: cfg.action,
							nonce: cfg.nonce,
							create_intent: createIntent ? '1' : '0'
						}
					}).then(function(response) {
						if (response && response.success) {
							applyState(response.data);
							return response.data;
						}

						return {};
					}).always(function() {
						if (!createIntent) {
							stateRequest = null;
						}
					});

					if (!createIntent) {
						stateRequest = request;
					}

					return request;
				}

				// Debounces cart badge refreshes after LatePoint AJAX activity.
				function refreshState() {
					if (checkoutOpening) return;

					window.clearTimeout(refreshTimer);
					refreshTimer = window.setTimeout(function() {
						if (checkoutOpening) return;
						requestState(false);
					}, 250);
				}

				// Marks checkout forms opened from the header cart so their Back behavior can stay cart-safe.
				function markHeaderCartCheckout($bookingFormElement) {
					if (!$bookingFormElement || !$bookingFormElement.length) return;

					$bookingFormElement.addClass('isu-lp-header-cart-checkout');
					$bookingFormElement.attr('data-isu-lp-header-cart-checkout', '1');
				}

				// Restarts header-cart checkout when Back is pressed on Customer Information.
				function interceptHeaderCartCustomerBack(event) {
					var trigger = event.target && event.target.closest ? event.target.closest('.latepoint-prev-btn') : null;
					var bookingFormElement;
					var currentStepInput;

					if (!trigger) return;

					bookingFormElement = trigger.closest('.latepoint-booking-form-element.isu-lp-header-cart-checkout');
					if (!bookingFormElement) return;

					currentStepInput = bookingFormElement.querySelector('input[name="current_step_code"]');
					if (!currentStepInput || currentStepInput.value !== 'customer') return;

					event.preventDefault();
					event.stopPropagation();
					if (event.stopImmediatePropagation) {
						event.stopImmediatePropagation();
					}

					if (typeof latepoint_restart_booking_process === 'function') {
						latepoint_restart_booking_process($(bookingFormElement));
					}
				}

				// Opens LatePoint checkout in a lightbox from the active cart order intent.
				function openCheckout(event) {
					event.preventDefault();

					var $cartButton = ensureButton();
					if (checkoutOpening) return;

					checkoutOpening = true;
					window.clearTimeout(refreshTimer);
					$cartButton.addClass('is-loading');

					requestState(true).then(function(state) {
						if (!state || !state.order_intent_key || !state.checkout_route || typeof latepoint_helper === 'undefined') {
							$cartButton.removeClass('is-loading');
							checkoutOpening = false;
							return;
						}

						return $.ajax({
							type: 'post',
							dataType: 'json',
							url: latepoint_helper.ajaxurl,
							data: {
								action: latepoint_helper.route_action,
								route_name: state.checkout_route,
								params: {
									order_intent_key: state.order_intent_key
								},
								layout: 'none',
								return_format: 'json'
							}
						}).done(function(response) {
							var $bookingFormElement;

							if (!response || response.status !== 'success') return;

							latepoint_show_data_in_lightbox(response.message, 'booking-form-in-lightbox', false);
							$bookingFormElement = $('.latepoint-lightbox-w .latepoint-booking-form-element');
							markHeaderCartCheckout($bookingFormElement);
							$('body').addClass('latepoint-lightbox-active');
							latepoint_init_booking_form($bookingFormElement);
							latepoint_init_step(response.step, $bookingFormElement);

							if (state.checkout_step && state.checkout_step !== response.step && typeof latepoint_reload_step === 'function') {
								latepoint_reload_step($bookingFormElement, state.checkout_step);
							}
						}).always(function() {
							$cartButton.removeClass('is-loading');
							checkoutOpening = false;
						});
					}, function() {
						$cartButton.removeClass('is-loading');
						checkoutOpening = false;
					});
				}

				$(function() {
					ensureButton();
					requestState(false);

					$('body').on('latepoint:initBookingForm latepoint:initStep latepoint:prevStepReInit', refreshState);
					document.addEventListener('click', interceptHeaderCartCustomerBack, true);
					document.addEventListener('keydown', function(event) {
						if (event.key !== 'Enter' && event.key !== ' ') return;
						interceptHeaderCartCustomerBack(event);
					}, true);

					$(document).ajaxComplete(function(event, xhr, settings) {
						if (settings && settings.url && settings.url.indexOf('admin-ajax.php') !== -1) {
							refreshState();
						}
					});
				});
			})(jQuery);
		</script>
		<?php
	}
}

ISU_LatePoint_Header_Cart::init();
