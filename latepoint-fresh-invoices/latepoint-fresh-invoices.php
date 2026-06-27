<?php
/**
 * Plugin Name: LatePoint Fresh Invoices
 * Description: Rebuilds newly created LatePoint invoices from the current order and aligns invoice document data with the manually entered amount.
 */

if (!defined('ABSPATH')) {
	exit;
}

function lp_fresh_invoices_adjustment_group_key(): string {
	return 'lp_fresh_invoice_adjustment';
}

function lp_fresh_invoices_money($amount): string {
	if (class_exists('OsMoneyHelper')) {
		return OsMoneyHelper::pad_to_db_format((float) $amount);
	}

	return number_format((float) $amount, 4, '.', '');
}

function lp_fresh_invoices_format_price($amount): string {
	if (class_exists('OsMoneyHelper')) {
		return OsMoneyHelper::format_price((float) $amount, true, false);
	}

	return '$' . number_format((float) $amount, 2);
}

function lp_fresh_invoices_is_editable_invoice($invoice): bool {
	if (!$invoice || (method_exists($invoice, 'is_new_record') && $invoice->is_new_record())) {
		return false;
	}

	$locked_statuses = [];
	if (defined('LATEPOINT_INVOICE_STATUS_PAID')) {
		$locked_statuses[] = LATEPOINT_INVOICE_STATUS_PAID;
	}
	if (defined('LATEPOINT_INVOICE_STATUS_VOID')) {
		$locked_statuses[] = LATEPOINT_INVOICE_STATUS_VOID;
	}

	return !in_array((string) $invoice->status, array_map('strval', $locked_statuses), true);
}

function lp_fresh_invoices_get_order($invoice) {
	if (!$invoice || !method_exists($invoice, 'get_order')) {
		return false;
	}

	$order = $invoice->get_order();

	return ($order && method_exists($order, 'is_new_record') && !$order->is_new_record()) ? $order : false;
}

function lp_fresh_invoices_remove_adjustment_rows(array $rows): array {
	if (isset($rows['after_subtotal'][lp_fresh_invoices_adjustment_group_key()])) {
		unset($rows['after_subtotal'][lp_fresh_invoices_adjustment_group_key()]);
	}

	if (empty($rows['after_subtotal']) || !is_array($rows['after_subtotal'])) {
		return $rows;
	}

	foreach ($rows['after_subtotal'] as $group_key => $group) {
		if (empty($group['items']) || !is_array($group['items'])) {
			continue;
		}

		$group['items'] = array_values(array_filter($group['items'], function($item): bool {
			if (!is_array($item)) {
				return true;
			}

			if (!empty($item['lp_fresh_invoice_adjustment'])) {
				return false;
			}

			$label = strtolower(trim(wp_strip_all_tags((string) ($item['label'] ?? ''))));
			$adjustment_labels = [
				strtolower(__('Manual invoice discount', 'latepoint')),
				strtolower(__('Additional services', 'latepoint')),
			];

			return !in_array($label, $adjustment_labels, true);
		}));

		if (empty($group['items'])) {
			unset($rows['after_subtotal'][$group_key]);
			continue;
		}

		$rows['after_subtotal'][$group_key] = $group;
	}

	return $rows;
}

function lp_fresh_invoices_recalculate_order_rows($order): array {
	if (!$order || !method_exists($order, 'generate_price_breakdown_rows')) {
		return [];
	}

	$rows = $order->generate_price_breakdown_rows(['payments', 'balance'], true);

	return is_array($rows) ? lp_fresh_invoices_remove_adjustment_rows($rows) : [];
}

function lp_fresh_invoices_raw_row_value($row): float {
	if (!is_array($row)) {
		return 0;
	}

	return isset($row['raw_value']) ? (float) $row['raw_value'] : 0;
}

function lp_fresh_invoices_order_total_from_rows(array $rows, $order): float {
	if (!empty($rows['total']) && is_array($rows['total'])) {
		return lp_fresh_invoices_raw_row_value($rows['total']);
	}

	if ($order && method_exists($order, 'get_total')) {
		return (float) $order->get_total(true);
	}

	return isset($order->total) ? (float) $order->total : 0;
}

