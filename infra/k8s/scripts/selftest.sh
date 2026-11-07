#!/bin/sh
# The self-test of the checks: each case copies infra/ to a temporary tree, injects one error and runs the check of the
# copy, which must fail and say why. The unbroken copy must pass (a check that always fails proves nothing).
#
# Usage: selftest.sh        (make -C infra/k8s selftest)
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"

mkdir -p "$TOOLS_CACHE"
base="$(mktemp -d "$TOOLS_CACHE/selftest-XXXXXX")"
trap 'rm -rf "$base"' EXIT
failures=0

fresh_copy() {
  rm -rf "$base/tree"
  mkdir -p "$base/tree"
  # cp -R keeps the symlinks of charts/shop/files as they are; they stay relative and so point into the copy.
  cp -R "$TOOLS_REPO/infra" "$base/tree/infra"
  # The tests compare the browser image of the smoke test with the image of the E2E tests.
  mkdir -p "$base/tree/e2e"
  cp "$TOOLS_REPO/e2e/Dockerfile" "$base/tree/e2e/Dockerfile"
  # actionlint checks the workflows.
  mkdir -p "$base/tree/.github"
  cp -R "$TOOLS_REPO/.github/workflows" "$base/tree/.github/workflows"
  tree="$base/tree"
}

# replace <file of the copy> <from> <to>: literal replacement of the first occurrence; fails if the text is not there.
replace() {
  file="$tree/$1"
  grep -qF -- "$2" "$file" || { echo "selftest: '$2' is not in $1: the case is out of date" >&2; exit 1; }
  # ENVIRON (not -v): the text may contain newlines and backslashes.
  REPLACE_FROM="$2" REPLACE_TO="$3" awk 'BEGIN { done = 0; from = ENVIRON["REPLACE_FROM"]; to = ENVIRON["REPLACE_TO"] } {
    if (!done && (i = index($0, from)) > 0) { $0 = substr($0, 1, i - 1) to substr($0, i + length(from)); done = 1 }
    print }' "$file" > "$file.new"
  mv "$file.new" "$file"
}

# case <name> <expected text in the output> <command that injects the error>
run_case() {
  name="$1" expected="$2"
  shift 2
  fresh_copy
  "$@"
  if output="$(sh "$tree/infra/k8s/scripts/check.sh" 2>&1)"; then
    echo "selftest: FAIL $name: the check passed with the injected error" >&2
    failures=$((failures + 1))
  elif printf '%s' "$output" | grep -qF -- "$expected"; then
    echo "selftest: ok   $name (the check failed: $expected)"
  else
    echo "selftest: FAIL $name: the check failed, but not with '$expected':" >&2
    printf '%s\n' "$output" | tail -n 15 >&2
    failures=$((failures + 1))
  fi
}

