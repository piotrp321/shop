#!/bin/sh
# Static checks of the chart, without a cluster and without a server. Every step runs in a container (tools.sh).
#
#   check.sh            all steps
#   check.sh <step>...  only the given steps: lint, render, schema, images, kubeconform, shellcheck, actionlint, tests, bootstrap,
#                       gitignore
#
# The first failing step stops the script. REPO_ROOT (default: this repository) selects the tree to check; the
# self-test (selftest.sh) points it at a copy with an injected error.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"

steps="${*:-lint render schema images kubeconform shellcheck actionlint tests bootstrap gitignore}"

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
  step "helm lint (prod, ci, load)"
  # shellcheck disable=SC2086 # $digests is a list of arguments
  helm lint charts/shop --strict --kube-version "$KUBERNETES_VERSION" -f environments/prod/values.yaml --set image.tag=$rev $digests
  helm lint charts/shop --strict --kube-version "$KUBERNETES_VERSION" -f environments/ci/values.yaml --set image.tag=$rev
  helm lint charts/shop --strict --kube-version "$KUBERNETES_VERSION" -f environments/ci/values.yaml -f environments/load/values.yaml \
    --set image.tag=$rev
}

do_render() {
  step "helm template: all cases"
  sh "$here/render.sh" "$work"
}

# A render that must fail names the value that is wrong or missing in the message of helm. The Kubernetes version is
# that of the cluster of the smoke test unless the arguments give another one (the last --kube-version wins).
expect_failure() {
  description="$1" expected="$2"
  shift 2
  if output="$(helm template shop charts/shop --namespace shop --kube-version "$KUBERNETES_VERSION" "$@" 2>&1)"; then
    echo "schema: $description: the render succeeded, it must fail" >&2
    return 1
  fi
  case "$output" in
    *"$expected"*) echo "ok: $description" ;;
    *) echo "schema: $description: the error does not name '$expected':" >&2; echo "$output" >&2; return 1 ;;
  esac
}

do_schema() {
  step "values.schema.json and Chart.yaml: wrong values and clusters fail the render and say why"
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
    # garage-init mounts the binary of Garage from its image (a volume of type image): stable since Kubernetes 1.36.
    expect_failure "Kubernetes 1.35" "kubeVersion" $prod --set image.tag=$rev $digests --kube-version 1.35.9
    expect_failure "secret created without values" "/secrets" -f environments/ci/values.yaml --set image.tag=$rev \
      --set secrets.values=null
    expect_failure "secret created without one key" "/secrets/values/STORE_SESSION_SECRET" -f environments/ci/values.yaml \
      --set image.tag=$rev --set secrets.values.STORE_SESSION_SECRET=
    # A value that selects Brevo needs the Secret with the Brevo SMTP login and key.
    expect_failure "orderMail brevo without the Brevo secret" "/brevo/secretName" -f environments/ci/values.yaml \
      --set image.tag=$rev --set orderMail=brevo
    expect_failure "Keycloak mail through Brevo without the Brevo secret" "/brevo/secretName" -f environments/ci/values.yaml \
      --set image.tag=$rev --set keycloak.mail=brevo
    expect_failure "prod (Keycloak mail through Brevo) without the Brevo secret" "/brevo/secretName" $prod --set image.tag=$rev \
      $digests --set brevo.secretName=
    expect_failure "unknown orderMail" "/orderMail" $prod --set image.tag=$rev $digests --set orderMail=sendgrid
    expect_failure "unknown mail of Keycloak" "/keycloak/mail" $prod --set image.tag=$rev $digests --set keycloak.mail=sendgrid
    expect_failure "customerAccounts that is not a boolean" "/customerAccounts" $prod --set image.tag=$rev $digests \
      --set-string customerAccounts=false
    expect_failure "demoNotice that is not a boolean" "/demoNotice" $prod --set image.tag=$rev $digests --set-string demoNotice=true
    # D3 (docs/features/hardening/spec.md): the header of the client address is trusted only behind the allow list,
    # and going without it is a confirmed choice that no deployment with the images of a release may make.
    expect_failure "clientIpHeader without ipAllowList and without the confirmation" "/clientIpHeaderWithoutAllowList" \
      -f environments/ci/values.yaml --set image.tag=$rev --set clientIpHeader=CF-Connecting-IP
    expect_failure "prod with clientIpHeaderWithoutAllowList" "/clientIpHeaderWithoutAllowList" $prod --set image.tag=$rev $digests \
      --set clientIpHeaderWithoutAllowList=true
    expect_failure "clientIpHeaderWithoutAllowList with requireDigests" "/clientIpHeaderWithoutAllowList" -f environments/ci/values.yaml \
      --set image.tag=$rev $digests --set image.requireDigests=true --set clientIpHeader=CF-Connecting-IP \
      --set clientIpHeaderWithoutAllowList=true
    expect_failure "clientIpHeader that is no header name" "/clientIpHeader" $prod --set image.tag=$rev $digests \
      --set 'clientIpHeader=CF Connecting IP'
    expect_failure "clientIpHeaderWithoutAllowList that is not a boolean" "/clientIpHeaderWithoutAllowList" \
      -f environments/ci/values.yaml --set image.tag=$rev --set-string clientIpHeaderWithoutAllowList=true
  }
}

