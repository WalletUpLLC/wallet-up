# Offered-then-locked audit: the free build and Pro

Rule applied: the free plugin shows only what it does. Pro is presented in one place, the Explore Pro page in the Wallet Up menu (plus the Upgrade link WordPress shows on the Plugins row). Anything that looked usable and then turned out to be Pro is either hidden on the free build or reworded as a plain fact without a call to action.

Two inventory agents (free admin app, setup wizard) completed before the weekly agent limit stopped the rest; their 51 findings, including the front-end, notice, gateway and listing surfaces they covered on the way, were de-duplicated and verified by hand against the code. Every gate below was reachable in the free build before this fix.

| # | Where | What the free build showed | Action |
|---|---|---|---|
| 1 | Admin navigation | Donations, QR Studio, Activity entries with PRO badges that open an "Unlock with Pro" card | Removed from the free navigation; Explore Pro describes them |
| 2 | Dashboard, Quick actions | Donations and Activity cards with PRO badges | Removed |
| 3 | Payments page | Meta Pay and WalletUp Pay rows with a disabled "Available in Pro" input | Removed |
| 4 | Payments page | Cryptocurrency, Mobile Money and "Your own Stripe & PayPal keys" sections with disabled fields and "Unlock with Pro" | Removed |
| 5 | Sell with cards | Collapsibles "Prefer to use your own Stripe keys?" and "Want to drop the platform fee?" that open on a See Pro button | Removed |
| 6 | Dashboard | "Unlock the full Wallet Up experience" banner | Removed; Explore Pro is the one surface |
| 7 | Settings, About | "Upgrade to Pro" link | Removed |
| 8 | Settings, Branding (added in 6.4.1) | A disabled "Powered by" checkbox with a PRO badge and See Pro | Card not shown on the free plan; the credit line is simply there |
| 9 | Setup wizard, method picker | 24 tiles, 20 of them locked, plus "Pro adds N more ways to get paid" | Free methods only; the line is gone |
| 10 | Setup wizard, donations | "How do you want to collect donations?" with Donation Form and Campaign locked | Chooser gone; the Tip Jar is the donation tool |
| 11 | Setup wizard, goal card | "a full donation form with suggested amounts, recurring gifts, and tax receipts" | Describes the Tip Jar (en, es, fr) |
| 12 | Setup wizard, integrations | Pro pills on detected integrations and a See Pro link under the hint | Pills and link removed; the hint states the facts |
| 13 | Setup wizard, WooCommerce box | "Show my selected methods" with a Pro pill when more than one method; extra methods reported back as Pro-gated | Box always names the one method it places; nothing is reported as gated |
| 14 | Setup wizard, finish step | Pink "Wallet Up Pro adds full Donation Campaigns" card with See Pro features | Removed |
| 15 | Welcome step, geography line | "Pro adds Interac, PayID, BPAY and mobile money" | Sentence removed |
| 16 | Block editor | Payment method dropdown listing 20 Pro methods as disabled "X: Pro" with an upgrade notice | Free methods only; no upgrade URL |
| 17 | Public pages | `[bitcoin]`, `[metapay]` and other Pro-method shortcodes rendered a "available in WalletUp Pro" box to visitors | Visitors get an HTML comment; administrators a plain note without a link |
| 18 | Block render | Administrators saw "is available in Pro. Upgrade to Pro" inline | Plain note, no link |
| 19 | Shortcodes page, templates | "Crypto Checkout" template whose shortcodes are Pro methods | Removed |
| 20 | WPForms admin screens | Notice whose only button was See Pro | Notice states the fact and opens Wallet Up |
| 21 | WooCommerce admin screens | Notice ending with what Pro adds | First sentence only |
| 22 | WooCommerce gateway settings | Description and help text ending with what Pro adds | Trimmed |
| 23 | Deactivation dialog | "See it in Pro" and "See what Pro adds" buttons on the free build | Free build points to setup instead |
| 24 | Wallet Up menu | Freemius "Upgrade" submenu beside Explore Pro | Submenu hidden on the free build; the page stays registered for the Plugins-row link |
| 25 | readme screenshot caption 2 | "WooCommerce Checkout … (Pro)" while one method is free | Reworded |

Kept on purpose: the Explore Pro page and its navigation entry; the Plugins-row Upgrade link WordPress shows for every Freemius plugin; the readme's "Pro Features" section, which is where the boundary belongs; the nonprofit offer (it gives Pro away, it locks nothing).

Proof: `proof/gates-*` folders hold the three drivers re-run on this build plus screenshots of the Dashboard, Payments, Sell with cards, Settings and Explore Pro screens with a text scan for PRO, Unlock, Upgrade to Pro, Available in Pro and See Pro.
