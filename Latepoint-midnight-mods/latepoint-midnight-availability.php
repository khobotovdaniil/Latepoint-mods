<?php
/**
 * Plugin Name: LatePoint Midnight Availability Fix
 * Description: Normalizes LatePoint booked periods that cross midnight so next-day 00:00 slots are blocked correctly.
 *
 * LatePoint builds availability and admin timelines mainly from same-day booking periods.
 * A booking that starts before midnight and ends after midnight can therefore block only
 * the first day, leaving the next day's 00:00 slots available and hiding the visual tail
 * in some admin views. This plugin splits cross-midnight booking periods into day-local
 * segments, applies those segments to frontend/admin availability checks, removes 24:00+
 * admin slots, and renders missing midnight tails in the admin calendar/dashboard.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Returns the number of minutes in a calendar day.
 */
function lp_midnight_availability_day_minutes(): int {
	return 24 * 60;
}

/**
 * Checks whether this plugin should write diagnostic messages to the WordPress debug log.
 */
function lp_midnight_availability_debug_enabled(): bool {
	return (defined('LP_MIDNIGHT_AVAILABILITY_DEBUG') && LP_MIDNIGHT_AVAILABILITY_DEBUG);
}

/**
 * Writes a namespaced debug-log message when midnight availability debug mode is enabled.
 */
function lp_midnight_availability_debug_log(string $message, array $context = []): void {
	if (!lp_midnight_availability_debug_enabled()) {
		return;
	}

	error_log('[LP Midnight Availability] ' . $message . ($context ? ' ' . wp_json_encode($context) : ''));
}

/**
 * Adds or subtracts whole days from a Y-m-d date string.
 */
function lp_midnight_availability_date_plus_days(string $date, int $days): string {
	try {
		$date_object = new DateTime($date);
		$date_object->modify(($days >= 0 ? '+' : '') . $days . ' day');
		return $date_object->format('Y-m-d');
	} catch (Exception $e) {
		return $date;
	}
}

/**
 * Builds a stable identity key for comparing booked-period objects.
 */
function lp_midnight_availability_period_key($period): string {
	return implode('|', [
		(string) ($period->start_date ?? ''),
		(string) ($period->end_date ?? ''),
		(string) ($period->start_time ?? ''),
		(string) ($period->end_time ?? ''),
		(string) ($period->agent_id ?? ''),
		(string) ($period->service_id ?? ''),
		(string) ($period->location_id ?? ''),
	]);
}

/**
 * Detects whether a booking or booked period continues into the next calendar day.
 */
function lp_midnight_availability_period_crosses_midnight($period): bool {
	$start_date = (string) ($period->start_date ?? '');
	$end_date = (string) ($period->end_date ?? '');
	$start_time = (int) ($period->start_time ?? 0);
	$end_time = (int) ($period->end_time ?? 0);

	if ($start_date && $end_time > lp_midnight_availability_day_minutes()) {
		return true;
	}

	if ($start_date && $end_date && $start_date !== $end_date) {
		return true;
	}

	return $start_date && $start_time > 0 && $end_time <= $start_time;
}

/**
 * Clones a booked period and rewrites it as a single-day segment.
 */
function lp_midnight_availability_clone_period($period, string $date, int $start_time, int $end_time) {
	$clone = clone $period;
	$clone->start_date = $date;
	$clone->end_date = $date;
	$clone->start_time = max(0, $start_time);
	$clone->end_time = min(lp_midnight_availability_day_minutes(), max(0, $end_time));

	return $clone;
}

/**
 * Splits a cross-midnight period into one segment per affected calendar day.
 */
function lp_midnight_availability_normalize_period($period): array {
	$day_minutes = lp_midnight_availability_day_minutes();
	$start_date = (string) ($period->start_date ?? '');
	$end_date = (string) ($period->end_date ?? '');
	$start_time = (int) ($period->start_time ?? 0);
	$end_time = (int) ($period->end_time ?? 0);

	if (!$start_date || !lp_midnight_availability_period_crosses_midnight($period)) {
		return [$period];
	}

	if ($end_time > $day_minutes || !$end_date || $end_date === $start_date) {
		$end_date = lp_midnight_availability_date_plus_days($start_date, 1);
	}

	$segments = [];
	$segments[] = lp_midnight_availability_clone_period($period, $start_date, $start_time, $day_minutes);

	// Some LatePoint saves exact-midnight endings as 1440 on the next date. Treat that as 00:00, not a full extra day.
	$tail_end_time = $end_time >= $day_minutes ? ($end_time - $day_minutes) : $end_time;
	if ($tail_end_time > 0) {
		$segments[] = lp_midnight_availability_clone_period($period, $end_date, 0, $tail_end_time);
	}

	return $segments;
}

/**
 * Adds a booked period to a list only when the same segment is not already present.
 */
