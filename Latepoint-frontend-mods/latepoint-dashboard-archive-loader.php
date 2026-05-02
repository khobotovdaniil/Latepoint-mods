<?php
/**
 * Plugin Name: ISU LatePoint Dashboard Archive Loader
 * Description: Limits heavy LatePoint customer dashboard queries and adds AJAX-backed pagination for appointments and orders.
 *
 * LatePoint renders every customer booking and order in the dashboard before any
 * frontend pagination can run. On customers with a long history that makes the
 * first page load unnecessarily heavy. This plugin replaces the customer
 * dashboard shortcode with the same LatePoint dashboard template, but feeds it
 * limited appointment/order query results and lets the customer load older
 * records in page-sized batches with optional period and sort controls.
 */

if (!defined('ABSPATH')) {
	exit;
}

const ISU_LATEPOINT_DASHBOARD_ARCHIVE_NONCE = 'isu_latepoint_dashboard_archive';

/**
 * Checks whether this plugin should write diagnostic messages to the WordPress debug log.
 */
function isu_latepoint_dashboard_archive_debug_enabled(): bool {
	return defined('ISU_LATEPOINT_DASHBOARD_ARCHIVE_DEBUG') && ISU_LATEPOINT_DASHBOARD_ARCHIVE_DEBUG;
}

/**
 * Writes a namespaced debug-log message when dashboard archive debug mode is enabled.
 */
function isu_latepoint_dashboard_archive_debug_log(string $message, array $context = []): void {
	if (!isu_latepoint_dashboard_archive_debug_enabled()) {
		return;
	}

	error_log('[ISU LatePoint Dashboard Archive] ' . $message . ($context ? ' ' . wp_json_encode($context) : ''));
}

/**
 * Registers the dashboard shortcode replacement after LatePoint has registered its own shortcode.
 */
function isu_latepoint_dashboard_archive_register_shortcode(): void {
	if (!class_exists('OsAuthHelper') || !class_exists('OsCustomerCabinetController')) {
		return;
	}

	remove_shortcode('latepoint_customer_dashboard');
	add_shortcode('latepoint_customer_dashboard', 'isu_latepoint_dashboard_archive_shortcode');
}

add_action('init', 'isu_latepoint_dashboard_archive_register_shortcode', 1000);

/**
 * Prevents the older client-side dashboard pagination plugin from paginating the already-limited output.
 */
function isu_latepoint_dashboard_archive_disable_client_pagination(): void {
	remove_action('wp_footer', 'isu_latepoint_dashboard_pagination_print_assets', 1001);
}

add_action('wp_footer', 'isu_latepoint_dashboard_archive_disable_client_pagination', 1);

/**
 * Returns the number of records loaded per dashboard batch.
 */
function isu_latepoint_dashboard_archive_limit(): int {
	$limit = (int) apply_filters('isu_latepoint_dashboard_archive_limit', 24);

	return max(1, min(100, $limit));
}

/**
 * Returns the number of already-loaded records shown per local dashboard page.
 */
function isu_latepoint_dashboard_archive_per_page(): int {
	$per_page = (int) apply_filters('isu_latepoint_dashboard_archive_per_page', 6);

	return max(1, min(24, $per_page));
}

/**
 * Rounds a record limit up to a whole number of dashboard pages.
 */
function isu_latepoint_dashboard_archive_align_limit_to_page(int $limit, int $page_size): int {
	$page_size = max(1, $page_size);

	return (int) (ceil($limit / $page_size) * $page_size);
}

/**
 * Normalizes period keys accepted from the frontend controls.
 */
function isu_latepoint_dashboard_archive_sanitize_period(string $period): string {
	$allowed = ['all', '30', '90', 'six_months', 'this_year', 'last_year'];

	return in_array($period, $allowed, true) ? $period : 'all';
}

/**
 * Normalizes sort keys accepted from the frontend controls.
 */
function isu_latepoint_dashboard_archive_sanitize_sort(string $sort): string {
	return $sort === 'oldest' ? 'oldest' : 'newest';
}

/**
 * Builds a reusable date/date-time range for dashboard archive filters.
 */
function isu_latepoint_dashboard_archive_period_range(string $period, bool $datetime = false): array {
	$period = isu_latepoint_dashboard_archive_sanitize_period($period);
	$today  = current_time('Y-m-d');
	$year   = (int) current_time('Y');
	$start  = '';
	$end    = '';

	switch ($period) {
		case '30':
			$start = date('Y-m-d', strtotime($today . ' -30 days'));
			$end   = $today;
			break;
		case '90':
			$start = date('Y-m-d', strtotime($today . ' -90 days'));
			$end   = $today;
			break;
		case 'six_months':
			$start = date('Y-m-d', strtotime($today . ' -6 months'));
			$end   = $today;
			break;
		case 'this_year':
			$start = $year . '-01-01';
			$end   = $year . '-12-31';
			break;
		case 'last_year':
			$start = ($year - 1) . '-01-01';
			$end   = ($year - 1) . '-12-31';
			break;
	}

	if (!$start || !$end) {
		return [];
	}

	if ($datetime) {
		return [
			'start' => $start . ' 00:00:00',
			'end'   => $end . ' 23:59:59',
		];
	}

	return [
		'start' => $start,
		'end'   => $end,
	];
}

