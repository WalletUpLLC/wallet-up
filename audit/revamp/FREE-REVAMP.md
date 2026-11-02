# Free tier revamp: stop the churn

Synthesis of the Freemius uninstall export of 2 November 2026 (kept outside the repository because it lists customer site URLs and account ids), the 6.3.9 audit, the 6.4.0/6.4.1 proof runs, and three deep-dive reports in `agent-reports/` (free/Pro boundary, reliability and comprehension, acquisition and retention). Where the reports disagree, this document says which reading it takes and why.

## 1. What the export actually says

**It is an uninstall-only export.** All 281 records are uninstalled installs (274 free, 7 Pro). It cannot give a churn rate, because it does not contain the installs that stayed.

**It cannot time most uninstalls.** The free build ran Freemius in opt-in mode, so an anonymous user had no install record until the deactivation dialog created one. On 207 of 274 free rows `created` equals `uninstalled_at`. The "219 uninstalled within a minute" figure that appears in one agent report is this artefact, not a measurement. For the 67 users Freemius knew earlier, the real distribution is: 57% gone within an hour, 88% within a week. That cohort is the only one with timing, and it is the number to beat.

**The reasons that carry signal are the free-text ones, and they cluster.** Of 38 written reasons, 13 name the free/Pro wall: Zelle paywalled (7), WooCommerce checkout paywalled (6). Nobody wrote that they wanted analytics, donations, QR styling, short links, crypto or mobile money, which is what Pro is built from. A further group is geographic: "not available in my location", "can't find my currency", plus 39% of all free uninstalls coming from outside the United States, where Cash App, Venmo and Zelle do not work.

**Reliability was a 3.x problem and is mostly gone.** "Didn't work" style reasons are 28% of 3.x uninstalls and 10% of 6.x. The reliability report traced 38 of the 66 reliability rows to four 3.x bugs that no longer exist: a settings menu registered with a boolean capability so it never appeared, a top-level `pluggable.php` include that fatals with other plugins, Google Charts QR codes, and a Zelle QR that encoded an enrolment URL banks cannot scan. Three current-code gaps remain: a shortcode for a switched-off method renders nothing for visitors, the React admin can mount blank, and the plugin parses 93 files and 2.3 MB of PHP on every front-end request, which is why the readme asks for 256 MB.

**"Temporary deactivation" is noise.** It is the first radio button, and its share falls with every generation (36%, 22%, 20%). Treat it as "no reason given".

**One push-back on the premise.** The 7 Pro uninstalls in the file include "it never worked", "Doesn't work" and "Didn't work". The Pro users who are happy are not in an uninstall export, so the file neither proves nor disproves that. It does say Pro has its own "didn't work" tail.

**Composition of free churn, estimated from country, language and text:**

| Why they left | Share of free uninstalls |
|---|---|
| Hit the Zelle or WooCommerce-checkout wall after installing | ~45% |
| Site is in a country where Cash App, Venmo and Zelle cannot work | ~30% |
| Users the current free tier can serve (freelancers, creators, tip jars, nonprofits) | ~25 to 35% |
| Accidental or test installs | ~5% |

Only about a third of the people who install can be served by the free tier as it stands. The 6.4.x releases fixed the first minute for that third. The revamp has to fix the other two thirds, and that is a packaging and targeting problem, not a polish problem.

## 2. The thesis

The free product disqualifies itself before it can be used. It attracts US store owners who want Cash App at checkout, US church and club treasurers who want Zelle, and a third of its installs from countries where its headline methods do not exist. Each of those groups finds out after installing, at the greyed-out button or the PRO badge, and leaves for "a free plugin that does that". The wall they hit protects almost no revenue: the file shows $241.71 gross across three years, and no Pro churn over price.

The revamp therefore has four pillars, in this order of leverage:

