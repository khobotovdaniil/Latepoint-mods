<?php
/**
 * Plugin Name: LatePoint Preserve Hidden Customer Fields
 * Description: Prevents customer booking steps from blanking Admin/Agent-only or Temporary Hidden customer custom fields.
 *
 * LatePoint Pro Features collects all customer custom fields on the booking
 * customer step, even fields hidden from customers. When the customer cannot
 * see a field, the browser does not submit a value for it, and the add-on can
 * turn that missing value into an empty custom field. This plugin removes
 * non-public customer fields from the customer-step payload, restores saved
 * values before the customer model is saved, and re-inserts the previous meta
 * value after save if a lower-level LatePoint meta save deleted it.
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!defined('ISU_LATEPOINT_HIDDEN_FIELDS_DEBUG')) {
	define('ISU_LATEPOINT_HIDDEN_FIELDS_DEBUG', false);
}

/**
 * Removes non-public customer custom fields from customer booking-step params.
 *
 * Public fields are left untouched, including unchecked checkboxes that may
 * intentionally submit as empty/off. Admin/agent-only and temporary-hidden
 * fields are not customer-editable, so their existing meta values should not be
 * changed by a customer booking.
 */
function isu_lpcfh_preserve_hidden_customer_fields_on_steps($customer_params, $raw_customer_params) {
	if (empty($customer_params['custom_fields']) || !is_array($customer_params['custom_fields'])) {
		return $customer_params;
	}

	if (!class_exists('OsCustomFieldsHelper')) {
		return $customer_params;
	}

	if (isu_lpcfh_is_staff_context()) {
		return $customer_params;
	}

	$protected_field_ids = isu_lpcfh_get_protected_customer_field_ids();
	if (empty($protected_field_ids)) {
		isu_lpcfh_debug('No protected fields found on customer step.');
		return $customer_params;
	}

	$removed_field_ids = array();
	foreach ($protected_field_ids as $field_id) {
		if (array_key_exists($field_id, $customer_params['custom_fields'])) {
			$removed_field_ids[] = $field_id;
			unset($customer_params['custom_fields'][$field_id]);
		}
	}

	if (empty($customer_params['custom_fields'])) {
		unset($customer_params['custom_fields']);
	}

	if (!empty($removed_field_ids)) {
		isu_lpcfh_debug(
			'Removed protected fields from customer step payload.',
			array('field_ids' => $removed_field_ids)
		);
	}

	return $customer_params;
}
add_filter('latepoint_customer_params_on_steps', 'isu_lpcfh_preserve_hidden_customer_fields_on_steps', 20, 2);

/**
 * Stashes protected customer meta before LatePoint processes a booking step.
 *
 * Customer Information is processed through latepoint_process_step, and some
 * LatePoint paths can update customer fields before model-level hooks give this
 * plugin a useful restore point. This hook captures the current customer meta at
 * the outer step boundary.
 */
function isu_lpcfh_stash_hidden_customer_fields_before_step($step_code, $booking_object, $params = array()) {
	if ($step_code !== 'customer' || isu_lpcfh_is_staff_context()) {
		return;
	}

	$customer_id = isu_lpcfh_get_current_customer_id_for_step($params);
	isu_lpcfh_debug(
		'Processing booking step.',
		array(
			'step_code'   => $step_code,
			'customer_id' => $customer_id,
			'has_customer_params' => !empty($params['customer']),
			'customer_custom_field_keys' => !empty($params['customer']['custom_fields']) && is_array($params['customer']['custom_fields'])
				? array_keys($params['customer']['custom_fields'])
				: array(),
		)
	);

	if ($customer_id) {
		isu_lpcfh_stash_customer_meta($customer_id, 'process_step_before');
	}
}
add_action('latepoint_process_step', 'isu_lpcfh_stash_hidden_customer_fields_before_step', 1, 3);

/**
 * Restores protected customer meta after all LatePoint process-step callbacks finish.
 */
