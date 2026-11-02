# Reliability and comprehension churn — root causes and hardening plan

Source: Freemius installs export of 2 Nov 2026 (281 install records, 274 free, 7 Pro). Code read on `claude/tender-maxwell-dilhiz` (6.4.1), the 6.3.9 tree (`4a8e565`) and the 3.2.3 tree (`d72fe48`). Memory figures measured on the local WordPress 7.1 / PHP 8.3 test site.

## 0. How to read the export before drawing conclusions

- **Every record in the export is an uninstalled install.** `is_active` is `no` and `is_uninstalled` is `yes` on all 281 rows, and `created` equals `uninstalled_at` on 219 of 274 free rows. The free plugin runs Freemius in opt-in mode, so an anonymous user has no install record until the deactivation dialog creates one. The export therefore says nothing about retained users, and the "<1 min" time-to-uninstall bucket is an artefact, not a measurement.
- **The five reliability and comprehension reasons total 66 of 274 free uninstalls (24%)**, but their share falls with each generation: 3.x 28% of its uninstalls, 4.x 27%, 5.x 19%, 6.x 10%. Only 3 of the 29 uninstalls on 6.x cite a reliability reason, all "Didn't work after installation" with no text. So the bulk of the reliability record is the 3.x/4.x product that 6.x already replaced, and the current build's live reliability problem is small but not zero.
- **PHP below 8.1 accounts for 50 of 274 free uninstalls (18%)**, all on 3.x to 5.x. No 6.x uninstall is on PHP below 8.1, which is expected: WordPress refuses to install 6.x there, so those sites now fail before Freemius can record anything. That is a silent loss the export cannot show.
- **39% of free uninstalls are outside the US** (28 with unknown country, 21 India, 9 Germany, 8 UK, 7 France). Cash App, Venmo and Zelle are US-only products. A good share of "didn't work", "expected something else" and "not available in my location" is a product that cannot work for the person who installed it.

## 1. Complaint to root cause