1. **Move the wall** so that what the listing promises is what free delivers: Zelle free, one peer-to-peer method at WooCommerce checkout free. Pro stays the "run a business on it" tier.
2. **Say where it works** before the user types anything: country-aware methods, PayPal.me as the worldwide path, honest geography in the listing.
3. **Never be silent**: a page never renders nothing, an admin screen is never blank, and a self-check answers "why isn't it showing" in plain words.
4. **Close the loop** in the first week: a live page at the end of setup, a first-tap email, a payment-confirmed email, a day-7 digest, a dashboard card that answers "did anyone pay me". No upsell before day 14.

Everything in pillars 2 to 4 hooks into code that already exists and, on the free tier, currently throws its data away.

## 3. Pillar 1: the free/Pro boundary

Decision recommended: **ship Zelle free now, and one peer-to-peer method at WooCommerce checkout free in the following release.** The boundary report weighed four options; the usage-capped tier was rejected (no metering exists in the code, directory users expect feature tiers), and "all peer-to-peer free everywhere" was judged the right destination but too much surface for one step.

| Candidate | Answers in the data | Pro revenue risk | Effort |
|---|---|---|---|
| A. Zelle free on pages | 7 Zelle texts | negligible: Zelle has no pay link, no API cost, nobody bought Pro for it | about a day |
| B. One P2P method at WooCommerce checkout free | 6 checkout texts | moderate, contained by one-method cap and keeping grouped display, discounts, verification, analytics Pro | two to three days |
| C. All P2P free everywhere | all 13 | real in principle, small in practice | four to six days |
| D. Usage-capped free | all, on paper | aligned | two weeks plus licence-server work; rejected |

**What stays Pro** and keeps the upgrade story intact: grouped and mixed checkout display, a second and third checkout method, per-method discounts, custom order statuses, automated verification, analytics, receipts, donation pages, memberships, QR Studio, short links, WPForms fields, own-keys Stripe, crypto and mobile money. These are the features of a merchant who stayed and has orders flowing, which is exactly the person who can pay.

**Zelle specifics.** In 6.x Zelle is an uploaded bank QR with scan instructions (`CUSTOM_QR_METHOD_IDS`, `wallet_up_custom_qr_images`, `generateZelleHelper()`), not the 3.x enrol-link QR that produced "not a Zelle QR" complaints. Shipping that flow free carries the explainer "Zelle has no pay link. Download the QR from your bank app and upload it here." The 6.4.1 change that removed the media library from free screens must be reversed for the Payments screen when Zelle is enabled, since the upload needs it.

**Checkout specifics.** Do not loosen the gate on `WooCommerceIntegration` (48 hooks, assumes premium throughout). Add a slim `src/WooCommerce/FreeP2pGateway.php` next to `FreeCardBootstrap`: one `WC_Payment_Gateway` subclass, id `wallet_up_p2p`, one method chosen from the enabled ones, `process_payment()` puts the order on-hold and the thank-you page renders the existing `DisplayHandler` QR and "I've sent it" confirmation form, which already accepts a `wc_order_id`. A `P2pBlocksSupport` modelled on `StripeCardBlocksSupport` covers the block checkout.

## 4. Pillar 2: say where it works

- `PaymentMethodsConfig`: add a `countries` field per method (Cash App: US, UK; Venmo: US; Zelle: US; PayPal.me: worldwide list; the Pro regional methods: their countries).
- On activation, read `timezone_string`, `get_locale()` and `woocommerce_default_country`; if none points to the US, set `wallet_up_geo_hint`. The Welcome step renders one extra line: "Your site looks like it is in Germany. Cash App and Venmo work in the United States only; PayPal.me works where you are. Pro adds Interac, PayID, BPAY and mobile money." with "I'm in the US" and "Continue with PayPal.me". Pre-select PayPal.me abroad.
- The amount rendering stops hard-coding `$`; currency follows the site, with the method's own currency rules.
- Listing, first line under the title: "Put a Cash App, Venmo or PayPal.me 'pay me' QR code and link on any page. US peer-to-peer apps; PayPal.me works worldwide. Free, no account, no fee from us." Tags: `cash app, venmo, paypal, qr code, tip jar`; drop `payments`. Replace screenshots 2 and 5 with the "You're ready" screen, the public card on a phone and the Tip Jar block.
- PHP floor: 18% of recorded free uninstalls ran PHP below 8.1 before the floor silently blocked them. Either support 8.0 on the free build, or put "Requires PHP 8.1" in the listing's first paragraph so the install button is not a dead end. This is a decision, not a default.

