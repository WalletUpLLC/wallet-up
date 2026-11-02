# Where the free tier should end

Analysis of the free/Pro boundary for Wallet Up, grounded in the Freemius installs export (281 uninstalled installs, 274 free and 7 Pro, October 2023 to November 2026) and in the gating that actually exists in the 6.3.9 code.

## What the data says about the boundary

Of 274 free uninstalls, 38 left free text. Nearly every text that names a feature names one of two things:

| Theme | Count | Verbatim samples |
|---|---|---|
| Zelle is paywalled | 7 | "so Cash App and Venmo is free to use but Zelle isn't, seriously?" · "Not paying for Zelle integration" · "I'm not paying $7/mo to accept zelle... there are free plugins for that" · "I only wanted to use it for cash app and zelle and it didn't give zelle as a free option" · "zelle for woocommerce" (left for an alternative) |
| WooCommerce checkout is paywalled | 6 | "the plugin requires the pro version to do basic things like linking to woocommerce checkout. i cant pay for plugin i dont trust" · "I hoped it would integrate with woocommerce in the free version. I am poor." · "I can't get the payment gateway to show in Woocommerce" · "i thought it worked in checkout." · "I was looking for something that works with my shop" |
| A paywall in general | 2 more | "You paywalled one of the payment options i need to use." · "Forcing me to use Stripe. I dont need." |

Thirteen of 38 written reasons, a third, are the boundary itself. The rest are "didn't work" (7, mostly 3.x and 5.5.x), test installs, and noise. No one wrote that they wanted analytics, donations, QR styling, short links, crypto or mobile money. Those are the things Pro is built around, and they are not what free users are leaving over.

Two more facts shape the answer. First, 219 of 274 free uninstalls happened within one minute of install, so the boundary is being discovered on the first screen, not after use. Second, the 7 Pro installs that churned were "temporary deactivation" or "didn't work", never price. The buyers were fine with the price; the problem is that almost nobody reaches a buying decision because the free product disqualifies itself first. Total gross in the file is $241.71 across three years. The Pro revenue being protected by the current boundary is small, and the boundary is costing the install base that would feed it.

One correction to the premise in the brief: the "forcing me to use Stripe" complaint (6.2.4) is about the free WooCommerce card path, which is the only checkout option the free tier has. A user who wanted Cash App at checkout was shown Stripe Connect with a 2% platform fee as the free alternative. That is the same wall from a different side.

## What the code actually gates

- `PaymentMethodsConfig::FREE_METHOD_IDS = ['cashapp', 'paypal', 'venmo']`. Everything else is Pro, including `zelle`, which is also in `CUSTOM_QR_METHOD_IDS`: Zelle has no public pay link, so its "QR" is the image the merchant downloads from their own bank app and uploads. Zelle in this plugin is an uploaded picture plus instructions; there is no API cost and no verification logic behind it.
- `FreeCardBootstrap` registers exactly one WooCommerce gateway on the free tier, `wallet_up_card` (Stripe Connect, platform fee). It deliberately keeps `WooCommerceIntegration` (the 48-hook class that registers the `wallet_up` peer-to-peer gateway, per-method gateways, thank-you page QR, order status bridge) premium-only.
- The onboarding handler filters `enabled` methods and `accounts` by tier, and `applyStepToSettings('wc')` refuses to switch a peer-to-peer method on at checkout unless premium.
- Stripe Connect is the free card path on every plan, with the platform fee; own-keys Stripe is Pro.

So the walls are three: Zelle, peer-to-peer at checkout, and the fee on cards. Everything else behind Pro (analytics, donations, QR Studio, short links, memberships, crypto, mobile money, WPForms, own Stripe keys) is a feature a user adds after they are already retained.

## Candidate boundaries

### A. Zelle free, nothing else changes

- **User gets:** Zelle alongside Cash App, Venmo and PayPal on pages and in the shortcode and blocks. Still no peer-to-peer at WooCommerce checkout.
- **Churner in the data:** the 7 Zelle texts stay; the 6 WooCommerce texts still leave. The user who left for "zelle for woocommerce" still leaves.
- **Pro revenue risk:** negligible. Zelle is one of 24 Pro methods and the one with no pay-link mechanics; nobody in the file bought Pro for Zelle.
- **Engineering cost:** small. Add `'zelle'` to `FREE_METHOD_IDS`, let the free onboarding and Payments screen accept the uploaded Zelle QR (the upload path already exists for Pro under `CUSTOM_QR_METHOD_IDS`), un-grey it in the React preset lists, update the Pro method count copy ("Pro adds Zelle..."). The 3.x-era complaints "QR code is not a Zelle QR" are a product-education problem: the free tier must explain that the merchant uploads their bank's Zelle QR, which the Pro flow already does.
- **Competitors:** several free WordPress plugins display a Zelle QR or Zelle instructions on a page; I cannot name specific pricing with confidence. Users in the file believe free Zelle exists elsewhere ("there are free plugins for that"), and that belief is what matters.

