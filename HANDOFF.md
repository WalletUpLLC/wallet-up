# Wallet Up free 6.7.0: handoff to the Pro team

Branch: `claude/tender-maxwell-dilhiz` (17 commits on top of `main`, which is 6.3.9). Base of the work: the official **6.3.11** release from WordPress.org, committed as `311a475`. Every change since is appended on top of that release. Nothing has been pushed; the branch is delivered as a git bundle, a patch series and a full diff (see "Getting the code").

## 1. Getting the code onto your machine

Pick one of the three. The bundle is the complete branch with history and signatures.

```bash
# A. git bundle (recommended): contains main..claude/tender-maxwell-dilhiz
git clone https://github.com/WalletUpLLC/wallet-up.git && cd wallet-up
git bundle verify ../wallet-up-branch.bundle
git fetch ../wallet-up-branch.bundle claude/tender-maxwell-dilhiz:claude/tender-maxwell-dilhiz
git checkout claude/tender-maxwell-dilhiz

# B. patch series: 16 patches, apply on main in order
git checkout -b free-6.7.0 main && git am -3 ../patches/*.patch

# C. one diff for review only (minified bundles make it large; use A or B to build)
git apply --check ../wallet-up-6.7.0-full.diff
```

Once the branch is on GitHub, `git fetch origin claude/tender-maxwell-dilhiz` replaces step A.

## 2. What the machine needs

| Tool | Version used here | Why |
|---|---|---|
| PHP | 8.3 (plugin requires 8.1+) | `php -l` and the local site |
| Composer | 2.8 | `composer dump-autoload -o` at release (see 6) |
| Node | 22 | syntax checks of the compiled bundles, Playwright drivers |
| Playwright + Chromium | any current | the proof drivers under `audit/proof/harness/` |
| WordPress | 7.1 with the SQLite drop-in, PHP built-in server | the local site the drivers run against |
| WooCommerce | 10.9 | the 6.6.0 driver (checkout, order, thank-you page) |

The React admin source (`walup-react/src`) is **not** in this repository or in the 6.3.11 package; only the compiled `walup-react/dist` is. See section 6 before running `vite build`.

## 3. What the branch contains, commit by commit

| Commit | Release | Content |
|---|---|---|
| `311a475` | 6.3.11 | The official release, replacing the 6.3.9 tree (new: `Brand`, `OptionPrimer`, `SchemaCache`, `ActivityCache`, `PageSearch`, `SocialMeta`, `StoreCheckout`, `ViteManifest`, bundled es/fr dictionaries, rebuilt bundles) |
| `701f414` | audit | First-run audit of 6.3.9 with screenshots (`audit/AUDIT.md`) |
| `da20a26` | 6.4.0 | Activate lands on setup (Freemius anonymous mode); permalink notice calmed; honest WooCommerce copy; one method per business preset |
| `9b3535a` | 6.4.1 | One upgrade surface; locked pages without mock data; Settings cards (Branding, Setup assistant); no media library or WP script deps on free screens; Stripe.js only when cards are live |
| `b07d8e9`, `a3ff207` | docs | Proof of 6.4.x; the free-tier revamp proposal (`audit/revamp/FREE-REVAMP.md`) |
| `13eae62` | 6.5.0 | Zelle free (pay-to card, uploaded bank QR); country detection and chooser; currency follows the country; never-silent shortcodes and blocks; notify opt-in after value; uninstall-survey context tag |
| `7e78550` | 6.6.0 | One method at WooCommerce checkout (classic and blocks); first-week loop: counter, This-week card, first-tap and confirmation emails, digest, review prompt after value; setup publishes the page |
| `f7a8385` | 6.7.0 | Nine-row setup check; plain-HTML fallbacks when the admin script fails; light request path for visitors (no Freemius SDK on page views); package trim; honest readme requirements |
| `98c8537` | 6.7.0 | Fixes for every confirmed finding of the three-reviewer adversarial audit (`audit/revamp/agent-reports/audit-*.md`) |
| `0862356` | rebase | Bundle edits re-applied to the 6.3.11 bundles; inline colours through `Brand` |
| `7770e9c`, `376fb9c`, `ea46c91` | proof | Drivers green on the 6.3.11 base; rebase verification report; zip install proof |
| `a3c92df` | research | Who installs Wallet Up and what they need (`audit/revamp/customer-sites-research.md`) |
| `2d7eca9` | 6.7.0 | Every offered-then-locked Pro gate removed or reworded (`audit/revamp/pro-gates-audit.md`) |

Diff against `main`, excluding the audit folder and the minified bundles: 141 files, +7,200 / −20,517 lines (the deletions are the trimmed web fonts, `.wup` bundles and duplicate images). 52 files deleted. The audit folder adds 289 files of screenshots, results and reports.

## 4. New modules and where they hook in

