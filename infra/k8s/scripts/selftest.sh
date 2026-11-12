#!/bin/sh
# The self-test of the checks: each case copies infra/ to a temporary tree, injects one error and runs the check of the
# copy, which must fail and say why. The unbroken copy must pass (a check that always fails proves nothing).
#
# Usage: selftest.sh        (make -C infra/k8s selftest)
#        SELFTEST_ONLY='<regex>' selftest.sh   only the cases whose name matches the regular expression (grep -E, case
#                                              insensitive); the unbroken copy is then not run. For working on a case.
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
  # The step shellcheck reads the scripts of the load measurement (load/k6.sh and load/smoke.sh are sourced by the scripts of infra/k8s).
  cp -R "$TOOLS_REPO/load" "$base/tree/load"
  # The tests of the load files read the Makefile and its fragments.
  cp "$TOOLS_REPO/Makefile" "$base/tree/Makefile"
  cp -R "$TOOLS_REPO/make" "$base/tree/make"
  # actionlint checks the workflows.
  mkdir -p "$base/tree/.github"
  cp -R "$TOOLS_REPO/.github/workflows" "$base/tree/.github/workflows"
  # The deployment script is shellchecked and its push is tested.
  mkdir -p "$base/tree/.github/scripts"
  cp "$TOOLS_REPO/.github/scripts/deploy-ref.sh" "$base/tree/.github/scripts/deploy-ref.sh"
  # The step gitignore asks git about the rules of .gitignore, so the copy is a (fresh, empty) repository with the same rules.
  cp "$TOOLS_REPO/.gitignore" "$base/tree/.gitignore"
  git init -q "$base/tree"
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

# replace_after <file of the copy> <anchor> <from> <to>: like replace, for the first <from> on a line after the first line with
# <anchor> (a <from> that is on several lines of the file, e.g. "create: false").
replace_after() {
  file="$tree/$1"
  REPLACE_ANCHOR="$2" REPLACE_FROM="$3" REPLACE_TO="$4" awk 'BEGIN { seen = 0; done = 0; anchor = ENVIRON["REPLACE_ANCHOR"]
      from = ENVIRON["REPLACE_FROM"]; to = ENVIRON["REPLACE_TO"] } {
    if (seen && !done && (i = index($0, from)) > 0) { $0 = substr($0, 1, i - 1) to substr($0, i + length(from)); done = 1 }
    if (index($0, anchor) > 0) seen = 1
    print } END { if (!done) exit 3 }' "$file" > "$file.new" \
    || { echo "selftest: '$3' is not after '$2' in $1: the case is out of date" >&2; exit 1; }
  mv "$file.new" "$file"
}

