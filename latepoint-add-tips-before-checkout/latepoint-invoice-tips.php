<?php
/**
 * Plugin Name: LatePoint Invoice Tips
 * Description: Adds optional tips to LatePoint invoice payment request pages before payment.
 */

if (!defined('ABSPATH')) {
	exit;
}

function lp_invoice_tips_allowed_percents(): array {
	return [0, 5, 10, 15, 20, 25];
}

/**
 * Default shown only for invoice payment requests that do not already have a saved tip.
 */
function lp_invoice_tips_default_percent(): int {
	return 5;
}

/**
 * Request param added to the invoice payment button when a customer chooses a tip.
 */
function lp_invoice_tips_request_percent_key(): string {
	return 'lp_invoice_tip_percent';
}

/**
 * Optional request param used when a customer enters a fixed custom tip amount.
 */
function lp_invoice_tips_request_amount_key(): string {
	return 'lp_invoice_tip_amount';
}

/**
 * Shared with latepoint-tips-order.php, so receipts/templates use one tip variable.
 */
function lp_invoice_tips_order_percent_key(): string {
	return 'order_tip_percent';
}

/**
 * Shared with latepoint-tips-order.php, so invoice tips and checkout tips do not diverge.
 */
function lp_invoice_tips_order_amount_key(): string {
	return 'order_tip_amount';
}

/**
 * Stores the pre-invoice-tip order total so changing invoice tips stays reversible.
 */
function lp_invoice_tips_original_order_total_key(): string {
	return 'invoice_tip_original_order_total';
}

/**
 * Links saved order tip meta back to the invoice request that created/changed it.
 */
function lp_invoice_tips_invoice_id_key(): string {
	return 'invoice_tip_invoice_id';
}

/**
 * Prefix for state stored in invoice->data JSON.
 */
function lp_invoice_tips_data_prefix(): string {
	return 'lp_invoice_tip_';
}

/**
 * LatePoint route calls can arrive through admin-post or ajax; this normalizes route lookup.
 */
function lp_invoice_tips_route_name(): string {
	if (isset($_REQUEST['route_name'])) {
		return sanitize_text_field(wp_unslash($_REQUEST['route_name']));
	}

	return '';
}

function lp_invoice_tips_get_latepoint_param(string $name, $default = null) {
	if (isset($_REQUEST[$name])) {
		return sanitize_text_field(wp_unslash($_REQUEST[$name]));
	}

	if (isset($_POST['params'])) {
		$params = [];
		$raw_params = wp_unslash($_POST['params']);

		if (is_string($raw_params)) {
			parse_str($raw_params, $params);
		} elseif (is_array($raw_params)) {
			$params = $raw_params;
		}

		if (isset($params[$name])) {
			return is_scalar($params[$name]) ? sanitize_text_field((string) $params[$name]) : $default;
		}
	}

	if (class_exists('OsParamsHelper')) {
		$value = OsParamsHelper::get_param($name);
		if ($value !== null) {
			return is_scalar($value) ? sanitize_text_field((string) $value) : $default;
		}
	}

	return $default;
}

/**
 * Public invoice/receipt routes identify invoices and transactions by access key.
 */
function lp_invoice_tips_request_key(): string {
	return (string) lp_invoice_tips_get_latepoint_param('key', '');
}

/**
 * Returns null when the customer has not submitted a tip choice yet.
 */
function lp_invoice_tips_get_request_percent() {
	$key = lp_invoice_tips_request_percent_key();
	$percent = lp_invoice_tips_get_latepoint_param($key, null);

	if ($percent === null) {
		return null;
	}

	return (int) $percent;
}

/**
 * Returns null when the customer is using a percentage tip.
 */
function lp_invoice_tips_get_request_custom_amount() {
	$key = lp_invoice_tips_request_amount_key();
	$amount = lp_invoice_tips_get_latepoint_param($key, null);

	if ($amount === null || $amount === '') {
		return null;
	}

	return lp_invoice_tips_sanitize_custom_amount($amount);
}

/**
 * Keeps invoice tips aligned with the allowed UI options.
 */
function lp_invoice_tips_sanitize_percent($percent): int {
	$percent = (int) $percent;

	return in_array($percent, lp_invoice_tips_allowed_percents(), true) ? $percent : 0;
}

/**
 * Sanitizes typed money values like "12", "12.50", or "$12,50".
 */
function lp_invoice_tips_sanitize_custom_amount($amount): float {
	$amount = preg_replace('/[^0-9.,]/', '', (string) $amount);
	$amount = str_replace(',', '.', $amount);

	return round(max(0, (float) $amount), 2);
}

