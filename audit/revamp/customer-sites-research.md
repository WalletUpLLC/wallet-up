# Who installs Wallet Up and what they need

> **How this was made and how to read it.** Sixteen agents classified the 280 sites in the Freemius uninstall export (2 November 2026). Direct page fetches were refused by this environment's network policy, so each site was looked up through web search and otherwise inferred from its domain name and the export's country, language and reason fields; the report states the confidence of every figure. Per-site records are kept outside the repository because they are customer data. Two corrections to the gap table from the maintainer's side: 6.3.11 ships bundled Spanish and French dictionaries and 6.7.0's free-plan additions match those screens, so "Non-English UI: not at all" should read "Spanish and French: yes; the other languages: no"; and the Zelle bank-QR upload is offered on the setup step and the Payments page, not hidden. Everything else stands as the agents wrote it.


A read of 280 sites that installed Wallet Up and later uninstalled it, taken from the Freemius export (uninstalls dated from the end of 2023 to 2 November 2026, so this is nearly three years of churn, not a recent snapshot). Each site was classified by what it appears to do and what it was probably trying to get from the plugin. No site is named here; the per-site working files stay in the scratchpad.

## Read this first: how solid the data is

- **280 sites** classified. Only **13 (5%)** could be confirmed by a search result from that very domain ("searched"). **241 (86%)** are inferred from the domain name, country, language and the uninstall reason text ("inferred"). **26 (9%)** could not be classified at all ("unknown").
- Direct fetches of the sites were blocked, and the search budget ran out much earlier than "the last ~40 sites": all 13 searched sites sit in the first six batches (120 sites). The last eight batches, **160 sites (57%)**, were never searched at all; their batch notes say so explicitly. For those, "inferred" means domain name plus export fields only.
- **Plugin versions are old.** Every one of the 280 uninstalls ran a build between 3.3.0 and 6.3.10: 133 on 3.x, 51 on 4.x, 67 on 5.x, 29 on 6.x. None of them ever saw 6.5.0 (Zelle free, 3 Nov 2026) or 6.6.0 (free one-method WooCommerce gateway, 4 Nov 2026). Every reason text below is a reaction to a product that no longer ships.
- **Free-text reasons are rare and often junk.** 41 of 280 sites typed anything in the reason box; 10 of those are keyboard-mash or a single character. So the quotable complaints below come from about 30 sites, not 280.
- "Inferred" is honest guesswork. A domain containing "peptides" or "church" is usually what it says; a personal name or acronym is a coin toss. Treat the segment sizes below as order-of-magnitude, not census figures.
- The export covers only sites that uninstalled. It says nothing about who stayed, so this is a churn profile, not a customer profile.
- The country field is unreliable in at least five cases (a US-named charity flagged as Malawi, a Kenyan-domain store flagged as US, a UK-domain studio flagged as Zambia, an Indonesian nonprofit flagged as Singapore, a US handyman build flagged as India). The geography figures below use the export field as given.

## 1. The business mix

| Segment | Sites | Share | Searched | Inferred | Unknown |
|---|---|---|---|---|---|
| Online shop (WooCommerce-style) | 78 | 28% | 7 | 71 | 0 |
| Agency, developer or test install | 42 | 15% | 1 | 36 | 5 |
| Nonprofit, church, mosque, union, club lodge | 30 | 11% | 0 | 30 | 0 |
| Other (campaigns, media, crypto, lottery, adult) | 21 | 7.5% | 0 | 21 | 0 |
| Unknown | 21 | 7.5% | 0 | 0 | 21 |
| Freelancer or creator | 20 | 7% | 3 | 17 | 0 |
| Service provider (contractors, consultants, drivers, salons) | 20 | 7% | 0 | 20 | 0 |
| Event or community group (clubs, alumni, weddings) | 14 | 5% | 1 | 13 | 0 |
| Education or coaching | 12 | 4% | 0 | 12 | 0 |
| Blog or personal site | 11 | 4% | 0 | 11 | 0 |
| Health and beauty | 8 | 3% | 1 | 7 | 0 |
| Restaurant or food | 3 | 1% | 0 | 3 | 0 |

The uninstall reasons across all 280, from the export: Temporary deactivation 90 (32%), Found a better alternative 33, Expected something else 28, Other 26, Didn't work 19, Didn't work after installation 18, Didn't understand how it works 17, No longer needed 15, Expected to work differently 15, Broke the website 9, Suddenly stopped working 5, Missing specific feature 4, Only needed for short period 1. The four "it did not work" reasons together are **51 sites (18%)**.