function lp_fresh_invoices_order_subtotal_from_rows(array $rows, $order): float {
	if (!empty($rows['subtotal']) && is_array($rows['subtotal'])) {
		return lp_fresh_invoices_raw_row_value($rows['subtotal']);
	}

	if ($order && method_exists($order, 'get_subtotal')) {
		return (float) $order->get_subtotal(true);
	}

	return isset($order->subtotal) ? (float) $order->subtotal : 0;
}

function lp_fresh_invoices_add_adjustment_row(array $rows, float $order_total, float $invoice_amount): array {
	$delta = round($invoice_amount - $order_total, 4);

	if (abs($delta) < 0.0001) {
		return $rows;
	}

	if (!isset($rows['after_subtotal']) || !is_array($rows['after_subtotal'])) {
		$rows['after_subtotal'] = [];
	}

	$is_discount = $delta < 0;
	$amount = abs($delta);
	$raw_value = $is_discount ? -$amount : $amount;

	$rows['after_subtotal'][lp_fresh_invoices_adjustment_group_key()] = [
		'items' => [
			[
				'label'                       => $is_discount ? __('Manual invoice discount', 'latepoint') : __('Additional services', 'latepoint'),
				'raw_value'                   => lp_fresh_invoices_money($raw_value),
				'value'                       => ($is_discount ? '-' : '') . lp_fresh_invoices_format_price($amount),
				'type'                        => $is_discount ? 'credit' : 'charge',
				'lp_fresh_invoice_adjustment' => true,
			],
		],
	];

	return $rows;
}

function lp_fresh_invoices_set_total_row(array $rows, float $invoice_amount): array {
	$rows['total'] = [
		'label'     => __('Total Price', 'latepoint'),
		'raw_value' => lp_fresh_invoices_money($invoice_amount),
		'value'     => lp_fresh_invoices_format_price($invoice_amount),
		'style'     => 'total',
	];

	return $rows;
}

function lp_fresh_invoices_decode_data($invoice): array {
	if (!$invoice || empty($invoice->data)) {
		return [];
	}

	$data = json_decode((string) $invoice->data, true);

	return is_array($data) ? $data : [];
}

function lp_fresh_invoices_get_saved_payable_amount($invoice) {
	$data = lp_fresh_invoices_decode_data($invoice);

	if (isset($data['lp_invoice_tip_total_charge_amount']) && is_numeric($data['lp_invoice_tip_total_charge_amount'])) {
		return round((float) $data['lp_invoice_tip_total_charge_amount'], 4);
	}

	if (isset($data['lp_fresh_invoice']['payable_amount']) && is_numeric($data['lp_fresh_invoice']['payable_amount'])) {
		return round((float) $data['lp_fresh_invoice']['payable_amount'], 4);
	}

	if (isset($data['lp_fresh_invoice']['invoice_amount']) && is_numeric($data['lp_fresh_invoice']['invoice_amount'])) {
		return round((float) $data['lp_fresh_invoice']['invoice_amount'], 4);
	}

	return null;
}

function lp_fresh_invoices_sync_charge_amount_from_data($invoice): void {
	if (!lp_fresh_invoices_is_editable_invoice($invoice) || !method_exists($invoice, 'update_attributes')) {
		return;
	}

	$payable_amount = lp_fresh_invoices_get_saved_payable_amount($invoice);
	if ($payable_amount === null || $payable_amount <= 0) {
		return;
	}

	if (abs((float) $invoice->charge_amount - $payable_amount) < 0.0001) {
		return;
	}

	$invoice->charge_amount = lp_fresh_invoices_money($payable_amount);
	$invoice->update_attributes([
		'charge_amount' => $invoice->charge_amount,
	]);
}

