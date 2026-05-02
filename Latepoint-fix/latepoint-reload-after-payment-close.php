<?php
/**
 * Plugin Name: LatePoint Refresh After Payment Close
 * Description: Reloads the page after a customer closes a successful LatePoint payment confirmation lightbox.
 *
 * LatePoint can leave customer dashboard/order widgets stale after a successful
 * checkout inside a lightbox. This plugin detects only real payment or
 * appointment confirmation screens, prevents close links from adding "#" to the
 * URL, and performs one normal page reload after the success lightbox is closed.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Prints a frontend/admin guard that reloads the current page after the success modal is closed.
 */
function lp_reload_after_payment_close_print_script(): void {
	?>
	<script id="lp-reload-after-payment-close">
	(function(){
		if (window.lpReloadAfterPaymentCloseLoaded) return;
		window.lpReloadAfterPaymentCloseLoaded = true;

		var shouldRefreshAfterClose = false;
		var refreshStarted = false;

		// Normalizes modal text so success checks are not sensitive to spacing.
		function elementText(element) {
			return element && element.textContent ? element.textContent.replace(/\s+/g, ' ').trim() : '';
		}

		// Detects only real paid/confirmed success screens, not every confirmation step.
		function hasSuccessfulConfirmationMarkup(scope) {
			if (!scope || !scope.querySelector) return false;

			if (scope.querySelector('.payment-confirmation-wrapper')) return true;

			var status = scope.querySelector('.summary-status-inner');
			if (status) {
				var title = elementText(status.querySelector('.ss-title')).toLowerCase();
				var confirmationNumber = status.querySelector('.ss-confirmation-number strong');
				var statusText = elementText(status).toLowerCase();

				if (
					title.indexOf('appointment confirmed') !== -1 ||
					title.indexOf('payment confirmed') !== -1 ||
					statusText.indexOf('payment confirmed') !== -1 ||
					!!confirmationNumber
				) {
					return true;
				}
			}

			var heading = elementText(scope.querySelector('.latepoint-heading-w')).toLowerCase();
			var confirmationContext = scope.querySelector('[data-step-code="confirmation"], .confirmation-info-w');

			return !!confirmationContext && (
				heading.indexOf('payment confirmed') !== -1 ||
				heading.indexOf('appointment confirmed') !== -1
			);
		}

		// Small wrapper for readability at click/close call sites.
		function lightboxHasSuccessfulPayment(lightbox) {
			return hasSuccessfulConfirmationMarkup(lightbox);
		}

		// Remembers that a success modal was added to the page and should trigger refresh on close.
		function markIfSuccessfulPaymentExists(root) {
			var scope = root && root.querySelectorAll ? root : document;
			var lightboxes = scope.querySelectorAll ? scope.querySelectorAll('.latepoint-lightbox-w') : [];

			for (var i = 0; i < lightboxes.length; i++) {
				if (hasSuccessfulConfirmationMarkup(lightboxes[i])) {
					shouldRefreshAfterClose = true;
					return;
				}
			}

			if (hasSuccessfulConfirmationMarkup(scope)) {
				shouldRefreshAfterClose = true;
			}
		}

		// The stable production behavior: reload the whole page after successful payment close.
		function fallbackReload() {
			window.location.reload();
		}

		// Guards against duplicate reloads when several close hooks fire for the same modal.
		function refreshContentOnce() {
			if (!shouldRefreshAfterClose || refreshStarted) return;

			refreshStarted = true;
			fallbackReload();
		}

		// Closes the LatePoint lightbox using the native function when it is available.
		function closeLightbox(lightbox) {
			if (typeof window.latepoint_lightbox_close === 'function') {
				window.latepoint_lightbox_close();
				return;
			}

			if (lightbox && lightbox.parentNode) {
				lightbox.parentNode.removeChild(lightbox);
			}

			document.body.classList.remove('latepoint-lightbox-active');
		}

		// Captures close clicks early so href="#" never changes the URL after success.
		document.addEventListener('click', function(event){
			var target = event.target && event.target.closest ? event.target : null;
			var closeButton = target ? target.closest('.latepoint-lightbox-close') : null;
			if (!closeButton) return;

			var lightbox = closeButton.closest ? closeButton.closest('.latepoint-lightbox-w') : null;
			var closeScope = lightbox || (closeButton.closest ? closeButton.closest('.latepoint-w') : null);

			if (lightboxHasSuccessfulPayment(closeScope)) {
				event.preventDefault();
				event.stopPropagation();
				event.stopImmediatePropagation();
				shouldRefreshAfterClose = true;
				closeLightbox(lightbox);
				window.setTimeout(refreshContentOnce, 80);
			}
		}, true);

		// Wraps LatePoint's own close helper for cases where close happens without a direct click.
		function wrapLatePointLightboxClose() {
			if (typeof window.latepoint_lightbox_close !== 'function' || window.latepoint_lightbox_close.lpContentRefreshWrapped) {
				return;
			}

			var originalClose = window.latepoint_lightbox_close;
			window.latepoint_lightbox_close = function(){
				if (lightboxHasSuccessfulPayment(document.querySelector('.latepoint-lightbox-w'))) {
					shouldRefreshAfterClose = true;
				}

				var result = originalClose.apply(this, arguments);
				window.setTimeout(refreshContentOnce, 60);

				return result;
			};
			window.latepoint_lightbox_close.lpContentRefreshWrapped = true;
		}

		// Watches for LatePoint success modals that are injected after AJAX payment steps.
		if (window.MutationObserver) {
			new MutationObserver(function(mutations){
				mutations.forEach(function(mutation){
					Array.prototype.forEach.call(mutation.addedNodes || [], function(node){
						if (node.nodeType === 1) {
							markIfSuccessfulPaymentExists(node);
						}
					});
				});
				wrapLatePointLightboxClose();
			}).observe(document.documentElement, {
				childList: true,
				subtree: true
			});
		}

		// Initial pass plus delayed wrapping for themes/plugins that define LatePoint JS late.
		document.addEventListener('DOMContentLoaded', function(){
			markIfSuccessfulPaymentExists(document);
			wrapLatePointLightboxClose();
			window.setTimeout(wrapLatePointLightboxClose, 500);
			window.setTimeout(wrapLatePointLightboxClose, 1500);
		});
	})();
	</script>
	<?php
}

add_action('wp_footer', 'lp_reload_after_payment_close_print_script', 1000);
add_action('admin_footer', 'lp_reload_after_payment_close_print_script', 1000);