Three facts cut across every segment:

- **Geography.** 171 sites (61%) are in the US, 80 (29%) are outside the US, 29 (10%) have no country. Outside the US, Cash App, Venmo and Zelle do not exist. For those 80 sites only PayPal.me among the free methods could ever have worked, and the per-site notes repeatedly name the local rail they actually wanted: UPI (India is the largest non-US country at 20 sites, UPI named for 18 of them), JazzCash/Easypaisa (Pakistan, 4), QRIS (Indonesia), PIX (Brazil), Bizum/SEPA/iDEAL/Lydia (Europe), M-Pesa, Mobile Money, bKash, Yape, TWINT, Interac. One Nigerian blogger wrote "I can't find my currency"; a US-flagged blog wrote "Not available in my location". Crypto is named as a wanted method in 12 per-site notes, mostly high-risk shops.
- **WooCommerce.** 96 sites (34%) look like they run or intended to run a WooCommerce store: the 78 shops plus 18 non-shop sites (agencies, salons, coaches, services) flagged as WooCommerce-likely. Of the 78 shops, roughly **40 (about half)** appear to be in categories mainstream card processors refuse: research peptides, "aminos", "compounds" and "labs" (27 by domain pattern, 4 of them confirmed by search), grey-market pharmacies, cannabis/CBD/psilocybin, heated tobacco, replica watches, K2 spice, IPTV, plus four or five softer cases (a grey-looking whisky shop, a dropship-pattern golf-cart store, an intimates boutique, a supplement store). This is the single largest identifiable cluster in the data, and it is an inference from domain names, not from seeing storefronts. Four of the cluster are outside the US.
- **Paying customers churned too.** **7 sites** are Pro (premium = yes) in the export: four shops (two peptide-pattern, two small branded-goods stores), a destination-wedding planner, a Peruvian creator and one unidentifiable site. Their reasons: three "temporary deactivation", two "didn't work", one "it never worked", one "didn't understand how it works". All ran 4.2.2 to 5.9.7.

## 2. What each major segment wanted, and what would have kept them

### Online shops (78 sites, 28%; 7 searched, 71 inferred)

**What they wanted.** A payment method *inside* WooCommerce checkout, not a pay-me page. Over and over the need is: customer places an order, sees Zelle/Cash App/Venmo instructions with the order amount and a reference, says "I have paid", the order moves to on-hold/paid. The high-risk sub-cluster (about 40) wants this because Stripe and PayPal will not board them; the ordinary shops (jerky, jewelry, farm produce, apparel, phone accessories, 3D-printed parts, custom goods) want it to dodge card fees.

**What the reason texts say.**
- "the plugin requires the pro version to do basic things like linking to woocommerce checkout. i cant pay for plugin i dont trust" (biotech/peptide-pattern shop, 5.7.13). This is the clearest statement in the export of why shops left: on every version these sites ran, checkout placement was Pro-only.
- "Forcing me to use Stripe. I dont need." (peptide-pattern shop, 6.2.4). The changelog confirms the 6.0 to 6.3 setup assistant highlighted Stripe Connect as the way to take payments at WooCommerce checkout, and free card payments via Stripe were the only free checkout option before 6.6.0.
- "Not paying for Zelle integration" (peptide-pattern shop, 6.2.4); "I'm not paying $7/mo to accept zelle... there are free plugins for that." (organic farm, 5.5.15)
- "i thought it worked in checkout." (jerky brand, 3.4.9)
- "zelle for woocommerce" named as the better alternative (Spanish-language US shop, 3.3.5)
- "I don't want to edit code" (peptide-pattern shop, 3.4.1)
- "I was looking for something that works with my shop" (general store, 3.4.0)
- "the short codes do not render on the front end. sent email about it" (a search-confirmed peptide shop, 5.5.15)
- "Not working, cannot get to settings." (supplement store, free plan, 3.3.4 on PHP 8.3)
- "Missing specific feature" (peptide shop, 5.9.2)
- The four "did not work" reasons account for **15 of the 78 shops**; 34 of the 78 ran 3.x builds and 28 ran 5.x.
- Shop reasons overall: Temporary deactivation 21, Found a better alternative 16, Expected something else 8, Other 8, the four did-not-work reasons 15.