function lp_fresh_invoices_sync_open_transaction_intents($invoice): void {
	if (!$invoice || empty($invoice->id) || !class_exists('OsTransactionIntentModel')) {
		return;
	}

	$statuses = [];
	if (defined('LATEPOINT_TRANSACTION_INTENT_STATUS_NEW')) {
		$statuses[] = LATEPOINT_TRANSACTION_INTENT_STATUS_NEW;
	}
	if (defined('LATEPOINT_TRANSACTION_INTENT_STATUS_PROCESSING')) {
		$statuses[] = LATEPOINT_TRANSACTION_INTENT_STATUS_PROCESSING;
	}

	if (empty($statuses)) {
		return;
	}

	$intent_query = new OsTransactionIntentModel();
	$intents = $intent_query->where([
		'invoice_id' => $invoice->id,
		'status'     => $statuses,
	])->get_results_as_models();

	if (!$intents) {
		return;
	}

	foreach ($intents as $intent) {
		if (!$intent || (method_exists($intent, 'is_new_record') && $intent->is_new_record())) {
			continue;
		}

		$intent->charge_amount = $invoice->charge_amount;
		if (method_exists($intent, 'calculate_specs_charge_amount')) {
			$intent->calculate_specs_charge_amount();
		}
		$intent->save();
	}
}

// A selected tip on an unpaid invoice is only checkout state; it becomes order money after a successful payment.
function lp_fresh_invoices_has_selected_invoice_tip($invoice): bool {
	$data = lp_fresh_invoices_decode_data($invoice);

	return (
		isset($data['lp_invoice_tip_total_charge_amount'])
		&& is_numeric($data['lp_invoice_tip_total_charge_amount'])
		&& isset($data['lp_invoice_tip_amount'])
		&& (float) $data['lp_invoice_tip_amount'] > 0
	);
}

function lp_fresh_invoices_get_tip_amount_from_invoice_data(array $data): float {
	if (isset($data['lp_invoice_tip_amount']) && is_numeric($data['lp_invoice_tip_amount'])) {
		return max(0, round((float) $data['lp_invoice_tip_amount'], 4));
	}

	return 0;
}

function lp_fresh_invoices_get_tip_percent_from_invoice_data(array $data): float {
	if (isset($data['lp_invoice_tip_percent']) && is_numeric($data['lp_invoice_tip_percent'])) {
		return max(0, (float) $data['lp_invoice_tip_percent']);
	}

	return 0;
}

function lp_fresh_invoices_get_order_tip_amount($order): float {
	if (!$order || !method_exists($order, 'get_meta_by_key')) {
		return 0;
	}

	return max(0, round((float) $order->get_meta_by_key('order_tip_amount', 0), 4));
}

function lp_fresh_invoices_get_order_tip_percent($order): float {
	if (!$order || !method_exists($order, 'get_meta_by_key')) {
		return 0;
	}

	return max(0, (float) $order->get_meta_by_key('order_tip_percent', 0));
}

function lp_fresh_invoices_invoice_has_successful_payment($invoice): bool {
	if (!$invoice || (method_exists($invoice, 'is_new_record') && $invoice->is_new_record())) {
		return false;
	}

	if (defined('LATEPOINT_INVOICE_STATUS_PAID') && (string) $invoice->status === (string) LATEPOINT_INVOICE_STATUS_PAID) {
		return true;
	}

	if (method_exists($invoice, 'get_successful_payments')) {
		$payments = $invoice->get_successful_payments();
		return !empty($payments);
	}

	return false;
}

function lp_fresh_invoices_should_count_tip($invoice, $order = null): bool {
	if (lp_fresh_invoices_invoice_has_successful_payment($invoice)) {
		return true;
	}

	if ($order && method_exists($order, 'get_total_amount_paid_from_transactions')) {
		return (float) $order->get_total_amount_paid_from_transactions() > 0;
	}

	return false;
}

function lp_fresh_invoices_get_invoice_base_amount_from_data(array $data): float {
	if (isset($data['lp_fresh_invoice']['payable_amount']) && is_numeric($data['lp_fresh_invoice']['payable_amount'])) {
		return round((float) $data['lp_fresh_invoice']['payable_amount'], 4);
	}

	if (isset($data['lp_fresh_invoice']['invoice_amount']) && is_numeric($data['lp_fresh_invoice']['invoice_amount'])) {
		return round((float) $data['lp_fresh_invoice']['invoice_amount'], 4);
	}

	if (isset($data['lp_invoice_tip_base_charge_amount']) && is_numeric($data['lp_invoice_tip_base_charge_amount'])) {
		return round((float) $data['lp_invoice_tip_base_charge_amount'], 4);
	}

	return 0;
}

