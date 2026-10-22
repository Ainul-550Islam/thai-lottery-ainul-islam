# Part 16 — production source exclusion and guarded CI provisioning

Date: 2026-10-14. Pinned base HEAD: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.

Delivery: File150–File163, fourteen complete files, no elision. Cumulative overlay: 160 unique paths. Next: File164. This part fixes actual production boot/provisioning behavior; it does not activate customer funding/payouts or claim every country permits this business.

## Reproduced blockers

The unchanged `ProductionConfigSafetyTest` reproduced Part15's result: **20 tests / 46 assertions / one error and four failures**:

- Missing `.env.ci`.
- Missing `.env.production.example`.
- CI copying the development template into the runtime environment.
- Production safety ignoring a fixture entry in GLO source priority.
- The absent production template also prevented credential-value inspection.

The failures were not removed or weakened. They now pass with actual templates/workflow/provider fixes plus new refusal regressions.

## Actual production boot fix

`ProductionSafetyServiceProvider` previously checked GLO mode and fixture fallback but not the source ladder itself. `GloResultProviderChain` normalizes names and walks that ladder, so a fixture entry is an independent configuration hole even when mode=official and fallback=false.

The provider now refuses:

- Fixture in any position, including fixture-only, leading fixture and whitespace/case variants.
- Non-list/associative priorities and non-array configuration.
- Nested/non-string/empty entries and unsupported source names.

Empty priority remains valid: the existing official mode is then the selected source. Official-only priorities retain their behavior, including the chain's normalization. Local/testing fixture use is not converted into production authority.

A regression calls the actual provider `boot()` with APP_ENV=production and a fixture-priority config: it raises the production refusal exception even with fallback disabled. The environment is restored in finally. Eleven added feature cases cover malformed/fixture priorities, safe paths and real boot refusal.

Comments were narrowed to configuration evidence rather than implying every internally confirmed fixture would necessarily publish/pay under the current canonical provenance gates. Selecting official mode is **not** an official-source authenticity attestation; ingestion, checker identity, source evidence, controlled publication and settlement proof remain independent authorities.

## Secret-free templates and tracking

`.env.ci` is a committed testing-only default, not a development copy or production environment. It has:

- Empty APP_KEY: CI generates an ephemeral key after dependency installation.
- A named repository SQLite test database, bounded timeout and foreign keys.
- Explicitly false result-fixture flags, fixture fallback, live providers and automatic payout.
- Disabled prize payout/automation and deliberately official-only source selection.
- Ephemeral test stores; no merchant/bank/operator credentials.

PHPUnit's explicit synthetic lane fixtures remain testing evidence. They are not enabled by the provisioning template or interpreted as production official results. CI setup/config-cache/route gates run before the test process with fixtures/providers/payout disabled.

`.env.production.example` is a secret-free operator key list, with secure session settings/shared Redis stores, finite token expiry, official-only source selection, fixture flags false and live providers/payout disabled. APP_KEY, database/Redis/provider/settlement credentials are empty. Provider variable names match `config/payment.php`; no API contract or country permission is invented.

Blank values deliberately require real operator provisioning. This template is not a runnable approved production deployment, and the displayed approval threshold is the existing configuration value, not a qualified operator risk policy.

`.gitignore` now explicitly allows **only** `.env.ci` and `.env.production.example` alongside the existing development template. Real `.env` and other `.env.*` stay ignored. The archive includes these two reviewed, credential-empty templates as an explicit exception to runtime environment exclusion; it does not include a generated CI APP_KEY or an actual operator environment.

## Actual CI provisioning changes

`.github/workflows/ci.yml` now:

