# PROMPT 3 DELIVERY REPORT — Member Auth + Registration + Password Reset + Account Verify

**Date:** 2026-09-28 · **Workspace:** `/home/user/thai-lottery` · **Commit:** `2db3438`
**Final validation:** 1640 passed / 7 skipped / 0 failed / 104,896 assertions
(pre-Prompt-3 baseline: 1558 / 103,963 — **+82 tests, +933 assertions, 0 regressions**)

---

## SECTION 1 — THE EXACT 30 NEW FILES

| # | Path | Class / Function | Responsibility · Why it closes the gap · Tests |
|---|---|---|---|
| 1 | `config/auth_security.php` | config | Canonical auth-security policy: CAPTCHA enablement/TTL/failure budget, login throttling thresholds (same env keys as `security.php` — one number per knob), session policy, password-reset expiry/throttle/revocation, identifier shapes, generic-failure message, lock observation threshold, rule version. Referenced by every auth service. |
| 2 | `config/account_verification.php` | config | Canonical verification policy: document types (= KycDocumentType vocabulary), MIME/size/dimension limits, back-document policy, country-code catalogue, submission/review limits + roles, private storage disk/prefix, retention (explicit, never silent). |
| 3 | `app/Enums/AuthLoginIdentifier.php` | enum | "Account ID or Email" taxonomy: digits → AccountId, email shape → Email, else Username; normalization + candidate credential fields. Pure shape classification — never an existence oracle. Tested via login matrix (id/email/username/generic-failure). |
| 4 | `app/Enums/AccountVerificationStatus.php` | enum | The explicit state machine: NotSubmitted/Pending/UnderReview/Approved/Rejected/Expired. Storage values MIRROR the canonical KYC machine (`verified`, not a second word). `transitions()`, `canTransitionTo()`, `isTerminal()`, `isOpen()`, `publicWord()`, `fromKycStatus()`. Tested: illegal-transition, terminal-immutability, retry. |
| 5 | `app/Enums/VerificationDocumentType.php` | enum | Document categories mapped 1:1 to KycDocumentType + `configured()` (only config-accepted types). Tested: unsupported type → 422. |
| 6 | `app/Services/Auth/LoginService.php` | `attempt()` | Server-authoritative login: CAPTCHA gate → identifier resolution → credential attempt → status enforcement → session regeneration → audit → throttle-window reset. ONE generic pre-credential failure. Tests: #1–15 of matrix. |
| 7 | `app/Services/Auth/CaptchaService.php` | `issue()/verify()` | Server-authoritative CAPTCHA: arithmetic challenge, sha256-hashed answer in session (never plaintext), 48-char token, TTL expiry, one-time consumption in every outcome (replay resistance), failure counting + cool-down, config driver. Zero logging. Tests: required/verified/invalid/expired/replay/boolean-never-proof/no-log. |
| 8 | `app/Services/Auth/PasswordResetService.php` | `requestReset()/resetPassword()` | Recovery on the framework broker (hashed tokens at rest, expiry, single-use). Generic response for every identifier outcome; session + remember-token revocation after reset. Tests: #31–43. |
| 9 | `app/Services/Auth/RegistrationService.php` | `register()` | Transactional registration: referral via the EXISTING AgentReferralService (existence/eligibility/self-referral), mobile normalization, Hash password, terms timestamp+version in preferences, benchmark personal/birth fields, wallet provisioning preserved exactly as the pre-existing explicit side effect. Tests: #16–30. |
| 10 | `app/Services/Verification/AccountVerificationService.php` | `submit()/review()/markUnderReview()/historyFor()` | The canonical workflow: delegates account summary/status/documents to the EXISTING facade; adds the immutable submission-event aggregate (reference, country/mobile pair, document pair, fingerprint, rule version) and the policy-guarded decision path mirroring onto canonical KYC documents. Tests: FlowTest suite. |
| 11 | `app/Services/Verification/DocumentStorageService.php` | `store()/readForAuthorized()` | Private-disk storage abstraction: content-MIME sniffing (finfo, not client headers), extension derived from sniffed content, size ceiling, image corruption guard, server-generated `kyc_{id}_{32 random}.{ext}` key, read-back integrity check, authorization-gated retrieval. Tests: private-storage, forged-MIME, traversal, dimensions. |
| 12 | `app/Rules/AccountIdentifierRule.php` | `validate()` | Shape-only validation for Account ID/email/username inputs — no DB queries, so validation can never enumerate. Tests: identifier parity + generic failure. |
| 13 | `app/Rules/StrongPasswordRule.php` | `validate()` | Configurable policy (min 8, letters+numbers — the existing contract) + common-password rejection; never logs the value. Tests: weak-password matrix. |
| 14 | `app/Rules/DocumentUploadRule.php` | `validate()` | Upload validation before any byte reaches storage: filename hygiene (traversal, executables, double extensions), extension whitelist, size, content MIME, image dimension sanity + corruption. Tests: oversized/forged/executable/svg/traversal/double-extension. |
| 15 | `app/Models/AccountVerification.php` | model | The submission-event aggregate over the NEW table, keeping the legacy static mapping contract (`publicStatusFromKycStatus`, `publicStatusFromVerification`) for every existing caller; fillable/casts/scopes, member-safe `toPublicArray()`. Tests: aggregate rows + schema guards. |
| 16 | `app/Policies/AccountVerificationPolicy.php` | `view/submit/review/decide` | Self-scope for members; review via the platform's panel-role convention (`isAdmin()` + `AdminAccess::PANEL_ROLES`, super-admin via the existing Gate::before). Tests: IDOR, member-cannot-approve, reviewer flows. |
| 17 | `app/Http/Controllers/Auth/MemberAuthController.php` | 9 actions | Thin orchestration: login/register/forgot/reset/logout page+action endpoints; CAPTCHA issue on renders; no credential/CAPTCHA/state logic inside. Tests: all auth routes. |
| 18 | `app/Http/Controllers/Verification/AccountVerificationController.php` | `show/submit/download/decide` | Thin orchestration over the verification service + policy; service errors land on the established `document` key; reviewer decision route policy-walled. Tests: FlowTest. |
| 19 | `app/Http/Requests/Auth/LoginRequest.php` | `rules()` | Strict login validation (identifier, password, CAPTCHA pair when enabled, remember). Never accepts status/role. |
| 20 | `app/Http/Requests/Auth/RegisterMemberRequest.php` | `rules()` | All 13 benchmark fields mapped to the real schema; unique email/mobile; DOB bounds; terms accepted. |
| 21 | `app/Http/Requests/Auth/ForgotPasswordRequest.php` | `rules()` | "Account No. or email" + CAPTCHA; anti-enumeration decided downstream (service). |
| 22 | `app/Http/Requests/Verification/SubmitAccountVerificationRequest.php` | `rules()` | Country code (catalogue), mobile, document type (configured enum), front (required) + back (per config) with DocumentUploadRule. Status/phone_verified keys never read. |
| 23 | `app/Notifications/AuthPasswordResetNotification.php` | `toMail()` | Localized recovery mail: token only in the action link, expiry stated, no plaintext password, no internal ids, queued. |
| 24 | `database/migrations/2026_09_28_230001_create_account_verifications_table.php` | up/down | The aggregate table (FK types inspected: bigint `foreignId` for users + kyc_documents; indexes on (user_id,status), (status,submitted_at), processed_at, rule_version; UNIQUE verification_reference + fingerprint) PLUS additive nullable users columns (gender, city, country, nationality). Safe rollback. |
| 25 | `resources/views/auth/member-login.blade.php` | view | Benchmark login page: Account ID or Email Address, Password, CAPTCHA (server-rendered challenge), Login, Register, Forgot Password. Accessible (labels, aria-describedby, aria-invalid, role=alert/status), escaped, no-JS. |
| 26 | `resources/views/auth/member-register.blade.php` | view | Benchmark registration: Accounts Information (Referral ID, A.C./Mobile, Password, Confirm) + Personal Details (First/Last, Gender, City, Country, Active Email) + Birth Information (DOB, Nationality) + Terms. |
| 27 | `resources/views/auth/forgot-password.blade.php` | view (dual-state) | Request state: Account No. or email + CAPTCHA + Submit + Back to Login. Reset state (token-gated): new-password form. |
| 28 | `resources/views/account-verification/index.blade.php` | view | Account No./Name/Email/Join/Renew/Status + Verify Now (Country Code, Mobile, Document Type, Front, Back, Submit) + member-safe history + authorized downloads. |
| 29 | `tests/Feature/Auth/MemberAuthParityTest.php` | 50 tests / 262 assertions | The full auth/registration/reset security matrix incl. real solved CAPTCHAs, enumeration resistance, session regeneration, throttling, token security, CSRF structure, localization parity. |
| 30 | `tests/Feature/Verification/AccountVerificationFlowTest.php` | 32 tests / 155 assertions | The full verification matrix: page parity, IDOR, submissions, upload security, private storage, state machine, review authorization, immutable history, en/th rendering. |

