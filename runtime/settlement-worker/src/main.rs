//! Thai Lottery — Rust settlement worker (MySQL single-contract engine)
//!
//! Aligns the Rust hot-path settlement worker with the canonical Laravel MySQL
//! schema (`draws`, `winning_numbers`, `settlement_runs`, `bets`, `bet_items`,
//! `payouts`, `wallets`, `ledger_accounts`, `financial_transactions`,
//! `ledger_entries`).
//!
//! Invariants enforced:
//!   1. All monetary values use `rust_decimal::Decimal` (scale 2,
//!      `MidpointAwayFromZero` matching PHP `Money` `PHP_ROUND_HALF_UP`) and
//!      serialize as strings. `f32`/`f64` are never used.
//!   2. Settlement runs in bounded chunks (`settlement_runs.cursor_bet_id`) so
//!      locks are held per chunk rather than across an entire draw.
//!   3. `safety_mode` (`DISABLED`, `DRY_RUN`, `LIVE`) is honored identically to
//!      PHP: wallet credits and ledger postings occur only in `LIVE` mode.
//!   4. When `LIVE`, every payout creates one `financial_transactions` row and
//!      a balanced pair of `ledger_entries` (`debit` prize expense, `credit`
//!      player wallet liability) while locking `wallets` in ascending ID order
//!      and incrementing `wallets.version`.
//!   5. `POST /settle` requires HMAC-SHA256 authentication (`X-Signature` over
//!      `"{timestamp}.{raw_body}"` and `X-Signature-Timestamp` within 300s) with
//!      replay protection.

use std::collections::{HashMap, VecDeque};
use std::env;
use std::fs;
use std::net::SocketAddr;
use std::str::FromStr;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use axum::body::Bytes;
use axum::extract::State;
use axum::http::{HeaderMap, StatusCode};
use axum::routing::{get, post};
use axum::{Json, Router};
use hmac::{Hmac, Mac};
use rust_decimal::{Decimal, RoundingStrategy};
use serde::{Deserialize, Serialize};
use sha2::Sha256;
use sqlx::mysql::{MySqlConnectOptions, MySqlPool, MySqlPoolOptions};
use sqlx::{ConnectOptions, Executor, Row};
use subtle::ConstantTimeEq;
use thiserror::Error;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpStream;
use tokio::sync::watch;
use tracing::{error, info, warn};
use uuid::Uuid;

type HmacSha256 = Hmac<Sha256>;

const DEFAULT_CHUNK_SIZE: u32 = 500;
const DEFAULT_POLL_MS: u64 = 2000;
const DEFAULT_STALE_CLAIM_SECS: i64 = 120;
const DEFAULT_HMAC_TOLERANCE_SECS: i64 = 300;
const MAX_REPLAY_ENTRIES: usize = 4096;
const PAYOUT_REFERENCE_TYPE: &str = "App\\Models\\Payout";

#[derive(Debug, Error)]
pub enum SettlementError {
    #[error("database error: {0}")]
    Db(#[from] sqlx::Error),

    #[error("draw {0} is in status '{1}', expected 'drawn' with published results")]
    InvalidDrawState(u64, String),

    #[error("draw {0} has no published winning_numbers rows")]
    NoWinningNumbers(u64),

    #[error("ledger account '{0}' for currency '{1}' is missing or inactive")]
    MissingLedgerAccount(String, String),

    #[error("wallet for user {0} currency '{1}' is missing or not active")]
    UnavailableWallet(u64, String),

    #[error("ledger imbalance on payout {0}: debits={1} credits={2}")]
    UnbalancedPosting(String, Decimal, Decimal),

    #[error("invalid decimal value '{0}'")]
    InvalidDecimal(String),
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "UPPERCASE")]
pub enum SafetyMode {
    Disabled,
    DryRun,
    Live,
}

impl SafetyMode {
    pub fn parse(raw: &str) -> Option<Self> {
        match raw.trim().to_ascii_uppercase().as_str() {
            "DISABLED" => Some(Self::Disabled),
            "DRY_RUN" => Some(Self::DryRun),
            "LIVE" => Some(Self::Live),
            _ => None,
        }
    }

    pub fn as_str(self) -> &'static str {
        match self {
            Self::Disabled => "DISABLED",
            Self::DryRun => "DRY_RUN",
            Self::Live => "LIVE",
        }
    }
}

#[derive(Debug, Clone)]
pub struct WinningNumberRule {
    pub bet_type: String,
    pub number: String,
    pub position: Option<String>,
    pub payout_multiplier: u32,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct ChunkSettlementSummary {
    pub draw_id: u64,
    pub run_id: u64,
    pub run_status: String,
    pub safety_mode: String,
    pub chunk_bets_processed: u64,
    pub chunk_winning_selections: u64,
    pub chunk_payouts_created: u64,
    #[serde(with = "rust_decimal::serde::str")]
    pub chunk_prize_total: Decimal,
    pub cursor_bet_id: u64,
}

#[derive(Debug, Deserialize)]
pub struct SettleTriggerRequest {
    pub draw_id: u64,
    #[serde(default)]
    pub chunk_size: Option<u32>,
}

#[derive(Debug, Serialize)]
pub struct HealthResponse {
    pub status: &'static str,
    pub db_connected: bool,
    pub shutting_down: bool,
}

#[derive(Debug, Serialize)]
pub struct ErrorResponse {
    pub error: String,
    pub correlation_id: String,
}

pub struct ReplayGuard {
    seen: HashMap<String, i64>,
    order: VecDeque<(String, i64)>,
}

impl Default for ReplayGuard {
    fn default() -> Self {
        Self::new()
    }
}

impl ReplayGuard {
    pub fn new() -> Self {
        Self {
            seen: HashMap::new(),
            order: VecDeque::new(),
        }
    }

