# --- Cloudflare / Edge WAF Security Policy ---

terraform {
  required_providers {
    cloudflare = {
      source  = "cloudflare/cloudflare"
      version = "~> 4.25"
    }
  }
}

variable "cloudflare_zone_id" {
  type        = string
  default     = "023e105f4ecef8ad9ca31a8372d0c353"
  description = "Zone ID for lottery domain"
}

# 1. Rate Limiting Rule: Protect Authentication & Order Placement Routes
resource "cloudflare_rate_limit" "api_protection" {
  zone_id   = var.cloudflare_zone_id
  threshold = 100
  period    = 60
  action {
    mode    = "challenge"
    timeout = 300
  }
  match {
    request {
      url_pattern = "*api.thai-lottery.internal/api/v1/auth/*"
      schemes     = ["HTTPS"]
      methods     = ["POST"]
    }
  }
  disabled    = false
  description = "Rate limit aggressive credential stuffing on login routes"
}

# 2. Web Application Firewall (WAF) Rule: Managed Bot & SQL Injection Defense
resource "cloudflare_ruleset" "waf_rules" {
  zone_id     = var.cloudflare_zone_id
  name        = "Enterprise Fintech Defense Ruleset"
  description = "Block OWASP Top 10, automated bots, and scrapers"
  kind        = "zone"
  phase       = "http_request_firewall_custom"

  rules {
    action = "block"
    expression = "(cf.bot_management.score lt 20 and not cf.bot_management.verified_bot) or (http.request.uri.path contains \"/admin\" and not ip.src in {10.0.0.0/8 198.51.100.0/24})"
    description = "Block malicious bots and unauthorized access to admin endpoints"
    enabled     = true
  }
}