## 5. Pillar 3: never be silent

Ordered by churners it would plausibly have kept.

1. **Never render nothing.** `PaymentRegistry::setupNotice()` and `PaymentMethod::processShortcode()`: visitors get an HTML comment naming the switched-off method; administrators get an inline "Turn on Cash App" button that posts to a nonce-checked action and reloads. A method with a saved username renders the card even when the flag is off, with an admin-only badge, so the flag is a visibility toggle and not a trap. The 6.3.9 "saving switches it on" fix removed the trigger; this removes the failure mode.
2. **No blank admin.** Cut the onboarding fallback timer from 8 seconds to 2.5 and render the simple setup form server-side inside the root, hidden until React mounts. Give the dashboard root a server-rendered card with the saved method's QR and link. Send a beacon when the mount fails.
3. **Self-check panel.** Extend `SetupCheck` and surface it from the dashboard, the Payments screen, the inline notice and the Plugins row. It prints the first failing check in plain words with a one-click fix: no username saved; method switched off; no page uses a block; the page is a draft; method does not work in the site's country; the shortcode renders empty for visitors; a caching plugin may be serving the old page; the admin app failed to mount; PHP memory headroom.
4. **Cut the per-request cost.** Lazy-load Freemius outside admin, cron and uninstall. Gate the webhook handler, gateway handler, QR endpoint and memberships behind the requests that need them. Target under 25 plugin files and 600 KB on a front-end request, peak under 44 MB. Then state honest requirements in the readme instead of "256 MB minimum". Trim the 19 MB package: duplicate webfonts, unused `.wup` files, stray dev file.

## 6. Pillar 4: the first-week loop

Design rule for the free tier: the user's only question for seven days is "did anyone pay me?" Every touch answers it or helps them get a yes. No upsell before day 14, and then only to users with activity.

| Moment | What happens | Hooks into |
|---|---|---|
| Minute 1 | Geography line on Welcome (pillar 2) | `OnboardingHandler`, main bundle dictionary |
| Minute 2 | Setup ends by publishing the page ("Pay Test Shop"), not opening a draft; shows the live URL and "View your payment page" | `OnboardingHandler::handleComplete`, existing create-page code |
| Minute 3 | Opt-in offered once, after value, as "Want to know when someone pays you? Turn on notifications." | Freemius `opt_in()`, new `wallet_up_notify_email` |
| Day 1 to 7 | Free counter (impressions, clicks, confirmations, 30-day ring in one option); first-tap email; payment-confirmed email with name, amount, reference, reply-to | `wup-events.js` beacon, `DisplayHandler` impressions (currently discarded on free), `walletup_confirm_payment_sent` |
| Day 7 | One digest: "12 views, 3 taps" with one tip, or "Nobody has seen your code yet" with three ways to get it in front of people | daily cron beside `walletup_clean_expired_payments` |
| Dashboard | "This week" card (views, taps, last three confirmations) replaces "Setup Progress 33%", which pushes users to configure methods they do not have | `AssetManager::getAdminDataForReact`, one bundle edit |
| Review prompt | Fires on first confirmation or ten taps, never before day 7, instead of a 14-day timer | `ReviewPrompt::shouldShow` |
| Reminder banner | Adds "page created but never viewed" with a share link; disappears after the first click | `OnboardingBanner`, `SetupCheck` |

Email copy for each of these is written out in `agent-reports/03-acquisition-and-retention.md`, section 3.

## 7. Measurement: proving it within 60 days

The export cannot show time-to-uninstall improving, so instrument first.

