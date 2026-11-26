# GO-LIVE CHECKLIST

Every gate must be ticked by a named person with a date and evidence. A gate with
no evidence is not passed. This list does not replace legal advice.

**Release:** `<OPERATOR: version / commit>`   **Go-live decision owner:** `<OPERATOR: name>`

| # | Gate | Evidence required | Owner | Date | Passed |
|---|---|---|---|---|---|
| 1 | **Licence attestation.** A valid, non-revoked `licence` row exists in `compliance_attestations` with reference, issuer, document hash and validity dates covering the launch period. Until then the public badge list stays empty. | Attestation id; `GET /api/v1/public/compliance-badges` output; stored document | `<OPERATOR>` | | [ ] |
| 2 | **Claims reviewed.** Every public claim has a row in `docs/compliance/CLAIMS_EVIDENCE_TEMPLATE.md` with evidence, owner and expiry. `ForbiddenClaimsTest` passes. | Completed template; test output | `<OPERATOR>` | | [ ] |
| 3 | **Games verified.** The game-rule validation command exits 0 for every game that is switched on. **VERIFY** the command name in the repository (Batch 2 `validate-rules`, if installed). | Command output, exit code 0 | `<OPERATOR>` | | [ ] |
| 4 | **Real-money flags.** Production env has the real-money gate in the intended state; the production env template keeps real money OFF until this gate is signed. Boot in production mode and read the flag back. | Env review; `EnvTemplateSafetyTest` result | `<OPERATOR>` | | [ ] |
| 5 | **Provider configs present.** Payment gateway and result provider are configured (status only); `GET /health/ready` returns 200 in production. | `/health/ready` output | `<OPERATOR>` | | [ ] |
| 6 | **Scheduler running.** Heartbeat fresh; `ops:heartbeat` scheduled every minute; `ledger:verify`, `wallet:snapshot-balances` scheduled. | `/health/ready` scheduler check; scheduler listing | `<OPERATOR>` | | [ ] |
| 7 | **Ledger integrity clean.** `php artisan ledger:verify` exits 0 on production data. | Command output | `<OPERATOR>` | | [ ] |
| 8 | **Backups tested.** A backup was restored to a separate environment and the restored data verified (not just "backup job succeeded"). | Restore log; date of restore | `<OPERATOR>` | | [ ] |
| 9 | **Penetration test done.** Findings of severity high or above are fixed or formally accepted by the risk owner. | Report; fix list | `<OPERATOR>` | | [ ] |
| 10 | **Load test done.** Bet placement, slip confirm, wallet feed and result settlement tested at the expected peak with headroom; results recorded. | Load test report | `<OPERATOR>` | | [ ] |
| 11 | **On-call rota.** Named owners for each runbook: settlement stuck, result provider down, payout failed, ledger mismatch. Contact details verified. | Rota link; test page sent | `<OPERATOR>` | | [ ] |
| 12 | **Full test suite.** The suite is run on the release commit and compared with the baseline. Any remaining failure is listed, explained and accepted in writing. | Test log; accepted-failures list | `<OPERATOR>` | | [ ] |
| 13 | **Responsible gaming.** Limits, self-exclusion and 18+ notice work end to end. | Manual test record | `<OPERATOR>` | | [ ] |
| 14 | **Rollback plan.** Steps and the person who can execute them at any hour. | Written plan | `<OPERATOR>` | | [ ] |

## Sign-off

| Role | Name | Signature / date |
|---|---|---|
| Engineering | `<OPERATOR>` | |
| Compliance | `<OPERATOR>` | |
| Finance | `<OPERATOR>` | |
| Operations | `<OPERATOR>` | |

If any gate is not passed, launch does not proceed.
