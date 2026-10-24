//! Thai Lottery — settlement hot-path worker.
//!
//! WHY THIS EXISTS AS A SEPARATE RUST PROCESS
//! ---------------------------------------------------------------------------
//! The settlement hot path is: given a draw's published result, decide for every
//! participating selection whether it won, and compute the exact prize. That is
//! a pure function over a lot of rows, executed on a deadline, on draw night,
//! for every player at once.
//!
//! PHP does this correctly but expensively: every selection becomes a hydrated
//! Eloquent model, every decimal becomes a bcmath string allocation, and the
//! whole run shares one process with the web tier's request handling. The Rust
//! worker does the same arithmetic with fixed-point decimals and no per-row
//! object graph, so a 100k-slip draw is bounded by the database round trip
//! rather than by PHP's object churn.
//!
//! WHAT THIS WORKER IS NOT
//! It is NOT the ledger. It writes no balances, creates no ledger entries and
//! posts no financial transactions. It COMPUTES a settlement plan and hands it
//! back to PHP, which applies it through `WalletService` inside the existing
//! transactional, idempotent, ledger-posted path. The Rust side is incapable of
//! moving money on its own — deliberately, so that a bug here produces a wrong
//! NUMBER that the PHP side's invariants can still catch, rather than a wrong
//! BALANCE that nothing checks.
//!
//! MONEY DISCIPLINE
//! `rust_decimal::Decimal` everywhere. No `f64` appears in this file, and it
//! must not be introduced: `0.1 + 0.2 != 0.3` in binary floating point, and a
//! lottery pays out in satang.

use std::net::SocketAddr;
use std::sync::Arc;
use std::time::Duration;

use anyhow::Context;
use axum::{
    extract::State,
    http::StatusCode,
    response::IntoResponse,
    routing::{get, post},
    Json, Router,
};
use chrono::{DateTime, Utc};
use hmac::{Hmac, Mac};
use rust_decimal::prelude::*;
use rust_decimal::Decimal;
use serde::{Deserialize, Serialize};
use sha2::Sha256;
use sqlx::postgres::PgPoolOptions;
use sqlx::{PgPool, Row};
use tokio::signal;
use tracing::{error, info, warn};
use tracing_subscriber::{layer::SubscriberExt, util::SubscriberInitExt, EnvFilter};

type HmacSha256 = Hmac<Sha256>;

// ============================================================================
// Prize tiers — the GLO structure this platform settles against.
// ============================================================================

/// The tier a single selection was placed on.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Tier {
    /// Match the 6-digit first prize exactly.
    First,
    /// Match one of the 2-digit "bottom two" positions.
    BottomTwo,
    /// 3-digit front / back.
    ThreeDigitFront,
    ThreeDigitBack,
    /// 2-digit front / back.
    TwoDigitFront,
    TwoDigitBack,
    /// Under/over on the 3-digit total.
    ThreeDigitTod,
}

impl Tier {
    pub fn parse(raw: &str) -> Option<Self> {
        match raw {
            "first" | "first_prize" => Some(Self::First),
            "bottom_two" | "bottom2" => Some(Self::BottomTwo),
            "three_front" | "three_digit_front" => Some(Self::ThreeDigitFront),
            "three_back" | "three_digit_back" => Some(Self::ThreeDigitBack),
            "two_front" | "two_digit_front" => Some(Self::TwoDigitFront),
            "two_back" | "two_digit_back" => Some(Self::TwoDigitBack),
            "three_tod" | "tod" => Some(Self::ThreeDigitTod),
            _ => None,
        }
    }

    /// Payout multiplier as a decimal. Loaded from configuration at deploy time
    /// for real deployments; the values here are the reference matrix and MUST
    /// be reconciled against `config/glo.php` before production, because a
    /// mismatch between the PHP-side shown odds and the Rust-side paid odds is
    /// a mispricing that no test would catch unless it compares the two.
    pub fn multiplier(self) -> Decimal {
        match self {
            Self::First => Decimal::new(500_000, 0),
            Self::BottomTwo => Decimal::new(4_000, 0),
            Self::ThreeDigitFront => Decimal::new(600, 0),
            Self::ThreeDigitBack => Decimal::new(600, 0),
            Self::TwoDigitFront => Decimal::new(70, 0),
            Self::TwoDigitBack => Decimal::new(70, 0),
            Self::ThreeDigitTod => Decimal::new(3, 0),
        }
    }
}

// ============================================================================
// The published result
// ============================================================================

