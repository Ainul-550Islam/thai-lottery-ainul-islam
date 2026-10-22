# Part40 — dependency remediation and a fail-closed release gate

Date: 2026-10-16. Sequential continuation: File716–743; next File744. This is a real build/dependency/stylesheet/CI correction, not only a test tranche. Part39's financial restrictions and remaining operational requirements remain authoritative except that its eight open npm findings are resolved by this tranche's current audited lock.

## Fixed

1. A fresh audit reproduced all **8 npm findings: 6 high and 2 moderate**. The legacy Tailwind3 chain depended on braces3.0.3, for which the queried registry had no patched release. There is no advisory suppression, false package rename, vendored fake version or `npm audit fix --force` here. The reviewed Tailwind4.3.3 compiler and matching PostCSS adapter remove that dependency chain. A normal lock-only audit fix also advances source-map-js to1.2.2. The complete new locked npm graph, including development/optional build tools, audits clean at every severity as of this execution.
2. A separate actual Composer audit discovered an additional advisory, **PKSA-xz3r-9gp4-4m1z / CVE-2026-109413**, for unbounded inline recursion in Symfony YAML. The only locked package change is deliberately **symfony/yaml7.4.17 →7.4.18**, not an unnecessary major framework upgrade. Composer also refreshes its plugin-API metadata from2.9.0 to2.6.0; no other locked package changes. Composer's complete locked audit and abandoned-package check pass. A real parser test verifies that2000 inline nesting levels cause a controlled ParseException, while ordinary configuration retains currency, decimal strings, leading-zero references and boolean types. This does not assert that a public YAML exploitation endpoint existed.
3. Tailwind4 is wired to its actual PostCSS adapter, explicit legacy JS configuration and explicit resource scanning, including TSX components. `app.css` retains unlayered utilities because the existing custom styles rely on that cascade. Legacy theme values (with the upstream MIT license) preserve existing colors, shadows, radius, typography and filter defaults. Small compatibility utilities preserve the removed unsuffixed blur and the older transparent forced-colors outline semantics. The default border color is retained. No production money, permission, payout or refund service was replaced.
4. Before the migration, actual Tailwind3 release CSS was captured and measured in Chromium. The committed reference records the original lock/CSS hashes and the original15 representative vectors at desktop/mobile sizes. Three additional component/utility cascade probes were then measured against that same hash-verified original CSS; the original15 records were asserted byte-for-value unchanged. These probes exposed and fixed a real component-last import-order regression before final acceptance. New release CSS is compared against that reference for colors, typography, borders, radius, visible shadows/rings, focus, blur and responsive behavior. Animations/transitions are disabled only on the three new component probes to measure the settled styles rather than race a border-color transition. Only geometrically zero-size bookkeeping shadows are normalized; visible offsets, spread, blur and colors are still compared. This is representative computed-style regression, not pixel-perfect certification of every page or browser. Actual public/authentication-entry routes also receive separate read-only Laravel browser smoke tests.
5. `.nvmrc` pins Node24.22.0; engine policy allows reviewed Node24/npm11 majors, with npm11.21.1 declared in packageManager and used for local acceptance. `.npmrc` makes engine mismatch an install error. All five CI Node setups follow `.nvmrc`. The release gate rejects an old/unreviewed runtime, lock/root-manifest disagreement, unreviewed compiler pairing, nonregistry/unlocked artifacts, restoration of the unpatched braces package, failed/unavailable/malformed audits, unsupported audit schemas, missing counts and **any** nonzero advisory severity. An npm exit0 alone cannot hide moderate/low findings.
6. The npm gate invokes a real full-graph audit with explicit dev/optional inclusion and the low threshold. Network errors/timeouts fail closed; there is no cached fallback green, allowlist or `|| true`. Legitimate npm bundled WASM dependencies are accepted only when declared in an integrity-locked parent tarball; a fake top-level bundle or undeclared child is rejected. This is not protection against a privileged machine administrator or every possible supply-chain attack.
7. Every npm-install CI lane now invokes the same policy and54 offline gate tests; main CI also runs built-CSS and local browser-transport tests. Composer audit commands use the locked graph and fail on abandoned packages. The patched real YAML parser validates all three workflow documents and their dependency-gate wiring. A real missing-GD failure in the restored PHP environment was corrected by installing GD. Three additional driver-option skips were traced to an unrestored PDO_MYSQL extension and resolved by installing it; its focused5-test/20-assertion suite now runs without skips. Both extensions are explicit in all six workflow PHP setups, enforced by the same workflow test. Tests were not weakened or filtered to hide the failure. These workflow changes and local commands are verified locally; no remote CI, deployment or production approval was executed.

## Executed acceptance

| Check | Actual result |
|---|---|
| Fresh locked npm install + complete security gate | PASS;149 locked/audited packages;0 advisories at every severity (previously8) |
| Node dependency/audit/runtime/CI policy tests |54 passed;0 failed/skipped |
| Release build | PASS;108 manifest records and106 registered inputs |
| Desktop/mobile Chromium |40 passed;0 unexpected,0 skipped,0 flaky;71.436s |
| Legacy style matrix |18 probes per layout; original15 unchanged plus3 original-CSS cascade probes; both layouts PASS |
| Locked Composer audit + abandoned-package check + validate | PASS;0 advisories/abandoned; only YAML package patched |
| Real YAML parser/workflow boundary |6 tests /38 assertions PASS (included below) |
| PHP static contracts plus YAML boundary |21 tests /1495 assertions PASS |
| Complete default PHP regression,4 independent SQLite stores |3170 tests /134005 assertions;68 explicit intentional skips;0 failures/errors; all discovered IDs accounted for |
| Python runner/preflight/inventory regression |86 passed |
| PHP syntax |1992 files;0 failures |
| Pint, changed PHP boundary |1 file PASS |
| Pinned native verifier |27 Rust tests PASS; release build PASS |
| Source preflight/inventory regeneration check | PASS;0 missing imported App classes;0 stale inventory documents |
| Part39 financial PHP source provenance | All prior delivered PHP bodies byte-identical;20 final sources frozen and unchanged |

