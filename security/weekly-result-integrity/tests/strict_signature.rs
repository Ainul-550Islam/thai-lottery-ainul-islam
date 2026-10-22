//! Synthetic low-order identity points must never authenticate provider results.
use weekly_result_integrity::{parse_request, verify};

#[test]
fn weak_identity_key_and_signature_cannot_authenticate_any_result() {
    let mut request = parse_request(
        r#"{"draw_reference":"WK-20260918","draw_date":"2026-09-18","first_6":"001234","three_ball":"049","two_ball":"09","source_identifier":"SYNTHETIC","parser_version":"1"}"#,
    )
    .expect("synthetic request");
    request.public_key_hex = Some(format!("01{}", "00".repeat(31)));
    request.signature_hex = Some(format!("01{}", "00".repeat(63)));
    for value in ["001234", "001235"] {
        request.first_6 = Some(value.to_string());
        let report = verify(request.clone());
        assert_eq!(report.status, "SIGNATURE_INVALID");
        assert!(!report.acceptable);
    }
}

#[test]
fn unicode_source_identifier_counts_characters_but_hashes_utf8_bytes() {
    let mut request = parse_request(
        r#"{"draw_reference":"WK-20260918","draw_date":"2026-09-18","parser_version":"1"}"#,
    )
    .expect("synthetic request");
    for value in ["ผล".repeat(80), "ক".repeat(191)] {
        request.source_identifier = Some(value);
        let report = verify(request.clone());
        assert_eq!(report.status, "INTEGRITY_HASH_ONLY");
        assert!(report.acceptable);
    }
}

#[test]
fn source_identifier_over_191_characters_is_rejected() {
    let mut request = parse_request(
        r#"{"draw_reference":"WK-20260918","draw_date":"2026-09-18","parser_version":"1"}"#,
    )
    .expect("synthetic request");
    request.source_identifier = Some("ক".repeat(192));
    let report = verify(request);
    assert_eq!(report.status, "REJECTED");
    assert!(!report.acceptable);
}
