# Free-tier retention: who installs, what they expected, what keeps them a month

Source: Freemius uninstall export of 2 Nov 2026 (281 records: 274 free, 7 Pro), the 6.3.9 audit, and the 6.4.0/6.4.1 proof runs.

## 0. Two facts about the data that change how to read it

**The export cannot measure time-to-uninstall for most users.** In 207 of 274 free records `created` equals `uninstalled_at`. Freemius only learned those installs existed at the moment the uninstall form was submitted, because the user never opted in. The "median 0 seconds" is an artefact, not a measurement. For the 67 records where Freemius knew the install earlier, the distribution is: 12 gone within a minute, 11 within ten minutes, 15 within an hour, 8 within a day, 13 within a week, 8 after a week. So for the only cohort with real timing, 57% left within an hour and 88% within a week. That is the number to beat.

**"Temporary deactivation" is the first radio button, not a diagnosis.** It is 37% of 3.x/4.x-era uninstalls, 23% of 5.x, 21% of 6.x. It falls as the product improves, which is what you would expect if it mostly means "I don't want to answer, let me out". Treat it as "no reason given". The reasons that carry signal are the ones with free text, and those cluster on three things: Zelle behind the paywall (7 mentions, the most of any keyword), WooCommerce checkout expected in free (3 direct, several indirect), and "didn't work" on the 3.x shortcode-and-QR era.

A third, smaller fact: 28 of 274 free installs run PHP below 8.1. Those sites can no longer install 6.x at all. They are a hidden funnel loss, not an uninstall.

## 1. Segments

Shares are of the 274 free uninstalls, assigned from country, language, reason text and site URL where it was telling. They are estimates; the export has no "industry" field. The wizard's industry answer is stored in `wallet_up_industry` but never reported anywhere.