All new PHP is under `WalletUpPro\` and listed in both Composer autoload files (classmap-authoritative). `Plugin::initializeServices()` and `wallet-up-start.php` wire them.

| Class | Purpose | Registered from |
|---|---|---|
| `Core\Admin\FreeExperience` | Enqueues `assets/js/admin/free-ui.js` on every Wallet Up screen; ajax `wallet_up_set_country`, `wallet_up_notify_optin` (local email; Freemius opt-in only with the `share` checkbox), `wallet_up_save_custom_qr` | `Plugin.php` (admin) |
| `Core\Admin\FreeSettingsPanel` | Settings cards: Where you are, Notifications, Setup assistant (Branding on Pro only); ajax `wallet_up_save_locale`, `wallet_up_save_notify`, `wallet_up_save_branding`, `wallet_up_rerun_setup` | `Plugin.php` (admin) |
| `Core\Admin\SelfCheckPanel` + `Core\Retention\SetupCheck::checks()` | Nine setup checks; Plugins-row meta, inline notice when PHP/QR engine/admin fail, server-rendered table; ajax `wallet_up_self_check` | `Plugin.php` (admin) |
| `Core\Admin\MountHealth` | Watchdog script for the React root: fallback shown after 2.5 s, failure recorded after 10 s or on a bundle error, cleared on the next mount; ajax `wallet_up_mount_failed`, `wallet_up_mount_ok` | `OnboardingHandler::render`, `AdminManager::renderAdminPage` |
| `Core\Geo\SiteLocale` | Country detection (owner → WooCommerce → timezone → locale), hint for the app, currency seeding on activation, `pickList()` | activation hook, `FreeExperience` |
| `Core\Retention\FreeCounter` | Views, taps, confirmations in `wallet_up_free_counts`; emails; daily cron `wallet_up_free_daily`; ajax `wallet_up_payment_event` (no nonce, per-IP cap) | `Plugin.php` (all requests) |
| `Payments\Zelle` | Free method with no pay link: pay-to card or uploaded bank QR; never a generated QR | `PaymentRegistry` |
| `Services\ScanRoutes` | The `/wallet-up/scan/` routes split from `QRCodeService` so visitor pages do not load the QR engine | `Plugin::initialize` |
| `WooCommerce\FreeP2pGateway` (`wallet_up_p2p`) + `Blocks\FreeP2pBlocksSupport` + `assets/js/free-p2p-checkout-block.js` | One method at checkout; order on hold; thank-you QR and confirm form | `FreeCardBootstrap` |

Light request path: `wallet_up_is_light_request()` in `wallet-up-start.php` decides at include time whether a request is a plain visitor page view on the free build; on such requests `wallet_up_fs()` returns `FreemiusFrontendStub` and `Plugin::isLightRequest()` skips admin/AJAX/REST-only services. Admin, admin-ajax, admin-post, cron (`doing_wp_cron` included), REST (`rest_get_url_prefix()` and `rest_route`), login, WP-CLI, Freemius actions, the support-login link and logged-in users all get the full plugin.

Options added: `wallet_up_free_counts`, `wallet_up_notify_email`, `wallet_up_geo_hint`, `wallet_up_site_country`, `wallet_up_custom_qr_images`, `wallet_up_admin_mount_failed_at/_on`, `wallet_up_payment_page_id`, `wallet_up_hide_branding`, `woocommerce_wallet_up_p2p_settings`, `wallet_up_settings[currency, currency_set_by_owner]`. Cleanup runs through Freemius `after_uninstall` (`wallet_up_free_uninstall_cleanup`); shared data is kept when Pro is installed.

Hooks other code can use: `wallet_up_display_rendered($method, $post_id, $mode)`, `wallet_up_payment_confirmed(array)`, `wallet_up_payment_event_recorded`, filter `wallet_up_light_request`, filter `wallet_up_admin_react_data`.

## 5. The compiled bundles: 37 string-level edits that must be ported to the React source

Because `walup-react/src` is not here, the admin app changes were made as string edits in the minified files. Re-running `vite build` from the source will **revert them** unless they are ported to JSX first. The edits (file, intent):

- `paymentMethods.*.js`: free list `["cashapp","paypal","venmo","zelle"]`.
- `OnboardingFlow.*.js`: one preset method per business type; the geo-aware preset pick reading `window.walletUpData.geoHint.works`; method picker lists free methods only; donation-tool chooser hidden (Tip Jar only); Pro pills and the See Pro link removed from the integrations step; checkout box always names the one method it places; the WooCommerce step is sent on the free plan with `showAtCheckout` defaulting to ticked; the end-of-setup upsell card hidden; `proHint` line hidden.
- `main.*.js` (dictionary, en/es/fr): `integrationProHint`, `p2pCheckoutProNote`, `proHint`, `pageHint2`, the donations goal description.
- `WalletUpAdminFree.*.js`: Explore Pro last in the navigation and the three locked entries removed; Donations and Activity quick actions removed; Zelle row in Payments; counters "of 4"; locked rows and the crypto, mobile money and own-keys sections removed from Payments; the two Sell-with-cards collapsibles, the dashboard upgrade banner, the sidebar upsell, the Setup Progress card and the About "Upgrade to Pro" link removed; lock overlay without the blurred mock; Payments description names Zelle.
- `ShortcodeTemplates.*.js`: the Crypto Checkout template removed.

The exact before/after of each edit is in the commits `da20a26`, `9b3535a`, `13eae62`, `0862356` and `2d7eca9` (`git show <commit> -- walup-react/dist`). `audit/proof/harness` has nothing for the React build; port by hand.

`assets/js/admin/free-ui.js` anchors on the app's rendered headings (English, Spanish and French strings as shipped). When the source is available, replace the text anchors with `data-wup-slot` attributes on the Welcome, ready, Dashboard, Payments and Settings headings and read those instead.

## 6. Release checklist

1. Port the bundle edits (section 5) to JSX, run `vite build`, copy `dist`, re-run the drivers.
2. `composer dump-autoload -o` (the classmap was edited by hand for every new class).
3. Replace WordPress.org screenshots 2 and 5 (they still show Pro screens; the readme labels them).
4. Versions: `wallet-up.php` header, `WALLET_UP_PRO_VERSION` in `wallet-up-start.php`, `Stable tag` and changelog in `readme.txt`, `changelog.txt`. All say 6.7.0 now; pick the public number.
5. Build the zip from the tree with `git archive` (the audit folder and git metadata excluded), install it through Plugins → Upload Plugin on a fresh site, run the three drivers. The sandbox needed `upload_max_filesize` above 2 MB.
6. Decide the open product questions in section 9.

## 7. How to run the proof harness

Scripts are in `audit/proof/harness/`. They expect a local WordPress at `http://127.0.0.1:8080`, admin/password, the mu-plugin `wup-test-harness.php` installed (logs `wp_mail` to `wp-content/mail.log`, REST helpers under `/wup-test/*`, a front-end `?wup_footprint=1` probe), and this repository synced into `wp-content/plugins/wallet-up`.

