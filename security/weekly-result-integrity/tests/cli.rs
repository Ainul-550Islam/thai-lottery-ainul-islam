//! Actual stdin/stdout and exit-code checks using synthetic data only.
//! This test harness may spawn the binary; the production crate cannot spawn
//! processes, contact a provider or write business/financial records.

#![forbid(unsafe_code)]

use std::io::Write;
use std::process::{Command, Stdio};

use ed25519_dalek::{Signer, SigningKey};
use serde_json::{json, Value};
use weekly_result_integrity::{canonical::WeeklyResultPayload, parse_request};

fn request() -> Value {
    json!({
        "draw_reference": "WK-20260918",
        "draw_date": "2026-09-18",
        "first_6": "001234",
        "three_ball": "049",
        "two_ball": "09",
        "source_identifier": "SYNTHETIC-F40",
        "parser_version": "1"
    })
}

fn invoke(input: &[u8]) -> (i32, Value) {
    let mut child = Command::new(env!("CARGO_BIN_EXE_weekly-result-integrity"))
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .expect("native fixture process must start");
    child
        .stdin
        .take()
        .expect("piped stdin")
        .write_all(input)
        .expect("fixture input must be written");
    let output = child.wait_with_output().expect("fixture process must exit");
    assert!(output.stderr.is_empty(), "no raw input/trace on stderr");
    assert!(
        output.stdout.ends_with(b"\n"),
        "one newline-terminated JSON report"
    );
    let report: Value = serde_json::from_slice(&output.stdout).expect("exactly one JSON report");
    (output.status.code().expect("ordinary exit code"), report)
}

fn invoke_json(value: &Value) -> (i32, Value) {
    invoke(&serde_json::to_vec(value).expect("fixture JSON"))
}

#[test]
fn unsigned_valid_input_exits_zero_and_does_not_claim_authority() {
    let (code, report) = invoke_json(&request());
    assert_eq!(code, 0);
    assert_eq!(report["status"], "INTEGRITY_HASH_ONLY");
    assert_eq!(report["acceptable"], true);
    assert_eq!(report["canonical_version"], "WKLY1");
    let payload: WeeklyResultPayload = parse_request(&request().to_string())
        .expect("fixture request")
        .into();
    assert_eq!(
        report["fingerprint"],
        payload.canonical_hash().expect("fixture hash")
    );
}

#[test]
fn fingerprint_disagreement_exits_two() {
    let mut input = request();
    input["expected_fingerprint"] = Value::String("0".repeat(64));
    let (code, report) = invoke_json(&input);
    assert_eq!(code, 2);
    assert_eq!(report["status"], "FINGERPRINT_MISMATCH");
    assert_eq!(report["acceptable"], false);
}

#[test]
fn malformed_json_exits_one_without_echoing_the_input() {
    let (code, report) = invoke(b"{PRIVATE-not-json");
    assert_eq!(code, 1);
    assert_eq!(report["status"], "REJECTED");
    assert_eq!(report["error_code"], "MALFORMED_REQUEST");
    assert!(!report.to_string().contains("PRIVATE"));
}

#[test]
fn invalid_utf8_exits_one() {
    let (code, report) = invoke(&[0xff, 0xfe]);
    assert_eq!(code, 1);
    assert_eq!(report["error_code"], "INVALID_UTF8");
    assert_eq!(report["acceptable"], false);
}

#[test]
fn input_limit_is_enforced_before_json_parsing() {
    let (code, report) = invoke(&vec![b'x'; 64 * 1024 + 1]);
    assert_eq!(code, 1);
    assert_eq!(report["error_code"], "DOCUMENT_TOO_LARGE");
    assert_eq!(report["acceptable"], false);
}

#[test]
fn missing_leading_zeros_are_refused_not_invented() {
    let mut input = request();
    input["first_6"] = json!("1234");
    let (code, report) = invoke_json(&input);
    assert_eq!(code, 2);
    assert_eq!(report["error_code"], "INVALID_FIELD");
    assert_eq!(report["error_field"], "first_6");
    assert_eq!(report["acceptable"], false);
}

#[test]
fn half_signature_material_is_not_an_unsigned_success() {
    let mut input = request();
    input["public_key_hex"] = Value::String("aa".repeat(32));
    let (code, report) = invoke_json(&input);
    assert_eq!(code, 2);
    assert_eq!(report["status"], "SIGNATURE_INVALID");
    assert_eq!(report["error_code"], "INCOMPLETE_SIGNATURE_MATERIAL");
    assert_eq!(report["acceptable"], false);
}

#[test]
fn a_public_test_key_signs_only_the_original_canonical_bytes() {
    let mut input = request();
    let payload: WeeklyResultPayload = parse_request(&input.to_string())
        .expect("fixture request")
        .into();
    let signing = SigningKey::from_bytes(&[42u8; 32]);
    let signature = signing.sign(&payload.canonical_bytes().expect("fixture bytes"));
    input["public_key_hex"] = json!(hex::encode(signing.verifying_key().to_bytes()));
    input["signature_hex"] = json!(hex::encode(signature.to_bytes()));
    let (code, report) = invoke_json(&input);
    assert_eq!(code, 0);
    assert_eq!(report["status"], "SIGNED_VERIFIED");
    input["first_6"] = json!("001235");
    let (code, report) = invoke_json(&input);
    assert_eq!(code, 2);
    assert_eq!(report["error_code"], "SIGNATURE_MISMATCH");
    assert_eq!(report["acceptable"], false);
}

#[test]
fn numeric_json_is_not_coerced_into_a_digit_string() {
    let mut input = request();
    input["three_ball"] = json!(49);
    let (code, report) = invoke_json(&input);
    assert_eq!(code, 1);
    assert_eq!(report["error_code"], "MALFORMED_REQUEST");
    assert_eq!(report["acceptable"], false);
}

#[test]
fn unavailable_triples_remain_null_instead_of_fabricated_zeroes() {
    let mut input = request();
    input["first_6"] = Value::Null;
    input["three_ball"] = Value::Null;
    input["two_ball"] = Value::Null;
    let (code, report) = invoke_json(&input);
    assert_eq!(code, 0);
    assert_eq!(report["status"], "INTEGRITY_HASH_ONLY");
    assert_eq!(report["acceptable"], true);
    assert!(report["fingerprint"].is_string());
}