function lp_midnight_availability_add_unique_period(array &$periods, $period): void {
	$key = lp_midnight_availability_period_key($period);
	foreach ($periods as $existing_period) {
		if (lp_midnight_availability_period_key($existing_period) === $key) {
			return;
		}
	}

	$periods[] = $period;
}

/**
 * Loads previous-day bookings that may create a midnight tail on the requested day.
 */
function lp_midnight_availability_get_previous_day_periods($filter): array {
	if (
		!class_exists('OsBookingHelper') ||
		!class_exists('\LatePoint\Misc\Filter') ||
		!class_exists('\LatePoint\Misc\BookedPeriod') ||
		empty($filter->date_from)
	) {
		return [];
	}

	$previous_day = lp_midnight_availability_date_plus_days((string) $filter->date_from, -1);
	$previous_filter = clone $filter;
	$previous_filter->date_from = $previous_day;
	$previous_filter->date_to = $previous_day;

	$bookings = OsBookingHelper::get_bookings($previous_filter, true);
	$periods = [];

	foreach ($bookings as $booking) {
		$period = \LatePoint\Misc\BookedPeriod::create_from_booking_model($booking);
		if (lp_midnight_availability_period_crosses_midnight($period)) {
			$periods[] = $period;
		}
	}

	return $periods;
}

/**
 * LatePoint groups booked periods by start_date, so a previous-day booking that ends after midnight
 * can be invisible while checking the next day's 00:00 slots. Split those bookings into day-local
 * segments before resources build their slots.
 */
function lp_midnight_availability_normalize_booked_periods(array $booked_periods, $filter): array {
	$periods_to_normalize = $booked_periods;

	foreach (lp_midnight_availability_get_previous_day_periods($filter) as $previous_day_period) {
		lp_midnight_availability_add_unique_period($periods_to_normalize, $previous_day_period);
	}

	$normalized_periods = [];
	foreach ($periods_to_normalize as $period) {
		foreach (lp_midnight_availability_normalize_period($period) as $normalized_period) {
			lp_midnight_availability_add_unique_period($normalized_periods, $normalized_period);
		}
	}

	if (count($normalized_periods) !== count($booked_periods)) {
		lp_midnight_availability_debug_log('Normalized booked periods.', [
			'date_from' => $filter->date_from ?? null,
			'date_to' => $filter->date_to ?? null,
			'original_count' => count($booked_periods),
			'normalized_count' => count($normalized_periods),
		]);
	}

	return $normalized_periods;
}
add_filter('latepoint_get_booked_periods', 'lp_midnight_availability_normalize_booked_periods', 20, 2);

/**
 * Checks whether a booked period belongs to the same agent, service, and location resource.
 */
function lp_midnight_availability_period_matches_resource($period, $resource): bool {
	$period_agent_id = (int) ($period->agent_id ?? 0);
	$period_service_id = (int) ($period->service_id ?? 0);
	$period_location_id = (int) ($period->location_id ?? 0);

	return (
		$period_agent_id === (int) ($resource->agent_id ?? 0) &&
		$period_service_id === (int) ($resource->service_id ?? 0) &&
		$period_location_id === (int) ($resource->location_id ?? 0)
	);
}

/**
 * Checks whether two minute ranges overlap.
 */
function lp_midnight_availability_period_overlaps(int $first_start, int $first_end, int $second_start, int $second_end): bool {
	return $first_start < $second_end && $second_start < $first_end;
}

/**
 * Resolves a slot duration from service duration, neighboring slots, or resource interval.
 */
function lp_midnight_availability_get_slot_duration($resource, int $slot_index, int $fallback_duration): int {
	if ($fallback_duration > 0) {
		return $fallback_duration;
	}

	if (isset($resource->slots[$slot_index + 1])) {
		$gap = (int) $resource->slots[$slot_index + 1]->start_time - (int) $resource->slots[$slot_index]->start_time;
		if ($gap > 0) {
			return $gap;
		}
	}

	if (isset($resource->slots[$slot_index - 1])) {
		$gap = (int) $resource->slots[$slot_index]->start_time - (int) $resource->slots[$slot_index - 1]->start_time;
		if ($gap > 0) {
			return $gap;
		}
	}

	if (!empty($resource->timeblock_interval)) {
		return (int) $resource->timeblock_interval;
	}

	return 15;
}

/**
 * Builds one filter for the full date span needed to find tails for grouped resources.
 */