broken_template() { printf '\n{{ if .Values.noindex }}\n' >> "$tree/infra/k8s/charts/shop/templates/mailpit.yaml"; }
secret_key_missing() { replace infra/scripts/init-env.sh "STORE_REVALIDATE_SECRET - 32'" "STORE_REVALIDATE_SECRET - 32
SELFTEST_NEW_SECRET - 16'"; }
secret_key_dropped_from_template() { grep -v 'VARNISH_PURGE_SECRET' "$tree/infra/k8s/charts/shop/templates/secret.yaml" > "$tree/s.new" && mv "$tree/s.new" "$tree/infra/k8s/charts/shop/templates/secret.yaml"; }
invalid_manifest() { replace infra/k8s/charts/shop/templates/_helpers.tpl "revisionHistoryLimit: 2" 'revisionHistoryLimit: "two"'; }
auth_exposes_master() { replace infra/k8s/charts/shop/templates/ingress.yaml '"/realms/backoffice/" "/resources/"' '"/realms/backoffice/" "/realms/master/" "/resources/"'; }
job_before_dependency() { replace infra/k8s/charts/shop/templates/jobs.yaml '(dict "root" . "name" "migrate" "wave" "0"' '(dict "root" . "name" "migrate" "wave" "-2"'; }
image_without_digest() { replace infra/k8s/charts/shop/values.yaml "  redis: redis:8.10.3@" "  redis: redis:8.10.3 # @"; }
kube_version_lowered() { replace infra/k8s/charts/shop/Chart.yaml 'kubeVersion: ">=1.36.0-0"' 'kubeVersion: ">=1.31.0-0"'; }
brevo_without_secret_allowed() { replace infra/k8s/charts/shop/values.schema.json '"const": "brevo"' '"const": "never"'; }
# shellcheck disable=SC2016 # the texts are lines of the scripts, with their variables
keycloak_config_prints_a_secret() { replace infra/k8s/charts/shop/files/scripts/keycloak-config.sh 'echo "keycloak-config: done"' 'echo "keycloak-config: done $STORE_CLIENT_SECRET"'; }
# shellcheck disable=SC2016 # the texts are lines of the scripts, with their variables
create_admin_without_confirmation() { replace infra/k8s/scripts/create-admin.sh 'if [ "$answer" != tak ]; then' 'if [ "$answer" = never ]; then'; }
deploy_needs_a_missing_job() { replace .github/workflows/deploy.yml 'needs: [images, smoke]' 'needs: [images, smok]'; }
# shellcheck disable=SC2016 # the texts are lines of the workflow, with their variables
deploy_script_unquoted() { replace .github/workflows/deploy.yml '-u "$GITHUB_ACTOR" --password-stdin' '-u $GITHUB_ACTOR --password-stdin'; }
deploy_without_the_smoke_test() { replace .github/workflows/deploy.yml 'needs: [images, smoke]' 'needs: [images]'; }
deploy_images_pushed_for_every_event() { replace .github/workflows/deploy.yml "    if: github.event_name == 'push'" "    if: github.event_name != 'schedule'"; }
# shellcheck disable=SC2016 # the texts are lines of the workflow, with their expressions
runs_for_main_cancelled() { replace .github/workflows/deploy.yml "cancel-in-progress: \${{ github.event_name != 'push' }}" 'cancel-in-progress: true'; }
deploy_workflow_missing() { rm "$tree/.github/workflows/deploy.yml"; }
nothing_broken() { :; }

echo "selftest: the unbroken copy passes"
fresh_copy
sh "$tree/infra/k8s/scripts/check.sh" >/dev/null 2>&1 || { echo "selftest: FAIL the unbroken copy does not pass the check" >&2; failures=$((failures + 1)); }

run_case "broken template" "unexpected EOF" broken_template
run_case "init-env.sh key missing from the secret template" "keys of infra/scripts/init-env.sh missing from the secret template" secret_key_missing
run_case "key dropped from the secret template" "VARNISH_PURGE_SECRET" secret_key_dropped_from_template
run_case "invalid manifest" "is invalid" invalid_manifest
run_case "auth ingress exposing the master realm" "test_auth_exposes_only_the_realms_of_the_shop_and_the_resources" auth_exposes_master
run_case "job in a wave before its dependency" "waits for postgres" job_before_dependency
run_case "third-party image without a digest" "/images/redis" image_without_digest
run_case "chart allowed on Kubernetes without stable image volumes" "Kubernetes 1.35: the render succeeded, it must fail" kube_version_lowered
run_case "orderMail brevo allowed without the Brevo secret" "orderMail brevo without the Brevo secret: the render succeeded" brevo_without_secret_allowed
run_case "keycloak-config printing a secret" "test_no_secret_is_in_the_arguments_or_in_the_output" keycloak_config_prints_a_secret
run_case "create-admin going on without tak" "test_without_the_word_tak_nothing_runs" create_admin_without_confirmation
run_case "deploy.yml needing a job that does not exist" "needs job \"smok\"" deploy_needs_a_missing_job
run_case "unquoted variable in a run script of deploy.yml (shellcheck)" "SC2086" deploy_script_unquoted
run_case "deploy without waiting for the smoke test" "test_it_needs_a_green_smoke_test_and_the_images" deploy_without_the_smoke_test
run_case "images pushed for every event" "test_images_are_pushed_and_deploy_moves_only_for_a_push_to_main" deploy_images_pushed_for_every_event
run_case "runs for main cancelled" "test_runs_for_main_are_never_cancelled_and_other_runs_are" runs_for_main_cancelled
run_case "deploy.yml missing" "deploy.yml is missing" deploy_workflow_missing

if [ "$failures" -ne 0 ]; then
  echo "selftest: $failures case(s) failed" >&2
  exit 1
fi
echo "selftest: ok"
