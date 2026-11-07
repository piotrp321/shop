#!/bin/sh
# Renders the chart for the cases the tests of tests/ read: one JSON document per line in <outdir>/<case>.ndjson.
# Usage: render.sh <outdir>
# A failing render stops the script with the message of helm.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"

out="${1:?usage: render.sh <outdir>}"
mkdir -p "$out"

rev1=0123456789abcdef0123456789abcdef01234567
rev2=89abcdef0123456789abcdef0123456789abcdef

# render <case> <environment> [helm arguments]
render() {
  case_name="$1" env="$2"
  shift 2
  if ! helm template shop charts/shop --namespace shop -f "environments/$env/values.yaml" "$@" > "$out/$case_name.yaml"; then
    echo "render: helm template failed for $case_name" >&2
    exit 1
  fi
  yq -o=json -I=0 '.' < "$out/$case_name.yaml" > "$out/$case_name.ndjson"
}

render prod prod --set image.tag=$rev1
render prod-rev2 prod --set image.tag=$rev2
render prod-domain prod --set image.tag=$rev1 --set global.domain=other.example.net
render prod-indexable prod --set image.tag=$rev1 --set noindex=false
render prod-noinit prod --set image.tag=$rev1 --set initialize.enabled=false
render ci ci --set image.tag=$rev1
render ci-rev2 ci --set image.tag=$rev2
