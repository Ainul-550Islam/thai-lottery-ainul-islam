# Clean source/CI-runtime reproduction — f43

Verified 2026-10-15. This stage tests delivery/runtime reproducibility, not another application feature,
deployment, scheduler activation or external transfer. Application production source is unchanged.

## Why the next check matters

F42's working-tree unfiltered run was genuinely green. A clean run must independently prove that
result can be recovered from the exact git base plus the complete-file archive, without an old .env,
existing database, compiled asset/native output or dependency directory.

The source audit found no tracked or application-untracked delta outside the f42 canonical bundle.
Its exact base commit is `5df5d144b8c912f297ece3b809171054a429d881`. The reproduction runner extracts
only that git base, applies the verified restorer/archive, creates an isolated .env.ci/fresh test file,
installs locked dependencies, generates a disposable key, builds frontend/native, then runs unfiltered
PHPUnit. It never calls migrations against an existing/deployed DB, app money commands or a scheduler.

## Genuine first clean result, retained

The first clean reproduction of the f42 source archive failed with **2,538 tests / 114,062 assertions,
one error and four failures, zero skips**. Complete evidence remains in
`/home/user/f43-clean-reproduction-evidence/11-full-phpunit.log` and its JUnit XML.

- Four `ScheduleRegistrationTest` cases assumed a developer .env had enabled draw automation. Safe
  `.env.ci` disables it, so registration was correctly absent.
- The P0 half-sale manual settlement fixture attempted a 2,985,000.00 credit against the actual default
  wallet maximum 1,000,000.00. The guard correctly refused; an earlier larger developer limit hid this
  environment dependency.

That first failed run is not overwritten or described as clean-green evidence.

## Corrected only test assumptions, not production limits

The schedule test now explicitly enables **test-only config** to inspect enabled wiring, recreating
cached Schedule/Artisan state so the actual application registration callback sees the fixture choice.
It executes no scheduled task. An additional disabled-case assertion proves safe config registers no
draw tick/risk provisioner; the real .env.ci and production automation flags remain disabled.

The positive P0 proportional-payout fixture uses a one-tenth sale, giving 600,000.00 gross, 3,000.00
stamp duty and 597,000.00 net, within the unchanged default cap. The original half-sale math remains
asserted in a new **over-cap refusal and full rollback** case. No cap is raised or removed; both tests
explicitly exercise the production-default 1,000,000.00 maximum rather than a developer override.
Private KYC/independent review/server-authored preapproval and replay journal assertions remain.

After the corrections, the complete two fixture classes pass **22 tests / 182 assertions** against
the clean safe-CI environment (`clean-fixture-focused-f43.log` and matching JUnit XML).

## Reproduction tool safety

Fourteen Python tests pass for the runner (`reproduction-tool-tests-f43.log`). They prove:

- only a new workspace/evidence directory is accepted; no implicit reuse/delete/overwrite;
- source/ancestor/symlink destinations are refused;
- tar traversal/link/duplicate/environment/dependency/build-state members are refused before writes;
- archive/restorer/base identity is checked;
- existing .env/test databases cannot be reused or overwritten;
- production/enabled-provider CI templates are refused;
- inherited production DB/provider/automation configuration is removed from the child environment;
- unfiltered JUnit must contain actual tests with zero error/failure/skip; a zero process wrapper or
  empty/skipped suite cannot become a successful report.

The runner never changes the source repository's .env/database, activates CLI/job/scheduler/public
surfaces, provisions provider keys or authorizes a live deployment. Dependency registries are contacted
only for installation/build inputs. Real application tests remain confined to the new disposable DB.

## Complete files, no elision

The runner, its safety tests and both corrected PHP fixture files are delivered beginning to end.
All previous application, original manual cursor acceptance and deployment barriers are unchanged.
The canonical manifest/list identifies exact full bytes; no diff-only file or success placeholder is used.

Run in a **new** workspace and **new** evidence directory, substituting actual project/runtime paths:

```bash
python3 scripts/reproduce-local-regression.py \
  --source /home/user/thai-lottery-ainul-islam \
  --archive /home/user/part3-bundle.tar \
  --manifest /home/user/part3-bundle.manifest.json \
  --workspace /home/user/.cache/new-clean-room \
  --report-dir /home/user/new-clean-evidence \
  --php /usr/bin/php8.4 \
  --composer /home/user/bin/composer.phar
```

The base source must retain its git commit and match the declared restorer hash. The example work/report
paths must not exist. Caches/compiled native/source copy are intentionally disposable; persist only
reports/log checksums and source tooling, not the .env/database/build as release deliverables.

A successful local clean reproduction is still not hosted CI, real provider-signature/source trust,
operational backup/restore, load/cross-host or production deployment certification. Those actions need
separate appropriate authority/review and were not performed here.
