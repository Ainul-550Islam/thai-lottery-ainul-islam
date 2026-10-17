# 🚨 Enterprise Security Incident Response Plan (CSIRP)

## 1. Severity Classifications

| Severity | Definition | Example Scenario | Immediate Action |
|---|---|---|---|
| **SEV-1 (Critical)** | Core ledger breach, data loss, unauthenticated GLO result write. | Unauthorized modification of `draw_results` or balance overdraft. | Immediate automated traffic freeze; invoke emergency break-glass procedure; trigger snapshot. |
| **SEV-2 (High)** | Large-scale bot scraping, credential stuffing, DDoS. | > 5,000 req/min automated brute-force attacking `/login`. | Switch `BOT_DETECTION_MODE` to `enforce`; apply CIDR block on upstream WAF/Cloudflare. |
| **SEV-3 (Medium)** | Isolated upload malware detection or single fraudulent withdrawal attempt. | EICAR/Trojan detected in KYC upload queue. | File quarantined automatically; isolate user wallet pending compliance review. |

---

## 2. Emergency Escalation Contacts
- **Security Operations Center (SOC):** `security-ops@thai-lottery.internal`
- **Lead Financial Controller:** `compliance-finance@thai-lottery.internal`
- **Infrastructure Emergency Line:** PagerDuty escalation tier 1.
