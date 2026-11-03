# Build proof for 6.4.0 through 6.7.0

Both releases were built on this branch and verified by driving the real plugin in a local WordPress 7.1 (PHP 8.3, SQLite drop-in) with headless Chromium, the same harness used for the audit. Nothing was published; all commits are local to the branch.

The harness is in `proof/harness/`: `sync.sh` copies the working tree into the test site, `reset.sh` reinstalls a fresh WordPress, `prove.js` walks the first run as a user and writes `results.json` plus numbered screenshots. Each run below has its own folder under `proof/`.

## What each release changed

**6.4.0**

| # | Change | Where |
|---|---|---|
| 1 | Freemius runs in anonymous mode; Activate lands on the setup assistant | `wallet-up-start.php` |
| 2 | "Permalinks Required" error replaced by an informational, dismissible notice shown only on Wallet Up screens when a Pro feature benefits from pretty URLs; dismissal persists | `src/Core/RewriteRulesManager.php` |
| 3 | Setup step 3 and the WooCommerce/WPForms admin notices state what the free plan does and does not do | `walup-react/dist/assets/OnboardingFlow.*.js`, `main.*.js`, `src/Core/Admin/Notices/IntegrationDetector.php` |
| 4 | Every business preset pre-selects one payment method | `walup-react/dist/assets/OnboardingFlow.*.js` |
| 10 | Listing title, tags, summary, intro paragraph and screenshot labels describe the free tier; live-preview blueprint no longer installs WooCommerce | `readme.txt`, `blueprint.json` |

**6.4.1**

| # | Change | Where |
|---|---|---|
| 5 | Sidebar "Unlock all 20 payment methods" card removed; Explore Pro moved to the end of the menu; "Finish setting up" reminder limited to Dashboard, Plugins and Wallet Up screens | `WalletUpAdminFree.*.js`, `src/Core/Admin/Notices/OnboardingBanner.php` |
| 6 | Locked pages show the explanation card only, no blurred sample figures | `WalletUpAdminFree.*.js` |
| 7 | Settings gains a Branding card (Powered-by switch, Pro-gated) and a Setup card (rerun the assistant) | `src/Core/Admin/FreeSettingsPanel.php`, `assets/js/admin/free-settings.js`, `src/Core/Display/DisplayHandler.php`, `src/Plugin.php`, Composer classmap |
| 8 | Stripe.js loads for visitors only when card payments are live | `src/Core/Assets/AssetManager.php` |
| 9 | Free build no longer enqueues the media library or WordPress script dependencies on its screens | `src/Core/Assets/AssetManager.php` |

## Measured results

All rows are from `results.json` in the named proof folders. "Before" is 6.3.9 from the audit runs.

| Check | 6.3.9 (before) | 6.4.0 | 6.4.1 |
|---|---|---|---|
| First screen after Activate | Freemius opt-in | Setup assistant "Welcome" | Setup assistant "Welcome" |
| Freemius opt-in shown in the activation path | yes | no | no |
| Plugins row still offers "Opt In" | n/a | yes | yes |
| Permalinks error banner on a plain-permalink site | every admin page, not dismissible | none | none |
| "Finish setting up" reminder on Posts screen | shown (when no method saved) | shown | not shown |
| Methods pre-selected by the Retail preset | 2 (two usernames required) | 1 | 1 |
| Continue enabled after one username | no | yes | yes |
| Step 3 copy with WooCommerce active | "connects to WooCommerce on Pro" | states free plan does not add checkout options | same |
| Menu position of "Explore Pro" | 2nd | 2nd | last |
| Sidebar "Unlock all 20 payment methods" card | present | present | removed |
| Bottom upgrade banner | present | present | present (the one kept) |
| Locked Donations page shows sample figures ($4,280, 47 donors) | yes | yes | no |
| Settings sections | About | About | About, Branding, Setup assistant |
| "Run setup again" lands on the assistant | n/a | n/a | yes |
| External hosts loaded by a visitor on a QR page | js.stripe.com | js.stripe.com | none |
| Media library loaded on Wallet Up screens | yes | yes | no |
| Wallet Up admin page transfer | 5,606 KB / 99 requests | 5,606 KB / 99 | 4,993 KB / 76 |
| WordPress's own Dashboard page transfer (baseline) | 4,370 KB / 90 | same | same |
| PHP fatals in debug.log during the run | 0 | 0 | 0 |
| Browser console errors (excluding blocked external hosts) | 0 | 0 | 0 |