/**
 * Formats values for LatePoint database fields.
 */
function lp_invoice_tips_money($amount): string {
	if (class_exists('OsMoneyHelper')) {
		return OsMoneyHelper::pad_to_db_format((float) $amount);
	}

	return number_format((float) $amount, 2, '.', '');
}

/**
 * Formats values for human-facing invoice/order breakdown rows.
 */
function lp_invoice_tips_format_price($amount): string {
	if (class_exists('OsMoneyHelper')) {
		return OsMoneyHelper::format_price((float) $amount, true, false);
	}

	return '$' . number_format((float) $amount, 2);
}

/**
 * Invoice documents store totals and price_breakdown inside invoice->data JSON.
 */
function lp_invoice_tips_decode_invoice_data($invoice): array {
	if (!$invoice || empty($invoice->data)) {
		return [];
	}

	$data = json_decode((string) $invoice->data, true);

	return is_array($data) ? $data : [];
}

/**
 * Namespaced keys prevent our invoice tip state from colliding with LatePoint data.
 */
function lp_invoice_tips_invoice_data_key(string $key): string {
	return lp_invoice_tips_data_prefix() . $key;
}

/**
 * Safely resolves the order attached to an invoice.
 */
function lp_invoice_tips_get_order_from_invoice($invoice) {
	if (!$invoice || !method_exists($invoice, 'get_order')) {
		return false;
	}

	$order = $invoice->get_order();

	return ($order && method_exists($order, 'is_new_record') && !$order->is_new_record()) ? $order : false;
}

/**
 * Reads an already-saved order tip, including tips created by latepoint-tips-order.php.
 *
 * This is the compatibility bridge between the checkout tip flow and the invoice/receipt flow.
 */
function lp_invoice_tips_get_saved_order_tip_state($invoice) {
	$order = lp_invoice_tips_get_order_from_invoice($invoice);
	if (!$order) {
		return false;
	}

	$percent = 0;
	$tip_amount = 0;

	if (method_exists($order, 'get_meta_by_key')) {
		$percent = (float) $order->get_meta_by_key(lp_invoice_tips_order_percent_key(), 0);
		$tip_amount = (float) $order->get_meta_by_key(lp_invoice_tips_order_amount_key(), 0);
	}

	if (($percent <= 0 || $tip_amount <= 0) && !empty($order->price_breakdown)) {
		// Backfill from price_breakdown for orders where meta was not saved but the Tip row exists.
		$rows = json_decode((string) $order->price_breakdown, true);
		if (is_array($rows) && !empty($rows['after_subtotal']) && is_array($rows['after_subtotal'])) {
			foreach ($rows['after_subtotal'] as $group) {
				if (empty($group['items']) || !is_array($group['items'])) {
					continue;
				}

				foreach ($group['items'] as $item) {
					if (!is_array($item) || ($item['label'] ?? '') !== 'Tip') {
						continue;
					}

					$tip_amount = isset($item['raw_value']) ? (float) $item['raw_value'] : $tip_amount;
					if (!empty($item['badge'])) {
						$percent = (float) str_replace('%', '', (string) $item['badge']);
					}
					break 2;
				}
			}
		}
	}

	if ($tip_amount <= 0) {
		return false;
	}

	$total_amount = (float) ($invoice->charge_amount ?? $order->total ?? 0);
	if ($total_amount <= 0) {
		$total_amount = (float) ($order->total ?? 0);
	}

	$base_amount = max(0, $total_amount - $tip_amount);

	return [
		'has_tip_state' => true,
		'base_amount'   => $base_amount,
		'percent'       => lp_invoice_tips_sanitize_percent($percent),
		'tip_amount'    => round($tip_amount, 2),
		'total_amount'  => $total_amount,
	];
}

/**
 * Returns the effective invoice tip state.
 *
 * Priority:
 * 1. Tip already saved on the related order, especially from latepoint-tips-order.php.
 * 2. Tip state saved in invoice->data by this plugin.
 * 3. Default invoice tip for open invoice payment forms.
 */
