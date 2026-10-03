#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
violations="$(grep -RInE 'DrawResult::(create|updateOrCreate|firstOrCreate)|new DrawResult' app/Services --include='*.php' | grep -v 'app/Services/Draw/DrawResultPublicationService.php' | grep -v 'new DrawResultCertified' || true)"
if [[ -n "$violations" ]]; then
    printf '%s\n' "$violations" >&2
    exit 1
fi
if grep -q '\$this->publication->publish(' app/Console/Commands/Lottery/PublishDrawResultCommand.php; then
    echo 'Operator command bypasses ingestion/confirmation.' >&2
    exit 1
fi
echo 'Official result mutation boundary verified.'
