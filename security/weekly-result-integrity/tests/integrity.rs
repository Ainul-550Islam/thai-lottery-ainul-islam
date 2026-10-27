//! Deterministic canonicalization, hashing and signature tests.
//!
//! Every fixture value here is synthetic. None of them came from any
//! third-party page: `001234`, `049` and `09` were chosen because they are the
//! shapes that break when a value is ever treated as a number.

use weekly_result_integrity::canonical::{WeeklyResultPayload, CANONICAL_VERSION, FIELD_ORDER};
use weekly_result_integrity::{parse_request, verify, VerificationRequest};

fn payload() -> WeeklyResultPayload {
    WeeklyResultPayload {
        draw_reference: "WK-20260918".to_string(),
        draw_date: "2026-09-18".to_string(),
        first_6: Some("001234".to_string()),
        three_ball: Some("049".to_string()),
        two_ball: Some("09".to_string()),
        source_identifier: Some("FIXTURE-1".to_string()),
        parser_version: "1".to_string(),
    }
}

#[test]
fn canonical_field_order_is_fixed_and_documented() {
    assert_eq!(
        FIELD_ORDER,
        [
            "draw_reference",
            "draw_date",
            "first_6",
            "three_ball",
            "two_ball",
            "source_identifier",
            "parser_version",
        ]
    );
}

#[test]
fn canonical_bytes_start_with_the_version_and_field_count() {
    let bytes = payload().canonical_bytes().expect("canonical");
    let text = String::from_utf8(bytes).expect("utf8");

    assert!(text.starts_with(&format!("{}\n7\n", CANONICAL_VERSION)));
}

#[test]
fn the_same_payload_always_produces_the_same_hash() {
    let a = payload().canonical_hash().expect("hash");
    let b = payload().canonical_hash().expect("hash");
    let c = payload().canonical_hash().expect("hash");

    assert_eq!(a, b);
    assert_eq!(b, c);
    assert_eq!(a.len(), 64);
    assert!(a.bytes().all(|byte| byte.is_ascii_hexdigit()));
}

#[test]
fn a_different_first_6_produces_a_different_hash() {
    let original = payload().canonical_hash().expect("hash");

    let mut changed = payload();
    changed.first_6 = Some("001235".to_string());

    assert_ne!(original, changed.canonical_hash().expect("hash"));
}

#[test]
fn leading_zeros_are_part_of_the_canonical_bytes() {
    let bytes = payload().canonical_bytes().expect("canonical");
    let text = String::from_utf8(bytes).expect("utf8");

    // Length prefixes prove the zeros survived: 6, 3 and 2 characters.
    assert!(text.contains("first_6=S6:001234\n"));
    assert!(text.contains("three_ball=S3:049\n"));
    assert!(text.contains("two_ball=S2:09\n"));

    // And the integer-truncated forms are absent.
    assert!(!text.contains("S4:1234"));
    assert!(!text.contains("S2:49\n"));
    assert!(!text.contains("S1:9\n"));
}

#[test]
fn a_value_that_lost_a_leading_zero_hashes_differently() {
    let with_zero = payload().canonical_hash().expect("hash");

    let mut without = payload();
    // What an integer cast would have produced. It must not collide.
    without.three_ball = Some("490".to_string());

    assert_ne!(with_zero, without.canonical_hash().expect("hash"));
}

#[test]
fn null_and_empty_are_distinct() {
    let mut unavailable = payload();
    unavailable.source_identifier = None;

    let mut empty = payload();
    empty.source_identifier = Some(String::new());

    let unavailable_bytes =
        String::from_utf8(unavailable.canonical_bytes().expect("canonical")).expect("utf8");
    let empty_bytes = String::from_utf8(empty.canonical_bytes().expect("canonical")).expect("utf8");

    assert!(unavailable_bytes.contains("source_identifier=N0:\n"));
    assert!(empty_bytes.contains("source_identifier=S0:\n"));
    assert_ne!(
        unavailable.canonical_hash().expect("hash"),
        empty.canonical_hash().expect("hash")
    );
}