function lp_invoice_tips_get_invoice_tip_state($invoice): array {
	$data = lp_invoice_tips_decode_invoice_data($invoice);
	$base_key = lp_invoice_tips_invoice_data_key('base_charge_amount');
	$percent_key = lp_invoice_tips_invoice_data_key('percent');
	$amount_key = lp_invoice_tips_invoice_data_key('amount');

	$has_tip_state = array_key_exists($percent_key, $data);

	$saved_order_tip_state = lp_invoice_tips_get_saved_order_tip_state($invoice);
	if ($saved_order_tip_state && !$has_tip_state) {
		return $saved_order_tip_state;
	}

	$order = lp_invoice_tips_get_order_from_invoice($invoice);
	$order_total = $order && isset($order->total) ? (float) $order->total : 0;
	$base_amount = array_key_exists($base_key, $data)
		? (float) $data[$base_key]
		: (float) ($order_total > 0 ? $order_total : ($invoice->charge_amount ?? 0));

	if (!$saved_order_tip_state && !lp_invoice_tips_invoice_is_open($invoice)) {
		return [
			'has_tip_state' => false,
			'base_amount'   => $base_amount,
			'percent'       => 0,
			'tip_amount'    => 0,
			'total_amount'  => $base_amount,
		];
	}

	$percent = $has_tip_state
		? lp_invoice_tips_sanitize_percent($data[$percent_key])
		: lp_invoice_tips_default_percent();
	$tip_amount = ($has_tip_state && array_key_exists($amount_key, $data) && $percent <= 0)
		? lp_invoice_tips_sanitize_custom_amount($data[$amount_key])
		: round($base_amount * $percent / 100, 2);

	if (
		$saved_order_tip_state
		&& (int) $percent === lp_invoice_tips_default_percent()
		&& abs($tip_amount - (float) $saved_order_tip_state['tip_amount']) > 0.01
	) {
		// If old invoice data contains only our default 5%, prefer the real order tip.
		return $saved_order_tip_state;
	}

	return [
		'has_tip_state' => $has_tip_state,
		'base_amount'   => $base_amount,
		'percent'       => $percent,
		'tip_amount'    => $tip_amount,
		'total_amount'  => $base_amount + $tip_amount,
	];
}

/**
 * Loads an invoice from a public payment/view key.
 */
function lp_invoice_tips_get_invoice_by_key(string $key) {
	if (empty($key) || !class_exists('OsInvoicesHelper')) {
		return false;
	}

	$invoice = OsInvoicesHelper::get_invoice_by_key($key);

	return ($invoice && method_exists($invoice, 'is_new_record') && !$invoice->is_new_record()) ? $invoice : false;
}

/**
 * Invoice tips are editable only while the invoice is open.
 */
function lp_invoice_tips_invoice_is_open($invoice): bool {
	if (!$invoice) {
		return false;
	}

	if (defined('LATEPOINT_INVOICE_STATUS_OPEN')) {
		return $invoice->status === LATEPOINT_INVOICE_STATUS_OPEN;
	}

	return (string) $invoice->status === 'open';
}

/**
 * Adds/replaces the Tip row in LatePoint price_breakdown data.
 */
function lp_invoice_tips_add_tip_row_to_breakdown(array $rows, float $percent, float $tip_amount): array {
	if ($tip_amount <= 0) {
		if (isset($rows['after_subtotal']['tips'])) {
			unset($rows['after_subtotal']['tips']);
		}
		return $rows;
	}

	if (!isset($rows['after_subtotal']) || !is_array($rows['after_subtotal'])) {
		$rows['after_subtotal'] = [];
	}

	unset($rows['after_subtotal']['tips']);

	$item = [
		'label'     => __('Tip', 'latepoint'),
		'raw_value' => lp_invoice_tips_money($tip_amount),
		'value'     => lp_invoice_tips_format_price($tip_amount),
		'type'      => 'charge',
	];

	if ($percent > 0) {
		$item['badge'] = ((int) $percent) . '%';
	}

	$rows['after_subtotal']['tips']['items'][] = $item;

	return $rows;
}

/**
 * Persists tip totals into invoice->data so invoice and receipt documents render consistently.
 */
function lp_invoice_tips_update_invoice_document_data($invoice, float $base_amount, float $percent, float $tip_amount, float $total_amount): void {
	if (!$invoice || !method_exists($invoice, 'update_attributes')) {
		return;
	}

	$data = lp_invoice_tips_decode_invoice_data($invoice);

	if (!isset($data['price_breakdown']) || !is_array($data['price_breakdown'])) {
		$data['price_breakdown'] = [];
	}

	$data['price_breakdown'] = lp_invoice_tips_add_tip_row_to_breakdown($data['price_breakdown'], $percent, $tip_amount);

	if (!isset($data['totals']) || !is_array($data['totals'])) {
		$data['totals'] = [];
	}

	if (!isset($data['totals']['subtotal']) || (float) $data['totals']['subtotal'] <= 0) {
		$data['totals']['subtotal'] = lp_invoice_tips_money($base_amount);
	}
	$data['totals']['total'] = lp_invoice_tips_money($total_amount);

	$base_key = lp_invoice_tips_invoice_data_key('base_charge_amount');
	$percent_key = lp_invoice_tips_invoice_data_key('percent');
	$amount_key = lp_invoice_tips_invoice_data_key('amount');
	$total_key = lp_invoice_tips_invoice_data_key('total_charge_amount');

	$data[$base_key] = lp_invoice_tips_money($base_amount);
	$data[$percent_key] = lp_invoice_tips_sanitize_percent($percent);
	$data[$amount_key] = lp_invoice_tips_money($tip_amount);
	$data[$total_key] = lp_invoice_tips_money($total_amount);

	$invoice->update_attributes([
		'data' => wp_json_encode($data),
	]);
}

