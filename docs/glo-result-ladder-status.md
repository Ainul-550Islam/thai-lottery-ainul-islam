# Result intake ladder, provenance and read-only health

Verified 2026-10-15. Related gap-report blockers: result intake (#4) and failing CI (#5).
F42 completes the manual cursor contract over the existing range engine; GLO intake/health is unchanged and deployment/automatic invocation remains off.

## Measured results

- Complete `tests/Feature/Glo`: **262 tests, 1,146 assertions; no errors, failures or skips**.
- Production, including the normalized fixture-priority guard: **43 tests, 135 assertions; all pass**.
- Expanded changed-area regression: **1,441 tests, 107,236 assertions; all pass, no skips**.
- Retained audit-preview component: **53 tests, 517 assertions; all pass, no skips**.
- Retained legacy-command component: **69 tests, 334 assertions; all pass, no skips**.
- Retained queue-job refusal: **26 tests, 121 assertions; all pass, no skips**.
- Current manual checkpoint/inventory scope: **51 tests, 315 assertions; all pass, no skips**.
- Former skipped simulation race: **1 test, 48 assertions; real separate processes**.
- Rust release tests: **29 pass**; local synthetic runtime only, not provider authority.
- Whole repository: **2,538 tests, 113,966 assertions; 0 errors, 0 failures, 0 skips**.

The measured local full suite is now green; this is not production/official-feed certification. Evidence: `/home/user/full-f42.log`,
`/home/user/full-f42.junit.xml`, `/home/user/part3-focused-f42.log` and
`/home/user/manual-focused-f42.log`. Thirty-six previously failing f35 names now pass; the remaining
15 are in the unchanged chunked-settlement class, not result intake.

## Intake defects closed

1. The chain no longer depends on the nonexistent `ProviderAttemptRecorder` or `ResultSource`.
   It resolves implemented providers through the container and records every considered rung.
2. Provenance writes use the actual `glo_result_imports` schema: `upstream_draw_id` and `payload_summary`.
3. Replay lookup and stored provider fingerprint use the same identity. The canonical ingestion
   fingerprint remains in ingestion audit instead of replacing the provider replay key.
4. Lifecycle/duplicate-ingestion refusals are recorded as `refused`, not thrown out of the command.
   Malformed payloads are recorded as `failed`. Neither path creates a published result.
5. Independently drawn bottom-two comes from the provider field or `tiers.last_two[0]`, never from
   the final digits of first prize.
6. The answering provider label reaches ingestion, provenance and independent confirmation. A fixture
   cannot be recorded as an official-source answer.
7. `Support\CircuitBreaker` reads and writes the same policy-specific cache key.
8. An unreachable official source retains provider-level `not_configured` plus `outage => true`.
   The chain treats that flag as failure and counts it against the breaker. No configured source
   remains a skip rather than an upstream outage.

## Adopted safety decisions

- Sources are ordered by `glo.sources.priority`. With an empty list, the existing mode switch selects
  exactly one source. Fall-through permission alone does not append a fixture rung.
- An explicitly listed fixture can answer after an unconfigured source, or after upstream failure
  only when development/test fall-through permission is explicitly enabled.
- Production independently rejects fixture mode, fall-through and **every normalized fixture entry**
  in the priority list, including mixed case, spaces and a scalar fixture value. This closes the
  unconfigured-official-to-fixture path even with fall-through false.
- `glo:import-result` exits non-zero unless intake succeeds. Published results are never overwritten
  merely to satisfy a console test.
- Fixtures remain labelled synthetic test data; no live result, government approval or affiliation is asserted.
- No real-money payout automation is introduced or enabled.

## Read-only operations projections

### Provider health

`GloProviderHealth::effectiveOrder()` mirrors the chain's normalization, mode-only selection, unknown
name filtering and explicit repetitions. It no longer reports an imaginary appended fixture. Nine data
vectors compare reported order with actual recorded order. A tenth test proves health does not fetch a
provider or consume the breaker's next half-open recovery probe.

### Import history

The admin `/result-imports` projection uses bounded `GloResultImportService::recentIntake()` rows:
reference, answering source, status and intake time only. Raw payloads, endpoints, fingerprints and
failure internals are not returned. The Pages150 static contract passes.

The obsolete `App\Services\Lottery\CircuitBreaker` is removed; commands and chain use
`App\Services\Lottery\Support\CircuitBreaker`. The bundle explicitly records that deletion so a
checkout cannot silently recreate dead code through a plain archive extraction.

## Fixture corrections retained

- Canonical fixture reference: `GLO-2026-09-16`.
- Raw stored numbers are under `payload`, not nonexistent flat fields.
- Successful intake fixtures use ingestible states, not already-published draws.
- Tests needing synthetic fallback state the permission explicitly without changing the safe default.
- Maker/checker fixtures independently supply bottom-two `09` to both operators and verify the public
  `two_digit_bottom`; validation is not weakened.
- Projection tests use database truncation to prevent committed shared draws leaking between cases.

## Remaining separate work

The original fifteen cursor errors now pass their unchanged acceptance class under the explicit
manual API. Legacy CLI/queued execution, external gateway and scheduler invocation remain disabled;
see `docs/manual-cursor-settlement-status.md`. Both previous runtime skips still genuinely execute.

All 51 resolved f35 cases and historical full details are retained in
`docs/full-suite-failure-classification.json`. Passing intake tests are not certification of a live
upstream feed, hosted CI or cross-host financial concurrency.
