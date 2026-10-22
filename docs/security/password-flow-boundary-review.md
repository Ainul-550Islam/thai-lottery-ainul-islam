# File 235 — Password-flow boundary review after Part 46

**Part 47 · REVIEW ONLY · two reproduced gaps · release remains NO-GO.**

এই review-তে production behavior পরিবর্তন করা হয়নি। Parts 43–46-এর member password-change/reset protection কাজ করছে, কিন্তু সেই protection সব password writer ও credential issuer-কে ঢেকে ফেলেছে—এমন দাবি সঠিক নয়। সবচেয়ে জরুরি পরের fix হলো admin User edit-এর password write-কে audited credential-revocation lifecycle-এ আনা।

## 1. Scope and evidence levels

Snapshot: original base `5df5d144b8c912f297ece3b809171054a429d881` plus delivered Parts 1–46. Before inspection, workspace files were restored only when their previous content matched a known delivered/base version. All 172 prior delivered paths remain byte-identical. This document is the only new repository file.

Reviewed application controllers, auth services, model casts, admin resource/page/provider, permissions helper, configured routes/brokers and relevant installed Laravel/Filament implementation. `reviewed-source-hashes.json` and numbered `reviewed-source-lines.txt` preserve the reviewed snapshot. Search matches alone are not classified as public endpoints: compiled route actions were checked.

Evidence vocabulary:

- **REPRODUCED:** deterministic local application/Livewire test demonstrates the stated behavior.
- **SOURCE-CONFIRMED:** source/control-flow or compiled route inventory demonstrates the boundary; no broader runtime claim.
- **UNVERIFIED:** production deployment, parallel workers, alternative stores/providers, browser behavior or arbitrary external callers not exercised.

The two diagnostic tests deliberately assert the current unsafe behavior. Their passing result confirms a gap, NOT a fix. They are delivery evidence outside the repository test suite; do not adopt their unsafe assertions as permanent acceptance requirements.

## 2. Entry-point inventory

Compiled testing-environment route list contains 549 entries. Selected route actions/middleware are saved in `password-routes.json`; full inventory in `routes.json`.

| Surface | Entry point / implementation | Current boundary |
|---|---|---|
| Member link request | POST `/forgot-password`; Auth/MemberAuthController → PasswordResetService::requestReset | CAPTCHA/route controls retained; Part 46 cooperating issuance with owner lock, stale observation check and after-commit notification |
| Member reset | POST `/reset-password`; Auth/MemberAuthController → PasswordResetService::resetPassword | Whole default-database broker transaction, owner lock, credential/proof revocation; accepted token consumed |
| Member API password change | PUT `/api/v1/profile/password`; Api/V1/ProfileController::updatePassword | Part 44 browser boundary + PasswordChangeService; Part 45 default-broker reset-link invalidation |
| Legacy player API password change | POST `/api/v1/player/profile/password`; PlayerProfilePortalController::changePasswordApi | Delegates to the same Request-only API method; not a second password writer |
| Member web password change | PUT `/profile/password` and `/player/profile/password`; Web/PlayerWebController::updatePassword | Same shared service, supported-session preflight, commit then current-browser logout |
| Operator user edit | GET `/admin/users/{record}/edit` plus Livewire save; UserResource / Pages/EditUser | **Separate direct model password writer; reproduced lifecycle bypass** |
| API login | POST `/api/v1/auth/login`; Api/V1/AuthController::login | Password/status check, then separate token deletion/creation; **reproduced late-token issuance gap** |
| Member web login | POST `/login`; Auth/MemberAuthController → LoginService | Auth::attempt + session regeneration; no shared password-generation barrier observed |
| Admin login | GET `/admin/login` plus Filament authenticate action | Stock Filament Auth::attempt/session regeneration; not routed through member LoginService |
| Registration | Member registration service/controller; user creation and auto-login | New-account lifecycle, not an existing-account password reset; not changed here |
| Old Web/AuthController | Class contains older register/login code | No compiled route action targets this class in the inspected configuration; do not call it an active public bypass without new evidence |
| Web/MemberAuthController | Compatibility subclass of Auth/MemberAuthController | No separate implementation; current route file targets the Auth namespace controller |
| Filament built-in recovery/profile | Vendor features exist | No admin password-recovery route in the compiled inventory; provider enables login but not passwordReset/profile. Presence in vendor is not activation |