| Complaint (count, generation) | Most likely root cause | Confidence | Evidence | Status now |
|---|---|---|---|---|
| "Didn't work after installation" (19: 8 on 3.x, 2 on 4.x, 6 on 5.x, 3 on 6.x) | Several causes stack. On 3.x: the settings menu was registered with a boolean capability (see next row) so the plugin appeared to do nothing after activation. On 5.x: a saved username did not switch the method on, so shortcodes rendered nothing for visitors. On 6.x: a visitor-facing page that shows nothing when the method is off, and the React admin that mounts blank if JavaScript fails. | Medium | `d72fe48:enqueue-walletup.php:37-39`; `src/Core/PaymentRegistry.php:136-163` and `184-203`; `src/Core/Admin/OnboardingHandler.php` 8-second fallback | 5.x cause fixed in 6.3.9 ("saving details switches the method on"). 6.x blank-admin and silent-visitor cases are open. |
| "Didn't work" (17: 13 on 3.x), with text "cannot get to settings" (3.3.4) | 3.x bug: `current_user_can()` was evaluated at plugin-load time and its boolean result passed as the `$capability` argument of `add_menu_page()`. At load time there is no current user, so the capability was `false` and the menu never appeared. The same file also called `current_user_can()` at the top level to decide whether to register the activation redirect, so the redirect never fired either. | High | `d72fe48:wallet-up.php:70-76`, `d72fe48:enqueue-walletup.php:37-39` | Gone in 6.x (capability string, React admin). Explains why 3.x looked dead after activation. |
| "Broke the website" (9: 5 on 3.x, 4 on 4.x) | 3.x included `wp-includes/pluggable.php` at plugin load, before other plugins define their pluggable functions. Any other active plugin that overrides `wp_mail()`, `wp_set_current_user()` or similar then fatals with "Cannot redeclare". Also `exit(wp_redirect(...))` on `activated_plugin` can leave the plugin half-activated. A second contributor on 4.x/5.x is memory: the plugin loads 93 PHP files and 2.3 MB of PHP on every front-end request, adding 8.5 MB to the baseline 40 MB; sites with a 48 MB limit fatal outright and sites at 64 MB with WooCommerce active exceed it. | High for pluggable; Medium for memory | `d72fe48:wallet-up.php:31-32`; measured: baseline 40.0 MB / 513 files, plugin-only 48.5 MB / 613 files, fatal at `memory_limit=48M`, 87 MB with WooCommerce | Pluggable include gone in 6.x. Memory and file count are current. The readme's "256 MB minimum" is this problem stated as a requirement. |
| "Suddenly stopped working" (5: 3 on 3.x) | 3.x generated every QR through `chart.googleapis.com`, a deprecated endpoint that has degraded intermittently; QR images simply stop appearing with no error. Plugin updates that changed shortcode names are a second candidate. | Medium | `d72fe48:chart.php:6` | 6.x generates QR locally (`endroid/qr-code`), confirmed in the proof runs (data-URI images, no external host). |
| "Zelle QR code didn't work" / "not a Zelle QR code when scanned with my Zelle account" (3.3.5, 3.4.1) | 3.x encoded the URL `https://enroll.zellepay.com/?data=<email>` into a generic QR. Bank apps' Zelle scanners only accept Zelle's own payload format, so the scan failed, and a phone camera opened an enrolment page instead of a payment. | High | `d72fe48:zelle.php:41-44` | 5.7.13 changelog: "Zelle shows a single code that scans in any bank's Zelle scanner". Zelle is Pro-only now, so the free user never gets this far; the free listing must stop implying Zelle works. |
| "the short codes do not render on the front end. sent email about it" (5.5.15) | Per-method shortcodes are only registered for methods whose `wallet_up_enabled_methods` flag is on. A method with a saved username but no flag falls through to `setupNotice()`, which returns a purple admin-only notice and an empty string for everyone else. Before 6.3.9 saving a username did not set the flag. | High | `src/Core/PaymentRegistry.php:105-163`, `184-203`; 6.3.9 changelog | Trigger fixed in 6.3.9. The silent-for-visitors behaviour remains and still bites any method switched off later. |
| "Didn't understand how it works" (16: 9 on 3.x, 5 on 4.x, 2 on 5.x) and "I don't want to edit code" (3.4.1) | 3.x had a settings form plus a tab of raw shortcodes and an "IMPORTANT NOTICE: CLICK HERE" button; shortcodes read as code to non-developers. No preview, no page creation, no blocks. | High | `d72fe48:settings.php` | 6.x has blocks, a wizard, "Create page with this block". The remaining gap is that the wizard's industry question comes before the only field that matters, and the dashboard still leads with shortcodes as a quick action. |
| "Never got an email for the code needed to run" (3.3.5) | Most likely the Freemius opt-in: when the WordPress admin email is not verified, Freemius shows "please confirm your email" and the plugin menu stayed in activation mode until confirmed. No "code" exists in any tree in this repo. | Low | Freemius SDK activation-mode behaviour; no code path in `d72fe48` | Removed by 6.4.0 (anonymous mode: the opt-in is no longer in the path). |
| "I can't get the payment gateway to show in Woocommerce" (5.5.15), "i thought it worked in checkout" (3.4.9), "hoped it would integrate with woocommerce in the free version" | Not a bug: free never placed methods at checkout. The listing and tags said "payment gateway"/"woocommerce" and screenshots showed checkout. | High | readme 6.3.9 tags and screenshots; wizard step 3 | 6.4.0 wording in wizard, notices and listing. Still a product decision whether one method goes free at checkout. |
| "I can't find my currency" (NG), "Not available in my location" (3.3.5) | The free methods are US-only apps. There is no country check, no currency setting on the free tier (`wallet_up_settings.currency` is hard-coded `USD` at activation), and the amount is always rendered with `$`. | High | `src/Core/Activation/ActivationHandler.php:72`; 39% of uninstalls are non-US | Open. The plugin should say on the first screen which methods work in the visitor's country, and PayPal.me should be offered as the method that works almost everywhere. |
| "doesn't work AT ALL", "scam", "the plugin does not work properly" (5.5.x, 5.6.0, 3.4.1) | Untyped frustration. Given the dates, the same silent-visitor and un-enabled-method issues as above, plus the Stripe/Pro gating on 5.x ("Forcing me to use Stripe"). | Low | Same rows as above | Partly fixed; the silent page is the common thread. |

## 2. Hardening plan for the free version

Ordered by how many of the 66 reliability/comprehension churners each change would plausibly have kept, counting only causes still present in the current code (3.x-only causes are listed last for completeness).

### 2.1 Never render nothing (would have kept ~15: the "didn't work" and "shortcodes do not render" rows across 4.x–6.x)

