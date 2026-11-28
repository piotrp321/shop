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
# The profile prod requires the digests of the images built by us (tests/support.py has the same values); ci has none.
# digest <letter>: sha256: and the letter 64 times.
digest() { printf 'sha256:%064d' 0 | tr 0 "$1"; }
digests="--set image.digests.api=$(digest a) --set image.digests.store=$(digest b)"
digests="$digests --set image.digests.admin=$(digest c) --set image.digests.elasticsearch=$(digest d)"

# render <case> <environment> [helm arguments]
render() {
  case_name="$1" env="$2"
  shift 2
  if ! helm template shop charts/shop --namespace shop --kube-version "$KUBERNETES_VERSION" -f "environments/$env/values.yaml" "$@" \
    > "$out/$case_name.yaml"; then
    echo "render: helm template failed for $case_name" >&2
    exit 1
  fi
  yq -o=json -I=0 '.' < "$out/$case_name.yaml" > "$out/$case_name.ndjson"
}

# shellcheck disable=SC2086 # $digests is a list of arguments
{
  render prod prod --set image.tag=$rev1 $digests
  render prod-again prod --set image.tag=$rev1 $digests
  render prod-rev2 prod --set image.tag=$rev2 $digests
  render prod-domain prod --set image.tag=$rev1 $digests --set global.domain=other.example.net
  render prod-indexable prod --set image.tag=$rev1 $digests --set noindex=false
  render prod-noinit prod --set image.tag=$rev1 $digests --set initialize.enabled=false
  # The backup is off in prod until its repository and secret exist (README.md, section 2): its tests render it switched on.
  render prod-backup prod --set image.tag=$rev1 $digests --set backup.enabled=true
  render prod-nobackup prod --set image.tag=$rev1 $digests --set backup.enabled=false
  render prod-noalerts prod --set image.tag=$rev1 $digests --set alerts.enabled=false
  render prod-verify-schedule prod --set image.tag=$rev1 $digests --set backup.enabled=true --set backup.verifySchedule='30 6 * * 1'
  # The switches of D10, one at a time against the profile (tests/test_switches.py).
  render prod-accounts prod --set image.tag=$rev1 $digests --set customerAccounts=true
  render prod-nodemo prod --set image.tag=$rev1 $digests --set demoNotice=false
  render prod-order-brevo prod --set image.tag=$rev1 $digests --set orderMail=brevo
  # The way back to the first step of the generator on the server (tests/test_demo_data.py): the profile medium, its time limit.
  # The four replicas of worker-indexing of the criterion of the rebuild: the node still fits them (tests/test_workloads.py, tests/test_indexing.py).
  render prod-indexing prod --set image.tag=$rev1 $digests --set workers.indexing.replicas=4
  render prod-demo-data-medium prod --set image.tag=$rev1 $digests --set demoData.profile=medium
}
# Argo CD renders prod from the two values files only: the profile and the images file of the branch deploy-images,
# which scripts/images-values.sh writes (no --set). The file is in the output directory, which the helm container
# mounts as /cache/<name of the directory>.
DIGEST_API="$(digest a)" DIGEST_STORE="$(digest b)" DIGEST_ADMIN="$(digest c)" DIGEST_ELASTICSEARCH="$(digest d)" \
  sh "$here/images-values.sh" "$rev1" "$out/values-images.yaml"
render prod-images-file prod -f "/cache/${out##*/}/values-images.yaml"
render ci ci --set image.tag=$rev1
# The smoke test with SMOKE_VALUES: the profile ci, the images file and the arguments smoke.sh adds (tools.sh).
# shellcheck disable=SC2046 # smoke_pull_args prints options
render ci-images-file ci -f "/cache/${out##*/}/values-images.yaml" $(smoke_pull_args)
render ci-rev2 ci --set image.tag=$rev2
# The refresh interval of the search index from the values (tests/test_reindex.py): SEARCH_REFRESH_INTERVAL of every container
# of the API.
render ci-refresh ci --set image.tag=$rev1 --set elasticsearch.refreshInterval=500ms
# The replicas of worker-indexing and the size of the batch of the search rebuild from the values (tests/test_indexing.py).
render ci-indexing ci --set image.tag=$rev1 --set workers.indexing.replicas=4 --set search.indexBatchSize=1000
# The alerts on a profile that has other workloads than prod (Mailpit): the rule follows the render (tests/test_alerts.py).
render ci-alerts ci --set image.tag=$rev1 --set alerts.enabled=true
render ci-accounts ci --set image.tag=$rev1 --set customerAccounts=true
render ci-keycloak-brevo ci --set image.tag=$rev1 --set keycloak.mail=brevo --set brevo.secretName=smoke-brevo
# The header of the client address without the allow list, confirmed (the profile of the measurement, tests/test_client_ip.py).
render ci-client-ip ci --set image.tag=$rev1 --set clientIpHeader=CF-Connecting-IP --set clientIpHeaderWithoutAllowList=true
# The CronJob demo-data (tests/test_demo_data.py): ci with the switch off, the profile medium with an anchor, the secret of the photo
# pool and other resources, and the profiles with the longer time limits.
render ci-no-demo-data ci --set image.tag=$rev1 --set demoData.enabled=false
render ci-demo-data-medium ci --set image.tag=$rev1 --set demoData.profile=medium --set demoData.anchor=2026-11-01 \
  --set demoData.photosSecretName=demo-photos --set resources.demoData.requests.memory=777Mi
