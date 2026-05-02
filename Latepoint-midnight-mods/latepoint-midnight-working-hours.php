<?php
/**
 * Plugin Name: LatePoint Midnight Working Hours Bridge
 * Description: Allows late-day slots to cross midnight only when the next day starts with matching availability.
 *
 * LatePoint normally requires a booking to fully fit inside the current day's work period.
 * That means a 23:30-00:30 service is not offered when the agent/service/location work
 * period ends at 24:00. The old workaround was to extend work hours to 25:00, but that
 * creates invalid 24:00+ slots and confusing admin timelines. This plugin clamps all
 * work periods to 24:00 for display and normal slot generation, then adds only the
 * missing pre-midnight start slots whose tail is covered by the first hour of the next
 * day for the same agent, service, and location resource.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Returns the number of minutes in a calendar day.
 */
function lp_midnight_hours_day_minutes(): int {
	return 24 * 60;
}

/**
 * Returns the maximum next-day tail that can be bridged from a previous-day slot.
 */
function lp_midnight_hours_bridge_minutes(): int {
	return 60;
}

/**
 * Returns the earliest minute LatePoint may use as a practical end-of-day marker.
 */
function lp_midnight_hours_day_end_threshold(): int {
	return lp_midnight_hours_day_minutes() - 1;
}

/**
 * Checks whether this plugin should write diagnostic messages to the WordPress debug log.
 */
function lp_midnight_hours_debug_enabled(): bool {
	return defined('LP_MIDNIGHT_HOURS_DEBUG') && LP_MIDNIGHT_HOURS_DEBUG;
}

/**
 * Checks whether detailed per-candidate diagnostics should be written to the debug log.
 */
function lp_midnight_hours_verbose_debug_enabled(): bool {
	return defined('LP_MIDNIGHT_HOURS_VERBOSE_DEBUG') && LP_MIDNIGHT_HOURS_VERBOSE_DEBUG;
}

/**
 * Writes a namespaced debug-log message when midnight working-hours debug mode is enabled.
 */
function lp_midnight_hours_debug_log(string $message, array $context = []): void {
	if (!lp_midnight_hours_debug_enabled()) {
		return;
	}

	error_log('[LP Midnight Hours] ' . $message . ($context ? ' ' . wp_json_encode($context) : ''));
}

/**
 * Returns the LatePoint work periods table name when LatePoint constants are available.
 */
function lp_midnight_hours_work_periods_table_name(): string {
	if (defined('LATEPOINT_TABLE_WORK_PERIODS')) {
		return LATEPOINT_TABLE_WORK_PERIODS;
	}

	global $wpdb;

	return $wpdb->prefix . 'latepoint_work_periods';
}

/**
 * Rewrites work-period SELECT queries so LatePoint receives runtime-clamped work hours.
 */
function lp_midnight_hours_clamp_work_period_select_query(string $query): string {
	$table_name = lp_midnight_hours_work_periods_table_name();
	if (!$table_name) {
		return $query;
	}

	$normalized_query = preg_replace('/\s+/', ' ', trim($query));
	$select_star_from_table = 'SELECT * FROM ' . $table_name;
	if (stripos($normalized_query, $select_star_from_table) !== 0) {
		return $query;
	}

	$select_fields = implode(', ', [
		'id',
		'service_id',
		'agent_id',
		'location_id',
		'LEAST(GREATEST(CAST(start_time AS SIGNED), 0), 1440) AS start_time',
		'LEAST(GREATEST(CAST(end_time AS SIGNED), 0), 1440) AS end_time',
		'week_day',
		'custom_date',
		'chain_id',
		'updated_at',
		'created_at',
	]);

	return preg_replace('/^\s*SELECT\s+\*\s+FROM\s+' . preg_quote($table_name, '/') . '/i', 'SELECT ' . $select_fields . ' FROM ' . $table_name, $query, 1);
}
add_filter('query', 'lp_midnight_hours_clamp_work_period_select_query', 20);

/**
 * Adds or subtracts whole days from a Y-m-d date string.
 */
function lp_midnight_hours_date_plus_days(string $date, int $days): string {
	try {
		$date_object = new DateTime($date);
		$date_object->modify(($days >= 0 ? '+' : '') . $days . ' day');
		return $date_object->format('Y-m-d');
	} catch (Exception $e) {
		return $date;
	}
}

/**
 * Checks whether two minute ranges overlap.
 */