Page weight note: WordPress 7.1 loads its command palette, and with it the block-editor bundle, on every admin page. That accounts for the 4.4 MB baseline. Wallet Up's own overhead on its screen dropped from about 1.2 MB and 24 extra requests to about 0.6 MB and 16 requests; the plugin's own files total 923 KB.

## Regression checks

- `php -l` passes on every changed PHP file; the two edited bundles pass `node --check` as ES modules.
- Deactivate and reactivate: deactivation through WordPress's own link works and reactivation returns to the setup assistant or dashboard with no redirect loop. The Freemius dialog's "Skip & Deactivate" button did not navigate under the headless harness on 6.3.9 and 6.4.1 alike, so that is a harness interaction with the Freemius script, not a change in these releases.
- Pre-existing behaviour noted, not changed: reaching the final "You're ready" step without pressing "Just go to dashboard" or "Create page" leaves setup marked incomplete, so a later reactivation reopens the assistant.
- Legacy `[cashapp]`, `[venmo]`, `[paypal]` shortcodes and the `[wallet_up]` shortcode render for visitors as before, with the Powered-by line intact on the free plan.
- The wizard's fallback form path and the fresh-install activation hooks were exercised on every reset.
- The only PHP warnings in the log come from the sandbox's unbuilt WooCommerce copy, not from Wallet Up.

## What a maintainer must still do

- The React admin source is not in this repository. Items 3, 4, 5 and 6 were applied as string-level edits to the built bundles in `walup-react/dist/assets/`; the same changes need to be made in the JSX source before the next `vite build`, or the rebuild will revert them. The exact strings are in the 6.4.0 and 6.4.1 commit diffs.
- Item 7 adds a class to `src/`; the shipped Composer autoloader is classmap-authoritative, so the classmap files were updated by hand. Run `composer dump-autoload -o` at release time.
- Replace WordPress.org screenshots 2 and 5 with free-tier screens; the readme now labels them as Pro but the images themselves still show Pro features.
- Item 3 was implemented as honest wording. The alternative, shipping one peer-to-peer method as a free WooCommerce gateway, is a product decision and was not built.


# 6.5.0, 6.6.0 and 6.7.0

Same harness, same local WordPress 7.1 / PHP 8.3 / SQLite site. Each release has its own driver: `prove-650.js` (fresh site, optionally with the site clock in Europe/Berlin), `prove-660.js` (WooCommerce 10.9 active, built from source because wordpress.org is unreachable from the sandbox), `prove-670.js` (fresh site, then the React bundle blocked, then JavaScript off). `results.json` in each folder is what the tables below quote.

## What each release changed

**6.5.0** (`proof/6.5.0-us`, `proof/6.5.0-berlin`)

| Change | Where |
|---|---|
| Zelle is a free method: wizard, Payments page, shortcodes, blocks; visitors get a pay-to card with a copy button or the owner's uploaded bank QR | `PaymentMethodsConfig`, new `src/Payments/Zelle.php`, `PaymentRegistry`, `DisplayHandler`, bundles |
| The site's country is detected (owner override → WooCommerce country → timezone → locale); the Welcome step says which methods work there; currency follows the country; PayPal.me links carry the currency | new `src/Core/Geo/SiteLocale.php`, `FreeExperience`, `PayPal.php`, `free-ui.js` |
| A shortcode or block for a method that is off or has no username never renders nothing: visitors get an HTML comment, admins get an inline "Turn on" form | `PaymentRegistry::setupNotice`, `handleTurnOnMethod` |
| Notification opt-in offered after the first QR exists, never before | `FreeExperience::notifyOptIn`, `free-ui.js` |
| Every uninstall reason carries `[version age methods pageviews admin-loaded php country]` | `DeactivationOffer::timeline`, `appendTimelineToReason` |

**6.6.0** (`proof/6.6.0-woocommerce`, `proof/6.6.0-fresh-regression`)

