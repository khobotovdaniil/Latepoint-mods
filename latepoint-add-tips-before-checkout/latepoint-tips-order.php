<?php
/**
 * Plugin Name: LatePoint Order Tips
 * Description: Adds an optional order-level tip step before LatePoint checkout.
 */

if (!defined('ABSPATH')) {
	exit;
}

function lp_order_tips_step_code(): string {
	return 'tips';
}

function lp_order_tips_label(): string {
	return 'Support your coach with a tip (optional) 💜';
}

function lp_order_tips_allowed_percents(): array {
	return [0, 5, 10, 15, 20, 25];
}

function lp_order_tips_default_percent(): int {
	return 5;
}

function lp_order_tips_meta_percent_key(): string {
	return 'order_tip_percent';
}

function lp_order_tips_meta_amount_key(): string {
	return 'order_tip_amount';
}

function lp_order_tips_amount_param_key(): string {
	return 'lp_order_tip_amount';
}

/**
 * Returns the current LatePoint step from AJAX params, supporting both raw and encoded requests.
 */
function lp_order_tips_get_request_step_code(): string {
	static $step_code = null;

	if ($step_code !== null) {
		return $step_code;
	}

	$step_code = '';

	if (class_exists('OsParamsHelper')) {
		$step_code = (string) OsParamsHelper::get_param('current_step_code', '');
	}

	if ($step_code === '' && !empty($_REQUEST['current_step_code'])) {
		$step_code = (string) wp_unslash($_REQUEST['current_step_code']);
	}

	if ($step_code === '') {
		$encoded_params = lp_order_tips_get_encoded_request_params();
		$step_code = (string) lp_order_tips_read_nested_value($encoded_params, ['current_step_code']);
	}

	return $step_code;
}

/**
 * Checks whether the current request is far enough in checkout for tips to affect totals or breakdowns.
 */
function lp_order_tips_request_is_checkout_tail(): bool {
	if (!empty($_REQUEST['order_intent_key'])) {
		return true;
	}

	$encoded_params = lp_order_tips_get_encoded_request_params();
	if (!empty($encoded_params['order_intent_key'])) {
		return true;
	}

	$step_code = lp_order_tips_get_request_step_code();

	if ($step_code === '') {
		return false;
	}

	$step_parent = explode('__', $step_code)[0] ?? $step_code;

	return in_array($step_parent, ['verify', 'tips', 'payment', 'confirmation'], true);
}

/**
 * Reads a nested value from an array or object without assuming one exact LatePoint request shape.
 */