function lp_midnight_availability_crossing_periods_filter_for_days(array $days, $booking_request) {
	if (!class_exists('\LatePoint\Misc\Filter') || empty($days)) {
		return null;
	}

	$days = array_values(array_unique(array_map('strval', $days)));
	sort($days, SORT_STRING);
	$first_day = reset($days);
	$last_day = end($days);

	return new \LatePoint\Misc\Filter([
		'date_from' => lp_midnight_availability_date_plus_days($first_day, -1),
		'date_to' => $last_day,
		'service_id' => $booking_request->service_id ?? 0,
		'agent_id' => $booking_request->agent_id ?? 0,
		'location_id' => $booking_request->location_id ?? 0,
		'statuses' => OsBookingHelper::get_timeslot_blocking_statuses(),
	]);
}

/**
 * Loads cross-midnight segments for all requested days with a single booking query.
 */
function lp_midnight_availability_get_crossing_periods_grouped_for_days(array $days, $booking_request): array {
	if (!class_exists('OsBookingHelper') || !class_exists('\LatePoint\Misc\Filter')) {
		return [];
	}

	$days = array_values(array_unique(array_map('strval', $days)));
	sort($days, SORT_STRING);
	if (!$days) {
		return [];
	}

	$day_lookup = array_fill_keys($days, true);
	$periods_by_day = array_fill_keys($days, []);
	$filter = lp_midnight_availability_crossing_periods_filter_for_days($days, $booking_request);
	if (!$filter) {
		return $periods_by_day;
	}

	foreach (OsBookingHelper::get_bookings($filter, true) as $booking) {
		$period = \LatePoint\Misc\BookedPeriod::create_from_booking_model($booking);
		if (!lp_midnight_availability_period_crosses_midnight($period)) {
			continue;
		}

		foreach (lp_midnight_availability_normalize_period($period) as $segment) {
			$segment_date = (string) ($segment->start_date ?? '');
			if (isset($day_lookup[$segment_date])) {
				lp_midnight_availability_add_unique_period($periods_by_day[$segment_date], $segment);
			}
		}
	}

	return $periods_by_day;
}

/**
 * Detects admin availability requests that are not tied to one concrete service.
 */
function lp_midnight_availability_is_generic_availability_request($booking_request): bool {
	$service_id = $booking_request->service_id ?? 0;

	return empty($service_id) || $service_id === 0 || $service_id === '0' || is_array($service_id);
}

/**
 * Returns the blocking start minute, including buffer when the period supports it.
 */
function lp_midnight_availability_period_start_with_buffer($period): int {
	if (method_exists($period, 'start_time_with_buffer')) {
		return (int) $period->start_time_with_buffer();
	}

	return (int) ($period->start_time ?? 0);
}

/**
 * Returns the blocking end minute, including buffer when the period supports it.
 */
function lp_midnight_availability_period_end_with_buffer($period): int {
	if (method_exists($period, 'end_time_with_buffer')) {
		return (int) $period->end_time_with_buffer();
	}

	return (int) ($period->end_time ?? 0);
}

/**
 * Blocks admin generic availability slots by comparing them to existing booked/blocked periods.
 */
function lp_midnight_availability_block_generic_resource_slots($resource, $booking_request, int $fallback_duration): int {
	if (!lp_midnight_availability_is_generic_availability_request($booking_request)) {
		return 0;
	}

	$blocking_periods = array_merge(
		$resource->booked_time_periods ?? [],
		$resource->blocked_time_periods ?? []
	);

	if (empty($blocking_periods)) {
		return 0;
	}

	$blocked_slots_count = 0;
	foreach ($resource->slots as $slot_index => $slot) {
		$slot_duration = lp_midnight_availability_get_slot_duration($resource, (int) $slot_index, $fallback_duration);
		$slot_start = (int) ($slot->start_time ?? 0) - (int) ($booking_request->buffer_before ?? 0);
		$slot_end = (int) ($slot->start_time ?? 0) + $slot_duration + (int) ($booking_request->buffer_after ?? 0);

		foreach ($blocking_periods as $period) {
			$period_start = lp_midnight_availability_period_start_with_buffer($period);
			$period_end = lp_midnight_availability_period_end_with_buffer($period);
			if ($period_end <= $period_start) {
				$period_end += lp_midnight_availability_day_minutes();
			}

			if (lp_midnight_availability_period_overlaps($slot_start, $slot_end, $period_start, $period_end)) {
				$slot->booked_capacity = (int) ($slot->max_capacity ?? 1);
				$blocked_slots_count++;
				break;
			}
		}
	}

	return $blocked_slots_count;
}

/**
 * Applies midnight-tail and generic-period blocking to LatePoint's grouped day resources.
 */