| Change | Where |
|---|---|
| One peer-to-peer method at WooCommerce checkout on the free plan, classic and block checkout; order goes on hold, the customer pays from the order-received page and confirms there | new `src/WooCommerce/FreeP2pGateway.php`, `Blocks/FreeP2pBlocksSupport.php`, `assets/js/free-p2p-checkout-block.js`, `FreeCardBootstrap`, `OnboardingHandler` |
| Views, taps and confirmations are counted; "This week" card on the dashboard; first-tap email, per-confirmation email, one digest after the first week | new `src/Core/Retention/FreeCounter.php`, `DisplayHandler`, `PaymentGatewayHandler`, `free-ui.js` |
| "Create page" publishes the page and lands on it; the review request waits for value | `PaymentPageGenerator`, `ReviewPrompt`, `OnboardingBanner` |

**6.7.0** (`proof/6.7.0-fresh`, `proof/6.7.0-regression-650`, `proof/6.7.0-regression-660`)

| Change | Where |
|---|---|
| Setup check: nine yes/no rows on the dashboard and Payments cards, the Plugins row, an inline notice when the server is the problem, and in both fallbacks | `SetupCheck::checks`, new `src/Core/Admin/SelfCheckPanel.php`, `free-ui.js` |
| Server-rendered fallbacks: the setup form appears on the setup screen after 2.5 s if the app has not mounted; the dashboard shows the saved link, QR, page link and checks; both also render with JavaScript off; the failure is recorded and cleared on the next good mount; the simple form publishes the payment page | new `src/Core/Admin/MountHealth.php`, `OnboardingHandler`, `AdminManager`, `PaymentPageGenerator::publishPage` |
| Visitor page views skip the Freemius SDK, admin notices, AJAX/REST handlers and the QR engine (scan routes split into `ScanRoutes`) | `wallet-up-start.php` (`wallet_up_is_light_request`, `FreemiusFrontendStub`), `Plugin.php`, new `src/Services/ScanRoutes.php` |
| Package trim: two unreferenced web-font sets, two `.wup` bundles, duplicate images | deleted files |
| Honest requirements in the readme (no 256 MB memory claim; WordPress 6.1; GD or Imagick for QR images) | `readme.txt` |
| Timeline tag counted page views from the wrong structure and always said `pv0`; fixed | `DeactivationOffer::timeline` |

## Measured results

| Check | Result | Folder |
|---|---|---|
| Fresh activate lands on the setup assistant, no opt-in | yes | 6.5.0-us, 6.7.0-fresh |
| Zelle selectable in step 3 and saved (`zelle_holder`), enabled | yes | 6.5.0-us |
| Zelle bank-QR upload stored under `wallet_up_custom_qr_images` | yes, uploads URL | 6.5.0-us |
| Welcome step geography line with the clock in Europe/Berlin | shown; "Cash App, Venmo and Zelle work in the United States only" | 6.5.0-berlin |
| Currency seeded from country; PayPal.me link | EUR; `…/paypalme/TestShop/25EUR` | 6.5.0-berlin |
| Visitor output for a method that is off / unconfigured | `<!-- wallet-up: cashapp is switched off -->`, `<!-- wallet-up: venmo is not configured -->` | 6.5.0-us |
| Admin sees "Turn on Cash App"; after clicking, the QR renders | yes / yes | 6.5.0-us |
| Zelle without an uploaded QR | pay-to card with the handle, no broken image | 6.5.0-us |
| Notify opt-in saved | `wallet_up_notify_email` = admin email | 6.5.0-us |
| Stored uninstall reason carries the tag | `just testing [6.x.y 0m m3 pv0 am-yes php8.3 US]` | 6.5.0-us |
| Wizard with WooCommerce: gateway enabled with the chosen method | `woocommerce_wallet_up_p2p_settings` enabled=yes, method=paypal | 6.6.0-woocommerce |
| Classic checkout shows the method; order placed | "Pay with PayPal.me"; order on-hold with method meta | 6.6.0-woocommerce |
| Thank-you page: QR and confirm form once; confirm succeeds | yes; counts {i:2, c:1, f:1} | 6.6.0-woocommerce |
| Emails: confirmation, first tap, digest | all three in `mail.log` | 6.6.0-woocommerce |
| Plugin PHP loaded on a visitor page view (files / KB / Freemius) | 96 / 2,436 / yes → 30 / 451 / no | 6.7.0-fresh |
| Setup screen with the React bundle blocked | mounted=false, inline form shown, failure recorded (`onboarding`) | 6.7.0-fresh |
| Simple form end to end (bundle blocked) | accounts, enabled, completed saved; page 6 published "Pay with PayPal.me"; dashboard fallback shows QR + link + "Setup saved" | 6.7.0-fresh |
| Dashboard with JavaScript off | card, checks table and QR visible in `<noscript>` | 6.7.0-fresh |
| Failure cleared by the next successful mount | option gone | 6.7.0-fresh |
| Checks card on Dashboard and on Payments; nine rows | yes / yes | 6.7.0-fresh |
| Plugins row | "Setup check: 1 thing needs attention (Visitors have seen your code)"; "Opt In" link still offered | 6.7.0-fresh |
| Inline notice when a failure is recorded | "Wallet Up screens load: The visual admin did not load 1 hour ago…" | 6.7.0-fresh |
| Scan redirect with the QR engine lazy | 302 → target | 6.7.0-fresh |
| `[walletup_payment_section]` lazy shortcode | renders the PayPal link | 6.7.0-fresh |
| Timeline tag after three visitor views | `pv3` | 6.7.0-fresh |
| 6.5.0 and 6.6.0 drivers re-run on the 6.7.0 build | all rows unchanged, 0 fatals | 6.7.0-regression-650, 6.7.0-regression-660 |
| PHP fatals / plugin warnings / console errors across all runs | 0 / 0 / 0 | all |

