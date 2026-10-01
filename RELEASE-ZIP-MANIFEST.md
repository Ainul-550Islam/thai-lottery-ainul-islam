# TYPE: Release ZIP manifest
# PURPOSE: Record the final release archive decision and the manifest fields required if the enterprise gate becomes green.

## Release archive decision

`RELEASE BLOCKED`

No production ZIP was created. The ZIP SHA-256 is therefore `NOT_APPLICABLE`.

## Build metadata

| Field | Value |
|---|---|
| Git commit | Recorded in `runtime/pages-451-550-inventory.json` without exposing credentials. |
| Branch | `main` |
| Working tree | Dirty; pre-existing and phase-created changes are present. |
| Build timestamp | `2026-09-30T14:23:37Z` evidence anchor. |
| Application version | No separate application version was configured in the inspected manifest. |
| PHP target | `^8.2` from `composer.json`; PHP executable unavailable locally. |
| Laravel target | `^11.0` from `composer.json`; Laravel runtime unavailable locally. |
| Node target | Node executable verified by inventory. |
| Rust target | `security/weekly-result-integrity`; Cargo unavailable locally. |
| Database target | Repository database configuration; MySQL/MariaDB runtime unavailable locally. |
| Redis requirement | Repository queue/cache configuration; Redis unavailable locally. |

## Verification fields

| Field | Result |
|---|---|
| Test count | Not available; PHP test runner blocked. |
| Test result | `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE` |
| Browser E2E count/result | No browser tests executed; `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE` |
| Security test result | `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE` |
| Rust result | `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE` |
| Backup/restore result | `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE` |
| CI result | `PARTIALLY VERIFIED`; hosted CI was not executed. |
| Final release decision | `RELEASE BLOCKED` |
| ZIP path | `NOT_CREATED` |
| ZIP SHA-256 | `NOT_APPLICABLE` |

## Required archive contents when the gate is green

A future production archive may include application source, migrations, tests, scripts, configuration examples, package manifests, lockfiles, `public/build`, reports, non-sensitive runtime evidence, CI workflows, Rust source/lockfiles, and release documentation.

It must exclude `.env`, secrets, private keys, local credentials, sensitive runtime dumps, temporary caches, `node_modules`, `vendor`, browser temporary files, and uncontrolled database dumps.