The initial component-last CSS candidate failed both new cascade matrices; a partial PHP run was deliberately stopped and excluded. Import order was corrected, deterministic settled-style probes passed, then the complete final PHP run was restarted from clean owned stores. The final browser run used the corrected release assets. A subsequent complete candidate exposed40 errors caused by missing local GD. After GD restoration it passed with3 additional driver-option skips caused by missing PDO_MYSQL. Both extensions were restored and explicitly declared in all six workflow PHP setups, then the entire suite was rerun; no test was skipped or weakened to hide these environment defects. No failed/interrupted candidate is counted as final acceptance.

Matrices overlap. Explicit skips are not passes. Financial application PHP bodies from Part39 remain byte-identical; Part39's native SQL acceptance remains historical, not newly recertified by this dependency tranche. The current complete default suite uses isolated disposable SQLite stores; this is not native SQL contention evidence. The browser matrix uses a separate disposable blank web database, with no users/wallets/deposits/withdrawals/financial transactions/ledger entries created by those read-only journeys.

## Reproduction and rollout

Use Node24.22.0 (`nvm use` or equivalent), npm11.21.1, PHP8.4 with declared extensions (including GD for the real image-upload fixtures and PDO_MYSQL for the driver-option regression) and the identified base repository plus the cumulative overlay. Run:

```sh
npm ci
npm run test:dependencies
npm run security:dependencies
npm run build
npx playwright install --with-deps chromium
npm run test:browser-local
composer install --prefer-dist --no-interaction
composer validate --no-check-publish
composer audit --locked --abandoned=fail --no-interaction
php vendor/bin/phpunit tests/Unit/Production/DependencyAuditBoundaryTest.php
```

The public browser smoke also needs a **separate owned disposable** Laravel database and local application server. Do not point truncating regression suites at customer/production data or run independent native truncating suites against one store concurrently. Restore/build the pinned native verifier before complete default PHPUnit acceptance, otherwise its tests are explicit skips rather than native proof. Dependencies, compiler binaries, databases, node_modules, vendor, browser downloads and compiled release assets are intentionally not in the source overlay.

Publish the rebuilt manifest and assets together only through an independently authorized rollout. Retaining an old compiled bundle after applying a new lock/configuration is not this tested release. Existing Tailwind classes are preserved through reviewed legacy values and representative probes, but broader route-specific visual/accessibility review, other browsers and supported end-user-device acceptance remain required. Review the legacy compatibility layer before later removing it or changing major versions.

Registry findings are time-specific: a clean npm/Composer report means no reported advisory in the queried locked graph at that time, not the absence of undiscovered vulnerabilities. Rust tests/release build were executed, but no cargo-advisory audit, exhaustive application SAST, penetration test or production supply-chain certification is claimed. The package-manager declaration does not claim that a remote CI run has already installed that exact npm release.

## Baseline recovery and delivery integrity

The prior514-file ZIP was absent from this workspace snapshot. Its complete514 bodies were recovered from the verified499-file ZIP plus the verified25 complete File691–715 Markdown bodies; **every recovered body matches the previously delivered514-path SHA-256 manifest and Part39 registry**. Recovery changed ZIP container metadata, so the recovered archive does not pretend to have the original container hash. Before new edits,279 drifted/missing overlay bodies were restored. Subsequent new code was frozen before final acceptance; no old baseline was restored over this tranche's edits. The cumulative delivery verifies all previous bodies outside the explicitly revised paths as byte-identical.

## Remaining substantive work — authoritative continuation

1. Provider/merchant/jurisdiction selection, incoming-fee/refund/dispute policy, authenticated original-payment adapters and independently authorized acceptance. Legacy external refund money movement remains intentionally blocked. A signature, local journal, human archive or Captured label does not prove bank delivery. Unknown payout outcomes remain non-resendable and non-releasable without trustworthy final facts.
2. Remaining unmanaged prize/cancellation/reversal/reporting/hold writers and granular permissions; non-withdrawal obligation ownership and aggregate liabilities; agent aggregate-concurrency/historical-bookkeeping proof. Do not infer obligation ownership from aggregate locked money or silently repair old links/labels.
3. Independent controlled historical reconciliation/migration. Missing original ownership, economics, mappings, references, fingerprints and signatures must not be invented for a green result. Existing reports are observational, not repair authority or deployment-wide atomic snapshots.
4. Authorized staging/deployment/backup restoration, deployment-volume contention/index/scan acceptance, multi-host private evidence storage, encryption-key restoration and lawful retention. Old native SQL/Redis/queue-kill/recovery evidence is not recertified here; remote CI and a production rollout still require execution in their authorized environments.
5. Country/operator legal, tax, KYC/AML, liquidity, monitoring and incident-response acceptance, continuing dependency-advisory monitoring and broader browser/accessibility/security review. Multi-country architecture and zero known locked npm/Composer findings do not mean worldwide operating permission or100-percent whole-platform security.

All28 delivered bodies are complete. The cumulative533-path source overlay requires the identified base repository; it is not a standalone repository or live-money approval. No credentials, customer/database exports, private evidence or installed/compiled dependencies are packaged.
