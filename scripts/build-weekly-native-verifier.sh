#!/usr/bin/env bash
# Build/test only the non-monetary integrity crate. No import, scheduler or app job.
set -euo pipefail

if [[ $# -ne 0 ]]; then
    printf '%s\n' 'Usage: bash scripts/build-weekly-native-verifier.sh (no arguments)' >&2
    exit 64
fi

for tool in cargo rustc sha256sum install cmp; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        printf 'Required build tool is missing: %s\n' "$tool" >&2
        exit 69
    fi
done

ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
CRATE="$ROOT/security/weekly-result-integrity"
MANIFEST="$CRATE/Cargo.toml"
LOCK="$CRATE/Cargo.lock"
TARGET="$CRATE/target"
HOST=$(rustc -vV | awk '/^host: / { print $2 }')

if [[ -z "$HOST" || ! -f "$MANIFEST" || ! -f "$LOCK" ]]; then
    printf '%s\n' 'Native verifier manifest, lock or compiler host is unavailable.' >&2
    exit 69
fi

BEFORE=$(sha256sum "$LOCK" | cut -d ' ' -f 1)
printf 'Compiler: '; rustc --version
printf 'Cargo: '; cargo --version
printf 'Building isolated verifier for host %s with committed lock %s\n' "$HOST" "$BEFORE"

# Explicit manifest, target directory and host prevent an inherited cross-target
# configuration from leaving a stale executable at the PHP integration path.
cargo fmt --manifest-path "$MANIFEST" --check
cargo clippy --locked --manifest-path "$MANIFEST" --target-dir "$TARGET" \
    --target "$HOST" --all-targets -- -D warnings
cargo test --locked --release --manifest-path "$MANIFEST" --target-dir "$TARGET" \
    --target "$HOST" --all-targets
cargo build --locked --release --manifest-path "$MANIFEST" --target-dir "$TARGET" \
    --target "$HOST" --bin weekly-result-integrity

AFTER=$(sha256sum "$LOCK" | cut -d ' ' -f 1)
if [[ "$BEFORE" != "$AFTER" ]]; then
    printf '%s\n' 'Cargo.lock changed; refusing to install the verifier artifact.' >&2
    exit 65
fi

BUILT="$TARGET/$HOST/release/weekly-result-integrity"
DESTINATION="$TARGET/release/weekly-result-integrity"
if [[ ! -x "$BUILT" ]]; then
    printf '%s\n' 'The requested host executable was not produced.' >&2
    exit 70
fi

mkdir -p -- "$TARGET/release"
TEMP=$(mktemp "$TARGET/release/.weekly-result-integrity.XXXXXX")
trap 'rm -f -- "$TEMP"' EXIT
install -m 0755 -- "$BUILT" "$TEMP"
cmp -s -- "$BUILT" "$TEMP"
mv -f -- "$TEMP" "$DESTINATION"
trap - EXIT
printf 'NATIVE_VERIFIER_BUILD_READY %s\n' "$DESTINATION"
sha256sum "$DESTINATION"
printf '%s\n' 'Local synthetic test/build gates passed; this is not provider-authenticity certification.'
