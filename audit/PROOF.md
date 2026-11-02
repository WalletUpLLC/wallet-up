# Build proof for 6.4.0 and 6.4.1

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