/**
 * Removes stale tip data from already-paid invoices/orders that have no saved tip.
 */
function lp_invoice_tips_clear_invoice_document_tip($invoice, float $total_amount): void {
	if (!$invoice || !method_exists($invoice, 'update_attributes')) {
		return;
	}

	$data = lp_invoice_tips_decode_invoice_data($invoice);

	if (isset($data['price_breakdown']) && is_array($data['price_breakdown'])) {
		$data['price_breakdown'] = lp_invoice_tips_add_tip_row_to_breakdown($data['price_breakdown'], 0, 0);
	}

	if (!isset($data['totals']) || !is_array($data['totals'])) {
		$data['totals'] = [];
	}

	$data['totals']['total'] = lp_invoice_tips_money($total_amount);

	unset(
		$data[lp_invoice_tips_invoice_data_key('percent')],
		$data[lp_invoice_tips_invoice_data_key('amount')],
		$data[lp_invoice_tips_invoice_data_key('total_charge_amount')]
	);

	$data[lp_invoice_tips_invoice_data_key('base_charge_amount')] = lp_invoice_tips_money($total_amount);

	$invoice->update_attributes([
		'data' => wp_json_encode($data),
	]);
}

/**
 * Syncs invoice-selected tips back to the order.
 *
 * This intentionally writes the same order_tip_* meta keys used by latepoint-tips-order.php.
 */
function lp_invoice_tips_sync_order($order, int $invoice_id, float $percent, float $tip_amount): void {
	if (!$order || !method_exists($order, 'is_new_record') || $order->is_new_record()) {
		return;
	}

	$original_total = method_exists($order, 'get_meta_by_key')
		? $order->get_meta_by_key(lp_invoice_tips_original_order_total_key(), null)
		: null;

	if ($original_total === null || $original_total === false || $original_total === '') {
		$original_total = (float) $order->total;
		if (method_exists($order, 'save_meta_by_key')) {
			$order->save_meta_by_key(lp_invoice_tips_original_order_total_key(), lp_invoice_tips_money($original_total));
		}
	} else {
		$original_total = (float) $original_total;
	}

	if (method_exists($order, 'save_meta_by_key')) {
		$order->save_meta_by_key(lp_invoice_tips_order_percent_key(), lp_invoice_tips_sanitize_percent($percent));
		$order->save_meta_by_key(lp_invoice_tips_order_amount_key(), lp_invoice_tips_money($tip_amount));
		$order->save_meta_by_key(lp_invoice_tips_invoice_id_key(), (int) $invoice_id);
	}

	$new_total = $original_total + $tip_amount;
	$rows = [];
	if (!empty($order->price_breakdown)) {
		$decoded_rows = json_decode((string) $order->price_breakdown, true);
		if (is_array($decoded_rows)) {
			$rows = $decoded_rows;
		}
	}
	$rows = lp_invoice_tips_add_tip_row_to_breakdown($rows, $percent, $tip_amount);

	if (method_exists($order, 'update_attributes')) {
		$order->update_attributes([
			'total'           => lp_invoice_tips_money($new_total),
			'price_breakdown' => wp_json_encode($rows),
		]);
	}
}

/**
 * Applies a customer-selected invoice tip before the payment form is rendered/paid.
 */