render ci-demo-data-server ci --set image.tag=$rev1 --set demoData.profile=server
render ci-demo-data-full ci --set image.tag=$rev1 --set demoData.profile=full
# Sentry (tests/test_sentry.py): one application with a DSN at a time, all three, prod with all three, a rate and an environment
# without a DSN, and the default rate. The DSNs differ in the key and the project, so the test sees which one went where.
sentry_api=https://aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@o1.ingest.de.sentry.io/1
sentry_store=https://bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb@o1.ingest.de.sentry.io/2
sentry_admin=https://cccccccccccccccccccccccccccccccc@o1.ingest.de.sentry.io/3
sentry_values="--set sentry.environment=smoke --set-json sentry.tracesSampleRate=0.25"
# shellcheck disable=SC2086 # $sentry_values and $digests are lists of arguments
{
  render ci-sentry-api ci --set image.tag=$rev1 $sentry_values --set sentry.dsn.api=$sentry_api
  render ci-sentry-store ci --set image.tag=$rev1 $sentry_values --set sentry.dsn.store=$sentry_store
  render ci-sentry-admin ci --set image.tag=$rev1 $sentry_values --set sentry.dsn.admin=$sentry_admin
  render ci-sentry ci --set image.tag=$rev1 $sentry_values --set sentry.dsn.api=$sentry_api --set sentry.dsn.store=$sentry_store \
    --set sentry.dsn.admin=$sentry_admin
  render ci-sentry-nodsn ci --set image.tag=$rev1 $sentry_values
  render ci-sentry-default-rate ci --set image.tag=$rev1 --set sentry.environment=smoke --set sentry.dsn.api=$sentry_api \
    --set sentry.dsn.store=$sentry_store --set sentry.dsn.admin=$sentry_admin
  render prod-sentry prod --set image.tag=$rev1 $digests --set sentry.dsn.api=$sentry_api --set sentry.dsn.store=$sentry_store \
    --set sentry.dsn.admin=$sentry_admin
}
# The profile of the load measurement: ci with environments/load/values.yaml on top, as scripts/load.sh installs it
# (tests/test_load_profile.py).
render load ci -f environments/load/values.yaml --set image.tag=$rev1
# The Argo CD manifests (argocd/): the root, the applications it creates and what bootstrap installs. argocd.yaml is the
# whole set for kubeconform, the .ndjson files are one JSON document per line for tests/test_argocd.py.
: > "$out/argocd.yaml"
for part in root apps install; do
  files="argocd/$part.yaml"
  [ -d "$here/../argocd/$part" ] && files="$(cd "$here/.." && ls argocd/"$part"/*.yaml)"
  # shellcheck disable=SC2086 # the file names have no spaces
  yq -o=json -I=0 '.' $files > "$out/argocd-$part.ndjson"
  for file in $files; do { cat "$here/../$file"; echo '---'; } >> "$out/argocd.yaml"; done
done
# The sealed secrets in the repository (environments/prod/secrets), for tests/test_sealed_secrets.py; empty when there are none.
sealed_files="$(cd "$here/.." && ls environments/prod/secrets/*.yaml 2> /dev/null || true)"
if [ -n "$sealed_files" ]; then
  # shellcheck disable=SC2086 # the file names have no spaces
  yq -o=json -I=0 '.' $sealed_files > "$out/sealed.ndjson"
else
  : > "$out/sealed.ndjson"
fi
# The deployment workflow as JSON, for tests/test_deploy_workflow.py, and every workflow of the repository (workflows/<file>.json,
# both extensions GitHub reads) for its rule on secrets.
[ -f "$TOOLS_REPO/.github/workflows/deploy.yml" ] || { echo "render: .github/workflows/deploy.yml is missing" >&2; exit 1; }
yq -o=json '.' < "$TOOLS_REPO/.github/workflows/deploy.yml" > "$out/deploy-workflow.json"
mkdir -p "$out/workflows"
for workflow in "$TOOLS_REPO"/.github/workflows/*.yml "$TOOLS_REPO"/.github/workflows/*.yaml; do
  [ -f "$workflow" ] || continue
  yq -o=json '.' < "$workflow" > "$out/workflows/${workflow##*/}.json"
done
# The charts of the applications prometheus-crds and monitoring with the values of their manifests (monitoring.yaml,
# monitoring.ndjson, prometheus-crds.yaml, ... for kubeconform and tests/test_monitoring.py) and the log masking run in a real
# Alloy (masking-output.log for tests/test_log_masking.py).
sh "$here/monitoring-render.sh" "$out"
sh "$here/masking.sh" "$out"
