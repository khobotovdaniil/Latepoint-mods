# LatePoint MU Plugins

This folder contains small must-use plugins for the WordPress + LatePoint setup.
The main goal is to keep local fixes isolated from LatePoint core files and from
the active theme, so updates can be tested and moved to production safely.

## Loader

`Loader.php` is the only PHP file that must stay in the root `mu-plugin` folder.
WordPress loads root-level MU plugin files automatically, but it does not load
PHP files inside subdirectories. The loader scans first-level subdirectories,
sorts folders and files by name, and `require_once`s every `*.php` file it finds.

Important consequences:

- Any PHP file placed in a first-level subdirectory is active.
- Optional or archived plugins should be moved outside the loaded subdirectories
  or renamed so they do not end with `.php`.
- The loader does not recurse into nested subdirectories.
- Keep each plugin independent: guard with `ABSPATH`, use unique function/class
  prefixes, and avoid relying on another custom plugin unless it is documented.

## Production Set

Recommended production files:

- `latepoint-fresh-invoices/latepoint-fresh-invoices.php`
- `latepoint-add-tips-before-checkout/latepoint-invoice-tips.php`
- `latepoint-add-tips-before-checkout/latepoint-tips-order.php`
- `Latepoint-fix/latepoint-coupon-admin-discount-fix.php`
- `Latepoint-fix/latepoint-customer-hidden-fields-preserve.php`
- `Latepoint-fix/latepoint-empty-cart-checkout-guard.php`
- `Latepoint-fix/latepoint-midnight-datetime-normalizer.php`
- `Latepoint-fix/latepoint-reload-after-payment-close.php`
- `Latepoint-frontend-mods/latepoint-cart-menu.php`
- `Latepoint-frontend-mods/latepoint-customer-timezone-profile.php`
- `Latepoint-frontend-mods/latepoint-dashboard-archive-loader.php`
- `Latepoint-midnight-mods/latepoint-midnight-availability.php`
- `Latepoint-midnight-mods/latepoint-midnight-working-hours.php`

Optional/fallback:

- `Latepoint-frontend-mods/latepoint-dashboard-pagination.php`

`latepoint-dashboard-pagination.php` should normally be disabled on production
when `latepoint-dashboard-archive-loader.php` is active. The archive loader owns
the customer dashboard pagination and disables the older client-side pagination
hook defensively, but keeping only one active dashboard owner is cleaner.

## Plugins

### `latepoint-fresh-invoices/latepoint-fresh-invoices.php`

Keeps manually created LatePoint invoices, payment links, and order dashboard
totals synchronized with the current order and the manually entered invoice
amount.

- Rebuilds new invoice data from the current order when an invoice is created or
  updated.
- Preserves the final amount entered manually on the invoice.
- Adds `Manual invoice discount` when the invoice amount is lower than the
  current order total.
- Adds `Additional services` when the invoice amount is higher than the current
  order total.
- Syncs invoice `charge_amount` and open transaction intents so payment links
  charge the actual invoice amount.
- Works with invoice tips: paid tips are included in order/dashboard totals only
  after successful payment.
- Ignores abandoned checkout tip selections for unpaid invoices, so an unpaid
  order does not get a fake balance from a tip the customer did not pay.
- Protects paid orders after admin `Recalculate`: the dashboard total, payment
  status, and balance stay aligned to the paid invoice total.

This plugin intentionally stays separate from
`latepoint-add-tips-before-checkout`. The tips plugin owns tip selection and tip
metadata; this plugin owns invoice/order normalization. It runs invoice route
hooks at priority `8`, before invoice tip route handling at priority `9`, and
runs order/transaction normalization at priority `50`, after the existing
price-breakdown and tip filters.

### `latepoint-add-tips-before-checkout/latepoint-invoice-tips.php`

Adds optional tips to LatePoint invoice payment request pages.

- Tip options: `0`, `5`, `10`, `15`, `20`, `25` percent.
- Supports a custom amount through `lp_invoice_tip_amount`.
- Percent value is sent through `lp_invoice_tip_percent`.
- If a custom amount is selected, saved percent becomes `0`.
- Custom tips render without a percent badge.
- Syncs invoice data, order meta, price breakdown rows, receipts, and templates.

Use this for payment links/invoices, not for the normal booking checkout step.

### `latepoint-add-tips-before-checkout/latepoint-tips-order.php`

Adds a separate LatePoint `Tips` step between Verify and Payment for normal
checkout orders.

- Tip options: `0`, `5`, `10`, `15`, `20`, `25` percent.
- Adds a `Custom amount` option and `lp_order_tip_amount` input.
- Custom amount has priority over percent.
- Stores temporary selection by cart UUID.
- Saves `order_tip_percent` and `order_tip_amount` on order conversion.
- Custom tips save `order_tip_percent = 0` and render without a percent badge.
- Skips the Tips step when a customer is scheduling a lesson from an already
  placed bundle/order item.
- Avoids running tip total/breakdown logic before the checkout tail, so earlier
  booking steps such as bundle selection stay light.

