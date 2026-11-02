# Adversarial security and correctness review: Wallet Up free 6.7.0

Independent read of the complete branch diff against `origin/main` (including the then-uncommitted SelfCheckPanel.php, MountHealth.php and ScanRoutes.php). WordPress core behaviour was verified against the WordPress 7.1 checkout used by the local proof harness. All findings were triaged by the author afterwards; see `audit/PROOF.md`, section "Adversarial audit", for what was changed.

## Findings (most severe first)

### 1. HIGH. Unauthenticated order-id spoofing emailed the merchant and annotated arbitrary WooCommerce orders
`src/Plugin.php` wires `wp_ajax_nopriv_walletup_confirm_payment_sent` into the free build, and `PaymentGatewayHandler::handleConfirmPaymentSent` fires `wallet_up_payment_confirmed`, which `FreeCounter` turns into an email.
- `wc_order_id` was taken from POST with no order-key check; the handler loaded any order, copied its billing name, email and total into the confirmation, added an order note, and emailed the merchant with the real customer's details.
- `payment_method` was only `sanitize_text_field`, not whitelisted, and landed in the email subject and body through `FreeCounter::methodName()`, so attacker-controlled text reached the merchant inbox.
- The rate limit was 10 per minute per IP only; the nonce printed on public pages is the uid-0 nonce (valid 12 to 24 hours).
- The body said "Reply to this email to reach them" but no `Reply-To` header was set.
- The "already received" message distinguished pending confirmations by order id.

Fix applied: order key required and compared with `hash_equals`; payment method validated against the known id list; `Reply-To` set when the payer gave an address; a daily cap on confirmation emails.

### 2. MEDIUM. Light-request classifier misclassified REST and alternate-cron requests
`wallet_up_is_light_request()` is decided when the plugin file loads, before `REST_REQUEST` exists, so the only REST guard was a substring test on the URI. Sites that change the REST prefix, `rest_route` sent in the POST body, and `ALTERNATE_WP_CRON` (`?doing_wp_cron`, where `wp-cron.php` runs inside the visitor request) all took the light path, so `rest_api_init` registrations and cron callbacks registered only on the full path were missing.

Fix applied: the URI check uses `rest_get_url_prefix()`; `rest_route` and `doing_wp_cron` take the full path.

### 3. MEDIUM. Nopriv `wallet_up_payment_event` let a bot bloat wp_options and inflate counters
The dedupe key included an attacker-chosen `post_id`, so each new integer bypassed dedupe, wrote two option rows and bumped `clicks`; ten clicks flipped the review prompt and hid the onboarding banner.

Fix applied: `post_id` bounded to real posts, a per-IP hourly cap, and no nonce (the counter is harmless and the nonce broke on cached pages).

### 4. MEDIUM. Zelle still rendered a fabricated QR when no bank QR was uploaded
`Zelle::qrPayload()` returned an empty string, but `PaymentRegistry::qrPayloadFor` treated that as "no opinion" and fell through to the generic payload (the bare email or phone).

Fix applied: a method without a native link owns the empty answer.

### 5. MEDIUM. "Turn on notifications" silently opted the install in to Freemius
`FreeExperience::notifyOptIn` called `opt_in()` on every click while the copy only promised plugin emails.

Fix applied: separate, default-off checkbox; the button alone stores the address locally.

### 6. MEDIUM. Free P2P gateway constructed two or three times per request
WooCommerce and WooCommerce Blocks each construct the gateway; the constructor added the thank-you and email hooks each time, so the on-hold customer email printed the instructions twice.

Fix applied: hooks registered once per request.

### 7. LOW/MEDIUM. MountHealth recorded false "admin did not load" failures that stuck for a week
At 2.5 seconds the script recorded a failure; a mount at 2.6 seconds did not clear it.

Fix applied: fallback shows at 2.5 seconds, failure recorded only after 10 seconds or on a bundle script error, and cleared by any later mount.

### 8. LOW. Light path changed front-end behaviour of two existing services
`AdminThemeManager` (front-end `show_admin_bar` and `wp_nav_menu_args` filters when the admin theme is on) and `MerchantProvisioning::addPortalLink` (admin bar) were no longer registered on visitor requests.

Fix applied: `AdminThemeManager` constructed on every request; logged-in users take the full path.

### 9. LOW. FreeCounter data hazards
Read-modify-write of one option per render (accepted, documented); `save()` could throw on a corrupted non-array value; `first_click_email_at` was stamped even when no notify email was configured; `method=unknown` was accepted.

Fix applied: guards in `load()`, stamp only when sent, unknown rejected.

### 10. LOW. Uninstall and deactivation hygiene
No `uninstall.php` and no uninstall hook existed anywhere in the plugin; none of the new options were removed on uninstall.

Fix applied: cleanup through Freemius' `after_uninstall`; shared data kept when Pro is installed.

### 11. LOW. `saveCustomQr` URL validation was loose
Prefix plus extension check accepted `../` and query-string tricks (admin-only impact).

Fix applied: no `?`, `#` or `..`, and the URL must resolve to an attachment of this site; `esc_url` at output.

### 12. LOW. Small output and robustness nits
Unescaped label in the Plugins row meta; hard-coded `/wp-admin/` in a dashboard link; byte-based `substr()` on the survey reason; no-JS form did not validate the handle. All fixed.

## Light request classifier and FreemiusFrontendStub
`admin-post.php` and `admin-ajax.php` define `WP_ADMIN`, so `is_admin()` is true and both take the full path. `$pagenow` is set before plugins load, so the `wp-login.php` test works at include time. Every method called on `wallet_up_fs()` in `src/` and `wallet-up-start.php` was listed against the stub's answer; no TypeError or fatal path was found on the free build. `?wc-ajax=checkout` is a front-end request and therefore light; everything the free gateway needs there is registered unconditionally, so checkout works.

## Verified OK
Nonce and `manage_options` on every new state-changing handler; escaping in every new HTML string; the Zelle upload handler (MIME by data-URI prefix and `getimagesizefromstring`, size cap, `wp_upload_bits`); the free build creates `walletup_payment_transactions` on activation; `wallet_up_enabled_methods` string values are normalised everywhere they are read; strict_types files pass correctly typed values; `SiteLocale::onActivation` only seeds currency when the owner has not set one; `FreeP2pGateway::is_available()` requires a saved holder, an enabled method and a USD store for US-only apps; `FreeCounter::init()` schedules the daily hook once and `Plugin::deactivate()` unschedules it.
