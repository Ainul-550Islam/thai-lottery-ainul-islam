# PUSH-INSTRUCTIONS.md — getting Agent C's work onto GitHub yourself

Nothing has been pushed. This branch exists only as files in this download. Pick
one of the three routes below; they produce the same commit.

## Route 1 — apply the patch (recommended)

The patch is against the exact base commit
`5a10574e41fc9f8eec7202aa13cfd388f39003cb`, and its fidelity is proved: applying
it to that commit produces tree `6d9c1288f5e6bbca58511df892fdd06929c3dd49`,
byte for byte the branch tree.

```bash
git clone https://github.com/Ainul-550Islam/thai-lottery-ainul-islam.git
cd thai-lottery-ainul-islam
git checkout -b agent/c-platform-ops-ci 5a10574e41fc9f8eec7202aa13cfd388f39003cb
git apply --check /path/to/C-changes.patch     # proves it applies cleanly
git apply /path/to/C-changes.patch
git add -A
git commit -F <(sed -n '/^Subject:/,$p' /path/to/C-changes-commit.patch | sed 's/^Subject: \[PATCH\] //')
git push -u origin agent/c-platform-ops-ci
```

If you would rather keep C's commit message exactly as written, use
`git am /path/to/C-changes-commit.patch` instead of `git apply` + `git commit`.

## Route 2 — fetch from the bundle

The bundle carries the whole branch and its history:

```bash
git clone https://github.com/Ainul-550Islam/thai-lottery-ainul-islam.git
cd thai-lottery-ainul-islam
git fetch /path/to/C-platform-ops.bundle 'refs/heads/agent/c-platform-ops-ci:agent/c-platform-ops-ci'
git push -u origin agent/c-platform-ops-ci
```

## Route 3 — copy the files

`C-CHANGED-FILES.zip` contains every file this branch adds or modifies, at its
repository path. Copy them over the base checkout, then delete the four paths
listed in `docs/agent-c/REMOVED-PATHS.txt` — Route 3 is the only route where you
have to remember the deletions yourself.

## After the push

Do not merge into `main` until the CI run on the branch is green and Agent D has
looked at it: C's work is the platform lane, and integration is D's call. The
workflow will run six jobs; two of them (`container`, `rust-integrity`) need
Docker and a Rust toolchain on the runner — both are declared in `ci.yml`.

```bash
gh run watch      # or watch the Actions tab
```