#[test]
fn a_fully_unavailable_result_canonicalizes_without_inventing_zeros() {
    let mut missing = payload();
    missing.first_6 = None;
    missing.three_ball = None;
    missing.two_ball = None;

    let text = String::from_utf8(missing.canonical_bytes().expect("canonical")).expect("utf8");

    assert!(text.contains("first_6=N0:\n"));
    assert!(text.contains("three_ball=N0:\n"));
    assert!(text.contains("two_ball=N0:\n"));
    // No fabricated 000000 / 000 / 00 anywhere.
    assert!(!text.contains("000000"));
    assert!(!text.contains("S3:000"));
    assert!(!text.contains("S2:00"));
}

#[test]
fn malformed_values_are_rejected_rather_than_repaired() {
    let mut short = payload();
    short.first_6 = Some("1234".to_string());
    assert!(short.canonical_bytes().is_err());

    let mut alpha = payload();
    alpha.three_ball = Some("04a".to_string());
    assert!(alpha.canonical_bytes().is_err());

    let mut long = payload();
    long.two_ball = Some("091".to_string());
    assert!(long.canonical_bytes().is_err());

    let mut bad_date = payload();
    bad_date.draw_date = "18/09/2026".to_string();
    assert!(bad_date.canonical_bytes().is_err());

    let mut bad_reference = payload();
    bad_reference.draw_reference = "WK 2026'; DROP".to_string();
    assert!(bad_reference.canonical_bytes().is_err());
}

#[test]
fn a_partial_result_triple_is_refused() {
    let mut partial = payload();
    partial.two_ball = None;

    assert!(partial.canonical_bytes().is_err());
}

#[test]
fn unsigned_payload_returns_hash_only_and_never_claims_a_signature() {
    let request = VerificationRequest {
        draw_reference: "WK-20260918".to_string(),
        draw_date: "2026-09-18".to_string(),
        first_6: Some("001234".to_string()),
        three_ball: Some("049".to_string()),
        two_ball: Some("09".to_string()),
        source_identifier: Some("FIXTURE-1".to_string()),
        parser_version: "1".to_string(),
        expected_fingerprint: None,
        public_key_hex: None,
        signature_hex: None,
    };

    let report = verify(request);

    assert_eq!(report.status, "INTEGRITY_HASH_ONLY");
    assert_ne!(report.status, "SIGNED_VERIFIED");
    assert!(report.acceptable);
    assert!(report.fingerprint.is_some());
}

#[test]
fn a_matching_expected_fingerprint_is_accepted_and_a_wrong_one_is_not() {
    let expected = payload().canonical_hash().expect("hash");

    let mut request = parse_request(&sample_request_json(Some(&expected))).expect("parse");
    let report = verify(request);
    assert_eq!(report.status, "INTEGRITY_HASH_ONLY");

    request = parse_request(&sample_request_json(Some(&"0".repeat(64)))).expect("parse");
    let report = verify(request);
    assert_eq!(report.status, "FINGERPRINT_MISMATCH");
    assert!(!report.acceptable);
}

#[test]
fn half_supplied_signature_material_is_refused_not_downgraded() {
    let mut request = parse_request(&sample_request_json(None)).expect("parse");
    request.public_key_hex = Some("aa".repeat(32));

    let report = verify(request);

    assert_eq!(report.status, "SIGNATURE_INVALID");
    assert_eq!(report.error_code, Some("INCOMPLETE_SIGNATURE_MATERIAL"));
    assert!(!report.acceptable);
}

#[test]
fn an_invalid_signature_is_rejected() {
    let mut request = parse_request(&sample_request_json(None)).expect("parse");
    // A syntactically valid but wrong key/signature pair.
    request.public_key_hex = Some(hex::encode([7u8; 32]));
    request.signature_hex = Some(hex::encode([9u8; 64]));

    let report = verify(request);

    assert_eq!(report.status, "SIGNATURE_INVALID");
    assert!(!report.acceptable);
}

#[test]
fn a_malformed_request_document_is_a_value_not_a_panic() {
    assert!(parse_request("{not json").is_err());
    assert!(parse_request("").is_err());
    assert!(parse_request("[]").is_err());
}