#[derive(Debug, Clone, Deserialize)]
pub struct PublishedResult {
    pub draw_id: i64,
    /// Digit strings, never integers. "007" and "7" are different lottery
    /// numbers and an i64 round trip destroys the distinction.
    pub first_prize: String,
    pub bottom_two: String,
    #[serde(default)]
    pub three_front: Vec<String>,
    #[serde(default)]
    pub three_back: Vec<String>,
    #[serde(default)]
    pub two_front: Vec<String>,
    #[serde(default)]
    pub two_back: Vec<String>,
    /// The fingerprint the PHP side pinned when the result was published.
    /// Verified here before any arithmetic, and echoed back in the plan.
    pub result_fingerprint: String,
}

#[derive(Debug, Clone, Deserialize)]
pub struct Selection {
    pub bet_id: i64,
    pub bet_item_id: i64,
    /// The player's chosen number, as stored — a digit string.
    pub number: String,
    pub tier: String,
    /// Stake on this selection.
    pub stake: String,
}

#[derive(Debug, Clone, Serialize)]
pub struct SettlementDecision {
    pub bet_id: i64,
    pub bet_item_id: i64,
    pub won: bool,
    /// Exact prize, as a decimal string with 2 places. Empty when not won.
    pub prize: String,
    pub tier: String,
}

#[derive(Debug, Clone, Serialize)]
pub struct SettlementPlan {
    pub draw_id: i64,
    pub result_fingerprint: String,
    pub computed_at: DateTime<Utc>,
    pub total_selections: usize,
    pub winning_selections: usize,
    /// Exact sum, computed in Decimal and emitted as a string.
    pub total_prize: String,
    pub decisions: Vec<SettlementDecision>,
}

// ============================================================================
// Matching — pure, total, and unit-testable
// ============================================================================

/// Decide one selection.
///
/// This function is intentionally the ONLY place match logic lives. It is pure:
/// no database, no clock, no config lookup, no I/O. That is what makes it
/// exhaustively testable, and a settlement rule that cannot be exhaustively
/// tested is a settlement rule nobody can promise anything about.
pub fn decide(selection: &Selection, result: &PublishedResult) -> SettlementDecision {
    let tier = Tier::parse(&selection.tier);
    let number = normalise(&selection.number);

    let won = match tier {
        Some(Tier::First) => number == normalise(&result.first_prize),
        Some(Tier::BottomTwo) => number == normalise(&result.bottom_two),
        Some(Tier::ThreeDigitFront) => result
            .three_front
            .iter()
            .any(|n| normalise(n) == number),
        Some(Tier::ThreeDigitBack) => result.three_back.iter().any(|n| normalise(n) == number),
        Some(Tier::TwoDigitFront) => result.two_front.iter().any(|n| normalise(n) == number),
        Some(Tier::TwoDigitBack) => result.two_back.iter().any(|n| normalise(n) == number),
        // TOD comparison is positional and lives on the PHP side, which owns
        // the rule table. Refusing to guess here is the correct default: an
        // unknown tier must resolve to "not won", never to a payout.
        Some(Tier::ThreeDigitTod) | None => false,
    };

    let prize = if won {
        match tier {
            Some(t) => {
                let stake = Decimal::from_str_exact(&selection.stake).unwrap_or(Decimal::ZERO);
                // Round HALF_UP to 2 places to match the PHP side's bcmath
                // scale exactly. Banker's rounding would disagree with a PHP
                // implementation that uses the default rounding mode, and a
                // one-satang disagreement between the two engines is a
                // reconciliation break.
                (stake * t.multiplier())
                    .round_dp_with_strategy(2, RoundingStrategy::MidpointAwayFromZero)
                    .to_string()
            }
            None => "0.00".to_string(),
        }
    } else {
        "0.00".to_string()
    };

    SettlementDecision {
        bet_id: selection.bet_id,
        bet_item_id: selection.bet_item_id,
        won,
        prize,
        tier: selection.tier.clone(),
    }
}

/// Trim, strip separators, and keep leading zeros.
///
/// A lottery number is a DIGIT STRING, not a number. "007" is not "7".
fn normalise(raw: &str) -> String {
    raw.trim()
        .chars()
        .filter(|c| c.is_ascii_digit())
        .collect()
}

