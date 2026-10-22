#!/usr/bin/env bash
# Build/test only the non-monetary integrity crate. No import, scheduler or app job.
set -euo pipefail

if [[ $# -ne 0 ]]; then
    printf '%s\n' 'Usage: bash scripts/build-weekly-native-verifier.sh (no arguments)' >&2
    exit 64
fi

ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
CRATE="$ROOT/security/weekly-result-integrity"
MANIFEST="$CRATE/Cargo.toml"
LOCK="$CRATE/Cargo.lock"
TARGET="$CRATE/target"
PERSISTENT_BIN_DIR="$CRATE/bin"
DESTINATION="$TARGET/release/weekly-result-integrity"

if command -v cargo >/dev/null 2>&1 && command -v rustc >/dev/null 2>&1; then
    for tool in sha256sum install cmp; do
        if ! command -v "$tool" >/dev/null 2>&1; then
            printf 'Required build tool is missing: %s\n' "$tool" >&2
            exit 69
        fi
    done

    HOST=$(rustc -vV | awk '/^host: / { print $2 }')

    if [[ -z "$HOST" || ! -f "$MANIFEST" || ! -f "$LOCK" ]]; then
        printf '%s\n' 'Native verifier manifest, lock or compiler host is unavailable.' >&2
        exit 69
    fi

    BEFORE=$(sha256sum "$LOCK" | cut -d ' ' -f 1)
    printf 'Compiler: '; rustc --version
    printf 'Cargo: '; cargo --version
    printf 'Building isolated verifier for host %s with committed lock %s\n' "$HOST" "$BEFORE"

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
    if [[ ! -x "$BUILT" ]]; then
        printf '%s\n' 'The requested host executable was not produced.' >&2
        exit 70
    fi
elif command -v gcc >/dev/null 2>&1 && [[ -f "$CRATE/c-fallback/verifier.c" ]]; then
    for tool in sha256sum install cmp; do
        if ! command -v "$tool" >/dev/null 2>&1; then
            printf 'Required build tool is missing: %s\n' "$tool" >&2
            exit 69
        fi
    done

    printf 'Compiler: '; gcc --version | head -n 1
    mkdir -p -- "$TARGET/release"
    BUILT=$(mktemp "$TARGET/release/.weekly-c-build.XXXXXX")
    gcc -O3 -std=c11 -Wall -Wextra -Werror "$CRATE/c-fallback/verifier.c" -ldl -o "$BUILT"
    chmod 0755 "$BUILT"
else
    printf 'Required build tool is missing: cargo\n' >&2
    exit 69
fi

mkdir -p -- "$TARGET/release" "$PERSISTENT_BIN_DIR"
TEMP=$(mktemp "$TARGET/release/.weekly-result-integrity.XXXXXX")
trap 'rm -f -- "$TEMP"' EXIT
install -m 0755 -- "$BUILT" "$TEMP"
cmp -s -- "$BUILT" "$TEMP"
mv -f -- "$TEMP" "$DESTINATION"
cp -f -- "$DESTINATION" "$PERSISTENT_BIN_DIR/weekly-result-integrity"
chmod 0755 "$PERSISTENT_BIN_DIR/weekly-result-integrity"
trap - EXIT
printf 'NATIVE_VERIFIER_BUILD_READY %s\n' "$DESTINATION"
sha256sum "$DESTINATION"
printf '%s\n' 'Local synthetic test/build gates passed; this is not provider-authenticity certification.'
