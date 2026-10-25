# Runbook: application and field-encryption key rotation

## Scope and safety rules

This procedure covers Laravel `APP_KEY` and `FIELD_ENCRYPTION_KEY`. A key rotation is a controlled data operation with potential permanent data loss. Never place a key in shell history, a ticket, chat, CI log, source control, or command-line output. Use the organization's secret manager and approved operator identities. A production rotation is forbidden until a restorable database and private-storage backup have been created and independently verified.

A key rotation is not complete merely because the application boots. The operator must verify representative encrypted records, application health, and the audit record, then keep the previous key available for the required rollback window under restricted access.

## Before the change

1. Open a change record with service owner, security approver, key custodian, maintenance window, rollback approver, and a recovery deadline.
2. Pause deployments and nonessential writes. Confirm all application, Horizon, scheduler, Reverb and settlement-worker instances use the same currently active key versions.
3. Create a database backup and private-storage backup using `RUNBOOK-backup-restore.md`. Record backup identifiers and checksums in the restricted change record; do not place sensitive values in this runbook.
4. Verify a restore in an isolated environment and confirm records encrypted with the active field key decrypt there.
5. Confirm `APP_KEY` and `FIELD_ENCRYPTION_KEY` are held in the secret manager. Generate new values using the approved cryptographic secret-generation workflow; do not generate or print them in a shared terminal.
6. Confirm application config supports `app.field_encryption_previous_keys` as configured in the current release. Do not assume old Laravel encrypted payloads are compatible with a new APP_KEY.

## Field-encryption key rotation

The real command signature in `app/Console/Commands/Security/ReencryptEncryptedFieldsCommand.php` is:

```text
security:reencrypt-fields
  --from-app-key
  --dry-run
  --chunk=200
  --i-have-a-backup
```

The options are independent. `--from-app-key` explicitly enables decryption of legacy field values with the current Laravel APP_KEY. `--dry-run` counts without writing. `--chunk` accepts an integer from 1 through 10000. In production, `--i-have-a-backup` is required; it is an acknowledgement, not a backup operation. The command also considers configured prior field-encryption keys. It refuses malformed configuration and encrypted-looking values that no configured key can decrypt. It does not print personal data.

1. Keep the current `FIELD_ENCRYPTION_KEY` active and add the new key as the active key. Retain the old key in the protected previous-key list for the rotation period. If converting legacy Laravel-encrypted fields, explicitly plan `--from-app-key` and keep the old APP_KEY available to the command.
2. Run dry-run first from a controlled production operator session with output captured in the restricted change record:

   ```sh
   php artisan security:reencrypt-fields --dry-run --chunk=200 --i-have-a-backup
   ```

   Add `--from-app-key` only when the records being converted are known to be encrypted with the current APP_KEY. Do not infer this from a nonzero count.
3. Compare scanned/rotated/skipped counts with the expected data inventory. Any unexpected skipped count, exception, or mismatch is a stop condition. Do not proceed by increasing chunk size or changing keys until the cause is known.
4. Schedule a write-controlled window. Deploy the new key and explicit previous-key configuration consistently to all application and worker processes, then run the write operation:

   ```sh
   php artisan security:reencrypt-fields --chunk=200 --i-have-a-backup
   ```

   Include `--from-app-key` only if the dry-run was approved for that legacy source. The command processes protected fields under database transactions and writes an audit record on completion.
5. Verify the reported scanned, rotated and skipped counts; inspect the audit event without exposing decrypted values; run representative application-level reads; and verify the service health and queue backlog. Restart long-running workers only after the new secret configuration is confirmed.
6. Keep the previous key material in the secret manager under restricted, time-limited access until the change owner signs off and backup retention requirements allow retirement. Remove a prior key only after confirming no rows or archived payloads require it and all replicas have converged.

## APP_KEY rotation

APP_KEY can protect Laravel cookies, sessions and values encrypted through Laravel's encryption service. Rotating it without preserving decryptability can invalidate sessions and make encrypted application data unreadable. Do not treat a field-encryption re-encryption command as a general Laravel APP_KEY migration.

1. Inventory every APP_KEY-dependent data surface for the currently deployed code and installed Laravel version. Confirm whether `APP_PREVIOUS_KEYS` or an equivalent supported previous-key facility is configured and tested for this exact application version.
2. If any persisted data cannot be decrypted using a supported previous-key mechanism, stop. Obtain a code-reviewed, data-specific migration plan before changing APP_KEY. Never attempt a blind key swap.
3. Back up and verify restoreability as above. Stage the change in an isolated production-like environment with copies of the relevant encrypted data and prove both read compatibility and the intended new-write key.
4. Deploy the new APP_KEY and its explicitly supported previous-key configuration to all web and worker processes as one coordinated change. Expect active sessions/cookies to require renewal unless compatibility is proven.
5. Verify login, session renewal, encrypted-data reads, queued jobs, callbacks and audit continuity. Do not delete the old key until the retention and compatibility window expires.

## Rollback

- Stop the rollout immediately if a decryption failure, unexpected rotation count, application error spike, or audit mismatch occurs.
- If no new encrypted writes occurred, restore the previous secret configuration and prior image; verify service health before reopening traffic.
- If new writes occurred under the new key, do not simply remove that key. Retain both keys and restore the database only under an approved recovery plan that accounts for all writes since the backup.
- For irreversible/corrupt field changes, pause affected writes, restore the verified backup to an isolated target, compare records and audit history, then obtain explicit approval for any point-in-time recovery or reconciliation. Never overwrite production data without incident command approval.
- Record the final outcome and securely revoke temporary operator access. Do not include secret values or decrypted data in the incident record.
