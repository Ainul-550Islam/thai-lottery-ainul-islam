variable "aws_region" {
  type        = string
  default     = "ap-southeast-1"
  description = "AWS deployment region"
}

variable "environment" {
  type        = string
  default     = "prod"
  description = "Target deployment environment"
}

variable "vpc_cidr" {
  type        = string
  default     = "10.0.0.0/16"
  description = "VPC CIDR block"
  validation {
    condition     = can(cidrhost(var.vpc_cidr, 0))
    error_message = "vpc_cidr must be a valid IPv4 CIDR block."
  }
}

variable "db_master_username" {
  type        = string
  sensitive   = true
  description = "Master username for Aurora MySQL, supplied by the deployment secret store"
  validation {
    condition     = length(var.db_master_username) >= 1 && length(var.db_master_username) <= 16
    error_message = "Aurora MySQL master username must contain 1 to 16 characters."
  }
}

variable "db_master_password" {
  type        = string
  sensitive   = true
  description = "Master password for Aurora MySQL, supplied by the deployment secret store"
  validation {
    condition     = length(var.db_master_password) >= 16
    error_message = "The database password must be at least 16 characters."
  }
}

variable "redis_auth_token" {
  type        = string
  sensitive   = true
  description = "Auth token for Redis cluster"
  validation {
    condition     = length(var.redis_auth_token) >= 16
    error_message = "The Redis auth token must be at least 16 characters."
  }
}

variable "aurora_mysql_engine_version" {
  type        = string
  description = "Aurora MySQL 8.0-compatible engine version selected for this account and region"
  validation {
    condition     = can(regex("^8\\.0\\.mysql_aurora\\.[0-9]+\\.[0-9]+\\.[0-9]+$", var.aurora_mysql_engine_version))
    error_message = "Use an available Aurora MySQL 8.0-compatible engine version."
  }
}

variable "backup_retention_period" {
  type        = number
  default     = 14
  description = "Automated database backup retention in days"
  validation {
    condition     = var.backup_retention_period >= 14 && var.backup_retention_period <= 35
    error_message = "Production backup retention must be between 14 and 35 days."
  }
}

variable "final_snapshot_prefix" {
  type        = string
  description = "Non-secret unique prefix for final database snapshots"
  validation {
    condition     = can(regex("^[a-z][a-z0-9-]{2,40}$", var.final_snapshot_prefix))
    error_message = "Snapshot prefix must be a valid lowercase identifier."
  }
}

variable "cloudflare_zone_id" {
  type        = string
  description = "Cloudflare zone identifier, supplied by the deployment environment"
  validation {
    condition     = can(regex("^[0-9a-fA-F]{32}$", var.cloudflare_zone_id))
    error_message = "cloudflare_zone_id must be a 32-character hexadecimal identifier."
  }
}

variable "cloudflare_hostname" {
  type        = string
  description = "Public application hostname without scheme"
  validation {
    condition     = length(var.cloudflare_hostname) <= 253 && can(regex("^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\\.)+[A-Za-z]{2,63}$", var.cloudflare_hostname))
    error_message = "cloudflare_hostname must be a valid public DNS hostname without a scheme or path."
  }
}

variable "bank_webhook_source_cidrs" {
  type        = list(string)
  default     = []
  description = "Optional provider-published IPv4 CIDRs allowed to call bank webhook paths"
  validation {
    condition     = alltrue([for cidr in var.bank_webhook_source_cidrs : can(cidrhost(cidr, 0))])
    error_message = "Every bank webhook source must be a valid IPv4 CIDR block."
  }
}

variable "webhook_provider_source_cidrs" {
  type        = list(string)
  default     = []
  description = "Provider egress CIDRs allowed by the cluster network policy"
  validation {
    condition     = alltrue([for cidr in var.webhook_provider_source_cidrs : can(cidrhost(cidr, 0))])
    error_message = "Every provider CIDR must be valid."
  }
}