### B. One peer-to-peer method at WooCommerce checkout free (Zelle stays Pro)

- **User gets:** Cash App, Venmo or PayPal as a selectable WooCommerce payment method with the QR on the order-pay and thank-you page, one method at a time; grouped/mixed display, discounts, custom order statuses, verification stay Pro.
- **Churner in the data:** the 6 WooCommerce texts are answered. The 7 Zelle texts are not; "zelle for woocommerce" still leaves.
- **Pro revenue risk:** moderate. Checkout placement is the feature store owners would pay for, and most of the Pro feature list on the readme is checkout-related. Limiting to one method and no customisation keeps a reason to upgrade.
- **Engineering cost:** medium to high. `WooCommerceIntegration` is a 48-hook class gated by `can_use_premium_code__premium_only()` in `Plugin::initializeServices()`. Either load it on the free tier with a method cap (riskiest: the class assumes premium throughout) or write a slim `FreeP2pGateway` next to `FreeCardBootstrap` that registers one `WC_Payment_Gateway` subclass rendering the existing `DisplayHandler` QR block on the pay page and marking orders on-hold. The second is the honest estimate: two or three days, plus block-checkout support via a copy of `StripeCardBlocksSupport`.
- **Competitors:** WooCommerce itself ships "Direct bank transfer", "Check payments" and "Cash on delivery" free; a Zelle or Cash App manual gateway is the same shape as those. Free "manual payment" gateways exist in the directory; I cannot vouch for specific ones.

### C. All peer-to-peer free everywhere (pages and WooCommerce checkout, all four US methods plus the regional ones); Pro is cards on own keys, donations, analytics, QR Studio, short links, memberships, WPForms, crypto, mobile money

- **User gets:** everything a person installing a "Cash App Venmo PayPal Zelle QR payments" plugin expects, including at checkout. Pro becomes the "run a business on it" tier.
- **Churner in the data:** all 13 boundary texts are answered. The "forcing me to use Stripe" user is answered because Cash App at checkout is now free and Stripe is optional.
- **Pro revenue risk:** real in principle, small in practice. The file shows $241.71 gross over three years and zero Pro churn over price. The Pro features that remain are the ones the readme leads with for stores (discounts per method, verification, analytics, donation pages, memberships), and every one of them is a feature of a merchant who stayed. The risk is that a store owner who only needs "Cash App at checkout, manual confirmation" never upgrades; that user is currently not a customer either, they are a one-minute uninstall.
- **Engineering cost:** highest. Same slim-gateway work as B but for all methods and the grouped display, plus Zelle from A, plus the onboarding `wc` step unlocked. Four to six days, and it touches the class that is the most hook-heavy in the plugin.
- **Competitors:** this is the WooCommerce core model (manual gateways free, paid is for automation and verification). Payment-gateway plugins with a free tier typically give away the basic gateway and charge for subscriptions, verification or analytics; I would not quote specifics.

### D. Usage-limited free tier (everything available, capped by volume: for example 25 payment pages or 50 confirmed orders a month)

- **User gets:** the full product with a meter.
- **Churner in the data:** the boundary texts are answered on day one, and the cap converts only people who succeed. This is the strongest conversion mechanic on paper.
- **Pro revenue risk:** low to moderate, and better aligned: revenue follows usage.
- **Engineering cost:** high and the wrong kind. There is no metering anywhere in the code; the plugin cannot count "payments" for peer-to-peer methods because nothing confirms them except the optional visitor "I paid" form. A cap on pages or QR codes is countable but easy to resent and easy to evade. It also needs licence-server support in Freemius or pay.walletup.app. Two weeks minimum, and it adds a new class of support ticket.
- **Competitors:** common in SaaS, rare in WordPress payment plugins; users of the directory expect feature tiers, not meters. Unsure how it would be received.

## Recommendation

Ship A immediately and B as the next release, which lands at C minus the extras: Zelle free on pages now, and one peer-to-peer method at WooCommerce checkout free in the release after, with grouped/mixed display, per-method discounts, verification, analytics and the rest staying Pro. The data is unambiguous that Zelle and checkout are the two walls people hit in the first minute, that nobody is leaving over the features Pro is actually built from, and that the revenue the current wall protects is a few hundred dollars across three years. A is a day of work with no revenue downside and answers a third of the written complaints. B is the real fix for the store audience the listing attracts, and the one-method cap plus the kept Pro checkout features leaves a clean upgrade story: "it works at checkout for free; Pro makes it a proper store gateway". I would not do D; the plugin has no metering and the directory's users do not expect it.

