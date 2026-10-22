# Thai Lottery project vs thailotto.club — competitor gap audit

**Audit date:** 16 October 2026 (Asia/Dhaka)  
**Reference competitor:** https://www.thailotto.club/ and public pages linked from it  
**Scope:** Publicly fetchable competitor pages and corresponding local customer-facing product surfaces; implementation review and targeted automated tests. This is a comparative product audit, not a legal/regulatory certification or a claim of exhaustive browser verification.

## Executive summary

The project already covers many core lottery and account functions, but the competitor emphasizes a more discoverable public funnel: results archives, game/how-to-play information, fees and discounts, verification guidance, membership tiers, referrals/affiliates, and prominent support/contact paths. The project has received targeted additions and fixes for several exposed gaps, including a public discount catalogue, clearer affiliate presentation, contact inquiry onboarding for agents, localized footer navigation, and a locale-safe next-draw cache.

**This audit is not proven 100% complete.** Some competitor routes were unavailable, the agent-registration form could not be inspected, the third-party site can change, and no real-browser end-to-end comparison was performed. Do not treat competitor marketing claims or fee/rate tables as approved local policy.

## Feature-by-feature comparison

| Area | Competitor observation | Local project comparison / action | Status and caveat |
|---|---|---|---|
| Homepage and live draw | Homepage promotes live results, next draw and member benefits. | Local homepage, live draw, countdown, result feed and localized public sections were reviewed; wording/status expectations and locale-scoped next-draw cache corrected. | Targeted tests passed. No real-browser visual verification. |
| Public results and archives | National and weekly lottery pages expose current 2569 results and older-year links. | Local results and history surfaces exist; public result fallbacks and draw data are already part of the project. | Data freshness depends on the configured importer/provider and operational schedule; not verified live here. |
| How to play / FAQ | Competitor links to how-to-play and FAQ, but both returned “File not found.” | No gap inferred from unavailable pages; do not invent their contents. | Not verified. |
| Fees | Competitor publishes renewal/verification, referral/affiliate, transfer, withdrawal, cash-in and agent fee categories; some values are blank. | Local platform has a public, configuration-driven fee schedule. | Do not copy competitor amounts. Local values/policy require operator and legal approval. |
| Discounts / prize ratios | Competitor publishes national/Bangkok discount and payout figures. | A public discount catalogue was added as a discoverability gap. | Rates must be local, approved, and configuration-backed; competitor figures are not local policy. |
| Membership grades | Competitor lists five spend-based tiers and game-specific discounts. | Local account-grade / membership features exist. | Do not import competitor thresholds or discount rates without product approval. |
| Referrals / affiliates | Homepage promotes referral and affiliate bonuses. | Affiliate bonus display was added/reviewed in local public/member surfaces. | Competitor claims such as “instant payout” or “no commission on winning” were not copied or endorsed. Local rules need approval. |
| Account verification | Competitor asks for account details, status/renewal, phone, document type and front/back document submission. | Local verification separates signup from document verification and supports KYC flows. | Avoid copying unnecessary fields or collecting more personal data than the local policy requires. Verify retention, access control and consent before launch. |
| Prize verification | Competitor describes ticket/barcode authenticity and paper features, including unsafe chemical-testing advice. | Local prize-verification guidance and UI were reviewed. | Do not reproduce chemical tests or hazardous handling instructions; rely on safe, authoritative verification channels. |
| Sign-in and recovery | Competitor has password login, remember-me and recovery. Social labels extracted to `#` rather than real provider links. | Local password login and recovery exist; no fake social sign-in controls were added. | Social login remains an explicit integration gap unless a real provider flow is implemented. |
| Registration | Competitor signup asks for referral ID, mobile, address/location, DOB, nationality, email, IC number and WeChat ID. | Local registration separates verification and does not copy IC/WeChat fields into signup. | This is a deliberate data-minimization choice, not a missing-feature defect. |
| Agent onboarding | A fresh search-index listing for the public agent form describes first/last name, gender, city/country, phone, email, document type and front/back images, face image, DOB, nationality, password confirmation, six-digit PIN and terms acceptance. Direct page fetch still redirects to the competitor homepage, so this is indexed listing evidence, not a verified live submission flow. | Local active-agent onboarding remains operator-controlled. Added a localized Agent and Affiliate Inquiries card on Services linking to the public contact form; it explicitly says inquiry is not an application, does not create an agent account or guarantee commission, and warns against sending identity documents, passwords or PINs through the form. | Do not copy the competitor's sensitive-document collection or create public agents without approved security, privacy, risk and compliance policy. The full competitor flow remains unverified. |
| Contact and support | Competitor publishes contact/about/vision content. | Local contact and corporate information surfaces exist; contact inquiry CTA improves agent lead routing. | Validate response operations and contact destinations before production. |
| Localization and navigation | Competitor public experience is primarily Thai. | Local surfaces support Thai, English and Bangla; localized footer navigation was added and translation entries validated. | Human review by native speakers remains advisable for all high-impact/legal/payment wording. |
| Payment-status communication | Competitor promotes payment/withdrawal claims. | Local callback copy was revised to describe recorded status and avoid guaranteeing withdrawal availability; view translation syntax corrected. | Payment correctness still depends on verified provider callbacks and operational controls, not UI copy alone. |

