//! Weekly Lottery result integrity verifier.
//!
//! WHAT THIS CRATE IS FOR, AND WHAT IT IS HONESTLY NOT
//! -----------------------------------------------------------------------------
//! It answers exactly one question: *do these result bytes hash to what the
//! caller says they hash to, and - when a real signed provider exists - is that
//! hash signed by the key we were told to trust?*
//!
//! That is defence in depth, not a security guarantee. It does not make the
//! platform "unhackable". It catches a specific, real class of problem: a
//! result whose stored fingerprint no longer matches its stored values,
//! whether through a bug in the PHP canonicalizer, a partial write, or
//! tampering with a row. The authoritative business state stays in Laravel and
//! PostgreSQL; this crate never becomes a second source of truth.
//!
//! WHY RUST FOR THIS ONE PIECE
//! -----------------------------------------------------------------------------
//! Not for novelty. Canonicalization is byte-exact work where a silent
//! type-juggle is the whole risk, and PHP is a language in which `"049"` and
//! `49` compare equal under `==`. Here the values are `&str` from end to end
//! and there is no integer in the canonical path at all, so the class of bug
//! that would destroy a leading zero cannot be written. The second
//! implementation also means the PHP one is checked by something that does not
//! share its bugs.
//!
//! HARD BOUNDARIES (enforced by what is absent, not by policy)
//! -----------------------------------------------------------------------------
//! * No network. There is no HTTP client, no socket, no DNS, no async runtime
//!   in the dependency tree. This component cannot be an SSRF pivot.
//! * No database. It never learns a connection string.
//! * No authentication, no wallet, no payout, no claim, no KYC. It cannot
//!   authorise anything; Laravel's policies remain the only gate.
//! * No `unsafe`. Forbidden at the crate level below, so it cannot be
//!   reintroduced in a submodule.
//! * No panics on caller input. Malformed data is an `IntegrityError` value.
//!
//! SIGNATURES ARE NOT FAKED
//! -----------------------------------------------------------------------------
//! When no public key is configured, the answer is `INTEGRITY_HASH_ONLY`. A
//! hash is not a signature: it proves the bytes are internally consistent, not
//! that anyone authorised them. Reporting `SIGNED_VERIFIED` without a key
//! would be the exact false assurance this wave forbids.

#![forbid(unsafe_code)]
#![deny(clippy::unwrap_used, clippy::expect_used, clippy::panic)]

pub mod canonical;
pub mod error;

use canonical::WeeklyResultPayload;
use ed25519_dalek::{Signature, Verifier, VerifyingKey};
use error::IntegrityError;
use serde::{Deserialize, Serialize};

/// The verification outcome, as a stable machine code.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "SCREAMING_SNAKE_CASE")]
pub enum VerificationStatus {
    /// Canonicalized and hashed. No signing key was configured, so nothing is
    /// being claimed about authorisation - only about internal consistency.
    IntegrityHashOnly,

    /// A configured Ed25519 key verified a signature over the canonical bytes.
    SignedVerified,

    /// A signature was supplied and did not verify. The caller must refuse the
    /// payload.
    SignatureInvalid,

    /// The caller supplied an expected fingerprint and it does not match the
    /// one derived here.
    FingerprintMismatch,

    /// The payload could not be canonicalized at all.
    Rejected,
}

impl VerificationStatus {
    pub fn as_str(&self) -> &'static str {
        match self {
            VerificationStatus::IntegrityHashOnly => "INTEGRITY_HASH_ONLY",
            VerificationStatus::SignedVerified => "SIGNED_VERIFIED",
            VerificationStatus::SignatureInvalid => "SIGNATURE_INVALID",
            VerificationStatus::FingerprintMismatch => "FINGERPRINT_MISMATCH",
            VerificationStatus::Rejected => "REJECTED",
        }
    }

    /// May the caller proceed to store this payload?
    pub fn is_acceptable(&self) -> bool {
        matches!(
            self,
            VerificationStatus::IntegrityHashOnly | VerificationStatus::SignedVerified
        )
    }
}

