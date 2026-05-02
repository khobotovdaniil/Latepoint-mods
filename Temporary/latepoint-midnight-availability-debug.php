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
	define('ISU_LATEPOINT_DASHBOARD_ARCHIVE_DEBUG', true);
}