function lp_midnight_availability_block_resource_tail_slots(array $daily_resources, $booking_request, $date_from, $date_to, array $settings): array {
	if (!class_exists('OsBookingHelper') || !class_exists('\LatePoint\Misc\Filter')) {
		return $daily_resources;
	}

	$duration = (int) ($booking_request->duration ?? 0);
	if ($duration <= 0 && !empty($booking_request->service_id) && !is_array($booking_request->service_id) && class_exists('OsServiceModel')) {
		$service = new OsServiceModel($booking_request->service_id);
		$duration = (int) ($service->duration ?? 0);
	}

	$days = array_keys(array_filter($daily_resources, function ($resources) {
		return !empty($resources);
	}));
	$crossing_periods_by_day = lp_midnight_availability_get_crossing_periods_grouped_for_days($days, $booking_request);

	foreach ($daily_resources as $day => $resources) {
		if (empty($resources)) {
			continue;
		}

		$crossing_day_periods = $crossing_periods_by_day[(string) $day] ?? [];
		lp_midnight_availability_debug_log('Checked previous-day periods for resource slots.', [
			'day' => $day,
			'previous_periods' => count($crossing_day_periods),
			'resources' => count($resources),
		]);

		if (empty($crossing_day_periods)) {
			lp_midnight_availability_debug_log('No midnight tails found for day.', [
				'day' => $day,
			]);
		}

		$blocked_slots_count = 0;
		$generic_blocked_slots_count = 0;
		foreach ($resources as $resource) {
			$resource->slots = array_values(array_filter($resource->slots, function ($slot) {
				return (int) ($slot->start_time ?? 0) < lp_midnight_availability_day_minutes();
			}));

			$generic_blocked_slots_count += lp_midnight_availability_block_generic_resource_slots($resource, $booking_request, $duration);

			if (empty($crossing_day_periods)) {
				continue;
			}

			foreach ($crossing_day_periods as $crossing_period) {
				if (!lp_midnight_availability_period_matches_resource($crossing_period, $resource)) {
					continue;
				}

				foreach ($resource->slots as $slot_index => $slot) {
					$slot_duration = lp_midnight_availability_get_slot_duration($resource, (int) $slot_index, $duration);
					$slot_start = (int) ($slot->start_time ?? 0) - (int) ($booking_request->buffer_before ?? 0);
					$slot_end = (int) ($slot->start_time ?? 0) + $slot_duration + (int) ($booking_request->buffer_after ?? 0);

					if (lp_midnight_availability_period_overlaps($slot_start, $slot_end, (int) $crossing_period->start_time, (int) $crossing_period->end_time)) {
						$slot->booked_capacity = (int) ($slot->max_capacity ?? 1);
						$blocked_slots_count++;
					}
				}
			}
		}

		lp_midnight_availability_debug_log('Blocked midnight tail slots.', [
			'day' => $day,
			'tails' => count($crossing_day_periods),
			'blocked_slots' => $blocked_slots_count,
			'generic_blocked_slots' => $generic_blocked_slots_count,
		]);
	}

	return $daily_resources;
}
add_filter('latepoint_get_resources_grouped_by_day', 'lp_midnight_availability_block_resource_tail_slots', 30, 5);

/**
 * Checks whether a booking request has a selected date and time, including valid 00:00.
 */
function lp_midnight_availability_booking_has_selected_time($booking): bool {
	return !empty($booking->start_date) && isset($booking->start_time) && $booking->start_time !== '';
}

/**
 * Builds a LatePoint BookingRequest from a booking and fills missing service duration.
 */
function lp_midnight_availability_booking_request_for($booking) {
	if (!class_exists('\LatePoint\Misc\BookingRequest')) {
		return null;
	}

	$booking_request = \LatePoint\Misc\BookingRequest::create_from_booking_model($booking);
	if ((int) ($booking_request->duration ?? 0) <= 0 && !empty($booking->service_id) && class_exists('OsServiceModel')) {
		$service = new OsServiceModel($booking->service_id);
		$booking_request->duration = (int) ($service->duration ?? 0);
	}

	return $booking_request;
}

/**
 * Checks whether the selected time is close enough to midnight to need the expensive corrected availability pass.
 */
function lp_midnight_availability_booking_needs_corrected_filter($booking, $booking_request): bool {
	if (!lp_midnight_availability_booking_has_selected_time($booking)) {
		return false;
	}

	$start_time = (int) ($booking->start_time ?? 0);
	$duration = (int) ($booking_request->duration ?? 0);
	$buffer_after = (int) ($booking_request->buffer_after ?? 0);
	$day_minutes = lp_midnight_availability_day_minutes();
	$early_day_window = 60;

	return $start_time < $early_day_window || ($start_time + $duration + $buffer_after) > $day_minutes;
}

/**
 * Finds available agent/location ids for the selected slot by building LatePoint resources once.
 */