function lp_fresh_invoices_get_invoice_tip_amount(array $data, $order = null, $invoice = null): float {
	if (!lp_fresh_invoices_should_count_tip($invoice, $order)) {
		return 0;
	}

	return max(
		lp_fresh_invoices_get_tip_amount_from_invoice_data($data),
		lp_fresh_invoices_get_order_tip_amount($order)
	);
}

function lp_fresh_invoices_get_invoice_tip_percent(array $data, $order = null, $invoice = null): float {
	if (!lp_fresh_invoices_should_count_tip($invoice, $order)) {
		return 0;
	}

	$percent = lp_fresh_invoices_get_tip_percent_from_invoice_data($data);

	return $percent > 0 ? $percent : lp_fresh_invoices_get_order_tip_percent($order);
}

function lp_fresh_invoices_get_invoice_total_amount_from_data(array $data, $order = null, $invoice = null): float {
	if (
		lp_fresh_invoices_should_count_tip($invoice, $order)
		&& isset($data['lp_invoice_tip_total_charge_amount'])
		&& is_numeric($data['lp_invoice_tip_total_charge_amount'])
	) {
		return round((float) $data['lp_invoice_tip_total_charge_amount'], 4);
	}

	$base_amount = lp_fresh_invoices_get_invoice_base_amount_from_data($data);
	$tip_amount = lp_fresh_invoices_get_invoice_tip_amount($data, $order, $invoice);

	return round($base_amount + $tip_amount, 4);
}

function lp_fresh_invoices_invoice_can_drive_order($invoice, $order = null): bool {
	if (!$invoice || (method_exists($invoice, 'is_new_record') && $invoice->is_new_record())) {
		return false;
	}

	$data = lp_fresh_invoices_decode_data($invoice);

	if (!empty($data['lp_fresh_invoice'])) {
		return true;
	}

	if (isset($data['lp_invoice_tip_base_charge_amount'])) {
		return true;
	}

	if (
		lp_fresh_invoices_invoice_has_successful_payment($invoice)
		&& (
			isset($data['lp_invoice_tip_total_charge_amount'])
			|| isset($data['lp_invoice_tip_amount'])
			|| lp_fresh_invoices_get_order_tip_amount($order) > 0
		)
	) {
		return true;
	}

	return false;
}

function lp_fresh_invoices_get_order_total_at_refresh_from_data(array $data): float {
	if (isset($data['lp_fresh_invoice']['order_total_at_refresh']) && is_numeric($data['lp_fresh_invoice']['order_total_at_refresh'])) {
		return round((float) $data['lp_fresh_invoice']['order_total_at_refresh'], 4);
	}

	return 0;
}

function lp_fresh_invoices_remove_tip_rows(array $rows): array {
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
			if (!is_array($item)) {
				return true;
			}

			$label = strtolower(trim(wp_strip_all_tags((string) ($item['label'] ?? ''))));

			return $label !== strtolower(__('Tip', 'latepoint'));
		}));

		if (empty($group['items'])) {
			unset($rows['after_subtotal'][$group_key]);
			continue;
		}

		$rows['after_subtotal'][$group_key] = $group;
	}

	return $rows;
}

function lp_fresh_invoices_add_tip_row(array $rows, float $percent, float $tip_amount): array {
	$rows = lp_fresh_invoices_remove_tip_rows($rows);

	if ($tip_amount <= 0) {
		return $rows;
	}

	if (!isset($rows['after_subtotal']) || !is_array($rows['after_subtotal'])) {
		$rows['after_subtotal'] = [];
	}

	$item = [
		'label'     => __('Tip', 'latepoint'),
		'raw_value' => lp_fresh_invoices_money($tip_amount),
		'value'     => lp_fresh_invoices_format_price($tip_amount),
		'type'      => 'charge',
	];

	if ($percent > 0) {
		$item['badge'] = ((int) $percent) . '%';
	}

	$rows['after_subtotal']['tips']['items'][] = $item;

	return $rows;
}

