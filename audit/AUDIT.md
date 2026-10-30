# Wallet Up 6.3.9 (free) — first-run audit

**Question asked:** several users uninstall the free plugin within seconds of installing. What could we have done better?

**Scope:** audit only, no code changes. Version audited is 6.3.9, the build on `main` and on WordPress.org since 23 October 2026. Findings come from three sources: reading the code and the WordPress.org listing, then running the actual plugin twice in a real browser and screenshotting every screen a new user meets.

**How it was run.** WordPress.org and the Playground host are not reachable from the audit sandbox, so the official "Live preview" could not be used. Instead a fresh WordPress 7.1 on PHP 8.3 was installed locally with the SQLite drop-in, the identical 6.3.9 tree from this repository was dropped into `wp-content/plugins/wallet-up`, and a headless Chromium drove it the way a person would: Plugins screen, Activate, follow whatever appears.

| Run | Setup | Folder |
|---|---|---|
| 1 | Fresh site, WordPress default plain permalinks, no other plugins | `screenshots/run1-fresh-site/` |
| 2 | Pretty permalinks, WooCommerce 10.9.4 active before Wallet Up | `screenshots/run2-woocommerce/` |

Two limits to keep in mind. Outbound calls to Freemius, walletup.app and Stripe were blocked, so the Upgrade pricing page shows a spinner and nothing was reported to Freemius. WooCommerce in run 2 was built from GitHub source without its JavaScript bundle, so WooCommerce's own React screens are blank and it prints two red "development version" banners; those banners are a sandbox artifact and real users never see them. Every Wallet Up screen is the real plugin.

---

## 1. Verdict

A person who installs Wallet Up for a Cash App or Venmo QR code gets a usable result in under a minute, but only after a screen about us (the Freemius opt-in), and on a default WordPress install they do it under a red error banner that says the plugin needs something to work. A person who installs it for their WooCommerce store, which the listing actively courts, is told at step 3 of the wizard that the thing they came for is Pro. Both are plausible sub-minute exits. Freemius records the reason every one of those users gives on the way out; that export is the first thing to read.

---

## 2. Run 1: fresh site, exactly as WordPress installs it

### 2.1 Activate lands on the Freemius opt-in, not on Wallet Up

![Freemius opt-in](screenshots/run1-fresh-site/01-after-activate-freemius-optin.png)

The SDK is initialised without anonymous mode, so the first thing after "Activate" is a request to share data and receive marketing email. Nothing of the product is visible yet. The 6.3.3 changelog made this deliberate ("a new install opens on the connect step first"). This is the single biggest sub-minute exit point.

### 2.2 A red "WalletUp Pro: Permalinks Required" error on every admin page

![Permalinks banner on the WordPress dashboard](screenshots/run1-fresh-site/07-wp-dashboard-permalinks-banner.png)

WordPress installs with plain permalinks. On such a site the free plugin shows an error-styled banner titled "WalletUp Pro" on every admin screen, including the wizard itself. Dismissing it does nothing; it is back on the next page load. It is also misleading: the QR, the payment link, the generated page and both shortcode forms all worked under plain permalinks in this run. Only scan tracking needs rewrite rules. A new user reads "this plugin is broken and wants money". Run 2 confirms it disappears once pretty permalinks are on, so it is specific to default and budget-host installs, which is still a large share of first installs.

### 2.3 The wizard is four steps and ends well

![Welcome](screenshots/run1-fresh-site/02-wizard-step1-welcome.png)
![Industry](screenshots/run1-fresh-site/03-wizard-step2-industry.png)
![Details form](screenshots/run1-fresh-site/04-wizard-step3-details-form.png)
![Ready](screenshots/run1-fresh-site/05-wizard-step4-ready.png)

Welcome, industry, one combined form (name, email, method, username, amount), done. It took under a minute. Pro-only methods are shown greyed out with a crown and cannot be selected, which is honest. The final screen is the strongest moment in the product: a locally generated QR, a copyable link, Download QR, Test it, Send to my phone, and a one-click "Create page with this block" that opens the block editor with the page drafted.

Small frictions on the form: the industry survey comes before the one field the product needs, and the Cash App link is displayed with the dollar sign URL-encoded (`cash.app/%24TestShop/25`), which looks wrong even though it scans fine.

### 2.4 The dashboard works, but carries six upsell surfaces

![Dashboard](screenshots/run1-fresh-site/06-dashboard.png)

Payment card first, setup progress, quick actions. It is a competent screen. It also shows, at the same time: "Explore Pro" as the second navigation item, three PRO-badged navigation items, a sidebar "Unlock all 20 payment methods" card, and a bottom "Upgrade to Pro" banner. The Explore Pro page is a full sales page with an embedded demo.