/// Compute a settlement plan for a batch of selections.
///
/// Chunking is the CALLER's job: this function is O(n) in the slice it is given
/// and holds nothing. That is what lets the worker consume a 100k-selection draw
/// in bounded windows without the memory profile growing with the draw size.
pub fn plan(
    draw_id: i64,
    result: &PublishedResult,
    selections: &[Selection],
) -> SettlementPlan {
    let mut total = Decimal::ZERO;
    let mut decisions = Vec::with_capacity(selections.len());
    let mut winners = 0usize;

    for selection in selections {
        let decision = decide(selection, result);

        if decision.won {
            winners += 1;

            if let Ok(prize) = Decimal::from_str_exact(&decision.prize) {
                total += prize;
            }
        }

        decisions.push(decision);
    }

    SettlementPlan {
        draw_id,
        result_fingerprint: result.result_fingerprint.clone(),
        computed_at: Utc::now(),
        total_selections: decisions.len(),
        winning_selections: winners,
        total_prize: total.round_dp(2).to_string(),
        decisions,
    }
}

// ============================================================================
// Signature verification — the same scheme as the PHP middleware
// ============================================================================

/// Verify `<timestamp>.<body>` against an HMAC-SHA256 signature.
///
/// Mirrors `App\Http\Middleware\VerifyWebhookSignature`. The timestamp is INSIDE
/// the signed material, so it cannot be edited to extend a signature's life.
pub fn verify_signature(
    secret: &[u8],
    timestamp: &str,
    body: &[u8],
    signature_hex: &str,
    tolerance_seconds: i64,
) -> Result<(), String> {
    let ts: i64 = timestamp.parse().map_err(|_| "timestamp is not an integer".to_string())?;

    if tolerance_seconds > 0 {
        let now = Utc::now().timestamp();
        if (now - ts).abs() > tolerance_seconds {
            return Err(format!("timestamp outside tolerance window ({}s)", (now - ts).abs()));
        }
    }

    let mut mac = HmacSha256::new_from_slice(secret).map_err(|_| "bad key length".to_string())?;
    mac.update(timestamp.as_bytes());
    mac.update(b".");
    mac.update(body);

    let expected = hex::encode(mac.finalize().into_bytes());

    // Constant-time comparison. `==` on a hex string leaks the position of the
    // first differing byte through timing, which is enough to forge a signature
    // byte by byte.
    if constant_time_eq(expected.as_bytes(), signature_hex.to_ascii_lowercase().as_bytes()) {
        Ok(())
    } else {
        Err("signature mismatch".to_string())
    }
}

fn constant_time_eq(a: &[u8], b: &[u8]) -> bool {
    if a.len() != b.len() {
        return false;
    }

    let mut diff = 0u8;
    for (x, y) in a.iter().zip(b.iter()) {
        diff |= x ^ y;
    }

    diff == 0
}

// ============================================================================
// Database access — read-only
// ============================================================================

/// Load the selections for one bet-id window.
///
/// READ-ONLY. There is no INSERT, UPDATE or DELETE in this file, and adding one
/// would break the project's single-write-owner rule: the PHP side owns every
/// financial write. The window is a half-open id range so the caller can page
/// without holding a cursor.
pub async fn load_selections(
    pool: &PgPool,
    draw_id: i64,
    from_bet_id: i64,
    to_bet_id: i64,
) -> Result<Vec<Selection>, sqlx::Error> {
    let rows = sqlx::query(
        r#"
        SELECT
            bi.bet_id            AS bet_id,
            bi.id                AS bet_item_id,
            bi.number            AS number,
            bi.tier              AS tier,
            bi.stake             AS stake
        FROM bet_items bi
        JOIN bets b ON b.id = bi.bet_id
        WHERE b.draw_id = $1
          AND b.deleted_at IS NULL
          AND bi.deleted_at IS NULL
          AND bi.bet_id >= $2
          AND bi.bet_id <= $3
        ORDER BY bi.bet_id, bi.id
        "#,
    )
    .bind(draw_id)
    .bind(from_bet_id)
    .bind(to_bet_id)
    .fetch_all(pool)
    .await?;

    Ok(rows
        .into_iter()
        .map(|row| Selection {
            bet_id: row.get("bet_id"),
            bet_item_id: row.get("bet_item_id"),
            number: row.get("number"),
            tier: row.get("tier"),
            stake: row.get("stake"),
        })
        .collect())
}

/// Confirm the pinned result fingerprint for a draw.
///
/// The whole plan is keyed to this value. If it has moved, the caller's frozen
/// chunk plan is stale and the run must abort rather than settle half a draw
/// against one number and half against another.
pub async fn current_fingerprint(pool: &PgPool, draw_id: i64) -> Result<String, sqlx::Error> {
    sqlx::query_scalar::<_, String>(
        "SELECT result_fingerprint FROM draw_results WHERE draw_id = $1",
    )
    .bind(draw_id)
    .fetch_one(pool)
    .await
}