## What a maintainer must still do (added)

- The three React bundle edits from 6.5.0 (Zelle in the free list, geo-aware preset, Woo step sent on free, counters "of 4") are string-level and must be ported to the JSX source before the next `vite build`.
- `ScanRoutes`, `SelfCheckPanel`, `MountHealth`, `Zelle`, `SiteLocale`, `FreeExperience`, `FreeCounter`, `FreeP2pGateway`, `FreeP2pBlocksSupport` were added to the classmap by hand; run `composer dump-autoload -o` at release.
- The Freemius installs export that motivated 6.5.0–6.7.0 contains customer site URLs and is not in the repository.


# Adversarial audit of the whole branch (after 6.7.0)

Three independent reviewers (security and correctness, wiring sweep, product and copy) read the complete diff against `origin/main` without the author's conclusions. Their full reports are in `revamp/agent-reports/audit-security.md`, `audit-wiring.md` and `audit-product.md`. Every finding was checked against the code; the table lists what was changed and what was left, with the reason. The fixes were then re-proven with all three drivers (`proof/6.7.1-fresh`, `6.7.1-regression-650`, `6.7.1-regression-660`).

| Finding | Severity | Action |
|---|---|---|
| A visitor could confirm against any WooCommerce order by id, with free-text in the method name, and the merchant got an email | high | Order key required and compared with `hash_equals`; method id validated against the known list (`PaymentGatewayHandler`, `DisplayHandler` form) |
| Legacy `[cashapp]`/`[venmo]`/`[paypal]`/`[zelle]` rendered while the method was off | must-fix | `PaymentMethod::processShortcode` returns the same setup notice as `[wallet_up]` and the blocks |
| Zelle without a bank QR still drew a QR of the bare email or phone | medium | `PaymentRegistry::qrPayloadFor` lets a method without a native link own the "no QR" answer |
| "Turn on notifications" silently registered the install with Freemius | medium (consent) | Separate, default-off checkbox; the button alone stores the address locally only |
| No way to turn notifications off; emails promised a reply-to that was not set | must-fix | Notifications card under Settings (on/off), "turn off" link in every email, `Reply-To` set when the payer gave an address |
| Owner's own page views counted as visitor views | must-fix | `FreeCounter::onImpression` skips users who can edit posts; banner copy button only copies |
| Tap beacon and confirm form broke on cached pages after 24 h (nonce) | should-fix | Tap counter needs no nonce (bounded per IP and per real post); confirm form carries a page-bound token beside the nonce |
| Confirmation emails had no ceiling | should-fix | Ten a day, then one "more today" email; identical confirmations within ten minutes send nothing |
| Mount watchdog recorded a failure at 2.5 s and never cleared it on a late mount | should-fix | Fallback still shows at 2.5 s; a failure is recorded only after 10 s or on a bundle script error, and cleared by any later mount |
| "Create page" published a new page on every click; re-running setup switched the free checkout gateway off | should-fix | `publishPage` refreshes the generated page; wizard state reads the free gateway option |
| A US-only method could be chosen for a non-USD store and the check said it shows | should-fix | Wizard and setup check apply the same currency rule as the gateway |
| Cash App listed for the United Kingdom | factual | US only |
| Welcome step had no "not in the US" path; default en_US installs were treated as US | should-fix | Neutral line with a country chooser whenever the detection is locale-only or unknown; preset data updated after the choice |
| Free-plan additions anchored on English headings only | must-fix (partial) | Anchors match the English, Spanish and French strings the compiled app ships; a stable hook in the React source is still the right fix |
| Step 3 said checkout placement is Pro, three lines above the free checkbox | copy | `integrationProHint` rewritten (en, es, fr) |
| Light request classifier missed custom REST prefixes, body `rest_route` and alternate cron; dropped the admin theme's front-end filters and admin-bar items for logged-in users | medium | REST prefix from `rest_get_url_prefix()`, `rest_route` and `doing_wp_cron` excluded; logged-in users take the full path; `AdminThemeManager` back on every request |
| Setup-check "Fix" links pointed at `page=wallet-up-payments`, which the free build does not register | defect | Links go to the single free admin page |
| Free gateway constructed by WooCommerce and by WooCommerce Blocks, so email instructions printed twice | defect | Hooks registered once per request |
| `FreeCounter` option could throw on a corrupted value; first-tap stamp set without an email; `method=unknown` accepted | low | Guards added; stamp only when sent; unknown rejected |
| Plugin header said WordPress 5.8 while the readme said 6.1; two Requirements blocks; changelog.txt missing 6.7.0; copy omitting Zelle; "24 methods" vs "20"; "no outgoing connection" overstated | copy/wiring | All corrected |
| No uninstall cleanup for the new options | low | `after_uninstall` (Freemius) removes the free plan's own state; shared data is kept when Pro is installed |
| Uninstall-survey tag unreadable in the modal; byte-based truncation could split a multibyte character | should-fix | Words in the modal, compact tag on the wire, `mb_strcut` |
| `SelfCheckPanel` label unescaped in the Plugins row; hard-coded `/wp-admin/` in a link | nit | Escaped; `adminUrl` passed |
| No-JS setup form did not validate the handle | nit | Same pattern check as the wizard, with a back link |
| `SetupCheck::checks()` ran three times per screen | perf | Memoised per request |
| Found while re-proving: a legacy eight-second script in `AssetManager` appended a Pro-branded "Admin interface failed to load" alert (Cloudflare and file-permission advice) into the React root, which the new watchdog took for a successful mount, hiding the real fallback and clearing the recorded failure | must-fix | Injector removed; the 6.7.0 fallbacks replace it |

