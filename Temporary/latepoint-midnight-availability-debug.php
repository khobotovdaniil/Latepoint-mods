<?php
/**
 * Plugin Name: LatePoint Midnight Availability Debug
 * Description: Enables temporary debug logging for the LatePoint midnight availability fix.
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!defined('LP_MIDNIGHT_AVAILABILITY_DEBUG')) {
	define('LP_MIDNIGHT_AVAILABILITY_DEBUG', false);
}

if (!defined('LP_MIDNIGHT_HOURS_DEBUG')) {
	define('LP_MIDNIGHT_HOURS_DEBUG', false);
}
if (!defined('ISU_LATEPOINT_DASHBOARD_ARCHIVE_DEBUG')) {
	define('ISU_LATEPOINT_DASHBOARD_ARCHIVE_DEBUG', false);
}

if (!defined('ISU_LATEPOINT_CUSTOMER_TIMEZONE_DEBUG')) {
	define('ISU_LATEPOINT_CUSTOMER_TIMEZONE_DEBUG', false);
}

if (!defined('ISU_LATEPOINT_HIDDEN_FIELDS_DEBUG')) {
	define('ISU_LATEPOINT_HIDDEN_FIELDS_DEBUG', false);
}

add_filter('isu_latepoint_hidden_fields_debug_enabled', function ($enabled) {
	return defined('ISU_LATEPOINT_HIDDEN_FIELDS_DEBUG') ? ISU_LATEPOINT_HIDDEN_FIELDS_DEBUG : $enabled;
});

// add_filter('isu_latepoint_customer_timezone_debug_enabled', function ($enabled) {
// 	return defined('ISU_LATEPOINT_CUSTOMER_TIMEZONE_DEBUG')
// 		? ISU_LATEPOINT_CUSTOMER_TIMEZONE_DEBUG
// 		: $enabled;
// });