function isu_lpcfh_restore_hidden_customer_fields_after_step($step_code, $booking_object, $params = array()) {
	if ($step_code !== 'customer' || isu_lpcfh_is_staff_context()) {
		return;
	}

	$customer_id = isu_lpcfh_get_current_customer_id_for_step($params);
	if ($customer_id) {
		isu_lpcfh_restore_stashed_customer_meta($customer_id, 'process_step_after');
	}
}
add_action('latepoint_process_step', 'isu_lpcfh_restore_hidden_customer_fields_after_step', 999, 3);

/**
 * Stores existing protected customer meta before Pro Features can blank it.
 *
 * LatePoint custom meta deletes a row when it receives an empty value. The
 * stash gives the plugin a trusted copy to restore from after the customer save
 * finishes, without affecting public custom fields submitted by the customer.
 */
function isu_lpcfh_stash_hidden_customer_fields_before_save($model, $data) {
	if (!is_a($model, 'OsCustomerModel') || $model->is_new_record()) {
		return;
	}

	if (isu_lpcfh_is_staff_context()) {
		return;
	}

	$protected_field_ids = isu_lpcfh_get_protected_customer_field_ids();
	if (empty($protected_field_ids)) {
		return;
	}

	isu_lpcfh_stash_customer_meta((int) $model->id, 'model_set_data');
}
add_action('latepoint_model_set_data', 'isu_lpcfh_stash_hidden_customer_fields_before_save', 1, 2);

/**
 * Restores protected customer field values after LatePoint Pro Features sets model custom_fields.
 *
 * This is the safety layer for the booking customer step: even if another
 * callback has already created an empty hidden/admin field value, the customer
 * model gets the previous database meta value back before latepoint_model_save
 * writes custom fields.
 */
function isu_lpcfh_restore_hidden_customer_fields_before_save($model, $data) {
	if (!is_a($model, 'OsCustomerModel') || empty($data['custom_fields']) || !is_array($data['custom_fields'])) {
		return;
	}

	if (!class_exists('OsCustomFieldsHelper')) {
		return;
	}

	if (isu_lpcfh_is_staff_context()) {
		return;
	}

	$protected_field_ids = isu_lpcfh_get_protected_customer_field_ids();
	if (empty($protected_field_ids)) {
		return;
	}

	if (!isset($model->custom_fields) || !is_array($model->custom_fields)) {
		$model->custom_fields = array();
	}

	foreach ($protected_field_ids as $field_id) {
		if (!$model->is_new_record()) {
			$existing_value = $model->get_meta_by_key($field_id, false);
			if ($existing_value !== false) {
				$model->custom_fields[$field_id] = $existing_value;
				isu_lpcfh_debug(
					'Restored protected field on customer model before save.',
					array(
						'customer_id' => (int) $model->id,
						'field_id'    => $field_id,
					)
				);
				continue;
			}
		}

		unset($model->custom_fields[$field_id]);
	}
}
add_action('latepoint_model_set_data', 'isu_lpcfh_restore_hidden_customer_fields_before_save', 20, 2);

/**
 * Restores protected customer meta if LatePoint deletes it while saving an empty value.
 *
 * Pro Features can call save_meta_by_key() with an empty value. LatePoint handles
 * that as a delete on OsCustomerMetaModel, so this hook catches the deleted meta
 * row directly and writes the previous value back during public booking flows.
 */