---

## SECTION 2 — EXISTING FILES MODIFIED (integration only)

| Path | Exact change | Reason · Preserved logic |
|---|---|---|
| `routes/web.php` | Login/register/logout repointed to `MemberAuthController` (same route names); added password.request/.attempt/.reset/.reset.attempt routes (guest, throttled); verification routes repointed to `Verification\AccountVerificationController`; added policy-walled decision route. | Single authoritative path (AQ). Every existing name/redirect/test keeps resolving; old controllers remain untouched on disk but unrouted. |
| `app/Providers/AuthServiceProvider.php` | Registered `AccountVerification → AccountVerificationPolicy`. | Policy wall for review actions; existing gates untouched. |
| `app/Providers/AppServiceProvider.php` | Added the `password-reset` limiter (identifier-hash + IP dimensions; thresholds read at request time). | Recovery brute-force ceiling; existing limiters untouched. |
| `app/Models/User.php` | Added `sendPasswordResetNotification()` (PROMPT 3 notification); added `date_of_birth/gender/city/country/nationality` to fillable. | Framework broker now emits the localized secure notification; all existing methods preserved. |
| `lang/en|th/public_pages.php` | Added the member-auth key block (en+th, matching key sets). | AH: keys live in the existing namespace — no new lang file. |
| `lang/en|th/account_services.php` | Added the verification-page key block (en+th, matching key sets). | Same namespace the existing verification copy uses. |
| `phpunit.xml` | `AUTH_CAPTCHA_ENABLED=false` for the shared regression env, with an explanatory comment. | The pre-existing login/register tests predate the challenge; the CAPTCHA contract is exercised by dedicated tests that re-enable it at runtime and solve REAL challenges. Production default stays enabled. |
| `.env` / `.env.example` | Added the PROMPT 3 env knobs. | Config parity between environments. |
| `tests/Feature/Account/AccountServicesPagesTest.php` | (a) 14 empty fake upload fixtures upgraded to real GD images — assertions untouched, tests strengthened (they now fail/succeed for their stated reason, not incidentally for empty bytes). (b) The `test_no_second_kyc_table_created` guard updated to the Prompt 3 contract: it now asserts the aggregate table EXISTS **and** that its status vocabulary MIRRORS the canonical KYC machine exactly and the legacy mapping still flows through KycStatus — a stronger anti-parallel-schema guard than before. | Required by the mandated migration + content-sniffing validation; disclosed here per Section AP. No assertion was deleted; the vocabulary guard now catches drift the old one could not. |
| `app/Services/Account/AccountVerificationService.php` | NONE (delegated to, not modified). | — |