## Re-proof after the fixes

| Check | Result | Folder |
|---|---|---|
| Visitor page view (logged out): plugin files / KB / Freemius | 31 / 467 / no (owner logged in on the front end: 34 / 529 / no) | 6.7.1-fresh |
| Setup screen with the bundle blocked: fallback form shown, failure recorded on the script error | yes / yes | 6.7.1-fresh |
| Dashboard with the bundle blocked after the simple form: QR, link, "Setup saved", checks | yes | 6.7.1-fresh |
| Inline notice visible above the app when a failure is recorded; cleared by the next good mount | yes / yes | 6.7.1-fresh |
| Owner's own page views not counted; a visitor's view and tap counted | before 3, after owner 3; visitor +1 view, +1 tap | 6.7.1-fresh |
| `[cashapp]` while Cash App is off | visitor gets `<!-- wallet-up: cashapp is switched off -->`, no link | 6.7.1-fresh |
| Zelle with no uploaded bank QR | pay-to card with the handle, 0 generated QR images | 6.7.1-fresh |
| Tap beacon without a nonce / with a bad method id | 200 counted / 400 rejected | 6.7.1-fresh |
| "Create page" twice | same page id both times | 6.7.1-fresh |
| Settings cards | Where you are, Notifications, Branding, Setup assistant | 6.7.1-fresh |
| Survey modal text | "installed 54 seconds ago · 3 methods saved · 4 page views · admin loaded · PHP 8.3 · the United States" | 6.7.1-fresh |
| Welcome line on a default en_US site | neutral line with a country chooser | 6.7.1-regression-650 |
| Zelle upload stored; Zelle pay-to card without an image | yes / yes | 6.7.1-regression-650 |
| Confirm with a wrong order key / with free-text method | 403 / 400 | 6.7.1-regression-660 |
| Wizard state after setup with WooCommerce | gatewayEnabled true, methodsAtCheckout {paypal} | 6.7.1-regression-660 |
| On-hold order email instruction line (hook fired directly on order 17) | printed once | script in `harness/` notes |
| Checkout, order on hold, thank-you confirm, counts, three emails, This week card, review prompt | unchanged from 6.6.0 | 6.7.1-regression-660 |
| PHP fatals / console errors | 0 / one 404 for a WordPress core asset in the sandbox, none from the plugin | all |