Other password-looking values were distinguished from account recovery: payment gateway credentials are provider secrets, and GloTicketFreezeService's system-placeholder account creation is not a password update/reset of an existing member. They are not certified secure by this review; they are outside this lifecycle finding.

## 3. F1 — Operator password edit bypasses revocation (REPRODUCED, high priority)

### Source chain

- `app/Filament/Resources/UserResource.php:84–87`: edit permission checks `AdminAccess::MANAGE_USERS`.
- `app/Filament/Resources/UserResource.php:158–172`: optional password input, minLength(8), non-empty dehydration, no shared StrongPasswordRule.
- `app/Filament/Resources/UserResource/Pages/EditUser.php`: strips the hash before fill and discards blank password before save; it does not override handleRecordUpdate to invoke a password lifecycle service.
- `vendor/filament/filament/src/Resources/Pages/EditRecord.php:254–258`: inherited update uses `$record->update($data)`.
- `app/Models/User.php:70–101`: password is fillable and has the hashed cast. Hashing is not credential revocation.
- Contrast `app/Services/Auth/PasswordChangeService.php:31–46`: the member path locks/rechecks, changes hash, revokes credentials/proofs and invalidates existing reset links in one transaction.

### Diagnostic result

An authorized seeded admin edits a distinct active player through the actual Livewire EditUser form. The diagnostic provisions an existing Sanctum token, default-broker reset link, remember credential and database-session row before the edit.

Observed after saving:

1. The new password `abcdefgh` is accepted and stored as a working hash.
2. The old Sanctum token remains stored and authenticates a subsequent `/api/v1/profile` request as the target player after resetting test guards/session state.
3. The old reset link remains valid and is successfully redeemed through PasswordResetService, replacing the operator-set password again.
4. The remember credential and the provisioned session row remain stored.
5. No `password_credentials_revoked` audit record is produced by the edit.

The test demonstrates API access and reset-service redemption, not a real-browser session/remember-cookie replay. SecuritySession/action-proof invalidation is missing from the source chain, but this diagnostic does not claim those additional records were individually exercised. It also does not assert that every possible panel log is absent; the checked lifecycle audit is absent.

The admin form accepts an eight-letter lowercase password with no digit, whereas `StrongPasswordRule.php:73–76` requires both letters and digits. This is a confirmed policy mismatch, not just a difference in explanatory text.

This is a security invariant missed after an authorized operator action. It is not, by itself, proof that an unauthenticated user can reach the admin form. Target privilege restrictions, step-up policy and operator approval requirements need their own explicit design.

### Recommended next implementation

Use an explicitly authorized operator password-change service, not a weakened member current-password check. It should:

- Preserve existing panel/target authorization and decide separately whether higher-privilege targets or self-edits require stronger restrictions.
- Enforce the shared password rule and distinguish blank password/profile-only edits from actual password replacement.
- Lock the fresh target and compose password persistence, Part 43 credential/proof revocation, Part 45 reset-link invalidation and operator-attributed audit in one transaction.
- Preserve unrelated profile fields and other accounts' sessions/tokens. Handle self-edit current-browser logout only after owned commit; do not log out an unrelated operator when changing someone else's account.
- Require real SQL/audit failures and model vetoes to abort. Do not route an admin request through a fake target-current-password value or expose an unguarded force-reset option on the member service.
- Test Livewire integration, weak password rejection, no-password edits, old credential/link rejection, audit attribution, denied operators/targets, rollback and operator self-edit behavior.

No part of that proposed fix is implemented by this review.

## 4. F2 — API login can mint a usable token after password revocation (REPRODUCED ordering)

`app/Http/Controllers/Api/V1/AuthController.php:83–129` retrieves a user, checks its password and status, deletes tokens with the selected device name, then creates a new token. The password check and creation are not coupled through the owner-lock/recheck transaction used by password-change/reset operations.

The diagnostic makes a normal API login request using the then-valid old password. A one-shot PersonalAccessToken::creating hook is used solely as a deterministic scheduling point: before insertion, it runs and commits a real PasswordChangeService operation with a fresh owner. Then the original login resumes its insertion.

Observed:

- Password change succeeds and the new hash remains persisted.
- The original login returns 201 with a token created after revocation.
- That token subsequently accesses the authenticated profile as the same user.

The hook simulates the interleaving; it is not an attacker-facing injection feature and is not a multi-process exploit demonstration. Source ordering plus this runtime reproduction confirms the stale-login issuance boundary. Actual concurrent MySQL/PostgreSQL timing, isolation and frequency are UNVERIFIED.