function lp_midnight_availability_available_ids_from_resources(array $ids, $booking, string $id_property): ?array {
	if (!class_exists('OsResourceHelper')) {
		return null;
	}

	$booking_request = lp_midnight_availability_booking_request_for($booking);
	if (!$booking_request || !lp_midnight_availability_booking_needs_corrected_filter($booking, $booking_request)) {
		return $ids;
	}

	$ids = array_values(array_unique(array_map('intval', $ids)));
	if (!$ids) {
		return [];
	}

	$booking_request->{$id_property} = $ids;
	$start_date = (string) ($booking->start_date ?? '');
	$start_time = (int) ($booking->start_time ?? 0);

	try {
		$date = new DateTime($start_date);
	} catch (Exception $e) {
		return null;
	}

	$settings = ['consider_cart_items' => true];
	if (class_exists('OsTimeHelper') && method_exists('OsTimeHelper', 'get_timezone_name_from_session')) {
		$settings['timezone_name'] = OsTimeHelper::get_timezone_name_from_session();
	}

	$resources_by_day = OsResourceHelper::get_resources_grouped_by_day($booking_request, $date, clone $date, $settings);
	$resources = $resources_by_day[$start_date] ?? [];
	$available_ids = [];

	foreach ($resources as $resource) {
		$resource_id = (int) ($resource->{$id_property} ?? 0);
		if (!in_array($resource_id, $ids, true)) {
			continue;
		}

		foreach ($resource->slots ?? [] as $slot) {
			if ((int) ($slot->start_time ?? -1) !== $start_time) {
				continue;
			}
			if (method_exists($slot, 'can_accomodate') && !$slot->can_accomodate((int) ($booking_request->total_attendees ?? 1))) {
				continue;
			}
			$available_ids[] = $resource_id;
			break;
		}
	}

	return array_values(array_intersect($ids, array_values(array_unique($available_ids))));
}

/**
 * Filters candidate agents to only those available for the selected booking time.
 */
function lp_midnight_availability_filter_agent_ids_for_booking(array $agent_ids, $booking): array {
	if (!class_exists('OsBookingHelper') || !lp_midnight_availability_booking_has_selected_time($booking)) {
		return $agent_ids;
	}

	$booking_request = lp_midnight_availability_booking_request_for($booking);
	if (!$booking_request) {
		return $agent_ids;
	}
	if (!lp_midnight_availability_booking_needs_corrected_filter($booking, $booking_request)) {
		return $agent_ids;
	}

	$available_agent_ids = lp_midnight_availability_available_ids_from_resources($agent_ids, $booking, 'agent_id');
	if ($available_agent_ids !== null) {
		return $available_agent_ids;
	}

	$available_agent_ids = [];
	foreach ($agent_ids as $agent_id) {
		$booking_request->agent_id = $agent_id;
		if (OsBookingHelper::is_booking_request_available($booking_request)) {
			$available_agent_ids[] = $agent_id;
		}
	}

	return array_values(array_intersect($available_agent_ids, $agent_ids));
}

/**
 * Filters candidate locations to only those available for the selected booking time.
 */
function lp_midnight_availability_filter_location_ids_for_booking(array $location_ids, $booking): array {
	if (!class_exists('OsBookingHelper') || !lp_midnight_availability_booking_has_selected_time($booking)) {
		return $location_ids;
	}

	$booking_request = lp_midnight_availability_booking_request_for($booking);
	if (!$booking_request) {
		return $location_ids;
	}
	if (!lp_midnight_availability_booking_needs_corrected_filter($booking, $booking_request)) {
		return $location_ids;
	}

	$available_location_ids = lp_midnight_availability_available_ids_from_resources($location_ids, $booking, 'location_id');
	if ($available_location_ids !== null) {
		return $available_location_ids;
	}

	$available_location_ids = [];
	foreach ($location_ids as $location_id) {
		$booking_request->location_id = $location_id;
		if (OsBookingHelper::is_booking_request_available($booking_request)) {
			$available_location_ids[] = $location_id;
		}
	}

	return array_values(array_intersect($available_location_ids, $location_ids));
}

/**
 * Removes unavailable agents from the frontend agent-selection step.
 */
function lp_midnight_availability_filter_agents_step_vars(array $vars, $booking, $cart, string $step_code): array {
	if ($step_code !== 'booking__agents' || empty($vars['agents']) || !is_array($vars['agents']) || !lp_midnight_availability_booking_has_selected_time($booking)) {
		return $vars;
	}

	$agent_ids = [];
	foreach ($vars['agents'] as $agent) {
		if (!empty($agent->id)) {
			$agent_ids[] = $agent->id;
		}
	}

	$available_agent_ids = lp_midnight_availability_filter_agent_ids_for_booking($agent_ids, $booking);
	$vars['agents'] = array_values(array_filter($vars['agents'], function ($agent) use ($available_agent_ids) {
		return in_array($agent->id, $available_agent_ids);
	}));

	lp_midnight_availability_debug_log('Filtered agents step for selected time.', [
		'date' => $booking->start_date ?? null,
		'time' => $booking->start_time ?? null,
		'original_agents' => count($agent_ids),
		'available_agents' => count($available_agent_ids),
	]);

	return $vars;
}
add_filter('latepoint_prepare_step_vars_for_view', 'lp_midnight_availability_filter_agents_step_vars', 20, 4);