- `src/Core/PaymentRegistry.php`, `setupNotice()` and `upgradeNotice()`: for visitors, keep returning no markup, but emit an HTML comment `<!-- wallet-up: cashapp is switched off -->` so a site owner viewing source, or support looking at a page, sees why. For logged-in administrators, keep the notice but add an inline **"Turn on Cash App"** button that posts to a nonce-checked `admin-post` action and reloads, rather than a link to a settings page. One click fixes the page from the page.
- `src/Core/PaymentRegistry.php`, `registerPaymentMethods()`: register the real shortcode handler for every free method regardless of the enabled flag, and let `PaymentMethod::processShortcode()` decide. A method with a saved username and the flag off should render the payment card with a small admin-only badge "shown because a username is saved; switch it off under Payments to hide it", not nothing. The enabled flag then becomes a visibility toggle the owner set on purpose, not a trap.
- `src/Payments/PaymentMethod.php`, `processShortcode()`: the "not configured" exception is caught and logged; visitors get an empty string. Replace with the same admin-only inline action.

### 2.2 The admin must never be blank (would have kept ~5: the untyped 6.x "didn't work after installation" rows)

- `src/Core/Admin/OnboardingHandler.php`, `render()`: cut the fallback timer from 8 s to 2.5 s and render the simple setup form server-side inside the root, hidden by CSS until the React app mounts, so a blocked script shows a working form instead of white space. Do the same for the dashboard root in `src/Core/Admin/AdminManager.php::renderAdminPage()`: a server-rendered card with the saved method's QR and link, replaced when React mounts.
- `src/Core/Assets/AssetManager.php`, `enqueueReactApp()`: the script tag already carries `data-cfasync="false"`; also add `defer`-safe loading and log a `wp_ajax` beacon when the mount fails so the next export can count blank admins (see section 4).

### 2.3 Cut the per-request cost (would have kept ~6: "Broke the website" on 4.x/5.x plus unmeasured activation failures)

- `wallet-up-start.php`: Freemius is required on every request (`plugins_loaded`, priority 0) and its main class alone is 967 KB. Load it only when `is_admin()`, on cron, or on the uninstall path. The SDK supports this pattern (its own docs call it "lazy loading").
- `src/Plugin.php`, `initialize()`: 93 plugin files and 2.3 MB of PHP are parsed on a plain front-end page view. Gate the webhook handler (`src/API/WebhookHandler.php`, 57 KB), payment gateway handler, QR endpoint and memberships behind "is this a REST, checkout or Wallet Up endpoint request", and the admin notices behind `is_admin()`. Target: under 25 plugin files and 600 KB on a front-end request, peak under 44 MB.
- `readme.txt`: once that is done, state honest requirements: "works within WordPress's default 40 MB" instead of "256 MB minimum, 512–768 MB recommended", which reads as a warning to the exact small sites that install a free QR plugin.
- `vendor/freemius`, webfonts and language blobs: the 19 MB package is a download-time and update-time cost on shared hosting; the duplicate 2.9 MB webfont set and the unused `.wup` files go first.

### 2.4 Say where it works (would have kept ~10: non-US "didn't work", "not available", "can't find my currency")

- `src/Core/Config/PaymentMethodsConfig.php`: add a `countries` field per method (`cashapp`: US, UK; `venmo`: US; `zelle`: US; `paypal`: worldwide list).
- Wizard step 1 and the dashboard: read the site's locale and the WordPress timezone/country, and say in one line: "Cash App and Venmo only pay out in the United States. PayPal.me works in your country." Pre-select PayPal for non-US sites. This is a comprehension fix that costs one sentence and avoids a wasted install.
- Listing: say "US peer-to-peer apps; PayPal.me worldwide" in the first paragraph.

### 2.5 Keep the PHP floor honest (unmeasurable loss, likely larger than any row above)

- 18% of recorded uninstalls ran PHP below 8.1 before the floor was raised. Those sites now cannot install at all and leave no record. Either support 8.0 (the codebase uses readonly properties and `never`? check `grep -r "readonly\|: never" src` before deciding) or state the floor in the listing's first paragraph so the install button is not a dead end.

### 2.6 Legacy causes already removed, for the record

The boolean-capability menu, the top-level `current_user_can()` redirect, the pluggable include, the Google Charts QR and the URL-encoded Zelle code are all 3.x. They explain 38 of the 66 reliability rows and need no action beyond keeping 3.x users updated; a one-time admin notice on sites still running `<6.0` would be the only lever.