#[test]
fn canonical_bytes_are_valid_utf8_including_non_ascii_identifiers() {
    let mut thai = payload();
    thai.source_identifier = Some("แหล่งข้อมูล-1".to_string());

    let bytes = thai.canonical_bytes().expect("canonical");
    let text = String::from_utf8(bytes).expect("utf8");

    // The length prefix is a BYTE length, which is what makes the encoding
    // injective for multi-byte text.
    let identifier = "แหล่งข้อมูล-1";
    assert!(text.contains(&format!(
        "source_identifier=S{}:{}",
        identifier.len(),
        identifier
    )));
}

fn sample_request_json(expected: Option<&str>) -> String {
    let expected_line = match expected {
        Some(value) => format!(r#","expected_fingerprint":"{}""#, value),
        None => String::new(),
    };

    format!(
        r#"{{"draw_reference":"WK-20260918","draw_date":"2026-09-18","first_6":"001234","three_ball":"049","two_ball":"09","source_identifier":"FIXTURE-1","parser_version":"1"{}}}"#,
        expected_line
    )
}

#[test]
fn a_valid_signature_is_accepted_only_when_a_key_is_configured() {
    use ed25519_dalek::{Signer, SigningKey};

    // A deterministic test key. Signing exists only in dev-dependencies: the
    // shipped library can verify a signature and cannot create one.
    let signing_key = SigningKey::from_bytes(&[42u8; 32]);
    let verifying_key = signing_key.verifying_key();

    // The signature is over the CANONICAL BYTES, which is what the verifier
    // will independently rebuild from the field values.
    let canonical = payload().canonical_bytes().expect("canonical");
    let signature = signing_key.sign(&canonical);

    let mut request = parse_request(&sample_request_json(None)).expect("parse");
    request.public_key_hex = Some(hex::encode(verifying_key.to_bytes()));
    request.signature_hex = Some(hex::encode(signature.to_bytes()));

    let report = verify(request);

    assert_eq!(report.status, "SIGNED_VERIFIED");
    assert!(report.acceptable);

    // The same signature must NOT verify once a single digit changes, which is
    // the whole point of signing the canonical bytes rather than a label.
    let mut tampered = parse_request(
        &sample_request_json(None).replace("\"first_6\":\"001234\"", "\"first_6\":\"001235\""),
    )
    .expect("parse");
    tampered.public_key_hex = Some(hex::encode(verifying_key.to_bytes()));
    tampered.signature_hex = Some(hex::encode(signature.to_bytes()));

    let tampered_report = verify(tampered);
    assert_eq!(tampered_report.status, "SIGNATURE_INVALID");
    assert!(!tampered_report.acceptable);
}

#[test]
fn the_dependency_tree_contains_no_networking_crate() {
    // The "no network" property is enforced by ABSENCE, so this test checks
    // the absence directly against the resolved lock file rather than trusting
    // a comment. If any of these ever appears, the security boundary that lets
    // this component be described as non-networked has been broken.
    let lock = include_str!("../Cargo.lock");

    for forbidden in [
        "\nname = \"tokio\"",
        "\nname = \"reqwest\"",
        "\nname = \"hyper\"",
        "\nname = \"curl\"",
        "\nname = \"ureq\"",
        "\nname = \"socket2\"",
        "\nname = \"mio\"",
        "\nname = \"trust-dns-resolver\"",
        "\nname = \"native-tls\"",
        "\nname = \"rustls\"",
        "\nname = \"postgres\"",
        "\nname = \"tokio-postgres\"",
        "\nname = \"diesel\"",
        "\nname = \"sqlx\"",
    ] {
        assert!(
            !lock.contains(forbidden),
            "forbidden dependency present in Cargo.lock: {forbidden}"
        );
    }
}

#[test]
fn the_crate_source_contains_no_unsafe_and_no_network_or_process_calls() {
    // forbid(unsafe_code) already makes `unsafe` a compile error; this asserts
    // the other boundaries that the compiler cannot express.
    for source in [
        include_str!("../src/lib.rs"),
        include_str!("../src/canonical.rs"),
        include_str!("../src/error.rs"),
        include_str!("../src/main.rs"),
    ] {
        assert!(!source.contains("std::net"));
        assert!(!source.contains("TcpStream"));
        assert!(!source.contains("TcpListener"));
        assert!(!source.contains("UdpSocket"));
        assert!(!source.contains("std::process::Command"));
    }
}
