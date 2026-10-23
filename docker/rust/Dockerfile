# Rust settlement worker — multi-stage, static, minimal.
# Runtime image: FROM scratch, USER 10001, no shell, no package manager.
#
# The Rust settlement worker targets PostgreSQL. It is opt-in in Compose
# (profile: settlement-worker) and must NOT be started against the MySQL db
# service defined in docker-compose.production.yml.

FROM rust:1.89-alpine AS build

RUN apk add --no-cache musl-dev pkgconfig openssl-dev

WORKDIR /build

COPY Cargo.toml Cargo.lock ./
RUN mkdir -p security/weekly-result-integrity crates/settlement-worker
COPY security/weekly-result-integrity/Cargo.toml ./security/weekly-result-integrity/Cargo.toml
COPY crates/settlement-worker/Cargo.toml ./crates/settlement-worker/Cargo.toml

RUN echo 'fn main() {}' > crates/settlement-worker/src/main.rs \
    && mkdir -p security/weekly-result-integrity/src \
    && echo 'fn main() {}' > security/weekly-result-integrity/src/main.rs \
    && cargo build --release --target x86_64-unknown-linux-musl \
                    --manifest-path crates/settlement-worker/Cargo.toml \
    && rm -rf crates/settlement-worker/src security/weekly-result-integrity/src

COPY crates/settlement-worker/src ./crates/settlement-worker/src

RUN touch crates/settlement-worker/src/main.rs \
    && cargo test --release --locked --target x86_64-unknown-linux-musl \
                  --manifest-path crates/settlement-worker/Cargo.toml \
    && cargo build --release --locked --target x86_64-unknown-linux-musl \
                   --manifest-path crates/settlement-worker/Cargo.toml \
    && strip target/x86_64-unknown-linux-musl/release/settlement-worker

FROM scratch

COPY --from=build /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/
COPY --from=build /build/target/x86_64-unknown-linux-musl/release/settlement-worker /settlement-worker

USER 10001:10001

EXPOSE 9101

ENTRYPOINT ["/settlement-worker"]