    pub fn check_and_record(&mut self, nonce_key: String, now_ts: i64, window_secs: i64) -> bool {
        let cutoff = now_ts.saturating_sub(window_secs);
        while let Some((front_key, ts)) = self.order.front() {
            if *ts < cutoff || self.order.len() >= MAX_REPLAY_ENTRIES {
                let (k, _) = self.order.pop_front().expect("front exists");
                self.seen.remove(&k);
            } else {
                let _ = front_key;
                break;
            }
        }

        if self.seen.contains_key(&nonce_key) {
            return false;
        }

        self.seen.insert(nonce_key.clone(), now_ts);
        self.order.push_back((nonce_key, now_ts));
        true
    }
}

pub struct AppState {
    pub pool: MySqlPool,
    pub hmac_secret: Option<Vec<u8>>,
    pub hmac_tolerance_secs: i64,
    pub safety_mode: SafetyMode,
    pub chunk_size: u32,
    pub stale_claim_secs: i64,
    pub replay_guard: Mutex<ReplayGuard>,
    pub chunks_settled_total: AtomicU64,
    pub bets_settled_total: AtomicU64,
    pub payouts_created_total: AtomicU64,
    pub errors_total: AtomicU64,
    pub shutting_down: AtomicBool,
}

/// Exact scale-2 decimal rounding (`MidpointAwayFromZero`), identical to PHP
/// `App\Support\Money` (`PHP_ROUND_HALF_UP`).
pub fn round_money(value: Decimal) -> Decimal {
    value.round_dp_with_strategy(2, RoundingStrategy::MidpointAwayFromZero)
}

/// Evaluates a single bet selection against the draw's published winning numbers.
/// Returns `(is_winner, effective_multiplier, prize_amount)`.
pub fn evaluate_selection(
    bet_type: &str,
    number: &str,
    position: Option<&str>,
    stake_amount: Decimal,
    item_multiplier: u32,
    rules: &[WinningNumberRule],
) -> (bool, Decimal, Decimal) {
    let normalized_type = bet_type.trim().to_ascii_lowercase();
    let normalized_number = number.trim();
    let normalized_pos = position.map(str::trim).filter(|s| !s.is_empty());

    let mut matched_multiplier: Option<u32> = None;

    for rule in rules {
        if rule.bet_type.trim().eq_ignore_ascii_case(&normalized_type)
            && rule.number.trim() == normalized_number
        {
            let rule_pos = rule
                .position
                .as_deref()
                .map(str::trim)
                .filter(|s| !s.is_empty());
            if rule_pos == normalized_pos {
                let effective = if item_multiplier > 0 {
                    item_multiplier
                } else {
                    rule.payout_multiplier
                };
                matched_multiplier =
                    Some(matched_multiplier.map_or(effective, |cur| cur.max(effective)));
            }
        }
    }

    match matched_multiplier {
        Some(mult) if mult > 0 => {
            let mult_dec = Decimal::from(mult);
            let prize = round_money(stake_amount * mult_dec);
            (true, mult_dec, prize)
        }
        _ => (false, Decimal::ZERO, Decimal::ZERO),
    }
}

/// Verifies `X-Signature` = hex(`HMAC-SHA256(secret, "{timestamp}.{body}")`)
/// in constant time and checks that `timestamp` is within `tolerance_secs` of `now_ts`.
pub fn verify_hmac_signature(
    secret: &[u8],
    timestamp_header: &str,
    signature_header: &str,
    body: &[u8],
    now_ts: i64,
    tolerance_secs: i64,
) -> Result<i64, &'static str> {
    if secret.is_empty() {
        return Err("hmac secret is empty");
    }

    let timestamp: i64 = timestamp_header
        .trim()
        .parse()
        .map_err(|_| "invalid signature timestamp")?;

    if (now_ts - timestamp).abs() > tolerance_secs {
        return Err("signature timestamp outside tolerance window");
    }

    let normalized_sig = signature_header
        .trim()
        .strip_prefix("sha256=")
        .unwrap_or_else(|| signature_header.trim());

    let provided_bytes = hex::decode(normalized_sig).map_err(|_| "malformed hex signature")?;

    let mut mac = HmacSha256::new_from_slice(secret).map_err(|_| "invalid hmac key")?;
    mac.update(timestamp.to_string().as_bytes());
    mac.update(b".");
    mac.update(body);
    let expected_bytes = mac.finalize().into_bytes();

    if provided_bytes.ct_eq(expected_bytes.as_slice()).into() {
        Ok(timestamp)
    } else {
        Err("signature mismatch")
    }
}

pub fn compute_hmac_signature(secret: &[u8], timestamp: i64, body: &[u8]) -> String {
    let mut mac = HmacSha256::new_from_slice(secret).expect("valid hmac key");
    mac.update(timestamp.to_string().as_bytes());
    mac.update(b".");
    mac.update(body);
    hex::encode(mac.finalize().into_bytes())
}

fn load_env_or_file(var_name: &str) -> Option<String> {
    let file_var = format!("{var_name}_FILE");
    if let Ok(path) = env::var(&file_var) {
        let trimmed_path = path.trim();
        if !trimmed_path.is_empty() {
            let content = fs::read_to_string(trimmed_path)
                .unwrap_or_else(|e| panic!("failed to read {file_var} at {trimmed_path}: {e}"));
            let value = content.trim().to_string();
            assert!(!value.is_empty(), "{file_var} at {trimmed_path} is empty");
            return Some(value);
        }
    }

    env::var(var_name)
        .ok()
        .map(|v| v.trim().to_string())
        .filter(|v| !v.is_empty())
}

fn correlation_id_from_headers(headers: &HeaderMap) -> String {
    headers
        .get("x-correlation-id")
        .or_else(|| headers.get("x-request-id"))
        .and_then(|v| v.to_str().ok())
        .map(str::trim)
        .filter(|v| !v.is_empty() && v.len() <= 128)
        .map(ToOwned::to_owned)
        .unwrap_or_else(|| Uuid::new_v4().to_string())
}