**What would have kept them.** (a) Zelle free at checkout; 6.5.0 and 6.6.0 now deliver this, but no site in the export ever ran either version, so nobody has yet been retained by it. (b) A free method at checkout at all: this is what the explicit complaints asked for, and 6.6.0 is the first build that has it. Several methods at once with per-order reference and a confirm-payment step remains the Pro "grouped checkout" upsell. (c) No Stripe prompt in the path to a P2P checkout; one owner left over it and the onboarding copy of the time did push Stripe Connect. (d) Compatibility across old stacks: most of the "didn't work" shop reports are on 3.x and 5.x plugin builds, not on exotic PHP (only 4 of all 51 did-not-work reports are on PHP 7.4).

**Sceptical note.** 14 of the shops are outside the US by the export (15 if the Kenyan-domain store flagged as US is counted) and would not be served by any amount of checkout work. And a chunk of the high-risk cluster (grey pharmacies, replica goods, synthetic cannabinoids, lottery) are merchants the P2P networks themselves ban; building for them invites account terminations and chargeback complaints that land on the plugin's reputation. The peptide/supplement sub-segment is the part worth serving deliberately.

### Nonprofits, churches, mosques, unions and lodges (30 sites, 11%; 0 searched, all inferred)

**What they wanted.** A Give/Donate page with QR codes for Zelle, Cash App, Venmo and PayPal, suggested amounts, fund designation (general, missions, building, zakat), optional recurring giving, and some record of who gave for thank-yous and year-end statements. 21 of the 30 are US-based, 5 have no country, 4 are flagged non-US (one of them with a Philadelphia name). Several are Spanish-, Telugu- or Afghan/Persian-speaking congregations and a Spanish-language radio ministry, where Zelle is the dominant habit. 19 of the 30 ran 3.x builds.

**What the reason texts say.**
- "Found a better alternative: Paypal API" (a lineage-society chapter; the treasurer wanted records, not a link to reconcile by hand). This is the only nonprofit that said "found a better alternative".
- Two separate organisations said the **Zelle QR code did not work**; one explicitly tried to scan it with the Zelle app ("The QR code generated is not a Zelle QR code when I tried to scan it with my Zelle account", 3.4.1). This is the most specific defect in the whole export.
- "Didn't understand how it works" from a monastery, a Telugu-speaking congregation and an eco/community farm nonprofit.
- "Expected something else" from a union local, a memorial fund and a 12-step area assembly (the first two read as wanting a donation form).
- Plugin "broke the website" on a ministry site running PHP 7.4 and plugin 3.4.1; "Didn't work" from a church-style nonprofit and a mosque; "Didn't work after installation" from an Indonesian and a Lithuanian religious site, where no free method works anyway.
- "No longer needed" from **seven** organisations (the most common nonprofit reason, tied with "temporary deactivation" at 7), consistent with one-off fundraisers ending.
- "Missing specific feature" from a community church.

**What would have kept them.** A donation block in the free tier (amount buttons, fund selector, donor name, thank-you page) rather than a bare pay-me card; a Zelle QR that the Zelle app actually recognises, with a clear note where Zelle's own QR format cannot be reproduced; a two-minute "church giving page" onboarding path. Donations are currently a Pro feature, which is exactly the feature this segment left over.

### Freelancers, creators, service providers, coaches (71 sites combined: 20 + 20 + 12 + 8 health/beauty + 11 blog/personal, 25%; 4 searched)

**What they wanted.** The simplest use case the plugin is designed for: a "pay me / tip me / pay my invoice" link or QR for deposits, lesson fees, session balances, book sales at events, tips at a DJ booth or salon chair. 48 of the 71 are US, mostly no store. Reasons: Temporary deactivation 25, Found a better alternative 8, Other 7, Expected something else 6, Expected to work differently 5, the four did-not-work reasons 13, Didn't understand 3, No longer needed 3.