function lp_order_tips_read_nested_value($source, array $path) {
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
 * Normalizes an order item id value from form params or cart item data.
 */
function lp_order_tips_normalize_order_item_id($value): int {
	if (is_array($value) || is_object($value)) {
		return 0;
	}

	return max(0, absint($value));
}

/**
 * Parses LatePoint's encoded params payload when AJAX sends fields as one query string.
 */
function lp_order_tips_get_encoded_request_params(): array {
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
 * Returns the existing order item id used by bundle scheduling flows.
 */
function lp_order_tips_get_request_order_item_id(): int {
	static $request_order_item_id = null;

	if ($request_order_item_id !== null) {
		return $request_order_item_id;
	}

	$request_order_item_id = 0;
	$sources = [$_REQUEST, lp_order_tips_get_encoded_request_params()];

	if (class_exists('OsParamsHelper')) {
		$sources[] = [
			'presets'          => OsParamsHelper::get_param('presets', []),
			'active_cart_item' => OsParamsHelper::get_param('active_cart_item', []),
			'booking'          => OsParamsHelper::get_param('booking', []),
		];
	}

	if (class_exists('OsStepsHelper') && !empty(OsStepsHelper::$presets) && is_array(OsStepsHelper::$presets)) {
		$sources[] = ['presets' => OsStepsHelper::$presets];
	}

	foreach ($sources as $source) {
		if (!is_array($source)) {
			continue;
		}

		$order_item_id = lp_order_tips_normalize_order_item_id(
			lp_order_tips_read_nested_value($source, ['presets', 'order_item_id'])
		);

		if ($order_item_id > 0) {
			$request_order_item_id = $order_item_id;
			return $request_order_item_id;
		}

		$order_item_id = lp_order_tips_normalize_order_item_id(
			lp_order_tips_read_nested_value($source, ['booking', 'order_item_id'])
		);

		if ($order_item_id > 0) {
			$request_order_item_id = $order_item_id;
			return $request_order_item_id;
		}

		$item_data = lp_order_tips_read_nested_value($source, ['active_cart_item', 'item_data']);
		if (is_string($item_data) && $item_data !== '') {
			$decoded_item_data = json_decode(html_entity_decode(wp_unslash($item_data), ENT_QUOTES, 'UTF-8'), true);
			$item_data = is_array($decoded_item_data) ? $decoded_item_data : [];
		}

		$order_item_id = lp_order_tips_normalize_order_item_id(
			lp_order_tips_read_nested_value($item_data, ['order_item_id'])
		);

		if ($order_item_id > 0) {
			$request_order_item_id = $order_item_id;
			return $request_order_item_id;
		}
	}

	return $request_order_item_id;
}

/**
 * Checks a cart item for an existing order item reference used when scheduling lessons from a bundle.
 */
function lp_order_tips_cart_item_has_existing_order_item($item): bool {
	$order_item_id = lp_order_tips_normalize_order_item_id(lp_order_tips_read_nested_value($item, ['order_item_id']));
	if ($order_item_id > 0) {
		return true;
	}

	$item_data = lp_order_tips_read_nested_value($item, ['item_data']);
	if (is_string($item_data) && $item_data !== '') {
		$decoded_item_data = json_decode($item_data, true);
		$item_data = is_array($decoded_item_data) ? $decoded_item_data : [];
	}

	return lp_order_tips_normalize_order_item_id(lp_order_tips_read_nested_value($item_data, ['order_item_id'])) > 0;
}

/**
 * Detects bundle scheduling and other already-created-order flows where a new tip must not be collected.
 */
function lp_order_tips_is_existing_order_scheduling($cart = null): bool {
	static $cache = [];

	if (lp_order_tips_get_request_order_item_id() > 0) {
		return true;
	}

	if (!$cart || !method_exists($cart, 'get_items')) {
		return false;
	}

	$cache_key = !empty($cart->uuid)
		? 'uuid:' . (string) $cart->uuid
		: 'id:' . (string) ($cart->id ?? spl_object_id($cart));

	if (array_key_exists($cache_key, $cache)) {
		return $cache[$cache_key];
	}

	$cache[$cache_key] = false;

	foreach ($cart->get_items() as $item) {
		if (lp_order_tips_cart_item_has_existing_order_item($item)) {
			$cache[$cache_key] = true;
			return $cache[$cache_key];
		}
	}

	return $cache[$cache_key];
}

/**
 * Detects Tip rows previously added by this plugin, even if LatePoint saved them under another group key.
 */
function lp_order_tips_breakdown_item_is_tip($item): bool {
	if (!is_array($item)) {
		return false;
	}

	$label = strtolower(trim(wp_strip_all_tags((string) ($item['label'] ?? ''))));
	$type = strtolower((string) ($item['type'] ?? ''));

	return $label === strtolower(__('Tip', 'latepoint')) && ($type === '' || $type === 'charge');
}

/**
 * Removes all existing Tip rows from after_subtotal so the displayed order breakdown stays idempotent.
 */
function lp_order_tips_remove_tip_rows_from_breakdown(array $rows): array {
	if (empty($rows['after_subtotal']) || !is_array($rows['after_subtotal'])) {
		return $rows;
	}

	foreach ($rows['after_subtotal'] as $group_key => $group) {
		if ($group_key === 'tips') {
			unset($rows['after_subtotal'][$group_key]);
			continue;
		}

		if (empty($group['items']) || !is_array($group['items'])) {
			continue;
		}

		$group['items'] = array_values(array_filter($group['items'], function($item): bool {
			return !lp_order_tips_breakdown_item_is_tip($item);
		}));

		if (empty($group['items'])) {
			unset($rows['after_subtotal'][$group_key]);
			continue;
		}

		$rows['after_subtotal'][$group_key] = $group;
	}

	return $rows;
}

/**
 * Some LatePoint installs have order intents but no order_intent_meta table.
 * Avoid calling intent meta methods there, because WordPress logs DB errors for every step.
 */
function lp_order_tips_order_intent_meta_table_exists(): bool {
	static $table_exists = null;

	if ($table_exists !== null) {
		return $table_exists;
	}

	global $wpdb;

	if (!$wpdb || empty($wpdb->prefix)) {
		$table_exists = false;
		return $table_exists;
	}

	$table_name = $wpdb->prefix . 'latepoint_order_intent_meta';
	$found_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
	$table_exists = ($found_table === $table_name);

	return $table_exists;
}

function lp_order_tips_add_tip_row_to_breakdown(array $rows, float $percent, float $tip_amount): array {
	$rows = lp_order_tips_remove_tip_rows_from_breakdown($rows);

	if ($tip_amount <= 0) {
		return $rows;
	}

	if (!isset($rows['after_subtotal']) || !is_array($rows['after_subtotal'])) {
		$rows['after_subtotal'] = [];
	}

	$item = [
		'label'     => __('Tip', 'latepoint'),
		'raw_value' => OsMoneyHelper::pad_to_db_format($tip_amount),
		'value'     => OsMoneyHelper::format_price($tip_amount, true, false),
		'type'      => 'charge',
	];

	if ($percent > 0) {
		$item['badge'] = ((int) $percent) . '%';
	}

	$rows['after_subtotal']['tips']['items'][] = $item;

	return $rows;
}

function lp_order_tips_transient_key($cart): string {
	return 'lp_order_tip_' . (!empty($cart->uuid) ? $cart->uuid : 'unknown');
}

function lp_order_tips_amount_transient_key($cart): string {
	return 'lp_order_tip_amount_' . (!empty($cart->uuid) ? $cart->uuid : 'unknown');
}

function lp_order_tips_get_current_cart() {
	if (class_exists('OsStepsHelper') && !empty(OsStepsHelper::$cart_object)) {
		return OsStepsHelper::$cart_object;
	}

	return class_exists('OsCartsHelper') ? OsCartsHelper::get_or_create_cart() : false;
}

function lp_order_tips_get_percent($cart): float {
	if (!$cart) return 0;

	$percent = (int) get_transient(lp_order_tips_transient_key($cart));

	return in_array($percent, lp_order_tips_allowed_percents(), true) ? $percent : 0;
}

function lp_order_tips_set_percent($cart, $percent): void {
	if (!$cart) return;

	$percent = (int) str_replace('%', '', (string) $percent);

	if (!in_array($percent, lp_order_tips_allowed_percents(), true)) {
		$percent = 0;
	}

	set_transient(lp_order_tips_transient_key($cart), $percent, DAY_IN_SECONDS);
}

function lp_order_tips_sanitize_custom_amount($amount): float {
	$amount = preg_replace('/[^0-9.,]/', '', (string) $amount);
	$amount = str_replace(',', '.', $amount);

	return round(max(0, (float) $amount), 4);
}

function lp_order_tips_get_custom_amount($cart): float {
	if (!$cart) return 0;

	return lp_order_tips_sanitize_custom_amount(get_transient(lp_order_tips_amount_transient_key($cart)));
}

function lp_order_tips_set_custom_amount($cart, $amount): void {
	if (!$cart) return;

	$amount = lp_order_tips_sanitize_custom_amount($amount);

	if ($amount > 0) {
		set_transient(lp_order_tips_amount_transient_key($cart), $amount, DAY_IN_SECONDS);
		return;
	}

	delete_transient(lp_order_tips_amount_transient_key($cart));
}

function lp_order_tips_save_selection_from_request($cart, $default_percent = null): void {
	if (!$cart) return;

	$percent = OsParamsHelper::get_param('lp_order_tip_percent', $default_percent ?? lp_order_tips_default_percent());
	$custom_amount = OsParamsHelper::get_param(lp_order_tips_amount_param_key(), null);

	lp_order_tips_set_custom_amount($cart, $custom_amount);

	if (lp_order_tips_sanitize_custom_amount($custom_amount) > 0) {
		lp_order_tips_set_percent($cart, 0);
		return;
	}

	if ($percent === 'custom') {
		$percent = 0;
	}

	lp_order_tips_set_percent($cart, $percent);
}

/**
 * Calculates the amount tips should be based on.
 * Coupons are excluded from the tip base; taxes are not included.
 */
function lp_order_tips_get_base_amount($cart): float {
	$subtotal = (float) $cart->subtotal;
	$coupon_discount = property_exists($cart, 'coupon_discount') ? (float) $cart->coupon_discount : 0;

	return max(0, $subtotal - $coupon_discount);
}


function lp_order_tips_get_amount($cart): float {
	if (!$cart || lp_order_tips_cart_is_empty($cart)) {
		return 0;
	}

	$custom_amount = lp_order_tips_get_custom_amount($cart);
	if ($custom_amount > 0) {
		return $custom_amount;
	}

	$percent = lp_order_tips_get_percent($cart);

	if ($percent <= 0) {
		return 0;
	}

	$base_amount = lp_order_tips_get_base_amount($cart);

	if ($base_amount <= 0) {
		return 0;
	}

	return round($base_amount * ($percent / 100), 4);
}



/**
 * Register step after Verify Order Details.
 */
add_filter('latepoint_get_step_codes_with_rules', function(array $steps): array {
	if (lp_order_tips_get_request_order_item_id() > 0) {
		unset($steps[lp_order_tips_step_code()]);
		return $steps;
	}

	$steps[lp_order_tips_step_code()] = [
		'after'  => 'verify',
		'before' => 'payment',
	];

	return $steps;
}, 20);

add_filter('latepoint_next_btn_labels_for_steps', function(array $labels): array {
	$labels['verify'] = __('Next', 'latepoint');
	$labels[lp_order_tips_step_code()] = __('Checkout', 'latepoint');

	return $labels;
}, 20);

add_filter('latepoint_step_labels_by_step_codes', function(array $labels): array {
	$labels[lp_order_tips_step_code()] = __('Tips', 'latepoint');

	return $labels;
}, 20);

add_filter('latepoint_settings_for_step_codes', function(array $settings): array {
	$settings[lp_order_tips_step_code()] = [
		'side_panel_heading'     => __('Support your coach', 'latepoint'),
		'side_panel_description' => __('Add an optional tip before checkout.', 'latepoint'),
		'main_panel_heading'     => __('Support your coach', 'latepoint'),
	];

	return $settings;
}, 20);

add_filter('latepoint_step_show_next_btn_rules', function(array $rules, string $step_code): array {
	$rules[lp_order_tips_step_code()] = true;

	return $rules;
}, 20, 2);

/**
 * Render custom Tips step.
 */
add_action('latepoint_load_step', function($step_code, $format = 'json'): void {
	if ($step_code !== lp_order_tips_step_code()) {
		return;
	}

	$cart = lp_order_tips_get_current_cart();

	if (lp_order_tips_is_existing_order_scheduling($cart)) {
		lp_order_tips_clear_percent($cart);
		return;
	}

	$saved_percent = $cart ? get_transient(lp_order_tips_transient_key($cart)) : false;
	$current_percent = ($saved_percent === false)
		? lp_order_tips_default_percent()
		: lp_order_tips_get_percent($cart);
	$current_custom_amount = lp_order_tips_get_custom_amount($cart);


	ob_start();
	?>
		<div class="step-order-tips-w latepoint-step-content"
			data-step-code="<?php echo esc_attr($step_code); ?>"
			data-next-btn-label="<?php echo esc_attr(OsStepsHelper::get_next_btn_label_for_step($step_code)); ?>">
			<style>
				.step-order-tips-w .lp-order-tip-custom-hidden{display:none!important}
				.step-order-tips-w .lp-order-tip-custom-w{display:flex;align-items:center;gap:16px;width:100%}
				.step-order-tips-w .lp-order-tip-custom-w label{flex:1 1 50%;margin:0}
				.step-order-tips-w .lp-order-tip-custom-w .os-form-control{
					flex:1 1 50%;
					width:100%;
					max-width:50%;
					box-sizing:border-box;
					padding:12px 10px 12px 10px!important;
					border-radius:var(--latepoint-border-radius)!important;
					background-color:#fff!important;
					color:#32373c;
					line-height:1.2;
				}
				@media (max-width: 600px){
					.step-order-tips-w .lp-order-tip-custom-w{display:block}
					.step-order-tips-w .lp-order-tip-custom-w label{display:block;margin-bottom:8px}
					.step-order-tips-w .lp-order-tip-custom-w .os-form-control{width:100%;max-width:100%}
				}
			</style>

			<div class="os-row">
				<div class="os-col-12">
					<div class="os-form-group os-form-select-group os-form-group-transparent">
						<label for="lp_order_tip_percent">
							<?php echo esc_html(lp_order_tips_label()); ?>
						</label><br>

						<select name="lp_order_tip_percent"
										id="lp_order_tip_percent"
										class="os-form-control"
										placeholder="">
							<?php foreach (lp_order_tips_allowed_percents() as $percent) : ?>
								<option value="<?php echo esc_attr($percent); ?>" <?php selected((int) $current_percent, $percent); ?>>
									<?php echo esc_html($percent . '%'); ?>
								</option>
							<?php endforeach; ?>
							<option value="custom" <?php selected($current_custom_amount > 0); ?>>
								<?php echo esc_html__('Custom amount', 'latepoint'); ?>
							</option>
						</select>
					</div>
					<div class="os-form-group os-form-group-transparent lp-order-tip-custom-w<?php echo $current_custom_amount > 0 ? '' : ' lp-order-tip-custom-hidden'; ?>">
						<label for="lp_order_tip_amount">
							<?php echo esc_html__('Custom tip amount', 'latepoint'); ?>
						</label>
						<input type="number"
							   min="0"
							   step="0.01"
							   inputmode="decimal"
							   name="<?php echo esc_attr(lp_order_tips_amount_param_key()); ?>"
							   id="lp_order_tip_amount"
							   class="os-form-control"
							   value="<?php echo esc_attr($current_custom_amount > 0 ? number_format($current_custom_amount, 2, '.', '') : ''); ?>"
							   placeholder="0.00">
					</div>
				</div>
			</div>
		</div>

	<?php
	$html = ob_get_clean();

	if ($format === 'json') {
		wp_send_json([
			'status'         => LATEPOINT_STATUS_SUCCESS,
			'message'        => $html,
			'step_code'      => $step_code,
			'show_next_btn'  => OsStepsHelper::can_step_show_next_btn($step_code),
			'show_prev_btn'  => OsStepsHelper::can_step_show_prev_btn($step_code),
			'is_first_step'  => OsStepsHelper::is_first_step($step_code),
			'is_last_step'   => OsStepsHelper::is_last_step($step_code),
			'is_pre_last_step' => OsStepsHelper::is_pre_last_step($step_code),
		]);
	}

	echo $html;
}, 20, 2);

/**
 * Save tip when leaving Tips step.
 */
add_action('latepoint_process_step', function($step_code): void {
	if ($step_code !== lp_order_tips_step_code()) {
		return;
	}

	$cart = lp_order_tips_get_current_cart();
	if (lp_order_tips_is_existing_order_scheduling($cart)) {
		lp_order_tips_clear_percent($cart);
		return;
	}

	lp_order_tips_save_selection_from_request($cart, lp_order_tips_default_percent());
	lp_order_tips_sync_cart_and_order_intent($cart);
}, 20);

/**
 * verify
 */
add_action('latepoint_load_step', function($step_code): void {
	if ($step_code !== 'verify') {
		return;
	}

	$cart = lp_order_tips_get_current_cart();

	if (!$cart || lp_order_tips_cart_is_empty($cart) || lp_order_tips_is_existing_order_scheduling($cart)) {
		lp_order_tips_clear_percent($cart);
		return;
	}

	$request_percent = OsParamsHelper::get_param('lp_order_tip_percent', null);
	$request_custom_amount = OsParamsHelper::get_param(lp_order_tips_amount_param_key(), null);

	if ($request_percent !== null || $request_custom_amount !== null) {
		lp_order_tips_save_selection_from_request($cart, $request_percent ?? lp_order_tips_default_percent());
	}

	lp_order_tips_sync_cart_and_order_intent($cart);
}, 5);

add_action('latepoint_cart_summary_before_price_breakdown', function($cart): void {
	if (!lp_order_tips_request_is_checkout_tail()) {
		return;
	}

	if (!$cart || lp_order_tips_cart_is_empty($cart) || lp_order_tips_is_existing_order_scheduling($cart) || (float) $cart->subtotal <= 0) {
		lp_order_tips_clear_percent($cart);
		return;
	}

	if (method_exists($cart, 'calculate_prices')) {
		$cart->calculate_prices();
	}
}, 5);

/**
 * clear cart
 */

function lp_order_tips_cart_is_empty($cart): bool {
	if (!$cart) {
		return true;
	}

	if (method_exists($cart, 'is_empty')) {
		return $cart->is_empty();
	}

	return method_exists($cart, 'get_items') && count($cart->get_items()) === 0;
}

function lp_order_tips_clear_percent($cart): void {
	if (!$cart) {
		return;
	}

	delete_transient(lp_order_tips_transient_key($cart));
	delete_transient(lp_order_tips_amount_transient_key($cart));
}

/**
 * Recalculates cart totals, updates order intent, and persists tip meta for order creation.
 */
function lp_order_tips_sync_cart_and_order_intent($cart): void {
	if (!$cart) {
		return;
	}

	if (lp_order_tips_is_existing_order_scheduling($cart)) {
		lp_order_tips_clear_percent($cart);
		return;
	}

	if (method_exists($cart, 'calculate_prices')) {
		$cart->calculate_prices();
	}

	if (
		class_exists('OsOrderIntentHelper')
		&& class_exists('OsStepsHelper')
	) {
		$order_intent = OsOrderIntentHelper::create_or_update_order_intent(
			$cart,
			OsStepsHelper::$restrictions ?? [],
			OsStepsHelper::$presets ?? []
		);

		if (
			$order_intent
			&& method_exists($order_intent, 'save_meta_by_key')
			&& lp_order_tips_order_intent_meta_table_exists()
		) {
			$order_intent->save_meta_by_key(lp_order_tips_meta_percent_key(), lp_order_tips_get_percent($cart));
			$order_intent->save_meta_by_key(lp_order_tips_meta_amount_key(), lp_order_tips_get_amount($cart));
		}
	}
}

/**
 * Copies tip data from order intent to the final order and saves it in price_breakdown.
 */
add_action('latepoint_order_intent_converted', function($order_intent, $order): void {
	if (!$order_intent || !$order) {
		return;
	}

	$can_read_order_intent_meta = lp_order_tips_order_intent_meta_table_exists()
		&& method_exists($order_intent, 'get_meta_by_key');

	$percent = $can_read_order_intent_meta
		? (float) $order_intent->get_meta_by_key(lp_order_tips_meta_percent_key(), 0)
		: 0;

	$tip_amount = $can_read_order_intent_meta
		? (float) $order_intent->get_meta_by_key(lp_order_tips_meta_amount_key(), 0)
		: 0;

	if ($tip_amount <= 0) {
		[$percent, $tip_amount] = lp_order_tips_get_order_tip_data($order);
	}

	if ($tip_amount <= 0) {
		return;
	}

	if (method_exists($order, 'save_meta_by_key')) {
		$order->save_meta_by_key(lp_order_tips_meta_percent_key(), $percent);
		$order->save_meta_by_key(lp_order_tips_meta_amount_key(), $tip_amount);
	}

	$rows = [];
	if (!empty($order->price_breakdown)) {
		$decoded_rows = json_decode($order->price_breakdown, true);
		if (is_array($decoded_rows)) {
			$rows = $decoded_rows;
		}
	}

	$rows = lp_order_tips_add_tip_row_to_breakdown($rows, $percent, $tip_amount);

	if (method_exists($order, 'update_attributes')) {
		$order->update_attributes([
			'price_breakdown' => wp_json_encode($rows),
		]);
	}
}, 20, 2);


/**
 * Ensures saved orders, customer cabinet, invoice, and receipt breakdowns include Tip.
 */
add_filter('latepoint_order_price_breakdown_rows', function(array $rows, $order, array $rows_to_hide, bool $force_recalculate = false): array {
	[$percent, $tip_amount] = lp_order_tips_get_order_tip_data($order);

	return lp_order_tips_add_tip_row_to_breakdown($rows, $percent, $tip_amount);
}, 20, 4);


/**
 * Add tip to cart total.
 */
add_action('latepoint_cart_calculate_prices', function($cart): void {
	static $is_calculating_tip = false;

	if ($is_calculating_tip) {
		return;
	}

	if (!lp_order_tips_request_is_checkout_tail()) {
		return;
	}

	if (!$cart || lp_order_tips_cart_is_empty($cart) || lp_order_tips_is_existing_order_scheduling($cart) || (float) $cart->subtotal <= 0) {
		lp_order_tips_clear_percent($cart);
		return;
	}

	$is_calculating_tip = true;

	$tip_amount = lp_order_tips_get_amount($cart);

	if ($tip_amount > 0) {
		$cart->total = (float) $cart->total + $tip_amount;
	}

	$is_calculating_tip = false;
}, 20);

/**
 * Adds optional template variables for custom invoice/receipt/email templates.
 */
add_filter('latepoint_model_view_as_first_level_data', function($data, $model) {
	if (!class_exists('OsOrderModel') || !($model instanceof OsOrderModel)) {
		return $data;
	}

	[$percent, $tip_amount] = lp_order_tips_get_order_tip_data($model);

	$data['order_tip_percent'] = $percent ? ((int) $percent) . '%' : '';
	$data['order_tip_amount'] = $tip_amount > 0 && class_exists('OsMoneyHelper')
		? OsMoneyHelper::format_price($tip_amount, true, false)
		: '';

	return $data;
}, 20, 2);


add_action('latepoint_available_vars_order', function(): void {
	echo '<li><span class="var-label">Order Tip Percent:</span> <span class="var-code">{{order_tip_percent}}</span></li>';
	echo '<li><span class="var-label">Order Tip Amount:</span> <span class="var-code">{{order_tip_amount}}</span></li>';
});


/**
 * side image for Tips step
 */
add_filter('latepoint_svg_for_step_code', function(string $svg, string $step_code): string {
	if ($step_code !== lp_order_tips_step_code()) {
		return $svg;
	}

	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 73 73">
		<path class="latepoint-step-svg-base" d="M58.65 6.12l-.27 2.75c-.04.44.3.82.75.82.38 0 .71-.29.75-.68l.27-2.75c.04-.41-.26-.78-.67-.82-.41-.05-.78.26-.82.68z"/>
		<path class="latepoint-step-svg-base" d="M60.97 11.08c.3.29.77.29 1.06-.01 1.07-1.08 1.85-2.43 2.25-3.9.11-.4-.13-.81-.53-.92-.41-.11-.81.13-.92.53-.33 1.22-.98 2.34-1.87 3.24-.29.29-.29.77.01 1.06z"/>
		<path class="latepoint-step-svg-base" d="M68.8 10.26c-.18-.37-.63-.53-1-.35l-4.27 2.08c-.37.18-.53.63-.35 1 .18.37.63.53 1 .35l4.27-2.08c.37-.18.53-.63.35-1z"/>
		<path class="latepoint-step-svg-highlight" d="M12 16.5h39c4.2 0 7.5 3.3 7.5 7.5v26c0 4.2-3.3 7.5-7.5 7.5H12c-4.2 0-7.5-3.3-7.5-7.5V24c0-4.2 3.3-7.5 7.5-7.5zm0 1.8c-3.2 0-5.7 2.5-5.7 5.7v26c0 3.2 2.5 5.7 5.7 5.7h39c3.2 0 5.7-2.5 5.7-5.7V24c0-3.2-2.5-5.7-5.7-5.7H12z"/>
		<path class="latepoint-step-svg-base" d="M5.6 28.5h51.8v1.8H5.6z"/>
		<path class="latepoint-step-svg-highlight" d="M22.6 43.7c0-3.2 2.7-5.8 6.1-5.8 1.9 0 3.7.8 4.8 2.1 1.1-1.3 2.8-2.1 4.8-2.1 3.4 0 6.1 2.6 6.1 5.8 0 5.4-7.1 9.3-10.4 11-.3.2-.7.2-1 0-3.3-1.7-10.4-5.6-10.4-11zm6.1-4.1c-2.4 0-4.3 1.8-4.3 4.1 0 4.1 5.5 7.5 9.1 9.3 3.6-1.9 9.1-5.2 9.1-9.3 0-2.3-1.9-4.1-4.3-4.1-1.8 0-3.3.9-4.1 2.3-.3.5-1.1.5-1.4 0-.8-1.4-2.3-2.3-4.1-2.3z"/>
		<path class="latepoint-step-svg-base" d="M13.5 36.5h8c.5 0 .9.4.9.9s-.4.9-.9.9h-8c-.5 0-.9-.4-.9-.9s.4-.9.9-.9z"/>
		<path class="latepoint-step-svg-base" d="M13.5 42h5.5c.5 0 .9.4.9.9s-.4.9-.9.9h-5.5c-.5 0-.9-.4-.9-.9s.4-.9.9-.9z"/>
	</svg>';
}, 20, 2);

/**
 * Handles custom amount UI for AJAX-loaded LatePoint steps.
 */
function lp_order_tips_print_custom_amount_script(): void {
	?>
	<script id="lp-order-tips-custom-amount">
	(function(){
		if (window.lpOrderTipsCustomAmountLoaded) return;
		window.lpOrderTipsCustomAmountLoaded = true;

		function parseAmount(value) {
			value = String(value || '').replace(',', '.').replace(/[^0-9.]/g, '');
			return Math.max(0, parseFloat(value) || 0);
		}

		function syncTipCustomField(root) {
			if (!root || !root.querySelector) return;

			var select = root.querySelector('#lp_order_tip_percent');
			var custom = root.querySelector('#lp_order_tip_amount');
			var customWrap = root.querySelector('.lp-order-tip-custom-w');

			if (!select || !custom || !customWrap) return;

			var isCustom = select.value === 'custom';
			customWrap.classList.toggle('lp-order-tip-custom-hidden', !isCustom);
			custom.disabled = !isCustom;

			if (!isCustom) {
				customWrap.style.setProperty('display', 'none', 'important');
				custom.value = '';
				return;
			}

			customWrap.style.removeProperty('display');

			if (custom.value !== '') {
				custom.value = parseAmount(custom.value).toString();
			}
		}

		function getTipsRoot(element) {
			return element && element.closest ? element.closest('.step-order-tips-w') : null;
		}

		document.addEventListener('change', function(event){
			if (!event.target || event.target.id !== 'lp_order_tip_percent') return;
			syncTipCustomField(getTipsRoot(event.target));
		});

		document.addEventListener('input', function(event){
			if (!event.target || event.target.id !== 'lp_order_tip_amount') return;

			var root = getTipsRoot(event.target);
			var select = root ? root.querySelector('#lp_order_tip_percent') : null;

			if (parseAmount(event.target.value) > 0 && select) {
				select.value = 'custom';
			}

			if (event.target.value !== '') {
				event.target.value = parseAmount(event.target.value).toString();
			}

			syncTipCustomField(root);
		});

		document.addEventListener('DOMContentLoaded', function(){
			document.querySelectorAll('.step-order-tips-w').forEach(syncTipCustomField);
		});

		document.addEventListener('latepoint:initOrderTipsCustomAmount', function(){
			document.querySelectorAll('.step-order-tips-w').forEach(syncTipCustomField);
		});
	})();
	</script>
	<?php
}

add_action('wp_footer', 'lp_order_tips_print_custom_amount_script', 1002);
add_action('admin_footer', 'lp_order_tips_print_custom_amount_script', 1002);

/**
 * Show tip in cost breakdown.
 */
add_filter('latepoint_cart_price_breakdown_rows', function(array $rows, $cart, array $rows_to_hide): array {
	if (!lp_order_tips_request_is_checkout_tail()) {
		return $rows;
	}

	if (!$cart || lp_order_tips_cart_is_empty($cart) || lp_order_tips_is_existing_order_scheduling($cart) || (float) $cart->subtotal <= 0) {
		lp_order_tips_clear_percent($cart);
		return $rows;
	}

	return lp_order_tips_add_tip_row_to_breakdown(
		$rows,
		lp_order_tips_get_percent($cart),
		lp_order_tips_get_amount($cart)
	);
}, 20, 3);

/**
 * Reads tip percent/amount from order meta, with a fallback for already-created orders.
 */
function lp_order_tips_get_order_tip_data($order): array {
	if (!$order) {
		return [0, 0];
	}

	$percent = 0;
	$tip_amount = 0;

	if (method_exists($order, 'get_meta_by_key')) {
		$percent = (float) $order->get_meta_by_key(lp_order_tips_meta_percent_key(), 0);
		$tip_amount = (float) $order->get_meta_by_key(lp_order_tips_meta_amount_key(), 0);
	}

	// Fallback для уже созданных orders, где total уже с чаевыми,
	// но meta/breakdown еще не были сохранены.
	if ($tip_amount <= 0) {
		$subtotal = isset($order->subtotal) ? (float) $order->subtotal : 0;
		$coupon_discount = isset($order->coupon_discount) ? (float) $order->coupon_discount : 0;
		$tax_total = isset($order->tax_total) ? (float) $order->tax_total : 0;
		$total = isset($order->total) ? (float) $order->total : 0;

		$base_total = max(0, $subtotal - $coupon_discount + $tax_total);
		$tip_amount = round(max(0, $total - $base_total), 4);

		if ($tip_amount > 0 && $base_total > 0) {
			$percent = round(($tip_amount / $base_total) * 100);
		}
	}

	if ($tip_amount <= 0) {
		return [0, 0];
	}

	return [$percent, $tip_amount];
}
