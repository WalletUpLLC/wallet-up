# Adversarial product and copy review: Wallet Up free 6.4.0 to 6.7.0

Read against the revamp plan in `FREE-REVAMP.md` and the branch diff. Findings in order of importance; the author's action on each is in `audit/PROOF.md`, section "Adversarial audit".

1. **Switched-off methods still rendered through the legacy shortcodes** (`[cashapp]`, `[venmo]`, `[paypal]`, `[zelle]`), a regression from 6.5.0, because the registry now instantiates methods with a saved username even when off and `processShortcode()` never checked the flag. Fixed.
2. **Free-plan additions anchored on English DOM text**, so Spanish and French admin screens got none of them. Fixed for the three shipped languages; a stable hook in the React source remains the right long-term fix.
3. **Step 3 contradicted itself on WooCommerce sites**: the integration hint still said checkout placement is Pro three lines above the free checkbox. Fixed in en, es and fr.
4. **The notifications button silently registered the install with Freemius.** Fixed: separate, default-off checkbox.
5. **No way to turn notifications off; emails promised a reply-to that was not set.** Fixed: Notifications card under Settings, a link in every email, `Reply-To` when the payer gave an address.
6. **The owner's own page views counted as visitor views**, which fooled every "did anyone see it" signal once "Create page" started landing the owner on the page. Fixed: users who can edit posts are not counted; the banner's copy button only copies.
7. **Cash App listed as working in the United Kingdom.** Fixed: US only.
8. **The 2.5-second mount watchdog fired on ordinary slow loads and poisoned the setup check for a week.** Fixed: failure recorded after 10 seconds or on a script error, cleared by any later mount.
9. **Tap counting and the confirm form died on cached sites** because a 24-hour nonce was baked into the page. Fixed: no nonce on the counter, a page-bound token on the confirm form, and a caching note in the setup check.
10. **Confirmation emails had no ceiling.** Fixed: ten a day, then one more, with a ten-minute dedupe.
11. **"Create page" published a new page on every click; re-running setup silently disabled the checkout gateway.** Fixed: the generated page is refreshed; the wizard reads the free gateway option.
12. **The "page published" row was red for owners who used their own page; the WooCommerce row was green for a gateway that cannot show in a non-USD store.** Fixed: any published post with a Wallet Up block or shortcode counts; the currency rule is applied in the check and in the wizard.
13. **Welcome offered no "not in the US" path; default en_US installs were treated as US.** Fixed: neutral line with a country chooser whenever detection is locale-only or unknown; the preset data is updated after the choice.
14. **Settings cards loaded on one URL only.** The enqueue now covers every Wallet Up screen.
15. **Pro build inherited free-only checks and fallbacks.** Fixed: the visitor and cron rows are not applicable on Pro; the simple-form links are hidden.
16. **The uninstall-survey tag was unreadable and truncated the free-text reason.** Fixed: words in the modal, a compact tag on the wire, multibyte-safe truncation.
17. Plugins-row anchor to the asynchronously mounted card: works via the hash handler (kept).
18. The setup notice audience (`manage_options`) and the Turn-on button were judged acceptable (kept).
19. **Copy batch**: readme sections that still framed checkout placement as Pro, a duplicate Requirements block, "24 payment methods" vs "20", installation steps that predate the setup assistant, shortcode examples and nonprofit copy without Zelle, the FAQ "opens with the amount pre-filled" (untrue for Zelle), "no outgoing connection" overstated, `pageHint2` "You just hit Publish", the Payments header without Zelle, first-tap email wording ("tapped" is not "paid"; Zelle has no link), the digest's zero-count branch when no page exists, "add the Tip Jar block to your footer". All corrected.
20. **First-sixty-seconds budget**: the geo line (abroad or unknown only), the Zelle hint and nothing else precede the first QR. The fallback flash and yellow notice (finding 8) and the contradictory step 3 (finding 3) were the added risks; both fixed. `SetupCheck::checks()` is now memoised per request.

## Plan vs delivery (after fixes)
Every 6.5.0, 6.6.0 and 6.7.0 item in the plan's release schedule is delivered, with these notes: the three bundle edits must be ported to the React source before the next build; the WordPress.org screenshots 2 and 5 still show Pro screens and are labelled as such; the self-check keeps nine rows and folds the caching warning into the "visitors have seen your code" row rather than adding a tenth.