/**
 * Filters LatePoint's Any Agent candidate list with the corrected availability check.
 */
function lp_midnight_availability_filter_any_agent_ids(array $agent_ids, $booking): array {
	return lp_midnight_availability_filter_agent_ids_for_booking($agent_ids, $booking);
}
add_filter('latepoint_agent_ids_assignable_to_any_agent_booking', 'lp_midnight_availability_filter_any_agent_ids', 20, 2);

/**
 * Filters LatePoint's Any Location candidate list with the corrected availability check.
 */
function lp_midnight_availability_filter_any_location_ids(array $location_ids, $booking): array {
	return lp_midnight_availability_filter_location_ids_for_booking($location_ids, $booking);
}
add_filter('latepoint_location_ids_assignable_to_any_location_booking', 'lp_midnight_availability_filter_any_location_ids', 20, 2);

/**
 * Compares one value against empty, scalar, or array filter values used by admin views.
 */
function lp_midnight_availability_value_matches_filter($value, $filter_value): bool {
	if (empty($filter_value)) {
		return true;
	}

	if (is_array($filter_value)) {
		return in_array($value, array_map('intval', $filter_value), true);
	}

	return (int) $value === (int) $filter_value;
}

/**
 * Finds previous-day bookings whose midnight tail should be drawn in the admin calendar.
 */
function lp_midnight_availability_get_calendar_tail_bookings(string $day, array $args): array {
	if (!class_exists('OsBookingHelper') || !class_exists('\LatePoint\Misc\Filter')) {
		return [];
	}

	$previous_day = lp_midnight_availability_date_plus_days($day, -1);
	$statuses = class_exists('OsCalendarHelper') ? OsCalendarHelper::get_booking_statuses_to_display_on_calendar() : OsBookingHelper::get_timeslot_blocking_statuses();

	$filter = new \LatePoint\Misc\Filter([
		'date_from' => $previous_day,
		'date_to' => $previous_day,
		'agent_id' => $args['agent_id'] ?? 0,
		'statuses' => $statuses,
	]);

	$bookings = OsBookingHelper::get_bookings($filter, true);
	$tail_bookings = [];

	foreach ($bookings as $booking) {
		lp_midnight_availability_debug_log('Inspecting calendar tail booking.', [
			'day' => $day,
			'id' => $booking->id ?? null,
			'start_date' => $booking->start_date ?? null,
			'end_date' => $booking->end_date ?? null,
			'start_time' => $booking->start_time ?? null,
			'end_time' => $booking->end_time ?? null,
			'agent_id' => $booking->agent_id ?? null,
			'service_id' => $booking->service_id ?? null,
			'location_id' => $booking->location_id ?? null,
			'crosses' => lp_midnight_availability_period_crosses_midnight($booking) ? 1 : 0,
		]);

		if (!lp_midnight_availability_period_crosses_midnight($booking)) {
			continue;
		}
		if (!lp_midnight_availability_value_matches_filter($booking->agent_id, $args['agent_id'] ?? 0)) {
			continue;
		}

		foreach (lp_midnight_availability_normalize_period($booking) as $segment) {
			if (($segment->start_date ?? '') === $day && (int) ($segment->start_time ?? 0) === 0 && (int) ($segment->end_time ?? 0) > 0) {
				$tail_bookings[] = [$booking, (int) $segment->end_time];
			}
		}
	}

	lp_midnight_availability_debug_log('Checked calendar midnight tails.', [
		'day' => $day,
		'agent_id' => $args['agent_id'] ?? null,
		'previous_day' => $previous_day,
		'previous_bookings' => count($bookings),
		'tails' => count($tail_bookings),
	]);

	return $tail_bookings;
}

/**
 * Renders missing next-day midnight tail blocks in the admin calendar day/week timeline.
 */
