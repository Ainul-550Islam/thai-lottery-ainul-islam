# Rust settlement worker — multi-stage, static, minimal.
#
# The runtime image has no shell, no package manager and no glibc. If this
# worker is ever compromised, there is nothing in the container to pivot with.

FROM rust:1.79-alpine AS build

RUN apk add --no-cache musl-dev

WORKDIR /build

# Dependency layer cached separately from source.
COPY runtime/settlement-worker/Cargo.toml runtime/settlement-worker/Cargo.lock ./
RUN mkdir src && echo 'fn main() {}' > src/main.rs \
    && cargo build --release --target x86_64-unknown-linux-musl \
    && rm -rf src

COPY runtime/settlement-worker/src ./src
COPY runtime/settlement-worker/tests ./tests 2>/dev/null || true

# `touch` defeats cargo's mtime-based staleness check so the real main.rs is
# actually rebuilt after the dummy above.
RUN touch src/main.rs \
    && cargo build --release --target x86_64-unknown-linux-musl \
    && strip target/x86_64-unknown-linux-musl/release/settlement-worker

FROM scratch

COPY --from=build /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/
COPY --from=build /build/target/x86_64-unknown-linux-musl/release/settlement-worker /settlement-worker

# Non-root, no filesystem, no shell.
USER 10001:10001

EXPOSE 9101

ENTRYPOINT ["/settlement-worker"]