function lp_fresh_invoices_get_invoice_for_order_breakdown($order) {
	if (!$order || empty($order->id) || !class_exists('OsInvoiceModel')) {
		return false;
	}

	$invoice_ids = [];
	if (method_exists($order, 'get_meta_by_key')) {
		$tip_invoice_id = absint($order->get_meta_by_key('invoice_tip_invoice_id', 0));
		if ($tip_invoice_id > 0) {
			$invoice_ids[] = $tip_invoice_id;
		}
	}

	foreach ($invoice_ids as $invoice_id) {
		$invoice = new OsInvoiceModel($invoice_id);
		if (lp_fresh_invoices_invoice_can_drive_order($invoice, $order)) {
			return $invoice;
		}
	}

	$invoice_query = new OsInvoiceModel();
	$invoices = $invoice_query->where(['order_id' => $order->id])->order_by('id desc')->get_results_as_models();
	if (!$invoices) {
		return false;
	}

	foreach ($invoices as $invoice) {
		if (lp_fresh_invoices_invoice_can_drive_order($invoice, $order)) {
			return $invoice;
		}
	}

	return false;
}

// Dashboard rows are rebuilt from the current order items, then adjusted to the stored invoice amount.
function lp_fresh_invoices_build_order_rows_from_invoice($order, $invoice): array {
	$data = lp_fresh_invoices_decode_data($invoice);
	$invoice_base_amount = lp_fresh_invoices_get_invoice_base_amount_from_data($data);
	if ($invoice_base_amount <= 0) {
		$invoice_base_amount = max(0, (float) ($invoice->charge_amount ?? 0) - lp_fresh_invoices_get_invoice_tip_amount($data, $order, $invoice));
	}
	$order_total_at_refresh = lp_fresh_invoices_get_order_total_at_refresh_from_data($data);
	if ($order_total_at_refresh <= 0) {
		$order_total_at_refresh = $invoice_base_amount;
	}
	$tip_amount = lp_fresh_invoices_get_invoice_tip_amount($data, $order, $invoice);
	$tip_percent = lp_fresh_invoices_get_invoice_tip_percent($data, $order, $invoice);

	$GLOBALS['lp_fresh_invoices_building_order_rows'] = true;
	$rows = lp_fresh_invoices_recalculate_order_rows($order);
	$GLOBALS['lp_fresh_invoices_building_order_rows'] = false;

	$rows = lp_fresh_invoices_remove_adjustment_rows($rows);
	$rows = lp_fresh_invoices_remove_tip_rows($rows);

	if ($invoice_base_amount > 0 && $order_total_at_refresh > 0) {
		$rows = lp_fresh_invoices_add_adjustment_row($rows, $order_total_at_refresh, $invoice_base_amount);
	}

	$rows = lp_fresh_invoices_add_tip_row($rows, $tip_percent, $tip_amount);

	unset($rows['payments'], $rows['balance'], $rows['total']);

	return $rows;
}

function lp_fresh_invoices_payment_status_for_total($order, float $total_amount): string {
	if (!$order || !method_exists($order, 'get_total_amount_paid_from_transactions')) {
		return defined('LATEPOINT_ORDER_PAYMENT_STATUS_NOT_PAID') ? LATEPOINT_ORDER_PAYMENT_STATUS_NOT_PAID : 'not_paid';
	}

	$total_paid = (float) $order->get_total_amount_paid_from_transactions();

	if ($total_paid <= 0) {
		return defined('LATEPOINT_ORDER_PAYMENT_STATUS_NOT_PAID') ? LATEPOINT_ORDER_PAYMENT_STATUS_NOT_PAID : 'not_paid';
	}

	if ($total_paid + 0.0001 < $total_amount) {
		return defined('LATEPOINT_ORDER_PAYMENT_STATUS_PARTIALLY_PAID') ? LATEPOINT_ORDER_PAYMENT_STATUS_PARTIALLY_PAID : 'partially_paid';
	}

	return defined('LATEPOINT_ORDER_PAYMENT_STATUS_FULLY_PAID') ? LATEPOINT_ORDER_PAYMENT_STATUS_FULLY_PAID : 'fully_paid';
}