![Explore Pro](screenshots/extra/explore-pro.png)

### 2.5 Locked pages show fabricated data behind a blur

![Payments page](screenshots/run1-fresh-site/09-payments-locked-sections.png)
![Donations locked](screenshots/run1-fresh-site/10-donations-locked.png)

Clicking Donations in the navigation lands on a mock dashboard ("$4,280 raised, 47 donors, Sarah M.") behind a lock overlay. The Payments page has three lock overlays on one scroll. Landing on a mock-up after a navigation click reads as bait.

### 2.6 Settings is empty

![Settings](screenshots/extra/settings-page.png)

The Settings page contains only an About box. There is no setting of any kind for a free user, and no way to remove the "Powered by WalletUp" footer printed on every public payment card.

### 2.7 The public page is good

![Generated page](screenshots/run1-fresh-site/11-frontend-generated-page.png)
![Mobile visitor](screenshots/run1-fresh-site/12-visitor-mobile-shortcodes.png)

Clean card, readable amount, works on a phone, legacy `[cashapp]` shortcodes from version 3 still render. Two costs: the "Powered by WalletUp" link on the merchant's page, and the page loads Stripe's script from js.stripe.com for every visitor even though no card payments are configured.

### 2.8 Deactivation

![Deactivation modal](screenshots/run1-fresh-site/13-deactivate-modal.png)

The Freemius reasons modal, with a custom "It is usually one unfinished step" panel and a "Check this site" button. Not a cause of leaving, but it is where the reasons are recorded.

---

## 3. Run 2: pretty permalinks on, WooCommerce active first

### 3.1 The permalinks banner is gone

![Welcome without banner](screenshots/run2-woocommerce/02-wizard-step1-no-permalinks-banner.png)