function lp_invoice_tips_apply_to_invoice($invoice, int $percent, $custom_amount = null): bool {
	if (!$invoice || !lp_invoice_tips_invoice_is_open($invoice)) {
		return false;
	}

	$percent = lp_invoice_tips_sanitize_percent($percent);
	$custom_amount = $custom_amount === null ? null : lp_invoice_tips_sanitize_custom_amount($custom_amount);
	$data = lp_invoice_tips_decode_invoice_data($invoice);
	$base_key = lp_invoice_tips_invoice_data_key('base_charge_amount');
	$percent_key = lp_invoice_tips_invoice_data_key('percent');
	$amount_key = lp_invoice_tips_invoice_data_key('amount');
	$total_key = lp_invoice_tips_invoice_data_key('total_charge_amount');

	$base_amount = array_key_exists($base_key, $data)
		? (float) $data[$base_key]
		: (float) $invoice->charge_amount;

	if ($custom_amount !== null) {
		$percent = 0;
		$tip_amount = $custom_amount;
	} else {
		$tip_amount = round($base_amount * $percent / 100, 2);
	}

	$total_amount = $base_amount + $tip_amount;

	$data[$base_key] = lp_invoice_tips_money($base_amount);
	$data[$percent_key] = $percent;
	$data[$amount_key] = lp_invoice_tips_money($tip_amount);
	$data[$total_key] = lp_invoice_tips_money($total_amount);

	if (!method_exists($invoice, 'update_attributes')) {
		return false;
	}

	$updated = $invoice->update_attributes([
		'charge_amount' => lp_invoice_tips_money($total_amount),
		'data'          => wp_json_encode($data),
	]);

	if ($updated && method_exists($invoice, 'get_order')) {
		lp_invoice_tips_sync_order($invoice->get_order(), (int) $invoice->id, (float) $percent, $tip_amount);
		lp_invoice_tips_update_invoice_document_data($invoice, $base_amount, (float) $percent, $tip_amount, $total_amount);
	}

	return (bool) $updated;
}

/**
 * Refreshes invoice document data from whichever tip source is authoritative.
 */
function lp_invoice_tips_sync_invoice_document_from_saved_tip($invoice): void {
	if (!$invoice || method_exists($invoice, 'is_new_record') && $invoice->is_new_record()) {
		return;
	}

	$state = lp_invoice_tips_get_invoice_tip_state($invoice);
	if ((float) $state['tip_amount'] <= 0) {
		lp_invoice_tips_clear_invoice_document_tip($invoice, (float) $state['total_amount']);
		return;
	}

	lp_invoice_tips_update_invoice_document_data(
		$invoice,
		(float) $state['base_amount'],
		(float) $state['percent'],
		(float) $state['tip_amount'],
		(float) $state['total_amount']
	);
}

/**
 * Loads an invoice model by numeric id, usually from a transaction.
 */
function lp_invoice_tips_get_invoice_by_id($invoice_id) {
	if (!$invoice_id || !class_exists('OsInvoiceModel')) {
		return false;
	}

	$invoice = new OsInvoiceModel((int) $invoice_id);

	return ($invoice && method_exists($invoice, 'is_new_record') && !$invoice->is_new_record()) ? $invoice : false;
}

/**
 * Resolves a transaction/receipt by its public access key.
 */
function lp_invoice_tips_get_transaction_by_key(string $key) {
	if (empty($key) || !class_exists('OsTransactionModel')) {
		return false;
	}

	$transaction = new OsTransactionModel();
	$transaction = $transaction->where(['access_key' => $key])->set_limit(1)->get_results_as_models();

	return $transaction ?: false;
}

add_action('latepoint_transaction_created', function($transaction): void {
	if (!$transaction || empty($transaction->invoice_id)) {
		return;
	}

	$invoice = lp_invoice_tips_get_invoice_by_id($transaction->invoice_id);
	if ($invoice) {
		lp_invoice_tips_sync_invoice_document_from_saved_tip($invoice);
	}
}, 20, 1);

/**
 * Intercepts LatePoint route calls before controllers render invoice/payment/receipt output.
 */
function lp_invoice_tips_pre_route_handler(): void {
	$route_name = lp_invoice_tips_route_name();

	if ($route_name === 'invoices__payment_form') {
		// Customer selected a tip and clicked the payment button.
		$percent = lp_invoice_tips_get_request_percent();
		if ($percent !== null) {
			$custom_amount = lp_invoice_tips_get_request_custom_amount();
			$invoice = lp_invoice_tips_get_invoice_by_key(lp_invoice_tips_request_key());
			if ($invoice) {
				lp_invoice_tips_apply_to_invoice($invoice, (int) $percent, $custom_amount);
			}
		}
		return;
	}

	if ($route_name === 'invoices__summary_before_payment') {
		// The summary route is plain HTML, so inject our UI at shutdown.
		add_action('shutdown', 'lp_invoice_tips_append_summary_ui', 0);
		return;
	}

	if ($route_name === 'invoices__view_by_key') {
		// Viewing an invoice should refresh the document from saved order/invoice tip state.
		$invoice = lp_invoice_tips_get_invoice_by_key(lp_invoice_tips_request_key());
		if ($invoice) {
			lp_invoice_tips_sync_invoice_document_from_saved_tip($invoice);
		}
		return;
	}

	if ($route_name === 'transactions__view_receipt_by_key') {
		// Receipts are reached through transaction keys, then mapped back to invoice/order.
		$transaction = lp_invoice_tips_get_transaction_by_key(lp_invoice_tips_request_key());
		if ($transaction && !empty($transaction->invoice_id)) {
			$invoice = lp_invoice_tips_get_invoice_by_id($transaction->invoice_id);
			if ($invoice) {
				lp_invoice_tips_sync_invoice_document_from_saved_tip($invoice);
			}
		}
	}
}

