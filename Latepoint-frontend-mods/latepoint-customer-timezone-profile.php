<?php
/**
 * Plugin Name: ISU LatePoint Customer Timezone Profile Field
 * Description: Adds a customer timezone selector to profile/customer edit forms and saves it to LatePoint customer meta.
 *
 * LatePoint already supports customer timezone selection near the date picker,
 * but customers often miss it. This plugin exposes the same timezone choice in
 * customer profile and admin customer edit forms, stores it in customer meta
 * under LatePoint's native `timezone_name` key, and uses the saved value as the
 * default booking timezone for logged-in customers.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Returns the LatePoint customer meta key used by the core customer timezone API.
 */
function isu_latepoint_customer_timezone_meta_key(): string {
	return 'timezone_name';
}

/**
 * Validates a timezone name against PHP's known timezone identifiers.
 */
function isu_latepoint_customer_timezone_is_valid(string $timezone_name): bool {
	if ($timezone_name === 'UTC') {
		return true;
	}

	return in_array($timezone_name, timezone_identifiers_list(), true);
}

/**
 * Sanitizes a submitted timezone value and falls back to an empty string when invalid.
 */
function isu_latepoint_customer_timezone_sanitize($timezone_name): string {
	$timezone_name = is_string($timezone_name) ? sanitize_text_field(wp_unslash($timezone_name)) : '';

	return isu_latepoint_customer_timezone_is_valid($timezone_name) ? $timezone_name : '';
}

/**
 * Reads timezone from direct requests and LatePoint's encoded AJAX params.
 */
function isu_latepoint_customer_timezone_get_submitted(): string {
	if (isset($_REQUEST['customer']['timezone_name'])) {
		return isu_latepoint_customer_timezone_sanitize($_REQUEST['customer']['timezone_name']);
	}

	if (isset($_REQUEST['timezone_name'])) {
		return isu_latepoint_customer_timezone_sanitize($_REQUEST['timezone_name']);
	}

	if (isset($_REQUEST['params']) && is_string($_REQUEST['params'])) {
		$params = [];
		parse_str(wp_unslash($_REQUEST['params']), $params);

		if (isset($params['customer']['timezone_name'])) {
			return isu_latepoint_customer_timezone_sanitize($params['customer']['timezone_name']);
		}

		if (isset($params['timezone_name'])) {
			return isu_latepoint_customer_timezone_sanitize($params['timezone_name']);
		}
	}

	return '';
}

/**
 * Returns the selected timezone for a customer, falling back to LatePoint/WP timezone.
 */
function isu_latepoint_customer_timezone_selected($customer = null): string {
	if ($customer && method_exists($customer, 'get_meta_by_key')) {
		$saved_timezone = isu_latepoint_customer_timezone_sanitize(
			$customer->get_meta_by_key(isu_latepoint_customer_timezone_meta_key(), '')
		);

		if ($saved_timezone !== '') {
			return $saved_timezone;
		}
	}

	if (class_exists('OsTimeHelper')) {
		return OsTimeHelper::get_timezone_name_from_session();
	}

	return wp_timezone_string() ?: 'UTC';
}

/**
 * Builds a readable label for one timezone option.
 */
function isu_latepoint_customer_timezone_label(string $timezone_name): string {
	try {
		$timezone = new DateTimeZone($timezone_name);
		$now = new DateTimeImmutable('now', $timezone);
		$offset = $now->format('P');
	} catch (Exception $e) {
		$offset = '+00:00';
	}

	return str_replace('_', ' ', $timezone_name) . ' (UTC' . $offset . ')';
}

/**
 * Returns timezone options grouped by continent/region for a plain select field.
 */
function isu_latepoint_customer_timezone_options(): array {
	static $options = null;

	if ($options !== null) {
		return $options;
	}

	$options = [
		'UTC' => 'UTC (UTC+00:00)',
	];

	foreach (timezone_identifiers_list() as $timezone_name) {
		$options[$timezone_name] = isu_latepoint_customer_timezone_label($timezone_name);
	}

	asort($options, SORT_NATURAL | SORT_FLAG_CASE);

	return $options;
}

/**
 * Renders the timezone select with LatePoint-compatible form markup.
 */