| Segment | Share (est.) | What they expected | What free gives them today | What would keep them |
|---|---|---|---|---|
| US small shop or WooCommerce store ("works with my shop", "checkout", "payment gateway" searches) | ~30% | Cash App / Venmo / Zelle as a checkout option, like any gateway | QR or link on a page; checkout placement is Pro; a card gateway via Stripe with a platform fee | One peer-to-peer method at checkout on free, or an honest "not at checkout" message before install (6.4.0 did the second). Nothing else retains this segment. |
| US Zelle user (bank-transfer people, often church or club treasurers) | ~15% | Zelle QR, because Zelle was free in 3.1 to 4.x and the title still says Zelle | Zelle is Pro-only; the free wizard shows it greyed with a crown | Zelle back in free as a plain "pay to this email/phone" QR (it is a static enrol link, no API), or drop Zelle from the title entirely. Half-measures keep producing "seriously?" exits. |
| US freelancer / service provider (invoices, one-off jobs) | ~15% | Put a "pay me" QR or button on a page, done | Exactly that, and it works well; the step-4 screen is the best moment in the product | They stay if they get paid once. They need to know when a visitor clicked or paid, and a page that looks right in their theme. |
| US creator / tip jar / events | ~10% | Tip jar, preset amounts, maybe recurring | Tip Jar block with presets; recurring is Pro | A confirmation ("Someone just tipped $5") and a weekly "3 taps this week" nudge. Without feedback the tip jar feels dead and gets removed. |
| US nonprofit / church | ~10% | Donation page, receipts | Tip Jar only; donation pages and receipts are Pro; the wizard offers a free Pro licence to nonprofits (6.3.1) | The nonprofit licence offer is strong but buried in the wizard. Surface it on the listing and the dashboard for the nonprofit industry choice. |
| Non-US, known country (India 21, Germany 9, UK 8, France 7, Pakistan 4, Nigeria, Bangladesh, Mexico…) | ~29% known + most of the 10% unknown | A payments plugin that works where they are | Cash App, Venmo, Zelle are US-only. PayPal.me works in most of these countries but is never pitched as the non-US path. "Can't find my currency", "Not available in my location" appear verbatim | Tell them at install (geo check) and give them the PayPal.me path, or the Pro methods for their region (Interac for Canada, PayID for Australia, mobile money for Kenya/Côte d'Ivoire/Zambia). Today they discover it by reading the greyed-out buttons. |
| Accidental / curious ("accidentally installed", "don't remember installing", test strings like "tsetng") | ~5% | Nothing | n/a | Nothing to do; subtract them from the churn target. |

Net: roughly 45% of free uninstalls (shop + Zelle) left over a paywall they hit after installing, and roughly 30% left because the product cannot work in their country. Only about a third are people the free tier can actually serve today, and those are the ones a retention loop can keep.

## 2. Listing and acquisition beyond 6.4.0

6.4.0 already retitled to "Cash App, Venmo & PayPal QR Code Payments", dropped the "payment gateway" and "woocommerce" tags, and added a free-versus-Pro section. Remaining changes:

**Say "United States" in the first line.** Cash App, Venmo and Zelle are US-only products. A third of installs are from countries where two of the three cannot work. Proposed short description (the 150-character line under the title):

> Put a Cash App, Venmo or PayPal.me "pay me" QR code and link on any page. US peer-to-peer apps; PayPal.me works worldwide. Free, no account, no fee from us.

**Lead the description with the one thing it does, then the geography.** First paragraph:

> Wallet Up puts a scannable "pay me" code and a tap-to-pay link on any WordPress page or post. Enter your $cashtag, Venmo username or PayPal.me name, and your customers pay you directly in the app they already have. Nothing goes through us: no merchant account, no API keys, no fee.
>
> Cash App and Venmo are available in the United States. PayPal.me works in most countries. Looking for Zelle, Interac e-Transfer, PayID, M-Pesa or cards at checkout? Those are in Wallet Up Pro.

**Decide Zelle, then make the listing match.** Zelle is the most-cited word in uninstall reasons and the title said Zelle until 6.4.0. Either put Zelle back in free as the static QR it was in 3.x (recommended: it is an enrol link, costs nothing to serve, and removes the single loudest complaint), or remove the word Zelle from every free-tier surface including the wizard's greyed-out button, so nobody installs for it. Keeping it visible but locked is the one option the data says not to do.

**Tags:** `cash app, venmo, paypal, qr code, tip jar`. Drop "payments": it ranks against hundreds of gateways and brings the shop segment that cannot be served.

**Screenshots:** replace 2 and 5 (WooCommerce checkout, Pro) with (a) the step-4 "You're ready" screen, which is the product's best screen, (b) the public payment card on a phone, (c) the Tip Jar block. Put the WooCommerce screenshot last with "(Pro)" in its caption.

**Localisation and geo-qualification.** Do not translate the listing; 90% of installs are English-language sites and the product is US-centric. Instead geo-qualify in the plugin itself (section 3, "Day 0, minute 1"). The 14 Spanish-language sites are mostly US Spanish speakers; the existing es_ES admin translation covers them.

**PHP floor.** 10% of this export runs PHP 7.4 or 8.0. If the 8.1 floor is deliberate, keep it; if it is inherited from the Pro build, lowering the free build to 8.0 recovers installs that today fail silently at the "This plugin doesn't work with your version of PHP" message.

## 3. First day and first week on the free tier

Design principle: the free user's only question for seven days is "did anyone pay me?" Every touch should answer it or help them get a yes. Nothing in this section proposes a new upsell.

### Day 0, minute 1: honest geography before the wizard

On activation (hook: `wallet-up-start.php` activation callback, next to `scheduleRedirectOnActivation`), read `get_option('timezone_string')`, `get_locale()`, and `woocommerce_default_country` if present. If none of them points to the US, set an option `wallet_up_geo_hint` and let the wizard's Welcome step render one extra line (new copy key in the main bundle dictionary):

> Your site looks like it is in **Germany**. Cash App and Venmo work in the United States only; PayPal.me works where you are. Pro adds Interac, PayID, BPAY and mobile money. [I'm in the US] [Continue with PayPal.me]

"I'm in the US" clears the hint. This single line answers "Not available in my location" before the user has typed anything.

### Day 0, minute 2: the wizard ends on a page, not a dashboard

The step-4 screen already offers "Create page with this block". Make it the primary action and pre-publish the page (status publish, title "Pay {business name}") instead of opening a draft in the editor. Then show the live URL and a "View your payment page" button. A user who has a live page on day 0 has something to send to a customer; a user with a draft in the block editor has homework. Hook: `OnboardingHandler::handleComplete` already receives the step data; the page creation code exists behind the "Create page" button.

### Day 0, minute 3: ask to opt in after value, with a reason

Freemius anonymous mode (6.4.0) removed the opt-in from the activation path. Re-offer it once, on the step-4 screen, below the QR, framed as the thing it actually buys the user:

> Want to know when someone pays you? Turn on notifications and we'll email you the first time your code is scanned or your link is tapped. [Turn on notifications] [Not now]

"Turn on notifications" calls the Freemius opt-in (`$fs->opt_in()` via the existing `wp_ajax` pattern) and sets `wallet_up_notify_email`. This is also what makes section 4's measurement possible.

### Day 1 to 7: the feedback loop

The plugin already records what it needs and throws it away on the free tier:

- `assets/js/wup-events.js` sends a `wallet_up_payment_event` beacon on every click of a payment link or QR (`type: click`).
- `DisplayHandler::generateDisplay` calls `AnalyticsHandler::recordImpression` for every render, but `AnalyticsHandler` is only instantiated on Pro, so free impressions vanish.
- `DisplayHandler::generateConfirmationForm` renders an "I've sent the payment" form that posts `walletup_confirm_payment_sent` with name, email and reference. On free, nothing tells the merchant.

Proposed free-tier loop, all built on those three hooks:

1. **A free counter, not analytics.** Add a tiny option `wallet_up_free_counts` keyed by day: impressions, clicks, confirmations. Write to it from the three hooks above when not premium. No table, no dashboard page, a 30-day ring. (Pro keeps the full Activity module; this is a number, not a report.)
2. **First-click email.** The first time a click or confirmation is recorded, send one email to `wallet_up_notify_email` (or the admin email if the user opted in):

   Subject: `Someone just tapped your Cash App link`

   > A visitor on **Pay Test Shop** tapped your Cash App link a minute ago. Payments go straight to your Cash App, so check the app for the money. Nothing else to do.
   >
   > If you want visitors to confirm on your site after paying, the "I've sent it" form is already under your code. You'll get an email each time it is used.
3. **Payment-confirmed email.** Each `walletup_confirm_payment_sent` on free sends:

   Subject: `{{name}} says they sent you ${{amount}} via Cash App`

   > {{name}} ({{email}}) confirmed a payment of ${{amount}} on {{page}}. Reference: {{reference}}. Check Cash App to match it. Reply to this email to reach them.
4. **Day-7 summary, only if there was activity.** One email:

   Subject: `Your first week with Wallet Up: 12 views, 3 taps`

   > Your payment code was seen 12 times and tapped 3 times this week. Most taps came from your "Pay me" page. Tip: add the Tip Jar block to your footer so every page can take a payment.

   If there was no activity, send a different one:

   Subject: `Nobody has seen your payment code yet`

   > Your code is live at {{url}} but no visitor has opened that page this week. Three ways people get paid with Wallet Up: send the link in your next invoice email, add the Payment Button block to your contact page, or print the QR (Download QR in your dashboard) for the counter.

   Hook: a daily WP-Cron event already exists for `walletup_clean_expired_payments`; add `wallet_up_free_weekly_digest` beside it, gated on `wallet_up_activated_at` + 7 days and on the opt-in.
5. **Dashboard card that answers the question.** Replace the "Setup Progress 33%" card (which pushes users to configure methods they do not have) with a "This week" card: views, taps, confirmations, and the last three confirmations with name and amount. For a user with zero activity it shows the three tips from the no-activity email. Hook: `AssetManager::getAdminDataForReact` already localises dashboard data; add `freeCounts`. The React card is the one bundle edit this plan needs.
6. **Review prompt follows value, not time.** `ReviewPrompt::shouldShow` fires at 14 days active. Change the condition to "at least one confirmation recorded, or 10 taps", whichever first, and never before day 7. Users who have been paid leave reviews; users who have waited 14 days leave.
7. **"Finish setting up" banner knows what is missing.** `OnboardingBanner` already uses `SetupCheck::run`. Add a finding for "page created but never viewed" that links to the page with "Share this link", and drop the banner entirely once one click exists.

### What not to do in week one

No "Explore Pro" email, no "unlock 20 methods" notice, no nonprofit offer except for users whose industry answer was nonprofit. Every free-tier touch in the first week is about the user getting paid. The upgrade conversation starts on day 14 at the earliest, and only for users with activity, because they are the only ones with a reason to want analytics, more methods or checkout placement.

## 4. Measuring the effect inside 60 days

The export's `created` timestamp is the uninstall time for 75% of users, so the export alone cannot show time-to-uninstall improving. Four instruments, cheapest first:

1. **Uninstalls per download, weekly.** WordPress.org publishes download counts per day (`api.wordpress.org/stats/plugin/1.0/downloads.php?slug=wallet-up&limit=60`) and active installs on the listing. Freemius gives uninstall events per week. Baseline the ratio for the 8 weeks before release; compare the 8 weeks after. This is coarse but needs no code and starts now.
2. **Reason mix shift.** Count "Expected something else", "Found a better alternative" and free-text mentions of Zelle, WooCommerce and location per 100 uninstalls, before and after. If the listing and geo changes work, these fall first, within two weeks of the listing update because listing changes act on new installs immediately.
3. **Real time-to-uninstall, for everyone.** Two parts:
   - Append install age to the uninstall feedback. `DeactivationOffer::decorate` already modifies the Freemius `uninstall_reasons` filter. Add one line of JavaScript on the plugins screen that, on submit of the Freemius deactivation form, appends `[v6.4.1 · installed 3h · methods 1 · page views 0]` to the `reason_info` field, and show that text in the dialog so it is visible, not hidden. This works for anonymous users because the feedback submission itself is what creates their record. From then on every uninstall carries its own age.
   - Store `wallet_up_activated_at` (exists), the count of configured methods, and the free counter in that string. After 60 days the export answers: did median age move from minutes to days, and did users who received a first-click email churn less than those who did not.
4. **Opt-in cohort timing.** Users who accept the step-4 "Turn on notifications" opt-in get a real Freemius `created` at that moment. Their `uninstalled_at` minus `created` is exact. Report that cohort separately; it is the cohort the retention loop can reach, so it is the one the loop is accountable for.

Targets that would count as proof, measured on uninstalls from installs that happened after release:

| Metric | Today (opted-in cohort of 67) | 60-day target |
|---|---|---|
| Uninstalled within 1 hour | 57% | under 30% |
| Uninstalled within 7 days | 88% | under 60% |
| Reasons mentioning Zelle, WooCommerce or location, per 100 uninstalls | about 7 | under 2 after the listing change |
| "Temporary deactivation" share | 21% (6.x) | unchanged; it is noise |
| Free users with at least one recorded tap in week one | unknown (not recorded) | measured, and over 40% |

The last row is the one that matters. Churn is downstream of "nobody tapped my code". If the week-one tap rate is low even after the loop ships, the problem is the users' sites, not the plugin, and the right next investment is distribution help (printable QR, email-ready link), not more onboarding.
