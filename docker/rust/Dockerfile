# Rust settlement worker — multi-stage, static, minimal.
#
# The runtime image has no shell, no package manager and no glibc. If this
# worker is ever compromised, there is nothing in the container to pivot with.

FROM rust:1.85-alpine AS build

RUN apk add --no-cache musl-dev ca-certificates

WORKDIR /build

# Dependency layer cached separately from source.
COPY runtime/settlement-worker/Cargo.toml runtime/settlement-worker/Cargo.lock ./
RUN mkdir src && echo 'fn main() {}' > src/main.rs \
    && cargo build --release --locked --target x86_64-unknown-linux-musl \
    && rm -rf src

COPY runtime/settlement-worker/src ./src

# `touch` defeats cargo's mtime-based staleness check so the real main.rs is
# actually rebuilt and unit-tested after the dummy layer above.
RUN touch src/main.rs \
    && cargo test --release --locked --target x86_64-unknown-linux-musl \
    && cargo build --release --locked --target x86_64-unknown-linux-musl \
    && strip target/x86_64-unknown-linux-musl/release/settlement-worker

FROM scratch

COPY --from=build /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/
COPY --from=build /build/target/x86_64-unknown-linux-musl/release/settlement-worker /settlement-worker

# Non-root, no filesystem, no shell.
USER 10001:10001

EXPOSE 9101

HEALTHCHECK --interval=15s --timeout=5s --retries=3 --start-period=15s \
    CMD ["/settlement-worker", "--healthcheck"]

ENTRYPOINT ["/settlement-worker"]