- Copies `.env.ci`, never `.env.example`.
- Installs Composer dependencies before Artisan key generation in the full-runtime job.
- Stops on key/provisioning failure; removes `|| true` suppression.
- Runs the CI guard before key generation, then again after key generation and before schema/test steps.
- Uses `migrate --force`, not `migrate:fresh`: no unconditional schema destruction.
- Aligns the disposable MySQL database with the existing test harness allowlist: `thai_lottery_test`, not `thailotto_test`.
- Keeps the app database user restricted for ordinary work. Backup restore creation/import/grant uses only the ephemeral MySQL service's CI-only root identity rather than assuming the app account can create a second database.
- Uses PHP8.4 (matching the Composer platform/dependency runtime) and Node22 (matching the frontend toolchain). Security workflow setup is aligned too.

The public literal database passwords in the workflow belong exclusively to disposable CI service containers, not an operator/customer account. No live secret is added.

Rust commands retain the existing nested-crate working directory and `--locked` flags. This part does not claim a local Cargo run, MySQL service execution or a successful hosted GitHub Actions pipeline. The workflows were parsed and their 41 run-blocks passed `bash -n`; those are syntax checks, not hosted service execution.

Existing unrelated CI release gates/audits remain authoritative. The sandbox's scoped results do not guarantee every historical workflow step is green; a real authorized branch run must record them.

## Guarded CI schema entry point

`app/Support/CiEnvironmentGuard.php` validates facts without DB/network/shell calls. `scripts/ci_runtime_guard.php` is a read-only CLI wrapper, without Laravel/kernel/service-provider bootstrap:

- Requires `CI=true` and APP_ENV=testing; not an operator migration tool.
- Requires debug false and a generated 32-byte ephemeral key before ordinary guarded commands.
- `--before-key` permits only a missing/blank key while validating the remaining facts and refuses an existing config cache. It neither generates nor writes the key.
- SQLite must be the repository's exact `database/thai_lottery_test` path, with no retargeting/symlink directory/file.
- MySQL must be the named disposable loopback service, port3306 and restricted `thailotto_ci` account; arbitrary databases/hosts/root users are refused.
- Requires explicit disabled fixtures/provider flags/live payout and official-only selection for provisioning.
- Runner process env overrides dotenv defaults, and those effective values are checked.
- When standard Laravel-generated PHP config cache exists, its effective DB/source/provider/payout facts are validated **before** any framework bootstrap/schema command. This cache is executable trusted application configuration, not treated as untrusted user input.
- Diagnostics are fixed messages, never env/key/password/credential dumps.

The guard does not execute migration, seed, queries, networking, queue workers or payments. It is not a replacement for authorization, staging isolation, deployment change control or production database identity protections. A caller can still issue arbitrary shell commands outside this workflow; no claim is made otherwise.

A local controlled bootstrap also copied the secret-free CI template, passed the before-key guard, generated an ephemeral key, passed the ordinary guard, built real Laravel config cache, passed the cached-facts guard and cleared the cache. It ran no migration/seed, did not enable gateways/payout, and removed the generated runtime environment/key in a trap. This proves the actual local Laravel cache shape is compatible with the guard, not hosted MySQL/Redis execution.

Nineteen standalone PHP unit cases cover safe SQLite/MySQL paths, key gating, missing CI marker, unsafe/missing flags, wrong targets, fixture/live settings, no sensitive-value echo and symlink refusal. Six Python subprocess cases exercise the actual PHP CLI in isolated temporary repository trees, including process overrides, stale cached DB rejection, before-key bounds and key non-disclosure. A sentinel file remains unchanged in every CLI case; no database is opened or migrated by those tests.

## Validation

