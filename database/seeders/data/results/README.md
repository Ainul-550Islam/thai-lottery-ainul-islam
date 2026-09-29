# Historical Result Archive Payloads

`ResultArchiveSeeder` replays every `*.json` file in this directory tree
through the same import command an operator would run by hand:

| Directory | Command |
|---|---|
| `national/` | `php artisan national-lottery:import --file=...` |
| `weekly/` | `php artisan weekly-lottery:import --file=...` |
| `bingo/` | `php artisan bingo-lottery:import --file=...` |
| `pcso/` | `php artisan pcso-lottery:import --file=...` |

**One file = one draw.** Name files so alphabetical order matches draw order
(e.g. `2025-09-16.json`). With no files present, `migrate --seed` leaves the
result history empty on purpose — this project never invents draws.

## Payload shape

Each command validates the payload itself and exits non-zero on anything it
refuses; the seeder surfaces that rejection in its output instead of hiding
it. The National lane payload (see `ImportNationalLotteryResults`) is:

```json
{
    "draw_date": "2025-09-16",
    "first_prize": "730640",
    "three_up": "640",
    "two_up": "40",
    "two_down": "64",
    "three_front": ["060", "521", "266", "041"],
    "three_after": [],
    "source_identifier": "glo-2025-09-16",
    "retrieved_at": "2025-09-16T15:30:00+00:00"
}
```

Values are stored UNCHANGED — pad them yourself (`"040"`, not `40`), because
the import service rejects malformed values rather than repairing them. The
Weekly, Bingo and PCSO lanes use the equivalent payload keys for their own
prize structure; each command's docblock (`app/Console/Commands/`) is the
authoritative list.

## Where to get a history

Export the archive from your current system of record into these files, then
commit them (or mount them at deploy time) and run `migrate --seed`. Anything
the importer refuses is reported by lane and filename — fix that file, do not
work around it.

## Import contract (what an approved bundle must satisfy)

The per-lane commands are the enforcement point — `LaneResultImportContractTest`
pins the behaviour in CI:

- **Canonical widths, leading zeros intact.** `"040615"` stays `"040615"`;
  a five-digit first prize or a one-digit `two_down` is REFUSED, not padded.
  The importer never repairs a number without approved source data.
- **Dates in accepted formats only.** Anything that is not a recognized
  date is refused and nothing is persisted for that row.
- **Duplicates are conflicts.** Re-delivering a draw with DIFFERENT numbers
  exits non-zero (conflict) and can never replace the current published
  result; the conflicting delivery may leave a non-current evidence row.
- **Provenance per row.** `source_identifier` + `retrieved_at` are required
  and recorded; who imported it and when is the command's own audit trail.
- **No fabrication.** This project never invents draws, numbers or history.
  If a payload cannot be sourced, it does not exist here.

### Bundle provenance & reconciliation procedure

1. Record the source system, export date, and a checksum (e.g. SHA-256) of
   every file alongside the bundle — outside player-visible content.
2. Import lane by lane; the command reports refusals per file — fix the
   FILE, never the importer, when a row is rejected.
3. After import, verify per lane: every advertised year page carries rows,
   every detail link resolves, leading zeros render (`ArchiveInventoryParityTest`
   is the automated form of this check).
4. Keep the bundle (or its checksums) so a later re-import can be proven
   identical.

Buddhist/Gregorian years: payloads carry Gregorian ISO dates; the public
surface renders Buddhist-era years where the lane's convention requires it,
derived deterministically — malformed source years never reach navigation.
