//! Stdin/stdout shim so Laravel can invoke the verifier as a short-lived
//! subprocess.
//!
//! THIS IS NOT A SERVER. It reads a single JSON document from stdin, writes one
//! JSON document to stdout, and exits. No socket is opened, no port is bound,
//! no address is resolved, and nothing is kept between invocations. That is
//! deliberate: a long-lived listener inside the security component would add
//! exactly the network attack surface this crate exists to avoid.
//!
//! EXIT CODES
//!   0  the payload is acceptable (INTEGRITY_HASH_ONLY or SIGNED_VERIFIED)
//!   2  the payload was read but is not acceptable (rejected, mismatch, bad
//!      signature) - a determinate "no", not a crash
//!   1  the request document itself could not be read
//!
//! The report is written to stdout in every case, so the caller never has to
//! infer a reason from the exit code alone.

#![forbid(unsafe_code)]

use std::io::{self, Read, Write};

use weekly_result_integrity::{parse_request, render_report, verify, VerificationReport};

const MAX_INPUT_BYTES: usize = 64 * 1024; // 64 KB

fn main() {
    // A hard ceiling on the document size. A verifier that will read an
    // unbounded stream is a memory-exhaustion target, and no legitimate
    // Weekly payload is anywhere near this large. Read at most 64KB + 1 byte.
    let mut buffer = Vec::new();
    if io::stdin()
        .take((MAX_INPUT_BYTES + 1) as u64)
        .read_to_end(&mut buffer)
        .is_err()
    {
        emit(r#"{"status":"REJECTED","acceptable":false,"error_code":"MALFORMED_REQUEST"}"#);
        std::process::exit(1);
    }

    if buffer.len() > MAX_INPUT_BYTES {
        emit(r#"{"status":"REJECTED","acceptable":false,"error_code":"DOCUMENT_TOO_LARGE"}"#);
        std::process::exit(1);
    }

    let input = match String::from_utf8(buffer) {
        Ok(s) => s,
        Err(_) => {
            emit(r#"{"status":"REJECTED","acceptable":false,"error_code":"INVALID_UTF8"}"#);
            std::process::exit(1);
        }
    };

    let request = match parse_request(&input) {
        Ok(request) => request,
        Err(error) => {
            let report = VerificationReport {
                status: "REJECTED",
                acceptable: false,
                canonical_version: weekly_result_integrity::canonical::CANONICAL_VERSION,
                fingerprint: None,
                canonical_length: None,
                error_code: Some(error.code()),
                error_field: error.field(),
            };
            emit(&render_report(&report));
            std::process::exit(1);
        }
    };

    let report = verify(request);
    let acceptable = report.acceptable;
    emit(&render_report(&report));

    std::process::exit(if acceptable { 0 } else { 2 });
}

fn emit(line: &str) {
    let stdout = io::stdout();
    let mut handle = stdout.lock();
    let _ = handle.write_all(line.as_bytes());
    let _ = handle.write_all(b"\n");
    let _ = handle.flush();
}
