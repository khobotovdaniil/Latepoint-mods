<?php
/**
 * Plugin Name: LatePoint Empty Cart Checkout Guard
 * Description: Prevents customers from continuing to verify, tips, payment, or confirmation after all cart items are removed from the booking form.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Checks whether a step belongs to the checkout tail that requires at least one cart item.
 */
function isu_lp_empty_cart_guard_is_checkout_step($step_code): bool {
	$step_code = (string) $step_code;
	$step_parent = explode('__', $step_code)[0] ?? $step_code;

	return in_array($step_parent, ['verify', 'tips', 'payment', 'confirmation'], true);
}

/**
 * Reads a nested value from an array or object without depending on one exact request shape.
 */
function isu_lp_empty_cart_guard_read_nested_value($source, array $path) {
	foreach ($path as $key) {
		if (is_array($source) && array_key_exists($key, $source)) {
			$source = $source[$key];
			continue;
		}

		if (is_object($source) && isset($source->{$key})) {
			$source = $source->{$key};
			continue;
		}

		return null;
	}

	return $source;
}

/**
 * Parses LatePoint's encoded AJAX params payload when the form fields are sent as one query string.
 */
function isu_lp_empty_cart_guard_encoded_params(): array {
	static $decoded_params = null;

	if ($decoded_params !== null) {
		return $decoded_params;
	}

	$decoded_params = [];

	if (empty($_REQUEST['params'])) {
		return $decoded_params;
	}

	$params = wp_unslash($_REQUEST['params']);
	if (!is_string($params)) {
		return $decoded_params;
	}

	if (
		strpos($params, 'order_item_id') === false
		&& strpos($params, 'order_item_id%5D') === false
		&& strpos($params, 'order_item_id%255D') === false
	) {
		return $decoded_params;
	}

	parse_str(html_entity_decode($params, ENT_QUOTES, 'UTF-8'), $decoded_params);

	return is_array($decoded_params) ? $decoded_params : [];
}

/**
 * Detects bundle scheduling for an already-created order item, where the cart can be empty until final submit.
 */
function isu_lp_empty_cart_guard_is_existing_order_item_scheduling(): bool {
	$sources = [$_REQUEST, isu_lp_empty_cart_guard_encoded_params()];

	if (class_exists('OsParamsHelper')) {
		$sources[] = ['presets' => OsParamsHelper::get_param('presets', [])];
	}

	if (class_exists('OsStepsHelper') && !empty(OsStepsHelper::$presets) && is_array(OsStepsHelper::$presets)) {
		$sources[] = ['presets' => OsStepsHelper::$presets];
	}

	foreach ($sources as $source) {
		if (!is_array($source)) {
			continue;
		}

		$order_item_id = absint(isu_lp_empty_cart_guard_read_nested_value($source, ['presets', 'order_item_id']));
		if ($order_item_id > 0) {
			return true;
		}
	}

	return false;
}

/**
 * Returns true after LatePoint has already converted the cart into a real order.
 */
function isu_lp_empty_cart_guard_order_exists(): bool {
	if (!class_exists('OsStepsHelper')) {
		return false;
	}

	if (!empty(OsStepsHelper::$order_object)) {
		$order = OsStepsHelper::$order_object;

		if (!method_exists($order, 'is_new_record') || !$order->is_new_record()) {
			return true;
		}
	}

	if (!empty(OsStepsHelper::$cart_object) && !empty(OsStepsHelper::$cart_object->order_id)) {
		return true;
	}

	return false;
}

/**
 * Returns true when LatePoint currently has no cart items to verify or pay for.
 */
function isu_lp_empty_cart_guard_cart_is_empty(): bool {
	if (!class_exists('OsStepsHelper') || empty(OsStepsHelper::$cart_object)) {
		return false;
	}

	if (isu_lp_empty_cart_guard_order_exists()) {
		return false;
	}

	if (isu_lp_empty_cart_guard_is_existing_order_item_scheduling()) {
		return false;
	}

	$cart = OsStepsHelper::$cart_object;

	if (method_exists($cart, 'is_empty')) {
		return $cart->is_empty();
	}

	return method_exists($cart, 'get_items') && count($cart->get_items()) === 0;
}

/**
 * Finds the earliest active booking step so LatePoint can recover to a valid selection state.
 */
function isu_lp_empty_cart_guard_first_booking_step(array $active_step_codes, array $all_step_codes): string {
	$ordered_steps = $active_step_codes ?: $all_step_codes;

	foreach ($ordered_steps as $step_code) {
		if (strpos((string) $step_code, 'booking__') === 0) {
			return (string) $step_code;
		}
	}

	return !empty($ordered_steps[0]) ? (string) $ordered_steps[0] : 'booking__services';
}

/**
 * Sends an empty-cart checkout attempt back to booking selection instead of allowing a zero-value order flow.
 */
add_filter('latepoint_get_next_step_code', function($next_step_code, $current_step_code, array $all_step_codes, array $active_step_codes) {
	if (!isu_lp_empty_cart_guard_is_checkout_step($next_step_code)) {
		return $next_step_code;
	}

	if (!isu_lp_empty_cart_guard_cart_is_empty()) {
		return $next_step_code;
	}

	return isu_lp_empty_cart_guard_first_booking_step($active_step_codes, $all_step_codes);
}, 5, 4);

/**
 * Helps LatePoint recognize last-item removals when the visible step has no active cart item id.
 */
function isu_lp_empty_cart_guard_print_last_item_removal_script(): void {
	?>
	<script id="isu-lp-empty-cart-last-item-removal">
	(function(){
		if (window.isuLpEmptyCartLastItemRemovalLoaded) return;
		window.isuLpEmptyCartLastItemRemovalLoaded = true;

		document.addEventListener('click', function(event){
			var trigger = event.target && event.target.closest ? event.target.closest('.os-remove-item-from-cart') : null;
			var bookingForm;
			var removeButtons;
			var activeCartItemInput;
			var cartItemId;

			if (!trigger) return;

			bookingForm = trigger.closest('.latepoint-booking-form-element');
			if (!bookingForm) return;

			removeButtons = bookingForm.querySelectorAll('.os-remove-item-from-cart');
			if (removeButtons.length !== 1) return;

			cartItemId = trigger.getAttribute('data-cart-item-id') || '';
			if (!cartItemId) return;

			activeCartItemInput = bookingForm.querySelector('input[name="active_cart_item[id]"]');
			if (activeCartItemInput) {
				activeCartItemInput.value = cartItemId;
			}
		}, true);
	})();
	</script>
	<?php
}

add_action('wp_footer', 'isu_lp_empty_cart_guard_print_last_item_removal_script', 1004);
add_action('admin_footer', 'isu_lp_empty_cart_guard_print_last_item_removal_script', 1004);