## What changes in code for the recommendation

**Release 1 (Zelle free, boundary A)**

- `src/Core/Config/PaymentMethodsConfig.php`: add `'zelle'` to `FREE_METHOD_IDS`. Keep it in `CUSTOM_QR_METHOD_IDS`.
- `src/Core/Admin/OnboardingHandler.php`: `FREE_ACCOUNT_FIELDS` gains `zelle_holder`; `handleFallbackSubmit()` and `renderFallback()` list Zelle with the hint "your Zelle email or phone, plus the QR from your bank app".
- `src/Core/Admin/AdminManager.php` / `AssetManager::getAllPaymentMethodsForReact()`: the free method list sent to React includes Zelle and the custom-QR upload flag so the Payments page shows the upload control on the free tier. Confirm the media picker dependency: `wp_enqueue_media()` was removed from the free build in 6.4.1 and the Zelle upload needs it, so re-enable it only when Zelle is enabled or on the Payments screen.
- `walup-react/dist` (and the JSX source when rebuilt): remove the crown/lock from Zelle in the onboarding method grid and the Payments page; change "Pro adds Zelle, Meta Pay, crypto, and mobile money" to "Pro adds Meta Pay, crypto, and mobile money"; add a two-line Zelle explainer under the field ("Zelle has no pay link. Download the QR from your bank's app and upload it here; we show it with your name and the amount.").
- `src/Core/Display/DisplayHandler.php`: `generateZelleHelper()` already renders bank instructions; make sure it runs on the free path (it is keyed on the method, not on premium, so likely no change).
- `readme.txt`: title back to "Cash App, Venmo, PayPal & Zelle QR Code Payments" honestly; FAQ "Which payment methods..." lists Zelle as free; Pro list drops Zelle.
- Upgrade note for existing free installs: nothing to migrate; Zelle holders saved under earlier Pro trials become active.

**Release 2 (one peer-to-peer method at checkout free, boundary B)**

- New `src/WooCommerce/FreeP2pGateway.php`, registered from `FreeCardBootstrap::init()` next to the card gateway. A single `WC_Payment_Gateway` subclass with id `wallet_up_p2p`, title from the chosen method, `process_payment()` sets the order on-hold and redirects to the order-pay/thank-you page where `DisplayHandler` renders the QR and the existing "I've paid" confirmation form (`generateConfirmationForm()` already takes a `wc_order_id`). Admin setting: one method, chosen from the methods enabled on the Payments screen.
- `src/WooCommerce/Blocks/`: a `P2pBlocksSupport` modelled on `StripeCardBlocksSupport` so the block checkout shows it; `FreeCardBootstrap::registerBlockSupport()` registers both.
- `src/Core/Admin/OnboardingHandler.php`: the `wc` step's `canPlaceP2pAtCheckout` becomes true for one method on free; `applyStepToSettings('wc')` writes the single method into the new gateway's settings instead of refusing.
- `src/Core/Admin/Notices/IntegrationDetector.php`: WooCommerce free copy becomes "Wallet Up is at your checkout with one method. Pro adds the rest, per-method discounts and verification."
- Onboarding step 3 React copy and the "Show my selected methods at my WooCommerce checkout" checkbox: free with one method selectable, PRO badge on the second.
- Keep `WooCommerceIntegration` premium-only; do not loosen its gate. The free gateway must not depend on it.
- `readme.txt`: WooCommerce section rewritten; screenshot 2 becomes a free screenshot of the single-method checkout.

## The two biggest objections

**"If Zelle and a checkout method are free, why would a store owner ever pay?"** Because the paying feature set was never "a QR code at checkout"; it is the things a store needs once orders are flowing: grouped and mixed method display, per-method discounts, custom order statuses, automated verification, analytics, receipts, donations and memberships. Every one of those stays Pro. The file shows nobody paid for the wall we have now; it shows a few hundred dollars in three years and 219 one-minute uninstalls. Free users who stay are the only people who can later buy Pro. A free tier that disqualifies itself in sixty seconds protects nothing.

**"Zelle QR codes don't really work; we'll trade a paywall complaint for a 'didn't work' complaint."** The 3.x complaints ("the QR code generated is not a Zelle QR") came from the old approach of generating a QR that pointed at enroll.zellepay.com, which Zelle apps do not scan. The current code already handles Zelle correctly as an uploaded bank QR with instructions, and that is what Pro users get today without complaint in the file. The fix is to ship that same flow free with the explainer text, not to keep it behind a paywall because an older version did it wrong.