Recommended follow-up after F1: one lock/transaction boundary that freshly rechecks password, active status and device-token replacement before issuing the API token, coordinated with password-change/reset locks. Do not publish the plaintext token before the relevant commit. Reassess web/admin session issuance and remember-device behavior separately; fixing the bearer path alone does not create a global generation barrier.

## 5. Other boundaries still open

| Boundary | Evidence / status |
|---|---|
| Direct broker/raw writers and multiple named brokers | Parts 45–46 protect the current default broker and cooperating issuer, not arbitrary writers. No second active application sendResetLink call was found outside Part 46's helper in the inspected app scan. This does not enumerate external jobs/packages/deployments. |
| Member/admin web login overlap with revocation | Source shows Auth::attempt and session establishment without a common persisted generation check. Not reproduced as a browser race here; do not claim F2's token proof also proves every web-session outcome. |
| Notification delivery durability | Part 46 afterCommit callback is not an outbox. Crash, queue failure or a later invalidation may leave a persisted token without mail or deliver an already-invalid link. |
| Email changes and historical addresses | Admin form permits direct email updates and explicitly preserves existing verification timestamp. Part 46 rejects stale issuance observations, but historical-link cleanup and a verified email-change lifecycle are not implemented. Separate policy/implementation needed. |
| Session backend identity | Part 44 checks stable config and exact handler class, not protected cached handler connection/table identity; mixed/non-database stores remain unsupported. |
| Whole-system generation | Hash/email observation is not a monotonic epoch. Backup restoration, noncooperating writers and sessions issued later remain outside that check. |
| MFA and other audit sections | No login MFA enforcement is activated here. Fraud/AML, result integrity and the remaining remediation sections are not completed by this auth review. |

## 6. What the green tests mean

Executed locally with PHP 8.4.26 / PHPUnit 11.5.56 and isolated SQLite test databases:

| Evidence | Result | Interpretation |
|---|---:|---|
| Diagnostic reproductions | 2 tests / 25 assertions PASS | Both gaps reproduced; NOT security acceptance |
| Existing cumulative targeted regression | 1019 tests / 5066 assertions PASS | Previously covered behavior remains green; missing coverage can coexist with real gaps |
| Original MemberAuthParity, separate | 54 tests / 294 assertions PASS | Existing member auth checks |
| Original AdminPanelAccess, separate | 11 tests / 24 assertions PASS | Existing panel access checks, not global password revocation |
| Existing IdentityResources password filter | 3 tests / 16 assertions PASS | Hash not exposed, blank password unchanged, new value hashed once; does not test lifecycle revocation |

Do not add overlapping suite counts or equate them with full-app coverage. No browser automation, live mail, production data mutation, deployment or parallel-worker test was performed. Application source/config/routes and existing test files were not edited.

Two diagnostic/setup corrections are retained in evidence:

- Initial route-list command was blocked by the existing production safety provider because the sandbox's fixture/security configuration was unsafe for production. The provider was not disabled; route inventory was rerun with command-local `APP_ENV=testing`, SQLite `:memory:`. Production boot readiness is not established.
- Initial diagnostic assertions incorrectly expected `data.id` from the profile response. The real schema is `data.user`; assertions were corrected to compare the target email. Both requests had already returned 200. Final diagnostics additionally redeem the old reset link. No application code was changed to make these tests pass.

## 7. Reproduce and handoff

Use only a disposable test environment with lockfile dependencies installed. The sidecar `PasswordBoundaryDiagnosticTest.php` is not installed into the repository test suite. From the checkout root, run `PASSWORD_REVIEW_REPO="$PWD" php vendor/phpunit/phpunit/phpunit /path/to/delivery/PasswordBoundaryDiagnosticTest.php --colors=never`. It explicitly selects SQLite `:memory:` before migrations. Notification transport is not exercised by these diagnostics.

For the existing cumulative suite, use delivery `test-paths.txt` with the same supported database-session test profile. Run original auth/admin/identity checks separately with `DB_DATABASE=:memory:` and isolate/restore the test log as required by their assertions.

Artifacts include diagnostic source/JUnit/logs, complete route inventory, reviewed source hashes/numbered snapshots, regression logs and prior-source preservation evidence. Diagnostic credentials are synthetic; raw issued tokens are not printed by the test.

**Next priority: F1 admin password-write integration, starting at File 236.** F2 stale API login issuance follows. Keep production release **NO-GO** until these and the other release blockers are addressed and verified. No code was pushed or deployed in this batch.
