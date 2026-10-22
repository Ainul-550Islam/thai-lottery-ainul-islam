# 🤖 Bot & Automated Abuse Detection Runbook

## 1. Operating Modes & Staged Rollout

| Environment | Mode | Configuration Setting | Behavior |
|---|---|---|---|
| **Staging / Initial Production** | `log_only` | `BOT_DETECTION_MODE=log_only` | Scores all incoming requests, emits `BotSuspected` events, zero user disruption. |
| **Active Challenge Mode** | `challenge` | `BOT_DETECTION_MODE=challenge` | Prompts CAPTCHA challenge for requests with score >= `BOT_SCORE_CHALLENGE_THRESHOLD` (45). |
| **Full Enforcement** | `enforce` | `BOT_DETECTION_MODE=enforce` | Rejects requests with HTTP 403 when score >= `BOT_SCORE_BLOCK_THRESHOLD` (80). |

---

## 2. Signal Weights & Reason Code Dictionary

| Signal Key | Weight | Trigger Condition | Reason Code |
|---|---|---|---|
| `user_agent` | 20 | Automated scraper / headless pattern | `AUTOMATED_CLIENT_SIGNATURE`, `EMPTY_USER_AGENT` |
| `header_consistency` | 15 | Inconsistent browser headers on web form | `INCONSISTENT_BROWSER_HEADERS` |
| `request_velocity` | 30 | Rapid burst exceeding 20 req / 5s | `BURST_REQUEST_VELOCITY_EXCEEDED` |
| `form_timing` | 20 | Form submission < 800ms | `SUPERHUMAN_FORM_SUBMISSION_SPEED` |
| `failed_auth` | 25 | >= 5 login failures in 15 mins | `REPEATED_AUTHENTICATION_FAILURES` |
| `device_reuse` | 20 | Device token shared across > 3 accounts | `EXCESSIVE_DEVICE_ACCOUNT_SHARING` |