/**
 * Adds period conditions to a LatePoint model conditions array.
 */
function isu_latepoint_dashboard_archive_apply_period_conditions(array $conditions, string $field, string $period, bool $datetime = false): array {
	$range = isu_latepoint_dashboard_archive_period_range($period, $datetime);

	if (!$range) {
		return $conditions;
	}

	$conditions[$field . ' >='] = $range['start'];
	$conditions[$field . ' <='] = $range['end'];

	return $conditions;
}

/**
 * Builds the LatePoint booking query for one dashboard appointment section.
 */
function isu_latepoint_dashboard_archive_booking_query(int $customer_id, string $section, string $period, string $sort): ?OsBookingModel {
	if (!class_exists('OsBookingModel') || !class_exists('OsTimeHelper')) {
		return null;
	}

	$query = new OsBookingModel();

	if ($section === 'upcoming') {
		return $query
			->should_not_be_cancelled()
			->where(['customer_id' => $customer_id])
			->should_be_in_future()
			->order_by('start_date, start_time asc');
	}

	if ($section === 'past') {
		$direction  = isu_latepoint_dashboard_archive_sanitize_sort($sort) === 'oldest' ? 'asc' : 'desc';
		$conditions = [
			'customer_id' => $customer_id,
			'OR'          => [
				'start_date <' => OsTimeHelper::today_date('Y-m-d'),
				'AND'          => [
					'start_date'   => OsTimeHelper::today_date('Y-m-d'),
					'start_time <' => OsTimeHelper::get_current_minutes(),
				],
			],
		];
		$conditions = isu_latepoint_dashboard_archive_apply_period_conditions($conditions, 'start_date', $period);

		return $query
			->should_not_be_cancelled()
			->where($conditions)
			->order_by('start_date ' . $direction . ', start_time ' . $direction);
	}

	if ($section === 'cancelled') {
		$direction  = isu_latepoint_dashboard_archive_sanitize_sort($sort) === 'oldest' ? 'asc' : 'desc';
		$conditions = isu_latepoint_dashboard_archive_apply_period_conditions(
			['customer_id' => $customer_id],
			'start_date',
			$period
		);

		return $query
			->should_be_cancelled()
			->where($conditions)
			->order_by('start_date ' . $direction . ', start_time ' . $direction);
	}

	return null;
}

/**
 * Returns a limited booking batch and total count for one dashboard appointment section.
 */
function isu_latepoint_dashboard_archive_get_bookings(int $customer_id, string $section, string $period = 'all', string $sort = 'newest', int $limit = 24, int $offset = 0): array {
	$count_query = isu_latepoint_dashboard_archive_booking_query($customer_id, $section, $period, $sort);
	$items_query = isu_latepoint_dashboard_archive_booking_query($customer_id, $section, $period, $sort);

	if (!$count_query || !$items_query) {
		return [
			'items' => [],
			'total' => 0,
		];
	}

	$total = (int) $count_query->count();
	$items = $items_query
		->set_limit($limit)
		->set_offset($offset)
		->get_results_as_models();

	isu_latepoint_dashboard_archive_debug_log('Loaded booking batch.', [
		'customer_id' => $customer_id,
		'section'     => $section,
		'period'      => $period,
		'sort'        => $sort,
		'limit'       => $limit,
		'offset'      => $offset,
		'count'       => count($items),
		'total'       => $total,
	]);

	return [
		'items' => $items,
		'total' => $total,
	];
}

/**
 * Builds the LatePoint order query for the customer dashboard Orders tab.
 */
function isu_latepoint_dashboard_archive_order_query(int $customer_id, string $period, string $sort): ?OsOrderModel {
	if (!class_exists('OsOrderModel')) {
		return null;
	}

	$direction  = isu_latepoint_dashboard_archive_sanitize_sort($sort) === 'oldest' ? 'asc' : 'desc';
	$conditions = isu_latepoint_dashboard_archive_apply_period_conditions(
		['customer_id' => $customer_id],
		'created_at',
		$period,
		true
	);

	return (new OsOrderModel())
		->where($conditions)
		->order_by('created_at ' . $direction);
}

/**
 * Returns a limited order batch and total count for the dashboard Orders tab.
 */