### Zero-configuration definition

Within 60 seconds of clicking Activate, with no setup at all, the user should see:

1. The Wallet Up welcome screen, not an opt-in, not a permalink error (done in 6.4.0).
2. On that screen, **one input: "Your Cash App $cashtag, Venmo username or PayPal.me name"** with a live QR and link rendering to the right as they type, and a note of which of the three works in their country. Industry and goal questions move after this, optional.
3. A **"Put it on a page"** button that creates a published page with the block, and a **"Copy link"** button. The success state is a URL they can open on their phone.
4. If they do nothing else, placing any Wallet Up block or shortcode on a page renders either the payment card or, for an administrator, a one-click "Turn on" box. A visitor never sees a blank gap where a payment should be.
5. No admin screen is ever blank: the dashboard and the wizard have a server-rendered fallback that appears before the React app does.

## 3. Self-check: "Why isn't it showing on my page?"

A single server-rendered panel, reachable from the dashboard, the Payments screen and the admin-only inline notice on a page, that answers in plain words. It runs the checks below and prints the first failing one with a one-click fix where possible. `src/Core/Retention/SetupCheck.php` already has the skeleton (PHP version, no method enabled, WooCommerce missing, card keys); extend it rather than adding a new class.

| Check | Plain-words result | Fix offered |
|---|---|---|
| Any method has a saved username | "You have not entered a Cash App, Venmo or PayPal name yet, so there is nothing to show." | Input box inline |
| Saved method is switched on | "Cash App has a name saved but is switched off, so pages show nothing." | "Turn on Cash App" button |
| Shortcode or block exists on at least one published page | "No page uses a Wallet Up block or shortcode yet." | "Create a page" button |
| That page is published, not draft | "Your page 'Pay me' is a draft; visitors cannot see drafts." | "Publish" link |
| The method's country matches the site | "Venmo only pays out in the United States; your site is set to India. PayPal.me works there." | Pre-fill PayPal |
| Front-end render test | Render the shortcode server-side with `current_user_can` forced off and report whether output is empty. "On your page, visitors currently see: nothing." | Links to the failing check above |
| Caching plugin detected and page cached before setup | "A caching plugin is active; the page may show the old version until the cache is cleared." | "Clear cache" via the detected plugin's API |
| Admin app failed to mount in the last day (beacon from 2.2) | "The visual dashboard did not load in your browser. Use the simple form." | Link to the fallback form |
| PHP memory headroom | "Your server allows 48 MB; Wallet Up and WordPress need about 50." | Documented host instruction |

Surface the same first failing line, in one sentence, on the Plugins screen row under the plugin description, since "didn't work" users go back to the Plugins screen to uninstall and that is the last place a message can reach them.

## 4. Instrumentation so the next export shows where people fail

Freemius only creates an install record for an anonymous free user at the moment of uninstall, which is why `created` equals `uninstalled_at` and why nothing about the retained population or the time-to-churn can be read today. Three options, in order of how little they change for the user:

1. **Carry a timeline into the uninstall reason.** The plugin already stores `wallet_up_activated_at`. At deactivation, append a short machine-readable summary to Freemius's `reason_info` through the `uninstall_reasons` filter the plugin already hooks in `src/Core/Retention/DeactivationOffer.php`: minutes since activation, whether a method was saved, whether a page with a block exists, whether the admin app ever mounted, PHP version, country from the site locale. Example: `[t=3m method=none page=none admin_mount=no php=8.2 cc=IN]`. The free-text column then becomes analysable with no new endpoint and no new consent, since the data goes only where the reason already goes.
2. **Opt-in users get events.** For users who did accept the Freemius opt-in, send one Freemius custom event per milestone: `setup_started`, `method_saved`, `page_created`, `first_visitor_render`, `admin_mount_failed`. Freemius's event API is already part of the SDK; nothing new is sent for anonymous users.
3. **Count silent install failures.** Add the `Requires PHP` floor and memory requirement to the first line of the listing and, separately, ask Freemius or WordPress.org for the "install attempts vs activations" number; the plugin cannot record a site that never activated it.

What to read in the next export, after 6.4.x has been out for 30 days: the share of reliability reasons on 6.4.x versus 6.3.x, the `t=` distribution from item 1 (if most are under five minutes the admin or the wizard is still failing; if they cluster at days the problem has moved to the product), and whether `admin_mount=no` appears at all.