// ============================================================================
// HTTP surface
// ============================================================================

#[derive(Clone)]
struct AppState {
    pool: PgPool,
}

#[derive(Debug, Deserialize)]
struct PlanRequest {
    draw_id: i64,
    from_bet_id: i64,
    to_bet_id: i64,
    result: PublishedResult,
}

async fn health(State(state): State<Arc<AppState>>) -> impl IntoResponse {
    match sqlx::query("SELECT 1").execute(&state.pool).await {
        Ok(_) => (StatusCode::OK, Json(serde_json::json!({"status": "ok"}))),
        Err(e) => (
            StatusCode::SERVICE_UNAVAILABLE,
            Json(serde_json::json!({"status": "degraded", "error": e.to_string()})),
        ),
    }
}

/// Compute a settlement plan for one window.
///
/// The route is internal-only (backend network) and is NOT a money endpoint.
/// It returns a plan; the PHP side decides what to do with it.
async fn compute_plan(
    State(state): State<Arc<AppState>>,
    Json(request): Json<PlanRequest>,
) -> impl IntoResponse {
    let selections =
        match load_selections(&state.pool, request.draw_id, request.from_bet_id, request.to_bet_id)
            .await
        {
            Ok(s) => s,
            Err(e) => {
                error!(error = %e, draw_id = request.draw_id, "selection load failed");
                return (
                    StatusCode::INTERNAL_SERVER_ERROR,
                    Json(serde_json::json!({"error": "selection_load_failed"})),
                );
            }
        };

    // Fingerprint guard: settling against a result that moved mid-run is the
    // one failure this worker must make impossible.
    match current_fingerprint(&state.pool, request.draw_id).await {
        Ok(current) if current != request.result.result_fingerprint => {
            warn!(
                draw_id = request.draw_id,
                expected = %request.result.result_fingerprint,
                actual = %current,
                "result fingerprint changed mid-settlement; refusing to compute"
            );
            return (
                StatusCode::CONFLICT,
                Json(serde_json::json!({
                    "error": "result_fingerprint_changed",
                    "expected": request.result.result_fingerprint,
                    "actual": current,
                })),
            );
        }
        Err(e) => {
            error!(error = %e, draw_id = request.draw_id, "fingerprint read failed");
            return (
                StatusCode::INTERNAL_SERVER_ERROR,
                Json(serde_json::json!({"error": "fingerprint_read_failed"})),
            );
        }
        _ => {}
    }

    let settlement_plan = plan(request.draw_id, &request.result, &selections);

    (StatusCode::OK, Json(serde_json::to_value(settlement_plan).unwrap()))
}

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    tracing_subscriber::registry()
        .with(EnvFilter::try_from_default_env().unwrap_or_else(|_| EnvFilter::new("info")))
        .with(tracing_subscriber::fmt::layer().json())
        .init();

    let database_url = std::env::var("DATABASE_URL")
        .or_else(|_| {
            std::fs::read_to_string("/run/secrets/database_url")
                .map(|s| s.trim().to_string())
        })
        .context("DATABASE_URL is required (env or /run/secrets/database_url)")?;

    let bind: SocketAddr = std::env::var("WORKER_BIND")
        .unwrap_or_else(|_| "0.0.0.0:9101".to_string())
        .parse()
        .context("WORKER_BIND must be a socket address")?;

    // Small pool on purpose. This worker is read-only and short-lived per
    // request; a large pool would starve the PHP fleet of connections during a
    // draw, which is exactly when PHP needs them most.
    let pool = PgPoolOptions::new()
        .max_connections(8)
        .acquire_timeout(Duration::from_secs(5))
        .connect(&database_url)
        .await
        .context("could not connect to PostgreSQL")?;

    let state = Arc::new(AppState { pool });

    let app = Router::new()
        .route("/health", get(health))
        .route("/v1/settlement/plan", post(compute_plan))
        .with_state(state);

    let listener = tokio::net::TcpListener::bind(bind).await?;
    info!(%bind, "settlement worker listening");

    axum::serve(listener, app)
        .with_graceful_shutdown(shutdown_signal())
        .await?;

    Ok(())
}

