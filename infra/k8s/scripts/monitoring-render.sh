#!/bin/sh
# Renders the charts of the applications prometheus-crds and monitoring of argocd/apps with the values of their manifests
# (docs/features/deploy-ops/spec.md, O5), for kubeconform and for the tests of tests/:
#
#   <outdir>/monitoring-values.yaml    the valuesObject of argocd/apps/monitoring.yaml (what Argo CD hands to Helm)
#   <outdir>/monitoring.yaml           helm template of the chart k8s-monitoring of the pinned version with those values (no CRDs:
#                                      kubeconform has no schema for the kind CustomResourceDefinition)
#   <outdir>/monitoring.ndjson         the same as one JSON document per line
#   <outdir>/monitoring-config.alloy   the Alloy configuration of the chart (the ConfigMap monitoring-alloy)
#   <outdir>/prometheus-crds.yaml, .ndjson   helm template of prometheus-operator-crds of the pinned version (its CRDs)
#   <outdir>/monitoring-chart.json     chart, version and sha256 of the archives: JSON, for the tests
#
# The charts are published (Helm repositories), pinned by the version of their manifest and by the sha256 of the archive that
# is in this file: an archive that differs, or a version without a digest here, stops the script. The archives are downloaded
# once into the cache of the tools (TOOLS_CACHE/charts). Argo CD renders with the API versions of the cluster; the CRDs of
# prometheus-crds are there by then (wave -3), so the render has monitoring.coreos.com/v1.
# Usage: monitoring-render.sh <outdir>   (outdir inside TOOLS_CACHE, which the containers mount as /cache)
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"

out="${1:?usage: monitoring-render.sh <outdir>}"
mkdir -p "$out"
in_cache="/cache/${out##*/}"
charts="$TOOLS_CACHE/charts"
mkdir -p "$charts"

# The sha256 of the chart archives of the versions that argocd/apps pin: <chart>-<version>.
pinned_digest() {
  case "$1" in
    k8s-monitoring-4.6.0) echo 082ecdcea60924b879e7ef7cc0f8ef3bc4128954da72688fe72075c7acdde0d2 ;;
    prometheus-operator-crds-33.0.2) echo deb80119aa72342845776db1ed004714ce87cbc807d9eecaa72f4837ee5f79f5 ;;
    *) return 1 ;;
  esac
}

sha256_of() {
  if command -v sha256sum > /dev/null; then sha256sum "$1" | cut -d ' ' -f 1; else shasum -a 256 "$1" | cut -d ' ' -f 1; fi
}

# fetch <application manifest>: prints "<chart> <version> <archive>" for the chart of the manifest, downloaded when it is not in the cache.
fetch() {
  app="argocd/apps/$1.yaml"
  chart="$(yq '.spec.source.chart' < "$TOOLS_REPO/infra/k8s/$app")"
  version="$(yq '.spec.source.targetRevision' < "$TOOLS_REPO/infra/k8s/$app")"
  repo="$(yq '.spec.source.repoURL' < "$TOOLS_REPO/infra/k8s/$app")"
  if ! expected="$(pinned_digest "$chart-$version")"; then
    echo "monitoring-render: $app pins $chart $version, which has no sha256 in scripts/monitoring-render.sh: download the archive, check it and add its digest there" >&2
    return 1
  fi
  archive="$charts/$chart-$version.tgz"
  if [ ! -f "$archive" ]; then
    pulled="$(mktemp -d "$charts/pull-XXXXXX")"
    if ! helm pull "$chart" --repo "$repo" --version "$version" --destination "/cache/charts/${pulled##*/}" > /dev/null; then
      rm -rf "$pulled"
      echo "monitoring-render: cannot download $chart $version from $repo (the network is needed once per version)" >&2
      return 1
    fi
    mv "$pulled/$chart-$version.tgz" "$archive"
    rm -rf "$pulled"
  fi
  actual="$(sha256_of "$archive")"
  if [ "$actual" != "$expected" ]; then
    rm -f "$archive"
    echo "monitoring-render: the archive of $chart $version has sha256 $actual, not the $expected of scripts/monitoring-render.sh" >&2
    return 1
  fi
  printf '%s %s %s\n' "$chart" "$version" "$actual"
}

monitoring="$(fetch monitoring)"
crds="$(fetch prometheus-crds)"
monitoring_chart="${monitoring%% *}" rest="${monitoring#* }"
monitoring_version="${rest%% *}" monitoring_sha="${rest#* }"
crds_chart="${crds%% *}" rest="${crds#* }"
crds_version="${rest%% *}" crds_sha="${rest#* }"

yq '.spec.source.helm.valuesObject' < "$TOOLS_REPO/infra/k8s/argocd/apps/monitoring.yaml" > "$out/monitoring-values.yaml"
release="$(yq '.spec.source.helm.releaseName' < "$TOOLS_REPO/infra/k8s/argocd/apps/monitoring.yaml")"
namespace="$(yq '.spec.destination.namespace' < "$TOOLS_REPO/infra/k8s/argocd/apps/monitoring.yaml")"

if ! helm template "$release" "/cache/charts/$monitoring_chart-$monitoring_version.tgz" --namespace "$namespace" \
  --kube-version "$KUBERNETES_VERSION" --api-versions monitoring.coreos.com/v1 -f "$in_cache/monitoring-values.yaml" > "$out/monitoring.yaml"; then
  echo "monitoring-render: helm template failed for the application monitoring" >&2
  exit 1
fi
yq '.spec.source.helm.valuesObject // {}' < "$TOOLS_REPO/infra/k8s/argocd/apps/prometheus-crds.yaml" > "$out/prometheus-crds-values.yaml"
if ! helm template prometheus-crds "/cache/charts/$crds_chart-$crds_version.tgz" --namespace kube-system --include-crds \
  --kube-version "$KUBERNETES_VERSION" -f "$in_cache/prometheus-crds-values.yaml" > "$out/prometheus-crds.yaml"; then
  echo "monitoring-render: helm template failed for the application prometheus-crds" >&2
  exit 1
fi
yq -o=json -I=0 '.' < "$out/monitoring.yaml" > "$out/monitoring.ndjson"
yq -o=json -I=0 '.' < "$out/prometheus-crds.yaml" > "$out/prometheus-crds.ndjson"
yq 'select(.kind == "ConfigMap" and .metadata.name == "monitoring-alloy") | .data["config.alloy"]' < "$out/monitoring.yaml" \
  > "$out/monitoring-config.alloy"
[ -s "$out/monitoring-config.alloy" ] || { echo "monitoring-render: no ConfigMap monitoring-alloy in the render" >&2; exit 1; }
printf '{"charts": {"%s": {"version": "%s", "sha256": "%s"}, "%s": {"version": "%s", "sha256": "%s"}}}\n' \
  "$monitoring_chart" "$monitoring_version" "$monitoring_sha" "$crds_chart" "$crds_version" "$crds_sha" > "$out/monitoring-chart.json"
