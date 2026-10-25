terraform {
  required_version = ">= 1.7.0"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.40"
    }
    kubernetes = {
      source  = "hashicorp/kubernetes"
      version = "~> 2.27"
    }
    cloudflare = {
      source  = "cloudflare/cloudflare"
      version = "~> 4.25"
    }
  }

}

provider "aws" {
  region = var.aws_region

  default_tags {
    tags = {
      Environment = var.environment
      Project     = "thai-lottery-ainul-islam"
      ManagedBy   = "Terraform"
    }
  }
}

# --- VPC & Networking ---
module "vpc" {
  source  = "terraform-aws-modules/vpc/aws"
  version = "5.5.3"

  name = "thai-lottery-vpc-${var.environment}"
  cidr = var.vpc_cidr

  azs              = ["${var.aws_region}a", "${var.aws_region}b", "${var.aws_region}c"]
  private_subnets  = ["10.0.1.0/24", "10.0.2.0/24", "10.0.3.0/24"]
  public_subnets   = ["10.0.101.0/24", "10.0.102.0/24", "10.0.103.0/24"]
  database_subnets = ["10.0.201.0/24", "10.0.202.0/24", "10.0.203.0/24"]

  enable_nat_gateway     = true
  single_nat_gateway     = false
  one_nat_gateway_per_az = true
  enable_vpn_gateway     = false

  enable_dns_hostnames = true
  enable_dns_support   = true

  create_database_subnet_group = true
}

# --- EKS Kubernetes Cluster ---
module "eks" {
  source  = "terraform-aws-modules/eks/aws"
  version = "20.8.4"

  cluster_name    = "thai-lottery-eks-${var.environment}"
  cluster_version = "1.29"

  cluster_endpoint_public_access  = true
  cluster_endpoint_private_access = true

  vpc_id                   = module.vpc.vpc_id
  subnet_ids               = module.vpc.private_subnets
  control_plane_subnet_ids = module.vpc.private_subnets

  eks_managed_node_groups = {
    general = {
      min_size     = 5
      max_size     = 100
      desired_size = 10

      instance_types = ["c6i.2xlarge"]
      capacity_type  = "ON_DEMAND"

      labels = {
        role = "application"
      }
    }

    scanners = {
      min_size     = 2
      max_size     = 10
      desired_size = 2

      instance_types = ["m6i.xlarge"]
      capacity_type  = "ON_DEMAND"

      labels = {
        role = "malware-scanner"
      }
    }
  }
}

# --- Aurora MySQL 8.0-compatible Multi-AZ Cluster ---
resource "aws_rds_cluster" "aurora_mysql" {
  cluster_identifier              = "thai-lottery-db-cluster-${var.environment}"
  engine                          = "aurora-mysql"
  engine_version                  = var.aurora_mysql_engine_version
  database_name                   = "lottery_ledger"
  master_username                 = var.db_master_username
  master_password                 = var.db_master_password
  db_subnet_group_name            = module.vpc.database_subnet_group_name
  vpc_security_group_ids          = [aws_security_group.rds_sg.id]
  storage_encrypted               = true
  kms_key_id                      = aws_kms_key.db_key.arn
  backup_retention_period         = var.backup_retention_period
  preferred_backup_window         = "03:00-04:00"
  deletion_protection             = true
  skip_final_snapshot             = false
  final_snapshot_identifier       = "${var.final_snapshot_prefix}-${formatdate("YYYYMMDDhhmmss", timestamp())}"
  copy_tags_to_snapshot           = true
  db_cluster_parameter_group_name = aws_rds_cluster_parameter_group.mysql8.name

  lifecycle {
    ignore_changes = [final_snapshot_identifier]
  }
}

resource "aws_rds_cluster_instance" "aurora_instances" {
  count              = 3
  identifier         = "thai-lottery-db-instance-${var.environment}-${count.index}"
  cluster_identifier = aws_rds_cluster.aurora_mysql.id
  instance_class     = "db.r6g.2xlarge"
  engine             = aws_rds_cluster.aurora_mysql.engine
  engine_version     = aws_rds_cluster.aurora_mysql.engine_version
}

# --- Redis Cluster for Redlock & Cache ---
resource "aws_elasticache_replication_group" "redis" {
  replication_group_id       = "thai-lottery-redis-${var.environment}"
  description                = "Redis cluster for distributed locking and session management"
  engine                     = "redis"
  engine_version             = "7.1"
  node_type                  = "cache.r6g.xlarge"
  num_cache_clusters         = 3
  parameter_group_name       = "default.redis7"
  port                       = 6379
  subnet_group_name          = aws_elasticache_subnet_group.redis_subnet_group.name
  security_group_ids         = [aws_security_group.redis_sg.id]
  automatic_failover_enabled = true
  multi_az_enabled           = true
  at_rest_encryption_enabled = true
  transit_encryption_enabled = true
  auth_token                 = var.redis_auth_token
}

resource "aws_elasticache_subnet_group" "redis_subnet_group" {
  name       = "thai-lottery-redis-subnet-group-${var.environment}"
  subnet_ids = module.vpc.private_subnets
}

# --- S3 Quarantine Bucket with Object Lock ---
resource "aws_s3_bucket" "quarantine_bucket" {
  bucket        = "thai-lottery-quarantine-${var.environment}"
  force_destroy = false
}

resource "aws_s3_bucket_object_lock_configuration" "quarantine_lock" {
  bucket = aws_s3_bucket.quarantine_bucket.id

  rule {
    default_retention {
      mode = "COMPLIANCE"
      days = 90
    }
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "quarantine_encryption" {
  bucket = aws_s3_bucket.quarantine_bucket.id

  rule {
    apply_server_side_encryption_by_default {
      kms_master_key_id = aws_kms_key.s3_key.arn
      sse_algorithm     = "aws:kms"
    }
  }
}

# --- KMS Keys ---
resource "aws_kms_key" "db_key" {
  description             = "KMS Key for Aurora Database Encryption"
  deletion_window_in_days = 30
  enable_key_rotation     = true
}

resource "aws_kms_key" "s3_key" {
  description             = "KMS Key for S3 Quarantine and Ledger Backups"
  deletion_window_in_days = 30
  enable_key_rotation     = true
}

# --- Security Groups ---
resource "aws_security_group" "rds_sg" {
  name        = "thai-lottery-rds-sg-${var.environment}"
  description = "Allow inbound MySQL traffic from EKS node groups only"
  vpc_id      = module.vpc.vpc_id

  ingress {
    from_port       = 3306
    to_port         = 3306
    protocol        = "tcp"
    security_groups = [module.eks.node_security_group_id]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

resource "aws_security_group" "redis_sg" {
  name        = "thai-lottery-redis-sg-${var.environment}"
  description = "Allow inbound Redis traffic from EKS node groups only"
  vpc_id      = module.vpc.vpc_id

  ingress {
    from_port       = 6379
    to_port         = 6379
    protocol        = "tcp"
    security_groups = [module.eks.node_security_group_id]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

resource "aws_rds_cluster_parameter_group" "mysql8" {
  name        = "thai-lottery-aurora-mysql8-${var.environment}"
  family      = "aurora-mysql8.0"
  description = "UTF-8 defaults for the Laravel MySQL application"

  parameter {
    name         = "character_set_server"
    value        = "utf8mb4"
    apply_method = "pending-reboot"
  }

  parameter {
    name         = "collation_server"
    value        = "utf8mb4_unicode_ci"
    apply_method = "pending-reboot"
  }
}