function isu_lpcfh_restore_deleted_hidden_customer_meta($model, $deleted_id) {
	if (!is_a($model, 'OsCustomerMetaModel') || empty($model->object_id) || empty($model->meta_key)) {
		return;
	}

	if (isu_lpcfh_is_staff_context()) {
		return;
	}

	$protected_field_ids = isu_lpcfh_get_protected_customer_field_ids();
	if (empty($protected_field_ids) || !in_array($model->meta_key, $protected_field_ids, true)) {
		return;
	}

	$customer_id = (int) $model->object_id;
	$restore_value = null;
	if (isset($GLOBALS['isu_lpcfh_customer_meta_stash'][$customer_id][$model->meta_key])) {
		$restore_value = $GLOBALS['isu_lpcfh_customer_meta_stash'][$customer_id][$model->meta_key];
	} elseif (isset($model->meta_value) && $model->meta_value !== '') {
		$restore_value = $model->meta_value;
	}

	if ($restore_value === null) {
		isu_lpcfh_debug(
			'Protected customer meta was deleted but no restore value was available.',
			array(
				'customer_id' => $customer_id,
				'field_id'    => $model->meta_key,
				'deleted_id'  => (int) $deleted_id,
			)
		);
		return;
	}

	isu_lpcfh_upsert_customer_meta($customer_id, $model->meta_key, $restore_value);
	isu_lpcfh_debug(
		'Restored protected customer meta after delete.',
		array(
			'customer_id' => $customer_id,
			'field_id'    => $model->meta_key,
			'deleted_id'  => (int) $deleted_id,
		)
	);
}
add_action('latepoint_model_deleted', 'isu_lpcfh_restore_deleted_hidden_customer_meta', 999, 2);

/**
 * Rewrites stashed protected meta after the customer model has finished saving.
 *
 * This final guard handles LatePoint's meta behavior where saving an empty
 * value deletes the row. It only runs on the public customer flow; admin and
 * agent edits are intentionally left untouched so staff can change these fields.
 */
function isu_lpcfh_restore_hidden_customer_fields_after_save($model) {
	if (!is_a($model, 'OsCustomerModel') || empty($model->id)) {
		return;
	}

	if (isu_lpcfh_is_staff_context()) {
		return;
	}

	$customer_id = (int) $model->id;
	isu_lpcfh_restore_stashed_customer_meta($customer_id, 'model_save');
}
add_action('latepoint_model_save', 'isu_lpcfh_restore_hidden_customer_fields_after_save', 999);

/**
 * Returns custom field IDs that customers must not be able to overwrite.
 */
function isu_lpcfh_get_protected_customer_field_ids() {
	static $protected_field_ids = null;

	if ($protected_field_ids !== null) {
		return $protected_field_ids;
	}

	$protected_field_ids = array();

	if (!class_exists('OsCustomFieldsHelper')) {
		return $protected_field_ids;
	}

	$custom_fields = OsCustomFieldsHelper::get_custom_fields_arr('customer', 'all');
	if (empty($custom_fields) || !is_array($custom_fields)) {
		return $protected_field_ids;
	}

	foreach ($custom_fields as $custom_field) {
		if (empty($custom_field['id'])) {
			continue;
		}

		$visibility = $custom_field['visibility'] ?? 'public';
		if ($visibility !== 'public') {
			$protected_field_ids[] = $custom_field['id'];
		}
	}

	isu_lpcfh_debug('Loaded protected customer field IDs.', array('field_ids' => $protected_field_ids));

	return $protected_field_ids;
}

/**
 * Detects staff contexts where hidden/admin-only fields should stay editable.
 */
function isu_lpcfh_is_staff_context() {
	return class_exists('OsAuthHelper') && (OsAuthHelper::is_admin_logged_in() || OsAuthHelper::is_agent_logged_in());
}

/**
 * Resolves the current customer ID while a booking step is being processed.
 */
function isu_lpcfh_get_current_customer_id_for_step($params = array()) {
	if (class_exists('OsStepsHelper')) {
		$customer_id = OsStepsHelper::get_customer_object_id();
		if ($customer_id) {
			return (int) $customer_id;
		}
	}

	if (class_exists('OsAuthHelper')) {
		$customer = OsAuthHelper::get_logged_in_customer();
		if ($customer && !empty($customer->id)) {
			return (int) $customer->id;
		}
	}

	if (!empty($params['customer']['id']) && is_numeric($params['customer']['id'])) {
		return (int) $params['customer']['id'];
	}

	return 0;
}

/**
 * Stores current protected customer meta values for a known customer ID.
 */
