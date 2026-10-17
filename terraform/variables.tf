variable "aws_region" {
  type        = string
  default     = "ap-southeast-1"
  description = "AWS deployment region (Singapore for lowest latency to Thailand)"
}

variable "environment" {
  type        = string
  default     = "prod"
  description = "Target deployment environment (staging/prod)"
}

variable "vpc_cidr" {
  type        = string
  default     = "10.0.0.0/16"
  description = "VPC CIDR block"
}

variable "db_master_username" {
  type        = string
  default     = "lottery_admin"
  description = "Master username for Aurora PostgreSQL"
}

variable "db_master_password" {
  type        = string
  sensitive   = true
  description = "Master password for Aurora PostgreSQL"
}

variable "redis_auth_token" {
  type        = string
  sensitive   = true
  description = "Auth token for Redis cluster"
}
