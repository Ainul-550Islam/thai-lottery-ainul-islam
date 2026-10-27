//! Typed integrity errors.
//!
//! WHY THESE ARE VALUES AND NOT PANICS
//! -----------------------------------------------------------------------------
//! Every input to this crate arrives, ultimately, from an external provider
//! payload. Validation failure is therefore an ORDINARY, EXPECTED outcome, not
//! a programming mistake, and a crate that panics on malformed input hands an
//! attacker a denial-of-service primitive: send one bad byte, kill the
//! verifier. So there is no `unwrap()` on caller-supplied data anywhere in this
//! crate, no `expect()`, no indexing that can go out of bounds, and no
//! `panic!`. Malformed input produces an `IntegrityError` that the caller can
//! read and report.
//!
//! Each variant maps to a stable string code, because the Laravel side stores
//! and displays that code and must not have to parse an English sentence.

use core::fmt;

/// Everything that can go wrong, as a value.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum IntegrityError {
    /// A required canonical field was absent from the request document.
    MissingField(&'static str),

    /// A field was present but did not match its documented shape.
    ///
    /// Carries the field name only - NEVER the offending value. Echoing the
    /// value back would put unvalidated provider bytes into a log line that a
    /// human later reads in a terminal.
    InvalidField(&'static str),

    /// A string contained bytes that are not valid UTF-8, or a control
    /// character that has no business in a canonical field.
    InvalidEncoding(&'static str),

    /// The request document itself could not be parsed.
    MalformedRequest,

    /// A public key was supplied but is not a well-formed Ed25519 key.
    InvalidPublicKey,

    /// A signature was supplied but is not a well-formed Ed25519 signature.
    InvalidSignatureEncoding,

    /// A well-formed signature did not verify against the canonical bytes.
    SignatureMismatch,

    /// A signature was supplied without a public key, or the reverse. Verifying
    /// one without the other is meaningless, so it is refused rather than
    /// silently downgraded to a hash-only answer.
    IncompleteSignatureMaterial,
}

impl IntegrityError {
    /// The stable machine code. Laravel stores this string.
    pub fn code(&self) -> &'static str {
        match self {
            IntegrityError::MissingField(_) => "MISSING_FIELD",
            IntegrityError::InvalidField(_) => "INVALID_FIELD",
            IntegrityError::InvalidEncoding(_) => "INVALID_ENCODING",
            IntegrityError::MalformedRequest => "MALFORMED_REQUEST",
            IntegrityError::InvalidPublicKey => "INVALID_PUBLIC_KEY",
            IntegrityError::InvalidSignatureEncoding => "INVALID_SIGNATURE_ENCODING",
            IntegrityError::SignatureMismatch => "SIGNATURE_MISMATCH",
            IntegrityError::IncompleteSignatureMaterial => "INCOMPLETE_SIGNATURE_MATERIAL",
        }
    }

    /// The field this error is about, when it is about one.
    pub fn field(&self) -> Option<&'static str> {
        match self {
            IntegrityError::MissingField(f)
            | IntegrityError::InvalidField(f)
            | IntegrityError::InvalidEncoding(f) => Some(f),
            _ => None,
        }
    }
}

impl fmt::Display for IntegrityError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self.field() {
            Some(field) => write!(f, "{} ({})", self.code(), field),
            None => write!(f, "{}", self.code()),
        }
    }
}

impl std::error::Error for IntegrityError {}
