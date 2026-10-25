# Runbook: backup and restore drill

## Objectives and prerequisites

Set `RPO` (maximum acceptable data loss) and `RTO` (maximum acceptable recovery time) as approved operator parameters before a drill or incident. This runbook intentionally does not invent numeric objectives. Record the approved values, backup generation time, encryption-key reference, checksum, storage location and responsible owner in the restricted change record.

A backup is not proven until it has been restored and application-level integrity checks pass. Production recovery requires incident-command approval, an isolated restore target, access to the relevant encryption keys, and a verified plan for reconciling external payment events that occurred after the recovery point.

## What must be protected

- **MySQL/MariaDB database:** transactional application and ledger tables, schema, and required database metadata. Use a transactionally consistent snapshot or an approved online dump method. Include binlogs/PITR material when configured and required by the approved RPO.
- **Redis:** configuration and any persistence required for the chosen recovery model. Queue/cache/session state must be treated according to its durability and privacy classification. Do not assume Redis persistence replaces the relational ledger.
- **Private application storage:** KYC documents, uploaded payment slips, exports, and any other private files stored outside the database. For Kubernetes, include the `fintech-app-storage` ReadWriteMany PVC and its CSI-backed volume snapshots/backup policy; for Compose, include the `app-storage` named volume; for object storage, preserve object identifiers, versioning, retention locks and access controls. Never restore private documents into a public web root.
- **Encryption and recovery configuration:** secret-manager references for APP_KEY, FIELD_ENCRYPTION_KEY and database/storage encryption keys. Back up key material only through the approved secret manager, never beside a database dump.
- **Release artifact and deployment configuration:** immutable image digests, schema migration revision, non-secret manifests, and the last known-good deployment revision.

## Backup procedure

1. Confirm the approved RPO/RTO, maintenance or snapshot window, target region, retention policy and access approvals.
2. Verify the target storage bucket/volume is private, encrypted, versioned where available and subject to the required retention policy. Confirm no credentials are embedded in scripts or command arguments.
3. Capture a consistent MySQL backup using the platform's managed snapshot/PITR service or an approved `mysqldump --single-transaction` process. If the database includes non-InnoDB tables, use the engine-approved consistency method instead of assuming transactional consistency.
4. Capture Redis persistence according to the configured RDB/AOF and recovery policy. Document whether queue and session state will be restored, replayed, expired or deliberately discarded; do not silently treat cache data as financial truth.
5. Snapshot/version the private object store or the persistent app-storage volume, including KYC documents and exports. Verify object count/metadata, volume snapshot status, encryption and retention controls without exposing object contents.
6. Generate cryptographic checksums for exported backup files and store the checksum separately in the restricted backup catalog. Verify completed upload, encryption and retention metadata.
7. Record the database snapshot ID, PITR point, Redis snapshot, private-storage version/snapshot, release image digest and operator approvals. Do not include secrets or personal data in the record.

## Restore drill

1. Provision an isolated restore environment with network egress denied by default. Use temporary, least-privilege credentials and the same database engine family as production.
2. Restore the selected database snapshot/PITR point and private objects. Restore key references from the secret manager. Do not connect the drill environment to production payment providers or production queues.
3. Restore Redis only according to the documented recovery model. Keep external side effects disabled and ensure no scheduler or payout worker can run.
4. Run schema/status checks, application boot checks, integrity verification, migration-state verification, and approved read-only ledger reconciliation. Confirm private objects remain private and decrypt only with the authorized key versions.
5. Verify a sample of records across relevant business domains using privacy-safe identifiers; do not export customer content. Compare row/object counts and checksums where applicable.
6. Measure actual recovery elapsed time and data-loss window. Compare results with approved RPO/RTO. If either is exceeded, declare the drill failed, preserve evidence and assign remediation.
7. Destroy the isolated environment and temporary credentials according to retention policy. Preserve only approved drill evidence and metadata.

## Production restore / failover

1. Declare an incident and obtain authorization from incident command, finance and security. Stop new payment/payout submissions and disable scheduler/settlement workers before restoring.
2. Select the last consistent database and private-storage recovery points. Include all required encryption-key versions. If no verified consistent point exists, stop and escalate rather than guessing.
3. Restore to a clean, isolated target first. Verify application compatibility and ledger reconciliation. Establish the exact recovery point and list external events since that point.
4. Reconcile provider/bank event history and webhook inboxes before resuming money movement. Never blindly replay payout requests; use provider status inquiries and the payments incident runbook.
5. Switch traffic only after the technical lead, finance reviewer and incident commander approve the restore validation. Keep provider lanes disabled until preflight, webhook verification and a small-amount smoke test are complete.
6. Monitor errors, queue age, duplicate event handling, ledger reconciliation and private-storage access. Retain the original production environment until the incident commander approves disposal.

## Restore acceptance record

Record approved RPO/RTO values, selected backup identifiers, checksum verification, restore start/end timestamps, database engine/version, migration revision, object-store restore evidence, Redis recovery decision, integrity/reconciliation outcome, data-loss estimate, approvals and follow-up actions. Exclude secrets and personal data.
