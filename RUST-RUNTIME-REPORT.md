# Rust Runtime Report

**Status:** Cargo verification not yet recorded for this remediation pass.

The weekly-result integrity program is checked in at `security/weekly-result-integrity`. CI is configured to run `cargo fmt --check`, `cargo check --locked --all-targets`, `cargo test --locked`, and `cargo build --release --locked` from that directory. This report does not claim those commands passed locally; a successful Cargo run or CI result must be attached before release sign-off.