function isu_latepoint_dashboard_archive_get_orders(int $customer_id, string $period = 'all', string $sort = 'newest', int $limit = 24, int $offset = 0): array {
	$count_query = isu_latepoint_dashboard_archive_order_query($customer_id, $period, $sort);
	$items_query = isu_latepoint_dashboard_archive_order_query($customer_id, $period, $sort);

	if (!$count_query || !$items_query) {
		return [
			'items' => [],
			'total' => 0,
		];
	}

	$total = (int) $count_query->count();
	$items = $items_query
		->set_limit($limit)
		->set_offset($offset)
		->get_results_as_models();

	isu_latepoint_dashboard_archive_debug_log('Loaded order batch.', [
		'customer_id' => $customer_id,
		'period'      => $period,
		'sort'        => $sort,
		'limit'       => $limit,
		'offset'      => $offset,
		'count'       => count($items),
		'total'       => $total,
	]);

	return [
		'items' => $items,
		'total' => $total,
	];
}

/**
 * Renders a LatePoint customer cabinet view inside a local variable scope.
 */
function isu_latepoint_dashboard_archive_render_view(string $view, array $vars): string {
	if (!defined('LATEPOINT_VIEWS_ABSPATH')) {
		return '';
	}

	$path = LATEPOINT_VIEWS_ABSPATH . 'customer_cabinet/' . $view . '.php';
	if (!is_readable($path)) {
		return '';
	}

	extract($vars, EXTR_SKIP);

	ob_start();
	include $path;

	return (string) ob_get_clean();
}

/**
 * Renders one batch of booking tiles with the same partial LatePoint uses in the original dashboard.
 */
function isu_latepoint_dashboard_archive_render_booking_tiles(array $bookings, bool $is_upcoming_booking): string {
	if (!defined('LATEPOINT_VIEWS_ABSPATH')) {
		return '';
	}

	$path = LATEPOINT_VIEWS_ABSPATH . 'customer_cabinet/_booking_tile.php';
	if (!is_readable($path)) {
		return '';
	}

	ob_start();
	foreach ($bookings as $booking) {
		include $path;
	}

	return (string) ob_get_clean();
}

/**
 * Renders one batch of order tiles with the same partial LatePoint uses in the original dashboard.
 */
function isu_latepoint_dashboard_archive_render_order_tiles(array $orders): string {
	if (!defined('LATEPOINT_VIEWS_ABSPATH')) {
		return '';
	}

	$path = LATEPOINT_VIEWS_ABSPATH . 'customer_cabinet/_order_tile.php';
	if (!is_readable($path)) {
		return '';
	}

	ob_start();
	foreach ($orders as $order) {
		include $path;
	}

	return (string) ob_get_clean();
}

/**
 * Builds the initial limited data set used by the dashboard shortcode.
 */
function isu_latepoint_dashboard_archive_initial_data(OsCustomerModel $customer, int $booking_limit, int $order_limit): array {
	$upcoming  = isu_latepoint_dashboard_archive_get_bookings((int) $customer->id, 'upcoming', 'all', 'oldest', $booking_limit, 0);
	$past      = isu_latepoint_dashboard_archive_get_bookings((int) $customer->id, 'past', '30', 'newest', $booking_limit, 0);
	$cancelled = isu_latepoint_dashboard_archive_get_bookings((int) $customer->id, 'cancelled', '30', 'newest', $booking_limit, 0);
	$orders    = isu_latepoint_dashboard_archive_get_orders((int) $customer->id, 'all', 'newest', $order_limit, 0);

	return [
		'future_bookings'    => $upcoming['items'],
		'past_bookings'      => $past['items'],
		'cancelled_bookings' => $cancelled['items'],
		'orders'             => $orders['items'],
		'totals'             => [
			'upcoming'  => $upcoming['total'],
			'past'      => $past['total'],
			'cancelled' => $cancelled['total'],
			'orders'    => $orders['total'],
		],
	];
}

/**
 * Replacement for the LatePoint customer dashboard shortcode.
 */
