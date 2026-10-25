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
    body::Bytes,
    extract::State,
    http::{HeaderMap, StatusCode},
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
use sqlx::mysql::MySqlPoolOptions;
use sqlx::{MySqlPool, Row};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpStream;
use tokio::signal;
use tracing::{error, info, warn};
use tracing_subscriber::{layer::SubscriberExt, util::SubscriberInitExt, EnvFilter};

type HmacSha256 = Hmac<Sha256>;

// ============================================================================
// Configured lottery markets from `config/lottery.php`.
// ============================================================================

/// The application's configured market keys. Names and multipliers mirror
/// `config/lottery.php` exactly; the worker never guesses a fallback market.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Tier {
    ThreeDigitDirect,
    ThreeDigitTod,
    TwoDigitTop,
    TwoDigitBottom,
    RunTop,
    RunBottom,
}

impl Tier {
    pub fn parse(raw: &str) -> Option<Self> {
        match raw {
            "3d_direct" => Some(Self::ThreeDigitDirect),
            "3d_tod" => Some(Self::ThreeDigitTod),
            "2d_top" => Some(Self::TwoDigitTop),
            "2d_bottom" => Some(Self::TwoDigitBottom),
            "run_top" => Some(Self::RunTop),
            "run_bottom" => Some(Self::RunBottom),
            _ => None,
        }
    }

    pub fn digits(self) -> usize {
        match self {
            Self::ThreeDigitDirect | Self::ThreeDigitTod => 3,
            Self::TwoDigitTop | Self::TwoDigitBottom => 2,
            Self::RunTop | Self::RunBottom => 1,
        }
    }

    /// Current authoritative market multipliers from `lottery.markets`.
    pub fn multiplier(self) -> Decimal {
        match self {
            Self::ThreeDigitDirect => Decimal::new(900, 0),
            Self::ThreeDigitTod => Decimal::new(45, 0),
            Self::TwoDigitTop | Self::TwoDigitBottom => Decimal::new(90, 0),
            Self::RunTop => Decimal::new(3, 0),
            Self::RunBottom => Decimal::new(4, 0),
        }
    }
}

// ============================================================================
// The published result
// ============================================================================

#[derive(Debug, Clone, Deserialize)]
pub struct PublishedResult {
    pub draw_id: u64,
    /// Digit strings, never integers. "007" and "7" are different lottery
    /// numbers and an integer round trip destroys the distinction.
    pub first_prize: String,
    pub bottom_two: String,
    /// The fingerprint the PHP side pinned when the result was published.
    /// Verified here before any arithmetic, and echoed back in the plan.
    pub result_fingerprint: String,
}

#[derive(Debug, Clone, Deserialize)]
pub struct Selection {
    pub bet_id: u64,
    pub bet_item_id: u64,
    /// The player's chosen number, as stored — a digit string.
    pub number: String,
    pub tier: String,
    /// Stake on this selection.
    pub stake: String,
    /// The purchase-time market metadata, when present, must agree with the
    /// market derived from `bets.type` and `bet_items.position`.
    pub recorded_market: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
pub struct SettlementDecision {
    pub bet_id: u64,
    pub bet_item_id: u64,
    pub won: bool,
    /// Exact prize, as a decimal string with 2 places. Empty when not won.
    pub prize: String,
    pub tier: String,
}

#[derive(Debug, Clone, Serialize)]
pub struct SettlementPlan {
    pub draw_id: u64,
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

    let first_three = trailing_digits(&result.first_prize, 3);
    let first_two = trailing_digits(&result.first_prize, 2);
    let bottom_two = normalise(&result.bottom_two);
    let won = match tier {
        Some(Tier::ThreeDigitDirect) => number == first_three && !first_three.is_empty(),
        Some(Tier::ThreeDigitTod) => {
            !first_three.is_empty()
                && number.len() == 3
                && sorted_digits(&number) == sorted_digits(&first_three)
        }
        Some(Tier::TwoDigitTop) => number == first_two && !first_two.is_empty(),
        Some(Tier::TwoDigitBottom) => number == bottom_two && bottom_two.len() == 2,
        Some(Tier::RunTop) => {
            number.len() == 1 && !first_three.is_empty() && first_three.contains(&number)
        }
        Some(Tier::RunBottom) => {
            number.len() == 1 && bottom_two.len() == 2 && bottom_two.contains(&number)
        }
        None => false,
    };