/// Settles at most one chunk (`chunk_size` bets) for `draw_id` inside a single
/// MySQL transaction, advancing `settlement_runs.cursor_bet_id` atomically.
pub async fn settle_draw_chunk(
    state: &AppState,
    draw_id: u64,
    chunk_override: Option<u32>,
    correlation_id: &str,
) -> Result<ChunkSettlementSummary, SettlementError> {
    let started = Instant::now();
    let mut tx = state.pool.begin().await?;

    // 1. Lock the draw row and verify it is eligible for settlement.
    let draw_row = sqlx::query(
        "SELECT id, status, result_published_at, total_amount_wagered
         FROM draws
         WHERE id = ? AND deleted_at IS NULL
         FOR UPDATE",
    )
    .bind(draw_id)
    .fetch_optional(&mut *tx)
    .await?;

    let Some(draw_row) = draw_row else {
        return Err(SettlementError::InvalidDrawState(
            draw_id,
            "missing".to_string(),
        ));
    };

    let draw_status: String = draw_row.try_get("status")?;
    let total_wagered: Decimal = draw_row
        .try_get::<Decimal, _>("total_amount_wagered")
        .unwrap_or(Decimal::ZERO);

    if draw_status == "completed" {
        if let Some(run_row) = sqlx::query(
            "SELECT id, status, safety_mode, cursor_bet_id
             FROM settlement_runs
             WHERE draw_id = ?",
        )
        .bind(draw_id)
        .fetch_optional(&mut *tx)
        .await?
        {
            tx.commit().await?;
            return Ok(ChunkSettlementSummary {
                draw_id,
                run_id: run_row.try_get("id")?,
                run_status: run_row.try_get("status")?,
                safety_mode: run_row
                    .try_get::<Option<String>, _>("safety_mode")?
                    .unwrap_or_else(|| state.safety_mode.as_str().to_string()),
                chunk_bets_processed: 0,
                chunk_winning_selections: 0,
                chunk_payouts_created: 0,
                chunk_prize_total: Decimal::ZERO,
                cursor_bet_id: run_row.try_get("cursor_bet_id")?,
            });
        }
    }

    if draw_status != "drawn" {
        return Err(SettlementError::InvalidDrawState(draw_id, draw_status));
    }

    // 2. Load published winning_numbers for this draw.
    let rule_rows = sqlx::query(
        "SELECT bet_type, number, position, payout_multiplier
         FROM winning_numbers
         WHERE draw_id = ? AND deleted_at IS NULL AND published_at IS NOT NULL
         ORDER BY id ASC",
    )
    .bind(draw_id)
    .fetch_all(&mut *tx)
    .await?;

    if rule_rows.is_empty() {
        return Err(SettlementError::NoWinningNumbers(draw_id));
    }

    let mut rules = Vec::with_capacity(rule_rows.len());
    for row in rule_rows {
        rules.push(WinningNumberRule {
            bet_type: row.try_get("bet_type")?,
            number: row.try_get("number")?,
            position: row.try_get("position")?,
            payout_multiplier: row.try_get("payout_multiplier")?,
        });
    }

    // 3. Ensure a `settlement_runs` row exists and lock it FOR UPDATE.
    let chunk_size = chunk_override.unwrap_or(state.chunk_size).clamp(1, 5000);
    let total_bets_count: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM bets WHERE draw_id = ? AND deleted_at IS NULL AND status IN ('pending', 'confirmed', 'won', 'lost')",
    )
    .bind(draw_id)
    .fetch_one(&mut *tx)
    .await?;

    sqlx::query(
        "INSERT IGNORE INTO settlement_runs
            (draw_id, status, chunk_size, cursor_bet_id, bets_total, bets_settled,
             selections_evaluated, selections_written, winning_selections, payouts_created,
             total_stake, total_prize, mode, safety_mode, chunks_processed, started_at,
             last_chunk_at, created_at, updated_at)
         VALUES
            (?, 'open', ?, 0, ?, 0, 0, 0, 0, 0, 0.00, 0.00, 'monetary', ?, 0, UTC_TIMESTAMP(),
             UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
    )
    .bind(draw_id)
    .bind(chunk_size)
    .bind(total_bets_count as u64)
    .bind(state.safety_mode.as_str())
    .execute(&mut *tx)
    .await?;

    let run_row = sqlx::query(
        "SELECT id, status, chunk_size, cursor_bet_id, safety_mode, total_prize
         FROM settlement_runs
         WHERE draw_id = ?
         FOR UPDATE",
    )
    .bind(draw_id)
    .fetch_one(&mut *tx)
    .await?;

    let run_id: u64 = run_row.try_get("id")?;
    let run_status: String = run_row.try_get("status")?;
    let cursor_bet_id: u64 = run_row.try_get("cursor_bet_id")?;
    let recorded_chunk_size: u32 = run_row.try_get("chunk_size")?;
    let effective_chunk_size = chunk_override.unwrap_or(recorded_chunk_size).clamp(1, 5000);
    let run_safety_str: String = run_row
        .try_get::<Option<String>, _>("safety_mode")?
        .unwrap_or_else(|| state.safety_mode.as_str().to_string());
    let run_safety = SafetyMode::parse(&run_safety_str).unwrap_or(SafetyMode::Disabled);
    let prior_total_prize: Decimal = run_row
        .try_get::<Decimal, _>("total_prize")
        .unwrap_or(Decimal::ZERO);

    if run_status == "completed" {
        tx.commit().await?;
        return Ok(ChunkSettlementSummary {
            draw_id,
            run_id,
            run_status,
            safety_mode: run_safety.as_str().to_string(),
            chunk_bets_processed: 0,
            chunk_winning_selections: 0,
            chunk_payouts_created: 0,
            chunk_prize_total: Decimal::ZERO,
            cursor_bet_id,
        });
    }

    // 4. Lock the next chunk of unsettled bets in ascending ID order.
    let bet_rows = sqlx::query(
        "SELECT id, user_id, ticket_id, type, currency, stake_amount
         FROM bets
         WHERE draw_id = ?
           AND id > ?
           AND deleted_at IS NULL
           AND status IN ('pending', 'confirmed')
         ORDER BY id ASC
         LIMIT ?
         FOR UPDATE",
    )
    .bind(draw_id)
    .bind(cursor_bet_id)
    .bind(effective_chunk_size)
    .fetch_all(&mut *tx)
    .await?;

    if bet_rows.is_empty() {
        // No further unsettled bets after cursor_bet_id -> finalize run and draw.
        sqlx::query(
            "UPDATE settlement_runs
             SET status = 'completed',
                 completed_at = UTC_TIMESTAMP(),
                 last_chunk_at = UTC_TIMESTAMP(),
                 updated_at = UTC_TIMESTAMP()
             WHERE id = ?",
        )
        .bind(run_id)
        .execute(&mut *tx)
        .await?;

        let house_profit = round_money(total_wagered - prior_total_prize);
        sqlx::query(
            "UPDATE draws
             SET status = 'completed',
                 completed_at = UTC_TIMESTAMP(),
                 total_payout = ?,
                 house_profit = ?,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = ?",
        )
        .bind(prior_total_prize)
        .bind(house_profit)
        .bind(draw_id)
        .execute(&mut *tx)
        .await?;

        tx.commit().await?;
        return Ok(ChunkSettlementSummary {
            draw_id,
            run_id,
            run_status: "completed".to_string(),
            safety_mode: run_safety.as_str().to_string(),
            chunk_bets_processed: 0,
            chunk_winning_selections: 0,
            chunk_payouts_created: 0,
            chunk_prize_total: Decimal::ZERO,
            cursor_bet_id,
        });
    }

    let mut new_cursor_bet_id = cursor_bet_id;
    let mut chunk_bets_processed: u64 = 0;
    let mut chunk_selections_evaluated: u64 = 0;
    let mut chunk_selections_written: u64 = 0;
    let mut chunk_winning_selections: u64 = 0;
    let mut chunk_payouts_created: u64 = 0;
    let mut chunk_stake_total = Decimal::ZERO;
    let mut chunk_prize_total = Decimal::ZERO;

    for bet_row in &bet_rows {
        let bet_id: u64 = bet_row.try_get("id")?;
        let user_id: u64 = bet_row.try_get("user_id")?;
        let ticket_id: Option<u64> = bet_row.try_get("ticket_id")?;
        let bet_type: String = bet_row.try_get("type")?;
        let currency: String = bet_row.try_get("currency")?;
        let stake_amount: Decimal = round_money(bet_row.try_get("stake_amount")?);

        new_cursor_bet_id = bet_id;
        chunk_bets_processed += 1;
        chunk_stake_total = round_money(chunk_stake_total + stake_amount);

        let item_rows = sqlx::query(
            "SELECT id, number, position, amount, payout_multiplier
             FROM bet_items
             WHERE bet_id = ? AND deleted_at IS NULL
             ORDER BY id ASC
             FOR UPDATE",
        )
        .bind(bet_id)
        .fetch_all(&mut *tx)
        .await?;

        let mut bet_payout = Decimal::ZERO;
        let mut max_winning_multiplier = Decimal::ZERO;

        for item_row in item_rows {
            let item_id: u64 = item_row.try_get("id")?;
            let number: String = item_row.try_get("number")?;
            let position: Option<String> = item_row.try_get("position")?;
            let amount: Decimal = round_money(item_row.try_get("amount")?);
            let item_mult: u32 = item_row.try_get("payout_multiplier")?;

            chunk_selections_evaluated += 1;
            let (is_winner, eff_mult, prize) = evaluate_selection(
                &bet_type,
                &number,
                position.as_deref(),
                amount,
                item_mult,
                &rules,
            );

            sqlx::query(
                "UPDATE bet_items
                 SET is_winner = ?,
                     actual_payout = ?,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = ?",
            )
            .bind(is_winner)
            .bind(prize)
            .bind(item_id)
            .execute(&mut *tx)
            .await?;

            chunk_selections_written += 1;
            if is_winner {
                chunk_winning_selections += 1;
                bet_payout = round_money(bet_payout + prize);
                if eff_mult > max_winning_multiplier {
                    max_winning_multiplier = eff_mult;
                }
            }
        }

        if bet_payout > Decimal::ZERO {
            chunk_prize_total = round_money(chunk_prize_total + bet_payout);
            let payout_ref = format!("PO-D{draw_id}-B{bet_id}");
            let payout_status = match run_safety {
                SafetyMode::Live => "completed",
                SafetyMode::Disabled | SafetyMode::DryRun => "pending",
            };

            let wallet_row = sqlx::query(
                "SELECT id, balance, status
                 FROM wallets
                 WHERE user_id = ? AND type = 'primary' AND currency = ? AND deleted_at IS NULL
                 FOR UPDATE",
            )
            .bind(user_id)
            .bind(&currency)
            .fetch_optional(&mut *tx)
            .await?;

            let wallet_id: Option<u64> = wallet_row.as_ref().and_then(|r| r.try_get("id").ok());

            let payout_insert = sqlx::query(
                "INSERT INTO payouts
                    (uuid, reference_number, draw_id, user_id, bet_id, ticket_id, wallet_id,
                     status, currency, amount, multiplier, processed_at, created_at, updated_at)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     CASE WHEN ? = 'completed' THEN UTC_TIMESTAMP() ELSE NULL END,
                     UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            )
            .bind(Uuid::new_v4().to_string())
            .bind(&payout_ref)
            .bind(draw_id)
            .bind(user_id)
            .bind(bet_id)
            .bind(ticket_id)
            .bind(wallet_id)
            .bind(payout_status)
            .bind(&currency)
            .bind(bet_payout)
            .bind(max_winning_multiplier)
            .bind(payout_status)
            .execute(&mut *tx)
            .await?;

            let payout_id = payout_insert.last_insert_id();
            chunk_payouts_created += 1;

            if run_safety == SafetyMode::Live {
                let Some(ref locked_wallet) = wallet_row else {
                    return Err(SettlementError::UnavailableWallet(user_id, currency));
                };
                let w_status: String = locked_wallet.try_get("status")?;
                if w_status != "active" {
                    return Err(SettlementError::UnavailableWallet(user_id, currency));
                }
                let w_id: u64 = locked_wallet.try_get("id")?;
                let current_balance: Decimal = round_money(locked_wallet.try_get("balance")?);
                let new_balance = round_money(current_balance + bet_payout);

                // Resolve double-entry ledger accounts (Expense debit, Player Liability credit).
                let expense_account = sqlx::query(
                    "SELECT id, current_balance
                     FROM ledger_accounts
                     WHERE code IN ('EXPENSE_PRIZE_PAYOUTS', '5100')
                       AND currency = ?
                       AND is_active = 1
                       AND deleted_at IS NULL
                     ORDER BY id ASC
                     LIMIT 1
                     FOR UPDATE",
                )
                .bind(&currency)
                .fetch_optional(&mut *tx)
                .await?;

                let Some(expense_account) = expense_account else {
                    return Err(SettlementError::MissingLedgerAccount(
                        "EXPENSE_PRIZE_PAYOUTS".to_string(),
                        currency,
                    ));
                };

                let liability_account = sqlx::query(
                    "SELECT id, current_balance
                     FROM ledger_accounts
                     WHERE code IN ('LIABILITY_PLAYER_WALLETS', '2100')
                       AND currency = ?
                       AND is_active = 1
                       AND deleted_at IS NULL
                     ORDER BY id ASC
                     LIMIT 1
                     FOR UPDATE",
                )
                .bind(&currency)
                .fetch_optional(&mut *tx)
                .await?;

                let Some(liability_account) = liability_account else {
                    return Err(SettlementError::MissingLedgerAccount(
                        "LIABILITY_PLAYER_WALLETS".to_string(),
                        currency,
                    ));
                };

                let expense_acc_id: u64 = expense_account.try_get("id")?;
                let expense_bal: Decimal = round_money(expense_account.try_get("current_balance")?);
                let liability_acc_id: u64 = liability_account.try_get("id")?;
                let liability_bal: Decimal =
                    round_money(liability_account.try_get("current_balance")?);

                let debit_amount = bet_payout;
                let credit_amount = bet_payout;
                if debit_amount != credit_amount {
                    return Err(SettlementError::UnbalancedPosting(
                        payout_ref,
                        debit_amount,
                        credit_amount,
                    ));
                }

                let tx_ref = format!("FT-PO-D{draw_id}-B{bet_id}");
                let idempotency_key = format!("settlement-payout:{draw_id}:{bet_id}");
                let description = format!("Prize payout for draw #{draw_id} bet #{bet_id}");

                let ft_insert = sqlx::query(
                    "INSERT INTO financial_transactions
                        (uuid, reference_number, user_id, wallet_id, type, status, currency,
                         amount, fee, description, idempotency_key, reference_type, reference_id,
                         processed_at, created_at, updated_at)
                     VALUES
                        (?, ?, ?, ?, 'prize_payout', 'completed', ?, ?, 0.00, ?, ?, ?, ?,
                         UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                )
                .bind(Uuid::new_v4().to_string())
                .bind(&tx_ref)
                .bind(user_id)
                .bind(w_id)
                .bind(&currency)
                .bind(bet_payout)
                .bind(&description)
                .bind(&idempotency_key)
                .bind(PAYOUT_REFERENCE_TYPE)
                .bind(payout_id)
                .execute(&mut *tx)
                .await?;

                let financial_tx_id = ft_insert.last_insert_id();

                let new_expense_bal = round_money(expense_bal + debit_amount);
                let new_liability_bal = round_money(liability_bal + credit_amount);

                // Debit prize expense account.
                sqlx::query(
                    "INSERT INTO ledger_entries
                        (ledger_account_id, financial_transaction_id, wallet_id, type, amount,
                         currency, balance_after, description, reference_type, reference_id,
                         posted_at, created_at, updated_at)
                     VALUES
                        (?, ?, NULL, 'debit', ?, ?, ?, ?, ?, ?,
                         UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                )
                .bind(expense_acc_id)
                .bind(financial_tx_id)
                .bind(debit_amount)
                .bind(&currency)
                .bind(new_expense_bal)
                .bind(&description)
                .bind(PAYOUT_REFERENCE_TYPE)
                .bind(payout_id)
                .execute(&mut *tx)
                .await?;

                // Credit player wallet liability account.
                sqlx::query(
                    "INSERT INTO ledger_entries
                        (ledger_account_id, financial_transaction_id, wallet_id, type, amount,
                         currency, balance_after, description, reference_type, reference_id,
                         posted_at, created_at, updated_at)
                     VALUES
                        (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?,
                         UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                )
                .bind(liability_acc_id)
                .bind(financial_tx_id)
                .bind(w_id)
                .bind(credit_amount)
                .bind(&currency)
                .bind(new_liability_bal)
                .bind(&description)
                .bind(PAYOUT_REFERENCE_TYPE)
                .bind(payout_id)
                .execute(&mut *tx)
                .await?;

                sqlx::query(
                    "UPDATE ledger_accounts
                     SET current_balance = ?, updated_at = UTC_TIMESTAMP()
                     WHERE id = ?",
                )
                .bind(new_expense_bal)
                .bind(expense_acc_id)
                .execute(&mut *tx)
                .await?;

                sqlx::query(
                    "UPDATE ledger_accounts
                     SET current_balance = ?, updated_at = UTC_TIMESTAMP()
                     WHERE id = ?",
                )
                .bind(new_liability_bal)
                .bind(liability_acc_id)
                .execute(&mut *tx)
                .await?;

                sqlx::query(
                    "UPDATE wallets
                     SET balance = ?,
                         total_won = total_won + ?,
                         version = version + 1,
                         updated_at = UTC_TIMESTAMP()
                     WHERE id = ?",
                )
                .bind(new_balance)
                .bind(bet_payout)
                .bind(w_id)
                .execute(&mut *tx)
                .await?;

                sqlx::query(
                    "UPDATE payouts
                     SET financial_transaction_id = ?, updated_at = UTC_TIMESTAMP()
                     WHERE id = ?",
                )
                .bind(financial_tx_id)
                .bind(payout_id)
                .execute(&mut *tx)
                .await?;
            }

            sqlx::query(
                "UPDATE bets
                 SET status = 'won',
                     actual_payout = ?,
                     payout_id = ?,
                     won_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = ?",
            )
            .bind(bet_payout)
            .bind(payout_id)
            .bind(bet_id)
            .execute(&mut *tx)
            .await?;
        } else {
            sqlx::query(
                "UPDATE bets
                 SET status = 'lost',
                     actual_payout = 0.00,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = ?",
            )
            .bind(bet_id)
            .execute(&mut *tx)
            .await?;
        }
    }

    // 5. Check whether any unsettled bets remain after `new_cursor_bet_id`.
    let remaining_unsettled: i64 = sqlx::query_scalar(
        "SELECT COUNT(*)
         FROM bets
         WHERE draw_id = ?
           AND id > ?
           AND deleted_at IS NULL
           AND status IN ('pending', 'confirmed')",
    )
    .bind(draw_id)
    .bind(new_cursor_bet_id)
    .fetch_one(&mut *tx)
    .await?;

    let final_run_status = if remaining_unsettled == 0 {
        "completed"
    } else {
        "open"
    };

    sqlx::query(
        "UPDATE settlement_runs
         SET status = ?,
             cursor_bet_id = ?,
             bets_settled = bets_settled + ?,
             selections_evaluated = selections_evaluated + ?,
             selections_written = selections_written + ?,
             winning_selections = winning_selections + ?,
             payouts_created = payouts_created + ?,
             total_stake = total_stake + ?,
             total_prize = total_prize + ?,
             chunks_processed = chunks_processed + 1,
             last_chunk_at = UTC_TIMESTAMP(),
             completed_at = CASE WHEN ? = 'completed' THEN UTC_TIMESTAMP() ELSE completed_at END,
             updated_at = UTC_TIMESTAMP()
         WHERE id = ?",
    )
    .bind(final_run_status)
    .bind(new_cursor_bet_id)
    .bind(chunk_bets_processed)
    .bind(chunk_selections_evaluated)
    .bind(chunk_selections_written)
    .bind(chunk_winning_selections)
    .bind(chunk_payouts_created)
    .bind(chunk_stake_total)
    .bind(chunk_prize_total)
    .bind(final_run_status)
    .bind(run_id)
    .execute(&mut *tx)
    .await?;

    if final_run_status == "completed" {
        let cumulative_prize = round_money(prior_total_prize + chunk_prize_total);
        let house_profit = round_money(total_wagered - cumulative_prize);
        sqlx::query(
            "UPDATE draws
             SET status = 'completed',
                 completed_at = UTC_TIMESTAMP(),
                 total_payout = ?,
                 house_profit = ?,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = ?",
        )
        .bind(cumulative_prize)
        .bind(house_profit)
        .bind(draw_id)
        .execute(&mut *tx)
        .await?;
    }

    tx.commit().await?;

    state.chunks_settled_total.fetch_add(1, Ordering::Relaxed);
    state
        .bets_settled_total
        .fetch_add(chunk_bets_processed, Ordering::Relaxed);
    state
        .payouts_created_total
        .fetch_add(chunk_payouts_created, Ordering::Relaxed);

    info!(
        correlation_id = %correlation_id,
        draw_id = draw_id,
        run_id = run_id,
        run_status = %final_run_status,
        safety_mode = %run_safety.as_str(),
        chunk_bets_processed = chunk_bets_processed,
        chunk_winning_selections = chunk_winning_selections,
        chunk_payouts_created = chunk_payouts_created,
        chunk_prize_total = %chunk_prize_total,
        cursor_bet_id = new_cursor_bet_id,
        elapsed_ms = started.elapsed().as_millis() as u64,
        "settlement chunk committed"
    );

    Ok(ChunkSettlementSummary {
        draw_id,
        run_id,
        run_status: final_run_status.to_string(),
        safety_mode: run_safety.as_str().to_string(),
        chunk_bets_processed,
        chunk_winning_selections,
        chunk_payouts_created,
        chunk_prize_total,
        cursor_bet_id: new_cursor_bet_id,
    })
}

async fn poll_and_settle_next(state: &AppState) -> Result<bool, SettlementError> {
    let candidate: Option<u64> = sqlx::query_scalar(
        "SELECT sr.draw_id
         FROM settlement_runs sr
         INNER JOIN draws d ON d.id = sr.draw_id
         WHERE sr.status = 'open'
           AND d.status = 'drawn'
           AND d.deleted_at IS NULL
           AND (sr.last_chunk_at IS NULL OR sr.last_chunk_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND))
         ORDER BY sr.id ASC
         LIMIT 1",
    )
    .bind(state.stale_claim_secs)
    .fetch_optional(&state.pool)
    .await?;

    let Some(draw_id) = candidate else {
        return Ok(false);
    };

    let correlation_id = Uuid::new_v4().to_string();
    settle_draw_chunk(state, draw_id, None, &correlation_id).await?;
    Ok(true)
}

async fn healthz_handler(State(state): State<Arc<AppState>>) -> (StatusCode, Json<HealthResponse>) {
    let shutting_down = state.shutting_down.load(Ordering::Relaxed);
    let db_ok = sqlx::query_scalar::<_, i32>("SELECT 1")
        .fetch_one(&state.pool)
        .await
        .is_ok();

    if db_ok && !shutting_down {
        (
            StatusCode::OK,
            Json(HealthResponse {
                status: "ok",
                db_connected: true,
                shutting_down: false,
            }),
        )
    } else {
        (
            StatusCode::SERVICE_UNAVAILABLE,
            Json(HealthResponse {
                status: "degraded",
                db_connected: db_ok,
                shutting_down,
            }),
        )
    }
}

async fn metrics_handler(State(state): State<Arc<AppState>>) -> (StatusCode, String) {
    let body = format!(
        "# TYPE settlement_chunks_settled_total counter\n\
         settlement_chunks_settled_total {}\n\
         # TYPE settlement_bets_settled_total counter\n\
         settlement_bets_settled_total {}\n\
         # TYPE settlement_payouts_created_total counter\n\
         settlement_payouts_created_total {}\n\
         # TYPE settlement_errors_total counter\n\
         settlement_errors_total {}\n",
        state.chunks_settled_total.load(Ordering::Relaxed),
        state.bets_settled_total.load(Ordering::Relaxed),
        state.payouts_created_total.load(Ordering::Relaxed),
        state.errors_total.load(Ordering::Relaxed),
    );
    (StatusCode::OK, body)
}

async fn settle_handler(
    State(state): State<Arc<AppState>>,
    headers: HeaderMap,
    body: Bytes,
) -> (StatusCode, Json<serde_json::Value>) {
    let correlation_id = correlation_id_from_headers(&headers);

    if state.shutting_down.load(Ordering::Relaxed) {
        return (
            StatusCode::SERVICE_UNAVAILABLE,
            Json(
                serde_json::to_value(ErrorResponse {
                    error: "worker is shutting down".to_string(),
                    correlation_id,
                })
                .unwrap(),
            ),
        );
    }

    let Some(secret) = state.hmac_secret.as_deref() else {
        return (
            StatusCode::SERVICE_UNAVAILABLE,
            Json(
                serde_json::to_value(ErrorResponse {
                    error: "trigger authentication secret is not configured".to_string(),
                    correlation_id,
                })
                .unwrap(),
            ),
        );
    };

    let ts_header = headers
        .get("x-signature-timestamp")
        .and_then(|v| v.to_str().ok())
        .unwrap_or("");
    let sig_header = headers
        .get("x-signature")
        .and_then(|v| v.to_str().ok())
        .unwrap_or("");

    let now_ts = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs() as i64;

    if let Err(reason) = verify_hmac_signature(
        secret,
        ts_header,
        sig_header,
        &body,
        now_ts,
        state.hmac_tolerance_secs,
    ) {
        warn!(correlation_id = %correlation_id, reason = %reason, "rejected settle trigger");
        return (
            StatusCode::UNAUTHORIZED,
            Json(
                serde_json::to_value(ErrorResponse {
                    error: reason.to_string(),
                    correlation_id,
                })
                .unwrap(),
            ),
        );
    }

    let replay_key = format!("{}:{}", ts_header.trim(), sig_header.trim());
    {
        let mut guard = state.replay_guard.lock().expect("replay mutex");
        if !guard.check_and_record(replay_key, now_ts, state.hmac_tolerance_secs) {
            warn!(correlation_id = %correlation_id, "rejected replayed settle trigger");
            return (
                StatusCode::CONFLICT,
                Json(
                    serde_json::to_value(ErrorResponse {
                        error: "replayed trigger signature".to_string(),
                        correlation_id,
                    })
                    .unwrap(),
                ),
            );
        }
    }

    let payload: SettleTriggerRequest = match serde_json::from_slice(&body) {
        Ok(p) => p,
        Err(_) => {
            return (
                StatusCode::BAD_REQUEST,
                Json(
                    serde_json::to_value(ErrorResponse {
                        error: "invalid JSON request body".to_string(),
                        correlation_id,
                    })
                    .unwrap(),
                ),
            );
        }
    };

    match settle_draw_chunk(&state, payload.draw_id, payload.chunk_size, &correlation_id).await {
        Ok(summary) => (StatusCode::OK, Json(serde_json::to_value(summary).unwrap())),
        Err(err) => {
            state.errors_total.fetch_add(1, Ordering::Relaxed);
            error!(correlation_id = %correlation_id, draw_id = payload.draw_id, error = %err, "settle trigger failed");
            (
                StatusCode::UNPROCESSABLE_ENTITY,
                Json(
                    serde_json::to_value(ErrorResponse {
                        error: err.to_string(),
                        correlation_id,
                    })
                    .unwrap(),
                ),
            )
        }
    }
}

pub fn build_router(state: Arc<AppState>) -> Router {
    Router::new()
        .route("/healthz", get(healthz_handler))
        .route("/metrics", get(metrics_handler))
        .route("/settle", post(settle_handler))
        .with_state(state)
}

async fn run_self_healthcheck(bind_addr: &str) -> i32 {
    let target = bind_addr
        .replace("0.0.0.0:", "127.0.0.1:")
        .replace("[::]:", "[::1]:");
    let stream =
        match tokio::time::timeout(Duration::from_secs(3), TcpStream::connect(&target)).await {
            Ok(Ok(s)) => s,
            _ => return 1,
        };
    let mut stream = stream;
    let req = b"GET /healthz HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
    if stream.write_all(req).await.is_err() {
        return 1;
    }
    let mut buf = Vec::with_capacity(512);
    if stream.read_to_end(&mut buf).await.is_err() {
        return 1;
    }
    let response = String::from_utf8_lossy(&buf);
    if response.starts_with("HTTP/1.1 200") || response.starts_with("HTTP/1.0 200") {
        0
    } else {
        1
    }
}

async fn shutdown_signal(tx: watch::Sender<bool>, state: Arc<AppState>) {
    let ctrl_c = async {
        let _ = tokio::signal::ctrl_c().await;
    };

    #[cfg(unix)]
    let terminate = async {
        if let Ok(mut sigterm) =
            tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate())
        {
            sigterm.recv().await;
        }
    };

    #[cfg(not(unix))]
    let terminate = std::future::pending::<()>();

    tokio::select! {
        _ = ctrl_c => info!("received SIGINT, draining in-flight settlement chunk"),
        _ = terminate => info!("received SIGTERM, draining in-flight settlement chunk"),
    }

    state.shutting_down.store(true, Ordering::SeqCst);
    let _ = tx.send(true);
}

#[tokio::main]
async fn main() {
    let bind: String = env::var("WORKER_BIND").unwrap_or_else(|_| "0.0.0.0:9101".to_string());
    if env::args().any(|arg| arg == "--healthcheck") {
        std::process::exit(run_self_healthcheck(&bind).await);
    }

    tracing_subscriber::fmt()
        .json()
        .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
        .init();

    let db_url =
        load_env_or_file("DATABASE_URL").expect("DATABASE_URL or DATABASE_URL_FILE is required");
    let hmac_secret = load_env_or_file("SETTLEMENT_WORKER_HMAC_SECRET").map(String::into_bytes);

    let safety_mode_raw =
        env::var("PRIZE_PAYOUT_SAFETY_MODE").unwrap_or_else(|_| "DISABLED".to_string());
    let safety_mode = SafetyMode::parse(&safety_mode_raw).unwrap_or_else(|| {
        panic!("invalid PRIZE_PAYOUT_SAFETY_MODE '{safety_mode_raw}': expected DISABLED, DRY_RUN, or LIVE")
    });

    let chunk_size: u32 = env::var("SETTLEMENT_CHUNK_SIZE")
        .ok()
        .and_then(|v| v.parse().ok())
        .unwrap_or(DEFAULT_CHUNK_SIZE);

    let poll_ms: u64 = env::var("WORKER_POLL_MS")
        .ok()
        .and_then(|v| v.parse().ok())
        .unwrap_or(DEFAULT_POLL_MS);

    let connect_opts = MySqlConnectOptions::from_str(&db_url)
        .expect("valid MySQL DATABASE_URL")
        .disable_statement_logging();

    let pool = MySqlPoolOptions::new()
        .max_connections(16)
        .min_connections(2)
        .acquire_timeout(Duration::from_secs(5))
        .idle_timeout(Duration::from_secs(300))
        .after_connect(|conn, _meta| {
            Box::pin(async move {
                conn.execute("SET SESSION time_zone = '+00:00', innodb_lock_wait_timeout = 15")
                    .await?;
                Ok(())
            })
        })
        .connect_with(connect_opts)
        .await
        .expect("failed to connect to MySQL");

    let state = Arc::new(AppState {
        pool,
        hmac_secret,
        hmac_tolerance_secs: DEFAULT_HMAC_TOLERANCE_SECS,
        safety_mode,
        chunk_size,
        stale_claim_secs: DEFAULT_STALE_CLAIM_SECS,
        replay_guard: Mutex::new(ReplayGuard::new()),
        chunks_settled_total: AtomicU64::new(0),
        bets_settled_total: AtomicU64::new(0),
        payouts_created_total: AtomicU64::new(0),
        errors_total: AtomicU64::new(0),
        shutting_down: AtomicBool::new(false),
    });

    let (shutdown_tx, mut shutdown_rx) = watch::channel(false);
    let signal_state = Arc::clone(&state);
    tokio::spawn(async move {
        shutdown_signal(shutdown_tx, signal_state).await;
    });

    let poller_state = Arc::clone(&state);
    let mut poller_shutdown = shutdown_rx.clone();
    let poller_handle = tokio::spawn(async move {
        let mut interval = tokio::time::interval(Duration::from_millis(poll_ms));
        loop {
            tokio::select! {
                _ = poller_shutdown.changed() => {
                    info!("settlement poller stopping gracefully");
                    break;
                }
                _ = interval.tick() => {
                    if poller_state.shutting_down.load(Ordering::Relaxed) {
                        break;
                    }
                    if let Err(err) = poll_and_settle_next(&poller_state).await {
                        poller_state.errors_total.fetch_add(1, Ordering::Relaxed);
                        error!(error = %err, "settlement poll cycle failed");
                    }
                }
            }
        }
    });

    let addr: SocketAddr = bind.parse().expect("valid WORKER_BIND socket address");
    let listener = tokio::net::TcpListener::bind(addr)
        .await
        .expect("failed to bind settlement-worker listener");

    info!(bind = %addr, safety_mode = %safety_mode.as_str(), chunk_size = chunk_size, "settlement-worker listening");

    let app = build_router(Arc::clone(&state));
    axum::serve(listener, app)
        .with_graceful_shutdown(async move {
            let _ = shutdown_rx.changed().await;
        })
        .await
        .expect("http server error");

    let _ = poller_handle.await;
}

#[cfg(test)]
mod tests {
    use super::*;
    use rust_decimal_macros_or_str::dec;

    mod rust_decimal_macros_or_str {
        use rust_decimal::Decimal;
        use std::str::FromStr;
        pub fn dec(s: &str) -> Decimal {
            Decimal::from_str(s).expect("valid decimal literal")
        }
    }

    #[test]
    fn money_rounding_matches_php_half_away_from_zero() {
        assert_eq!(round_money(dec("10.005")), dec("10.01"));
        assert_eq!(round_money(dec("10.004")), dec("10.00"));
        assert_eq!(round_money(dec("-10.005")), dec("-10.01"));
        assert_eq!(round_money(dec("0.00")), dec("0.00"));
    }

    #[test]
    fn selection_evaluation_preserves_leading_zeros_and_exact_decimal_payout() {
        let rules = vec![
            WinningNumberRule {
                bet_type: "3d".to_string(),
                number: "007".to_string(),
                position: Some("top".to_string()),
                payout_multiplier: 900,
            },
            WinningNumberRule {
                bet_type: "2d".to_string(),
                number: "09".to_string(),
                position: Some("bottom".to_string()),
                payout_multiplier: 90,
            },
        ];

        // Exact match with leading zeros ("007" wins, "7" does not).
        let (won_exact, mult, prize) =
            evaluate_selection("3d", "007", Some("top"), dec("12.50"), 0, &rules);
        assert!(won_exact);
        assert_eq!(mult, dec("900"));
        assert_eq!(prize, dec("11250.00"));

        let (lost_unpadded, _, zero_prize) =
            evaluate_selection("3d", "7", Some("top"), dec("12.50"), 0, &rules);
        assert!(!lost_unpadded);
        assert_eq!(zero_prize, Decimal::ZERO);

        // Wrong position does not match.
        let (lost_position, _, _) =
            evaluate_selection("2d", "09", Some("top"), dec("50.00"), 0, &rules);
        assert!(!lost_position);

        // Item-level contract multiplier overrides default tier multiplier.
        let (won_custom, custom_mult, custom_prize) =
            evaluate_selection("2d", "09", Some("bottom"), dec("25.00"), 95, &rules);
        assert!(won_custom);
        assert_eq!(custom_mult, dec("95"));
        assert_eq!(custom_prize, dec("2375.00"));
    }

    #[test]
    fn chunk_summary_serializes_decimal_as_exact_string_without_float() {
        let summary = ChunkSettlementSummary {
            draw_id: 42,
            run_id: 7,
            run_status: "completed".to_string(),
            safety_mode: "LIVE".to_string(),
            chunk_bets_processed: 3,
            chunk_winning_selections: 1,
            chunk_payouts_created: 1,
            chunk_prize_total: dec("11250.00"),
            cursor_bet_id: 109,
        };

        let json = serde_json::to_string(&summary).expect("serialize");
        assert!(json.contains("\"chunk_prize_total\":\"11250.00\""));
    }

    #[test]
    fn hmac_signature_verification_and_replay_guard_enforce_window_and_uniqueness() {
        let secret = b"test-settlement-worker-hmac-secret-32b";
        let body = br#"{"draw_id":15,"chunk_size":250}"#;
        let now_ts = 1_760_000_000_i64;
        let valid_sig = compute_hmac_signature(secret, now_ts, body);

        assert_eq!(
            verify_hmac_signature(secret, &now_ts.to_string(), &valid_sig, body, now_ts, 300),
            Ok(now_ts)
        );

        // Expired timestamp is rejected.
        assert!(verify_hmac_signature(
            secret,
            &(now_ts - 301).to_string(),
            &valid_sig,
            body,
            now_ts,
            300
        )
        .is_err());

        // Tampered body is rejected.
        let tampered_body = br#"{"draw_id":16,"chunk_size":250}"#;
        assert!(verify_hmac_signature(
            secret,
            &now_ts.to_string(),
            &valid_sig,
            tampered_body,
            now_ts,
            300
        )
        .is_err());

        // Replay guard accepts first delivery and rejects replay within tolerance window.
        let mut guard = ReplayGuard::new();
        let key = format!("{now_ts}:{valid_sig}");
        assert!(guard.check_and_record(key.clone(), now_ts, 300));
        assert!(!guard.check_and_record(key.clone(), now_ts + 5, 300));
        // After window expires, old entry is evicted.
        assert!(guard.check_and_record(key, now_ts + 305, 300));
    }

    #[test]
    fn safety_mode_parsing_is_strict_and_fails_closed_on_unknown_values() {
        assert_eq!(SafetyMode::parse("DISABLED"), Some(SafetyMode::Disabled));
        assert_eq!(SafetyMode::parse("dry_run"), Some(SafetyMode::DryRun));
        assert_eq!(SafetyMode::parse("LIVE"), Some(SafetyMode::Live));
        assert_eq!(SafetyMode::parse("LIVE_"), None);
        assert_eq!(SafetyMode::parse(""), None);
    }

    #[tokio::test]
    async fn mysql_end_to_end_chunked_settlement_and_idempotent_replay() {
        let Some(db_url) = env::var("TEST_MYSQL_URL").ok().filter(|s| !s.is_empty()) else {
            return;
        };

        let pool = MySqlPoolOptions::new()
            .max_connections(4)
            .connect(&db_url)
            .await
            .expect("connect to disposable MySQL database");

        let suffix = Uuid::new_v4().simple().to_string();

        // 1. Seed a user and an active primary THB wallet.
        let user_insert = sqlx::query(
            "INSERT INTO users (username, name, email, password, status, created_at, updated_at)
             VALUES (?, ?, ?, 'hashed', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        )
        .bind(format!("rust_{}", &suffix[..12]))
        .bind(format!("Rust Settlement Player {suffix}"))
        .bind(format!("rust-{suffix}@example.test"))
        .execute(&pool)
        .await
        .expect("insert user");
        let user_id = user_insert.last_insert_id();

        let wallet_insert = sqlx::query(
            "INSERT INTO wallets
                (user_id, type, status, currency, balance, locked_balance,
                 total_deposited, total_withdrawn, total_wagered, total_won, version, created_at, updated_at)
             VALUES
                (?, 'primary', 'active', 'THB', 500.00, 0.00, 500.00, 0.00, 110.00, 0.00, 1,
                 UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        )
        .bind(user_id)
        .execute(&pool)
        .await
        .expect("insert wallet");
        let wallet_id = wallet_insert.last_insert_id();

        // Ensure EXPENSE_PRIZE_PAYOUTS and LIABILITY_PLAYER_WALLETS exist for THB.
        sqlx::query(
            "INSERT IGNORE INTO ledger_accounts
                (code, name, type, currency, is_active, opening_balance, current_balance, created_at, updated_at)
             VALUES
                ('EXPENSE_PRIZE_PAYOUTS', 'Prize Payout Expense', 'expense', 'THB', 1, 0.00, 0.00, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('LIABILITY_PLAYER_WALLETS', 'Player Wallet Liability', 'liability', 'THB', 1, 0.00, 0.00, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        )
        .execute(&pool)
        .await
        .expect("ensure ledger accounts");

        // 2. Seed a drawn draw with published winning_numbers ("007" 3d top @ 900x).
        let draw_insert = sqlx::query(
            "INSERT INTO draws
                (draw_number, type, status, scheduled_at, drawn_at, result_published_at,
                 total_bets, total_amount_wagered, total_payout, house_profit, created_at, updated_at)
             VALUES
                (?, 'government', 'drawn', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                 2, 110.00, 0.00, 0.00, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        )
        .bind(format!("DRW-RUST-{}", &suffix[..12]))
        .execute(&pool)
        .await
        .expect("insert draw");
        let draw_id = draw_insert.last_insert_id();

        sqlx::query(
            "INSERT INTO winning_numbers
                (draw_id, bet_type, number, prize_tier, position, payout_multiplier,
                 published_at, created_at, updated_at)
             VALUES
                (?, '3d', '007', 'first_top', 'top', 900, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        )
        .bind(draw_id)
        .execute(&pool)
        .await
        .expect("insert winning_numbers");

        // 3. Seed one winning bet (10.00 * 900 = 9000.00) and one losing bet (100.00).
        let win_bet_insert = sqlx::query(
            "INSERT INTO bets
                (uuid, bet_number, user_id, draw_id, type, status, currency,
                 stake_amount, potential_payout, actual_payout, total_numbers, placed_at, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, '3d', 'confirmed', 'THB', 10.00, 9000.00, 0.00, 1,
                 UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        )
        .bind(Uuid::new_v4().to_string())
        .bind(format!("BET-W-{}", &suffix[..12]))
        .bind(user_id)
        .bind(draw_id)
        .execute(&pool)
        .await
        .expect("insert winning bet");
        let win_bet_id = win_bet_insert.last_insert_id();

        sqlx::query(
            "INSERT INTO bet_items
                (bet_id, number, position, amount, payout_multiplier, potential_payout,
                 is_winner, actual_payout, created_at, updated_at)
             VALUES
                (?, '007', 'top', 10.00, 900, 9000.00, 0, 0.00, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        )
        .bind(win_bet_id)
        .execute(&pool)
        .await
        .expect("insert winning bet item");

        let lose_bet_insert = sqlx::query(
            "INSERT INTO bets
                (uuid, bet_number, user_id, draw_id, type, status, currency,
                 stake_amount, potential_payout, actual_payout, total_numbers, placed_at, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, '3d', 'confirmed', 'THB', 100.00, 90000.00, 0.00, 1,
                 UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        )
        .bind(Uuid::new_v4().to_string())
        .bind(format!("BET-L-{}", &suffix[..12]))
        .bind(user_id)
        .bind(draw_id)
        .execute(&pool)
        .await
        .expect("insert losing bet");
        let lose_bet_id = lose_bet_insert.last_insert_id();

        sqlx::query(
            "INSERT INTO bet_items
                (bet_id, number, position, amount, payout_multiplier, potential_payout,
                 is_winner, actual_payout, created_at, updated_at)
             VALUES
                (?, '123', 'top', 100.00, 900, 90000.00, 0, 0.00, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        )
        .bind(lose_bet_id)
        .execute(&pool)
        .await
        .expect("insert losing bet item");

        let state = AppState {
            pool: pool.clone(),
            hmac_secret: Some(b"integration-test-hmac-secret".to_vec()),
            hmac_tolerance_secs: 300,
            safety_mode: SafetyMode::Live,
            chunk_size: 500,
            stale_claim_secs: 120,
            replay_guard: Mutex::new(ReplayGuard::new()),
            chunks_settled_total: AtomicU64::new(0),
            bets_settled_total: AtomicU64::new(0),
            payouts_created_total: AtomicU64::new(0),
            errors_total: AtomicU64::new(0),
            shutting_down: AtomicBool::new(false),
        };

        // 4. Settle the draw in LIVE mode.
        let summary = settle_draw_chunk(&state, draw_id, Some(500), "corr-test-1")
            .await
            .expect("settle chunk succeeds");

        assert_eq!(summary.draw_id, draw_id);
        assert_eq!(summary.run_status, "completed");
        assert_eq!(summary.safety_mode, "LIVE");
        assert_eq!(summary.chunk_bets_processed, 2);
        assert_eq!(summary.chunk_winning_selections, 1);
        assert_eq!(summary.chunk_payouts_created, 1);
        assert_eq!(summary.chunk_prize_total, dec("9000.00"));

        // Verify wallet balance, total_won, and version.
        let wallet_after =
            sqlx::query("SELECT balance, total_won, version FROM wallets WHERE id = ?")
                .bind(wallet_id)
                .fetch_one(&pool)
                .await
                .expect("fetch wallet");
        let bal_after: Decimal = wallet_after.try_get("balance").unwrap();
        let won_after: Decimal = wallet_after.try_get("total_won").unwrap();
        let ver_after: u64 = wallet_after.try_get("version").unwrap();
        assert_eq!(bal_after, dec("9500.00"));
        assert_eq!(won_after, dec("9000.00"));
        assert_eq!(ver_after, 2);

        // Verify balanced double-entry ledger entries for the payout.
        let payout_row = sqlx::query(
            "SELECT id, financial_transaction_id, status, amount FROM payouts WHERE bet_id = ?",
        )
        .bind(win_bet_id)
        .fetch_one(&pool)
        .await
        .expect("fetch payout");
        let payout_status: String = payout_row.try_get("status").unwrap();
        let payout_amount: Decimal = payout_row.try_get("amount").unwrap();
        let ft_id: u64 = payout_row.try_get("financial_transaction_id").unwrap();
        assert_eq!(payout_status, "completed");
        assert_eq!(payout_amount, dec("9000.00"));

        let debit_sum: Decimal = sqlx::query_scalar(
            "SELECT COALESCE(SUM(amount), 0.00) FROM ledger_entries WHERE financial_transaction_id = ? AND type = 'debit'",
        )
        .bind(ft_id)
        .fetch_one(&pool)
        .await
        .unwrap();
        let credit_sum: Decimal = sqlx::query_scalar(
            "SELECT COALESCE(SUM(amount), 0.00) FROM ledger_entries WHERE financial_transaction_id = ? AND type = 'credit'",
        )
        .bind(ft_id)
        .fetch_one(&pool)
        .await
        .unwrap();
        assert_eq!(debit_sum, dec("9000.00"));
        assert_eq!(credit_sum, dec("9000.00"));

        // 5. Calling settle_draw_chunk a second time is idempotent and creates no duplicates.
        let replay = settle_draw_chunk(&state, draw_id, Some(500), "corr-test-2")
            .await
            .expect("idempotent replay succeeds");
        assert_eq!(replay.run_status, "completed");
        assert_eq!(replay.chunk_bets_processed, 0);
        assert_eq!(replay.chunk_payouts_created, 0);
    }
}