function isu_latepoint_dashboard_archive_shortcode($atts): string {
	if (!class_exists('OsAuthHelper') || !class_exists('OsCustomerCabinetController')) {
		return '';
	}

	$params = is_array($atts) ? $atts : [];

	if (!OsAuthHelper::is_customer_logged_in()) {
		$controller = new OsCustomerCabinetController();

		return (string) $controller->dashboard($params);
	}

	$customer = OsAuthHelper::get_logged_in_customer();
	if (!$customer || empty($customer->id)) {
		return '';
	}

	$limit        = isu_latepoint_dashboard_archive_limit();
	$per_page     = isu_latepoint_dashboard_archive_per_page();
	$cart_not_empty = class_exists('OsCartsHelper') ? (!OsCartsHelper::is_current_cart_empty() && OsCartsHelper::can_checkout_multiple_items()) : false;
	$not_scheduled_bundles = method_exists($customer, 'get_not_scheduled_bundles') ? $customer->get_not_scheduled_bundles() : [];
	$hide_new_appointment_ui = isset($params['hide_new_appointment_ui']) && $params['hide_new_appointment_ui'] === 'yes';
	$booking_page_size = $hide_new_appointment_ui ? $per_page : max(1, $per_page - 1);
	$booking_limit = isu_latepoint_dashboard_archive_align_limit_to_page($limit, $booking_page_size);
	$order_limit = isu_latepoint_dashboard_archive_align_limit_to_page($limit, $per_page);
	$initial_data = isu_latepoint_dashboard_archive_initial_data($customer, $booking_limit, $order_limit);

	isu_latepoint_dashboard_archive_debug_log('Rendered limited customer dashboard.', [
		'customer_id'       => (int) $customer->id,
		'limit'             => $limit,
		'booking_limit'     => $booking_limit,
		'order_limit'       => $order_limit,
		'per_page'          => $per_page,
		'loaded_upcoming'   => count($initial_data['future_bookings']),
		'loaded_past'       => count($initial_data['past_bookings']),
		'loaded_cancelled'  => count($initial_data['cancelled_bookings']),
		'loaded_orders'     => count($initial_data['orders']),
		'loaded_bundles'    => count($not_scheduled_bundles),
		'total_upcoming'    => $initial_data['totals']['upcoming'],
		'total_past'        => $initial_data['totals']['past'],
		'total_cancelled'   => $initial_data['totals']['cancelled'],
		'total_orders'      => $initial_data['totals']['orders'],
	]);

	$html = isu_latepoint_dashboard_archive_render_view(
		'dashboard',
		[
			'customer'                => $customer,
			'orders'                  => $initial_data['orders'],
			'future_bookings'         => $initial_data['future_bookings'],
			'past_bookings'           => $initial_data['past_bookings'],
			'cancelled_bookings'      => $initial_data['cancelled_bookings'],
			'not_scheduled_bundles'   => $not_scheduled_bundles,
			'cart_not_empty'          => $cart_not_empty,
			'hide_new_appointment_ui' => $hide_new_appointment_ui,
		]
	);

	$config = [
		'ajaxUrl' => admin_url('admin-ajax.php'),
		'nonce'   => wp_create_nonce(ISU_LATEPOINT_DASHBOARD_ARCHIVE_NONCE),
		'limit'   => $limit,
		'perPage' => $per_page,
		'totals'  => $initial_data['totals'],
		'loaded'  => [
			'upcoming'  => count($initial_data['future_bookings']),
			'past'      => count($initial_data['past_bookings']),
			'cancelled' => count($initial_data['cancelled_bookings']),
			'orders'    => count($initial_data['orders']),
		],
	];

	$html .= '<script id="isu-latepoint-dashboard-archive-config">window.isuLatePointDashboardArchive=' . wp_json_encode($config) . ';</script>';

	return $html;
}

/**
 * Handles dashboard archive pagination, period, and sort requests.
 */
function isu_latepoint_dashboard_archive_ajax_load(): void {
	if (!class_exists('OsAuthHelper') || !OsAuthHelper::is_customer_logged_in()) {
		wp_send_json_error(['message' => 'Not logged in.'], 403);
	}

	check_ajax_referer(ISU_LATEPOINT_DASHBOARD_ARCHIVE_NONCE, 'nonce');

	$customer = OsAuthHelper::get_logged_in_customer();
	if (!$customer || empty($customer->id)) {
		wp_send_json_error(['message' => 'Customer not found.'], 404);
	}

	$section = isset($_POST['section']) ? sanitize_key((string) wp_unslash($_POST['section'])) : '';
	$period  = isset($_POST['period']) ? isu_latepoint_dashboard_archive_sanitize_period((string) wp_unslash($_POST['period'])) : 'all';
	$sort    = isset($_POST['sort']) ? isu_latepoint_dashboard_archive_sanitize_sort((string) wp_unslash($_POST['sort'])) : 'newest';
	$limit   = isset($_POST['limit']) ? (int) $_POST['limit'] : isu_latepoint_dashboard_archive_limit();
	$offset  = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
	$limit   = max(1, min(100, $limit));

	isu_latepoint_dashboard_archive_debug_log('Dashboard archive AJAX requested.', [
		'customer_id' => (int) $customer->id,
		'section'     => $section,
		'period'      => $period,
		'sort'        => $sort,
		'limit'       => $limit,
		'offset'      => $offset,
	]);

	if (in_array($section, ['upcoming', 'past', 'cancelled'], true)) {
		$result = isu_latepoint_dashboard_archive_get_bookings((int) $customer->id, $section, $period, $sort, $limit, $offset);
		$html   = isu_latepoint_dashboard_archive_render_booking_tiles($result['items'], $section === 'upcoming');
	} elseif ($section === 'orders') {
		$result = isu_latepoint_dashboard_archive_get_orders((int) $customer->id, $period, $sort, $limit, $offset);
		$html   = isu_latepoint_dashboard_archive_render_order_tiles($result['items']);
	} else {
		wp_send_json_error(['message' => 'Unknown section.'], 400);
	}

	$count       = count($result['items']);
	$next_offset = $offset + $count;

	isu_latepoint_dashboard_archive_debug_log('Dashboard archive AJAX completed.', [
		'customer_id' => (int) $customer->id,
		'section'     => $section,
		'count'       => $count,
		'total'       => (int) $result['total'],
		'next_offset' => $next_offset,
		'has_more'    => $next_offset < (int) $result['total'],
	]);

	wp_send_json_success(
		[
			'html'       => $html,
			'count'      => $count,
			'total'      => (int) $result['total'],
			'offset'     => $offset,
			'nextOffset' => $next_offset,
			'hasMore'    => $next_offset < (int) $result['total'],
		]
	);
}