/**
 * Builds the tip selector shown on the invoice payment summary.
 */
function lp_invoice_tips_build_summary_tip_html($invoice): string {
	if (!$invoice || !lp_invoice_tips_invoice_is_open($invoice)) {
		return '';
	}

	$state = lp_invoice_tips_get_invoice_tip_state($invoice);
	$selected_percent = (int) $state['percent'];
	$base_amount = (float) $state['base_amount'];
	$custom_tip_amount = ($selected_percent <= 0 && (float) $state['tip_amount'] > 0) ? (float) $state['tip_amount'] : 0;

	ob_start();
	?>
	<div class="lp-invoice-tip-box" data-lp-invoice-tip-box="yes">
		<!-- LatePoint Invoice Tips active -->
		<style>
			.lp-invoice-tip-box{margin:18px 0 4px;padding:16px;border:1px solid rgba(0,0,0,.12);border-radius:8px;background:#fff}
			.lp-invoice-tip-title{font-weight:600;margin-bottom:10px}
			.lp-invoice-tip-options{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
			.lp-invoice-tip-option input{position:absolute;opacity:0;pointer-events:none}
			.lp-invoice-tip-option span{display:inline-flex;align-items:center;justify-content:center;min-width:54px;height:36px;padding:0 12px;border:1px solid rgba(0,0,0,.18);border-radius:6px;cursor:pointer;background:#fff;font-weight:600}
			.lp-invoice-tip-option input:checked+span{border-color:#111;background:#111;color:#fff}
			.lp-invoice-tip-custom{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:-2px 0 12px}
			.lp-invoice-tip-custom-label{font-size:14px;font-weight:600}
			.lp-invoice-tip-custom-input{width:130px;min-height:36px;border:1px solid rgba(0,0,0,.18);border-radius:6px;padding:0 10px;font:inherit}
			.lp-invoice-tip-custom.is-active .lp-invoice-tip-custom-input{border-color:#111}
			.lp-invoice-tip-totals{display:flex;justify-content:space-between;gap:12px;font-size:14px;line-height:1.5}
			.lp-invoice-tip-totals strong{font-weight:700}
		</style>
		<div class="lp-invoice-tip-title"><?php echo esc_html__('Add a tip for your coach (optional)', 'latepoint'); ?></div>
		<div class="lp-invoice-tip-options">
			<?php foreach (lp_invoice_tips_allowed_percents() as $percent) :
				$tip_amount = round($base_amount * $percent / 100, 2);
				$total_amount = $base_amount + $tip_amount;
				?>
				<label class="lp-invoice-tip-option">
					<input type="radio"
						   name="lp_invoice_tip_percent_ui"
						   value="<?php echo esc_attr($percent); ?>"
						   data-tip-label="<?php echo esc_attr(lp_invoice_tips_format_price($tip_amount)); ?>"
						   data-total-label="<?php echo esc_attr(lp_invoice_tips_format_price($total_amount)); ?>"
						   <?php checked($selected_percent === $percent && $custom_tip_amount <= 0); ?>>
					<span><?php echo $percent > 0 ? esc_html($percent . '%') : esc_html__('No tip', 'latepoint'); ?></span>
				</label>
			<?php endforeach; ?>
		</div>
		<div class="lp-invoice-tip-custom<?php echo $custom_tip_amount > 0 ? ' is-active' : ''; ?>">
			<label class="lp-invoice-tip-option">
				<input type="radio"
					   name="lp_invoice_tip_percent_ui"
					   value="custom"
					   data-tip-label="<?php echo esc_attr(lp_invoice_tips_format_price($custom_tip_amount)); ?>"
					   data-total-label="<?php echo esc_attr(lp_invoice_tips_format_price($base_amount + $custom_tip_amount)); ?>"
					   <?php checked($custom_tip_amount > 0); ?>>
				<span><?php echo esc_html__('Custom', 'latepoint'); ?></span>
			</label>
			<label class="lp-invoice-tip-custom-label" for="lp-invoice-tip-custom-amount"><?php echo esc_html__('Amount', 'latepoint'); ?></label>
			<input id="lp-invoice-tip-custom-amount"
				   class="lp-invoice-tip-custom-input"
				   type="number"
				   min="0"
				   step="0.01"
				   inputmode="decimal"
				   value="<?php echo esc_attr($custom_tip_amount > 0 ? number_format($custom_tip_amount, 2, '.', '') : ''); ?>"
				   placeholder="0.00"
				   data-base-amount="<?php echo esc_attr(number_format($base_amount, 2, '.', '')); ?>">
		</div>
		<div class="lp-invoice-tip-totals">
			<div><?php echo esc_html__('Tip:', 'latepoint'); ?> <strong class="lp-invoice-tip-amount"><?php echo esc_html(lp_invoice_tips_format_price((float) $state['tip_amount'])); ?></strong></div>
			<div><?php echo esc_html__('Total:', 'latepoint'); ?> <strong class="lp-invoice-tip-total"><?php echo esc_html(lp_invoice_tips_format_price((float) $state['total_amount'])); ?></strong></div>
		</div>
		<script>
			(function(){
				var root = document.currentScript.closest('.lp-invoice-tip-box');
				if (!root) return;
				var scope = root.closest('.invoice-payment-summary-wrapper') || document;
				var button = scope.querySelector('.invoice-make-payment-btn');
				var amount = scope.querySelector('.invoice-due-amount-wrapper .id-amount');
				var tipAmount = root.querySelector('.lp-invoice-tip-amount');
				var tipTotal = root.querySelector('.lp-invoice-tip-total');
				var customWrap = root.querySelector('.lp-invoice-tip-custom');
				var customInput = root.querySelector('.lp-invoice-tip-custom-input');
				var customRadio = root.querySelector('input[value="custom"]');
				var baseAmount = customInput ? parseFloat(customInput.getAttribute('data-base-amount') || '0') : 0;
				function parseCustomAmount(){
					if (!customInput) return 0;
					var value = String(customInput.value || '').replace(',', '.').replace(/[^0-9.]/g, '');
					return Math.max(0, Math.round((parseFloat(value) || 0) * 100) / 100);
				}
				function formatMoney(value){
					try {
						return new Intl.NumberFormat('en-US', {style: 'currency', currency: 'USD'}).format(value);
					} catch (error) {
						return '$' + value.toFixed(2);
					}
				}
				function syncTipSelection(){
					// Keep visible totals and LatePoint payment button params in sync.
					var checked = root.querySelector('input[name="lp_invoice_tip_percent_ui"]:checked');
					if (!checked) return;
					var isCustom = checked.value === 'custom';
					var customAmount = isCustom ? parseCustomAmount() : 0;
					var totalLabel = isCustom ? formatMoney(baseAmount + customAmount) : (checked.getAttribute('data-total-label') || '');
					var tipLabel = isCustom ? formatMoney(customAmount) : (checked.getAttribute('data-tip-label') || '');

					if (customWrap) customWrap.classList.toggle('is-active', isCustom);
					if (amount && totalLabel) amount.textContent = totalLabel;
					if (tipAmount && tipLabel) tipAmount.textContent = tipLabel;
					if (tipTotal && totalLabel) tipTotal.textContent = totalLabel;
					if (button) {
						var params = new URLSearchParams(button.getAttribute('data-os-params') || '');
						if (isCustom) {
							params.set('<?php echo esc_js(lp_invoice_tips_request_percent_key()); ?>', '0');
							params.set('<?php echo esc_js(lp_invoice_tips_request_amount_key()); ?>', customAmount.toFixed(2));
						} else {
							params.set('<?php echo esc_js(lp_invoice_tips_request_percent_key()); ?>', checked.value);
							params.delete('<?php echo esc_js(lp_invoice_tips_request_amount_key()); ?>');
						}
						button.setAttribute('data-os-params', params.toString());
					}
				}
				root.querySelectorAll('input[name="lp_invoice_tip_percent_ui"]').forEach(function(input){
					input.addEventListener('change', syncTipSelection);
				});
				if (customInput && customRadio) {
					customInput.addEventListener('focus', function(){
						customRadio.checked = true;
						syncTipSelection();
					});
					customInput.addEventListener('input', function(){
						customRadio.checked = true;
						syncTipSelection();
					});
				}
				syncTipSelection();
			})();
		</script>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Legacy helper for string injection if LatePoint returns a full HTML fragment.
 */
function lp_invoice_tips_inject_summary_html(string $html): string {
	if (strpos($html, 'data-lp-invoice-tip-box="yes"') !== false) {
		return $html;
	}

	$invoice = lp_invoice_tips_get_invoice_by_key(lp_invoice_tips_request_key());
	if (!$invoice) {
		return $html;
	}

	$tip_html = lp_invoice_tips_build_summary_tip_html($invoice);
	if ($tip_html === '') {
		return $html;
	}

	$needle = '<div class="full-summary-info-w">';
	if (strpos($html, $needle) !== false) {
		return str_replace($needle, $tip_html . $needle, $html);
	}

	$button_marker = 'class="latepoint-btn invoice-make-payment-btn"';
	if (strpos($html, $button_marker) !== false) {
		return $html . $tip_html;
	}

	return $html;
}

/**
 * Appends the invoice tip UI after LatePoint renders the summary page.
 */
function lp_invoice_tips_append_summary_ui(): void {
	if (lp_invoice_tips_route_name() !== 'invoices__summary_before_payment') {
		return;
	}

	$return_format = isset($_REQUEST['return_format'])
		? sanitize_text_field(wp_unslash($_REQUEST['return_format']))
		: 'html';

	if ($return_format === 'json') {
		return;
	}

	$invoice = lp_invoice_tips_get_invoice_by_key(lp_invoice_tips_request_key());
	if (!$invoice) {
		error_log('LatePoint Invoice Tips: invoice not found for key ' . lp_invoice_tips_request_key());
		return;
	}

	$tip_html = lp_invoice_tips_build_summary_tip_html($invoice);
	if ($tip_html === '') {
		error_log('LatePoint Invoice Tips: tip html is empty for invoice ' . (isset($invoice->id) ? $invoice->id : 'unknown'));
		return;
	}

	?>
	<div id="lp-invoice-tip-holder" style="display:none;"><?php echo $tip_html; ?></div>
	<script>
		(function(){
			var holder = document.getElementById('lp-invoice-tip-holder');
			if (!holder) return;
			var tipBox = holder.querySelector('[data-lp-invoice-tip-box="yes"]');
			var target = document.querySelector('.invoice-payment-summary-wrapper .full-summary-info-w');
			if (tipBox && target && !document.querySelector('.invoice-payment-summary-wrapper [data-lp-invoice-tip-box="yes"]')) {
				target.parentNode.insertBefore(tipBox, target);
			}
			holder.parentNode.removeChild(holder);
		})();
	</script>
	<?php
}

add_action('admin_post_latepoint_route_call', 'lp_invoice_tips_pre_route_handler', 9);
add_action('admin_post_nopriv_latepoint_route_call', 'lp_invoice_tips_pre_route_handler', 9);
add_action('wp_ajax_latepoint_route_call', 'lp_invoice_tips_pre_route_handler', 9);
add_action('wp_ajax_nopriv_latepoint_route_call', 'lp_invoice_tips_pre_route_handler', 9);

/**
 * Ensures admin/customer order breakdowns show the invoice tip saved on the order.
 */
add_filter('latepoint_order_price_breakdown_rows', function(array $rows, $order, array $rows_to_hide, bool $force_recalculate = false): array {
	if (!$order || !method_exists($order, 'get_meta_by_key')) {
		return $rows;
	}

	$percent = (float) $order->get_meta_by_key(lp_invoice_tips_order_percent_key(), 0);
	$tip_amount = (float) $order->get_meta_by_key(lp_invoice_tips_order_amount_key(), 0);

	return lp_invoice_tips_add_tip_row_to_breakdown($rows, $percent, $tip_amount);
}, 30, 4);

/**
 * Exposes tip variables to LatePoint templates, receipts, invoices, and emails.
 */
add_filter('latepoint_model_view_as_first_level_data', function($data, $model) {
	if (!class_exists('OsOrderModel') || !($model instanceof OsOrderModel) || !method_exists($model, 'get_meta_by_key')) {
		return $data;
	}

	$percent = (float) $model->get_meta_by_key(lp_invoice_tips_order_percent_key(), 0);
	$tip_amount = (float) $model->get_meta_by_key(lp_invoice_tips_order_amount_key(), 0);

	$data['order_tip_percent'] = $percent ? ((int) $percent) . '%' : '';
	$data['order_tip_amount'] = $tip_amount > 0 ? lp_invoice_tips_format_price($tip_amount) : '';

	return $data;
}, 30, 2);