Left as is, with the reason:
- The reviewers' `enumeration by order id` on the "already received" message needs the order key now, so it is closed by the key check.
- `PaymentPageGenerator::registerLiveSection()` is unused but public; Pro code may call it, so it stays.
- The reviewer's note that `PaymentGatewayHandler::init()` runs on light requests was wrong: that call is inside the premium-only block.
- Screenshots 2 and 5 on WordPress.org still show Pro screens; the readme labels them as Pro. Replacing the images is a release task outside this repository.


# Rebased onto the official 6.3.11 release

The branch originally started from 6.3.9. The official `wallet-up.6.3.11.zip` from WordPress.org was committed as a new base (commit "Update to the official Wallet Up 6.3.11 release") and the nine commits of work were re-applied on top of it with three-way merges. Conflicts and how they were resolved:

| File | Conflict | Resolution |
|---|---|---|
| `wallet-up.php`, `wallet-up-start.php`, `readme.txt` | version numbers and the changelog | the branch's versions (6.4.0 → 6.7.0) win; upstream's 6.3.10 and 6.3.11 changelog entries stay below the branch entries; upstream's `OptionPrimer::prime()` call kept |
| `src/Core/PaymentRegistry.php` | upstream routed the notice link colour through `Brand::HEX`; the branch rewrote the notice | branch markup with `Brand::HEX` |
| `src/Core/Display/DisplayHandler.php` | upstream replaced server-side impression counting with `countImpression()` plus a client beacon; the branch added the `wallet_up_display_rendered` action | both: upstream counting kept, the action fires for real visitors beside it |
| `src/Plugin.php` | upstream added `ActivityCache::register()` next to the lines the branch gated behind the light-request check | `ActivityCache` registered on every request; `DeactivationOffer` and `SetupHelp` stay gated |
| four admin bundles | upstream rebuilt them (new hashed names, new minified identifiers) | the 37 string-level edits were re-applied against the new files, using the new identifiers (`re()` for the premium check, `ke()` for the selected-methods helper); the old bundle files are gone |
| deleted assets | upstream had already removed `assets/js/report/dd` and the `.wup` bundles | nothing to do |

Brand colours: 6.3.11 introduces `src/Core/Brand.php` (#674FBF, dark #503AA1, white on brand). The inline "Turn on" button and the visitor confirm button added by the branch now use `Brand::HEX` and `Brand::ON_BRAND_HEX`; the admin cards use the Tailwind purple-600 token, which 6.3.11 maps to the same colour.

The three proof drivers were run again on the rebased tree: `proof/6.3.11-base-fresh`, `proof/6.3.11-base-regression-650`, `proof/6.3.11-base-regression-660`. Results on the rebased tree, all from fresh installs: visitor page view 35 plugin files / 477 KB / no Freemius (upstream's OptionPrimer and ActivityCache account for the four extra files); every row of the 6.7.0 fresh run, the 6.5.0 run and the 6.6.0 WooCommerce run unchanged from the 6.3.9-based proofs; 0 PHP fatals, 0 plugin console errors.

An independent multi-agent check of the rebase (every original hunk present, every upstream hunk preserved, bundle edits valid against the new identifiers) is recorded in `revamp/agent-reports/rebase-verification.md`.