add_action('wp_ajax_isu_latepoint_dashboard_archive_load', 'isu_latepoint_dashboard_archive_ajax_load');

/**
 * Prints dashboard archive controls and client-side behavior.
 */
function isu_latepoint_dashboard_archive_print_assets(): void {
	?>
	<style id="isu-latepoint-dashboard-archive-styles">
		.lp-dashboard-archive-controls {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 8px;
			margin: 8px 0 14px;
		}

		.lp-dashboard-archive-controls select,
		.lp-dashboard-archive-pagination button {
			min-height: 34px;
			border: 1px solid rgba(0, 0, 0, 0.12);
			background: #fff;
			color: inherit;
			font: inherit;
			line-height: 1.2;
			border-radius: 6px;
		}

		.lp-dashboard-archive-controls select {
			padding: 0 28px 0 10px;
		}

		.lp-dashboard-archive-status {
			font-size: 13px;
			opacity: 0.75;
		}

		.lp-dashboard-archive-page-hidden {
			display: none !important;
		}

		.lp-dashboard-archive-pagination {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 6px;
		}

		.lp-dashboard-archive-pagination button {
			min-width: 32px;
			height: 32px;
			border: 1px solid rgba(0, 0, 0, 0.12);
			background: #fff;
			color: inherit;
			cursor: pointer;
			font: inherit;
			line-height: 1;
			padding: 0 9px;
			border-radius: 6px;
		}

		.lp-dashboard-archive-pagination button:hover,
		.lp-dashboard-archive-pagination button.is-active {
			border-color: currentColor;
		}

		.lp-dashboard-archive-pagination button.is-active {
			font-weight: 700;
			pointer-events: none;
		}

		.lp-dashboard-archive-pagination button:disabled {
			cursor: default;
			opacity: 0.45;
		}

		.lp-dashboard-archive-pagination-ellipsis {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			min-width: 20px;
			height: 32px;
			opacity: 0.65;
		}

		.lp-dashboard-archive-empty {
			width: 100%;
		}

		.customer-bookings-tiles,
		.customer-orders-tiles,
		.latepoint-section-heading-w,
		.lp-dashboard-archive-controls {
			transition: opacity 180ms ease, transform 180ms ease;
		}

		.lp-dashboard-archive-soft-refresh {
			opacity: 0;
			transform: translateY(4px);
		}

		@media (prefers-reduced-motion: reduce) {
			.customer-bookings-tiles,
			.customer-orders-tiles,
			.latepoint-section-heading-w,
			.lp-dashboard-archive-controls {
				transition: none;
			}
		}

		.lp-dashboard-section-tabs {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 8px;
			margin: 14px 0 20px;
		}

		.lp-dashboard-section-tab {
			border: 1px solid rgba(0, 0, 0, 0.12);
			background: #fff;
			color: inherit;
			cursor: pointer;
			font: inherit;
			font-weight: 600;
			line-height: 1;
			padding: 10px 14px;
			border-radius: 6px;
		}

		.lp-dashboard-section-tab:hover,
		.lp-dashboard-section-tab.is-active {
			border-color: currentColor;
		}
	</style>
	<script id="isu-latepoint-dashboard-archive">
	(function(){
		var config = window.isuLatePointDashboardArchive;
		if (!config || window.isuLatePointDashboardArchiveLoaded) return;
		window.isuLatePointDashboardArchiveLoaded = true;

		var sectionTabsClass = 'lp-dashboard-section-tabs';
		var sectionTabClass = 'lp-dashboard-section-tab';
		var controlsClass = 'lp-dashboard-archive-controls';
		var emptyClass = 'lp-dashboard-archive-empty';
		var hiddenClass = 'lp-dashboard-archive-page-hidden';
		var paginationClass = 'lp-dashboard-archive-pagination';
		var limit = parseInt(config.limit, 10) || 24;
		var perPage = parseInt(config.perPage, 10) || 6;

		// Returns only direct children to avoid touching nested tiles or LatePoint form controls.
		function directChildren(container, selector) {
			return Array.prototype.filter.call(container.children || [], function(child){
				return child.matches && child.matches(selector);
			});
		}

		// Reads compact, normalized text from a DOM node.
		function textOf(element) {
			return element && element.textContent ? element.textContent.replace(/\s+/g, ' ').trim() : '';
		}

		// Creates a plain button used for tabs and pagination controls.
		function button(label, className) {
			var el = document.createElement('button');
			el.type = 'button';
			el.className = className || '';
			el.textContent = label;
			return el;
		}

		// Creates one select with value/label pairs.
		function select(options, value, label) {
			var el = document.createElement('select');
			el.setAttribute('aria-label', label);
			options.forEach(function(option){
				var item = document.createElement('option');
				item.value = option.value;
				item.textContent = option.label;
				item.selected = option.value === value;
				el.appendChild(item);
			});
			return el;
		}

		// Finds the New Appointment tile so AJAX results can be inserted before it.
		function getNewBookingTile(container) {
			return directChildren(container, '.new-booking-tile')[0] || null;
		}

		// Returns direct loaded tiles that should be paginated locally.
		function getPageItems(container) {
			var selector = container.classList.contains('customer-orders-tiles')
				? '.customer-order'
				: '.customer-booking, .customer-bundle-tile';

			return directChildren(container, selector);
		}

		// Hides or shows a tile without depending on LatePoint's grid display rules.
		function setVisible(item, visible) {
			if (!item) return;

			item.classList.toggle(hiddenClass, !visible);
			item.hidden = !visible;
			if (visible) {
				item.style.removeProperty('display');
			} else {
				item.style.setProperty('display', 'none', 'important');
			}
		}

		// Calculates the local page size for one section, reserving room for New Appointment.
		function getLocalPerPage(state) {
			var newTile = getNewBookingTile(state.container);

			return newTile ? Math.max(1, perPage - 1) : perPage;
		}

		// Rounds AJAX batches so server-loaded records always fill whole local pages.
		function getBatchLimit(state) {
			var localPerPage = getLocalPerPage(state);

			return Math.ceil(limit / localPerPage) * localPerPage;
		}

		// Sends the user to a local page, loading the next server batch first when needed.
		function goToPage(state, page) {
			var targetPage = Math.max(1, parseInt(page, 10) || 1);
			var localPerPage = getLocalPerPage(state);
			var loadedItems = getPageItems(state.container).length;
			var requiredItems = targetPage * localPerPage;

			if (requiredItems > loadedItems && state.loaded < state.total && state.canLoadMore) {
				state.pendingPage = targetPage;
				state.requestLimit = Math.max(getBatchLimit(state), requiredItems - loadedItems);
				loadSection(state, false);
				return;
			}

			state.page = targetPage;
			renderLocalPage(state);
		}

		// Adds a short fade/slide cue after local page or tab changes.
		function softRefresh(element) {
			if (!element) return;

			element.classList.add('lp-dashboard-archive-soft-refresh');
			window.requestAnimationFrame(function(){
				element.classList.remove('lp-dashboard-archive-soft-refresh');
			});
		}

		// Inserts returned tile HTML while preserving the New Appointment tile as the last card.
		function appendTiles(container, html, replace) {
			if (replace) {
				directChildren(container, '.customer-booking, .customer-order, .' + emptyClass).forEach(function(item){
					item.parentNode.removeChild(item);
				});
			}

			if (!html) {
				if (replace) {
					var empty = document.createElement('div');
					empty.className = 'latepoint-message-info latepoint-message ' + emptyClass;
					empty.textContent = container.classList.contains('customer-orders-tiles') ? 'No orders found' : 'No appointments found';
					container.insertBefore(empty, getNewBookingTile(container));
				}
				return;
			}

			var template = document.createElement('template');
			template.innerHTML = html;
			var anchor = getNewBookingTile(container);
			var nodes = Array.prototype.slice.call(template.content.childNodes);
			nodes.forEach(function(node){
				container.insertBefore(node, anchor);
			});
		}

		// Applies local pagination to the records already loaded into one section.
		function renderLocalPage(state) {
			if (!state || !state.container) return;

			var items = getPageItems(state.container);
			var newTile = getNewBookingTile(state.container);
			var localPerPage = getLocalPerPage(state);
			var totalItems = Math.max(items.length, parseInt(state.total, 10) || 0);
			var pageCount = Math.max(1, Math.ceil(totalItems / localPerPage));
			state.page = Math.min(Math.max(1, parseInt(state.page, 10) || 1), pageCount);

			var start = (state.page - 1) * localPerPage;
			var end = start + localPerPage;
			if (end > items.length && state.loaded < state.total && state.canLoadMore && !state.loading) {
				state.pendingPage = state.page;
				state.requestLimit = Math.max(getBatchLimit(state), end - items.length);
				loadSection(state, false);
				return;
			}

			items.forEach(function(item, index){
				setVisible(item, index >= start && index < end);
			});

			if (newTile) {
				setVisible(newTile, true);
			}

			renderPagination(state, pageCount);
			softRefresh(state.container);
		}

		// Rebuilds the local page controls for one already-loaded section.
		function renderPagination(state, pageCount) {
			if (!state.pagination) return;

			state.pagination.innerHTML = '';
			state.pagination.hidden = pageCount <= 1;
			if (pageCount <= 1) return;

			var prev = button('<', '');
			var next = button('>', '');
			prev.setAttribute('aria-label', 'Previous page');
			next.setAttribute('aria-label', 'Next page');
			prev.disabled = state.page === 1;
			next.disabled = state.page === pageCount;

			prev.addEventListener('click', function(){
				goToPage(state, state.page - 1);
			});

			next.addEventListener('click', function(){
				goToPage(state, state.page + 1);
			});

			state.pagination.appendChild(prev);

			getCompactPages(state.page, pageCount).forEach(function(page){
				if (page === 'ellipsis') {
					var ellipsis = document.createElement('span');
					ellipsis.className = 'lp-dashboard-archive-pagination-ellipsis';
					ellipsis.textContent = '...';
					state.pagination.appendChild(ellipsis);
					return;
				}

				var pageButton = button(String(page), page === state.page ? 'is-active' : '');
				pageButton.setAttribute('aria-label', 'Page ' + page);
				pageButton.addEventListener('click', function(){
					goToPage(state, page);
				});
				state.pagination.appendChild(pageButton);
			});
			state.pagination.appendChild(next);
		}

		// Keeps long local pagination compact: first, current neighbors, last.
		function getCompactPages(current, total) {
			if (total <= 6) {
				var allPages = [];
				for (var page = 1; page <= total; page++) {
					allPages.push(page);
				}
				return allPages;
			}

			var pages = [1];
			var start = Math.max(2, current - 1);
			var end = Math.min(total - 1, current + 1);

			if (start > 2) {
				pages.push('ellipsis');
			}

			for (var i = start; i <= end; i++) {
				pages.push(i);
			}

			if (end < total - 1) {
				pages.push('ellipsis');
			}

			pages.push(total);

			return pages;
		}

		// Updates the small "loaded of total" status next to each control row.
		function updateStatus(state) {
			if (!state.status) return;

			state.status.textContent = String(state.loaded) + ' of ' + String(state.total);
			if (state.loading) {
				state.status.textContent += ' Loading...';
			}
		}

		// Sends one AJAX request for a section and applies the returned batch.
		function loadSection(state, replace) {
			if (state.loading || !state.canLoadMore) return;
			state.loading = true;
			updateStatus(state);

			var body = new URLSearchParams();
			body.set('action', 'isu_latepoint_dashboard_archive_load');
			body.set('nonce', config.nonce);
			body.set('section', state.section);
			body.set('period', state.period ? state.period.value : 'all');
			body.set('sort', state.sort ? state.sort.value : (state.section === 'upcoming' ? 'oldest' : 'newest'));
			body.set('offset', replace ? '0' : String(state.loaded));
			body.set('limit', String(state.requestLimit || getBatchLimit(state)));

			fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
				body: body.toString()
			}).then(function(response){
				return response.json();
			}).then(function(response){
				if (!response || !response.success || !response.data) return;

				state.total = parseInt(response.data.total, 10) || 0;
				state.loaded = parseInt(response.data.nextOffset, 10) || 0;
				appendTiles(state.container, response.data.html || '', replace);
				state.page = replace ? 1 : (state.pendingPage || Math.ceil(Math.max(1, getPageItems(state.container).length) / getLocalPerPage(state)));
				state.pendingPage = null;
				state.requestLimit = null;
				renderLocalPage(state);
				updateHeadingTotal(state);
			}).finally(function(){
				state.loading = false;
				updateStatus(state);
			});
		}

		// Keeps LatePoint's original heading count honest after the query is limited.
		function updateHeadingTotal(state) {
			if (!state.heading) return;
			var extra = state.heading.querySelector('.heading-extra');
			if (extra) {
				var noun = state.section === 'bundles' ? ' Bundles' : (state.section === 'orders' ? ' Orders' : ' Appointments');
				extra.textContent = String(state.total) + noun;
			}
		}

		// Adds controls to one existing tile section.
		function initArchiveControls(container, section, heading) {
			if (!container || container.getAttribute('data-lp-dashboard-archive-ready') === '1') return;

			container.setAttribute('data-lp-dashboard-archive-ready', '1');

			var controls = document.createElement('div');
			controls.className = controlsClass;

			var state = {
				container: container,
				heading: heading,
				section: section,
				total: parseInt(config.totals && config.totals[section], 10) || 0,
				loaded: parseInt(config.loaded && config.loaded[section], 10) || 0,
				loading: false,
				period: null,
				sort: null,
				status: document.createElement('span'),
				canLoadMore: section !== 'bundles',
				controls: controls,
				pagination: document.createElement('div'),
				page: 1
			};

			if (section === 'bundles') {
				state.total = getPageItems(container).length;
				state.loaded = state.total;
			}

			if (section === 'past' || section === 'cancelled') {
				state.period = select([
					{value: 'all', label: 'All history'},
					{value: '30', label: 'Last 30 days'},
					{value: '90', label: 'Last 90 days'},
					{value: 'this_year', label: 'This year'},
					{value: 'last_year', label: 'Last year'}
				], '30', 'Period');
				controls.appendChild(state.period);
			}

			if (section === 'orders') {
				state.period = select([
					{value: 'all', label: 'All history'},
					{value: 'six_months', label: 'Last 6 months'},
					{value: 'this_year', label: 'This year'},
					{value: 'last_year', label: 'Last year'}
				], 'all', 'Period');
				controls.appendChild(state.period);
			}

			if (section !== 'upcoming' && section !== 'bundles') {
				state.sort = select([
					{value: 'newest', label: 'Newest first'},
					{value: 'oldest', label: 'Oldest first'}
				], 'newest', 'Sort');
				controls.appendChild(state.sort);
			}

			state.status.className = 'lp-dashboard-archive-status';
			state.pagination.className = paginationClass;
			controls.appendChild(state.pagination);
			controls.appendChild(state.status);
			if (heading && heading.parentNode) {
				heading.insertAdjacentElement('afterend', controls);
			} else {
				container.insertAdjacentElement('beforebegin', controls);
			}

			[state.period, state.sort].forEach(function(control){
				if (!control) return;
				control.addEventListener('change', function(){
					loadSection(state, true);
				});
			});

			container._isuDashboardArchiveState = state;
			updateHeadingTotal(state);
			updateStatus(state);
			renderLocalPage(state);
		}

		// Maps LatePoint's appointment section headings to stable internal section keys.
		function sectionKeyFromHeading(heading) {
			var label = textOf(heading && heading.querySelector('.latepoint-section-heading')).toLowerCase();
			if (label.indexOf('upcoming') !== -1) return 'upcoming';
			if (label.indexOf('bundle') !== -1) return 'bundles';
			if (label.indexOf('past') !== -1) return 'past';
			if (label.indexOf('cancelled') !== -1 || label.indexOf('canceled') !== -1) return 'cancelled';
			return '';
		}

		// Finds rendered appointment sections and initializes their controls.
		function initAppointmentSections(root) {
			var tabContent = root.querySelector('.latepoint-w .tab-content-customer-bookings');
			if (!tabContent) return;

			var sections = directChildren(tabContent, '.customer-bookings-tiles').map(function(container){
				var heading = container.previousElementSibling && container.previousElementSibling.classList.contains('latepoint-section-heading-w')
					? container.previousElementSibling
					: null;
				return {
					key: sectionKeyFromHeading(heading),
					label: textOf(heading && heading.querySelector('.latepoint-section-heading')),
					heading: heading,
					container: container
				};
			}).filter(function(section){
				return section.key && section.heading && section.container;
			});

			sections.forEach(function(section){
				initArchiveControls(section.container, section.key, section.heading);
			});

			initSectionTabs(tabContent, sections);
		}

		// Shows one appointment subsection and hides the rest.
		function setActiveSection(tabContent, activeIndex) {
			var state = tabContent._isuDashboardArchiveTabsState;
			if (!state) return;

			state.activeIndex = Math.min(Math.max(0, activeIndex), state.sections.length - 1);
			state.sections.forEach(function(section, index){
				var active = index === state.activeIndex;
				var controls = section.container._isuDashboardArchiveState ? section.container._isuDashboardArchiveState.controls : null;
				section.heading.hidden = !active;
				section.container.hidden = !active;
				if (controls && controls.classList && controls.classList.contains(controlsClass)) {
					controls.hidden = !active;
				}
				if (active) {
					softRefresh(section.heading);
					softRefresh(section.container);
					softRefresh(controls);
				}
			});

			Array.prototype.forEach.call(state.tabs.querySelectorAll('.' + sectionTabClass), function(tab, index){
				tab.classList.toggle('is-active', index === state.activeIndex);
				tab.setAttribute('aria-selected', index === state.activeIndex ? 'true' : 'false');
			});
		}

		// Adds internal Upcoming/Past/Cancelled tabs only for sections LatePoint actually rendered.
		function initSectionTabs(tabContent, sections) {
			if (sections.length <= 1 || tabContent._isuDashboardArchiveTabsState) return;

			var tabs = document.createElement('div');
			tabs.className = sectionTabsClass;
			tabs.setAttribute('role', 'tablist');

			sections.forEach(function(section, index){
				var tab = button(section.label, sectionTabClass);
				tab.setAttribute('role', 'tab');
				tab.addEventListener('click', function(){
					setActiveSection(tabContent, index);
				});
				tabs.appendChild(tab);
			});

			sections[0].heading.insertAdjacentElement('beforebegin', tabs);
			tabContent._isuDashboardArchiveTabsState = {
				activeIndex: 0,
				sections: sections,
				tabs: tabs
			};
			setActiveSection(tabContent, 0);
		}

		// Initializes controls for the Orders tab.
		function initOrders(root) {
			var container = root.querySelector('.latepoint-w .tab-content-customer-orders .customer-orders-tiles');
			if (!container) return;

			initArchiveControls(container, 'orders', null);
		}

		document.addEventListener('DOMContentLoaded', function(){
			initAppointmentSections(document);
			initOrders(document);
		});
	})();
	</script>
	<?php
}

add_action('wp_footer', 'isu_latepoint_dashboard_archive_print_assets', 1002);