function lp_midnight_availability_render_calendar_tail($target_date, array $args): void {
	if (!is_object($target_date) || empty($args['work_total_minutes']) || empty($args['agent_id'])) {
		return;
	}

	$day = $target_date->format('Y-m-d');
	$work_start = (int) ($args['work_start_minutes'] ?? 0);
	$work_total = (int) ($args['work_total_minutes'] ?? 0);
	$tail_bookings = lp_midnight_availability_get_calendar_tail_bookings($day, $args);

	foreach ($tail_bookings as [$booking, $tail_end_time]) {
		$visible_start = max(0, $work_start);
		$visible_end = max($visible_start, $tail_end_time);
		if ($visible_end <= $visible_start) {
			continue;
		}

		$top = max(0, ($visible_start - $work_start) / $work_total * 100);
		$height = min(($visible_end - $visible_start) / $work_total * 100, 100 - $top);
		if ($height <= 0) {
			continue;
		}

		$action_html = OsBookingHelper::quick_booking_btn_html($booking->id);
		$bg_color = !empty($booking->service->bg_color) ? $booking->service->bg_color : '#1d7afc';
		$css = 'top: ' . $top . '%; height: ' . $height . '%; background-color: ' . esc_attr($bg_color) . ';';
		$template = OsSettingsHelper::get_booking_template_for_calendar();
		$title = OsReplacerHelper::replace_all_vars($template, [
			'customer' => $booking->customer,
			'agent' => $booking->agent,
			'booking' => $booking,
			'order' => $booking->get_order(),
		]);
		?>
		<div class="ch-day-booking status-<?php echo esc_attr($booking->status); ?> lp-midnight-calendar-tail" <?php echo $action_html; ?> style="<?php echo esc_attr($css); ?>">
			<div class="ch-day-booking-i">
				<div class="booking-service-name"><?php echo wp_kses_post($title); ?></div>
				<div class="booking-time"><?php echo esc_html(OsTimeHelper::minutes_to_hours_and_minutes(0)); ?> - <?php echo esc_html(OsTimeHelper::minutes_to_hours_and_minutes($tail_end_time)); ?></div>
			</div>
		</div>
		<?php
	}

	if ($tail_bookings) {
		lp_midnight_availability_debug_log('Rendered calendar midnight tails.', [
			'day' => $day,
			'agent_id' => $args['agent_id'],
			'tails' => count($tail_bookings),
		]);
	}
}
add_action('latepoint_calendar_daily_timeline', 'lp_midnight_availability_render_calendar_tail', 20, 2);

/**
 * Builds day-local booking segments for the dashboard appointments timeline.
 */
function lp_midnight_availability_get_dashboard_crossing_booking_segments(string $day, array $args): array {
	if (!class_exists('OsBookingHelper') || !class_exists('\LatePoint\Misc\Filter')) {
		return [];
	}

	$segments = [];
	$statuses = class_exists('OsCalendarHelper') ? OsCalendarHelper::get_booking_statuses_to_display_on_calendar() : OsBookingHelper::get_timeslot_blocking_statuses();
	$dates_to_check = [
		lp_midnight_availability_date_plus_days($day, -1),
		$day,
	];

	foreach ($dates_to_check as $date_to_check) {
		$filter = new \LatePoint\Misc\Filter([
			'date_from' => $date_to_check,
			'date_to' => $date_to_check,
			'agent_id' => $args['agent_id'] ?? 0,
			'statuses' => $statuses,
		]);

		foreach (OsBookingHelper::get_bookings($filter, true) as $booking) {
			if (!lp_midnight_availability_period_crosses_midnight($booking)) {
				continue;
			}

			foreach (lp_midnight_availability_normalize_period($booking) as $segment) {
				if (($segment->start_date ?? '') === $day) {
					$segments[] = [$booking, (int) $segment->start_time, (int) $segment->end_time];
				}
			}
		}
	}

	return $segments;
}

/**
 * Returns the display start date from preloaded LatePoint fields or model helper fallback.
 */
function lp_midnight_availability_booking_nice_start_date($booking): string {
	if (!empty($booking->nice_start_date)) {
		return (string) $booking->nice_start_date;
	}

	return method_exists($booking, 'get_nice_start_date') ? (string) $booking->get_nice_start_date() : (string) ($booking->start_date ?? '');
}

/**
 * Returns the display start time from preloaded LatePoint fields or model helper fallback.
 */
function lp_midnight_availability_booking_nice_start_time($booking): string {
	if (!empty($booking->nice_start_time)) {
		return (string) $booking->nice_start_time;
	}

	return method_exists($booking, 'get_nice_start_time') ? (string) $booking->get_nice_start_time() : OsTimeHelper::minutes_to_hours_and_minutes((int) ($booking->start_time ?? 0));
}

/**
 * Returns the display end time from preloaded LatePoint fields or model helper fallback.
 */
function lp_midnight_availability_booking_nice_end_time($booking): string {
	if (!empty($booking->nice_end_time)) {
		return (string) $booking->nice_end_time;
	}

	return method_exists($booking, 'get_nice_end_time') ? (string) $booking->get_nice_end_time() : OsTimeHelper::minutes_to_hours_and_minutes((int) ($booking->end_time ?? 0));
}

/**
 * Renders dashboard appointment content using LatePoint's compact appointment markup shape.
 */
