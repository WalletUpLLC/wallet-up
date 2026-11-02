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