# case <name> <expected text in the output> <command that injects the error>
run_case() {
  name="$1" expected="$2"
  shift 2
  if [ -n "${SELFTEST_ONLY:-}" ] && ! printf '%s' "$name" | grep -Eqi "$SELFTEST_ONLY"; then
    return 0
  fi
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
# bash -e {0} is what GitHub runs without `shell:`: no pipefail, so `make smoke | tee log` hides a red smoke test
deploy_without_pipefail() { replace .github/workflows/deploy.yml '    shell: bash' '    shell: bash -e {0}'; }
# shellcheck disable=SC2016 # the texts are lines of the scripts, with their variables
deploy_push_not_atomic() { replace .github/scripts/deploy-ref.sh 'git push --atomic "$remote"' 'git push "$remote"'; }
# shellcheck disable=SC2016 # the texts are lines of the workflow, with their variables
deploy_without_the_render_check() { replace .github/workflows/deploy.yml 'run: sh infra/k8s/scripts/verify-images.sh "$RUNNER_TEMP/values-images.yaml" "$GITHUB_SHA"' 'run: "true"'; }
# shellcheck disable=SC2016 # the texts are lines of the workflow, with their variables
smoke_values_for_every_event() { replace .github/workflows/deploy.yml 'if [ "$GITHUB_EVENT_NAME" = push ]; then' 'if true; then'; }
# shellcheck disable=SC2016 # the texts are lines of the scripts, with their variables
generator_accepts_short_digests() { replace infra/k8s/scripts/images-values.sh '[ "${#2}" -eq 71 ] || fail' 'true || fail'; }
package_cleanup_added() {
  cat > "$tree/.github/workflows/cleanup.yml" <<'YAML'
name: Cleanup
on:
  workflow_dispatch:
jobs:
  cleanup:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/delete-package-versions@e5bc658cc4c965c472efe991f8beea3981499c55 # v5.0.0
        with:
          package-name: shop/api
          package-type: container
          min-versions-to-keep: 3
YAML
}
argocd_shop_on_another_branch() { replace infra/k8s/argocd/apps/shop.yaml 'targetRevision: deploy-images' 'targetRevision: deploy'; }
argocd_shop_ignores_missing_images_file() { replace infra/k8s/argocd/apps/shop.yaml '      releaseName: shop' '      ignoreMissingValueFiles: true
      releaseName: shop'; }
# A (made-up) certificate of the controller in the repository: every key of init-env.sh must then be sealed.
add_certificate() { printf -- '-----BEGIN CERTIFICATE-----\nMIIDselftest\n-----END CERTIFICATE-----\n' > "$tree/infra/k8s/environments/prod/sealed-secrets.pem"; }
certificate_without_sealed_secrets() { add_certificate; }
# shellcheck disable=SC2016 # awk program
certificate_with_a_key_unsealed() {
  add_certificate
  {
    printf 'apiVersion: bitnami.com/v1alpha1\nkind: SealedSecret\nmetadata:\n  name: shop-secrets\n  namespace: shop\nspec:\n  encryptedData:\n'
    awk -v q="'" '/^(secrets|appendable)=/ { inblock = 1; sub(/^[a-z]+=/, "") }
      inblock { line = $0; sub("^" q, "", line); ended = (line ~ (q "$")); sub(q "$", "", line); if (line != "") { split(line, parts, " "); print parts[1] }; if (ended) inblock = 0 }' \
      "$tree/infra/scripts/init-env.sh" | grep -v '^PAYMENT_DEMO_CARD_API_KEY$' | sed 's/^\(.*\)$/    \1: AgBselftest/'
    printf '  template:\n    type: Opaque\n'
  } > "$tree/infra/k8s/environments/prod/secrets/shop-secrets.yaml"
}
plain_secret_committed() {
  printf 'apiVersion: v1\nkind: Secret\nmetadata:\n  name: shop-secrets\n  namespace: shop\nstringData:\n  POSTGRES_PASSWORD: plain\n' \
    > "$tree/infra/k8s/environments/prod/secrets/shop-secrets.yaml"
}
# shellcheck disable=SC2016 # the texts are lines of the scripts, with their variables
bootstrap_without_the_root_check() { replace infra/k8s/scripts/server-bootstrap.sh '[ "$(id -u)" -eq 0 ] || fail "must run as root' 'true || fail "must run as root'; }
# shellcheck disable=SC2016 # the texts are lines of the scripts, with their variables
bootstrap_sets_sysctl_every_time() { replace infra/k8s/scripts/server-bootstrap.sh 'if [ "$(sysctl -n vm.max_map_count)" != 262144 ]; then' 'if true; then'; }
# shellcheck disable=SC2016 # the texts are lines of the scripts, with their variables
reinitialize_without_confirmation() { replace infra/k8s/scripts/reinitialize.sh 'if [ "$answer" != tak ]; then' 'if [ "$answer" = never ]; then'; }
# shellcheck disable=SC2016 # the texts are lines of the scripts, with their variables
seal_secrets_replaces_existing_keys() { replace infra/k8s/scripts/seal-secrets.sh 'grep -q "^$key$tab" "$work/$name.pairs" && continue' 'false && continue'; }
# shellcheck disable=SC2016 # the texts are lines of the scripts, with their variables
smoke_pull_args_never_pull() { replace infra/k8s/scripts/tools.sh '--set image.pullPolicy=IfNotPresent' '--set image.pullPolicy=Never'; }
# shellcheck disable=SC2016 # the texts are lines of the scripts, with their variables
smoke_installs_without_pull_args() { replace infra/k8s/scripts/smoke.sh '-f /kube/values-images.yaml $(smoke_pull_args)' '-f /kube/values-images.yaml'; }
secrets_application_includes_everything() { replace infra/k8s/argocd/apps/secrets.yaml 'include: "*.yaml"' 'include: "*"'; }
secret_in_a_yml_file() {
  printf 'apiVersion: v1\nkind: Secret\nmetadata:\n  name: x\n  namespace: shop\nstringData:\n  A: plain\n' > "$tree/infra/k8s/environments/prod/secrets/x.yml"
}
sealed_secrets_key_rotates() { replace infra/k8s/argocd/apps/sealed-secrets.yaml 'keyrenewperiod: "0"' 'keyrenewperiod: "720h"'; }
argocd_without_application_health() { replace infra/k8s/argocd/install/argocd.yaml 'resource.customizations.health.argoproj.io_Application' 'resource.customizations.health.argoproj.io_Other'; }
# shellcheck disable=SC2016 # the texts are lines of the scripts
bootstrap_never_restarts_k3s() { replace infra/k8s/scripts/server-bootstrap.sh '    systemctl restart k3s' '    true'; }
# shellcheck disable=SC2016 # the texts are lines of the scripts
bootstrap_accepts_only_a_secret_backup() { replace infra/k8s/scripts/server-bootstrap.sh "grep -Eq '^[[:space:]-]*kind: Secret\$'" "grep -q '^kind: Secret'"; }
# shellcheck disable=SC2016 # the texts are lines of the scripts
bootstrap_applies_root_whatever_the_certificate() { replace infra/k8s/scripts/server-bootstrap.sh 'if [ -f "$payload/sealed-secrets.pem" ] && ! cmp' 'if false && ! cmp'; }
# The rule *.pem without the exception for the certificate: it could not be committed.
certificate_ignored_by_git() { replace .gitignore '!infra/k8s/environments/prod/sealed-secrets.pem' '# no exception'; }
# The backup (docs/features/deploy-ops/spec.md): a repository without its password, a CronJob that may run twice at once, a
# script that goes on after an error, an access error taken for a missing repository, the backup password asked for twice.
backup_repository_without_password() { replace infra/k8s/charts/shop/templates/_helpers.tpl '- name: RESTIC_PASSWORD' '- name: RESTIC_PASSWORD_UNUSED'; }
backup_cronjob_without_forbid() { replace infra/k8s/charts/shop/templates/backup.yaml 'concurrencyPolicy: Forbid' 'concurrencyPolicy: Allow'; }
backup_script_ignores_errors() { replace infra/k8s/charts/shop/files/scripts/backup.sh 'set -eu' 'set -u'; }
backup_inits_after_any_error() { replace infra/k8s/charts/shop/files/scripts/backup.sh '    10)' '    10 | 1)'; }
# The verification of the backups (spec O3): a script that goes on after an error, a script that takes a stale snapshot for good,
# a temporary Postgres that is not a sidecar (the Job would never complete), a verification that reads the production Postgres, restic without CA certificates (https).
backup_verify_script_ignores_errors() { replace infra/k8s/charts/shop/files/scripts/backup-verify.sh 'set -eu' 'set -u'; }
backup_verify_accepts_any_age() { replace infra/k8s/charts/shop/files/scripts/backup-verify.sh 'max_age_hours=26' 'max_age_hours=99999'; }
backup_verify_postgres_not_a_sidecar() { replace infra/k8s/charts/shop/templates/backup-verify.yaml 'restartPolicy: Always' 'imagePullPolicy: IfNotPresent'; }
backup_verify_without_the_ca_certificates() { replace infra/k8s/charts/shop/templates/backup-verify.yaml '- name: SSL_CERT_FILE' '- name: SSL_CERT_FILE_UNUSED'; }
backup_verify_reads_the_production_postgres() { replace infra/k8s/charts/shop/templates/backup-verify.yaml 'value: 127.0.0.1' 'value: postgres'; }
# The monitoring (spec O5, O7): the stage that masks e-mail addresses made to match nothing, the ClusterRole of Alloy with the Secrets
# of the cluster, the Role of the token with no name of a Secret, the events without the stages, a rule that no longer drops Mailpit;
# the operator with the RBAC of its chart (a ClusterRole with all Secrets, bind and escalate), the operator with a Role that changes
# RBAC objects, the operator watching the whole cluster, the Alloy resource making its own RBAC.
monitoring_without_the_email_rule() { replace infra/k8s/argocd/apps/monitoring.yaml ']+@[' ']+Q['; }
monitoring_alloy_rules_with_secrets() { replace infra/k8s/argocd/apps/monitoring.yaml 'resources: [nodes, services, endpoints, pods, namespaces, events]' 'resources: [nodes, services, endpoints, pods, namespaces, events, secrets]'; }
monitoring_operator_with_the_rbac_of_its_chart() { replace_after infra/k8s/argocd/apps/monitoring.yaml 'ownNamespaceOnly: true' 'create: false' 'create: true'; }
monitoring_operator_changing_rbac() { replace infra/k8s/argocd/apps/monitoring.yaml 'resources: [configmaps, secrets, serviceaccounts, services]' 'resources: [configmaps, secrets, serviceaccounts, services]
                verbs: [get, list, watch, create, update, patch, delete]
              - apiGroups: [rbac.authorization.k8s.io]
                resources: [roles, rolebindings]'; }
monitoring_operator_on_the_whole_cluster() { replace infra/k8s/argocd/apps/monitoring.yaml 'ownNamespaceOnly: true' 'ownNamespaceOnly: false'; }
monitoring_alloy_making_its_own_rbac() { replace_after infra/k8s/argocd/apps/monitoring.yaml 'presets: [deployment]' 'create: false' 'create: true'; }
monitoring_role_on_all_secrets_of_the_shop() { replace infra/k8s/argocd/apps/monitoring.yaml 'resourceNames: [grafana-cloud]' 'resourceNames: []'; }
monitoring_events_without_the_stages() { replace infra/k8s/argocd/apps/monitoring.yaml 'extraLogProcessingStages: *logStages' 'extraLogProcessingStages: ""'; }
monitoring_collecting_mailpit() { replace infra/k8s/argocd/apps/monitoring.yaml 'regex         = "shop;mailpit"' 'regex         = "shop;mailpit-other"'; }
# The render of the monitoring: a chart version that has no digest in monitoring-render.sh, an archive with another digest, stages
# that Alloy does not accept.
monitoring_chart_version_without_a_digest() { replace infra/k8s/argocd/apps/monitoring.yaml 'targetRevision: 4.6.0' 'targetRevision: 4.5.0'; }
monitoring_chart_digest_differs() { replace infra/k8s/scripts/monitoring-render.sh 'k8s-monitoring-4.6.0) echo 082ecdce' 'k8s-monitoring-4.6.0) echo 082ecdcf'; }
monitoring_stages_alloy_rejects() { replace infra/k8s/argocd/apps/monitoring.yaml 'drop_counter_reason = "postgres_detail_statement"' 'drop_counter_reason ='; }
seal_secrets_looks_for_the_backup_in_another_file() { replace infra/k8s/scripts/seal-secrets.sh '  name=shop-backup' '  name=shop-backup-other'; }
# shellcheck disable=SC2016 # the texts are lines of the scripts, with their variables
restore_without_confirmation() { replace infra/k8s/scripts/restore.sh '[ "$answer" = tak ] || refuse' '[ "$answer" = never ] && refuse'; }
deploy_workflow_missing() { rm "$tree/.github/workflows/deploy.yml"; }
nothing_broken() { :; }

if [ -z "${SELFTEST_ONLY:-}" ]; then
  echo "selftest: the unbroken copy passes"
  fresh_copy
  sh "$tree/infra/k8s/scripts/check.sh" >/dev/null 2>&1 || { echo "selftest: FAIL the unbroken copy does not pass the check" >&2; failures=$((failures + 1)); }
fi

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
run_case "deploy.yml steps without pipefail (smoke test piped to tee)" "test_every_run_step_has_pipefail" deploy_without_pipefail
run_case "deploy pushing the two branches without --atomic" "test_the_script_pushes_both_branches_in_one_atomic_push" deploy_push_not_atomic
run_case "deploy without the check of the render of prod" "test_the_render_of_prod_is_checked_before_anything_is_published" deploy_without_the_render_check
run_case "smoke test on the registry images for every event" "test_on_main_the_first_install_uses_the_values_file_and_the_registry" smoke_values_for_every_event
run_case "images values generator accepting a short digest" "test_a_missing_or_malformed_digest_fails_and_writes_nothing" generator_accepts_short_digests
run_case "a workflow that deletes package versions (retention)" "test_no_workflow_deletes_package_versions" package_cleanup_added
run_case "Argo CD shop application on another branch than the rest" "test_everything_from_git_tracks_the_same_branch_of_the_same_repository" argocd_shop_on_another_branch
run_case "Argo CD shop application ignoring a missing images file" "test_the_values_are_the_profile_and_the_images_file_in_this_order_and_nothing_else" argocd_shop_ignores_missing_images_file
run_case "certificate of Sealed Secrets ignored by git" "is ignored by git" certificate_ignored_by_git
run_case "certificate in the repository and no sealed secrets" "keys of infra/scripts/init-env.sh without a sealed value" certificate_without_sealed_secrets
run_case "certificate in the repository and one key unsealed" "PAYMENT_DEMO_CARD_API_KEY" certificate_with_a_key_unsealed
run_case "plain secret committed to environments/prod/secrets" "not a SealedSecret" plain_secret_committed
run_case "bootstrap without the check for root" "FAIL no root" bootstrap_without_the_root_check
run_case "bootstrap setting vm.max_map_count on every run" "FAIL sysctl again" bootstrap_sets_sysctl_every_time
run_case "reinitialize going on without tak" "test_without_the_word_tak_nothing_runs" reinitialize_without_confirmation
run_case "seal-secrets.sh sealing keys that are already there" "test_the_second_run_changes_nothing_and_does_not_call_kubeseal" seal_secrets_replaces_existing_keys
run_case "smoke pull arguments that never pull" "test_the_images_are_those_of_the_registry_by_digest_and_may_be_pulled" smoke_pull_args_never_pull
run_case "smoke installing without the pull arguments" "test_smoke_sh_uses_the_values_file_and_checks_the_digests_of_the_pods" smoke_installs_without_pull_args
run_case "secrets application including every file" "test_the_application_includes_only_yaml" secrets_application_includes_everything
run_case "secret in a yml file of the secrets directory" "test_the_directory_of_the_repository_has_only_yaml_files_and_the_two_documents" secret_in_a_yml_file
run_case "sealed secrets key that rotates" "test_the_key_of_the_controller_is_not_rotated" sealed_secrets_key_rotates
run_case "Argo CD without the health of applications" "test_the_root_waits_for_the_health_of_the_applications_of_the_waves" argocd_without_application_health
run_case "bootstrap never restarting k3s" "FAIL restart" bootstrap_never_restarts_k3s
run_case "bootstrap accepting only a plain Secret backup" "FAIL list backup" bootstrap_accepts_only_a_secret_backup
run_case "bootstrap applying root whatever the certificate" "FAIL other cert" bootstrap_applies_root_whatever_the_certificate
run_case "backup repository without its password" "test_the_repository_always_comes_with_its_password" backup_repository_without_password
run_case "backup CronJob without concurrencyPolicy Forbid" "test_it_runs_every_night_at_three_in_warsaw_and_never_twice_at_once" backup_cronjob_without_forbid
run_case "backup script that goes on after an error" "test_the_script_is_posix_sh_and_stops_at_the_first_error" backup_script_ignores_errors
run_case "backup initializing a repository after any error" "test_another_error_of_access_is_an_error_and_not_an_init" backup_inits_after_any_error
run_case "verification script that goes on after an error" "test_the_script_is_posix_sh_and_stops_at_the_first_error" backup_verify_script_ignores_errors
run_case "verification accepting a snapshot of any age" "test_a_snapshot_older_than_26_hours_is_an_error_that_says_so" backup_verify_accepts_any_age
run_case "verification with the temporary Postgres that is not a sidecar" "test_the_postgres_is_a_sidecar_that_the_end_of_the_verification_stops" backup_verify_postgres_not_a_sidecar
run_case "verify without the CA certificates of restic" "test_restic_trusts_the_certificates_of_its_own_image" backup_verify_without_the_ca_certificates
run_case "verification that reads the production Postgres" "test_nothing_of_the_production_database_is_reachable" backup_verify_reads_the_production_postgres
run_case "seal-secrets looking for the existing backup secret in another file (it asks for the password again)" "test_the_backup_is_created_once_then_nothing_is_asked" seal_secrets_looks_for_the_backup_in_another_file
run_case "monitoring masking no e-mail address" "e-mail 'jan.kowalski' came out of Alloy" monitoring_without_the_email_rule
run_case "Alloy with a ClusterRole that reads all Secrets" "with access to Secrets outside monitoring" monitoring_alloy_rules_with_secrets
run_case "Alloy Operator with the RBAC of its chart" "gives the operator rights outside the namespace monitoring" monitoring_operator_with_the_rbac_of_its_chart
run_case "Alloy Operator with a Role that changes RBAC objects" "can bind, escalate, impersonate or change RBAC objects" monitoring_operator_changing_rbac
run_case "Alloy Operator watching the whole cluster" "test_the_operator_watches_only_its_own_namespace" monitoring_operator_on_the_whole_cluster
run_case "Alloy resource making its own RBAC" "the Alloy resource makes its own RBAC" monitoring_alloy_making_its_own_rbac
run_case "Role of Alloy on all Secrets of the shop" "the Role does not name the one Secret" monitoring_role_on_all_secrets_of_the_shop
run_case "cluster events without the stages that mask" "do not get the same stages" monitoring_events_without_the_stages
run_case "monitoring collecting Mailpit" "test_the_rules_drop_exactly_the_disabled_sources_of_the_shop" monitoring_collecting_mailpit
run_case "monitoring chart version without a pinned digest" "has no sha256 in scripts/monitoring-render.sh" monitoring_chart_version_without_a_digest
run_case "monitoring chart archive with another digest" "of scripts/monitoring-render.sh" monitoring_chart_digest_differs
run_case "monitoring stages that Alloy rejects" "Alloy rejects the rendered configuration" monitoring_stages_alloy_rejects
run_case "restore going on without tak" "test_without_the_word_tak_nothing_changes" restore_without_confirmation
run_case "deploy.yml missing" "deploy.yml is missing" deploy_workflow_missing

if [ "$failures" -ne 0 ]; then
  echo "selftest: $failures case(s) failed" >&2
  exit 1
fi
echo "selftest: ok"