This plugin is for regular cart/order checkout. It complements, but does not
replace, the invoice tips plugin.

Use `latepoint-fresh-invoices.php` together with this file when manual invoices
can be edited, recreated, discounted, increased, or recalculated after payment.

### `Latepoint-fix/latepoint-coupon-admin-discount-fix.php`

Keeps coupon credit rows negative in LatePoint price breakdowns.

- Normalizes coupon credit rows in cart/order price-breakdown filters.
- Restores negative values in LatePoint admin/front forms after input masks run.
- Uses a small MutationObserver because LatePoint frequently injects or refreshes
  price breakdown markup through AJAX.

This plugin is independent from the tips plugins, but it touches the same
price-breakdown area. Keep it active if coupon discounts can appear as positive
values or be overwritten by input masks.

### `Latepoint-fix/latepoint-customer-hidden-fields-preserve.php`

Preserves LatePoint Pro Features customer custom fields that are not editable by
customers during booking.

- Protects customer fields with visibility `Temporary hidden` or
  `Admin and agents only`.
- Prevents hidden/admin-only customer meta from being overwritten with an empty
  value when the customer moves from Customer Information to Verify Order
  Details.
- Stashes protected customer meta at the customer booking-step boundary and
  restores it after LatePoint finishes processing the step.
- Also guards model-level customer/meta saves in case Pro Features saves an
  empty custom field directly.
- Leaves admin and agent contexts untouched so staff can still view and edit
  those fields from LatePoint admin/profile screens.
- Supports staging and production prefixes through LatePoint's
  `LATEPOINT_TABLE_CUSTOMER_META` table constant.

Debug logging is off unless `ISU_LATEPOINT_HIDDEN_FIELDS_DEBUG` is defined and
truthy, or the `isu_latepoint_hidden_fields_debug_enabled` filter returns true.

### `Latepoint-fix/latepoint-empty-cart-checkout-guard.php`

Prevents empty LatePoint carts from continuing into checkout.

- Stops `verify`, `tips`, `payment`, and `confirmation` from loading when all
  cart items were removed before checkout.
- Allows valid bundle scheduling flows that use `presets[order_item_id]`, even
  when the cart itself is empty until final submit.
- Allows normal confirmation after an order has already been created.
- Adds a small frontend shim so removing the last cart item from the side
  summary lets LatePoint restart the booking form cleanly.

This plugin is independent from the tips plugin. The tips plugin hides its own
step for bundle scheduling; this guard protects the wider checkout flow from
zero-item orders.

### `Latepoint-fix/latepoint-midnight-datetime-normalizer.php`

Normalizes exact-midnight date/time values before LatePoint or calendar links use
them.

- Fixes cases where an appointment ending at `00:00` can be interpreted as a
  very long event.
- Corrects customer "add to calendar" date ranges for midnight-crossing events.
- Runs early so external sync code receives normalized start/end datetimes.

This is a companion fix for the midnight plugins and should stay active with
them.

### `Latepoint-fix/latepoint-reload-after-payment-close.php`

Reloads the page after a customer closes a successful LatePoint payment
confirmation lightbox.

- Detects real success/confirmation screens only.
- Prevents close links with `href="#"` from adding `#` to the URL.
- Uses a normal full page reload after successful payment close.
- Does not try to soft-refresh dashboard content, because that broke customer
  cabinet behavior.

This plugin is independent from the dashboard archive loader. It simply makes
post-payment UI state honest after the success modal closes.

### `Latepoint-frontend-mods/latepoint-cart-menu.php`

Adds a LatePoint cart icon button next to the header `Contact Us` button.

- Shows a cart badge with the current LatePoint cart item count.
- Hides the button when the cart is empty.
- Opens checkout in a LatePoint lightbox from the Verify Order Details step.
- Uses a lightweight database `COUNT(*)` for badge refreshes instead of building
  every cart item model.
- Debounces and reuses in-flight badge refreshes so LatePoint AJAX bursts do not
  compete with checkout opening.
- Creates/updates the LatePoint order intent only when the customer actually
  opens checkout.
- Marks header-cart checkout forms so pressing Back from Customer Information
  restarts the flow instead of falling into stale booking steps.
- Uses CSS spinner, not a static image.
- Frontend only; it does not print assets in `is_admin()`.

The checkout step can be changed with the
`isu_latepoint_header_cart_checkout_step` filter.

### `Latepoint-frontend-mods/latepoint-customer-timezone-profile.php`

Adds customer timezone controls where customers and admins are more likely to
find them.

- Adds a timezone selector to customer profile/customer information forms.
- Adds timezone editing to LatePoint admin customer edit forms.
- Saves the value to LatePoint customer meta key `timezone_name`.
- Uses saved customer timezone as the default booking timezone.
- After a successful profile timezone change, refreshes the customer dashboard
  Appointments/Orders sections and notifies dashboard plugins to reinitialize.