function lp_midnight_hours_ranges_overlap(int $first_start, int $first_end, int $second_start, int $second_end): bool {
	return $first_start < $second_end && $second_start < $first_end;
}

/**
 * Builds a stable resource key for matching same agent/service/location across adjacent days.
 */
function lp_midnight_hours_resource_key($resource): string {
	return implode('|', [
		(int) ($resource->agent_id ?? 0),
		(int) ($resource->service_id ?? 0),
		(int) ($resource->location_id ?? 0),
	]);
}

/**
 * Returns the first work-period start minute so generated bridge slots keep LatePoint's interval grid.
 */
function lp_midnight_hours_first_work_start($resource): int {
	$starts = [];
	foreach ($resource->work_time_periods ?? [] as $period) {
		$starts[] = (int) ($period->start_time ?? 0);
	}

	return $starts ? min($starts) : 0;
}

/**
 * Resolves the actual slot grid from already-built slots before falling back to service interval.
 */
function lp_midnight_hours_slot_interval($resource): int {
	$previous_start = null;
	$gaps = [];

	foreach ($resource->slots ?? [] as $slot) {
		$start_time = (int) ($slot->start_time ?? 0);
		if ($start_time >= lp_midnight_hours_day_minutes()) {
			continue;
		}
		if ($previous_start !== null) {
			$gap = $start_time - $previous_start;
			if ($gap > 0) {
				$gaps[] = $gap;
			}
		}
		$previous_start = $start_time;
	}

	if ($gaps) {
		return max(1, min($gaps));
	}

	if (method_exists($resource, 'get_timeblock_interval')) {
		$interval = (int) $resource->get_timeblock_interval();
		if ($interval > 0) {
			return $interval;
		}
	}

	return 15;
}

/**
 * Clamps work periods at 24:00 and drops empty/invalid periods.
 */
function lp_midnight_hours_clamp_work_periods($resource): int {
	$clamped_count = 0;
	$clamped_periods = [];

	foreach ($resource->work_time_periods ?? [] as $period) {
		$start_time = max(0, (int) ($period->start_time ?? 0));
		$raw_end_time = max(0, (int) ($period->end_time ?? 0));
		$end_time = ($raw_end_time >= lp_midnight_hours_day_end_threshold()) ? lp_midnight_hours_day_minutes() : min(lp_midnight_hours_day_minutes(), $raw_end_time);
		if ($end_time <= $start_time) {
			continue;
		}
		if ($raw_end_time !== $end_time || (int) ($period->start_time ?? 0) !== $start_time) {
			$clamped_count++;
		}

		$period->start_time = $start_time;
		$period->end_time = $end_time;
		$clamped_periods[] = $period;
	}

	$resource->work_time_periods = $clamped_periods;

	return $clamped_count;
}

/**
 * Removes generated slots that start at 24:00 or later.
 */
function lp_midnight_hours_remove_after_midnight_slots($resource): int {
	$original_count = count($resource->slots ?? []);
	$resource->slots = array_values(array_filter($resource->slots ?? [], function ($slot) {
		return (int) ($slot->start_time ?? 0) < lp_midnight_hours_day_minutes();
	}));

	return $original_count - count($resource->slots);
}

/**
 * Removes slots that cross midnight so they can be rebuilt only when next-day availability allows them.
 */
function lp_midnight_hours_remove_crossing_slots($resource, $booking_request): int {
	$duration = (int) ($booking_request->duration ?? 0);
	$buffer_after = (int) ($booking_request->buffer_after ?? 0);
	if ($duration <= 0) {
		return 0;
	}

	$day_minutes = lp_midnight_hours_day_minutes();
	$original_count = count($resource->slots ?? []);
	$resource->slots = array_values(array_filter($resource->slots ?? [], function ($slot) use ($duration, $buffer_after, $day_minutes) {
		return ((int) ($slot->start_time ?? 0) + $duration + $buffer_after) <= $day_minutes;
	}));

	return $original_count - count($resource->slots);
}

/**
 * Checks whether merged work periods continuously cover a requested minute range.
 */
