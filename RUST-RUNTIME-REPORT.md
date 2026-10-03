# Rust Integrity Verifier — Runtime Report

**Component:** `security/weekly-result-integrity`
**Status:** source present and reviewed; **not executed here — Cargo toolchain unavailable**

---

## What this component is

`security/weekly-result-integrity` is a standalone Rust binary that verifies
weekly lottery result integrity independently of the PHP application. Its value
comes precisely from that independence: a bug in the Laravel settlement path
cannot also hide itself from the verifier, because the verifier shares none of
that code.

| File | Role |
|---|---|
| `security/weekly-result-integrity/src/main.rs` | The verifier. Reads a single JSON document from stdin and checks the result set against the declared integrity rules. |
| `security/weekly-result-integrity/tests/integrity.rs` | Its test suite, driven by synthetic result documents. |

Both files are present in the tree and are asserted by
`tests/Feature/Pages150To250StaticContractTest.php`, which checks that the
binary reads `single JSON document from stdin` and that the tests exercise
`synthetic` inputs.

## Execution status in this environment

**Not executed.** The Cargo toolchain is not provisioned in this workspace, so
`cargo build`, `cargo test` and `cargo clippy` cannot be run. No binary has been
produced and no test result has been observed.

That absence is recorded rather than papered over. The static-contract suite
reads these files as **text**; it confirms that certain strings are present. It
does not compile them, and it cannot tell whether the crate builds at all.

## What the hosted pipeline reports

The `Rust integrity verifier` job is the **one** job in this repository's CI
that has completed green. That is a real signal and worth stating plainly: the
crate compiled and its tests passed in the hosted environment, even while every
surrounding job failed.

It is also the limit of the claim. A green job on an earlier commit is not
evidence about the current working tree, which has since been modified.

## Commands that would establish runtime status

Run from the repository root, with a Cargo toolchain installed:

```
cd security/weekly-result-integrity
cargo build --release
cargo test
cargo clippy -- -D warnings
```

A verification run then pipes one result document into the binary:

```
cat path/to/result-document.json | ./target/release/weekly-result-integrity
```

## What would change this status

This document may report `RUST RUNTIME VERIFIED` when all of the following are
recorded against the commit being assessed:

1. `cargo build --release` completes with no errors.
2. `cargo test` passes, with the count of tests recorded.
3. `cargo clippy -- -D warnings` is clean.
4. The binary has verified at least one **real** weekly result document — not a
   synthetic fixture — and its exit status and output are attached.

Until then: source reviewed, **not executed here**.