function lp_fresh_invoices_apply_invoice_totals_to_order($order, $invoice, bool $save = false) {
	if (!$order || !$invoice) {
		return $order;
	}

	$data = lp_fresh_invoices_decode_data($invoice);
	if (!lp_fresh_invoices_invoice_can_drive_order($invoice, $order)) {
		return $order;
	}

	$invoice_total_amount = lp_fresh_invoices_get_invoice_total_amount_from_data($data, $order, $invoice);
	if ($invoice_total_amount <= 0) {
		return $order;
	}

	$rows = lp_fresh_invoices_build_order_rows_from_invoice($order, $invoice);
	$order->total = lp_fresh_invoices_money($invoice_total_amount);

	if (!empty($rows['subtotal']['raw_value'])) {
		$order->subtotal = lp_fresh_invoices_money((float) $rows['subtotal']['raw_value']);
	}

	$order->price_breakdown = wp_json_encode($rows);
	$order->payment_status = lp_fresh_invoices_payment_status_for_total($order, $invoice_total_amount);

	if ($save && method_exists($order, 'update_attributes')) {
		$order->update_attributes([
			'total'           => $order->total,
			'subtotal'        => $order->subtotal,
			'price_breakdown' => $order->price_breakdown,
			'payment_status'  => $order->payment_status,
		]);
	}

	return $order;
}

function lp_fresh_invoices_build_data($invoice, $order): array {
	$invoice_amount = round((float) $invoice->charge_amount, 4);
	$rows = lp_fresh_invoices_recalculate_order_rows($order);
	$order_total = lp_fresh_invoices_order_total_from_rows($rows, $order);
	$order_subtotal = lp_fresh_invoices_order_subtotal_from_rows($rows, $order);

	$rows = lp_fresh_invoices_add_adjustment_row($rows, $order_total, $invoice_amount);
	$rows = lp_fresh_invoices_set_total_row($rows, $invoice_amount);

	if (class_exists('OsInvoicesHelper')) {
		$data = OsInvoicesHelper::generate_invoice_data_from_order($order);
	} else {
		$data = [];
	}

	if (!is_array($data)) {
		$data = [];
	}

	$data['price_breakdown'] = $rows;
	$data['totals'] = [
		'subtotal' => lp_fresh_invoices_money($order_subtotal),
		'total'    => lp_fresh_invoices_money($invoice_amount),
	];

	$data['lp_fresh_invoice'] = [
		'order_total_at_refresh' => lp_fresh_invoices_money($order_total),
		'invoice_amount'         => lp_fresh_invoices_money($invoice_amount),
		'payable_amount'         => lp_fresh_invoices_money($invoice_amount),
		'adjustment_amount'      => lp_fresh_invoices_money($invoice_amount - $order_total),
		'refreshed_at'           => current_time('mysql', true),
	];

	// Compatibility with LatePoint Invoice Tips: its summary/payment flow uses this
	// stored base amount when calculating the customer-selected tip.
	$data['lp_invoice_tip_base_charge_amount'] = lp_fresh_invoices_money($invoice_amount);

	return $data;
}

function lp_fresh_invoices_refresh_invoice($invoice): bool {
	static $is_refreshing = false;

	if ($is_refreshing || !lp_fresh_invoices_is_editable_invoice($invoice)) {
		return false;
	}

	$order = lp_fresh_invoices_get_order($invoice);
	if (!$order) {
		return false;
	}

	$is_refreshing = true;
	lp_fresh_invoices_sync_charge_amount_from_data($invoice);

	if (lp_fresh_invoices_has_selected_invoice_tip($invoice)) {
		lp_fresh_invoices_sync_open_transaction_intents($invoice);
		$is_refreshing = false;
		return true;
	}

	$data = lp_fresh_invoices_build_data($invoice, $order);
	$updated = method_exists($invoice, 'update_attributes')
		? $invoice->update_attributes(['data' => wp_json_encode($data)])
		: false;
	lp_fresh_invoices_sync_open_transaction_intents($invoice);
	$is_refreshing = false;

	return (bool) $updated;
}

function lp_fresh_invoices_get_request_route_name(): string {
	if (isset($_REQUEST['route_name'])) {
		return sanitize_text_field(wp_unslash($_REQUEST['route_name']));
	}

	return '';
}

function lp_fresh_invoices_get_request_key(): string {
	if (isset($_REQUEST['key'])) {
		return sanitize_text_field(wp_unslash($_REQUEST['key']));
	}

	if (class_exists('OsParamsHelper')) {
		$key = OsParamsHelper::get_param('key');
		return is_scalar($key) ? sanitize_text_field((string) $key) : '';
	}

	return '';
}