function isu_lpcfh_stash_customer_meta($customer_id, $source) {
	global $wpdb;

	if (!defined('LATEPOINT_TABLE_CUSTOMER_META') || empty($customer_id)) {
		return;
	}

	$protected_field_ids = isu_lpcfh_get_protected_customer_field_ids();
	if (empty($protected_field_ids)) {
		return;
	}

	$table_name = LATEPOINT_TABLE_CUSTOMER_META;
	foreach ($protected_field_ids as $field_id) {
		$existing_value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$table_name} WHERE object_id = %d AND meta_key = %s LIMIT 1",
				$customer_id,
				$field_id
			)
		);

		if ($existing_value !== null) {
			$GLOBALS['isu_lpcfh_customer_meta_stash'][(int) $customer_id][$field_id] = $existing_value;
		}
	}

	if (!empty($GLOBALS['isu_lpcfh_customer_meta_stash'][(int) $customer_id])) {
		isu_lpcfh_debug(
			'Stashed protected customer meta.',
			array(
				'source'      => $source,
				'customer_id' => (int) $customer_id,
				'field_ids'   => array_keys($GLOBALS['isu_lpcfh_customer_meta_stash'][(int) $customer_id]),
			)
		);
	}
}

/**
 * Restores all stashed protected meta values for a customer ID.
 */
function isu_lpcfh_restore_stashed_customer_meta($customer_id, $source) {
	$customer_id = (int) $customer_id;
	if (empty($GLOBALS['isu_lpcfh_customer_meta_stash'][$customer_id]) || !is_array($GLOBALS['isu_lpcfh_customer_meta_stash'][$customer_id])) {
		isu_lpcfh_debug(
			'No stashed protected customer meta to restore.',
			array(
				'source'      => $source,
				'customer_id' => $customer_id,
			)
		);
		return;
	}

	foreach ($GLOBALS['isu_lpcfh_customer_meta_stash'][$customer_id] as $field_id => $field_value) {
		isu_lpcfh_upsert_customer_meta($customer_id, $field_id, $field_value);
	}

	isu_lpcfh_debug(
		'Restored stashed protected customer meta.',
		array(
			'source'      => $source,
			'customer_id' => $customer_id,
			'field_ids'   => array_keys($GLOBALS['isu_lpcfh_customer_meta_stash'][$customer_id]),
		)
	);
}

/**
 * Inserts or updates a customer meta row without invoking LatePoint's empty-value delete behavior.
 */
function isu_lpcfh_upsert_customer_meta($customer_id, $meta_key, $meta_value) {
	global $wpdb;

	if (!defined('LATEPOINT_TABLE_CUSTOMER_META') || empty($customer_id) || $meta_key === '') {
		return false;
	}

	$table_name = LATEPOINT_TABLE_CUSTOMER_META;
	$now = current_time('mysql');
	$existing_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table_name} WHERE object_id = %d AND meta_key = %s LIMIT 1",
			$customer_id,
			$meta_key
		)
	);

	if ($existing_id) {
		return $wpdb->update(
			$table_name,
			array(
				'meta_value' => $meta_value,
				'updated_at' => $now,
			),
			array('id' => (int) $existing_id),
			array('%s', '%s'),
			array('%d')
		);
	}

	return $wpdb->insert(
		$table_name,
		array(
			'object_id' => $customer_id,
			'meta_key' => $meta_key,
			'meta_value' => $meta_value,
			'created_at' => $now,
			'updated_at' => $now,
		),
		array('%d', '%s', '%s', '%s', '%s')
	);
}

/**
 * Writes optional debug markers when ISU_LATEPOINT_HIDDEN_FIELDS_DEBUG is enabled.
 */
function isu_lpcfh_debug($message, $context = array()) {
	$is_enabled = apply_filters(
		'isu_latepoint_hidden_fields_debug_enabled',
		defined('ISU_LATEPOINT_HIDDEN_FIELDS_DEBUG') && ISU_LATEPOINT_HIDDEN_FIELDS_DEBUG
	);

	if (!$is_enabled) {
		return;
	}

	$line = 'LP Hidden Customer Fields ' . $message . ' ' . wp_json_encode($context);
	error_log($line);

	if (class_exists('OsDebugHelper')) {
		OsDebugHelper::log('LP Hidden Customer Fields ' . $message, 'isu_hidden_customer_fields', $context);
	}
}