    let prize = if won {
        match tier {
            Some(t) => {
                // The HTTP handler validates this value before plan generation.
                // Keep invalid data non-paying if decide() is called independently.
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

/// Trim surrounding whitespace while keeping the digit string and leading zeros intact.
///
/// Selection and result inputs are validated as ASCII digits before a plan is produced.
fn normalise(raw: &str) -> String {
    raw.trim().to_string()
}

fn trailing_digits(raw: &str, width: usize) -> String {
    let digits = normalise(raw);
    if digits.len() < width {
        return String::new();
    }
    digits[digits.len() - width..].to_string()
}

fn sorted_digits(raw: &str) -> String {
    let mut digits: Vec<char> = normalise(raw).chars().collect();
    digits.sort_unstable();
    digits.into_iter().collect()
}

/// Compute a settlement plan for a batch of selections.
///
/// Chunking is the CALLER's job: this function is O(n) in the slice it is given
/// and holds nothing. That is what lets the worker consume a 100k-selection draw
/// in bounded windows without the memory profile growing with the draw size.
pub fn plan(draw_id: u64, result: &PublishedResult, selections: &[Selection]) -> SettlementPlan {
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
/// The worker API contract uses `X-Settlement-Timestamp` and
/// `X-Settlement-Signature` headers. The timestamp is signed with the exact raw
/// request bytes and cannot be edited to extend a signature's life.
pub fn verify_signature(
    secret: &[u8],
    timestamp: &str,
    body: &[u8],
    signature_hex: &str,
    tolerance_seconds: i64,
) -> Result<(), String> {
    let ts: i64 = timestamp
        .parse()
        .map_err(|_| "timestamp is not an integer".to_string())?;

    if tolerance_seconds > 0 {
        let now = Utc::now().timestamp();
        if (now - ts).abs() > tolerance_seconds {
            return Err(format!(
                "timestamp outside tolerance window ({}s)",
                (now - ts).abs()
            ));
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
    if constant_time_eq(
        expected.as_bytes(),
        signature_hex.to_ascii_lowercase().as_bytes(),
    ) {
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
/// financial write. The window is an inclusive id range so the caller can page
/// without holding a cursor.
pub async fn load_selections(
    pool: &MySqlPool,
    draw_id: u64,
    from_bet_id: u64,
    to_bet_id: u64,
) -> Result<Vec<Selection>, sqlx::Error> {
    let rows = sqlx::query(
        r#"
        SELECT
            bi.bet_id            AS bet_id,
            bi.id                AS bet_item_id,
            bi.number            AS number,
            CASE
                WHEN b.type = '3d' AND bi.position = 'top' THEN '3d_direct'
                WHEN b.type = 'tod' AND bi.position = 'top' THEN '3d_tod'
                WHEN b.type = '2d' AND bi.position = 'top' THEN '2d_top'
                WHEN b.type = '2d' AND bi.position = 'bottom' THEN '2d_bottom'
                WHEN b.type = 'run' AND bi.position = 'top' THEN 'run_top'
                WHEN b.type = 'run' AND bi.position = 'bottom' THEN 'run_bottom'
                ELSE 'unresolved'
            END                  AS tier,
            CAST(bi.amount AS CHAR) AS stake,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(bi.metadata, '$.market')) AS CHAR) AS recorded_market
        FROM bet_items bi
        JOIN bets b ON b.id = bi.bet_id
        WHERE b.draw_id = ?
          AND b.deleted_at IS NULL
          AND bi.deleted_at IS NULL
          AND bi.bet_id >= ?
          AND bi.bet_id <= ?
        ORDER BY bi.bet_id, bi.id
        "#,
    )
    .bind(draw_id)
    .bind(from_bet_id)
    .bind(to_bet_id)
    .fetch_all(pool)
    .await?;

    rows.into_iter()
        .map(|row| {
            Ok(Selection {
                bet_id: row.try_get("bet_id")?,
                bet_item_id: row.try_get("bet_item_id")?,
                number: row.try_get("number")?,
                tier: row.try_get("tier")?,
                stake: row.try_get("stake")?,
                recorded_market: row.try_get("recorded_market")?,
            })
        })
        .collect()
}

/// Confirm the pinned result fingerprint for a draw.
///
/// The whole plan is keyed to this value. If it has moved, the caller's frozen
/// chunk plan is stale and the run must abort rather than settle half a draw
/// against one number and half against another.
pub struct CurrentPublishedResult {
    pub fingerprint: String,
    pub first_prize: String,
    pub bottom_two: Option<String>,
}

pub async fn current_published_result(
    pool: &MySqlPool,
    draw_id: u64,
) -> Result<CurrentPublishedResult, sqlx::Error> {
    let row = sqlx::query(
        r#"SELECT p.result_fingerprint AS fingerprint,
                  r.first_prize AS first_prize,
                  CAST(JSON_UNQUOTE(JSON_EXTRACT(r.metadata, '$.bottom_two')) AS CHAR) AS bottom_two
           FROM draw_publications p
           JOIN draw_results r ON r.draw_id = p.draw_id
           WHERE p.draw_id = ?
             AND p.status IN ('published', 'republished')
             AND r.deleted_at IS NULL
           ORDER BY p.version DESC LIMIT 1"#,
    )
    .bind(draw_id)
    .fetch_one(pool)
    .await?;

    Ok(CurrentPublishedResult {
        fingerprint: row.try_get("fingerprint")?,
        first_prize: row.try_get("first_prize")?,
        bottom_two: row.try_get("bottom_two")?,
    })
}

// ============================================================================
// HTTP surface
// ============================================================================

#[derive(Clone)]
struct AppState {
    pool: MySqlPool,
    hmac_secret: Arc<Vec<u8>>,
}

#[derive(Debug, Deserialize)]
struct PlanRequest {
    draw_id: u64,
    from_bet_id: u64,
    to_bet_id: u64,
    result: PublishedResult,
}

async fn health(State(state): State<Arc<AppState>>) -> impl IntoResponse {
    match sqlx::query("SELECT 1").execute(&state.pool).await {
        Ok(_) => (StatusCode::OK, Json(serde_json::json!({"status": "ok"}))),
        Err(e) => {
            error!(error = %e, "database health probe failed");
            (
                StatusCode::SERVICE_UNAVAILABLE,
                Json(serde_json::json!({"status": "degraded"})),
            )
        }
    }
}

/// Compute a settlement plan for one window.
///
/// The route is internal-only (backend network) and is NOT a money endpoint.
/// It returns a plan; the PHP side decides what to do with it.
async fn compute_plan(
    State(state): State<Arc<AppState>>,
    headers: HeaderMap,
    body: Bytes,
) -> impl IntoResponse {
    let timestamp = match headers
        .get("x-settlement-timestamp")
        .and_then(|v| v.to_str().ok())
    {
        Some(value) => value,
        None => {
            return (
                StatusCode::UNAUTHORIZED,
                Json(serde_json::json!({"error": "signature_required"})),
            )
        }
    };
    let signature = match headers
        .get("x-settlement-signature")
        .and_then(|v| v.to_str().ok())
    {
        Some(value) => value,
        None => {
            return (
                StatusCode::UNAUTHORIZED,
                Json(serde_json::json!({"error": "signature_required"})),
            )
        }
    };
    if verify_signature(&state.hmac_secret, timestamp, &body, signature, 300).is_err() {
        return (
            StatusCode::UNAUTHORIZED,
            Json(serde_json::json!({"error": "signature_invalid"})),
        );
    }
    let request: PlanRequest = match serde_json::from_slice(&body) {
        Ok(request) => request,
        Err(_) => {
            return (
                StatusCode::BAD_REQUEST,
                Json(serde_json::json!({"error": "invalid_request"})),
            )
        }
    };
    if request.draw_id != request.result.draw_id || request.from_bet_id > request.to_bet_id {
        return (
            StatusCode::UNPROCESSABLE_ENTITY,
            Json(serde_json::json!({"error": "invalid_request_range"})),
        );
    }

    let selections = match load_selections(
        &state.pool,
        request.draw_id,
        request.from_bet_id,
        request.to_bet_id,
    )
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
    match current_published_result(&state.pool, request.draw_id).await {
        Ok(current)
            if current.fingerprint != request.result.result_fingerprint
                || current.first_prize != request.result.first_prize
                || current.bottom_two.as_deref() != Some(request.result.bottom_two.as_str()) =>
        {
            warn!(
                draw_id = request.draw_id,
                "published result changed or request does not match stored result"
            );
            return (
                StatusCode::CONFLICT,
                Json(serde_json::json!({"error": "published_result_mismatch"})),
            );
        }
        Err(e) => {
            error!(error = %e, draw_id = request.draw_id, "published result read failed");
            return (
                StatusCode::INTERNAL_SERVER_ERROR,
                Json(serde_json::json!({"error": "published_result_read_failed"})),
            );
        }
        _ => {}
    }

    if selections.iter().any(|selection| {
        let Some(tier) = Tier::parse(&selection.tier) else {
            return true;
        };
        tier.digits() != selection.number.len()
            || !selection.number.bytes().all(|byte| byte.is_ascii_digit())
            || selection
                .recorded_market
                .as_deref()
                .is_some_and(|market| market != selection.tier)
            || Decimal::from_str_exact(&selection.stake).is_err()
            || Decimal::from_str_exact(&selection.stake).is_ok_and(|stake| stake <= Decimal::ZERO)
    }) {
        return (
            StatusCode::UNPROCESSABLE_ENTITY,
            Json(serde_json::json!({"error": "invalid_selection"})),
        );
    }
    if !request.result.first_prize.len().eq(&6)
        || !request
            .result
            .first_prize
            .bytes()
            .all(|byte| byte.is_ascii_digit())
        || request.result.bottom_two.len() != 2
        || !request
            .result
            .bottom_two
            .bytes()
            .all(|byte| byte.is_ascii_digit())
    {
        return (
            StatusCode::UNPROCESSABLE_ENTITY,
            Json(serde_json::json!({"error": "invalid_published_result"})),
        );
    }
    let settlement_plan = plan(request.draw_id, &request.result, &selections);

    (
        StatusCode::OK,
        Json(serde_json::to_value(settlement_plan).unwrap()),
    )
}

fn load_env_or_file(variable: &str, default_path: &str) -> anyhow::Result<String> {
    if let Ok(value) = std::env::var(variable) {
        let value = value.trim().to_string();
        anyhow::ensure!(!value.is_empty(), "{variable} must not be empty");
        return Ok(value);
    }

    let path_variable = format!("{variable}_FILE");
    let path = std::env::var(&path_variable).unwrap_or_else(|_| default_path.to_string());
    let value = std::fs::read_to_string(&path)
        .with_context(|| format!("could not read {variable} from {path}"))?
        .trim()
        .to_string();
    anyhow::ensure!(!value.is_empty(), "{variable} file must not be empty");
    Ok(value)
}

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    tracing_subscriber::registry()
        .with(EnvFilter::try_from_default_env().unwrap_or_else(|_| EnvFilter::new("info")))
        .with(tracing_subscriber::fmt::layer().json())
        .init();

    if std::env::args().any(|arg| arg == "--healthcheck") {
        return healthcheck().await;
    }

    let database_url = load_env_or_file("DATABASE_URL", "/run/secrets/database_url")?;
    let hmac_secret = load_env_or_file(
        "SETTLEMENT_WORKER_HMAC_SECRET",
        "/run/secrets/settlement_worker_hmac_secret",
    )?;
    anyhow::ensure!(
        hmac_secret.as_bytes().len() >= 32,
        "settlement worker HMAC secret must contain at least 32 bytes"
    );

    let bind: SocketAddr = std::env::var("WORKER_BIND")
        .unwrap_or_else(|_| "0.0.0.0:9101".to_string())
        .parse()
        .context("WORKER_BIND must be a socket address")?;

    // Small pool on purpose. This worker is read-only and short-lived per
    // request; a large pool would starve the PHP fleet of connections during a
    // draw, which is exactly when PHP needs them most.
    let pool = MySqlPoolOptions::new()
        .max_connections(8)
        .acquire_timeout(Duration::from_secs(5))
        .connect(&database_url)
        .await
        .context("could not connect to MySQL/MariaDB")?;

    let state = Arc::new(AppState {
        pool,
        hmac_secret: Arc::new(hmac_secret.into_bytes()),
    });

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

/// Docker invokes this executable with `--healthcheck` in a scratch image where
/// no shell, curl, wget or package manager exists. Probe the running HTTP health
/// route instead of starting a second worker process.
async fn healthcheck() -> anyhow::Result<()> {
    let bind: SocketAddr = std::env::var("WORKER_BIND")
        .unwrap_or_else(|_| "0.0.0.0:9101".to_string())
        .parse()
        .context("WORKER_BIND must be a socket address")?;
    let host = if bind.ip().is_unspecified() {
        if bind.is_ipv4() { "127.0.0.1" } else { "::1" }.parse()?
    } else {
        bind.ip()
    };
    let address = SocketAddr::new(host, bind.port());
    let mut stream = TcpStream::connect(address)
        .await
        .context("worker health endpoint is unreachable")?;
    stream
        .write_all(b"GET /health HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
        .await?;
    let mut response = [0_u8; 512];
    let bytes_read = stream.read(&mut response).await?;
    let response =
        std::str::from_utf8(&response[..bytes_read]).context("health response is not UTF-8")?;
    anyhow::ensure!(
        response.starts_with("HTTP/1.1 200 ") || response.starts_with("HTTP/1.0 200 "),
        "worker health endpoint returned a non-200 response"
    );
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
            tier: "3d_direct".into(),
            stake: "100.00".into(),
            recorded_market: None,
        };

        assert!(!decide(&s, &r).won);
    }

    #[test]
    fn exact_first_prize_pays_at_the_multiplier() {
        let s = Selection {
            bet_id: 1,
            bet_item_id: 1,
            number: "456".into(),
            tier: "3d_direct".into(),
            stake: "10.00".into(),
            recorded_market: None,
        };

        let d = decide(&s, &result());
        assert!(d.won);
        assert_eq!(d.prize, "9000.00");
    }

    #[test]
    fn losing_selection_pays_exactly_zero() {
        let s = Selection {
            bet_id: 1,
            bet_item_id: 1,
            number: "999".into(),
            tier: "3d_direct".into(),
            stake: "10.00".into(),
            recorded_market: None,
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
            recorded_market: None,
        };

        let d = decide(&s, &result());
        assert!(
            !d.won,
            "an unrecognised tier must resolve to not-won, never to a payout"
        );
    }

    #[test]
    fn total_is_an_exact_decimal_sum() {
        // 0.1 + 0.2 in f64 is 0.30000000000000004. In Decimal it is 0.3.
        // This is the entire reason the worker does not use floats.
        let selections = vec![
            Selection {
                bet_id: 1,
                bet_item_id: 1,
                number: "56".into(),
                tier: "2d_top".into(),
                stake: "0.05".into(),
                recorded_market: None,
            },
            Selection {
                bet_id: 2,
                bet_item_id: 2,
                number: "56".into(),
                tier: "2d_top".into(),
                stake: "0.05".into(),
                recorded_market: None,
            },
        ];

        let mut r = result();
        r.first_prize = "123456".into();

        // 2d_top multiplier is 90: 0.05 * 90 = 4.5 per selection.
        let p = plan(1, &r, &selections);
        assert_eq!(p.winning_selections, 2);
        assert_eq!(p.total_prize, "9.00");
    }

    #[test]
    fn tod_matches_unique_permutations_and_pays_once() {
        let selection = Selection {
            bet_id: 1,
            bet_item_id: 1,
            number: "654".into(),
            tier: "3d_tod".into(),
            stake: "10.00".into(),
            recorded_market: None,
        };
        let decision = decide(&selection, &result());
        assert!(decision.won);
        assert_eq!(decision.prize, "450.00");
    }

    #[test]
    fn run_markets_pay_once_for_repeated_draw_digits() {
        let top = Selection {
            bet_id: 1,
            bet_item_id: 1,
            number: "6".into(),
            tier: "run_top".into(),
            stake: "10.00".into(),
            recorded_market: None,
        };
        let bottom = Selection {
            tier: "run_bottom".into(),
            number: "9".into(),
            ..top.clone()
        };
        assert_eq!(decide(&top, &result()).prize, "30.00");
        assert_eq!(decide(&bottom, &result()).prize, "40.00");
    }

    #[test]
    fn bottom_two_market_uses_published_metadata_value() {
        let selection = Selection {
            bet_id: 1,
            bet_item_id: 1,
            number: "99".into(),
            tier: "2d_bottom".into(),
            stake: "2.00".into(),
            recorded_market: Some("2d_bottom".into()),
        };
        assert_eq!(decide(&selection, &result()).prize, "180.00");
    }

    #[test]
    fn malformed_selection_digits_do_not_match() {
        let selection = Selection {
            bet_id: 1,
            bet_item_id: 1,
            number: "x56".into(),
            tier: "2d_top".into(),
            stake: "10.00".into(),
            recorded_market: None,
        };
        assert!(!decide(&selection, &result()).won);
    }

    #[tokio::test]
    #[ignore = "requires a disposable MariaDB/MySQL database in MYSQL_TEST_URL"]
    async fn mysql_schema_compatibility_reads_real_migrated_columns() {
        let url = std::env::var("MYSQL_TEST_URL")
            .expect("MYSQL_TEST_URL must point to a disposable integration-test database");
        let pool = MySqlPoolOptions::new()
            .max_connections(2)
            .connect(&url)
            .await
            .expect("connect to test MariaDB/MySQL");

        for statement in [
            "DROP TABLE IF EXISTS bet_items",
            "DROP TABLE IF EXISTS draw_publications",
            "DROP TABLE IF EXISTS draw_results",
            "DROP TABLE IF EXISTS bets",
        ] {
            sqlx::query(statement).execute(&pool).await.unwrap();
        }

        sqlx::query("CREATE TABLE bets (id BIGINT UNSIGNED PRIMARY KEY, draw_id BIGINT UNSIGNED NOT NULL, type VARCHAR(16) NOT NULL, deleted_at DATETIME NULL) ENGINE=InnoDB")
            .execute(&pool).await.unwrap();
        sqlx::query("CREATE TABLE bet_items (id BIGINT UNSIGNED PRIMARY KEY, bet_id BIGINT UNSIGNED NOT NULL, number VARCHAR(16) NOT NULL, position VARCHAR(32) NULL, amount DECIMAL(20,2) NOT NULL, metadata JSON NULL, deleted_at DATETIME NULL) ENGINE=InnoDB")
            .execute(&pool).await.unwrap();
        sqlx::query("CREATE TABLE draw_results (draw_id BIGINT UNSIGNED PRIMARY KEY, first_prize VARCHAR(16) NOT NULL, metadata JSON NULL, deleted_at DATETIME NULL) ENGINE=InnoDB")
            .execute(&pool).await.unwrap();
        sqlx::query("CREATE TABLE draw_publications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, draw_id BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL, status VARCHAR(32) NOT NULL, result_fingerprint CHAR(64) NOT NULL) ENGINE=InnoDB")
            .execute(&pool).await.unwrap();

        for (id, bet_type) in [(10_u64, "3d"), (11, "2d"), (12, "run"), (13, "3d")] {
            sqlx::query("INSERT INTO bets (id, draw_id, type, deleted_at) VALUES (?, 7, ?, ?)")
                .bind(id)
                .bind(bet_type)
                .bind(if id == 13 {
                    Some("2026-10-25 00:00:00")
                } else {
                    None
                })
                .execute(&pool)
                .await
                .unwrap();
        }
        for (id, bet_id, number, position, amount, market) in [
            (21_u64, 10_u64, "456", "top", "1.25", "3d_direct"),
            (22, 11, "99", "bottom", "2.00", "2d_bottom"),
            (23, 12, "6", "top", "0.50", "run_top"),
            (24, 13, "456", "top", "1.00", "3d_direct"),
        ] {
            let metadata = serde_json::json!({"market": market}).to_string();
            sqlx::query("INSERT INTO bet_items (id, bet_id, number, position, amount, metadata, deleted_at) VALUES (?, ?, ?, ?, ?, ?, NULL)")
                .bind(id)
                .bind(bet_id)
                .bind(number)
                .bind(position)
                .bind(amount)
                .bind(metadata)
                .execute(&pool)
                .await
                .unwrap();
        }
        sqlx::query("INSERT INTO draw_results (draw_id, first_prize, metadata, deleted_at) VALUES (7, '123456', JSON_OBJECT('bottom_two', '99'), NULL)")
            .execute(&pool).await.unwrap();
        sqlx::query("INSERT INTO draw_publications (draw_id, version, status, result_fingerprint) VALUES (7, 1, 'published', ?)")
            .bind("a".repeat(64))
            .execute(&pool)
            .await
            .unwrap();

        let selections = load_selections(&pool, 7, 10, 13).await.unwrap();
        assert_eq!(
            selections.len(),
            3,
            "deleted parent bets must not be selected"
        );
        assert_eq!(selections[0].bet_item_id, 21);
        assert_eq!(selections[0].tier, "3d_direct");
        assert_eq!(selections[0].stake, "1.25");
        assert_eq!(selections[0].recorded_market.as_deref(), Some("3d_direct"));
        assert_eq!(selections[1].tier, "2d_bottom");
        assert_eq!(selections[2].tier, "run_top");

        let current = current_published_result(&pool, 7).await.unwrap();
        assert_eq!(current.fingerprint, "a".repeat(64));
        assert_eq!(current.first_prize, "123456");
        assert_eq!(current.bottom_two.as_deref(), Some("99"));

        for statement in [
            "DROP TABLE bet_items",
            "DROP TABLE draw_publications",
            "DROP TABLE draw_results",
            "DROP TABLE bets",
        ] {
            sqlx::query(statement).execute(&pool).await.unwrap();
        }
        pool.close().await;
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
