#!/bin/sh
# Brings the ranges of Cloudflare in environments/prod/values.yaml (ipAllowList.sourceRange, D6) up to date with the
# published lists https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6:
#
#   update-cloudflare-ips.sh           rewrites the list in the file when Cloudflare's differs (then commit the change; the
#                                      next deployment brings it to the middleware)
#   update-cloudflare-ips.sh --check   changes nothing; exit 1 when the list is out of date
#
# curl runs in a container (tools.sh). Anything that does not look like the two lists (a failed download, an HTML page, an
# empty list, a line that is not a CIDR range) is an error and the file stays as it is.
# Exit codes: 0 up to date or updated; 1 out of date (--check) or a failure; 2 wrong arguments.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"

mode=update
case "${1:-}" in
  "") ;;
  --check) mode=check ;;
  *) echo "update-cloudflare-ips: usage: update-cloudflare-ips.sh [--check]" >&2; exit 2 ;;
esac

values="$TOOLS_REPO/infra/k8s/environments/prod/values.yaml"
[ -f "$values" ] || { echo "update-cloudflare-ips: no $values" >&2; exit 1; }
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

fetch() {
  docker run --rm "$CURL_IMAGE" -fsS --max-time 30 "$1" > "$2" || {
    echo "update-cloudflare-ips: could not download $1" >&2
    exit 1
  }
}
fetch https://www.cloudflare.com/ips-v4 "$work/v4"
fetch https://www.cloudflare.com/ips-v6 "$work/v6"

# Every line a range: IPv4 a.b.c.d/n or IPv6 hex groups with colons /n.
: > "$work/new"
for family in v4 v6; do
  [ -s "$work/$family" ] || { echo "update-cloudflare-ips: the list ips-$family is empty" >&2; exit 1; }
  while IFS= read -r range || [ -n "$range" ]; do
    [ -n "$range" ] || continue
    case "$family:$range" in
      v4:*[!0-9./]* | v6:*[!0-9a-fA-F:/]* | *//* | */ | /* | *[!0-9]) echo "update-cloudflare-ips: '$range' in ips-$family is not a range" >&2; exit 1 ;;
      v4:*.*.*.*/* | v6:*:*/*) echo "$range" >> "$work/new" ;;
      *) echo "update-cloudflare-ips: '$range' in ips-$family is not a range" >&2; exit 1 ;;
    esac
  done < "$work/$family"
done

sed -n '/^  sourceRange:$/,$p' "$values" | sed '1d' | sed -n '/^    - /!q;p' | sed 's/^    - //' > "$work/current"
[ -s "$work/current" ] || { echo "update-cloudflare-ips: no ipAllowList.sourceRange list in $values" >&2; exit 1; }
if cmp -s "$work/current" "$work/new"; then
  echo "update-cloudflare-ips: the list is up to date ($(grep -c . "$work/new") ranges)"
  exit 0
fi
echo "update-cloudflare-ips: the list differs from Cloudflare's:"
sort "$work/current" > "$work/current.sorted"
sort "$work/new" > "$work/new.sorted"
comm -23 "$work/current.sorted" "$work/new.sorted" | sed 's/^/  removed: /'
comm -13 "$work/current.sorted" "$work/new.sorted" | sed 's/^/  added:   /'

if [ "$mode" = check ]; then
  echo "update-cloudflare-ips: out of date (run it without --check to rewrite the file)" >&2
  exit 1
fi
awk -v list="$work/new" '
  /^  sourceRange:$/ { print; while ((getline range < list) > 0) print "    - " range; skipping = 1; next }
  skipping && /^    - / { next }
  { skipping = 0; print }' "$values" > "$work/values.new"
cat "$work/values.new" > "$values"
echo "update-cloudflare-ips: rewrote the list in infra/k8s/environments/prod/values.yaml; commit it"
