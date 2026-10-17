output "vpc_id" {
  value       = module.vpc.vpc_id
  description = "The ID of the VPC"
}

output "eks_cluster_endpoint" {
  value       = module.eks.cluster_endpoint
  description = "Endpoint for EKS control plane"
}

output "eks_cluster_name" {
  value       = module.eks.cluster_name
  description = "Kubernetes Cluster Name"
}

output "aurora_postgres_writer_endpoint" {
  value       = aws_rds_cluster.aurora_postgres.endpoint
  description = "Primary writer endpoint for Aurora PostgreSQL"
}

output "aurora_postgres_reader_endpoint" {
  value       = aws_rds_cluster.aurora_postgres.reader_endpoint
  description = "Read-only load-balanced endpoint for Aurora PostgreSQL"
}

output "redis_primary_endpoint" {
  value       = aws_elasticache_replication_group.redis.primary_endpoint_address
  description = "Primary endpoint address for ElastiCache Redis cluster"
}

output "quarantine_s3_bucket" {
  value       = aws_s3_bucket.quarantine_bucket.bucket
  description = "S3 bucket for malware isolation and quarantine"
}
