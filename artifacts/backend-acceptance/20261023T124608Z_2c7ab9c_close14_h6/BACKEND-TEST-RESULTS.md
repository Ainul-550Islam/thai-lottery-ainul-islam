# Backend Test Results — Frontend `H6` Closure Iteration

- **Evidence directory:** `artifacts/backend-acceptance/20261023T124608Z_2c7ab9c_close14_h6/`
- **Source identity:** branch `backend-next`, commit `2c7ab9ca7e7b1b953c7a9d1f4c0c17ea154b64c7` (see `source-identity.json`)
- **Runtime:** PHP 8.4.27 · PHPUnit 11.5.56 · SQLite 3.46.1 · MariaDB 11.8.6 (REPEATABLE-READ) · Redis 8.0.2 · rustc/cargo 1.93.1
- **Scope of this iteration:** the 14 Frontend Blade `H6` failures handed off by `20261023T095500Z_2c7ab9c_close35`.

---

## 1. Headline result

| Metric | Value |
|---|---:|
| **Frontend Blade `H6` failures handed off** | **14** |
| **`H6` failures closed this iteration** | **14** |
| **`H6` failures remaining** | **0** |
| Regressions introduced by this iteration | **0** (proved, §5) |

> **NOT FINAL — BACKEND NOT 100%.** This evidence set closes the 14 Frontend Blade
> `H6` failures, but 117 pre-existing failures/errors remain open (65 Backend-owned), so
> the Phase 5 condition of zero Backend failures/errors/unexecuted-required-skips is not
> met. The archive is named and labelled accordingly.

The 14 cases are:

| Suite | Cases | Status |
|---|---:|---|
| `Tests\Feature\Admin\AdminConsoleChromeTest` | 7 | **CLOSED** |
| `Tests\Feature\Player\BrowserBetFinancialBoundaryTest` | 2 | **CLOSED** |
| `Tests\Feature\Player\PlayerFrontendModulesTest` | 3 | **CLOSED** |
| `Tests\Feature\Player\PlayerExperienceComprehensiveTest::test_15` | 1 | **CLOSED** |
| `Tests\Feature\Legal\PublicContentSourceTest` | 1 | **CLOSED** |

Paired before/after raw logs are in `pre-fix-logs/` and `post-fix-logs/`.

---

## 2. Per-run counts (raw, non-deduplicated)

Every number below is read directly from the JUnit XML in `junit/`. Nothing is carried over from a prior report.

| JUnit evidence file | Tests | Passed | Failures | Errors | Skipped | Assertions |
|---|---:|---:|---:|---:|---:|---:|
| `FULL-suite-sqlite.xml` | 7276 | 6897 | 92 | 24 | 263 | 192694 |
| `gate-a-build-deployment-production.xml` | 227 | 224 | 3 | 0 | 0 | 2671 |
| `gate-b-c-g-inline-mariadb-tests-mysql.xml` | 136 | 136 | 0 | 0 | 0 | 1432 |
| `gate-b-ledger-money-mysql.xml` | 101 | 101 | 0 | 0 | 0 | 1301 |
| `gate-b-ledger-money-sqlite.xml` | 1260 | 1220 | 0 | 0 | 40 | 11681 |
| `gate-c-deposit-webhook-mysql.xml` | 27 | 27 | 0 | 0 | 0 | 783 |
| `gate-c-deposit-webhook-sqlite.xml` | 1018 | 962 | 0 | 0 | 56 | 6612 |
| `gate-d-withdrawal-payout-mysql.xml` | 47 | 47 | 0 | 0 | 0 | 715 |
| `gate-d-withdrawal-payout-sqlite.xml` | 2595 | 2479 | 2 | 0 | 114 | 126288 |
| `gate-e-transfer-conversion-mysql.xml` | 56 | 56 | 0 | 0 | 0 | 1326 |
| `gate-e-transfer-conversion-sqlite.xml` | 926 | 832 | 0 | 0 | 94 | 7316 |
| `gate-f-ticket-purchase-lanes-mysql.xml` | 40 | 40 | 0 | 0 | 0 | 824 |
| `gate-f-ticket-purchase-lanes-sqlite.xml` | 1017 | 970 | 0 | 0 | 47 | 107584 |
| `gate-g-settlement-results-mysql.xml` | 43 | 43 | 0 | 0 | 0 | 1677 |
| `gate-g-settlement-results-sqlite.xml` | 495 | 471 | 0 | 0 | 24 | 104803 |
| `gate-h-database-seeders.xml` | 16 | 16 | 0 | 0 | 0 | 204 |
| `gate-i-security-compliance-mysql.xml` | 14 | 14 | 0 | 0 | 0 | 256 |
| `gate-i-security-compliance-sqlite.xml` | 923 | 909 | 0 | 0 | 14 | 33135 |
| `gate-integration-concurrency-mysql.xml` | 13 | 12 | 1 | 0 | 0 | 124 |
| **RAW (non-deduplicated) sum** | **16230** | **15456** | **98** | **24** | **652** | **601426** |

### Why `FULL-suite-sqlite.xml` exists

