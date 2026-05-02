<?php
/**
 * Plugin Name: LatePoint Midnight Datetime Normalizer
 * Description: Fixes exact-midnight LatePoint booking end datetimes and add-to-calendar links.
 *
 * LatePoint can save bookings that end exactly at midnight as end_date = next day and
 * end_time = 1440. Later, its datetime helpers add those 1440 minutes to the next day,
 * which turns a 23:00-00:00 booking into a 25-hour Google Calendar event. This plugin
 * recalculates stored booking datetimes from start_date + start_time + duration, saves
 * the corrected UTC fields, and rebuilds add-to-calendar links from the same normalized
 * datetimes. It is intentionally independent from the availability/display plugin.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Checks whether this plugin should write diagnostic messages to the WordPress debug log.
 * It also follows the shared midnight availability debug flag so all midnight fixes can be traced together.
 */
function lp_midnight_datetime_debug_enabled(): bool {
	return (defined('LP_MIDNIGHT_DATETIME_DEBUG') && LP_MIDNIGHT_DATETIME_DEBUG)
		|| (defined('LP_MIDNIGHT_AVAILABILITY_DEBUG') && LP_MIDNIGHT_AVAILABILITY_DEBUG);
}

/**
 * Writes a namespaced debug-log message when midnight datetime debug mode is enabled.
 */
function lp_midnight_datetime_debug_log(string $message, array $context = []): void {
	if (!lp_midnight_datetime_debug_enabled()) {
		return;
	}

	error_log('[LP Midnight Datetime] ' . $message . ($context ? ' ' . wp_json_encode($context) : ''));
}

/**
 * Returns the number of minutes in a calendar day.
 */
function lp_midnight_datetime_day_minutes(): int {
	return 24 * 60;
}

/**
 * Resolves the booking duration from the booking record, LatePoint duration helper, or service fallback.
 */
function lp_midnight_datetime_booking_duration($booking): int {
	if (empty($booking)) {
		return 0;
	}

	if (!empty($booking->duration)) {
		return (int) $booking->duration;
	}

	if (method_exists($booking, 'get_total_duration')) {
		$duration = (int) $booking->get_total_duration();
		if ($duration > 0) {
			return $duration;
		}
	}

	if (!empty($booking->service_id) && class_exists('OsServiceModel')) {
		$service = new OsServiceModel($booking->service_id);
		if (!empty($service->duration)) {
			return (int) $service->duration;
		}
	}

	return 0;
}

/**
 * Builds the expected local start/end DateTime objects from start date, start time, and duration.
 */
function lp_midnight_datetime_expected_datetimes($booking): ?array {
	if (empty($booking->start_date) || !isset($booking->start_time) || !class_exists('OsTimeHelper')) {
		return null;
	}

	$duration = lp_midnight_datetime_booking_duration($booking);
	if ($duration <= 0 || $duration > lp_midnight_datetime_day_minutes()) {
		return null;
	}

	try {
		$start_datetime = new DateTime($booking->start_date . ' 00:00:00', OsTimeHelper::get_wp_timezone());
		$start_time = (int) $booking->start_time;
		if ($start_time > 0) {
			$start_datetime->modify('+' . $start_time . ' minutes');
		}

		$end_datetime = clone $start_datetime;
		$end_datetime->modify('+' . $duration . ' minutes');

		return [$start_datetime, $end_datetime];
	} catch (Exception $e) {
		return null;
	}
}

/**
 * Converts a local DateTime into LatePoint's minutes-from-midnight value.
 */
function lp_midnight_datetime_minutes_from_datetime(DateTime $datetime): int {
	return ((int) $datetime->format('G') * 60) + (int) $datetime->format('i');
}

/**
 * Converts a local DateTime into LatePoint's UTC database datetime format.
 */
function lp_midnight_datetime_utc_db(DateTime $datetime): string {
	$utc_datetime = clone $datetime;
	$utc_datetime->setTimezone(new DateTimeZone('UTC'));

	return $utc_datetime->format(defined('LATEPOINT_DATETIME_DB_FORMAT') ? LATEPOINT_DATETIME_DB_FORMAT : 'Y-m-d H:i:s');
}