function lp_midnight_availability_render_dashboard_booking_box($booking): void {
	$bg_color = !empty($booking->service->bg_color) ? $booking->service->bg_color : '#1d7afc';
	$service_name = !empty($booking->service->name) ? $booking->service->name : __('Appointment', 'latepoint');
	$customer_avatar = (!empty($booking->customer) && method_exists($booking->customer, 'get_avatar_url')) ? $booking->customer->get_avatar_url() : '';
	$customer_name = !empty($booking->customer->full_name) ? $booking->customer->full_name : '';
	$customer_phone = !empty($booking->customer->phone) ? $booking->customer->phone : '';
	$customer_email = !empty($booking->customer->email) ? $booking->customer->email : '';
	?>
	<div class="appointment-box-small" <?php echo OsBookingHelper::quick_booking_btn_html($booking->id); ?>>
		<div class="appointment-info">
			<div class="appointment-color-elem" style="background-color: <?php echo esc_attr($bg_color); ?>"></div>
			<div class="appointment-service-name"><?php echo esc_html($service_name); ?></div>
			<div class="appointment-time">
				<div class="at-date"><?php echo esc_html(lp_midnight_availability_booking_nice_start_date($booking)); ?>,</div>
				<div class="at-time"><?php echo esc_html(implode('-', [lp_midnight_availability_booking_nice_start_time($booking), lp_midnight_availability_booking_nice_end_time($booking)])); ?></div>
			</div>
		</div>
		<div class="customer-info-w">
			<div class="avatar-w" style="background-image: url(<?php echo esc_url($customer_avatar); ?>);"></div>
			<div class="customer-info">
				<div class="customer-name"><?php echo esc_html($customer_name); ?></div>
				<div class="customer-property">
					<span class="label"><i class="latepoint-icon latepoint-icon-phone-15"></i></span>
					<span class="value"><?php echo esc_html($customer_phone); ?></span>
				</div>
				<div class="customer-property">
					<span class="label"><i class="latepoint-icon latepoint-icon-mail-01"></i></span>
					<span class="value"><?php echo esc_html($customer_email); ?></span>
				</div>
			</div>
		</div>
		<?php $max_capacity = OsServiceHelper::get_max_capacity($booking->service); ?>
		<?php if ($max_capacity > 1) {
			$css_width = min(((max((int) $booking->total_attendees, 1) / $max_capacity) * 100), 100);
			?>
			<div class="appointment-capacity-info">
				<div class="appointment-capacity-info-label">
					<strong><?php echo esc_html(max((int) $booking->total_attendees, 1) . ' ' . __('of', 'latepoint') . ' ' . $max_capacity); ?></strong>
					<span><?php esc_html_e('Slots Booked', 'latepoint'); ?></span>
				</div>
				<div class="appointment-capacity-progress-w">
					<div class="appointment-capacity-progress" style="width: <?php echo esc_attr($css_width); ?>%;"></div>
				</div>
			</div>
		<?php } ?>
	</div>
	<?php
}

/**
 * Draws cross-midnight booking segments that LatePoint's dashboard width calculation skips.
 */
function lp_midnight_availability_render_dashboard_crossing_bookings($target_date, array $args): void {
	if (!is_object($target_date) || empty($args['work_total_minutes']) || empty($args['agent_id'])) {
		return;
	}

	$day = $target_date->format('Y-m-d');
	$work_start = (int) ($args['work_start_minutes'] ?? 0);
	$work_total = (int) ($args['work_total_minutes'] ?? 0);
	$segments = lp_midnight_availability_get_dashboard_crossing_booking_segments($day, $args);

	foreach ($segments as [$booking, $segment_start, $segment_end]) {
		$visible_start = max($segment_start, $work_start);
		$visible_end = min($segment_end, $work_start + $work_total);
		if ($visible_end <= $visible_start) {
			continue;
		}

		$left = ($visible_start - $work_start) / $work_total * 100;
		$width = ($visible_end - $visible_start) / $work_total * 100;
		$action_html = OsBookingHelper::quick_booking_btn_html($booking->id);
		$bg_color = !empty($booking->service->bg_color) ? $booking->service->bg_color : '#1d7afc';

		echo '<div data-start="' . esc_attr($segment_start) . '" data-end="' . esc_attr($segment_end) . '" class="booking-block status-' . esc_attr($booking->status) . ' lp-midnight-dashboard-segment" ' . $action_html . ' style="background-color: ' . esc_attr($bg_color) . '; left: ' . esc_attr($left) . '%; width: ' . esc_attr($width) . '%;">';
		lp_midnight_availability_render_dashboard_booking_box($booking);
		echo '</div>';
	}

	if ($segments) {
		lp_midnight_availability_debug_log('Rendered dashboard midnight booking segments.', [
			'day' => $day,
			'agent_id' => $args['agent_id'],
			'segments' => count($segments),
		]);
	}
}
add_action('latepoint_appointments_timeline', 'lp_midnight_availability_render_dashboard_crossing_bookings', 20, 2);
