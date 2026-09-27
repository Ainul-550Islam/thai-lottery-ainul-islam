//! Deterministic canonicalization of a Weekly Lottery result payload.
//!
//! THE PROBLEM THIS SOLVES
//! -----------------------------------------------------------------------------
//! Two systems must agree, byte for byte, on what "this result" is, so that a
//! hash computed by one can be checked by the other. JSON cannot do that job:
//! object key order is unspecified, whitespace is free, and `"049"` and `49`
//! both round-trip through most encoders. Any of those differences changes the
//! hash while the result stays the same - or, far worse, leaves the hash equal
//! while the result changes.
//!
//! So the canonical form is not JSON. It is a length-prefixed, fixed-order,
//! tagged byte string with exactly one valid encoding per payload:
//!
//!   WKLY1\n
//!   <field count>\n
//!   <name length>:<name>=<tag><value length>:<value>\n     (repeated, in order)
//!
//! FIXED FIELD ORDER. The order is the `FIELD_ORDER` constant below, never a
//! map iteration. A HashMap in Rust and an associative array in PHP do not
//! agree on iteration order, and a canonicalizer that depends on one is not a
//! canonicalizer.
//!
//! LENGTH PREFIXES. Every name and value is preceded by its byte length. That
//! is what makes the encoding injective: without it, a value containing the
//! separator could impersonate the start of the next field, and two different
//! payloads could hash identically. With it, `first_6="049"` and
//! `first_6="0", three_ball="49"` cannot collide.
//!
//! NULL IS NOT EMPTY. A missing result is tagged `N` and an empty string is
//! tagged `S` with length 0. The reference product this lane models has draws
//! with no published numbers at all, and "we have no value" must not hash the
//! same as "the value is the empty string" - one is an honest gap, the other
//! is corrupt data.
//!
//! LEADING ZEROS ARE BYTES. Values are carried as `&str` and written as bytes.
//! Nothing in this file parses a value as a number, so `049` cannot become
//! `49`. There is no integer type anywhere in the canonical path.
//!
//! WHAT THIS FILE WILL NOT DO
//! -----------------------------------------------------------------------------
//! It does not repair input. A six-digit field that arrived with five digits is
//! an error, not something to left-pad: padding it would invent a digit the
//! source never sent and then certify the invention with a hash.

use crate::error::IntegrityError;
use sha2::{Digest, Sha256};

/// Canonical format version. Part of the hashed bytes, so a future change to
/// the encoding cannot produce a hash that collides with this one.
pub const CANONICAL_VERSION: &str = "WKLY1";

/// The canonical field order. This list IS the specification.
pub const FIELD_ORDER: [&str; 7] = [
    "draw_reference",
    "draw_date",
    "first_6",
    "three_ball",
    "two_ball",
    "source_identifier",
    "parser_version",
];

/// One Weekly result, as the verifier sees it.
///
/// Every field is a string or an explicit absence. There is no numeric type in
/// this struct on purpose.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct WeeklyResultPayload {
    pub draw_reference: String,
    pub draw_date: String,
    /// `None` means RESULT_UNAVAILABLE - the draw exists, the numbers do not.
    pub first_6: Option<String>,
    pub three_ball: Option<String>,
    pub two_ball: Option<String>,
    pub source_identifier: Option<String>,
    pub parser_version: String,
}

impl WeeklyResultPayload {
    /// Validate every field against its documented shape.
    ///
    /// ALL-OR-NOTHING ON THE RESULT TRIPLE. A draw either has all three
    /// numbers or none of them. A payload carrying a 6Ball but no 2Ball is a
    /// partial read of a source, and storing it would publish a result that is
    /// silently missing a field the page has a column for.
    pub fn validate(&self) -> Result<(), IntegrityError> {
        if self.draw_reference.is_empty() || self.draw_reference.len() > 64 {
            return Err(IntegrityError::InvalidField("draw_reference"));
        }
        if !self
            .draw_reference
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b == b'-' || b == b'_')
        {
            return Err(IntegrityError::InvalidField("draw_reference"));
        }

        if !is_iso_date(&self.draw_date) {
            return Err(IntegrityError::InvalidField("draw_date"));
        }

        check_digits(self.first_6.as_deref(), 6, "first_6")?;
        check_digits(self.three_ball.as_deref(), 3, "three_ball")?;
        check_digits(self.two_ball.as_deref(), 2, "two_ball")?;

