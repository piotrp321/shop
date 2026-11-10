#!/usr/bin/env bash
# The Lighthouse measurement of the store pages, started by e2e/entrypoint.sh for `make lighthouse` once the forwarders
# listen (see docs/features/lighthouse/spec.md). Exit codes: 1 for a warm-up answer other than 200 and for a
# measurement without manifest.json, otherwise the code of `lhci` (non-zero when the SEO assertion fails).
set -euo pipefail

cd /app
results="${LIGHTHOUSE_RESULTS_DIR:-/app/lighthouse-results}"
config=/app/lighthouse/lighthouserc.cjs

# Addresses to measure: the single list is in the configuration.
mapfile -t urls < <(node -p "require('$config').ci.collect.url.join('\n')")

# Only the results of this run: the medians must not mix runs of different measurements.
mkdir -p "$results"
find "$results" -mindepth 1 -delete

# One request per address fills the ISR cache of the product and listing pages, so the runs measure a cached page.
for url in "${urls[@]}"; do
  status="$(curl -s -o /dev/null -w '%{http_code}' --max-time 120 "$url" || true)"
  if [ "$status" != "200" ]; then
    echo "lighthouse: warm-up of $url answered ${status:-no response} instead of 200; the measurement was not started" >&2
    exit 1
  fi
done

CHROME_PATH="$(node -e "import('@playwright/test').then(({ chromium }) => console.log(chromium.executablePath()))")"
export CHROME_PATH

status=0
npx lhci autorun --config="$config" --failOnUploadFailure || status=$?

# A failed report write must never give a green result, even when the assertions passed.
if [ ! -f "$results/manifest.json" ]; then
  echo "lighthouse: $results/manifest.json is missing after the measurement" >&2
  [ "$status" -ne 0 ] || status=1
fi

# The medians are informational: their exit code does not change the result of the measurement.
node /app/lighthouse/summary.mjs "$results" || true

exit "$status"