## Specific competitor pages inspected

- `https://www.thailotto.club/` — homepage claims: referral/affiliate bonuses, “instant payout,” “no commission on winning,” live results and membership/engagement claims. These are treated as competitor marketing, not verified facts or local commitments.
- `/fees.php` — public fee categories; some PayPal amounts blank. No amounts copied.
- `/discount.php` — national/Bangkok discount and payout rates. No rates copied.
- `/account-verify.php` — account details, renewal/status, phone, document type and front/back document submission.
- `/account-grade.php` — five spend tiers and game-specific discounts. No thresholds copied.
- `/prize-verify.php` — paper/barcode guidance, including advice that should not be reproduced due to safety concerns.
- `/national-lottery.php`, `/weekly-lottery.php` — current and historic result archives observed.
- `/login.php` — password login, remember-me and recovery; social labels resolved to `#` in extracted page links.
- `https://myaccount.thailotto.club/members/register.php?ref=` — signup fields include referral ID, mobile, address/location, DOB, nationality, email, IC number and WeChat ID.
- `https://agent.thailotto.club/agent/register.php` — direct inspection redirected to the competitor homepage. A search-index listing (retrieved 17 Oct 2026) describes first/last name, gender, city/country, phone, email, document type and front/back images, face image, DOB, nationality, password confirmation, six-digit PIN and terms acceptance. Treat this as indexed evidence only; the form itself was not confirmed.
- `/forget-password.php`, `/how-to-play.php`, `/faq.php` — returned “File not found”; no requirements inferred from them.

## Validation completed

- Relevant home/public-service/contract test subset: **84 passed, 1,734 assertions** (earlier focused run); the three directly relevant home/contract/public-service files were re-run after later edits: **59 passed, 489 assertions**.
- Payment callback and CAPTCHA-focused tests after fixing the English translation array and refreshing the CAPTCHA fixture: **113 passed, 340 assertions**.
- `npm run build`: **passed in the preceding runtime** (Vite production build). A rerun in the current workspace could not start because the local `vite` executable/node_modules are absent.
- Follow-up Agent/Affiliate Inquiry card was added to the Services landing page with EN/TH/BN copy and regression assertions. Static checks confirm all 35 view translation keys resolve, catalog keys match across locales, the contact route is defined, and the view does not collect files/passwords/PINs. A further review found Bangla Terms/Privacy body text falls back to English; localized disclosure notices were added to both pages and regression assertions were added. The notice keys occur once per locale, and each view gates its notice on `bn`. Current runtime has no `php`, so these new Blade/PHP assertions could not be executed; run `APP_ENV=testing php artisan test --compact tests/Feature/PublicPages/PublicServicesLandingTest.php tests/Feature/Locale/BanglaPublicPagesTest.php` when PHP is provisioned.
- PHP syntax lint across `app`, `config`, `lang`, `resources/views` and `tests`: **passed** after repair of missing commas in `lang/en/account_services.php`.
- Full suite after these focused fixes: **479 failed, 5 skipped, 2,543 passed (115,682 assertions)**. Thus full-project validation is **not green**. The failure log includes broad pre-existing/incomplete-snapshot issues such as missing `config/bot.php`, missing bot provider/enum symbols, build-contract mismatches and many application test failures; these have not been resolved or all individually triaged. Full log: `/tmp/lotto-full-test.log` (ephemeral test output, not part of the deliverable).
- No GitHub write access was supplied; no remote branch or CI workflow was changed.

## Remaining work / honest limits

1. Inspect and classify all 479 full-suite failures before deciding which represent product defects versus missing files, stale tests, environment assumptions or out-of-scope work.
2. Run browser-based checks across Thai, English and Bangla on desktop/mobile; this environment did not provide that verification.
3. The indexed agent-form listing now describes the personal and identity fields, but the direct page redirects; verify the actual form via an accessible source before treating those fields as confirmed.
4. Obtain owner/counsel-reviewed Bangla Terms and Privacy Policy translations; the new notices disclose that substantive text is currently English. Confirm result-feed schedule, callback/provider configuration, operator-approved fees/discounts/bonuses, local legal requirements, privacy/retention and safe prize-claim instructions with responsible owners.
5. Re-fetch competitor pages before a final launch decision because pages and promotional claims can change.

**Completion statement:** The accessible comparison and targeted gap work are documented and the focused tests pass, but the audit cannot responsibly be called “100% complete” while competitor routes, runtime behavior and 479 suite failures remain unresolved.