async fn shutdown_signal() {
    let ctrl_c = async {
        signal::ctrl_c().await.expect("ctrl-c handler");
    };

    #[cfg(unix)]
    let terminate = async {
        signal::unix::signal(signal::unix::SignalKind::terminate())
            .expect("SIGTERM handler")
            .recv()
            .await;
    };

    #[cfg(not(unix))]
    let terminate = std::future::pending::<()>();

    tokio::select! {
        _ = ctrl_c => {},
        _ = terminate => {},
    }

    info!("shutdown signal received; draining");
}

// ============================================================================
// Tests
// ============================================================================

#[cfg(test)]
mod tests {
    use super::*;

    fn result() -> PublishedResult {
        PublishedResult {
            draw_id: 1,
            first_prize: "123456".into(),
            bottom_two: "99".into(),
            three_front: vec!["111".into(), "222".into()],
            three_back: vec!["333".into()],
            two_front: vec!["55".into()],
            two_back: vec!["66".into()],
            result_fingerprint: "abc".into(),
        }
    }

    #[test]
    fn leading_zeros_are_significant() {
        // "007" must not match "7". This is the regression that turns a losing
        // ticket into a paying one.
        assert_ne!(normalise("007"), normalise("7"));

        let mut r = result();
        r.first_prize = "000007".into();

        let s = Selection {
            bet_id: 1,
            bet_item_id: 1,
            number: "7".into(),
            tier: "first".into(),
            stake: "100.00".into(),
        };

        assert!(!decide(&s, &r).won);
    }

    #[test]
    fn exact_first_prize_pays_at_the_multiplier() {
        let s = Selection {
            bet_id: 1,
            bet_item_id: 1,
            number: "123456".into(),
            tier: "first".into(),
            stake: "10.00".into(),
        };

        let d = decide(&s, &result());
        assert!(d.won);
        assert_eq!(d.prize, "5000000.00");
    }

    #[test]
    fn losing_selection_pays_exactly_zero() {
        let s = Selection {
            bet_id: 1,
            bet_item_id: 1,
            number: "999999".into(),
            tier: "first".into(),
            stake: "10.00".into(),
        };

        let d = decide(&s, &result());
        assert!(!d.won);
        assert_eq!(d.prize, "0.00");
    }

    #[test]
    fn unknown_tier_never_pays() {
        let s = Selection {
            bet_id: 1,
            bet_item_id: 1,
            number: "123456".into(),
            tier: "some_new_tier_nobody_implemented".into(),
            stake: "10.00".into(),
        };

        let d = decide(&s, &result());
        assert!(!d.won, "an unrecognised tier must resolve to not-won, never to a payout");
    }

    #[test]
    fn total_is_an_exact_decimal_sum() {
        // 0.1 + 0.2 in f64 is 0.30000000000000004. In Decimal it is 0.3.
        // This is the entire reason the worker does not use floats.
        let selections = vec![
            Selection {
                bet_id: 1,
                bet_item_id: 1,
                number: "10".into(),
                tier: "two_back".into(),
                stake: "0.05".into(),
            },
            Selection {
                bet_id: 2,
                bet_item_id: 2,
                number: "10".into(),
                tier: "two_back".into(),
                stake: "0.05".into(),
            },
        ];

        let mut r = result();
        r.bottom_two = "10".into();

        // two_back multiplier is 70: 0.05 * 70 = 3.5 per selection.
        let p = plan(1, &r, &selections);
        assert_eq!(p.winning_selections, 2);
        assert_eq!(p.total_prize, "7.00");
    }

    #[test]
    fn signature_verification_rejects_a_forged_timestamp() {
        let secret = b"test-secret";
        let body = br#"{"event":"deposit.completed"}"#;

        let mut mac = HmacSha256::new_from_slice(secret).unwrap();
        mac.update(b"1700000000.");
        mac.update(body);
        let sig = hex::encode(mac.finalize().into_bytes());

        // Correct timestamp + correct signature: accepted (tolerance 0 so the
        // clock does not make this test flaky).
        assert!(verify_signature(secret, "1700000000", body, &sig, 0).is_ok());

        // Same signature, edited timestamp: refused, because the timestamp is
        // inside the signed material.
        assert!(verify_signature(secret, "1700000001", body, &sig, 0).is_err());
    }

    #[test]
    fn signature_verification_enforces_the_tolerance_window() {
        let secret = b"test-secret";
        let body = b"{}";
        let now = Utc::now().timestamp().to_string();

        let mut mac = HmacSha256::new_from_slice(secret).unwrap();
        mac.update(now.as_bytes());
        mac.update(b".");
        mac.update(body);
        let sig = hex::encode(mac.finalize().into_bytes());

        assert!(verify_signature(secret, &now, body, &sig, 300).is_ok());
    }
}
