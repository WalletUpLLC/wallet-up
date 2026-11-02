# "Nothing left unwired" sweep: Wallet Up free build, branch vs origin/main

Scope: the full diff (77 files) plus the three files that were untracked at review time and are now committed (`src/Core/Admin/MountHealth.php`, `src/Core/Admin/SelfCheckPanel.php`, `src/Services/ScanRoutes.php`). `php -l` passes on every changed PHP file; `node --input-type=module --check` passes on all four edited bundles; `node --check` passes on the three new plain JS files.

## A. New PHP classes vs autoloader
All ten new classes (FreeSettingsPanel, FreeExperience, SelfCheckPanel, MountHealth, SiteLocale, Zelle, FreeP2pGateway, FreeP2pBlocksSupport, FreeCounter, ScanRoutes) are present in both `autoload_classmap.php` and `autoload_static.php` with matching namespaces and paths. A script that walked every `src/**/*.php` found 0 missing and 0 path mismatches.

## B. Hooks added
Every added `add_action`/`add_filter` has a defined, public (or static, as called) callback with a compatible signature, on a hook that exists in WordPress or WooCommerce, and its `init()`/`register()` is reached on the request types where the hook fires. The light-request gate skips only classes that register `wp_ajax_*`, `admin_*` or `rest_api_init` hooks, with one exception (AdminThemeManager's front-end `wp_nav_menu_args`, since restored). The Freemius stand-in covers every method used in `src/`.

## C. AJAX actions used by JavaScript
Every action called from `free-ui.js`, `free-settings.js`, `free-p2p-checkout-block.js` and the inline scripts has a matching handler on the right request type, matching `$_POST` field names and matching nonce actions.

## D. Localized keys and DOM anchors
All keys read by the JS exist in the PHP arrays. Anchor strings were checked against the compiled bundles. One failed: the Zelle bank-QR upload looked for a `<span>` reading "Zelle" on the Payments page, while the list renders the name in an `<h3>` (fixed: the anchor now accepts both and prefers the visible one). Settings is an in-app route of `?page=wallet-up` on the free build.

## E. Options, transients and meta introduced
Every new option is written and read; two were write-only (`wallet_up_notify_optin_at`, since removed; `wallet_up_admin_mount_failed_on`, now read by the setup check). No uninstall cleanup existed in the plugin (since added through Freemius' `after_uninstall`).

## F. Shortcodes, blocks and custom actions
`walletup_payment_section` literal equals the class constant; the generic method shortcodes and blocks include Zelle; `wallet_up_display_rendered` and `wallet_up_payment_confirmed` fire where FreeCounter listens. Zelle with no uploaded QR fell through to a generic QR payload (fixed, see the security report).

## G. Bundles
All four edited bundles parse. The free list includes "zelle" everywhere a hardcoded list exists; no "of 3" copy remains. Block search keywords omitted Zelle (fixed).

## H. i18n
All new translatable strings use the `walletup` text domain; `sprintf` sites carry translators comments.

## I. readme and versions
Stable tag, plugin header Version and `WALLET_UP_PRO_VERSION` agree on 6.7.0. `changelog.txt` lacked the 6.7.0 entry and the plugin header said "Requires at least: 5.8" while the readme said 6.1 (both fixed).

## J. Deleted files
42 deletions (two web font sets, a stray dev file, duplicate demo image, two `.wup` bundles, eight duplicate PNGs); a grep of every basename across php, js, css and json finds no references.

## Defects reported (all addressed unless noted)
1. Three load-bearing files untracked at review time (committed in the 6.7.0 commit).
2. Zelle bank-QR upload anchor (fixed).
3. Setup-check "Fix" links pointed at `page=wallet-up-payments`, unregistered on the free build (fixed: single free admin page).
4. FreeP2pGateway hooks registered twice per request (fixed).
5. Plugin header vs readme "Requires at least" (fixed).
6. changelog.txt missing 6.7.0 (fixed).
7. Write-only `wallet_up_notify_optin_at` (removed).
8. Write-only `wallet_up_admin_mount_failed_on` (now shown in the setup check).
9. No uninstall cleanup (added).
10. Zelle fabricated QR (fixed).
11. Copy omitting Zelle in the plugin header, the WPForms notice and block keywords (fixed).
12. REST prefix detection in the light-request classifier (fixed).

Notes: `FreeCardBootstrap::gateMasterDisabled` hides the free gateway when a Pro-era master switch is off (not changed; the setup check does not detect it). `PaymentPageGenerator::registerLiveSection()` is unused but public (kept).
