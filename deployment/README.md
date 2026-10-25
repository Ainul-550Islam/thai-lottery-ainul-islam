# Production deployment and operations

This document defines the deployment order and operational safety controls for the Laravel application. Production service endpoints, provider credentials, account identifiers and hostnames are supplied by the operator or secret manager; no live hostname or credential is stored in this repository.

## Deployment topology

The production runtime consists of Laravel PHP-FPM, Nginx edge, MySQL-compatible storage, Redis, Horizon workers, one scheduler, Reverb, and the Rust settlement worker. Docker Compose is defined in `../docker-compose.production.yml`; Kubernetes manifests are under `../k8s/`; the Helm chart is under `../helm/thai-lottery-ledger/`. CI builds immutable images and uses `sha-<commit>` tags. Never deploy a mutable `latest` tag.

## Required operator configuration

Supply the public hostname as `APP_HOST`, TLS materials through the selected certificate manager, and DB/Redis/application/payment credentials through an external secret store and the permission-restricted `.env.production` runtime file where Laravel requires process environment values. Do not place secrets in Terraform variables files committed to source control, ConfigMaps, image build args, logs, or command-line arguments. Before provisioning, select a currently supported Aurora MySQL 8.0-compatible engine version available in the target AWS region and set the required Terraform inputs in a protected workspace.

For the Kubernetes deployment workflow, create a protected GitHub Environment with a base64-encoded `KUBE_CONFIG` secret and operator-verified `APP_HOST`, `APP_STORAGE_CLASS`, `APP_STORAGE_SIZE`, `DB_SOURCE_CIDRS`, `REDIS_SOURCE_CIDRS`, and `PROVIDER_SOURCE_CIDRS` variables. `APP_STORAGE_CLASS` must name a pre-provisioned encrypted `ReadWriteMany` storage class suitable for all PHP application pods; `APP_STORAGE_SIZE` is a Kubernetes quantity such as `100Gi`, set to the approved capacity. Configure the CSI storage class/access point so UID/GID 10001 can write and the volume is included in encrypted, versioned backups. The CIDR variables are comma-separated, must be provider-published or database/cache subnet ranges, and are rendered into narrowly scoped egress NetworkPolicies; broad routes are refused. Install the External Secrets Operator and configure a least-privilege `ClusterSecretStore` named `fintech-production` before deployment. Provide the required `APP_KEY`, `FIELD_ENCRYPTION_KEY`, database, Redis, Reverb, and worker secrets in that secret store. Provider credentials are optional until their payment lanes are enabled. When using Docker Compose, keep DB and Redis on the internal `backend` network and enforce the provider CIDR allowlist for the `provider-egress` bridge at the Docker host firewall; Docker bridge membership alone is not an egress firewall. Helm deployments also require non-empty `storage.className`, `storage.size`, and operator-verified database, Redis, and provider CIDR lists.

## Release order

1. Verify source revision, signed image provenance, SBOM, vulnerability scan, image digest and approval in the protected GitHub Environment.
2. Take and verify a restorable database backup and a private-storage backup. Confirm RPO/RTO objectives with the incident owner.
3. Configure secrets and non-secret runtime settings. Keep all payment lanes disabled initially.
4. Run `php artisan ops:preflight --environment=production --strict` in the deployment environment. The command must complete without blocking failures.
5. Run `php artisan thai-rails:preflight` and `php artisan realtime:check`; resolve every reported blocker.
6. Apply the pre-deploy migration Job and require successful completion before rolling application Deployments.
7. Deploy immutable app and worker image tags, wait for each rollout, then verify readiness and queue health.
8. Configure provider callbacks, signatures, allowlists and replay handling while payment lanes remain disabled. Verify each callback with provider sandbox/test delivery and ensure events are idempotent.
9. Perform a provider-approved small-amount smoke transaction with an operator present. Reconcile gateway, wallet, ledger and settlement records independently. Never repeat an uncertain payout.
10. Enable one payment/realtime lane at a time only after preflight, webhook verification and the small-amount reconciliation all pass.
11. Monitor error rates, queue age, duplicate callbacks, payout status and ledger reconciliation through the agreed observation window.

## Registered webhook and callback paths

The exact route definitions are maintained in `../routes/api.php` and `../routes/thai_rails.php`. Configure the provider callback URL as `https://${APP_HOST}` plus the matching path below; do not invent a second route or remove the API prefix.

- Generic payment gateway callback: `/api/v1/payments/webhook/{gateway}`
- Versioned payment event callback: `/api/v1/payments/webhook/v2/{gateway}`
- KBank payment callback: `/api/v1/payments/bank-webhook/kbank`
- SCB payment callback: `/api/v1/payments/bank-webhook/scb`
- SCB payout callback: `/api/v1/payments/payout-webhook/scb`
- KBank payout callback: `/api/v1/payments/payout-webhook/kbank`
- Stripe follow-up callback: `/api/v1/payments/stripe-followups`
- TrueMoney redemption is an authenticated client API, not a provider webhook: `/api/v1/deposit/truemoney/redeem`
- Slip verification is an authenticated upload API, not a provider webhook: `POST /api/v1/deposit/slip-verification` and `GET /api/v1/deposit/slip-verification/{verification}`
- Reverb private-channel authentication: `/broadcasting/auth`

## Lane activation order

Keep TrueMoney (`truemoney`), slip verification (`slip_verification`), Thai bank payouts (`thai_payout`), realtime/Reverb (`realtime`), legal PDF (`legal_pdf`), payout illustration (`payout_illustration`), privacy requests (`privacy_requests`) and support tickets (`support_tickets`) disabled until their credentials, limits and operational owners are configured. Enable lanes in this order: configuration preflight → callback/webhook verification → small-amount smoke where money movement applies → independent reconciliation → explicit operator approval → enable. Never enable a lane solely because the deployment is healthy.

## Rollback and escalation

Use the signed prior image tag and GitHub deployment workflow rollback path. If a database migration is not backward-compatible, stop traffic and follow the migration-specific rollback plan; do not automatically reverse schema changes. Payment uncertainty, duplicate callbacks, suspected slip fraud, and ledger mismatch are governed by `RUNBOOK-payments-incidents.md`.
