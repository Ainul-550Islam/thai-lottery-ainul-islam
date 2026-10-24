# ZIP-CONTENTS.md — what is inside each archive, and what was left out

## `PROMPT-C-FULL-SOURCE-CODE.zip` — the complete branch

The full working tree of `agent/c-platform-ops-ci` at commit
`b057f7baa4e48558ac140b0ce821db5a727dda3c` (tree
`6d9c1288f5e6bbca58511df892fdd06929c3dd49`), i.e. the base repository **plus** every
change from this branch. Unpack it and the project is exactly what CI sees.

Included: all tracked source, configuration, workflows, container files, ops
scripts, tests, documentation and environment **templates**.
Also included under `docs/agent-c/`: this file, `C-VERIFICATION.md`,
`C-OPS-RUNBOOK.md`, `HANDOFF-C.md`, `C-TREE.md`, `C-FILE-MANIFEST.md`,
`PUSH-INSTRUCTIONS.md` and both patches.

Deliberately excluded, with the reason:

| Excluded | Why |
|---|---|
| `.env` | real secrets. Only `.env.example`, `.env.ci` and `.env.production.example` are shipped, and they contain placeholders and empty secrets by design |
| `vendor/`, `node_modules/` | generated. `composer.lock` and `package-lock.json` pin every version; `composer install` / `npm ci` rebuild them and CI proves that on every run |
| `public/build/` | generated front-end output. CI builds it and verifies `manifest.json` there; committing it is how a stale bundle gets shipped |
| `storage/logs/*`, framework caches | runtime artefacts that never belong in a snapshot |
| `evidence/`, `artifacts/`, `images/`, `design-previews/` | large binary review material from earlier audit rounds (about 73 MB) with no build value. Present in the repository if you need them |
| `.git/` | the archive is a snapshot, not a clone. History travels separately in `C-platform-ops.bundle` |
| credentials, keys, customer data | never in the snapshot, never in the artefacts |

## `C-CHANGED-FILES.zip` — only what Agent C touched

Every file this branch adds or modifies, at its repository path — nothing else
from the repository is included. Use this if you want to review or push C's work
without unpacking the whole project.

Contents:

- the 65 added/modified files from the manifest,
- `docs/agent-c/REMOVED-PATHS.txt` — the four paths this branch deletes,
- `docs/agent-c/C-changes.patch` and `C-changes-commit.patch`,
- `docs/agent-c/C-VERIFICATION.md`, `C-OPS-RUNBOOK.md`, `HANDOFF-C.md`,
  `C-FILE-MANIFEST.md`, `PUSH-INSTRUCTIONS.md`.

Applying the patch removes the deleted paths automatically; copying files by hand
does not, which is why that list is in the archive.

## Which file to use

| Goal | File |
|---|---|
| "Just give me the branch" | `C-platform-ops.bundle`, `git fetch` it |
| "Give me the diff" | `C-changes.patch` (against the base) or `C-changes-commit.patch` (as a commit) |
| "Give me everything, I'll unpack and run it" | `PROMPT-C-FULL-SOURCE-CODE.zip` |
| "Give me only your files, I'll push them" | `C-CHANGED-FILES.zip` |
| "Is any of this true?" | `C-VERIFICATION.md` — every claim is a command and its output |

Integrity: `SHA256SUMS.txt` covers every artefact in this folder. Verify with
`sha256sum -c SHA256SUMS.txt`.