The prior iteration reported per-gate totals only. During this iteration those gate
scopes were found to be **internally inconsistent** — e.g. the prior
`gate-c-deposit-webhook-sqlite.xml` contains `Tests\Unit\Payment\*` classes that its
recorded command (`phpunit … tests/Feature/Payment`) cannot produce, and the prior
`gate-a-*.xml` omits `Tests\Feature\Locale\BanglaPublicPagesTest` entirely (224 tests
where the same command now yields 227). To remove all ambiguity, the **complete**
`tests/` tree was executed in one process as `FULL-suite-sqlite.xml`. It is the
authoritative SQLite result; the gate files above are retained as corroborating
lane-level evidence (notably the MariaDB lanes, which the SQLite run skips).

---

## 3. Deduplicated totals

**Deduplication rule.** A test case is identified by the pair
`(fully-qualified test class, test name)`. The same case legitimately appears in
several evidence files — the full-suite run, the SQLite gate it belongs to, and the
MariaDB lane that re-executes it — and is counted **once**. The outcome of a
deduplicated case is the worst outcome observed across every run in which it
appeared, so a case that fails in any lane is never reported as passing:

```
FAILURE if it failed in any run
else ERROR   if it errored in any run
else PASS    if it passed in any run
else SKIPPED if it was skipped in every run
```

**A case skipped on SQLite but executed on MariaDB counts as executed, not as a pass
and not as a skip.** This is why the deduplicated skip count (1) is far below the raw
SQLite skip count (263).

| Deduplicated outcome | Unique cases |
|---|---:|
| **PASSED** | **7159** |
| **FAILED** | **93** |
| **ERRORED** | **24** |
| **SKIPPED** | **1** |
| **TOTAL unique cases** | **7277** |

- Executed cases: **7276**. Pass rate of executed: **98.39 %**.
- Unique assertions (single-process full suite): **192,694**.

The single remaining skip is
`Tests\Integration\Concurrency\PasswordLifecycleRealEngineTest::test_password_lifecycle_holds_under_multi_process_load`
with data set `"no engine configured"`. That data set is emitted by the provider only
when `CONCURRENCY_TEST_DSN` is unset; it is a sentinel, not an executable case. With
the DSN set (`gate-integration-concurrency-mysql.xml`) the provider emits the real
engine data set instead, which **executes and fails** — see §6.

---

## 4. Prior-iteration total superseded

The prior iteration reported **5,591 unique cases / 14 failures**. That figure is
**superseded and must not be reused**:

| | Prior iteration | This iteration |
|---|---:|---|
| Unique cases | 5,591 | **7,277** |
| Failures + errors | 14 | **117** |
| Basis | 17 gate XMLs with inconsistent scopes | complete `tests/` tree + MariaDB lanes |

The prior 14 failures were all Frontend Blade `H6`; the 117 here are **different,
pre-existing** defects (§6), **none of which is an `H6` case**. The prior
under-count came from the incomplete gate scopes, not from the code.

---

## 5. Regression proof

The 117 remaining failures/errors were each attributed to a pre-existing cause, not to
this iteration. Method: the five files edited this iteration were reverted to their
pre-edit content, the **same** 35 failing test classes were executed, and the failing
set compared.

| | True baseline (pre-edit) | This iteration |
|---|---:|---|
| Failing classes executed | 35 | 35 |
| Failures | 92 | 92 |
| Errors | 24 | 24 |
| Failing `(class, name)` set | 116 | 116 — **identical** |

`diff` of the two failing sets: **empty**. Zero regressions, zero accidental fixes.

Two additional targeted baselines were run and were likewise identical:

- `tests/Feature/Home tests/Feature/Contact tests/Feature/About tests/Feature/Glo tests/Feature/Locale tests/Unit/Lang` → 513 tests, 21 failures, 3 errors, identical set before and after.
- `gate-a` scope → 227 tests, 3 failures, identical set before and after.

---

## 6. The 117 pre-existing failures/errors

Full detail, with per-case messages, is in `BACKEND-FAILURES-AND-OWNER-HANDOFFS.md`.
Summary by owning track:

| Owning track | Problems |
|---|---:|
| Frontend — public copy / parity (`Home`, `PublicPages`, `SEO`, `Locale`, `About`, `HomeCountdownService`) | 38 |
| Backend — Filament admin resources | 22 |
| Backend — operations reporting | 21 |
| Frontend — public services (`Web`, `PublicServices`) | 13 |
| Backend — realtime money chain | 8 |
| Backend — architecture / API | 5 |
| Backend — other (static page contracts) | 5 |
| Backend — HTTP trusted-proxy resolution | 3 |
| DevOps — repository hygiene | 1 |
| Backend — integration / concurrency | 1 |
| **Total** | **117** |

Notable, because it is a genuine security defect rather than a copy defect:

> `Tests\Integration\Concurrency\PasswordLifecycleRealEngineTest::test_password_lifecycle_holds_under_multi_process_load`
> — *"a login succeeded with an already-superseded password"* under 5 concurrent
> workers on MariaDB 11.8.6. Confirmed identical at the true baseline. This test only
> executes when `CONCURRENCY_TEST_DSN` is set, which is why no prior evidence ran it.

---

## 7. Blocked, not passed

| Check | Status | Reason |
|---|---|---|
| Docker-hosted smoke tests | **BLOCKED** | No Docker daemon in this environment (`docker_daemon_available: false`, `docker_executable_present: false`) |
| Live payment-gateway cutover | **BLOCKED** | Prohibited by task constraint; no live provider calls were made |
| `gate-integration-concurrency-mysql` password lifecycle | **FAILED (pre-existing)** | Real concurrency defect, §6 — handed to Backend, not skipped |

No Docker or live-payment check is reported as passed anywhere in this evidence set.
