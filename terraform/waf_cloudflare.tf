# Edge rate limits are deliberately scoped to routes defined in routes/api.php and routes/thai_rails.php.
locals {
  limited_post_paths = {
    auth              = "/api/v1/auth/*"
    wallet_deposit    = "/api/v1/wallet/deposit"
    slip_verification = "/api/v1/deposit/slip-verification"
    truemoney_redeem  = "/api/v1/deposit/truemoney/redeem"
    payment_webhooks  = "/api/v1/payments/webhook/*"
    bank_webhooks     = "/api/v1/payments/bank-webhook/*"
    payout_webhooks   = "/api/v1/payments/payout-webhook/*"
    broadcasting_auth = "/broadcasting/auth"
  }
}

resource "cloudflare_ruleset" "application_rate_limits" {
  zone_id     = var.cloudflare_zone_id
  name        = "Application POST rate limits"
  description = "Rate limits for authentication, payment, wallet and broadcast-auth routes"
  kind        = "zone"
  phase       = "http_ratelimit"

  dynamic "rules" {
    for_each = local.limited_post_paths

    content {
      action      = "block"
      description = "Rate limit POST requests for application route ${rules.key}"
      enabled     = true
      expression  = "(http.host eq \"${var.cloudflare_hostname}\" and http.request.method eq \"POST\" and http.request.uri.path wildcard \"${rules.value}\")"

      ratelimit {
        characteristics     = ["ip.src"]
        period              = 60
        requests_per_period = rules.key == "auth" ? 30 : (strcontains(rules.key, "webhook") ? 300 : 60)
        mitigation_timeout  = 60
      }
    }
  }
}

resource "cloudflare_ruleset" "bank_webhook_source_allowlist" {
  count       = length(var.bank_webhook_source_cidrs) > 0 ? 1 : 0
  zone_id     = var.cloudflare_zone_id
  name        = "Bank webhook provider source allowlist"
  description = "Reject bank webhook requests outside operator-configured provider CIDRs"
  kind        = "zone"
  phase       = "http_request_firewall_custom"

  rules {
    action      = "block"
    expression  = "(starts_with(http.request.uri.path, \"/api/v1/payments/bank-webhook/\") or starts_with(http.request.uri.path, \"/api/v1/payments/payout-webhook/\")) and not ip.src in {${join(" ", var.bank_webhook_source_cidrs)}}"
    description = "Fail closed for bank webhook source addresses"
    enabled     = true
  }
}