function isu_latepoint_customer_timezone_render_field($customer = null, string $context = 'profile'): void {
	$selected = isu_latepoint_customer_timezone_selected($customer);
	$field_id = 'isu_customer_timezone_' . preg_replace('/[^a-z0-9_]+/i', '_', $context);
	?>
	<div class="os-row isu-lp-customer-timezone-row" data-isu-lp-customer-timezone-row="yes">
		<div class="os-col-12">
			<div class="os-form-group os-form-select-group os-form-group-transparent">
				<label for="<?php echo esc_attr($field_id); ?>"><?php esc_html_e('Timezone', 'latepoint'); ?></label>
				<select
					name="customer[timezone_name]"
					id="<?php echo esc_attr($field_id); ?>"
					class="os-form-control isu-lp-customer-timezone-select">
					<?php foreach (isu_latepoint_customer_timezone_options() as $timezone_name => $label) : ?>
						<option value="<?php echo esc_attr($timezone_name); ?>" <?php selected($selected, $timezone_name); ?>>
							<?php echo esc_html($label); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Adds the timezone selector to the customer dashboard Profile tab.
 */
function isu_latepoint_customer_timezone_render_dashboard_field($customer): void {
	isu_latepoint_customer_timezone_render_field($customer, 'dashboard');
}

add_action('latepoint_customer_dashboard_information_form_after', 'isu_latepoint_customer_timezone_render_dashboard_field', 20);

/**
 * Adds the timezone selector to the admin customer quick edit side panel.
 */
function isu_latepoint_customer_timezone_render_admin_field($customer): void {
	isu_latepoint_customer_timezone_render_field($customer, 'admin');
}

add_action('latepoint_customer_edit_form_after', 'isu_latepoint_customer_timezone_render_admin_field', 8);

/**
 * Adds the timezone selector to inline customer editing inside quick order forms.
 */
function isu_latepoint_customer_timezone_render_inline_field($customer): void {
	isu_latepoint_customer_timezone_render_field($customer, 'inline');
}

add_action('latepoint_customer_inline_edit_form_after', 'isu_latepoint_customer_timezone_render_inline_field', 20);

/**
 * Adds the timezone selector to the booking form Customer Information step.
 */
function isu_latepoint_customer_timezone_render_booking_step_field(string $step_code): void {
	if ($step_code !== 'customer') {
		return;
	}

	$customer = class_exists('OsAuthHelper') && OsAuthHelper::is_customer_logged_in()
		? OsAuthHelper::get_logged_in_customer()
		: null;

	isu_latepoint_customer_timezone_render_field($customer, 'booking_step');
}

add_action('latepoint_after_step_content', 'isu_latepoint_customer_timezone_render_booking_step_field', 20);

/**
 * Saves a submitted timezone to the customer meta record.
 */
function isu_latepoint_customer_timezone_save_for_customer($customer): void {
	if (!$customer || !method_exists($customer, 'save_meta_by_key')) {
		return;
	}

	$submitted_timezone = isu_latepoint_customer_timezone_get_submitted();
	if ($submitted_timezone === '') {
		return;
	}

	$customer->save_meta_by_key(isu_latepoint_customer_timezone_meta_key(), $submitted_timezone);

	if (class_exists('OsTimeHelper') && method_exists('OsTimeHelper', 'set_timezone_name_in_cookie')) {
		OsTimeHelper::set_timezone_name_in_cookie($submitted_timezone);
	}
}

add_action('latepoint_customer_created', 'isu_latepoint_customer_timezone_save_for_customer', 20);
add_action('latepoint_customer_updated', 'isu_latepoint_customer_timezone_save_for_customer', 20);

/**
 * Uses the saved customer timezone as LatePoint's default session timezone.
 */
function isu_latepoint_customer_timezone_from_session(string $timezone_name): string {
	$request_timezone = isu_latepoint_customer_timezone_get_submitted();
	if ($request_timezone !== '') {
		return $request_timezone;
	}

	if (defined('LATEPOINT_SELECTED_TIMEZONE_COOKIE') && isset($_COOKIE[LATEPOINT_SELECTED_TIMEZONE_COOKIE])) {
		$cookie_timezone = isu_latepoint_customer_timezone_sanitize($_COOKIE[LATEPOINT_SELECTED_TIMEZONE_COOKIE]);
		if ($cookie_timezone !== '') {
			return $cookie_timezone;
		}
	}

	if (!class_exists('OsAuthHelper') || !OsAuthHelper::is_customer_logged_in()) {
		return $timezone_name;
	}

	$customer = OsAuthHelper::get_logged_in_customer();
	if (!$customer || !method_exists($customer, 'get_meta_by_key')) {
		return $timezone_name;
	}

	$saved_timezone = isu_latepoint_customer_timezone_sanitize(
		$customer->get_meta_by_key(isu_latepoint_customer_timezone_meta_key(), '')
	);

	return $saved_timezone !== '' ? $saved_timezone : $timezone_name;
}

add_filter('latepoint_timezone_name_from_session', 'isu_latepoint_customer_timezone_from_session', 20);

/**
 * Keeps the booking form hidden timezone field and cookie in sync when the new select changes.
 */
function isu_latepoint_customer_timezone_print_script(): void {
	$cookie_name = defined('LATEPOINT_SELECTED_TIMEZONE_COOKIE') ? LATEPOINT_SELECTED_TIMEZONE_COOKIE : 'latepoint_selected_timezone';
	?>
	<script id="isu-latepoint-customer-timezone-profile">
	(function(){
		if (window.isuLatePointCustomerTimezoneProfileLoaded) return;
		window.isuLatePointCustomerTimezoneProfileLoaded = true;

		var timezoneCookieName = <?php echo wp_json_encode($cookie_name); ?>;
		var initialTimezoneField = document.querySelector('input.latepoint_timezone_name, input[name="timezone_name"]');
		var lastAppliedTimezone = initialTimezoneField ? initialTimezoneField.value : '';
		var dashboardRefreshInProgress = false;
		var dashboardRefreshQueued = false;

		// Mirrors timezone changes into all LatePoint selectors, hidden fields, and the timezone cookie.
		function applyTimezoneEverywhere(timezoneName, sourceSelect) {
			if (!timezoneName) return;

			document.querySelectorAll('.isu-lp-customer-timezone-select').forEach(function(otherSelect){
				if (otherSelect !== sourceSelect) otherSelect.value = timezoneName;
			});

			document.querySelectorAll('.latepoint-customer-timezone-selector-w select').forEach(function(timezoneSelect){
				if (timezoneSelect !== sourceSelect) timezoneSelect.value = timezoneName;
			});

			document.querySelectorAll('input.latepoint_timezone_name, input[name="timezone_name"]').forEach(function(input){
				input.value = timezoneName;
			});

			document.cookie = timezoneCookieName + '=' + encodeURIComponent(timezoneName) + '; path=/; SameSite=Lax';
		}

		// Schedules a dashboard refresh only when the timezone value actually changes.
		function applyTimezoneAndMaybeRefresh(timezoneName, sourceSelect) {
			if (!timezoneName) {
				return;
			}

			var currentTimezoneField = document.querySelector('input.latepoint_timezone_name, input[name="timezone_name"]');
			var previousTimezone = lastAppliedTimezone || (currentTimezoneField ? currentTimezoneField.value : '');
			applyTimezoneEverywhere(timezoneName, sourceSelect);

			if (timezoneName !== previousTimezone) {
				lastAppliedTimezone = timezoneName;
				scheduleDashboardTimeSectionsRefresh();
			}
		}

		// Mirrors profile/admin timezone changes into LatePoint's booking-form hidden field.
		function syncTimezoneSelect(select) {
			if (!select || !select.value) return;

			applyTimezoneEverywhere(select.value, select);
		}

		document.addEventListener('change', function(event){
			if (!event.target || !event.target.classList || !event.target.classList.contains('isu-lp-customer-timezone-select')) {
				return;
			}

			syncTimezoneSelect(event.target);
		});

		// Returns the LatePoint route name from either URL-encoded data or FormData.
		function getAjaxRouteName(data) {
			if (!data) return '';

			if (window.FormData && data instanceof FormData) {
				return data.get('route_name') || '';
			}

			if (typeof data === 'string') {
				var params = new URLSearchParams(data);
				return params.get('route_name') || '';
			}

			return data.route_name || '';
		}

		// Extracts LatePoint request params from plain objects, URL-encoded strings, or FormData.
		function getAjaxParams(data) {
			var rawParams = null;

			if (!data) {
				return new URLSearchParams();
			}

			if (window.FormData && data instanceof FormData) {
				rawParams = data.get('params');
			} else if (typeof data === 'string') {
				rawParams = new URLSearchParams(data).get('params');
			} else if (data.params) {
				rawParams = data.params;
			}

			if (!rawParams) {
				return new URLSearchParams();
			}

			if (typeof rawParams === 'string') {
				return new URLSearchParams(rawParams);
			}

			if (rawParams.timezone_name) {
				return new URLSearchParams({ timezone_name: rawParams.timezone_name });
			}

			return new URLSearchParams();
		}

		// Replaces dashboard sections whose appointment/order times depend on timezone.
		function refreshDashboardTimeSections() {
			var $ = window.jQuery;
			if (!$ || typeof window.latepoint_timestamped_ajaxurl !== 'function') {
				return;
			}

			var $dashboard = $('.latepoint-w').has('.customer-dashboard-tabs').first();
			if (!$dashboard.length) {
				return;
			}

			if (dashboardRefreshInProgress) {
				dashboardRefreshQueued = true;
				return;
			}

			dashboardRefreshInProgress = true;
			var activeTabTarget = $dashboard.find('.customer-dashboard-tabs .latepoint-tab-trigger.active').data('tab-target') || '';
			var $refreshTargets = $dashboard.find('.tab-content-customer-bookings, .tab-content-customer-orders');
			$refreshTargets.addClass('os-loading');

			$.ajax({
				type: 'post',
				dataType: 'json',
				url: window.latepoint_timestamped_ajaxurl(),
				data: {
					action: 'latepoint_route_call',
					route_name: 'customer_cabinet__dashboard',
					return_format: 'json'
				},
				success: function(response) {
					if (!response || response.status !== 'success' || !response.message) {
						$refreshTargets.removeClass('os-loading');
						dashboardRefreshInProgress = false;
						runQueuedDashboardRefresh();
						return;
					}

					var parsed = $('<div>').html(response.message);
					['tab-content-customer-bookings', 'tab-content-customer-orders'].forEach(function(className){
						var $current = $dashboard.find('.' + className).first();
						var $fresh = parsed.find('.' + className).first();
						if ($current.length && $fresh.length) {
							$current.replaceWith($fresh);
						}
					});

					if (activeTabTarget) {
						$dashboard.find('.latepoint-tab-content').removeClass('active');
						$dashboard.find(activeTabTarget).addClass('active');
					}

					document.dispatchEvent(new CustomEvent('lp:customerDashboardTimeSectionsRefreshed', {
						detail: { container: $dashboard[0] }
					}));
					dashboardRefreshInProgress = false;
					runQueuedDashboardRefresh();
				},
				error: function() {
					$refreshTargets.removeClass('os-loading');
					dashboardRefreshInProgress = false;
					runQueuedDashboardRefresh();
				}
			});
		}

		// Runs one deferred dashboard refresh after the current request finishes.
		function runQueuedDashboardRefresh() {
			if (!dashboardRefreshQueued) {
				return;
			}

			dashboardRefreshQueued = false;
			scheduleDashboardTimeSectionsRefresh();
		}

		// Debounces dashboard refreshes when several timezone-related AJAX calls happen together.
		function scheduleDashboardTimeSectionsRefresh() {
			window.clearTimeout(scheduleDashboardTimeSectionsRefresh.timer);
			scheduleDashboardTimeSectionsRefresh.timer = window.setTimeout(refreshDashboardTimeSections, 120);
		}

		if (window.jQuery) {
			window.jQuery(document).ajaxSuccess(function(event, xhr, settings, response){
				if (!response || response.status !== 'success') {
					return;
				}

				var routeName = getAjaxRouteName(settings.data);

				if (routeName === 'customer_cabinet__update') {
					var profileSelect = document.querySelector('.tab-content-customer-info-form .isu-lp-customer-timezone-select');
					if (profileSelect && profileSelect.value) {
						applyTimezoneAndMaybeRefresh(profileSelect.value, profileSelect);
					}
				}

				if (routeName === 'timezone_selector__change_timezone') {
					var timezoneName = getAjaxParams(settings.data).get('timezone_name');
					if (timezoneName) {
						applyTimezoneAndMaybeRefresh(timezoneName, null);
					}
				}
			});
		}
	})();
	</script>
	<?php
}

add_action('wp_footer', 'isu_latepoint_customer_timezone_print_script', 1005);
add_action('admin_footer', 'isu_latepoint_customer_timezone_print_script', 1005);