function lp_fresh_invoices_refresh_by_request_key(): void {
	$route_name = lp_fresh_invoices_get_request_route_name();
	if (!in_array($route_name, ['invoices__summary_before_payment', 'invoices__payment_form', 'invoices__view_by_key'], true)) {
		return;
	}

	if (!class_exists('OsInvoicesHelper')) {
		return;
	}

	$key = lp_fresh_invoices_get_request_key();
	if ($key === '') {
		return;
	}

	$invoice = OsInvoicesHelper::get_invoice_by_key($key);
	if ($invoice && (!method_exists($invoice, 'is_new_record') || !$invoice->is_new_record())) {
		lp_fresh_invoices_refresh_invoice($invoice);
	}
}

// Priority 8 runs before LatePoint Invoice Tips routes, and priority 50 runs after its order/transaction filters.
add_action('latepoint_invoice_created', 'lp_fresh_invoices_refresh_invoice', 8, 1);
add_action('latepoint_invoice_updated', function($invoice, $old_invoice = null): void {
	lp_fresh_invoices_refresh_invoice($invoice);
}, 8, 2);

add_action('admin_post_latepoint_route_call', 'lp_fresh_invoices_refresh_by_request_key', 8);
add_action('admin_post_nopriv_latepoint_route_call', 'lp_fresh_invoices_refresh_by_request_key', 8);
add_action('wp_ajax_latepoint_route_call', 'lp_fresh_invoices_refresh_by_request_key', 8);
add_action('wp_ajax_nopriv_latepoint_route_call', 'lp_fresh_invoices_refresh_by_request_key', 8);

add_filter('latepoint_order_reload_price_breakdown', function($order) {
	$invoice = lp_fresh_invoices_get_invoice_for_order_breakdown($order);
	if (!$invoice) {
		return $order;
	}

	return lp_fresh_invoices_apply_invoice_totals_to_order($order, $invoice, false);
}, 50, 1);

add_filter('latepoint_order_price_breakdown_rows', function(array $rows, $order, array $rows_to_hide, bool $force_recalculate = false): array {
	if (!empty($GLOBALS['lp_fresh_invoices_building_order_rows'])) {
		return $rows;
	}

	$invoice = lp_fresh_invoices_get_invoice_for_order_breakdown($order);
	if (!$invoice) {
		return $rows;
	}

	$order = lp_fresh_invoices_apply_invoice_totals_to_order($order, $invoice, false);

	$data = lp_fresh_invoices_decode_data($invoice);
	if (!lp_fresh_invoices_invoice_can_drive_order($invoice, $order)) {
		return $rows;
	}

	$invoice_total_amount = lp_fresh_invoices_get_invoice_total_amount_from_data($data, $order, $invoice);
	if ($invoice_total_amount <= 0) {
		return $rows;
	}

	$rows = lp_fresh_invoices_build_order_rows_from_invoice($order, $invoice);
	$rows = lp_fresh_invoices_set_total_row($rows, $invoice_total_amount);

	return $rows;
}, 50, 4);

add_action('latepoint_order_updated', function($order, $old_order = null): void {
	static $normalizing_order = false;

	if ($normalizing_order) {
		return;
	}

	$invoice = lp_fresh_invoices_get_invoice_for_order_breakdown($order);
	if (!$invoice) {
		return;
	}

	$normalizing_order = true;
	lp_fresh_invoices_apply_invoice_totals_to_order($order, $invoice, true);
	$normalizing_order = false;
}, 50, 2);

add_action('latepoint_transaction_created', function($transaction): void {
	if (!$transaction || empty($transaction->invoice_id) || empty($transaction->order_id) || !class_exists('OsInvoiceModel') || !class_exists('OsOrderModel')) {
		return;
	}

	$invoice = new OsInvoiceModel((int) $transaction->invoice_id);
	$order = new OsOrderModel((int) $transaction->order_id);

	if ($invoice && $order && (!method_exists($invoice, 'is_new_record') || !$invoice->is_new_record()) && (!method_exists($order, 'is_new_record') || !$order->is_new_record())) {
		lp_fresh_invoices_apply_invoice_totals_to_order($order, $invoice, true);
	}
}, 50, 1);
