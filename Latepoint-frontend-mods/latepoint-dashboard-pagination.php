<?php
/**
 * Plugin Name: ISU LatePoint Dashboard Pagination
 * Description: Adds independent client-side pagination to LatePoint customer dashboard appointment and order sections.
 *
 * LatePoint renders all customer appointments and orders at once in the dashboard.
 * This plugin paginates those already-rendered tiles on the client side, adds
 * internal tabs for the Appointments sections, and keeps the New Appointment tile
 * visible as the sixth item on every appointment page. It does not modify booking
 * data or LatePoint queries.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Prints the small frontend-only CSS/JS patch used by the LatePoint customer dashboard.
 */
function isu_latepoint_dashboard_pagination_print_assets(): void {
	$per_page = (int) apply_filters('isu_latepoint_dashboard_pagination_per_page', 6);
	$per_page = max(1, $per_page);
	?>
	<style id="isu-latepoint-dashboard-pagination-styles">
		.lp-dashboard-paged-hidden {
			display: none !important;
		}

		.lp-dashboard-pagination {
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 6px;
			margin: 16px 0 26px;
		}

		.lp-dashboard-pagination button {
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

		.lp-dashboard-pagination button:hover,
		.lp-dashboard-pagination button.is-active {
			border-color: currentColor;
		}

		.lp-dashboard-pagination button.is-active {
			font-weight: 700;
			pointer-events: none;
		}

		.lp-dashboard-pagination button:disabled {
			cursor: default;
			opacity: 0.45;
		}

		.lp-dashboard-pagination .lp-dashboard-pagination-pages {
			display: flex;
			align-items: center;
			gap: 6px;
		}

		.lp-dashboard-new-booking-clone {
			display: flex;
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

		.lp-dashboard-section-tab.is-active {
			pointer-events: none;
		}
	</style>
	<script id="isu-latepoint-dashboard-pagination">
	(function(){
		if (window.isuLatePointDashboardPaginationLoaded) return;
		window.isuLatePointDashboardPaginationLoaded = true;

		var perPage = parseInt(<?php echo wp_json_encode($per_page); ?>, 10) || 6;
		var controlsClass = 'lp-dashboard-pagination';
		var hiddenClass = 'lp-dashboard-paged-hidden';
		var cloneClass = 'lp-dashboard-new-booking-clone';
		var initializedAttr = 'data-lp-dashboard-pagination-ready';
		var sectionTabsClass = 'lp-dashboard-section-tabs';
		var sectionTabClass = 'lp-dashboard-section-tab';

		// Returns only direct children that match a selector, avoiding nested buttons/cards.
		function directChildren(container, selector) {
			return Array.prototype.filter.call(container.children || [], function(child){
				return child.matches && child.matches(selector);
			});
		}

		// Collects paginated cards from one tile grid and removes old temporary clones.
		function getSectionItems(container) {
			directChildren(container, '.' + cloneClass).forEach(function(clone){
				if (clone.parentNode) {
					clone.parentNode.removeChild(clone);
				}
			});

			var newTile = directChildren(container, '.new-booking-tile')[0] || null;
			var itemSelector = container.classList.contains('customer-orders-tiles') ? '.customer-order' : '.customer-booking, .customer-bundle-tile';
			var items = directChildren(container, itemSelector);

			return {
				items: items,
				newTile: newTile
			};
		}

		// Hides elements strongly enough to beat LatePoint/theme grid styles.
		function setItemVisible(item, visible) {
			if (!item) return;

			item.classList.toggle(hiddenClass, !visible);
			item.hidden = !visible;

			if (visible) {
				item.style.removeProperty('display');
			} else {
				item.style.setProperty('display', 'none', 'important');
			}
		}

		// Semantic wrapper used when hiding whole dashboard sections.
		function setBlockVisible(element, visible) {
			setItemVisible(element, visible);
		}

		// Creates pagination and section-tab buttons with one shared helper.
		function buildButton(label, className, disabled) {
			var button = document.createElement('button');
			button.type = 'button';
			button.className = className || '';
			button.textContent = label;
			button.disabled = !!disabled;
			return button;
		}

		// Creates or reuses the pagination controls immediately after a tile grid.
		function ensureControls(container) {
			var controls = container.nextElementSibling && container.nextElementSibling.classList && container.nextElementSibling.classList.contains(controlsClass)
				? container.nextElementSibling
				: null;

			if (!controls) {
				controls = document.createElement('div');
				controls.className = controlsClass;
				container.insertAdjacentElement('afterend', controls);
			}

			return controls;
		}

		// Removes pagination when a grid has only one page.
		function clearControls(container) {
			var controls = container.nextElementSibling;
			if (controls && controls.classList && controls.classList.contains(controlsClass)) {
				controls.parentNode.removeChild(controls);
			}
		}

		// Applies the current page to one grid and keeps New Appointment as the last tile.
		function renderContainer(container) {
			var state = container._isuDashboardPaginationState;
			if (!state) return;

			try {
				var section = getSectionItems(container);
				var items = section.items;
				var newTile = section.newTile;
				var realPerPage = newTile ? Math.max(1, perPage - 1) : perPage;
				var pageCount = Math.ceil(items.length / realPerPage);

				Array.prototype.forEach.call(items, function(item){
					setItemVisible(item, true);
				});

				if (newTile) {
					setItemVisible(newTile, true);
				}

				if (!Number.isFinite(pageCount) || pageCount <= 1) {
					clearControls(container);
					state.page = 1;
					container.setAttribute('data-lp-dashboard-pagination-page', '1');
					return;
				}

				state.page = Math.min(Math.max(1, parseInt(state.page, 10) || 1), pageCount);
				container.setAttribute('data-lp-dashboard-pagination-page', String(state.page));

				var start = (state.page - 1) * realPerPage;
				var end = start + realPerPage;

				Array.prototype.forEach.call(items, function(item, index){
					setItemVisible(item, index >= start && index < end);
				});

				if (newTile) {
					setItemVisible(newTile, false);

					// The original button stays hidden; this clone appears on every page.
					var pageNewTile = newTile.cloneNode(true);
					pageNewTile.classList.add(cloneClass);
					setItemVisible(pageNewTile, true);
					container.appendChild(pageNewTile);
				}

				renderControls(container, state, pageCount);
			} catch (error) {
				resetContainer(container);
			}
		}

		// Fails open if LatePoint changes the dashboard markup unexpectedly.
		function resetContainer(container) {
			directChildren(container, '.' + cloneClass).forEach(function(clone){
				if (clone.parentNode) {
					clone.parentNode.removeChild(clone);
				}
			});

			Array.prototype.forEach.call(container.children || [], function(item){
				if (!item.classList || !item.classList.contains(hiddenClass)) {
					return;
				}

				item.classList.remove(hiddenClass);
				item.hidden = false;
				item.style.removeProperty('display');
			});

			clearControls(container);
		}

		// Rebuilds <, page numbers, and > controls for a single grid.
		function renderControls(container, state, pageCount) {
			var controls = ensureControls(container);
			controls.innerHTML = '';

			var prev = buildButton('<', 'lp-dashboard-pagination-prev', state.page === 1);
			var pages = document.createElement('div');
			var next = buildButton('>', 'lp-dashboard-pagination-next', state.page === pageCount);

			pages.className = 'lp-dashboard-pagination-pages';

			prev.setAttribute('aria-label', 'Previous page');
			next.setAttribute('aria-label', 'Next page');

			prev.addEventListener('click', function(){
				state.page -= 1;
				renderContainer(container);
			});

			next.addEventListener('click', function(){
				state.page += 1;
				renderContainer(container);
			});

			for (var i = 1; i <= pageCount; i++) {
				(function(page){
					var pageButton = buildButton(String(page), page === state.page ? 'is-active' : '', false);
					pageButton.setAttribute('aria-label', 'Page ' + page);
					pageButton.addEventListener('click', function(){
						state.page = page;
						renderContainer(container);
					});
					pages.appendChild(pageButton);
				})(i);
			}

			controls.appendChild(prev);
			controls.appendChild(pages);
			controls.appendChild(next);
		}

		// Initializes or restores pagination state for one tile grid.
		function initContainer(container) {
			if (!container) {
				return;
			}

			if (!container._isuDashboardPaginationState) {
				container._isuDashboardPaginationState = {
					page: parseInt(container.getAttribute('data-lp-dashboard-pagination-page'), 10) || 1
				};
			}

			container.setAttribute(initializedAttr, '1');
			renderContainer(container);
		}

		// Initializes all dashboard grids, then adds the inner Appointments tabs.
		function initDashboardPagination(root) {
			var scope = root && root.querySelectorAll ? root : document;
			var containers = scope.querySelectorAll('.latepoint-w .customer-bookings-tiles, .latepoint-w .customer-orders-tiles');

			Array.prototype.forEach.call(containers, initContainer);
			initDashboardSectionTabs(scope);
		}

		// Maps Upcoming/Bundles/Past/Cancelled headings to their following tile grids.
		function getBookingSections(tabContent) {
			var containers = directChildren(tabContent, '.customer-bookings-tiles');

			return containers.map(function(container, index){
				var heading = container.previousElementSibling && container.previousElementSibling.classList.contains('latepoint-section-heading-w')
					? container.previousElementSibling
					: null;
				var label = heading ? elementText(heading.querySelector('.latepoint-section-heading')) : '';

				return {
					index: index,
					label: label || 'Section ' + (index + 1),
					heading: heading,
					container: container
				};
			}).filter(function(section){
				return !!section.heading && !!section.container;
			});
		}

		// Normalizes visible text from labels and headings.
		function elementText(element) {
			return element && element.textContent ? element.textContent.replace(/\s+/g, ' ').trim() : '';
		}

		// Returns the pagination block that belongs to one Appointments section.
		function getSectionControls(section) {
			var controls = section.container.nextElementSibling;
			return controls && controls.classList && controls.classList.contains(controlsClass) ? controls : null;
		}

		// Shows one Appointments subsection and hides the rest.
		function setActiveBookingSection(tabContent, activeIndex) {
			var state = tabContent._isuDashboardSectionTabsState;
			if (!state) return;

			state.activeIndex = Math.min(Math.max(0, activeIndex), state.sections.length - 1);
			tabContent.setAttribute('data-lp-dashboard-section-tab', String(state.activeIndex));

			state.sections.forEach(function(section, index){
				var isActive = index === state.activeIndex;
				setBlockVisible(section.heading, isActive);
				setBlockVisible(section.container, isActive);
				setBlockVisible(getSectionControls(section), isActive);
			});

			Array.prototype.forEach.call(state.tabs.querySelectorAll('.' + sectionTabClass), function(tab, index){
				tab.classList.toggle('is-active', index === state.activeIndex);
				tab.setAttribute('aria-selected', index === state.activeIndex ? 'true' : 'false');
			});
		}

		// Creates the Upcoming/Bundles/Past/Cancelled tab bar in the best available place.
		function ensureSectionTabs(tabContent, sections) {
			var tabs = directChildren(tabContent, '.' + sectionTabsClass)[0] || null;

			if (!tabs) {
				tabs = document.createElement('div');
				tabs.className = sectionTabsClass;
				tabs.setAttribute('role', 'tablist');

				var timezoneSelector = directChildren(tabContent, '.latepoint-customer-timezone-selector-w')[0] || tabContent.querySelector('.latepoint-customer-timezone-selector-w');
				var insertAfter = timezoneSelector || sections[0].heading.previousElementSibling;

				if (insertAfter && insertAfter.parentNode === tabContent) {
					insertAfter.insertAdjacentElement('afterend', tabs);
				} else {
					sections[0].heading.insertAdjacentElement('beforebegin', tabs);
				}
			}

			tabs.innerHTML = '';

			sections.forEach(function(section, index){
				var button = buildButton(section.label, sectionTabClass, false);
				button.setAttribute('role', 'tab');
				button.setAttribute('aria-selected', 'false');
				button.addEventListener('click', function(){
					setActiveBookingSection(tabContent, index);
				});
				tabs.appendChild(button);
			});

			return tabs;
		}

		// Initializes inner tabs for the Appointments tab content only.
		function initDashboardSectionTabs(root) {
			var scope = root && root.querySelectorAll ? root : document;
			var tabContents = scope.querySelectorAll('.latepoint-w .tab-content-customer-bookings');

			Array.prototype.forEach.call(tabContents, function(tabContent){
				var sections = getBookingSections(tabContent);

				if (sections.length <= 1) {
					return;
				}

				var tabs = ensureSectionTabs(tabContent, sections);
				var previousActiveIndex = parseInt(tabContent.getAttribute('data-lp-dashboard-section-tab'), 10);

				tabContent._isuDashboardSectionTabsState = {
					activeIndex: Number.isFinite(previousActiveIndex) ? previousActiveIndex : 0,
					sections: sections,
					tabs: tabs
				};

				setActiveBookingSection(tabContent, tabContent._isuDashboardSectionTabsState.activeIndex);
			});
		}

		// Debounces reinitialization after external dashboard refresh events.
		function scheduleInit(root) {
			window.clearTimeout(scheduleInit.timer);
			scheduleInit.timer = window.setTimeout(function(){
				initDashboardPagination(root || document);
			}, 50);
		}

		document.addEventListener('DOMContentLoaded', function(){
			initDashboardPagination(document);
		});

		document.addEventListener('lp:paymentConfirmedContentRefreshed', function(event){
			scheduleInit(event.detail && event.detail.container ? event.detail.container : document);
		});
	})();
	</script>
	<?php
}

add_action('wp_footer', 'isu_latepoint_dashboard_pagination_print_assets', 1001);