/// One verification request, as it arrives on stdin.
#[derive(Debug, Clone, Deserialize)]
pub struct VerificationRequest {
    pub draw_reference: String,
    pub draw_date: String,
    #[serde(default)]
    pub first_6: Option<String>,
    #[serde(default)]
    pub three_ball: Option<String>,
    #[serde(default)]
    pub two_ball: Option<String>,
    #[serde(default)]
    pub source_identifier: Option<String>,
    pub parser_version: String,

    /// Lowercase hex SHA-256 the caller believes is correct. Optional: when
    /// present it is compared, when absent nothing is claimed about it.
    #[serde(default)]
    pub expected_fingerprint: Option<String>,

    /// Hex Ed25519 public key. Absent means "no signed provider exists".
    #[serde(default)]
    pub public_key_hex: Option<String>,

    /// Hex Ed25519 signature over the canonical bytes.
    #[serde(default)]
    pub signature_hex: Option<String>,
}

/// The answer, serialised back to the caller as JSON.
#[derive(Debug, Clone, Serialize)]
pub struct VerificationReport {
    pub status: &'static str,
    pub acceptable: bool,
    pub canonical_version: &'static str,
    /// Lowercase hex SHA-256 of the canonical bytes, when they could be built.
    pub fingerprint: Option<String>,
    /// Byte length of the canonical form. Useful for debugging an encoder
    /// disagreement without printing the payload itself.
    pub canonical_length: Option<usize>,
    pub error_code: Option<&'static str>,
    pub error_field: Option<&'static str>,
}

impl VerificationReport {
    fn rejected(error: &IntegrityError) -> Self {
        VerificationReport {
            status: VerificationStatus::Rejected.as_str(),
            acceptable: false,
            canonical_version: canonical::CANONICAL_VERSION,
            fingerprint: None,
            canonical_length: None,
            error_code: Some(error.code()),
            error_field: error.field(),
        }
    }
}

impl From<VerificationRequest> for WeeklyResultPayload {
    fn from(request: VerificationRequest) -> Self {
        WeeklyResultPayload {
            draw_reference: request.draw_reference,
            draw_date: request.draw_date,
            first_6: request.first_6,
            three_ball: request.three_ball,
            two_ball: request.two_ball,
            source_identifier: request.source_identifier,
            parser_version: request.parser_version,
        }
    }
}