```bash
# one-time: a WordPress checkout with the SQLite drop-in; then
php -d upload_max_filesize=32M -S 127.0.0.1:8080 -t core core/router.php &
cp audit/proof/harness/wup-test-harness.php core/wp-content/mu-plugins/
# edit the paths at the top of sync.sh and reset.sh, then per run:
bash reset.sh && bash sync.sh && node prove-670.js my-run          # fresh site, fallbacks, checks, audit fixes
bash reset.sh && node prove-650.js my-run-650 [geo]               # Zelle, geography, never-silent, survey tag
bash reset.sh && node prove-660.js my-run-660                     # WooCommerce (needs WooCommerce in plugins/)
node upload-zip.js /path/to/wallet-up-6.7.0.zip                   # install through the uploader
```

Each driver writes `results.json` and numbered screenshots into `proof-<label>/`. The checks that matter are listed in `audit/PROOF.md`; compare a new run against `audit/proof/gates-*` (the last green run).

## 8. Reports to read, in order

1. `audit/AUDIT.md`: what a first run of 6.3.9 looked like and why users left.
2. `audit/revamp/FREE-REVAMP.md`: the plan (four pillars, release plan, measurement).
3. `audit/revamp/customer-sites-research.md`: who the 280 uninstalled sites are and what they needed.
4. `audit/PROOF.md`: what each release changed, what was measured, the audit findings and the rebase.
5. `audit/revamp/agent-reports/`: the three adversarial reviews, the rebase verification, and `pro-gates-audit.md` beside them.

The Freemius uninstall export that started this work contains customer site URLs and is **not** in the repository; keep it that way.

## 9. Open decisions for the Pro team

- The PHP floor is 8.1. One in six uninstallers in the export ran 7.4 or 8.0; the readme and the header say 8.1 honestly, so WordPress.org blocks those installs rather than letting them fail. Lowering the floor is a separate project (the code uses PHP 8 features throughout).
- Non-US rails (UPI, PIX, QRIS, Bizum, M-Pesa) are a different product; the research sizes the demand.
- A basic free donation block (amount buttons, donor name) is the second-largest ask in the research; donations stay Pro today.
- The thank-you page still shows a full-size QR; one customer asked for a compact inline display.
- Measurement: ship, wait 30 to 60 days, export uninstalls again and compare the share of reasons carrying `pv0` and `m0` in the context tag against the baseline in `audit/revamp/FREE-REVAMP.md`, section 7.

## 10. Known limits of the proof

Everything was proven by driving the real plugin in a local WordPress with headless Chromium: behaviour, not retention. No live site, no real Freemius account, no WordPress.org listing was touched. Outbound network from the build environment was restricted, so the customer-site research used search results and the export's own fields rather than page fetches (confidence is stated per figure in the report).