| Check | Recorded result |
|---|---|
| Unchanged production config baseline | 20 tests /46 assertions /one error/four failures |
| Repaired production feature + pure CI unit classes | 50 tests /123 assertions, pass |
| Pure CI PHP unit class with no Laravel configuration/bootstrap | 19 tests /53 assertions, pass |
| Local CI key/config-cache/guard bootstrap | Pass;no migration/seed/funds;ephemeral runtime environment removed |
| Actual CLI subprocess guard tests | 6 tests, pass; sentinel files unchanged |
| Standalone static source contracts | 15 tests /991 assertions, pass |
| Inventory freshness/offline guards | Original source inventory matches;9 guards pass |
| Source scanner /offline SQL runner guards | 11/8 pass;no SQL server contacted |
| PHP lint/Pint | Five delivered PHP files pass |
| Python compile | Delivered CLI regression script passes |
| Workflow YAML /shell syntax | 8 total jobs parsed;41 run-blocks syntax pass, not executed |
| Secret-free template scan | 39 CI keys /77 production keys;credential-valued keys empty |
| Draw/GLO/operator write matrix | 111 tests /683 assertions, pass |
| Console | 45 tests /204 assertions, pass |
| Broad financial/Payment/Finance | 209 tests /991 assertions, pass |
| Betting/Risk/SQLite retry | 63 tests /300 assertions, pass |
| Wallet conversion | 71 tests /259 assertions, pass |
| Player application/financial presentation | 92 tests /583 assertions, pass |
| Current collection | 2,380 cases, not proof of execution |
| Authoritative serial whole-repository diagnostic | 1,869 tests /121,537 assertions /one public-terms disclaimer assertion failure;later cases unexecuted |

Database-writing PHPUnit runs are serial; standalone/offline guards do not access the shared test SQLite store. Scoped matrices overlap and must not be added as unique coverage. Runtime outputs are preserved fully in `evidence/runtime-part-16.txt`.

## Next actual code work — File164 onward

The repository stop-first run passed the repaired production cases and stopped at
`Tests\Feature\PublicPages\PublicTermsTest::test_legal_disclaimer_independent_platform_and_language_parity`, line234:
the rendered response does not contain the expected English phrase `not the official GLO website`.

The whole unchanged PublicTerms class was isolated: **18 tests /98 assertions /one failure**. Next compare actual English/Thai terms/disclaimer source and the public non-affiliation requirements. Determine whether a disclosure is missing or an old literal expectation contradicts a legitimate wording change. Preserve clear independent-operator status and do not fabricate government endorsement, official-site identity, licensing, payout guarantees or provider approval merely to satisfy a test.

There are 511 collected cases beyond the observed stop, not 511 known passes. Continue the serial full diagnostic after the evidence-based public disclosure fix.

## Global-system scope and real funds

The user's global/multi-country requirement remains the architectural direction, not an assumption that every country/provider/currency is authorized. Country eligibility plus currency-specific precision and provider routing must fail closed for unsupported/unapproved combinations. No global routing module or universal country approval is claimed implemented by these provisioning changes.

The immediate external dependency is an authorized operator/jurisdiction/currency/merchant sandbox contract. Next acceptance must execute actual signed callbacks/replay/amount-owner-currency refusals, withdrawal maker/checker/execution references and statement reconciliation against that provider, then isolated staging browser→Laravel→canonical ledger/history recovery. Part15's 16 Chromium tests used mocked transport; its separate canonical ledger tests executed real sandbox database changes. Neither substitutes for external payment acceptance.

Official-source authenticity/inventory, production SQL topology/concurrency, durable queue/outbox/crash recovery, qualified compliance and an authorized controlled live reconciliation procedure remain prerequisites. This part strengthens boot/setup refusals; it does not flip LIVE or enable a merchant gateway. Legacy member transfer and alternate GLO direct settlement remain fail-closed.

## Delivery integrity

All prior 146 delivered paths remain byte-identical; new provider/workflow/template/guard paths now overlay the pinned base. The ZIP has160 unique source/evidence paths, excludes actual runtime environments/secrets/dependencies/uploads/databases/generated assets, and includes only the two explicit blank-credential templates described above. All File150–File163 bodies are complete in `Full-Code-Continuation-Files-150-163.md`. Unique paths, CRC, byte equality and SHA-256 are checked. Next label:File164.