**What the reason texts say.**
- One confirmed author liked the plugin but dropped selling because of **sales-tax obligations**; the plugin was not the problem.
- "Additional form fields" (a coaching practice wanted name/service/notes captured with the payment)
- A fitness-challenge site disliked the **large QR display** and wanted a compact option inline beneath the card option at checkout
- "I can't get the payment gateway to show in Woocommerce" (a family creative studio, 5.5.15)
- "it never worked" (a Pro customer, destination-wedding planner, 5.7.1)
- "doesn't work AT ALL" (a dance school's local dev install, 5.5.15)
- "Found a better alternative" from a racing author, a creative studio, a video channel, a money blog, a UK beautician, a coaching program, a Cameroon auto business (likely invoicing or creator-tipping tools, or local rails)
- "Didn't understand how it works" from a creator, a karate dojo, a South African counselling practice; also a home baker (classed under food) and an IT/consulting firm (classed under agencies)
- "Didn't work" / "broke the website" / "suddenly stopped working" from a piano teacher (4.2.0), an events DJ/planner (4.2.0), a personal site on shared PHP 8.1 (3.4.1), two blogs (4.1.8 and 5.6.0), a travel brand, a VIP driver, a stove retailer, a tour operator
- "No need" / "No longer needed" from a sewist, a creative studio, a genealogist: they invoice in person and saw no reason for a plugin
- One blogger called the plugin a "scam" (5.6.0 on PHP 8.5), which on a tip-jar site means it visibly did not render on first try

**What would have kept them.** A pay page that renders without configuration within a minute of activation, a payer note/amount field, and a compact inline style. This segment is the natural fit for the free product and churns mostly on comprehension and first-run failure, not on missing features. Note that contractors and large-ticket service providers (pool builders, stove installers, car dealers) read as wanting invoicing with records, which a pay link will never be; expect them to leave regardless.

### Event and community groups (14 sites, 5%; 1 searched)

**What they wanted.** Dues, event registration, reunion tickets, wedding cash gifts, team fees, with a list of who paid. 10 of 14 are US. Reasons were "found a better alternative" (a search-confirmed RC aircraft club, probably a club-management tool), "Zelle was not a free option" (a gun club, 5.5.15), "expected to work differently" (a women's business collective on a staging host, and a Pakistani golf association), "suddenly stopped working" (an alumni site on WP Engine staging), "broke the site" (an anniversary gala on 4.2.0, a kids' party business on 4.1.1), "didn't work" (a riders' club, 3.4.9), and one "I don't remember even installing this". Free Zelle plus a payer list would have retained the US ones; memberships are a Pro feature they never reached.

### Agencies, developers, test installs (42 sites, 15%)

Covered in section 5. Reasons are dominated by "temporary deactivation" (18 of 42) and "didn't work after installation" (5). Their one actionable signal: the plugin must activate cleanly on local, staging and free hosts, must not depend on an activation email ("Never got an email for the code needed to run" on a GoDaddy staging host, 3.3.5), and must survive migration to the live domain.

### Other and unknown (42 sites, 15%)

Political campaign (needs donor compliance fields), community media, crypto/forex (needs crypto addresses, high-risk), lottery and adult directories (not customers to pursue), one "accidentely installed", plus 21 sites nobody can describe. Nothing here changes the product plan.

### Segments the first draft missed

- **Chinese-language US shops (3, plus one French-hosted zh-CN site).** Simplified-Chinese admin locales running US stores; the notes name Alipay/WeChat Pay alongside Zelle. One left "didn't understand how it works", one "didn't work after installation", one is a replica-watch shop to ignore.
- **Latin American diaspora shops where Zelle is the primary rail.** A Venezuela-facing perfume store, a Spanish-language US pharmacy, a Spanish-language general store that left for "zelle for woocommerce", a Vietnamese-language family store. For these, Zelle at checkout in their language is the whole product.
- **Counter and in-person use.** An optician's "pay your balance", a spa, a salon chair, a car lot, a farm-stand honor box, a lodge bar, a photo-booth: about a dozen sites read as wanting a printable or on-screen QR for in-person payment, not a web page. Nothing in the gap table covers "print this QR".
- **Subscription and recurring sellers** (IPTV, forex signals, trading education, a SaaS startup, a yield-investment brand): a pay link cannot bill monthly; these were never a fit and should not be counted as recoverable.
- **Large-ticket deposits and balances** (golf carts, massage chairs, stoves, vehicles, pool builds, wedding planning): they need an amount-plus-reference invoice flow, which overlaps with the "payer fields" need but is really invoicing.
- **Pre-install expectation setting.** "Not available in my location", "I can't find my currency", "I don't remember even installing this", "accidentely installed", plus 80 non-US sites: a country/currency check before or at activation would have saved these installs from counting as churn at all.

## 3. Gap table: need versus the free plugin (6.7.0 on the 6.3.11 base)

Free plugin today: Cash App, Venmo, PayPal.me, Zelle; one method at WooCommerce checkout; no-code page; tip jar; first-week emails; setup check. No site in the export ran any of 6.4 to 6.7.

| Need (segment, approximate sites) | Free plugin meets it |
|---|---|
| Simple pay-me / tip-me link or QR on a page (creators, services, blogs, ~70) | **Fully**, provided it renders on first try |
| Zelle at no charge alongside Cash App and Venmo (shops, clubs, nonprofits; 4 explicit complaints) | **Fully** since 6.5.0; every one of these sites churned before that |
| One P2P method at WooCommerce checkout (ordinary shops, ~38) | **Fully** since 6.6.0; none of the 78 shops ever ran it. On their builds checkout placement was Pro-only, and the explicit complaints ("requires the pro version to do basic things like linking to woocommerce checkout", "can't get the payment gateway to show", "i thought it worked in checkout") are about that |
| Several methods at checkout with per-order reference and "I have paid" confirmation (high-risk shops, ~40) | **Partly**: one method only; grouped checkout and automatic verification are Pro |
| No Stripe or card dependency anywhere in setup (peptide shops) | **Partly**: one owner left over "Forcing me to use Stripe"; the 6.0 to 6.3 setup assistant did highlight Stripe Connect for checkout, so the onboarding copy is worth auditing |
| Donation page with amounts, fund selector, donor name, thank-you (nonprofits, ~30) | **Not at all** in free; donations are Pro |
| Zelle QR that the Zelle app scans (2 explicit failures, plus every Zelle user) | **Not at all** as perceived by users; 6.5.0 lets the owner upload the bank's own Zelle QR, which addresses it only if the owner knows to do so |
| Payer name/note/amount fields on the pay form (coaching, clubs, campaigns) | **Not at all** in free; WPForms fields are Pro |
| Recurring giving or memberships (churches, dojos, clubs; dues or recurring named in ~29 notes) | **Not at all** in free; memberships are Pro |
| Records of who paid, exportable for a treasurer (clubs, nonprofits, unions) | **Not at all**; by design a P2P link does not report back |
| Compact inline method display instead of a large QR (checkout sites, 1 explicit complaint) | **Not at all** as reported |
| Printable or counter QR for in-person payment (opticians, salons, lots, farm stands, ~12) | **Partly**; the QR exists but nothing in the data shows a print or display path |
| Non-US methods: UPI, QRIS, PIX, Bizum, SEPA, iDEAL, M-Pesa, Mobile Money, Yape, TWINT (80 non-US sites) | **Not at all**; only PayPal.me works outside the US |
| Non-English UI: Spanish (14 sites, 6 of them US), Simplified Chinese (4, 3 US), plus fr-FR, pt-BR, vi, fa-IR, tr, hu, lt-LT, id, it-IT | **Not at all** as far as the data shows |
| Stability on PHP 7.4 through 8.5 and inside Divi, LMS and booking stacks (51 did-not-work reports: 28 on 3.x, 8 on 4.x, 12 on 5.x, 3 on 6.x; only 4 on PHP 7.4) | **Partly**; unknown how many were fixed after the versions those sites ran, but the 3.x concentration suggests most were |
| Activation without an emailed code on staging hosts (agencies, 1 explicit) | **Not at all** as reported |
| Country/currency check before install ("not available in my location", "can't find my currency", 80 non-US) | **Partly** since 6.5.0 country-aware methods; none of these sites saw it |

## 4. The three product moves that would retain the most

1. **Make WooCommerce checkout the free product's centre, and make it bulletproof.** Behind it: 78 shops (28%), of which about 40 are high-risk merchants who have nowhere else to go, plus 18 other sites flagged as WooCommerce-likely. The free one-method gateway (6.6.0) and free Zelle (6.5.0) exist now but none of the 280 ever saw them, so the first job is telling people: the listing, the upgrade notice, and a win-back note to the 7 Pro churns and the shops that left "temporarily" (21). Then: a per-order reference and amount on the instruction screen, an "I have paid" button that sets the order on-hold, and a hard audit for any Stripe-first wording in onboarding. Keep multi-method grouped checkout as the Pro upsell; it is the first thing these shops ask for after the free version works. Expect to lose the 14 non-US shops and the outright-banned merchants regardless.

2. **Ship a basic donation block in the free tier and fix (or explain) the Zelle QR.** Behind it: 30 nonprofits plus 14 clubs and community groups and a handful of campaign/media sites, roughly 45 sites (16%). Amount buttons, fund selector, donor name, thank-you page. Two organisations left specifically because the Zelle QR did not scan in the Zelle app; the 6.5.0 "upload your bank's QR" option answers this only if the page says plainly that a generated QR is not a Zelle QR and shows the Zelle email/phone prominently. Keep recurring giving and giving records as Pro; that is where the PayPal-API defector went.

3. **Get the first-run to a rendered pay page in under two minutes, with payer fields.** Behind it: the 71 creator/service/coaching/blog sites (25%) plus the 42 agency and test installs that judge the plugin in one sitting. Seventeen uninstalls say "didn't understand how it works" and 51 say it did not work, broke the site or stopped working; one called it a scam, one said shortcodes did not render, one could not reach settings. A setup check that fails loudly on a missing handle, a default page created on activation, a compact inline display option, a printable QR, and a free name/note field would address the stated complaints. This move also lifts every other segment, since nonprofits and shops gave the same comprehension reasons.

A fourth move, non-US methods, has a larger headcount (80 sites, 29%) but is a different product: each country needs a different rail and a different language, and none of the plugin's current brand promise (Cash App, Venmo, Zelle) applies. If pursued at all, UPI (20 Indian sites, though 6 are agency/test installs and 6 are "other") and Spanish-language UI (14 sites, but only 6 of them in the US where Zelle works) are the only sub-pieces with any mass, and neither is large.

## 5. Developer, agency, staging and localhost installs to exclude from churn maths

- **42 sites (15%)** are classified as agency, developer or test installs. Of these, **32** are on throwaway hosts (`.local`, `.test`, `localhost`, Hostinger/GoDaddy/WP Engine/Kinsta/InstaWP/EasyWP/Easypanel/HostArmada staging names, free-hosting subdomains, agency client subdomains); **10** are real agency or dev-studio domains evaluating the plugin. The line between the two is a judgement (a client build on an agency's own host could go either way).
- A further **11 sites** classified as real organisations are also on staging, local or free-hosting names (an alumni site, a dance school's local install, a fraternity chapter, a women's collective, a DJ, two Hostinger temporaries, a Lebanese marketplace on free hosting, three free-hosting hobby or blog sites).
- So **43 sites (15%) are staging/local/throwaway hosts by hostname**, and **53 sites (19%)** is the broader set of agency, developer and staging installs. Remove them and the churn base is about **227 real sites**, of which 148 are US, 53 are non-US and 26 have no country.
- Among the real sites, 19 are "unknown", a further 20 are "other", and roughly 10 are categories the plugin should not serve (lottery, adult, grey pharmacy, replica goods, subscription sellers). A fair denominator for "customers we could have kept" is nearer **190 to 200 sites**, and only about 12 of those were ever confirmed by search.

## What the data cannot tell you

- Whether any of these sites reinstalled later; **90 (32%)** said "temporary deactivation" and the export cannot distinguish a pause from a loss. That is the single largest reason, and it is uninformative.
- What the 13 confirmed sites look like today, or whether the 241 inferred ones are what their names suggest. A domain with "labs" in it was counted as a shop; it could be a dev studio. Two of the 27 peptide-pattern names rest on "lab" alone.
- Why "found a better alternative" (33 sites, 16 of them shops) was better: only two named it (the PayPal API; "zelle for woocommerce"). The rest could be a competitor gateway, an invoicing tool, a local rail, or giving up on online payments altogether.
- Which complaints are already fixed: two-thirds of all uninstalls (184 of 280) ran 3.x or 4.x builds, and 36 of the 51 did-not-work reports are on them. The export cannot show whether 5.x and 6.x fixed those failures, only that 15 did-not-work reports still arrived on 5.x and 6.x.
- Anything about the installs that stayed. Without the retained base, none of the shares above can be turned into a churn rate by segment.
- Whether the high-risk shops represent revenue worth chasing or a liability; that is a business judgement the data cannot make.
- Whether the 6.5.0 and 6.6.0 changes retain anyone: every site here left before they shipped, so this export is a baseline, not a measurement of them.

Working files: `/tmp/claude-0/-home-user-wallet-up/fd5e39c7-41c4-5b51-8c0a-c79cf509220e/scratchpad/site-research/batch-0.json` through `batch-13.json` (per-site classifications, three different JSON layouts), merged with the export fields into `/tmp/claude-0/-home-user-wallet-up/fd5e39c7-41c4-5b51-8c0a-c79cf509220e/scratchpad/all_sites.json` by `/tmp/claude-0/-home-user-wallet-up/fd5e39c7-41c4-5b51-8c0a-c79cf509220e/scratchpad/load_sites.py` (customer data, not for the repository).

## What was corrected

1. **Search coverage.** The draft said the budget ran out "before the last ~40 sites". The batch notes show no search ran for batches 6 through 13: 160 sites (57%) are name-only. All 13 "searched" sites are in batches 0 to 5.
2. **Reason counts** were understated throughout. Temporary deactivation is 90, not 25; Found a better alternative is 33, not 18; Didn't understand is 17, not 13; the four did-not-work reasons total 51, not "twenty-odd" or "about 25". Two sites named the better alternative, not one.
3. **Pro churn** is 7 sites, not "about 11", and not "mostly peptide shops": 2 peptide-pattern, 2 branded goods, 1 wedding planner, 1 Peruvian creator, 1 unknown. The "Pro customer who could not reach settings on PHP 8.3" was a free user on 3.3.4.
4. **Version timing.** All 280 ran 3.3.0 to 6.3.10; 66% ran 3.x/4.x. "Most of these churned before 6.5.0" is now "all of them", and the claim that shops "tried the free single-method gateway and found it wanting" was dropped: that gateway arrived in 6.6.0, after every uninstall. On their builds checkout placement was Pro-only, which one reason text states outright. Added the changelog fact that the 6.0 to 6.3 setup assistant pushed Stripe Connect, which explains the Stripe complaint.
5. **Creator block arithmetic.** 20+20+12+8+11 is 71 (25%), not 72 (26%). The "photography brand" did not say "found a better alternative" (it said temporary deactivation); the "church" among the didn't-understand trio is an eco-farm nonprofit; the "home baker" and "consulting firm" are classified under food and agencies respectively. Added omitted reason texts: "can't get the payment gateway to show in Woocommerce", "short codes do not render on the front end" (from a search-confirmed shop), "doesn't work AT ALL", and the Pro "it never worked".
6. **Nonprofits.** "No longer needed" came from 7 organisations, not 3 (it is the joint top reason). Added that 19 of 30 ran 3.x builds and that the "found a better alternative" count in this segment is exactly one.
7. **Shops.** Did-not-work reports from shops are 15, not "at least six". High-risk count given as a range (38 to 43) with the softer inclusions named; 4 of the cluster are non-US. Non-US shops are 14 by export (15 with the Kenyan-domain site), not a flat 15.
8. **Legacy stacks.** Only 4 of the 51 did-not-work reports are on PHP 7.4, not "a quarter"; the concentration is on old plugin builds (28 of 51 on 3.x), not old PHP.
9. **Languages and countries.** Spanish is 14 sites, not "at least 12", and only 6 are in the US, so "mostly US Hispanic businesses" was wrong. India is 20 sites with UPI named for 18, not 14. Simplified-Chinese sites (4) and the five contradictory country fields were missing. Added the export date range (Dec 2023 to Nov 2026).
10. **Section 5 counts.** Throwaway-host agency installs are 32 (not 31), real agency domains 10 (not 11), real organisations on throwaway hosts 11 (not 10), total by hostname 43 (not 41), broader set 53 (not 52), remaining 227 (not 228) with 148 US / 53 non-US / 26 no country, and 19 unknowns among them (not 21).
11. **Free-text quality.** Added that only 41 sites typed a reason and 10 of those are junk, so quotable complaints come from about 30 sites.
12. **Missed segments and needs** added: Chinese-language US shops, Zelle-first diaspora shops, in-person/printable QR use, subscription sellers (never a fit), large-ticket deposit invoicing, pre-install location/currency expectation setting, and the "accidental install" pair. Added gap-table rows for printable QR and country check, and corrected the Zelle QR row to reflect the 6.5.0 upload option.
13. **Descriptive wording** that could identify organisations (a named lineage society, a named gun club region) was generalised; no site URLs or names appear in the output.