function lp_midnight_hours_periods_cover_range(array $periods, int $required_start, int $required_end): bool {
	if ($required_end <= $required_start) {
		return true;
	}

	$normalized_periods = [];
	foreach ($periods as $period) {
		$start_time = max(0, (int) ($period->start_time ?? 0));
		$raw_end_time = max(0, (int) ($period->end_time ?? 0));
		$end_time = ($raw_end_time >= lp_midnight_hours_day_end_threshold()) ? lp_midnight_hours_day_minutes() : min(lp_midnight_hours_day_minutes(), $raw_end_time);
		if ($end_time > $start_time) {
			$normalized_periods[] = [$start_time, $end_time];
		}
	}

	usort($normalized_periods, function (array $first, array $second) {
		return $first[0] <=> $second[0];
	});

	$covered_until = $required_start;
	foreach ($normalized_periods as [$period_start, $period_end]) {
		if ($period_end <= $covered_until) {
			continue;
		}
		if ($period_start > $covered_until) {
			return false;
		}
		$covered_until = max($covered_until, $period_end);
		if ($covered_until >= $required_end) {
			return true;
		}
	}

	return false;
}

/**
 * Returns a booked period start minute including buffer when LatePoint provides that helper.
 */
function lp_midnight_hours_period_start_with_buffer($period): int {
	if (method_exists($period, 'start_time_with_buffer')) {
		return (int) $period->start_time_with_buffer();
	}

	return (int) ($period->start_time ?? 0);
}

/**
 * Returns a booked period end minute including buffer when LatePoint provides that helper.
 */
function lp_midnight_hours_period_end_with_buffer($period): int {
	if (method_exists($period, 'end_time_with_buffer')) {
		return (int) $period->end_time_with_buffer();
	}

	return (int) ($period->end_time ?? 0);
}

/**
 * Calculates how much capacity is already consumed inside a candidate bridge segment.
 */
function lp_midnight_hours_booked_capacity_for_range($resource, int $range_start, int $range_end): int {
	$booked_capacity = 0;

	foreach ($resource->booked_time_periods ?? [] as $booked_period) {
		$period_start = lp_midnight_hours_period_start_with_buffer($booked_period);
		$period_end = lp_midnight_hours_period_end_with_buffer($booked_period);
		if ($period_end <= $period_start) {
			$period_end += lp_midnight_hours_day_minutes();
		}
		if (!lp_midnight_hours_ranges_overlap($range_start, $range_end, $period_start, $period_end)) {
			continue;
		}

		if ((int) ($booked_period->service_id ?? 0) !== (int) ($resource->service_id ?? 0)) {
			return (int) ($resource->max_capacity ?? 1);
		}

		$booked_capacity += (int) ($booked_period->total_attendees ?? 1);
	}

	foreach ($resource->blocked_time_periods ?? [] as $blocked_period) {
		$period_start = (int) ($blocked_period->start_time ?? 0);
		$period_end = (int) ($blocked_period->end_time ?? 0);
		if ($period_end <= $period_start) {
			$period_end += lp_midnight_hours_day_minutes();
		}
		if (lp_midnight_hours_ranges_overlap($range_start, $range_end, $period_start, $period_end)) {
			return (int) ($resource->max_capacity ?? 1);
		}
	}

	return $booked_capacity;
}

/**
 * Checks whether a candidate bridge slot is already present in the resource.
 */
function lp_midnight_hours_slot_exists($resource, int $start_time): bool {
	foreach ($resource->slots ?? [] as $slot) {
		if ((int) ($slot->start_time ?? -1) === $start_time) {
			return true;
		}
	}

	return false;
}

/**
 * Returns the late-day start minutes from a mixed list of minutes or slot objects.
 */
function lp_midnight_hours_late_minutes(array $items): array {
	$minutes = [];
	foreach ($items as $item) {
		$minute = is_object($item) ? (int) ($item->start_time ?? -1) : (int) $item;
		if ($minute >= 1320 && $minute < lp_midnight_hours_day_minutes()) {
			$minutes[] = $minute;
		}
	}

	$minutes = array_values(array_unique($minutes, SORT_NUMERIC));
	sort($minutes, SORT_NUMERIC);

	return $minutes;
}

/**
 * Adds one pre-midnight slot whose remaining duration is covered by next-day availability.
 */