/// Canonicalize, hash, and - only when a key is configured - verify a
/// signature.
///
/// Never returns `Err` for ordinary bad input: a rejection is a report with
/// `status = REJECTED`, because the caller wants a structured answer rather
/// than a process that failed.
pub fn verify(request: VerificationRequest) -> VerificationReport {
    let expected = request.expected_fingerprint.clone();
    let public_key_hex = request.public_key_hex.clone();
    let signature_hex = request.signature_hex.clone();

    let payload: WeeklyResultPayload = request.into();

    let bytes = match payload.canonical_bytes() {
        Ok(bytes) => bytes,
        Err(error) => return VerificationReport::rejected(&error),
    };

    let fingerprint = match payload.canonical_hash() {
        Ok(hash) => hash,
        Err(error) => return VerificationReport::rejected(&error),
    };

    // A caller-supplied fingerprint is compared in constant time relative to
    // its own length: both sides are fixed-width lowercase hex here, so a
    // simple equality on equal-length strings does not leak a useful timing
    // signal. The comparison exists to catch a PHP/Rust encoder disagreement,
    // not to guard a secret.
    if let Some(expected) = expected {
        if !fingerprints_equal(&expected, &fingerprint) {
            return VerificationReport {
                status: VerificationStatus::FingerprintMismatch.as_str(),
                acceptable: false,
                canonical_version: canonical::CANONICAL_VERSION,
                fingerprint: Some(fingerprint),
                canonical_length: Some(bytes.len()),
                error_code: None,
                error_field: None,
            };
        }
    }

    match (public_key_hex, signature_hex) {
        // No signed provider exists. Say exactly that - a hash is not a
        // signature, and pretending otherwise is the failure mode this crate
        // was written to avoid.
        (None, None) => VerificationReport {
            status: VerificationStatus::IntegrityHashOnly.as_str(),
            acceptable: true,
            canonical_version: canonical::CANONICAL_VERSION,
            fingerprint: Some(fingerprint),
            canonical_length: Some(bytes.len()),
            error_code: None,
            error_field: None,
        },

        // Half the material is not a weaker signature; it is a misconfiguration.
        (Some(_), None) | (None, Some(_)) => VerificationReport {
            status: VerificationStatus::SignatureInvalid.as_str(),
            acceptable: false,
            canonical_version: canonical::CANONICAL_VERSION,
            fingerprint: Some(fingerprint),
            canonical_length: Some(bytes.len()),
            error_code: Some(IntegrityError::IncompleteSignatureMaterial.code()),
            error_field: None,
        },

        (Some(key_hex), Some(sig_hex)) => {
            let key = match decode_verifying_key(&key_hex) {
                Ok(key) => key,
                Err(error) => {
                    return VerificationReport {
                        status: VerificationStatus::SignatureInvalid.as_str(),
                        acceptable: false,
                        canonical_version: canonical::CANONICAL_VERSION,
                        fingerprint: Some(fingerprint),
                        canonical_length: Some(bytes.len()),
                        error_code: Some(error.code()),
                        error_field: None,
                    }
                }
            };

            let signature = match decode_signature(&sig_hex) {
                Ok(signature) => signature,
                Err(error) => {
                    return VerificationReport {
                        status: VerificationStatus::SignatureInvalid.as_str(),
                        acceptable: false,
                        canonical_version: canonical::CANONICAL_VERSION,
                        fingerprint: Some(fingerprint),
                        canonical_length: Some(bytes.len()),
                        error_code: Some(error.code()),
                        error_field: None,
                    }
                }
            };

            // The signature is over the CANONICAL BYTES, not over the hex
            // digest and not over the raw JSON. Signing a digest string would
            // make the signature depend on the encoding of the digest rather
            // than on the result itself.
            match key.verify(&bytes, &signature) {
                Ok(()) => VerificationReport {
                    status: VerificationStatus::SignedVerified.as_str(),
                    acceptable: true,
                    canonical_version: canonical::CANONICAL_VERSION,
                    fingerprint: Some(fingerprint),
                    canonical_length: Some(bytes.len()),
                    error_code: None,
                    error_field: None,
                },
                Err(_) => VerificationReport {
                    status: VerificationStatus::SignatureInvalid.as_str(),
                    acceptable: false,
                    canonical_version: canonical::CANONICAL_VERSION,
                    fingerprint: Some(fingerprint),
                    canonical_length: Some(bytes.len()),
                    error_code: Some(IntegrityError::SignatureMismatch.code()),
                    error_field: None,
                },
            }
        }
    }
}

fn fingerprints_equal(left: &str, right: &str) -> bool {
    if left.len() != right.len() {
        return false;
    }

    let mut diff: u8 = 0;

    for (a, b) in left.bytes().zip(right.bytes()) {
        diff |= a ^ b;
    }

    diff == 0
}

fn decode_verifying_key(hex_key: &str) -> Result<VerifyingKey, IntegrityError> {
    let raw = hex::decode(hex_key).map_err(|_| IntegrityError::InvalidPublicKey)?;
    let bytes: [u8; 32] = raw
        .try_into()
        .map_err(|_| IntegrityError::InvalidPublicKey)?;

    VerifyingKey::from_bytes(&bytes).map_err(|_| IntegrityError::InvalidPublicKey)
}

fn decode_signature(hex_signature: &str) -> Result<Signature, IntegrityError> {
    let raw = hex::decode(hex_signature).map_err(|_| IntegrityError::InvalidSignatureEncoding)?;
    let bytes: [u8; 64] = raw
        .try_into()
        .map_err(|_| IntegrityError::InvalidSignatureEncoding)?;

    Ok(Signature::from_bytes(&bytes))
}

/// Parse one request document.
pub fn parse_request(input: &str) -> Result<VerificationRequest, IntegrityError> {
    serde_json::from_str::<VerificationRequest>(input).map_err(|_| IntegrityError::MalformedRequest)
}

/// Serialise a report. Falls back to a minimal literal rather than panicking,
/// because a serialisation failure must still produce a parseable answer.
pub fn render_report(report: &VerificationReport) -> String {
    serde_json::to_string(report).unwrap_or_else(|_| {
        String::from(r#"{"status":"REJECTED","acceptable":false,"error_code":"MALFORMED_REQUEST"}"#)
    })
}