**Finance boundary:** zero changes to fees, grades, discount matrix, GLO pricing, result lanes, settlement. The registration wallet provisioning is the pre-existing explicit behavior, byte-for-byte (one active THB wallet at 0.00; verified by `test_registration_creates_no_money`: no commission rows).

---

## SECTION 3 — AUTH PARITY MATRIX

| Surface | Existing implementation | Gap | New implementation | Route | Security control | Tests |
|---|---|---|---|---|---|---|
| Login | `Web\AuthController@login` — username/email + password, throttle:login, status check | No Account-ID dimension, no CAPTCHA, controller-embedded logic | `LoginService` via `MemberAuthController@login` | `POST /login` (`login.attempt`) | CAPTCHA gate; identifier+IP throttle; generic failure; session regeneration; audit | ParityTest #1–15 |
| Registration | `Web\AuthController@register` — name/username/email/phone/password/terms | No referral, no benchmark personal/birth fields, controller-embedded | `RegistrationService` via `register` | `POST /register` (`register.attempt`) | Referral via existing service; unique email/mobile; strong password; terms timestamp; transactional rollback | ParityTest #16–30 |
| Forgot Password | **Did not exist** (no routes, no view) | Complete gap | `PasswordResetService` + dual-state view | `GET/POST /forgot-password`, `GET /reset-password/{token}`, `POST /reset-password` | Broker hashed tokens; expiry; single-use; CAPTCHA; rate limit; generic response; session revocation | ParityTest #31–43 |
| CAPTCHA | **Did not exist** | Complete gap | `CaptchaService` (hashed answers, one-time tokens, TTL, failure budget) | rendered into login + forgot forms | Server-authoritative verify; replay-resistant; never logged | ParityTest (7 captcha tests) |
| Session security | regenerate on login; invalidate on logout (pre-existing) | Unchanged semantics, now asserted | Same conventions via the new controller | login/logout | Regeneration + invalidation + token regen | ParityTest session tests |
| Account Verify | Root controller + facade over KYC | No submission-event history, no aggregate, no reviewer route | `Verification\AccountVerificationController` + aggregate | `/account/verification*` | Policy self-scope; private storage; explicit state machine | FlowTest (32) |

