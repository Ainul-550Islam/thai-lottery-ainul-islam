//! Real stdin/stdout protocol tests; no provider, socket or database involved.
use std::io::{ErrorKind, Write};
use std::process::{Command, Output, Stdio};

fn invoke(input: &[u8]) -> (Output, serde_json::Value) {
    let mut child = Command::new(env!("CARGO_BIN_EXE_weekly-result-integrity"))
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .expect("native test binary");
    let result = child.stdin.take().expect("stdin").write_all(input);
    if let Err(error) = result {
        assert_eq!(error.kind(), ErrorKind::BrokenPipe);
    }
    let output = child.wait_with_output().expect("native report");
    assert!(output.stderr.is_empty());
    let report = serde_json::from_slice(&output.stdout).expect("one JSON report");
    (output, report)
}

#[test]
fn unsigned_request_exits_zero_with_exact_library_fingerprint() {
    let input =
        br#"{"draw_reference":"WK-20260918","draw_date":"2026-09-18","parser_version":"1"}"#;
    let expected = weekly_result_integrity::verify(
        weekly_result_integrity::parse_request(std::str::from_utf8(input).expect("UTF8"))
            .expect("request"),
    );
    let (output, report) = invoke(input);
    assert_eq!(output.status.code(), Some(0));
    assert_eq!(report["status"], "INTEGRITY_HASH_ONLY");
    assert_eq!(report["acceptable"], true);
    assert_eq!(report["fingerprint"], expected.fingerprint.expect("hash"));
}

#[test]
fn malformed_json_exits_one_and_does_not_crash() {
    let (output, report) = invoke(b"{broken");
    assert_eq!(output.status.code(), Some(1));
    assert_eq!(report["status"], "REJECTED");
    assert_eq!(report["acceptable"], false);
    assert_eq!(report["error_code"], "MALFORMED_REQUEST");
}

#[test]
fn document_ceiling_is_enforced_before_parsing() {
    let (output, report) = invoke(&vec![b' '; 65_537]);
    assert_eq!(output.status.code(), Some(1));
    assert_eq!(report["acceptable"], false);
    assert_eq!(report["error_code"], "DOCUMENT_TOO_LARGE");
}

#[test]
fn invalid_utf8_is_a_structured_rejection() {
    let (output, report) = invoke(&[0xff]);
    assert_eq!(output.status.code(), Some(1));
    assert_eq!(report["acceptable"], false);
    assert_eq!(report["error_code"], "INVALID_UTF8");
}

#[test]
fn fingerprint_disagreement_exits_two_without_accepting() {
    let input = format!(
        r#"{{"draw_reference":"WK-20260918","draw_date":"2026-09-18","parser_version":"1","expected_fingerprint":"{}"}}"#,
        "0".repeat(64)
    );
    let (output, report) = invoke(input.as_bytes());
    assert_eq!(output.status.code(), Some(2));
    assert_eq!(report["status"], "FINGERPRINT_MISMATCH");
    assert_eq!(report["acceptable"], false);
}
