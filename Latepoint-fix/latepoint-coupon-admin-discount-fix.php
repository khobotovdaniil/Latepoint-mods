<?php
/**
 * Plugin Name: LatePoint Coupon Admin Discount Fix
 * Description: Keeps coupon discount rows negative in the LatePoint admin price breakdown.
 *
 * LatePoint can display coupon credit rows as positive values or let input masks
 * overwrite the minus sign while an order is edited. This plugin normalizes
 * coupon credit rows on the PHP price-breakdown filters and restores negative
 * values in LatePoint forms after AJAX/input-mask initialization.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Normalizes amounts to LatePoint database money format.
 */
function lp_coupon_admin_discount_fix_money($amount): string {
	if (class_exists('OsMoneyHelper')) {
		return OsMoneyHelper::pad_to_db_format((float) $amount);
	}

	return number_format((float) $amount, 4, '.', '');
}

/**
 * Formats an amount for display in LatePoint price breakdown rows.
 */
function lp_coupon_admin_discount_fix_format($amount): string {
	if (class_exists('OsMoneyHelper')) {
		return OsMoneyHelper::format_price((float) $amount, true, false);
	}

	return '$' . number_format((float) $amount, 2);
}

/**
 * Extracts a positive discount amount from an existing breakdown row.
 */
function lp_coupon_admin_discount_fix_amount_from_row(array $row): float {
	if (isset($row['raw_value']) && is_numeric($row['raw_value'])) {
		return abs((float) $row['raw_value']);
	}

	if (!empty($row['value'])) {
		$value = preg_replace('/[^\d.,-]+/', '', (string) $row['value']);
		$value = str_replace(',', '.', $value);

		return abs((float) $value);
	}

	return 0;
}

/**
 * Ensures credit rows are stored/rendered as negative values before output.
 */
function lp_coupon_admin_discount_fix_normalize_rows(array $rows): array {
	if (empty($rows['after_subtotal']) || !is_array($rows['after_subtotal'])) {
		return $rows;
	}

	foreach ($rows['after_subtotal'] as $group_key => $group) {
		if (empty($group['items']) || !is_array($group['items'])) {
			continue;
		}

		foreach ($group['items'] as $item_key => $item) {
			if (!is_array($item) || ($item['type'] ?? '') !== 'credit') {
				continue;
			}

			$amount = lp_coupon_admin_discount_fix_amount_from_row($item);
			if ($amount <= 0) {
				continue;
			}

			$rows['after_subtotal'][$group_key]['items'][$item_key]['raw_value'] = '-' . lp_coupon_admin_discount_fix_money($amount);
			$rows['after_subtotal'][$group_key]['items'][$item_key]['value'] = '-' . lp_coupon_admin_discount_fix_format($amount);
		}
	}

	return $rows;
}

add_filter('latepoint_cart_price_breakdown_rows', function(array $rows): array {
	return lp_coupon_admin_discount_fix_normalize_rows($rows);
}, 100);

add_filter('latepoint_order_price_breakdown_rows', function(array $rows): array {
	return lp_coupon_admin_discount_fix_normalize_rows($rows);
}, 100);

/**
 * Prints a small admin-side guard that prevents Inputmask from replacing discounts with zero.
 */
function lp_coupon_admin_discount_fix_print_script(): void {
	?>
	<script id="lp-coupon-admin-discount-fix">
	(function(){
		if (window.lpCouponAdminDiscountFixLoaded) return;
		window.lpCouponAdminDiscountFixLoaded = true;

		// Match the visible amount input to its hidden sibling that stores row type.
		function findCreditTypeInput(valueInput) {
			var name = valueInput.getAttribute('name') || '';
			var typeName = name.replace(/\[value\]$/, '[type]');

			if (!typeName || typeName === name) return null;

			return Array.prototype.find.call(document.querySelectorAll('input[name*="price_breakdown"][name$="[type]"]'), function(input){
				return input.getAttribute('name') === typeName;
			}) || null;
		}

		// Prefer the original HTML value, then current value, then the hidden coupon discount field.
		function getCouponDiscountValue(input) {
			var htmlValue = String(input.getAttribute('value') || '');
			var currentValue = String(input.value || '');
			var hiddenCouponDiscount = document.querySelector('input[name="order[coupon_discount]"]');
			var hiddenValue = hiddenCouponDiscount ? String(hiddenCouponDiscount.value || hiddenCouponDiscount.getAttribute('value') || '') : '';

			if (htmlValue.indexOf('-') !== -1) return htmlValue;
			if (currentValue.indexOf('-') !== -1) return currentValue;
			if (hiddenValue.indexOf('-') !== -1) return hiddenValue;

			return '';
		}

		// For coupon credit rows, remove the money mask and restore the negative value.
		function restoreCouponDiscountInputs(root) {
			var scope = root && root.querySelectorAll ? root : document;
			var inputs = scope.querySelectorAll('.price-breakdown-wrapper input[name*="price_breakdown[after_subtotal]"][name$="[value]"], .green-value-input input[name*="price_breakdown[after_subtotal]"][name$="[value]"]');

			inputs.forEach(function(input){
				var typeInput = findCreditTypeInput(input);
				if (!typeInput || typeInput.value !== 'credit') return;

				var value = getCouponDiscountValue(input);
				if (!value) return;

				if (window.jQuery && window.jQuery.fn && window.jQuery.fn.inputmask) {
					try {
						window.jQuery(input).inputmask('remove');
					} catch (e) {}
				}

				input.classList.remove('os-mask-money');
				input.value = value;
				input.setAttribute('value', value);
				input.setAttribute('data-lp-coupon-discount-fixed', 'yes');
			});
		}

		// LatePoint often initializes masks after AJAX, so retry shortly after each trigger.
		function scheduleRestore(root) {
			restoreCouponDiscountInputs(root);
			window.setTimeout(function(){ restoreCouponDiscountInputs(root); }, 50);
			window.setTimeout(function(){ restoreCouponDiscountInputs(root); }, 250);
			window.setTimeout(function(){ restoreCouponDiscountInputs(root); }, 1000);
		}

		document.addEventListener('DOMContentLoaded', function(){
			scheduleRestore(document);
		});

		document.addEventListener('latepoint:initInputMasks', function(event){
			scheduleRestore(event.target || document);
		}, true);

		if (window.jQuery) {
			window.jQuery(document).ajaxComplete(function(){
				scheduleRestore(document);
			});
		}

		if (window.MutationObserver) {
			new MutationObserver(function(mutations){
				var shouldRestore = mutations.some(function(mutation){
					return Array.prototype.some.call(mutation.addedNodes || [], function(node){
						return node.nodeType === 1 && (
							(node.matches && node.matches('.price-breakdown-wrapper, .green-value-input')) ||
							(node.querySelector && node.querySelector('.price-breakdown-wrapper, .green-value-input'))
						);
					});
				});

				if (shouldRestore) {
					scheduleRestore(document);
				}
			}).observe(document.documentElement, {
				childList: true,
				subtree: true
			});
		}
	})();
	</script>
	<?php
}

add_action('admin_footer', 'lp_coupon_admin_discount_fix_print_script', 1000);
add_action('wp_footer', 'lp_coupon_admin_discount_fix_print_script', 1000);