function lp_midnight_hours_add_bridge_slot($resource, $next_day_resource, $booking_request, int $start_time): bool {
	if (!class_exists('\LatePoint\Misc\BookingSlot')) {
		return false;
	}

	$day_minutes = lp_midnight_hours_day_minutes();
	$duration = (int) ($booking_request->duration ?? 0);
	$buffer_before = (int) ($booking_request->buffer_before ?? 0);
	$buffer_after = (int) ($booking_request->buffer_after ?? 0);
	$period_start = $start_time - $buffer_before;
	$period_end = $start_time + $duration + $buffer_after;
	$current_day_end = min($period_end, $day_minutes);
	$next_day_end = $period_end - $day_minutes;

	if ($duration <= 0 || $start_time < 0 || $start_time >= $day_minutes || $next_day_end <= 0 || $next_day_end > lp_midnight_hours_bridge_minutes()) {
		if (lp_midnight_hours_verbose_debug_enabled()) {
			lp_midnight_hours_debug_log('Skipped midnight bridge slot: outside bridge limits.', [
				'date' => $resource->date ?? null,
				'start_time' => $start_time,
				'duration' => $duration,
				'next_day_end' => $next_day_end,
			]);
		}
		return false;
	}
	if (lp_midnight_hours_slot_exists($resource, $start_time)) {
		return false;
	}
	if (!lp_midnight_hours_periods_cover_range($resource->work_time_periods ?? [], max(0, $period_start), $day_minutes)) {
		if (lp_midnight_hours_verbose_debug_enabled()) {
			lp_midnight_hours_debug_log('Skipped midnight bridge slot: current day does not cover tail start.', [
				'date' => $resource->date ?? null,
				'start_time' => $start_time,
				'required_start' => max(0, $period_start),
			]);
		}
		return false;
	}
	if (!$next_day_resource || !lp_midnight_hours_periods_cover_range($next_day_resource->work_time_periods ?? [], 0, $next_day_end)) {
		if (lp_midnight_hours_verbose_debug_enabled()) {
			lp_midnight_hours_debug_log('Skipped midnight bridge slot: next day does not cover tail.', [
				'date' => $resource->date ?? null,
				'start_time' => $start_time,
				'next_day_end' => $next_day_end,
				'has_next_day_resource' => $next_day_resource ? 1 : 0,
				'resource_key' => lp_midnight_hours_resource_key($resource),
			]);
		}
		return false;
	}

	$current_capacity = lp_midnight_hours_booked_capacity_for_range($resource, max(0, $period_start), $current_day_end);
	$next_capacity = lp_midnight_hours_booked_capacity_for_range($next_day_resource, 0, $next_day_end);
	$booked_capacity = max($current_capacity, $next_capacity);

	$slot = new \LatePoint\Misc\BookingSlot();
	$slot->start_date = $resource->date;
	$slot->start_time = $start_time;
	$slot->max_capacity = (int) ($resource->max_capacity ?? 1);
	$slot->min_capacity_to_be_blocked = (int) ($resource->min_capacity_to_be_blocked ?? 1);
	$slot->booked_capacity = $booked_capacity;

	$resource->slots[] = $slot;
	$resource->work_minutes[] = $start_time;

	return true;
}

/**
 * Generates missing late-day starts that cross midnight but are backed by next-day work periods.
 */
function lp_midnight_hours_add_bridge_slots_for_resource($resource, $next_day_resource, $booking_request): int {
	$duration = (int) ($booking_request->duration ?? 0);
	if ($duration <= 0 || $duration > lp_midnight_hours_day_minutes()) {
		return 0;
	}

	$interval = lp_midnight_hours_slot_interval($resource);
	$interval = max(1, $interval);
	$first_start = lp_midnight_hours_first_work_start($resource);
	$added_slots = 0;
	$added_minutes = [];

	foreach ($resource->work_time_periods ?? [] as $period) {
		$period_start = max(0, (int) ($period->start_time ?? 0));
		$raw_end_time = max(0, (int) ($period->end_time ?? 0));
		$period_end = ($raw_end_time >= lp_midnight_hours_day_end_threshold()) ? lp_midnight_hours_day_minutes() : min(lp_midnight_hours_day_minutes(), $raw_end_time);
		if ($period_end < lp_midnight_hours_day_minutes()) {
			continue;
		}

		for ($minute = $period_start; $minute < $period_end; $minute += $interval) {
			if (($minute - $first_start) % $interval !== 0) {
				continue;
			}
			if ($minute + $duration <= $period_end) {
				continue;
			}
			if (lp_midnight_hours_add_bridge_slot($resource, $next_day_resource, $booking_request, $minute)) {
				$added_slots++;
				$added_minutes[] = $minute;
			}
		}
	}

	if ($added_slots > 0) {
		usort($resource->slots, function ($first, $second) {
			return (int) ($first->start_time ?? 0) <=> (int) ($second->start_time ?? 0);
		});
		$resource->work_minutes = array_values(array_unique(array_map('intval', $resource->work_minutes ?? []), SORT_NUMERIC));
		sort($resource->work_minutes, SORT_NUMERIC);
		if (lp_midnight_hours_verbose_debug_enabled()) {
			lp_midnight_hours_debug_log('Added midnight bridge minutes to resource.', [
				'date' => $resource->date ?? null,
				'resource_key' => lp_midnight_hours_resource_key($resource),
				'added_minutes' => $added_minutes,
				'late_work_minutes' => lp_midnight_hours_late_minutes($resource->work_minutes ?? []),
				'late_slot_minutes' => lp_midnight_hours_late_minutes($resource->slots ?? []),
			]);
		}
	}

	return $added_slots;
}