This plugin does not try to recalculate every open booking cart after timezone
changes. It keeps saved profile/dashboard timezone state synchronized and leaves
active new-appointment cart summaries to LatePoint's normal flow.

### `Latepoint-frontend-mods/latepoint-dashboard-archive-loader.php`

Primary customer dashboard performance plugin.

- Replaces the `[latepoint_customer_dashboard]` shortcode.
- Prevents LatePoint from loading every historical booking/order at first render.
- Loads limited batches server-side and paginates locally.
- Adds period and sort controls for appointments/orders.
- Uses AJAX to load more records when the customer navigates to pages that need
  data not yet loaded.
- Keeps `New Appointment` as the final tile on appointment pages.
- Uses compact pagination when there are many pages.

This plugin should be the only active owner of customer dashboard pagination on
production.

Debug logging is off unless the constant
`ISU_LATEPOINT_DASHBOARD_ARCHIVE_DEBUG` is defined and truthy.

### `Latepoint-frontend-mods/latepoint-dashboard-pagination.php` optional

Older client-side dashboard pagination plugin.

- Does not query the server.
- Only paginates records already present in the page HTML.
- Splits appointments into internal tabs such as Upcoming, Bundles, Past, and
  Cancelled.
- Keeps `New Appointment` on appointment pages.

Mark this as optional/fallback. Do not keep it active together with the archive
loader unless intentionally testing fallback behavior.

### `Latepoint-midnight-mods/latepoint-midnight-availability.php`

Fixes availability and admin calendar rendering for bookings that cross
midnight.

- Blocks the next-day tail of cross-midnight appointments.
- Filters available agents/locations for midnight-edge slots.
- Renders next-day tails in the admin calendar/daily timeline.
- Adds dashboard/admin appointment timeline segments for cross-midnight events.
- Keeps the booking itself as one LatePoint booking while making both calendar
  days visually and availability-wise honest.

This plugin handles existing bookings and visual/availability consequences. It
works together with the working-hours bridge.

### `Latepoint-midnight-mods/latepoint-midnight-working-hours.php`

Allows late-day slots to cross midnight when the next day has matching early
availability.

- Treats work periods ending at or after 24 hours as ending at 24:00.
- Removes invalid after-midnight source slots from the current day.
- Adds bridge slots near the end of the day when the next day has enough matching
  availability.
- Allows examples such as `23:30-00:30` or `23:45-00:15` when the selected
  agent/service/location is also available after midnight on the next day.
- Supports longer lessons in principle as long as the required next-day interval
  is covered by availability.

Debug logging is off unless `LP_MIDNIGHT_HOURS_DEBUG` is defined and truthy.
Verbose debug requires `LP_MIDNIGHT_HOURS_VERBOSE_DEBUG`.

## Midnight Booking Model

The midnight fixes are split intentionally:

- `latepoint-midnight-working-hours.php` creates valid bookable late-day slots by
  looking at next-day availability.
- `latepoint-midnight-availability.php` makes already-created cross-midnight
  bookings block/render correctly across both dates.
- `latepoint-midnight-datetime-normalizer.php` protects exact-midnight datetime
  values before external calendar links or sync code interpret them.

Use all three together for production midnight support.

## Deployment Checklist

1. Copy only intended production files into loaded first-level subdirectories.
2. Keep optional/fallback files out of loaded folders unless needed.
3. Run PHP lint on every changed file:

   ```powershell
   C:\OpenServer\modules\php\PHP_8.1\php.exe -l <file>
   ```

4. Clear WordPress/browser caches if frontend JS or shortcode output changed.
5. Test:
   - normal checkout with and without tips;
   - invoice payment request with percent and custom tips;
   - manually created invoice lower than the current order total;
   - manually created invoice higher than the current order total;
   - recreated invoice after removing or changing an order item;
   - invoice payment link amount after a manual invoice total override;
   - paid invoice with tips, then admin `Recalculate` inside the order;
   - unpaid invoice where the customer selected a tip but abandoned payment;
   - successful payment close and page reload;
   - cart icon count and checkout lightbox;
   - header cart checkout with a bundle in the cart;
   - Back from Order Details to Customer Information, then Back to start;
   - hidden/admin-only customer custom fields survive Customer Information to
     Verify Order Details;
   - customer dashboard Past/Cancelled/Orders pagination;
   - customer profile timezone save and dashboard refresh;
   - empty-cart removal on Customer and Service steps;
   - bundle lesson scheduling from an already placed order;
   - `23:xx` bookings crossing midnight;
   - `00:xx` next-day availability blocking;
   - Google Calendar/add-to-calendar exact-midnight event ranges.

## Notes

- The site and LatePoint timezone are expected to be GMT+4 / Asia-Dubai.
- The plugins use the active WordPress table prefix where direct database access
  is needed, so staging prefixes such as `wpstg0_` and production prefixes such
  as `wp_` are both supported.
- Avoid editing LatePoint core files for these fixes. Keep changes in this
  mu-plugin structure so they can be reviewed, disabled, and moved independently.