---

## SECTION 4 — REGISTRATION PARITY MATRIX

| Benchmark field | Old state | New state | Actual DB field | Validation | Test |
|---|---|---|---|---|---|
| Referral ID | not collected | required, server-resolved | `agents.agent_code` → `preferences.referred_by_agent_id` (existing referral infra) | required, 3–64; AgentReferralService rejects unknown/suspended/self | missing/unknown/suspended/self |
| A.C. / Mobile | collected (free) | required, normalized digits | `users.phone` | regex + unique | duplicate/invalid |
| Password | collected | required, strong | `users.password` (Hash) | StrongPasswordRule | weak ×3 |
| Confirm Password | collected | required, same | — | `same:password` | mismatch |
| First Name | single `name` field | required, letters | `users.name` ("First Last") | regex | parity |
| Last Name | — " | required, letters | — " | regex | parity |
| Gender | not collected | required | `users.gender` (new column) | in:{male,female,unspecified} | parity |
| City | not collected | required | `users.city` (new column) | regex | invalid |
| Country | not collected | required | `users.country` (new column) | regex (format — no invented catalogue) | invalid |
| Active Email | collected | required, lowercased, unique (case-insensitive) | `users.email` | email:filter + unique | duplicate + case-insensitive |
| Date of Birth | column existed, never collected | required | `users.date_of_birth` | date, after 1900, 18+ | invalid ×2 |
| Nationality | not collected | required | `users.nationality` (new column) | regex | invalid |
| Terms acceptance | checkbox only | timestamped + versioned | `preferences.terms_accepted_at` + `terms_version` | required, accepted | not-accepted |

---

## SECTION 5 — ACCOUNT VERIFY PARITY MATRIX

