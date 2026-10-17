# 🌐 IP/CIDR Blocklist Operational Runbook

## 1. Overview
The IP blocklist system operates via database-authoritative storage (`blocked_ips` table) with Redis cache acceleration (`security:active_ip_blocks`) and bitwise IPv4/IPv6 CIDR matching (`IpCidrMatcher`).

---

## 2. Operator Procedures

### A. Blocking an Offending IP or Subnet
```bash
# Via Admin API
curl -X POST https://api.thai-lottery.internal/api/v1/admin/security/ip-blocks \
  -H "Authorization: Bearer <ADMIN_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "ip_or_cidr": "198.51.100.0/24",
    "reason_code": "BRUTE_FORCE",
    "source": "MANUAL",
    "duration_minutes": 1440,
    "notes": "Targeted brute-force on auth routes"
  }'
```

### B. Emergency Break-Glass Unblock & Recovery
In the event of accidental administrator lockout:
1. Ensure the administrator's network is listed in `SECURITY_ADMIN_ALLOWLIST` in `.env`.
2. Run the Artisan pruning command to flush expired blocks:
```bash
php artisan security:prune-expired-blocks
```
3. Or manually deactivate via database:
```sql
UPDATE blocked_ips SET is_active = 0, unblocked_at = NOW() WHERE ip_or_cidr = '198.51.100.5';
```
