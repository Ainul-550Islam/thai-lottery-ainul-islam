#!/usr/bin/env bash
# =============================================================================
# assert-result-lane-seams.sh — the seams between a result lane and the rest of
# the repository (moved verbatim in intent out of ci.yml).
#
# A lane can work perfectly and still be unreachable: robots.txt deindexes it,
# .env.example never documents its keys, the guest navigation forgets it, or a
# competitor's brand name ships in its copy. These are cheap greps, and they run
# before the full suite so an obvious breach fails in a second.
#
# USAGE
#   scripts/ci/assert-result-lane-seams.sh
# =============================================================================
set -Eeuo pipefail
IFS=$'\n\t'

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd -- "${ROOT}"

echo "--- robots.txt: search blocked, content crawlable ---"
test -f public/robots.txt || { echo "::error::public/robots.txt is missing"; exit 1; }

for lane in national-lottery weekly-lottery bingo-lottery pcso-lottery; do
    grep -qx "Disallow: /${lane}/search" public/robots.txt \
        || { echo "::error::robots.txt does not block /${lane}/search"; exit 1; }

    if grep -qE "^Disallow:[[:space:]]*/${lane}/?[[:space:]]*$" public/robots.txt; then
        echo "::error::robots.txt blocks the whole ${lane} lane, not just its search route"
        exit 1
    fi

    if grep -qE "^Disallow:[[:space:]]*/${lane}/year" public/robots.txt; then
        echo "::error::robots.txt blocks the ${lane} year archive"
        exit 1
    fi
done

echo "--- .env.example documents every key each lane config reads ---"
node -e '
  const fs = require("fs");
  const example = fs.readFileSync(".env.example", "utf8");
  let failed = false;

  for (const lane of ["national_lottery", "weekly_lottery", "bingo_lottery", "pcso_lottery", "contact"]) {
    const config = fs.readFileSync("config/" + lane + ".php", "utf8");
    const prefix = lane === "contact" ? "CONTACT" : lane.toUpperCase();
    const keys = new Set(
      [...config.matchAll(new RegExp("env\\(\\s*[\x27\"](\\w*" + prefix + "_[A-Z0-9_]+)[\x27\"]", "g"))].map(m => m[1])
    );

    if (keys.size === 0) {
      console.error("::error::no env keys parsed out of config/" + lane + ".php");
      failed = true;
      continue;
    }

    for (const key of keys) {
      if (!new RegExp("^#?\\s*" + key + "=", "m").test(example)) {
        console.error("::error::" + key + " is read by config/" + lane + ".php but undocumented in .env.example");
        failed = true;
      }

      if (/(TOKEN|SECRET|KEY)$/.test(key) && new RegExp("^" + key + "=.+", "m").test(example)) {
        console.error("::error::" + key + " has a value in .env.example");
        failed = true;
      }
    }
  }

  process.exit(failed ? 1 : 0);
'

echo "--- /contact must stay crawlable ---"
if grep -qE "^Disallow:[[:space:]]*/contact" public/robots.txt; then
    echo "::error::robots.txt blocks /contact, which is ordinary public content"
    exit 1
fi

echo "--- no competitor string in any public surface ---"
if grep -rniE "thailotto" app config routes lang public database \
        resources/views resources/css resources/js 2>/dev/null \
    | grep -v "^resources/views/vendor/"; then
    echo "::error::a competitor reference reached production source"
    exit 1
fi

echo "--- guest navigation links both public lanes ---"
for name in national-lottery.index weekly-lottery.index bingo-lottery.index pcso-lottery.index contact; do
    grep -q "route('${name}')" resources/views/layouts/app.blade.php \
        || { echo "::error::guest navigation has no link to ${name}"; exit 1; }
done

echo "Seam contracts hold."