        let present = [
            self.first_6.is_some(),
            self.three_ball.is_some(),
            self.two_ball.is_some(),
        ];
        if present.iter().any(|p| *p) && !present.iter().all(|p| *p) {
            return Err(IntegrityError::InvalidField("result_triple"));
        }

        if let Some(identifier) = &self.source_identifier {
            if identifier.len() > 191 || identifier.chars().any(|c| c.is_control()) {
                return Err(IntegrityError::InvalidEncoding("source_identifier"));
            }
        }

        if self.parser_version.is_empty()
            || self.parser_version.len() > 16
            || self.parser_version.chars().any(|c| c.is_control())
        {
            return Err(IntegrityError::InvalidField("parser_version"));
        }

        Ok(())
    }

    /// Value for one canonical field name, or `None` for an absent value.
    fn field(&self, name: &str) -> Option<&str> {
        match name {
            "draw_reference" => Some(self.draw_reference.as_str()),
            "draw_date" => Some(self.draw_date.as_str()),
            "first_6" => self.first_6.as_deref(),
            "three_ball" => self.three_ball.as_deref(),
            "two_ball" => self.two_ball.as_deref(),
            "source_identifier" => self.source_identifier.as_deref(),
            "parser_version" => Some(self.parser_version.as_str()),
            // Unreachable for FIELD_ORDER, and handled as absent rather than
            // by panicking, because a panic here would be a denial-of-service
            // reachable from a payload.
            _ => None,
        }
    }

    /// The canonical byte string. Deterministic for a given payload.
    pub fn canonical_bytes(&self) -> Result<Vec<u8>, IntegrityError> {
        self.validate()?;

        let mut out: Vec<u8> = Vec::with_capacity(256);
        out.extend_from_slice(CANONICAL_VERSION.as_bytes());
        out.push(b'\n');
        out.extend_from_slice(FIELD_ORDER.len().to_string().as_bytes());
        out.push(b'\n');

        for name in FIELD_ORDER.iter() {
            out.extend_from_slice(name.len().to_string().as_bytes());
            out.push(b':');
            out.extend_from_slice(name.as_bytes());
            out.push(b'=');

            match self.field(name) {
                Some(value) => {
                    out.push(b'S');
                    out.extend_from_slice(value.len().to_string().as_bytes());
                    out.push(b':');
                    out.extend_from_slice(value.as_bytes());
                }
                None => {
                    // Explicit null. Distinct from S0: (the empty string).
                    out.push(b'N');
                    out.push(b'0');
                    out.push(b':');
                }
            }

            out.push(b'\n');
        }

        Ok(out)
    }

    /// Lowercase hex SHA-256 of the canonical bytes.
    pub fn canonical_hash(&self) -> Result<String, IntegrityError> {
        let bytes = self.canonical_bytes()?;
        let mut hasher = Sha256::new();
        hasher.update(&bytes);
        Ok(hex::encode(hasher.finalize()))
    }
}

/// A value is either absent, or exactly `width` ASCII digits.
///
/// Note what this does NOT do: it does not trim, it does not pad, and it does
/// not accept a shorter value. `"49"` for a three-wide field is an error.
fn check_digits(
    value: Option<&str>,
    width: usize,
    field: &'static str,
) -> Result<(), IntegrityError> {
    let Some(value) = value else {
        return Ok(());
    };

    if value.len() != width || !value.bytes().all(|b| b.is_ascii_digit()) {
        return Err(IntegrityError::InvalidField(field));
    }

    Ok(())
}

/// Strict `YYYY-MM-DD`, checked structurally rather than by a date library.
///
/// This is a SHAPE check for canonicalization, not a calendar check; the
/// business calendar lives in Laravel's date service. It exists so that a
/// canonical hash can never be computed over `"next tuesday"`.
fn is_iso_date(value: &str) -> bool {
    let bytes = value.as_bytes();

    if bytes.len() != 10 {
        return false;
    }

    for (index, byte) in bytes.iter().enumerate() {
        let ok = match index {
            4 | 7 => *byte == b'-',
            _ => byte.is_ascii_digit(),
        };

        if !ok {
            return false;
        }
    }

    // Reject obviously impossible month/day values without pulling in a
    // calendar. `02-31` is left to Laravel, which owns the real calendar.
    let month = &value[5..7];
    let day = &value[8..10];

    ("01"..="12").contains(&month) && ("01"..="31").contains(&day)
}