1. **Carry a timeline in the uninstall reason.** Through the `uninstall_reasons` filter the plugin already hooks in `DeactivationOffer`, append a visible summary to the feedback text: `[v6.5.0 · installed 3h · methods 1 · page views 0 · admin_mount yes · php 8.2 · cc US]`. This works for anonymous users because the feedback submission is what creates their record. From then on every uninstall carries its own age and state. No new endpoint, no new consent.
2. **Opt-in cohort.** Users who accept the step-4 notifications opt-in get an exact Freemius `created`. Report that cohort separately; it is the one the loop can reach, so it is the one the loop is accountable for.
3. **Uninstalls per download, weekly.** WordPress.org publishes daily downloads; Freemius gives uninstalls. Baseline eight weeks before, compare eight weeks after. Needs no code.
4. **Reason mix.** Free-text mentions of Zelle, WooCommerce and location per 100 uninstalls. These fall first, within two weeks of the listing change, because listing changes act on new installs immediately.

| Metric | Today (opted-in cohort of 67) | 60-day target |
|---|---|---|
| Uninstalled within 1 hour | 57% | under 30% |
| Uninstalled within 7 days | 88% | under 60% |
| Reasons naming Zelle, WooCommerce or location, per 100 | about 7 | under 2 |
| Free users with at least one recorded tap in week one | not recorded | measured, over 40% |

The last row decides what comes next. If week-one taps stay low after the loop ships, the limit is the users' own traffic, and the next investment is distribution help (printable QR, invoice-ready link), not more onboarding.

## 8. Release plan

| Release | Contents | Effort |
|---|---|---|
| 6.5.0 "Works where you are" | Zelle free (pillar 1A); country-aware methods and geo line (pillar 2); never-render-nothing and inline Turn-on (pillar 3.1); uninstall-reason timeline and opt-in-after-value (pillar 7.1, 6 minute 3); listing and screenshots | about one week |
| 6.6.0 "Did anyone pay me" | One P2P method at WooCommerce checkout free (pillar 1B); free counter, first-tap and confirmation emails, day-7 digest, "This week" card, review prompt on value (pillar 4) | about two weeks |
| 6.7.0 "Light and honest" | Server-rendered admin fallbacks and self-check panel (pillar 3.2, 3.3); per-request cost cut, lazy Freemius, package trim, honest requirements, PHP floor decision (pillar 3.4, 2) | about one week |

6.4.0 and 6.4.1, already built and proven on this branch, ship first and unchanged.

## 9. Decisions only the owner can make

1. Zelle free on the free tier. Recommended yes; the data is unambiguous and the revenue at stake is negligible.
2. One peer-to-peer method at WooCommerce checkout free. Recommended yes, with the one-method cap and every store-operations feature kept in Pro.
3. The PHP floor: support 8.0 on free, or advertise 8.1 in the first line. Either is fine; the silent dead-end is not.
4. The Stripe platform-fee card path at checkout: once a free P2P method exists at checkout, "forcing me to use Stripe" stops being true, but the fee wording on the Sell-with-cards screen should say plainly that it is optional.

## 10. Where the reports disagree, and the reading taken here

- The boundary report counts 219 one-minute uninstalls as real; the reliability and retention reports identify that as the Freemius record-creation artefact. This document uses the 67-user opted-in cohort for timing.
- The retention report suggests Zelle could return as the 3.x static enrol-link QR; the reliability report shows that QR did not scan in bank apps. This document ships the 6.x uploaded-bank-QR flow, not the enrol link.
- The reliability report puts the per-request cost cut high; the retention report puts the first-week loop first. This document orders by churners kept: boundary and geography first because they cover two thirds of uninstalls, then never-silent, then the loop, then cost.

## Appendix: the raw inputs

- The Freemius export itself is not committed: it contains customer site URLs and user ids. Keep it in the Freemius dashboard and re-export when measuring.
- `agent-reports/01-free-pro-boundary.md`: four boundary candidates, code-level changes for the recommended one, objections answered.
- `agent-reports/02-reliability-and-comprehension.md`: complaint-to-root-cause table against 3.x and 6.x code, hardening plan, self-check design, instrumentation.
- `agent-reports/03-acquisition-and-retention.md`: segment table, listing copy, first-week loop with email copy, measurement plan.