/**
 * Checks whether a resource has any late-day candidate that would need next-day coverage.
 */
function lp_midnight_hours_resource_needs_next_day($resource, $booking_request): bool {
	$duration = (int) ($booking_request->duration ?? 0);
	$buffer_after = (int) ($booking_request->buffer_after ?? 0);
	if ($duration <= 0 || $duration > lp_midnight_hours_day_minutes()) {
		return false;
	}

	$interval = max(1, lp_midnight_hours_slot_interval($resource));
	$first_start = lp_midnight_hours_first_work_start($resource);

	foreach ($resource->work_time_periods ?? [] as $period) {
		$period_start = max(0, (int) ($period->start_time ?? 0));
		$raw_end_time = max(0, (int) ($period->end_time ?? 0));
		$period_end = ($raw_end_time >= lp_midnight_hours_day_end_threshold()) ? lp_midnight_hours_day_minutes() : min(lp_midnight_hours_day_minutes(), $raw_end_time);
		if ($period_end < lp_midnight_hours_day_minutes()) {
			continue;
		}

		for ($minute = $period_start; $minute < $period_end; $minute += $interval) {
			if (($minute - $first_start) % $interval !== 0) {
				continue;
			}
			if ($minute + $duration + $buffer_after > $period_end) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Loads next-day resources for a date range while preventing this filter from recursing forever.
 */
function lp_midnight_hours_get_next_day_resources_grouped(array $days, $booking_request, array $settings): array {
	static $loading_next_day = false;
	static $cache = [];

	$days = array_values(array_unique(array_map('strval', $days)));
	sort($days, SORT_STRING);
	if (!$days || $loading_next_day || !class_exists('OsResourceHelper')) {
		return [];
	}

	$cache_key = md5(wp_json_encode([
		'days' => $days,
		'agent_id' => (int) ($booking_request->agent_id ?? 0),
		'service_id' => (int) ($booking_request->service_id ?? 0),
		'location_id' => (int) ($booking_request->location_id ?? 0),
		'duration' => (int) ($booking_request->duration ?? 0),
		'buffer_before' => (int) ($booking_request->buffer_before ?? 0),
		'buffer_after' => (int) ($booking_request->buffer_after ?? 0),
		'total_attendees' => (int) ($booking_request->total_attendees ?? 0),
		'accessed_from_backend' => !empty($settings['accessed_from_backend']) ? 1 : 0,
		'timezone_name' => $settings['timezone_name'] ?? '',
		'consider_cart_items' => !empty($settings['consider_cart_items']) ? 1 : 0,
	]));
	if (array_key_exists($cache_key, $cache)) {
		return $cache[$cache_key];
	}

	try {
		$loading_next_day = true;
		$next_days = array_map(function (string $day): string {
			return lp_midnight_hours_date_plus_days($day, 1);
		}, $days);
		sort($next_days, SORT_STRING);
		$date_from = new DateTime(reset($next_days));
		$date_to = new DateTime(end($next_days));
		$resources_by_day = OsResourceHelper::get_resources_grouped_by_day($booking_request, $date_from, $date_to, $settings);
		$loading_next_day = false;
		$cache[$cache_key] = $resources_by_day;

		return $cache[$cache_key];
	} catch (Exception $e) {
		$loading_next_day = false;
		$cache[$cache_key] = [];
		return [];
	}
}

/**
 * Summarizes final late-day minutes across all resources for one rendered day.
 */
function lp_midnight_hours_day_debug_summary(array $resources): array {
	$work_minutes = [];
	$slot_minutes = [];
	foreach ($resources as $resource) {
		$work_minutes = array_merge($work_minutes, lp_midnight_hours_late_minutes($resource->work_minutes ?? []));
		$slot_minutes = array_merge($slot_minutes, lp_midnight_hours_late_minutes($resource->slots ?? []));
	}

	$work_minutes = array_values(array_unique($work_minutes, SORT_NUMERIC));
	$slot_minutes = array_values(array_unique($slot_minutes, SORT_NUMERIC));
	sort($work_minutes, SORT_NUMERIC);
	sort($slot_minutes, SORT_NUMERIC);

	return [
		'late_work_minutes' => $work_minutes,
		'late_slot_minutes' => $slot_minutes,
	];
}

/**
 * Clamps 24:00+ work periods and adds valid pre-midnight bridge slots.
 */
function lp_midnight_hours_bridge_resources(array $daily_resources, $booking_request, $date_from, $date_to, array $settings): array {
	$days_requiring_next_day = [];
	$resource_keys_requiring_next_day = [];
	$day_stats = [];

	foreach ($daily_resources as $day => $resources) {
		$day_stats[$day] = [
			'clamped_periods' => 0,
			'removed_slots' => 0,
			'added_slots' => 0,
		];
		$resource_keys_requiring_next_day[$day] = [];

		foreach ($resources as $resource) {
			$day_stats[$day]['clamped_periods'] += lp_midnight_hours_clamp_work_periods($resource);
			$day_stats[$day]['removed_slots'] += lp_midnight_hours_remove_after_midnight_slots($resource);
			$day_stats[$day]['removed_slots'] += lp_midnight_hours_remove_crossing_slots($resource, $booking_request);

			if (lp_midnight_hours_resource_needs_next_day($resource, $booking_request)) {
				$days_requiring_next_day[] = (string) $day;
				$resource_keys_requiring_next_day[$day][lp_midnight_hours_resource_key($resource)] = true;
			}
		}
	}

	$next_day_resources_by_day = [];
	$days_requiring_external_next_day_load = [];
	foreach (array_values(array_unique($days_requiring_next_day)) as $day_requiring_next_day) {
		$next_day = lp_midnight_hours_date_plus_days((string) $day_requiring_next_day, 1);
		if (array_key_exists($next_day, $daily_resources)) {
			$next_day_resources_by_day[$next_day] = $daily_resources[$next_day];
			continue;
		}

		$days_requiring_external_next_day_load[] = (string) $day_requiring_next_day;
	}

	if ($days_requiring_external_next_day_load) {
		$next_day_resources_by_day = array_replace(
			$next_day_resources_by_day,
			lp_midnight_hours_get_next_day_resources_grouped($days_requiring_external_next_day_load, $booking_request, $settings)
		);
	}

	foreach ($daily_resources as $day => $resources) {
		$next_day = lp_midnight_hours_date_plus_days((string) $day, 1);
		$next_day_resources = $next_day_resources_by_day[$next_day] ?? [];
		$resource_keys = $resource_keys_requiring_next_day[$day] ?? [];
		if (!$next_day_resources || !$resource_keys) {
			continue;
		}

		$next_day_resource_map = [];
		foreach ($next_day_resources as $next_day_resource_candidate) {
			$next_day_resource_map[lp_midnight_hours_resource_key($next_day_resource_candidate)] = $next_day_resource_candidate;
		}

		foreach ($resources as $resource) {
			$resource_key = lp_midnight_hours_resource_key($resource);
			if (empty($resource_keys[$resource_key])) {
				continue;
			}

			$next_day_resource = $next_day_resource_map[$resource_key] ?? null;
			if ($next_day_resource) {
				lp_midnight_hours_clamp_work_periods($next_day_resource);
				lp_midnight_hours_remove_after_midnight_slots($next_day_resource);
			}

			$day_stats[$day]['added_slots'] += lp_midnight_hours_add_bridge_slots_for_resource($resource, $next_day_resource, $booking_request);
		}

		if ($day_stats[$day]['added_slots'] || (lp_midnight_hours_verbose_debug_enabled() && ($day_stats[$day]['clamped_periods'] || $day_stats[$day]['removed_slots']))) {
			$summary = lp_midnight_hours_day_debug_summary($resources);
			lp_midnight_hours_debug_log('Adjusted midnight working hours.', [
				'day' => $day,
				'resources' => count($resources),
				'clamped_periods' => $day_stats[$day]['clamped_periods'],
				'removed_after_midnight_slots' => $day_stats[$day]['removed_slots'],
				'added_bridge_slots' => $day_stats[$day]['added_slots'],
				'late_work_minutes' => $summary['late_work_minutes'],
				'late_slot_minutes' => $summary['late_slot_minutes'],
			]);
		}
	}

	return $daily_resources;
}
add_filter('latepoint_get_resources_grouped_by_day', 'lp_midnight_hours_bridge_resources', 25, 5);