| Benchmark element | Old state | New state | Public/Private | Authorization | Test |
|---|---|---|---|---|---|
| Account No. | shown | shown (owner's own) | own data | session user | parity + IDOR |
| Name | shown | shown | own | session user | parity |
| Email Address | shown | shown | own | session user | parity + no-other-users |
| Join Date | shown | shown | own | session user | parity |
| Renew Date | shown (NOT_CONFIGURED fallback) | unchanged | own | session user | parity |
| Status | shown (public word) | shown + aggregate status | own | session user | parity |
| Verify Now | form present | section + form (no-JS) | own | session user | parity |
| Country Code | free-form | controlled catalogue select | own | session user | unsupported rejected |
| Mobile Number | free-form | structured pair, normalized | own (`users.phone`, never verified) | session user | invalid rejected; phone_verified stays null |
| Document Type | config list | config list (= KycDocumentType) | own | session user | unsupported → 422 |
| Front Document | required | required + hardened rule | private storage | owner/reviewer only | required/MIME/etc. |
| Back Document | optional | optional per config (require toggle) | private storage | owner/reviewer only | both policies tested |
| Submit | present | present, throttled | own | session user + policy | duplicate deterministic |

---

## SECTION 6 — SECURITY VERIFICATION

- **Credential brute-force:** `throttle:login` (identifier-hash + IP, 5/15min default) — verified 429 on the 6th attempt; CAPTCHA success does NOT bypass it.
- **CAPTCHA:** server-authoritative; answers stored only as sha256; tokens one-time in every outcome; TTL; failure budget + cool-down; `captcha=true` never accepted; verified end-to-end on REAL rendered challenges; zero logging (log-scan + source-scan tests).
- **Session fixation:** session id regenerated on login (asserted ≠); logout invalidates + regenerates token.
- **Password hashing:** bcrypt via the platform hasher; plaintext-never-stored and never-reflected tests; hash never appears in pages/logs.
- **Reset-token security:** broker tokens hashed at rest (asserted ≠ raw), expiring (time-travel test), single-use (reuse rejected), unguessable (guess-list rejected), revoked sessions + remember-token on success; old password stops working; token never logged.
- **Account enumeration:** login — one generic message, byte-identical for known/unknown identifiers; reset — identical response + timing-neutral single path; verification — self-scope with 404s.
- **CSRF:** all browser POST routes in the web group; `ValidateCsrfToken` asserted present in the group; every form carries `@csrf` (structural test; the framework skips CSRF only under `runningUnitTests()`).
- **IDOR:** `?user_id=999` ignored; forged `user_id`/`account_id` in POST never attributes; another user's document → 404; unauthenticated → login redirect.
- **Upload MIME:** finfo content sniffing at validation AND storage (+ read-back verification); client headers/names never trusted.
- **Path traversal:** filename pattern rejection + server-generated object keys (`kyc_{id}_{32 rand}.{ext}`); traversal fixtures rejected.
- **Private storage:** private disk only, no public URL surface, `X-Content-Type-Options: nosniff` on downloads, no storage paths/disk names/object keys on any page.
- **Sensitive-log scan:** new auth/verification code contains zero `Log::` calls (only `AuditLog::create` records of who/when/what-reference); no passwords, hashes, CAPTCHA answers, tokens or session ids in logs (behavioral test).
- **Secret-leak scan:** error pages never reflect passwords/hashes/remember tokens; notification contains no plaintext password.

---

## SECTION 7 — TEST RESULTS

| Suite | Result |
|---|---|
| `MemberAuthParityTest` (new, 50 tests) | **50 passed / 262 assertions** |
| `AccountVerificationFlowTest` (new, 32 tests) | **32 passed / 155 assertions** |
| `AccountServicesPagesTest` (existing KYC surface, 51) | 51 passed (fixtures strengthened, guard strengthened) |
| `PlayerExperienceComprehensiveTest` (existing auth) | passed (login/suspended/logout contract preserved) |
| Prompt-1 suites (fees/parity/reconciliation) | passed |
| Prompt-2 suites (grades, matrix, rebuild command) | passed |
| Security/KYC/audit suites | passed |
| **FULL SUITE** | **1640 passed / 7 skipped / 0 failed / 104,896 assertions** |

---

## SECTION 8 — FINAL COUNTS

- **NEW FILES: 30/30** (verified present, real production code, `php -l` clean)
- **SKIPPED: 0**
- **NEW TODOs: 0** (scan of all 30 files: zero TODO/FIXME markers)
- **FAKE AUTH/VERIFY PRODUCTION FEATURES: 0** (test-only config toggles; CAPTCHA verified on real challenges; no boolean bypass; production default = enabled)
- **REGRESSIONS: 0** (1558→1640 passing; 0 removed tests; 0 weakened assertions; two fixture/guard upgrades disclosed in Section 2)
- **Finance/grade/matrix/GLO/result lanes: unchanged** (re-verified by the full Prompt-1/2 suites in the run)

**Operational note:** vendor/ is archived as `vendor.tar.gz` (34 MB); extract before running tests, delete before turn end (workspace snapshot file cap). PHP needs `php8.4-cli php8.4-mbstring php8.4-xml php8.4-sqlite3 php8.4-bcmath php8.4-curl php8.4-zip php8.4-intl php8.4-gd`; `npm run build` regenerates `public/build`.

---

## 9. Post-Delivery Re-Verification (2026-09-28, second pass)

A full completeness re-audit was run against the 30-file manifest and the 65-item
security matrix:

| Check | Result |
|---|---|
| File existence (30/30 manifest paths) | ✅ all present, no zero-byte/stub files |
| Truncation & placeholder scan (`# ... existing code`, `// ...`, TODO/FIXME) | ✅ 28 raw hits — all false positives (Tailwind `placeholder-*` classes, `placeholder=` HTML attributes, promoted-constructor `{}` bodies each carrying real DI parameters) |
| `php -l` syntax lint — 34 files (30 new + routes/web.php + 2 providers + User.php + 4 lang) | ✅ zero errors (PHP 8.4.26) |
| Blade structural balance (@extends / @section / @endsection, @if/@endif, @foreach/@endforeach) | ✅ balanced; inline `@section('title', …)` forms verified individually |
| Translation-key reference audit (every `__()`/`trans()` reference in the 30 files resolves in BOTH `lang/en` and `lang/th`) | ⚠️→✅ **2 real defects found & fixed** (below) |
| Dynamic-key families (`register_gender_*` ×3, `verification_document_type_*` ×6, `{page}_meta_title/description` ×4) | ✅ complete after fix |
| Route-name audit (every `route()` reference resolves to a defined route) | ✅ all resolve |
| 65-item matrix test coverage | ⚠️→✅ **3 gaps found & closed** (below) |

### Defects found and fixed during re-verification

1. **Wrong meta-key form in `MemberAuthController::meta()`** — the controller built
   dynamic keys as `meta_title_{page}` / `meta_description_{page}`, but the lang files
   define suffix-form keys (`login_meta_title`, `register_meta_title`, …). All four
   member-auth pages were rendering the raw key string as their `<title>`. Fixed to
   `public_pages.{page}_meta_title` / `.{page}_meta_description`.
2. **Missing `reset_*` meta keys** — the password-reset state of the recovery page had
   no `reset_meta_title` / `reset_meta_description` entries. Added to both
   `lang/en/public_pages.php` and `lang/th/public_pages.php`.
3. **Three matrix coverage gaps** (explicit items whose guarantee existed but had no
   dedicated assertion):
   - **#19 self-referral** → new service-level test proving
     `AgentReferralService::attributeUser()` refuses to attribute a member to an agent
     the member themselves owns (the exact policy registration delegates to).
   - **#34 invalid CAPTCHA on the recovery surface** → new test submitting a wrong
     captcha answer to `password.request.attempt` (asserts session error, nothing sent).
   - **#38 reset token never logged** → new test driving the full reset happy path,
     then scanning `storage/logs/laravel.log` asserting the raw token **and** the new
     plaintext password appear nowhere.

### Re-verification proof run

| Suite | Result |
|---|---|
| `MemberAuthParityTest` | **53 passed, 281 assertions** (was 50/262) |
| Full suite | **1643 passed, 7 skipped, 0 failed — 104,915 assertions** (was 1640/104,896) |

Zero regressions. All 30 delivered files are complete — no truncation, no placeholder
elisions, no missing manifest paths.