/**
 * Normalizes stored booking end fields after LatePoint creates or updates a booking.
 */
function lp_midnight_datetime_normalize_booking($booking, $old_booking = null): void {
	static $normalizing = false;

	if ($normalizing || empty($booking->id)) {
		return;
	}

	$expected_datetimes = lp_midnight_datetime_expected_datetimes($booking);
	if (!$expected_datetimes) {
		return;
	}

	[$start_datetime, $end_datetime] = $expected_datetimes;
	$expected = [
		'end_date' => $end_datetime->format('Y-m-d'),
		'end_time' => lp_midnight_datetime_minutes_from_datetime($end_datetime),
		'start_datetime_utc' => lp_midnight_datetime_utc_db($start_datetime),
		'end_datetime_utc' => lp_midnight_datetime_utc_db($end_datetime),
	];

	$needs_update = false;
	foreach ($expected as $field => $value) {
		if ((string) ($booking->{$field} ?? '') !== (string) $value) {
			$needs_update = true;
			break;
		}
	}

	if (!$needs_update) {
		return;
	}

	global $wpdb;
	if (empty($wpdb)) {
		return;
	}

	$normalizing = true;
	$updated = $wpdb->update(
		$wpdb->prefix . 'latepoint_bookings',
		$expected,
		['id' => (int) $booking->id],
		['%s', '%d', '%s', '%s'],
		['%d']
	);
	$normalizing = false;

	if ($updated === false) {
		lp_midnight_datetime_debug_log('Failed to normalize booking datetimes.', [
			'booking_id' => (int) $booking->id,
			'last_error' => $wpdb->last_error ?? '',
		]);
		return;
	}

	foreach ($expected as $field => $value) {
		$booking->{$field} = $value;
	}

	lp_midnight_datetime_debug_log('Normalized booking stored datetimes.', [
		'booking_id' => (int) $booking->id,
		'start_date' => $booking->start_date ?? null,
		'start_time' => $booking->start_time ?? null,
		'end_date' => $expected['end_date'],
		'end_time' => $expected['end_time'],
		'start_datetime_utc' => $expected['start_datetime_utc'],
		'end_datetime_utc' => $expected['end_datetime_utc'],
	]);
}
// Run early so integrations that react to booking create/update, such as Google Calendar sync, see normalized datetimes.
add_action('latepoint_booking_created', 'lp_midnight_datetime_normalize_booking', 1, 1);
add_action('latepoint_booking_updated', 'lp_midnight_datetime_normalize_booking', 1, 2);

/**
 * Rebuilds Google/Outlook add-to-calendar date parameters from normalized datetimes.
 */
function lp_midnight_datetime_fix_add_to_calendar_params(array $params, string $calendar_type, $booking): array {
	$expected_datetimes = lp_midnight_datetime_expected_datetimes($booking);
	if (!$expected_datetimes) {
		return $params;
	}

	[$start_datetime, $end_datetime] = $expected_datetimes;
	$start_utc = clone $start_datetime;
	$end_utc = clone $end_datetime;
	$start_utc->setTimezone(new DateTimeZone('UTC'));
	$end_utc->setTimezone(new DateTimeZone('UTC'));

	if ($calendar_type === 'google' && isset($params['dates'])) {
		$params['dates'] = $start_utc->format('Ymd\THis\Z') . '/' . $end_utc->format('Ymd\THis\Z');
	}

	if ($calendar_type === 'outlook') {
		$params['startdt'] = $start_utc->format('Y-m-d\TH:i:s\Z');
		$params['enddt'] = $end_utc->format('Y-m-d\TH:i:s\Z');
	}

	return $params;
}
add_filter('latepoint_build_add_to_calendar_link_params', 'lp_midnight_datetime_fix_add_to_calendar_params', 20, 3);