Confirms finding 2.2 is permalink-specific. (The two red banners in this screenshot are WooCommerce's own sandbox-build notices.)

### 3.2 Step 3 becomes a Pro wall for the store owner

![Step 3 with WooCommerce detected](screenshots/run2-woocommerce/04-wizard-step3-woocommerce-detected.png)

With WooCommerce present the step is retitled "Connect Wallet Up to your site". WooCommerce is listed as "Detected" with a PRO badge, a blue box says "Wallet Up connects to WPForms and WooCommerce on Pro. See Pro", and the checkbox "Show my selected methods at my WooCommerce checkout" also carries a PRO badge with a note that placing the method on checkout is a Pro feature. A merchant who installed this to put Cash App or Venmo at checkout learns here, mid-wizard, that the free plugin cannot do it. The listing's "payment gateway" and "woocommerce" tags and its second screenshot are what bring that merchant in.

The Retail preset also pre-selects two methods, so Continue stays disabled until two usernames are typed. The same step carries a Stripe Connect card for the platform-fee card path.

### 3.3 Nothing on the dashboard or in WooCommerce reflects the store

![Dashboard with WooCommerce](screenshots/run2-woocommerce/06-dashboard-with-woocommerce.png)
![Notice on WooCommerce Home](screenshots/run2-woocommerce/07-woocommerce-home-wallet-up-notice.png)

The dashboard is identical to run 1. Wallet Up posts a notice on WooCommerce's own Home screen ("Make Wallet Up a payment option in your store with Pro. See Pro"). The only gateway the free plugin registers in WooCommerce is "Credit/Debit Card", switched off. A store owner who skips the wizard and goes to WooCommerce, Settings, Payments finds none of the methods the listing title names.

### 3.4 Otherwise unchanged

Freemius opt-in still comes first (`run2-woocommerce/01`). Four steps, same "You're ready" ending (`run2-woocommerce/05`). Activation took about four seconds.

---

## 4. Listing and package findings (from code and readme)

- **Title and description oversell the free tier.** The listing title is "Cash App, Venmo, PayPal, Zelle & QR Code Payments" and the second paragraph says Zelle and Meta Pay can be added "with a simple shortcode or block". Both are Pro. The plugin header's own description, which lists only Cash App, Venmo and PayPal, is the honest one.
- **Tags and screenshots court WooCommerce stores.** Tags include "payment gateway" and "woocommerce"; screenshots 2 and 5 show peer-to-peer methods at checkout with per-method discounts. All Pro. See 3.2 for what those users then meet.
- **Stated requirements frighten the target buyer.** PHP 8.1, 256 MB minimum and 512 to 768 MB recommended memory, for a QR-code plugin. Sites below PHP 8.1 cannot install at all, which is a silent funnel loss rather than an uninstall.
- **The live-preview blueprint installs WooCommerce too**, so the WordPress.org "try it" button leads to a long load and a Woo-cluttered admin before anyone sees a QR code.
- **The free build is the Pro codebase with gates, and it shows.** Namespaces, constants and error notices all say "WalletUp Pro", including the permalinks banner and the message a free user sees if initialisation fails.
- **Package weight.** 19 MB unpacked across 765 files. Font Awesome webfonts shipped twice (2.9 MB each), the demo PNG three times, 624 KB of `.wup` language blobs that nothing loads, a stray dev file under `assets/js/report/` referencing a `wallet-up-pro` path. Freemius alone is 4 MB and boots on every request.
- **Admin page weight.** The Wallet Up screen transfers about 5.7 MB over 97 requests. Most is WordPress's own block editor and media bundles, which the plugin enqueues on every one of its screens; about 0.7 MB is the plugin's React app.
- **Trust signals a cautious reviewer finds in the zip.** The readme says "no account with us", yet the code provisions a merchant account against pay.walletup.app with a temporary-password notice (only when mobile money is enabled), includes a support tool that mints a temporary administrator with a passwordless login link, and registers twelve public REST webhook routes. None of it fires for a plain free user.

---

## 5. What the code audit got wrong, corrected by running it

Recorded so the earlier written audit is not taken at face value.

| Earlier claim | What the real UI showed |
|---|---|
| Wizard is 8 steps | 4 steps, under a minute |
| Restaurant preset pre-selects Zelle | Pro methods are greyed out and unselectable |
| A "Finish setting up" banner nags on every admin page | Did not appear in the abandoned-wizard state; the only nag is the permalinks banner |
| Dashboard is a pure sales page | It is usable; upsell density is the issue, not absence of function |
| QR might depend on a third-party API | Generated locally as a data URI |

---

## 6. Recommendations, in order

1. **Move the Freemius opt-in after the wizard, or enable anonymous mode**, so Activate lands on "Welcome to Wallet Up". One change, removes the first exit point.
2. **Replace the permalinks error** with a dismissible info notice shown only on Wallet Up screens, only when scan tracking is in use, never branded "Pro", and with a working dismiss.
3. **Stop courting WooCommerce stores with free-sounding copy, or give the free tier one peer-to-peer method at checkout.** Either fix the title, tags and screenshots, or let Cash App appear as a WooCommerce gateway on the free plan. Today the wizard contradicts the listing at step 3.
4. **Cut the dashboard to one upsell surface**, drop the blurred fake data on locked pages, and make locked navigation items open a short explanation instead of a mock dashboard.
5. **Give Settings real content**, including a "Powered by" toggle even if the toggle is Pro-gated.
6. **Stop loading Stripe on public pages unless cards are switched on**, and stop enqueueing the block editor and media bundles on plugin screens that do not need them.
7. **Trim the package** below 5 MB: dedupe webfonts, drop unused language blobs and stray files, rename Pro-branded strings in the free build.
8. **Lower the stated requirements or explain them.** PHP 8.1 as a hard floor should be a decision, not an accident of the Pro build.
9. **Read the data already collected.** Export Freemius uninstall reasons and compare `wallet_up_activated_at` against deactivation time to see exactly where the drop happens before changing anything else.

---

## Appendix: screenshot index

**Run 1, fresh site** (`screenshots/run1-fresh-site/`)

| File | Screen |
|---|---|
| 01 | Freemius opt-in immediately after Activate |
| 02 | Wizard step 1, Welcome, under the permalinks banner |
| 03 | Wizard step 2, industry |
| 04 | Wizard step 3, details form, Pro methods greyed |
| 05 | Wizard step 4, ready screen with QR and link |
| 06 | Dashboard |
| 07 | WordPress dashboard with the permalinks error banner |
| 08 | Explore Pro page |
| 09 | Payments page with lock overlays |
| 10 | Donations page, mock data behind lock |
| 11 | Generated public payment page, desktop |
| 12 | Shortcode page as a phone visitor |
| 13 | Deactivation modal with reason panel |

**Run 2, WooCommerce** (`screenshots/run2-woocommerce/`)

| File | Screen |
|---|---|
| 01 | Freemius opt-in |
| 02 | Wizard step 1, no permalinks banner |
| 03 | Wizard step 2, industry |
| 04 | Wizard step 3, WooCommerce detected, Pro gating |
| 05 | Wizard step 4, ready |
| 06 | Dashboard, identical to run 1 |
| 07 | WooCommerce Home with Wallet Up's "See Pro" notice |
| 08 | WooCommerce Payments settings (blank React body is a sandbox artifact) |

**Extra** (`screenshots/extra/`): Explore Pro, Shortcodes page, Settings page, Sell with cards, Create-page result in the editor, mobile visitor view, initial deactivation modal, Plugins screen after activation, full-page Freemius opt-in.