# The images values file of the branch deploy-images (what Argo CD reads next to the profile prod): the generator writes
# it, the render with it has exactly the images of the file, and a file with a digest missing or wrong does not render.
do_images() {
  step "images values file (scripts/images-values.sh): the prod render with it, and without a digest or with a wrong one"
  digest_of() { printf 'sha256:%064d' 0 | tr 0 "$1"; }
  mkdir -p "$work"
  DIGEST_API="$(digest_of 1)" DIGEST_STORE="$(digest_of 2)" DIGEST_ADMIN="$(digest_of 3)" DIGEST_ELASTICSEARCH="$(digest_of 4)" \
    sh "$here/images-values.sh" "$rev" "$work/values-images.yaml"
  sh "$here/verify-images.sh" "$work/values-images.yaml" "$rev"
  # The generator refuses what the chart would refuse, and the chart refuses a file that lacks an image.
  if DIGEST_API=latest DIGEST_STORE="$(digest_of 2)" DIGEST_ADMIN="$(digest_of 3)" DIGEST_ELASTICSEARCH="$(digest_of 4)" \
    sh "$here/images-values.sh" "$rev" "$work/bad.yaml" 2> /dev/null || [ -e "$work/bad.yaml" ]; then
    echo "images: the generator accepted a digest that is a tag" >&2
    return 1
  fi
  echo "ok: the generator refuses a malformed digest and writes nothing"
  in_cache="/cache/${work##*/}"
  grep -v '^    elasticsearch:' "$work/values-images.yaml" > "$work/no-elasticsearch.yaml"
  expect_failure "images file without the digest of elasticsearch" "/image/digests/elasticsearch" \
    -f environments/prod/values.yaml -f "$in_cache/no-elasticsearch.yaml"
  sed 's/^    store: .*/    store: latest/' "$work/values-images.yaml" > "$work/bad-store.yaml"
  expect_failure "images file with a digest that is a tag" "/image/digests/store" \
    -f environments/prod/values.yaml -f "$in_cache/bad-store.yaml"
  sed 's/^  tag: .*/  tag: latest/' "$work/values-images.yaml" > "$work/latest.yaml"
  expect_failure "images file with the revision latest" "/image/tag" -f environments/prod/values.yaml -f "$in_cache/latest.yaml"
  if sh "$here/verify-images.sh" "$work/values-images.yaml" 89abcdef0123456789abcdef0123456789abcdef > "$work/verify-wrong.log" 2>&1; then
    echo "images: verify-images.sh accepted a render for another commit" >&2
    return 1
  fi
  grep -q 'are not the ones in' "$work/verify-wrong.log" || { cat "$work/verify-wrong.log" >&2; return 1; }
  echo "ok: verify-images.sh refuses a render that is not for the given commit"
  if sh "$here/verify-images.sh" "$work/does-not-exist.yaml" "$rev" > /dev/null 2>&1; then
    echo "images: verify-images.sh accepted a missing file" >&2
    return 1
  fi
  echo "ok: verify-images.sh refuses a missing file"
}

do_kubeconform() {
  step "kubeconform (Kubernetes $KUBERNETES_VERSION)"
  for case_name in prod ci load argocd; do
    # The CRDs of Traefik (Middleware), of Argo CD (Application) and of the Helm controller of k3s (HelmChart) are validated against the schemas of the CRDs catalog, pinned to a commit.
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
  shellcheck -x -P SCRIPTDIR $(cd "$TOOLS_REPO/infra/k8s" && ls scripts/*.sh charts/shop/files/scripts/*.sh tests/bootstrap/*.sh) \
    ../../.github/scripts/deploy-ref.sh ../../load/k6.sh ../../load/smoke.sh
}

# The workflows of the repository (deploy.yml among them), with shellcheck on their run: scripts.
do_actionlint() {
  step "actionlint"
  [ -f "$TOOLS_REPO/.github/workflows/deploy.yml" ] || { echo "actionlint: .github/workflows/deploy.yml is missing" >&2; return 1; }
  # shellcheck disable=SC2046 # the file names have no spaces
  actionlint $(cd "$TOOLS_REPO" && ls .github/workflows/*.yml)
}

do_tests() {
  [ -f "$work/prod.ndjson" ] || do_render
  step "tests of the rendered chart and of the scripts"
  tools_run -e RENDER_DIR="/cache/render-$$" "$PYTHON_IMAGE" python -I -m unittest discover -s tests
}

# scripts/server-bootstrap.sh in a container with Ubuntu 26.04 (root, no k3s, no systemd): the refusals before any change, then
# two runs; see tests/bootstrap/test.sh for what is real and what is stubbed.
do_bootstrap() {
  step "server-bootstrap.sh in Ubuntu 26.04 without k3s: refusals and two runs"
  docker run --rm -v "$TOOLS_REPO:/repo:ro" "$UBUNTU_IMAGE" sh /repo/infra/k8s/tests/bootstrap/test.sh
}

# The certificate of the Sealed Secrets controller is committed (the runbook says so), so git must not ignore it. This is the
# only step that runs git, on the host (the containers of the other steps have no .git); --no-index asks the rules alone,
# so the answer stays the same once the file is tracked.
do_gitignore() {
  step "git does not ignore the certificate of Sealed Secrets"
  cert=infra/k8s/environments/prod/sealed-secrets.pem
  command -v git > /dev/null || { echo "gitignore: git is not installed" >&2; return 1; }
  status=0
  git -C "$TOOLS_REPO" check-ignore --no-index -q "$cert" || status=$?
  case "$status" in
    1) echo "ok: $cert is not ignored" ;;
    0) echo "gitignore: $cert is ignored by git (an exception after the rule that matches it is missing in .gitignore): the certificate could not be committed:" >&2
       git -C "$TOOLS_REPO" check-ignore --no-index -v "$cert" >&2 || true
       return 1 ;;
    *) echo "gitignore: git check-ignore failed (exit $status): is $TOOLS_REPO a git repository?" >&2; return 1 ;;
  esac
}

for name in $steps; do
  "do_$name"
done
printf '\ncheck: ok (%s)\n' "$steps"
