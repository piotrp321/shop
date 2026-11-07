#!/bin/sh
# Static checks of the chart, without a cluster and without a server. Every step runs in a container (tools.sh).
#
#   check.sh            all steps
#   check.sh <step>...  only the given steps: lint, render, schema, kubeconform, shellcheck, tests
#
# The first failing step stops the script. REPO_ROOT (default: this repository) selects the tree to check; the
# self-test (selftest.sh) points it at a copy with an injected error.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"

steps="${*:-lint render schema kubeconform shellcheck tests}"

rev=0123456789abcdef0123456789abcdef01234567
# The digests of the images built by us, which the profile prod requires.
digest=sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
digests="--set image.digests.api=$digest --set image.digests.store=$digest --set image.digests.admin=$digest --set image.digests.elasticsearch=$digest"
work="$TOOLS_CACHE/render-$$"
mkdir -p "$TOOLS_CACHE"
rm -rf "$work"
mkdir -p "$work"
trap 'rm -rf "$work"' EXIT

step() { printf '\n== %s\n' "$1"; }

do_lint() {
  step "helm lint (prod, ci)"
  # shellcheck disable=SC2086 # $digests is a list of arguments
  helm lint charts/shop --strict -f environments/prod/values.yaml --set image.tag=$rev $digests
  helm lint charts/shop --strict -f environments/ci/values.yaml --set image.tag=$rev
}

do_render() {
  step "helm template: all cases"
  sh "$here/render.sh" "$work"
}

# A render that must fail names the value that is wrong or missing in the message of helm.
expect_failure() {
  description="$1" expected="$2"
  shift 2
  if output="$(helm template shop charts/shop --namespace shop "$@" 2>&1)"; then
    echo "schema: $description: the render succeeded, it must fail" >&2
    return 1
  fi
  case "$output" in
    *"$expected"*) echo "ok: $description" ;;
    *) echo "schema: $description: the error does not name '$expected':" >&2; echo "$output" >&2; return 1 ;;
  esac
}

do_schema() {
  step "values.schema.json: missing values fail the render and name the value"
  prod="-f environments/prod/values.yaml"
  # shellcheck disable=SC2086 # $prod and $digests are lists of arguments
  {
    expect_failure "no revision" "/image/tag" $prod $digests
    expect_failure "revision latest" "/image/tag" $prod $digests --set image.tag=latest
    expect_failure "revision latest without digests (ci)" "/image/tag" -f environments/ci/values.yaml --set image.tag=latest
    expect_failure "prod without digests" "/image/digests/api" $prod --set image.tag=$rev
    expect_failure "prod without the digest of elasticsearch" "/image/digests/elasticsearch" $prod --set image.tag=$rev $digests \
      --set image.digests.elasticsearch=
    expect_failure "digest that is a tag" "/image/digests/store" $prod --set image.tag=$rev $digests --set image.digests.store=latest
    expect_failure "malformed digest without requireDigests (ci)" "/image/digests/admin" -f environments/ci/values.yaml \
      --set image.tag=$rev --set image.digests.admin=sha256:abc
    expect_failure "digest of an unknown image" "'apy' not allowed" $prod --set image.tag=$rev $digests --set image.digests.apy=$digest
    expect_failure "image without a digest" "/images/redis" $prod --set image.tag=$rev $digests --set images.redis=redis:8.10.3
    expect_failure "no domain" "/global/domain" $prod --set image.tag=$rev $digests --set global.domain=
    expect_failure "bad scheme" "/global/scheme" $prod --set image.tag=$rev $digests --set global.scheme=ftp
    expect_failure "ipAllowList without ranges" "/ipAllowList" $prod --set image.tag=$rev $digests --set 'ipAllowList.sourceRange=null'
    expect_failure "secret created without values" "/secrets" -f environments/ci/values.yaml --set image.tag=$rev \
      --set secrets.values=null
    expect_failure "secret created without one key" "/secrets/values/STORE_SESSION_SECRET" -f environments/ci/values.yaml \
      --set image.tag=$rev --set secrets.values.STORE_SESSION_SECRET=
  }
}

do_kubeconform() {
  step "kubeconform (Kubernetes $KUBERNETES_VERSION)"
  for case_name in prod ci; do
    # The CRDs of Traefik (Middleware) are validated against the schemas of the CRDs catalog, pinned to a commit.
    if ! output="$(kubeconform -strict -summary -kubernetes-version "$KUBERNETES_VERSION" \
      -schema-location default \
      -schema-location 'https://raw.githubusercontent.com/datreeio/CRDs-catalog/c86448cbf1a840ca62d119259864863897c8748a/{{.Group}}/{{.ResourceKind}}_{{.ResourceAPIVersion}}.json' \
      "/cache/render-$$/$case_name.yaml" 2>&1)"; then
      echo "$output" | sed "s/^/$case_name: /" >&2
      return 1
    fi
    echo "$output" | sed "s/^/$case_name: /"
  done
}

do_shellcheck() {
  step "shellcheck"
  # shellcheck disable=SC2046 # the file names have no spaces
  shellcheck -x -P SCRIPTDIR $(cd "$TOOLS_REPO/infra/k8s" && ls scripts/*.sh charts/shop/files/scripts/*.sh)
}

do_tests() {
  [ -f "$work/prod.ndjson" ] || do_render
  step "tests of the rendered chart and of the scripts"
  tools_run -e RENDER_DIR="/cache/render-$$" "$PYTHON_IMAGE" python -I -m unittest discover -s tests
}

for name in $steps; do
  "do_$name"
done
printf '\ncheck: ok (%s)\n' "$steps"
