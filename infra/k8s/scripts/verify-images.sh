#!/bin/sh
# Renders the chart exactly as Argo CD will (the values of the prod profile and the images values file, nothing else:
# no --set) and checks that every image built by us in the render is
# <registry>/<app>:<sha>@<digest> with the digest from the file, and that no image of the registry is left out or comes
# from elsewhere. The deployment workflow runs it before it publishes a version; `make check` runs it on a generated file.
#
# Usage: verify-images.sh <images values file> <commit sha>
# Exit codes: 0 the render matches, 1 the render fails or does not match, 2 wrong arguments or a missing file.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"

file="${1:-}"
sha="${2:-}"
if [ $# -ne 2 ] || [ -z "$file" ] || [ -z "$sha" ]; then
  echo "verify-images: usage: verify-images.sh <images values file> <commit sha>" >&2
  exit 2
fi
if [ ! -f "$file" ]; then
  echo "verify-images: the file '$file' does not exist" >&2
  exit 2
fi

registry="$(sed -n 's/^  registry: //p' "$TOOLS_REPO/infra/k8s/charts/shop/values.yaml" | head -n 1)"
[ -n "$registry" ] || { echo "verify-images: no image.registry in charts/shop/values.yaml" >&2; exit 1; }

# The file goes to the cache directory, which the helm container mounts (the file may be anywhere on the host).
mkdir -p "$TOOLS_CACHE"
dir="$(mktemp -d "$TOOLS_CACHE/verify-XXXXXX")"
trap 'rm -rf "$dir"' EXIT
cp "$file" "$dir/values-images.yaml"
if ! helm template shop charts/shop --namespace shop --kube-version "$KUBERNETES_VERSION" \
  -f environments/prod/values.yaml -f "/cache/${dir##*/}/values-images.yaml" > "$dir/render.yaml" 2> "$dir/error"; then
  echo "verify-images: the chart does not render with environments/prod/values.yaml and $file:" >&2
  cat "$dir/error" >&2
  exit 1
fi

# The digests as the file has them (the first match of each key under digests).
digest_of() { sed -n "s/^    $1: \(sha256:[0-9a-f]\{64\}\)\$/\1/p" "$file" | head -n 1; }
for app in api store admin elasticsearch; do
  digest="$(digest_of "$app")"
  [ -n "$digest" ] || { echo "verify-images: no digest of $app in $file" >&2; exit 1; }
  printf '%s/%s:%s@%s\n' "$registry" "$app" "$sha" "$digest"
done | sort > "$dir/expected"

# Every reference to the registry of the render, whatever field it is in (container, initContainer, image volume).
grep -o "$registry/[a-z]*[:@][^\"' ]*" "$dir/render.yaml" | sort -u > "$dir/found" || true
if ! cmp -s "$dir/expected" "$dir/found"; then
  echo "verify-images: the images in the render are not the ones in $file." >&2
  echo "verify-images: expected:" >&2
  sed 's/^/  /' "$dir/expected" >&2
  echo "verify-images: found:" >&2
  sed 's/^/  /' "$dir/found" >&2
  exit 1
fi
echo "verify-images: the render of prod has exactly these images:"
sed 's/^/  /' "$dir/expected"
