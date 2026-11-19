#!/bin/sh
# The demo data of a profile, or the seed again, on the cluster of the server (docs/features/demo-scale/spec.md, "Cel make -C infra/k8s
# generate"): make -C infra/k8s generate PROFILE=<seed|profile> REPLACE=<data of the record> GENERATE_OUT=<directory>
# GENERATE_PROBE_URL='<url> ...' KUBE_DIR=<directory>. It REPLACES the data of the shop, so it runs only after the consent of the user
# in the conversation that names the operation, the host and the profile, and it asks for the word tak.
#
# Environment:
#   PROFILE               seed, or the profile of the CronJob demo-data of the deployed revision (demoData.profile; another one needs a
#                         change of the values and a deployment)
#   REPLACE               the data of the record demo-data-state (seed when there is no record, a profile, or failed)
#   GENERATE_OUT          an existing absolute directory outside the repository that can be written: the log of the container of the
#                         Job, its summary block, the sizes of the data and the log of the Job of the CronJob load go to
#                         GENERATE_OUT/<job>/ (demo-data.log, summary.txt, sizes.txt, load.log)
#   GENERATE_PROBE_URL    the public addresses of the shop through the ingress, separated by spaces (on the server
#                         'https://api.worf.pl/ https://worf.pl/'), asked from this computer
#   GENERATE_PROBE_HOST   the Host headers of those addresses, one per address in the same order (a test cluster whose address is the
#                         node); empty: none
#   KUBE_DIR              the directory with the kubeconfig `config` of the cluster (required; no default)
#   KUBE_NETWORK (host), KUBE_NAMESPACE (shop), ARGOCD_NAMESPACE (argocd)   as for make restore; the probe runs on KUBE_NETWORK too
#   DEMO_PHOTOS_ENV_FILE  the file of the user with the read access of the photo pool (~/.config/shop/demo-photos.env); only a profile
#                         whose CronJob reads a Secret of the photo pool needs it. Its four read variables become that Secret through
#                         the standard input of kubectl, for the time of the run: never in a command line, the output (also of sh -x)
#                         or GENERATE_OUT (the log of the generator is masked); the token that writes (DEMO_PHOTOS_WRITE_*) is skipped.
#   GENERATE_PROFILES_FILE  the profiles of the generator (apps/api/fixtures/demo-data/profiles.yaml): diskPeakGb
#   GENERATE_CLOSE_WAIT (120)  seconds for the probe to say closed after the rule and the restart of Traefik
#   GENERATE_OPEN_WAIT (300)   seconds for the probe to say open after the rule is deleted
#   GENERATE_ACTIVE_JOBS_WAIT (1800)  seconds to wait for Jobs that started after the checks of step 1
#   GENERATE_POLL (30)    seconds between two readings of a Job; GENERATE_PROBE_POLL (5) between two rounds of the probe
#
# The probe: every address is asked with curl (in a container, without following redirects); 502, 503 and 504 are "closed", a code
# below 500 is "open", any other code and no answer are a failed reading, which is tried again until the limit and never counted as
# closed or open. The shop is closed or open only when every address says so in one round (a page of the store from a cache while
# the API is closed is no closure).
#
# The record demo-data-state (a ConfigMap of the namespace, outside the chart, without labels or annotations of Argo CD): data (seed, a
# profile, failed), phase (idle, closing, running, checking, opening), target, job, anchor, revision (the tag of the image of the api),
# started, finished (UTC), reason (of a failure), products and orders (after a run that passed) and rootAutomated (the automatic
# synchronization of the application root before it was turned off, as JSON; written once, removed by the write after it is back).
# Every write is conditional: a replace with the resourceVersion this process read or wrote last (the first one a create); before
# every change in the cluster (the synchronization, the rule, Traefik, the Secret, a Job, the cache of the sitemap) the process reads
# the version again. Another version or a conflict is a second copy of the target: this one ends with 1 and changes nothing more.
#
# The run when the record says phase idle (no record: data seed, phase idle):
#   1. checks without any change: PROFILE is seed or the profile of the CronJob; REPLACE is the data of the record (the refusal names
#      the data and the estimated numbers of products and orders, pg_class.reltuples); the CronJob demo-data exists and is suspended
#      and has --min-free-gb; no Job of the namespace is active; with Argo CD (the CRD Application and the application shop) neither
#      root nor shop is synchronizing; every Deployment and StatefulSet of the namespace is ready; a profile other than seed whose
#      CronJob reads a Secret of the photo pool has the four read variables in DEMO_PHOTOS_ENV_FILE; the free space of every node (the
#      kubelet, /api/v1/nodes/<node>/proxy/stats/summary) can be read as a number and, for a profile other than seed, is at least
#      demoData.minFreeGb (--min-free-gb of the CronJob) and, for medium and server, also 1.25 x diskPeakGb of the profile (GB is 10^9
#      bytes); the seed has no floor (after a stop by the guard the space stays below minFreeGb, and the seed is the repair); the rule
#      and the probe agree with the record: with data failed the NetworkPolicy shop-closed exists and the probe says closed, otherwise
#      there is no rule and the probe says open;
#   2. it says what it will do and goes on only after `tak`;
#   3. the closing (nothing is replaced yet): the record closing with rootAutomated (an existing value stays) BEFORE the first change;
#      with Argo CD the automatic synchronization of root, then of shop, off; the Jobs that started since step 1 are waited for; with
#      Argo CD again: no synchronization of root or shop runs and neither has its automatic synchronization back; the NetworkPolicy
#      shop-closed (ingress to every pod of the namespace only from its pods) when it is not there; the pods of Traefik (kube-system)
#      deleted, which ends the connections that were open, and Traefik ready again; the probe says closed in GENERATE_CLOSE_WAIT.
#      The rollback, when one of these fails: the Secret of the photo pool deleted, the rule deleted unless the data is failed, the
#      synchronization of root back to rootAutomated (root brings back that of shop), and last the record idle with the data it had and
#      without rootAutomated; exit 1;
#   4. the generation: the record running with the name of the Job and data failed; for a profile whose CronJob reads it, the Secret
#      of the photo pool; the Job demo-data-<UTC time> from the CronJob with DEMO_DATA_CONFIRM=<PROFILE> (the seed too: its Job runs
#      bin/seed-demo). Then it only waits, until activeDeadlineSeconds of the Job and 600 s more, and prints the time of the run every
#      10 minutes: nothing that should stay after the run is gathered meanwhile (the log and the metrics go to Grafana Cloud), no SSH;
#   5. after a Job that completed: the record checking; the Secret deleted; the log of the container (as much as the kubelet has), the
#      last whole summary block of it and the sizes to GENERATE_OUT/<job>/ (a pod that is gone is noted, the run goes on); the pool
#      cache.sitemap cleared in every pod of the api (a failure stops the target in the phase checking, exit 1); the checks: no index
#      of the database shop invalid or not ready; the alias products of Elasticsearch on exactly one index that has documents, the
#      cluster not red, no index products_* without the alias; a Job load-<UTC time> of the CronJob load (load/smoke.js on the
#      services of the cluster) completes; the numbers of products and orders;
#   6. the opening: the record opening with data equal to the profile (it is checked); the rule deleted; the synchronization back to
#      rootAutomated; the probe says open in GENERATE_OPEN_WAIT; last the record idle with the numbers, the time of the end and without
#      rootAutomated; exit 0. When the probe does not say open (Cloudflare down), the phase stays opening and the exit is 1;
#   7. a failure in steps 4 and 5 (a Job that failed, also without its pod or by the guard of the free space, a Job that did not
#      finish in time, a failed check), in this order: the log saved; the Secret deleted; the rule there (made, with Traefik
#      restarted, when it is not); the synchronization back to rootAutomated; last the record idle with data failed, the reason (the
#      line of the termination log of the pod when there is one) and without rootAutomated. The shop is closed; the message names the
#      two ways out (PROFILE=<profile> REPLACE=failed, or PROFILE=seed REPLACE=failed). Exit 1.
#   In the steps that end a run (the rollback, 6 and 7) the write of the record is the last thing, and each step can be done again.
#   The target never deletes a Job.
#
# A record in another phase is a run that stopped (a signal, a lost connection, a failure of the cluster): this version of the target
# refuses it without any change. A signal ends the script without any cleanup: the Job runs on, the rule and the switched-off
# synchronization stay, and the record names the phase.
#
# Exit codes: 0 generated, checked, the shop open; 1 refused, not confirmed, rolled back, a conflict of the record or a failure (the
# message says whether the shop is closed); 2 wrong arguments or environment (PROFILE, REPLACE, GENERATE_OUT, GENERATE_PROBE_URL,
# GENERATE_PROBE_HOST, KUBE_DIR, a number setting, the file of the photo pool, diskPeakGb).
set -eu
# The addresses and the hosts are split into words, never expanded as patterns.
set -f

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=kube-jobs.sh
. "$here/kube-jobs.sh"
# The photo pool, the log of the generator and the sizes; their messages begin with generate.
# shellcheck source=demo-data-lib.sh
. "$here/demo-data-lib.sh"
DEMO_DATA_TOOL=generate

usage() {
  printf 'generate: %s\n' "$1" >&2
  echo "generate: usage: make -C infra/k8s generate PROFILE=<seed or the profile of the CronJob demo-data> REPLACE=<the data of the record demo-data-state> GENERATE_OUT=<directory outside the repository> GENERATE_PROBE_URL='<url> ...' [GENERATE_PROBE_HOST='<host> ...'] KUBE_DIR=<directory>" >&2
  echo "generate: nothing was changed" >&2
  exit 2
}
refuse() {
  printf 'generate: %s\n' "$*" >&2
  echo "generate: nothing was changed" >&2
  exit 1
}

# ---------------------------------------------------------------------------------------------------------------
# The arguments and the environment, before the cluster
# ---------------------------------------------------------------------------------------------------------------
profile="${PROFILE:-}"
printf '%s\n' "$profile" | grep -Eqx '[a-z][a-z0-9-]{0,30}' ||
  usage "PROFILE '$profile' is neither seed nor the name of a profile of the generator (small, medium, server, full)"
[ -n "${KUBE_DIR:-}" ] || usage "set KUBE_DIR to the directory with the kubeconfig (file config) of the cluster"
[ -f "$KUBE_DIR/config" ] || usage "no kubeconfig at $KUBE_DIR/config (set KUBE_DIR)"
KUBE_NETWORK="${KUBE_NETWORK:-host}"
namespace="${KUBE_NAMESPACE:-shop}"
argocd_namespace="${ARGOCD_NAMESPACE:-argocd}"

out_dir="${GENERATE_OUT:-}"
[ -n "$out_dir" ] || usage "set GENERATE_OUT=<directory> for the log, the summary and the sizes (an existing absolute path outside the repository)"
case "$out_dir" in
  /*) ;;
  *) usage "GENERATE_OUT '$out_dir' is not an absolute path" ;;
esac
[ -d "$out_dir" ] || usage "GENERATE_OUT '$out_dir' is not an existing directory"
out="$(cd "$out_dir" && pwd -P)"
repo_real="$(cd "$TOOLS_REPO" && pwd -P)"
case "$out/" in
  "$repo_real"/*) usage "GENERATE_OUT '$out_dir' is inside the repository; it must be a directory outside it (the results are not committed)" ;;
esac
out_check="$(mktemp "$out/.generate-write-check.XXXXXX" 2>/dev/null)" || usage "GENERATE_OUT '$out_dir' cannot be written"
rm -f "$out_check"

for setting in GENERATE_CLOSE_WAIT GENERATE_OPEN_WAIT GENERATE_ACTIVE_JOBS_WAIT GENERATE_POLL GENERATE_PROBE_POLL; do
  setting_value="$(printenv "$setting" || true)"
  case "$setting_value" in
    *[!0-9]*) usage "$setting must be a whole number of seconds, got '$setting_value'" ;;
  esac
done
close_wait="${GENERATE_CLOSE_WAIT:-120}"
open_wait="${GENERATE_OPEN_WAIT:-300}"
active_jobs_wait="${GENERATE_ACTIVE_JOBS_WAIT:-1800}"
poll="${GENERATE_POLL:-30}"
probe_poll="${GENERATE_PROBE_POLL:-5}"
# A Job is waited for until its activeDeadlineSeconds and this much more (Kubernetes fails it at the deadline; its pod has
# terminationGracePeriodSeconds to write the summary). Traefik has this long to be ready after its pods are deleted.
job_margin=600
traefik_wait=300
# The probe of step 1 (the shop as the record says) has this many seconds.
probe_check_wait=30

probe_urls="${GENERATE_PROBE_URL:-}"
probe_hosts="${GENERATE_PROBE_HOST:-}"
url_count=0
for probe_url in $probe_urls; do
  printf '%s\n' "$probe_url" | grep -Eqx 'https?://[A-Za-z0-9.:-]+(/[^[:space:]]*)?' ||
    usage "GENERATE_PROBE_URL: '$probe_url' is not an http or https address"
  url_count=$((url_count + 1))
done
[ "$url_count" -gt 0 ] ||
  usage "set GENERATE_PROBE_URL to the public addresses of the shop through the ingress, separated by spaces (on the server 'https://api.worf.pl/ https://worf.pl/')"
host_count=0
for probe_host in $probe_hosts; do
  printf '%s\n' "$probe_host" | grep -Eqx '[A-Za-z0-9.:-]+' || usage "GENERATE_PROBE_HOST: '$probe_host' is not a host name"
  host_count=$((host_count + 1))
done
[ "$host_count" -eq 0 ] || [ "$host_count" -eq "$url_count" ] ||
  usage "GENERATE_PROBE_HOST has $host_count host(s) for $url_count address(es) of GENERATE_PROBE_URL: one Host header per address, in the same order"

profiles_file="${GENERATE_PROFILES_FILE:-$TOOLS_REPO/apps/api/fixtures/demo-data/profiles.yaml}"
DEMO_PHOTOS_ENV_FILE="${DEMO_PHOTOS_ENV_FILE:-$HOME/.config/shop/demo-photos.env}"

# The answer is read from the standard input of the script; every other command reads nothing (docker run -i would take it).
exec 3<&0 < /dev/null

tab="$(printf '\t')"
record_name=demo-data-state
rule_name=shop-closed
# Ingress to every pod of the namespace only from the pods of the namespace: Traefik (kube-system) gets 502, the probes of the kubelet
# and the traffic between the pods go on (docs/features/demo-scale/spec.md, Z1). No labels: Argo CD does not track it (Z7).
rule_manifest='{"apiVersion":"networking.k8s.io/v1","kind":"NetworkPolicy","metadata":{"name":"shop-closed"},"spec":{"podSelector":{},"policyTypes":["Ingress"],"ingress":[{"from":[{"podSelector":{}}]}]}}'
traefik_namespace=kube-system
traefik_selector=app.kubernetes.io/name=traefik
work="$(mktemp -d)"
# What a failure that the script does not handle leaves (set after each write of the record), printed by the trap EXIT.
stopped_note=""

on_exit() {
  status=$?
  trap - EXIT
  rm -rf "$work"
  if [ "$status" -ne 0 ] && [ -n "$stopped_note" ]; then
    printf 'generate: stopped (exit %s) in the phase %s of the record %s: %s\n' "$status" "$cur_phase" "$record_name" "$stopped_note" >&2
    echo "generate: this version of the target does not resume a run that stopped (docs/features/demo-scale/spec.md, \"Przerwanie i wznowienie\")" >&2
  fi
  exit "$status"
}
trap on_exit EXIT
# A signal ends the script through the trap EXIT too (sh runs it only on a normal exit), without any cleanup.
trap 'exit 130' INT
trap 'exit 143' TERM

kc() { kubectl -n "$namespace" "$@"; }

# one_line <text>: the text as one line (for the reason in the record), at most 1000 characters.
one_line() { printf '%s' "$1" | tr '\t\r\n' '   ' | sed 's/  */ /g; s/^ //; s/ $//' | cut -c1-1000; }

# now_utc: the moment, as the record keeps it.
now_utc() { date -u +%Y-%m-%dT%H:%M:%SZ; }

# py_json <python expression of d, the JSON document on the standard input>: prints the value (a string).
py_json() {
  py -c "import json, sys
d = json.load(sys.stdin)
print($1)"
}

# gb <bytes>: the bytes as GB (10^9 bytes), two decimals.
gb() { awk -v bytes="$1" 'BEGIN { printf "%.2f", bytes / 1000000000 }'; }

# ---------------------------------------------------------------------------------------------------------------
# The record demo-data-state
# ---------------------------------------------------------------------------------------------------------------
# read_record: the fields of the record into rec_* and its resourceVersion into rec_version; no record is data seed, phase idle
# (rec_version empty). Each value is one line (whitespace runs become one space). Return 1 when it cannot be read.
read_record() {
  rec_version="" rec_data=seed rec_phase=idle rec_target="" rec_job="" rec_anchor="" rec_revision="" rec_started=""
  rec_finished="" rec_reason="" rec_products="" rec_orders="" rec_root_automated=""
  record_json="$(kc get configmap "$record_name" --ignore-not-found -o json)" || return 1
  [ -n "$record_json" ] || return 0
  record_fields="$(printf '%s' "$record_json" | py -c '
import json, sys
d = json.load(sys.stdin)
print("resourceVersion\t" + str(d.get("metadata", {}).get("resourceVersion", "")))
for key, value in (d.get("data") or {}).items():
    print(key + "\t" + " ".join(str(value).split()))
')" || return 1
  rec_data="" rec_phase=""
  while IFS="$tab" read -r record_key record_value; do
    case "$record_key" in
      resourceVersion) rec_version="$record_value" ;;
      data) rec_data="$record_value" ;;
      phase) rec_phase="$record_value" ;;
      target) rec_target="$record_value" ;;
      job) rec_job="$record_value" ;;
      anchor) rec_anchor="$record_value" ;;
      revision) rec_revision="$record_value" ;;
      started) rec_started="$record_value" ;;
      finished) rec_finished="$record_value" ;;
      reason) rec_reason="$record_value" ;;
      products) rec_products="$record_value" ;;
      orders) rec_orders="$record_value" ;;
      rootAutomated) rec_root_automated="$record_value" ;;
    esac
  done <<EOF
$record_fields
EOF
}

# write_record <phase>: the record from rec_* in the phase <phase> (an empty field is left out), conditional on own_version, the
# resourceVersion this process read or wrote last: a replace with it, or a create when there was no record. A conflict (another copy
# of the target wrote it meanwhile) or any other failure ends the script with 1, without any further change.
write_record() {
  rec_phase="$1"
  record_manifest="$(printf '%s\t%s\n' namespace "$namespace" resourceVersion "$own_version" data "$rec_data" phase "$rec_phase" \
    target "$rec_target" job "$rec_job" anchor "$rec_anchor" revision "$rec_revision" started "$rec_started" finished "$rec_finished" \
    reason "$rec_reason" products "$rec_products" orders "$rec_orders" rootAutomated "$rec_root_automated" | py -c '
import json, sys
fields = dict(line.rstrip("\n").split("\t", 1) for line in sys.stdin if "\t" in line)
metadata = {"name": "demo-data-state", "namespace": fields.pop("namespace")}
version = fields.pop("resourceVersion")
if version:
    metadata["resourceVersion"] = version
print(json.dumps({"apiVersion": "v1", "kind": "ConfigMap", "metadata": metadata, "data": {k: v for k, v in fields.items() if v != ""}}))
')"
  if [ -n "$own_version" ]; then record_verb=replace; else record_verb=create; fi
  if ! written="$(printf '%s' "$record_manifest" | kc "$record_verb" -f - -o 'jsonpath={.metadata.resourceVersion}' 2> "$work/record-error")"; then
    stopped_note=""
    printf 'generate: the record %s could not be written in the phase %s: %s\n' "$record_name" "$rec_phase" "$(one_line "$(cat "$work/record-error")")" >&2
    echo "generate: another copy of make generate wrote it since this one read it (or the cluster did not answer): this copy stops here without any further change" >&2
    exit 1
  fi
  case "$written" in
    "" | *[!0-9]*) stopped_note=""; echo "generate: the record $record_name was written in the phase $rec_phase, but its new version cannot be read ('$written'): this copy stops here" >&2; exit 1 ;;
  esac
  own_version="$written"
  cur_phase="$rec_phase"
}

# check_record <the change>: before every change in the cluster: the record still has the version of the last write of this process
# (or the one read at the start). Another version is a second copy of the target: exit 1 before the change.
check_record() {
  if ! check_version="$(kc get configmap "$record_name" --ignore-not-found -o 'jsonpath={.metadata.resourceVersion}')"; then
    printf 'generate: the record %s could not be read before %s: this copy stops without that change\n' "$record_name" "$1" >&2
    exit 1
  fi
  [ "$check_version" = "$own_version" ] && return 0
  stopped_note=""
  printf 'generate: the record %s has the version %s, not %s of the last write of this copy: another copy of make generate runs; this copy stops before %s, without any further change\n' \
    "$record_name" "${check_version:-(none)}" "${own_version:-(none)}" "$1" >&2
  exit 1
}

# ---------------------------------------------------------------------------------------------------------------
# What the checks read
# ---------------------------------------------------------------------------------------------------------------
# The estimated numbers of products and orders (pg_class.reltuples; -1 for a table never analyzed): est_text.
estimates_sql="SELECT (SELECT reltuples::bigint FROM pg_class WHERE oid = to_regclass('public.catalog_product')), (SELECT reltuples::bigint FROM pg_class WHERE oid = to_regclass('public.ordering_order'))"
read_estimates() {
  est_answer="$(kc exec statefulset/postgres -- psql -U shop -d shop -tA -c "$estimates_sql" 2>/dev/null | tr -d '\r' | head -n 1)" || est_answer=""
  est_products="${est_answer%%|*}" est_orders="${est_answer#*|}"
  case "$est_answer" in *"|"*) ;; *) est_products="" est_orders="" ;; esac
  case "$est_products" in "" | *[!0-9]*) est_products=unknown ;; esac
  case "$est_orders" in "" | *[!0-9]*) est_orders=unknown ;; esac
  est_text="$est_products products and $est_orders orders (estimated from pg_class.reltuples)"
}

# The CronJob demo-data: cron_suspend, cron_profile, cron_anchor, cron_min_free, cron_secret (the Secret of the photo pool it reads, or
# empty), cron_deadline, cron_revision (the tag of its image). Return 1 when it cannot be read; cron_found 0 when it is not there.
read_cronjob() {
  cron_found=0 cron_suspend="" cron_profile="" cron_anchor="" cron_min_free="" cron_secret="" cron_deadline="" cron_revision=""
  cron_json="$(kc get cronjob demo-data --ignore-not-found -o json)" || return 1
  [ -n "$cron_json" ] || return 0
  cron_fields="$(printf '%s' "$cron_json" | py -c '
import json, sys
d = json.load(sys.stdin)
spec = d.get("spec", {})
job = spec.get("jobTemplate", {}).get("spec", {})
containers = job.get("template", {}).get("spec", {}).get("containers") or [{}]
container = next((c for c in containers if c.get("name") == "demo-data"), containers[0])
command = container.get("command") or []
def argument(name):
    return next((a.split("=", 1)[1] for a in command if a.startswith(name + "=")), "")
secret = ""
for variable in container.get("env") or []:
    if variable.get("name") == "DEMO_PHOTOS_ENDPOINT":
        secret = ((variable.get("valueFrom") or {}).get("secretKeyRef") or {}).get("name", "")
reference = container.get("image", "").split("@")[0].rsplit("/", 1)[-1]
fields = (("suspend", "true" if spec.get("suspend") is True else "false"), ("profile", argument("--profile")), ("anchor", argument("--anchor")),
          ("minfree", argument("--min-free-gb")), ("secret", secret), ("deadline", job.get("activeDeadlineSeconds") or ""),
          ("revision", reference.rsplit(":", 1)[1] if ":" in reference else ""))
for key, value in fields:
    print(key + "\t" + " ".join(str(value).split()))
')" || return 1
  cron_found=1
  while IFS="$tab" read -r cron_key cron_value; do
    case "$cron_key" in
      suspend) cron_suspend="$cron_value" ;;
      profile) cron_profile="$cron_value" ;;
      anchor) cron_anchor="$cron_value" ;;
      minfree) cron_min_free="$cron_value" ;;
      secret) cron_secret="$cron_value" ;;
      deadline) cron_deadline="$cron_value" ;;
      revision) cron_revision="$cron_value" ;;
    esac
  done <<EOF
$cron_fields
EOF
}

# active_jobs: the names of the Jobs of the namespace that run (neither Complete nor Failed), separated by spaces.
active_jobs() {
  kc get jobs -o json | py_json '" ".join(j["metadata"]["name"] for j in d["items"]
    if not {c.get("type") for c in (j.get("status", {}).get("conditions") or []) if c.get("status") == "True"} & {"Complete", "Failed"})'
}

# app_syncing <application JSON>: yes when Argo CD runs an operation on it (a synchronization), no otherwise.
app_syncing() {
  printf '%s' "$1" | py_json '"yes" if d.get("operation") or (d.get("status", {}).get("operationState") or {}).get("phase") in ("Running", "Terminating") else "no"'
}
# app_automated <application JSON>: its automatic synchronization as JSON (null when it has none).
app_automated() {
  printf '%s' "$1" | py_json 'json.dumps((d.get("spec", {}).get("syncPolicy") or {}).get("automated"), sort_keys=True, separators=(",", ":"))'
}

# detect_argocd: argocd 1 with the CRD Application and the application shop (the server), 0 without (the smoke test on k3d); sets
# root_json and shop_json. Return 1 when the cluster cannot be asked.
detect_argocd() {
  argocd=0 root_json="" shop_json=""
  crd="$(kubectl get crd applications.argoproj.io --ignore-not-found -o name)" || return 1
  [ -n "$crd" ] || return 0
  shop_json="$(kubectl -n "$argocd_namespace" get application shop --ignore-not-found -o json)" || return 1
  [ -n "$shop_json" ] || return 0
  argocd=1
  root_json="$(kubectl -n "$argocd_namespace" get application root -o json)" || return 1
}

# not_ready <kind>: the Deployments or StatefulSets of the namespace that are not ready (fewer ready or updated replicas than they
# should have, or a generation not observed yet), as kind/name separated by spaces.
not_ready() {
  kc get "$1" -o json | py_json '" ".join("'"$1"'/" + w["metadata"]["name"] for w in d["items"]
    if (w.get("status", {}).get("readyReplicas") or 0) < w.get("spec", {}).get("replicas", 1)
    or (w.get("status", {}).get("updatedReplicas") or 0) < w.get("spec", {}).get("replicas", 1)
    or (w.get("status", {}).get("observedGeneration") or 0) < (w.get("metadata", {}).get("generation") or 0))'
}

# node_free <node>: the free bytes of the file system of the node (the kubelet: node.fs.availableBytes); fails when it cannot be read or
# is not a whole number.
node_free() {
  node_answer="$(kubectl get --raw "/api/v1/nodes/$1/proxy/stats/summary" | py -c '
import json, sys
value = json.load(sys.stdin).get("node", {}).get("fs", {}).get("availableBytes")
print(value if isinstance(value, int) and not isinstance(value, bool) and value >= 0 else "")
')" || return 1
  case "$node_answer" in "" | *[!0-9]*) return 1 ;; esac
  printf '%s' "$node_answer"
}

# ---------------------------------------------------------------------------------------------------------------
# The probe of the public addresses
# ---------------------------------------------------------------------------------------------------------------
# probe_round <closed|open>: asks every address once; 0 when every one said <state>. probe_report says what each one said.
probe_round() {
  probe_want="$1" probe_agree=1 probe_report="" probe_index=0
  for probe_url in $probe_urls; do
    probe_index=$((probe_index + 1))
    # shellcheck disable=SC2086 # the hosts, one word each
    probe_host="$(printf '%s\n' $probe_hosts | sed -n "${probe_index}p")"
    set -- -sS -o /dev/null -w '%{http_code}' --max-time 10
    [ -z "$probe_host" ] || set -- "$@" -H "Host: $probe_host"
    probe_code="$(docker run --rm --network "$KUBE_NETWORK" "$CURL_IMAGE" "$@" "$probe_url" 2>/dev/null)" || true
    case "$probe_code" in
      502 | 503 | 504) probe_seen=closed ;;
      [1-4][0-9][0-9]) probe_seen=open ;;
      *) probe_seen="not read" ;;
    esac
    [ "$probe_seen" = "$probe_want" ] || probe_agree=0
    probe_report="$probe_report${probe_report:+, }$probe_url${probe_host:+ (Host $probe_host)}: $probe_seen (${probe_code:-no answer})"
  done
  [ "$probe_agree" = 1 ]
}

# probe <closed|open> <seconds>: 0 once a round agrees on <state>; 1 when <seconds> passed first (probe_report: the last round). A
# failed reading is only tried again: it never counts as closed or open.
probe() {
  probe_until=$(($(date +%s) + $2))
  while :; do
    probe_round "$1" && return 0
    [ "$(date +%s)" -lt "$probe_until" ] || return 1
    sleep "$probe_poll"
  done
}

# ---------------------------------------------------------------------------------------------------------------
# 1. Checks, without changes
# ---------------------------------------------------------------------------------------------------------------
check_idle() {
  if [ -z "${REPLACE:-}" ]; then
    read_estimates
    usage "set REPLACE to the data of the record demo-data-state, which says data $rec_data ($est_text)"
  fi
  if [ "$REPLACE" != "$rec_data" ]; then
    read_estimates
    refuse "REPLACE=$REPLACE is not the data of the shop: the record demo-data-state says data $rec_data, $est_text"
  fi

  read_cronjob || refuse "cannot read the CronJob demo-data"
  [ "$cron_found" = 1 ] || refuse "there is no CronJob demo-data in the namespace $namespace (demoData.enabled of the deployed values)"
  [ "$cron_suspend" = true ] || refuse "the CronJob demo-data is not suspended (the chart has it suspended: is a run of it going on?)"
  if [ "$profile" != seed ] && [ "$profile" != "$cron_profile" ]; then
    refuse "PROFILE=$profile is neither seed nor the profile of the CronJob demo-data, ${cron_profile:-(none)} (demoData.profile of the deployed values); another profile needs a change of demoData.profile and a deployment"
  fi
  case "$cron_min_free" in
    "" | *[!0-9]*) refuse "the CronJob demo-data has no --min-free-gb=<GB> (demoData.minFreeGb) in its command" ;;
  esac

  active="$(active_jobs)" || refuse "cannot read the Jobs of the namespace $namespace"
  [ -z "$active" ] || refuse "Jobs are active in the namespace $namespace: $active (wait for them to finish)"

  detect_argocd || refuse "cannot read the applications of Argo CD"
  root_automated_now=""
  if [ "$argocd" = 1 ]; then
    [ "$(app_syncing "$root_json")" = no ] || refuse "Argo CD is synchronizing the application root (wait for it to finish)"
    [ "$(app_syncing "$shop_json")" = no ] || refuse "Argo CD is synchronizing the application shop (wait for it to finish)"
    root_automated_now="$(app_automated "$root_json")"
  fi

  unready="$(not_ready deployments)" || refuse "cannot read the Deployments of the namespace $namespace"
  unready_sets="$(not_ready statefulsets)" || refuse "cannot read the StatefulSets of the namespace $namespace"
  unready="$unready${unready:+${unready_sets:+ }}$unready_sets"
  [ -z "$unready" ] || refuse "not ready: $unready (the generator fails when Elasticsearch, Postgres, Keycloak, worker, Varnish or the store does not answer; wait until every workload is ready)"

  photos_needed=0
  if [ "$profile" != seed ] && [ -n "$cron_secret" ]; then
    photos_needed=1
    if ! read_photo_access "$DEMO_PHOTOS_ENV_FILE"; then
      printf 'generate: PROFILE=%s takes the photos from the private bucket of the photo pool (the CronJob reads the Secret %s), and %s\n' "$profile" "$cron_secret" "$demo_data_error" >&2
      echo "generate: nothing was changed" >&2
      exit 2
    fi
  fi

  check_free_space

  rule="$(kc get networkpolicy shop-closed --ignore-not-found -o name)" || refuse "cannot read the NetworkPolicy shop-closed"
  if [ "$rec_data" = failed ]; then
    [ -n "$rule" ] || refuse "the record says data failed, but there is no NetworkPolicy shop-closed: the shop may be open on incomplete data (go back to the seed: PROFILE=seed REPLACE=failed, after the rule is there again)"
    probe closed "$probe_check_wait" || refuse "the record says data failed and the rule shop-closed exists, but the probe does not say closed in $probe_check_wait s: $probe_report"
  else
    [ -z "$rule" ] || refuse "the NetworkPolicy shop-closed exists, but the record says data $rec_data: the shop should be open (is another make generate running?)"
    probe open "$probe_check_wait" || refuse "the probe does not say open in $probe_check_wait s: $probe_report"
  fi
}

# The free space of every node: a reading that is a number; for a profile, at least minFreeGb and, for medium and server, 1.25 x
# diskPeakGb (the larger of the two).
check_free_space() {
  nodes="$(kubectl get nodes -o json | py_json '" ".join(n["metadata"]["name"] for n in d["items"])')" || refuse "cannot read the nodes of the cluster"
  [ -n "$nodes" ] || refuse "the cluster has no node to read the free space of"
  required_bytes=0
  required_text="no floor (the seed)"
  if [ "$profile" != seed ]; then
    required_bytes="$(awk -v gb="$cron_min_free" 'BEGIN { printf "%.0f", gb * 1000000000 }')"
    required_text="$(awk -v gb="$cron_min_free" 'BEGIN { printf "%.2f", gb }') GB (demoData.minFreeGb)"
    case "$profile" in
      medium | server)
        if ! read_disk_peak "$profile" "$profiles_file"; then
          printf 'generate: %s\n' "$demo_data_error" >&2
          echo "generate: nothing was changed" >&2
          exit 2
        fi
        peak_bytes="$(awk -v kib="$required_kib" 'BEGIN { printf "%.0f", kib * 1024 }')"
        if awk -v a="$peak_bytes" -v b="$required_bytes" 'BEGIN { exit !(a > b) }'; then
          required_bytes="$peak_bytes"
          required_text="$required_gb GB (1.25 x diskPeakGb $disk_peak GB of the profile $profile)"
        fi ;;
    esac
  fi
  free_text=""
  for node in $nodes; do
    free="$(node_free "$node")" ||
      refuse "cannot read the free space of the node $node (the kubelet, /api/v1/nodes/$node/proxy/stats/summary, node.fs.availableBytes) as a number"
    if ! awk -v free="$free" -v need="$required_bytes" 'BEGIN { exit !(free >= need) }'; then
      refuse "the node $node has $(gb "$free") GB free; PROFILE=$profile needs at least $required_text"
    fi
    free_text="$free_text${free_text:+, }$node $(gb "$free") GB"
  done
}

# ---------------------------------------------------------------------------------------------------------------
# 2. What it will do, and the confirmation
# ---------------------------------------------------------------------------------------------------------------
confirm() {
  read_estimates
  server="$(kubectl config view --minify -o 'jsonpath={.clusters[0].cluster.server}' 2>/dev/null)" || server="(unknown)"
  echo "generate: in the namespace $namespace of the cluster $server ($KUBE_DIR/config):"
  echo "  - the data now: data $rec_data, $est_text;"
  if [ "$profile" = seed ]; then
    echo "  - replaced with the seed: a Job of the CronJob demo-data runs bin/seed-demo (minutes, no photo pool);"
  else
    echo "  - replaced with the profile $profile: a Job of the CronJob demo-data runs bin/generate-demo --profile=$profile${cron_anchor:+ --anchor=$cron_anchor} --force-reset (hours; at most ${cron_deadline:-?} s);"
    [ "$photos_needed" = 0 ] || echo "  - the Secret $cron_secret with the read access of the photo pool exists for the time of the run (from $DEMO_PHOTOS_ENV_FILE);"
  fi
  echo "  - the image of the api of the revision ${cron_revision:-(unknown)}; the free space: $free_text (needed: $required_text);"
  echo "  - the shop is closed from now until the check after the generation passed: every host (the store, api., admin., auth., img.)"
  echo "    answers 502 (the NetworkPolicy shop-closed, then Traefik restarted); a failed run leaves it closed, with data failed;"
  if [ "$argocd" = 1 ]; then
    echo "  - Argo CD: the automatic synchronization of root, then of shop, is turned off, and that of root on again at the end"
    echo "    (to ${rec_root_automated:-$root_automated_now});"
  fi
  echo "  - after the Job: the indexes of Postgres, the alias of the search, a Job of the CronJob load (load/smoke.js); then the shop opens."
  printf 'generate: type tak to go on: '
  answer=""
  IFS= read -r answer <&3 || answer=""
  echo
  [ "$answer" = tak ] || refuse "not confirmed (no 'tak')"
}

# ---------------------------------------------------------------------------------------------------------------
# The changes every step uses; each can be done again
# ---------------------------------------------------------------------------------------------------------------
# delete_photo_secret: the Secret of the photo pool that the CronJob reads, when it names one (also when it is not there).
delete_photo_secret() {
  [ -n "$cron_secret" ] || return 0
  check_record "deleting the Secret $cron_secret"
  kc delete secret "$cron_secret" --ignore-not-found > /dev/null
}

# restore_sync: with Argo CD, the automatic synchronization of root back to rootAutomated (root brings back that of shop).
restore_sync() {
  [ "$argocd" = 1 ] || return 0
  if [ -z "$rec_root_automated" ] || [ "$rec_root_automated" = null ]; then
    echo "generate: the application root had no automatic synchronization before the run: it stays off (and that of shop too)"
    return 0
  fi
  check_record "turning the automatic synchronization of root on again"
  kubectl -n "$argocd_namespace" patch application root --type merge -p "{\"spec\":{\"syncPolicy\":{\"automated\":$rec_root_automated}}}" > /dev/null
  echo "generate: the automatic synchronization of root is on again (root brings back that of shop)"
}

# traefik_ready: yes when Traefik has a pod that is not going away and every such pod is ready.
traefik_ready() {
  kubectl -n "$traefik_namespace" get pods -l "$traefik_selector" -o json | py_json '"yes" if [p for p in d["items"] if not p["metadata"].get("deletionTimestamp")] and all(
    any(c.get("type") == "Ready" and c.get("status") == "True" for c in (p.get("status", {}).get("conditions") or []))
    for p in d["items"] if not p["metadata"].get("deletionTimestamp")) else "no"'
}

# restart_traefik: deletes the pods of Traefik, which ends the connections opened before the rule (Z2), and waits until it is ready
# again; 1 when it is not in traefik_wait seconds.
restart_traefik() {
  check_record "restarting Traefik"
  kubectl -n "$traefik_namespace" delete pods -l "$traefik_selector" --wait=true > /dev/null
  traefik_until=$(($(date +%s) + traefik_wait))
  until [ "$(traefik_ready)" = yes ]; do
    [ "$(date +%s)" -lt "$traefik_until" ] || return 1
    sleep "$probe_poll"
  done
}

# make_rule: the NetworkPolicy shop-closed, when it is not there; rule_made 1 when it was made now.
make_rule() {
  rule_made=0
  rule="$(kc get networkpolicy "$rule_name" --ignore-not-found -o name)" || return 1
  [ -z "$rule" ] || return 0
  check_record "making the NetworkPolicy $rule_name"
  printf '%s' "$rule_manifest" | kc create -f - > /dev/null || return 1
  rule_made=1
}

# ---------------------------------------------------------------------------------------------------------------
# 3. The closing, and its rollback
# ---------------------------------------------------------------------------------------------------------------
close_shop() {
  # The value of before the first switch-off: an existing one stays (a run that stopped before it came back).
  [ -n "$rec_root_automated" ] || [ "$argocd" = 0 ] || rec_root_automated="$root_automated_now"
  rec_target="$profile" rec_started="$(now_utc)" rec_finished=""
  write_record closing
  stopped_note="nothing was replaced yet; the rule shop-closed and the switched-off synchronization of Argo CD may be left (the shop may be closed)"
  if [ "$argocd" = 1 ]; then
    # root first: with selfHeal it would bring back the synchronization of shop from git.
    for app in root shop; do
      check_record "turning off the automatic synchronization of $app"
      kubectl -n "$argocd_namespace" patch application "$app" --type merge -p '{"spec":{"syncPolicy":{"automated":null}}}' > /dev/null
    done
    echo "generate: the automatic synchronization of root and shop is off"
  fi
  # A Job that started since the checks (a CronJob of the chart) works on the same data: wait for it.
  jobs_until=$(($(date +%s) + active_jobs_wait))
  while :; do
    active="$(active_jobs)" || undo_closing "the Jobs of the namespace $namespace cannot be read"
    [ -n "$active" ] || break
    [ "$(date +%s)" -lt "$jobs_until" ] || undo_closing "Jobs are still active in the namespace $namespace after $active_jobs_wait s: $active"
    echo "generate: waiting for the Jobs $active (they started after the checks)"
    sleep "$poll"
  done
  if [ "$argocd" = 1 ]; then
    sync_problem="$(sync_problem)"
    [ -z "$sync_problem" ] || undo_closing "$sync_problem"
  fi
  make_rule || undo_closing "the NetworkPolicy $rule_name could not be made"
  restart_traefik || undo_closing "Traefik is not ready $traefik_wait s after its pods were deleted"
  echo "generate: the rule $rule_name is there and Traefik was restarted; the probe (at most $close_wait s)"
  probe closed "$close_wait" || undo_closing "the probe does not say closed in $close_wait s: $probe_report"
  echo "generate: the shop is closed: $probe_report"
}

# sync_problem: after the switch-off, why the run cannot go on (a synchronization of root or shop runs, or one of them has its
# automatic synchronization back), or nothing.
sync_problem() {
  detect_argocd || { echo "the applications of Argo CD cannot be read"; return 0; }
  [ "$argocd" = 1 ] || { echo "the application shop of Argo CD is gone"; return 0; }
  for app in root shop; do
    if [ "$app" = root ]; then app_json="$root_json"; else app_json="$shop_json"; fi
    if [ "$(app_syncing "$app_json")" != no ]; then
      echo "Argo CD started a synchronization of the application $app after the automatic synchronization was turned off"
      return 0
    fi
    if [ "$(app_automated "$app_json")" != null ]; then
      echo "the application $app has its automatic synchronization again (it was turned off; does something else synchronize it?)"
      return 0
    fi
  done
}

# undo_closing <reason>: the rollback of the closing (nothing was replaced); the record idle with the data it had, last. Exit 1.
undo_closing() {
  echo "generate: rolling back the closing: $1" >&2
  delete_photo_secret
  if [ "$rec_data" != failed ]; then
    check_record "deleting the NetworkPolicy $rule_name"
    kc delete networkpolicy "$rule_name" --ignore-not-found > /dev/null
  fi
  restore_sync
  rec_root_automated="" rec_finished="$(now_utc)"
  write_record idle
  stopped_note=""
  if [ "$rec_data" = failed ]; then
    echo "generate: rolled back, nothing was replaced: the data is still failed and the shop stays CLOSED (the rule $rule_name); try again, or go back to the seed with PROFILE=seed REPLACE=failed" >&2
  else
    echo "generate: rolled back, nothing was replaced: the data is still $rec_data and the shop is open again" >&2
  fi
  exit 1
}

# ---------------------------------------------------------------------------------------------------------------
# 4. The generation
# ---------------------------------------------------------------------------------------------------------------
start_generator() {
  rec_job="demo-data-$stamp" rec_data=failed rec_reason="" rec_products="" rec_orders="" rec_revision="$cron_revision"
  if [ "$profile" = seed ]; then rec_anchor=""; else rec_anchor="$cron_anchor"; fi
  write_record running
  stopped_note="the data is failed (being replaced) and the shop is closed; the Job $rec_job may run on"
  if [ "$photos_needed" = 1 ]; then
    delete_photo_secret
    check_record "making the Secret $cron_secret"
    create_photo_secret "$cron_secret" || fail_run "$demo_data_error"
  fi
  check_record "making the Job $rec_job"
  cronjob_job demo-data "$rec_job" "DEMO_DATA_CONFIRM=$profile" || fail_run "the Job $rec_job could not be created from the CronJob demo-data"
  echo "generate: the Job $rec_job runs (DEMO_DATA_CONFIRM=$profile); its log and the metrics go to Grafana Cloud, this target only waits"
}

# await_job <job> <report>: waits for the Job until its activeDeadlineSeconds and job_margin more, reading only its state; with
# <report> 1 it prints the time of the run every 10 minutes. 0 when it completed; 1 when it failed, is gone or did not finish in time
# (job_reason).
await_job() {
  job_name="$1"
  job_started="$(date +%s)"
  job_limit="$(kc get job "$job_name" -o 'jsonpath={.spec.activeDeadlineSeconds}' 2>/dev/null)" || job_limit=""
  case "$job_limit" in "" | *[!0-9]*) job_limit=0 ;; esac
  [ "$job_limit" -gt 0 ] || job_limit=86400
  job_until=$((job_started + job_limit + job_margin))
  job_reported="$job_started"
  while :; do
    job_now="$(date +%s)"
    if job_types="$(kc get job "$job_name" -o 'jsonpath={.status.conditions[?(@.status=="True")].type}' 2> "$work/job-error")"; then
      case " $job_types " in
        *" Complete "*) return 0 ;;
        *" Failed "*) job_reason="$(job_failure "$job_name")"; return 1 ;;
      esac
    elif grep -q NotFound "$work/job-error"; then
      job_reason="the Job $job_name is gone (it was deleted)"
      return 1
    fi
    if [ "$job_now" -ge "$job_until" ]; then
      job_reason="the Job $job_name did not finish in its activeDeadlineSeconds ($job_limit s) and $job_margin s more"
      return 1
    fi
    if [ "$2" = 1 ] && [ $((job_now - job_reported)) -ge 600 ]; then
      job_reported="$job_now"
      job_elapsed=$((job_now - job_started))
      echo "generate: the Job $job_name is running for $((job_elapsed / 3600)) h $((job_elapsed % 3600 / 60)) min (its limit: $((job_limit / 3600)) h $((job_limit % 3600 / 60)) min)"
    fi
    sleep "$poll"
  done
}

# job_failure <job>: why a failed Job failed: the line of the termination log of its container, else the condition of Kubernetes;
# a Job without a pod says so.
job_failure() {
  failure_pods="$(kc get pods -l "batch.kubernetes.io/job-name=$1" -o json 2>/dev/null)" || failure_pods=""
  failure_message="$(printf '%s' "$failure_pods" | py -c '
import json, sys
try:
    items = json.load(sys.stdin).get("items") or []
except ValueError:
    items = None
if items == []:
    print("NO POD")
else:
    for pod in items or []:
        for status in pod.get("status", {}).get("containerStatuses") or []:
            message = ((status.get("state") or {}).get("terminated") or {}).get("message") or ""
            if message.strip():
                print(" ".join(message.split()))
                sys.exit(0)
')" || failure_message=""
  if [ "$failure_message" = "NO POD" ]; then
    echo "the pod of the Job does not exist (the Job $1; the pod was deleted, evicted, or gone with a restart of the node): its log is only in Grafana Cloud"
  elif [ -n "$failure_message" ]; then
    printf '%s\n' "$failure_message"
  else
    failure_condition="$(kc get job "$1" -o json 2>/dev/null | py_json '" ".join("%s: %s" % (c.get("reason", ""), c.get("message", "")) for c in (d.get("status", {}).get("conditions") or []) if c.get("type") == "Failed")' 2>/dev/null)" || failure_condition=""
    echo "the Job $1 failed${failure_condition:+ ($failure_condition)}"
  fi
}

# ---------------------------------------------------------------------------------------------------------------
# 5. After the Job: the results and the checks
# ---------------------------------------------------------------------------------------------------------------
check_generated() {
  write_record checking
  stopped_note="the data is generated but not checked (failed) and the shop is closed"
  after_generator
}

# after_generator: step 5 from the deletion of the Secret on (the checks can be done again), then step 6 or 7.
after_generator() {
  delete_photo_secret
  save_results
  clear_sitemap
  run_checks || fail_run "$check_reason"
  open_shop
}

# save_results: the log of the container of the Job, its last whole summary block and the sizes of the data, in GENERATE_OUT/<job>/
# (best effort: what cannot be read or written is said and the run goes on).
save_results() {
  [ "${results_saved:-0}" = 0 ] || return 0
  results_saved=1
  results="$out/$rec_job"
  mkdir -p "$results" 2>/dev/null || { echo "generate: $results cannot be made: the log, the summary and the sizes are not kept" >&2; return 0; }
  pod_count="$(kc get pods -l "batch.kubernetes.io/job-name=$rec_job" -o json 2>/dev/null | py_json 'len(d.get("items") or [])' 2>/dev/null)" || pod_count=""
  if [ "$pod_count" = 0 ]; then
    echo "generate: the pod of the Job $rec_job is gone: its log and its summary are not in the cluster any more (the whole log is in Grafana Cloud)"
  else
    save_generator_log "$rec_job" "$results/demo-data.log" || echo "generate: the log of the Job could not be written to $results/demo-data.log" >&2
    if summary_block "$results/demo-data.log" > "$results/summary.txt" 2>/dev/null; then
      echo "generate: the summary of the Job $rec_job ($results/summary.txt):"
      sed 's/^/  /' "$results/summary.txt"
    else
      echo "generate: the log of the Job $rec_job has no whole summary block (its start may have rotated away; the whole log is in Grafana Cloud)"
    fi
  fi
  { data_sizes > "$results/sizes.txt"; } 2>/dev/null || echo "generate: the sizes could not be written to $results/sizes.txt" >&2
  echo "generate: the log, the summary and the sizes are in $results"
}

# summary_block <log>: the last whole block of the summary of demo-data.sh in a log of kubectl logs --timestamps, without the times;
# 1 when there is none.
summary_block() {
  awk '
    { line = $0; if (line ~ /^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[^ ]* /) sub(/^[^ ]* /, "", line) }
    line == "==== demo-data summary" { inside = 1; block = line; next }
    inside { block = block "\n" line; if (line == "==== end of the demo-data summary") { last = block; inside = 0 } }
    END { if (last == "") exit 1; print last }' "$1"
}

# clear_sitemap: the pool cache.sitemap (a file, one per pod) in every pod of the api, so that the checks read the new catalog. A
# failure stops the target in the phase checking: running it again repeats the step.
clear_sitemap() {
  api_pods="$(kc get pods -l app.kubernetes.io/name=api -o json | py_json '" ".join(p["metadata"]["name"] for p in d["items"]
    if p.get("status", {}).get("phase") == "Running" and not p["metadata"].get("deletionTimestamp"))')" || stop_checking "the pods of the api cannot be read"
  [ -n "$api_pods" ] || stop_checking "no pod of the api runs"
  for api_pod in $api_pods; do
    check_record "clearing the pool cache.sitemap of the pod $api_pod"
    kc exec "$api_pod" -- bin/console cache:pool:clear cache.sitemap > /dev/null 2>&1 || stop_checking "the pool cache.sitemap of the pod $api_pod could not be cleared"
  done
  echo "generate: the pool cache.sitemap is cleared in $api_pods"
}

stop_checking() {
  stopped_note=""
  echo "generate: $1" >&2
  echo "generate: the shop stays CLOSED (the rule $rule_name) and the record stays in the phase checking (data failed); the checks are not done" >&2
  exit 1
}

invalid_sql="SELECT c.relname FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE NOT i.indisvalid OR NOT i.indisready ORDER BY 1"
counts_sql="SELECT (SELECT count(*) FROM catalog_product), (SELECT count(*) FROM ordering_order)"

es_get() { kc exec statefulset/elasticsearch -- curl -fsS "http://localhost:9200/$1"; }

# run_checks: the checks after the generation (docs/features/demo-scale/spec.md, "Sprawdzenie po generowaniu"); 1 with check_reason at
# the first that fails. The data itself was checked by the Job (demo-data:verify, or bin/seed-demo).
run_checks() {
  if ! invalid="$(kc exec statefulset/postgres -- psql -U shop -d shop -v ON_ERROR_STOP=1 -tA -c "$invalid_sql" 2> "$work/psql-error")"; then
    check_reason="the indexes of Postgres could not be read: $(one_line "$(cat "$work/psql-error")")"
    return 1
  fi
  invalid="$(printf '%s\n' "$invalid" | tr -d '\r' | sed '/^$/d' | tr '\n' ' ' | sed 's/ $//')"
  [ -z "$invalid" ] || { check_reason="indexes of the database shop are not valid or not ready: $invalid"; return 1; }
  echo "generate: ok: every index of the database shop is valid and ready"

  health="$(es_get '_cluster/health' 2>/dev/null | py_json 'd.get("status", "")' 2>/dev/null)" || health=""
  [ -n "$health" ] || { check_reason="the state of the cluster of Elasticsearch could not be read"; return 1; }
  [ "$health" != red ] || { check_reason="the cluster of Elasticsearch is red"; return 1; }
  aliased="$(es_get '_cat/aliases/products?format=json&h=alias,index' 2>/dev/null | py_json '" ".join(sorted(a["index"] for a in d if a.get("alias") == "products"))' 2>/dev/null)" ||
    { check_reason="the alias products of Elasticsearch could not be read"; return 1; }
  # shellcheck disable=SC2086 # the names of the indexes, one word each
  set -- $aliased
  [ "$#" -eq 1 ] || { check_reason="the alias products of Elasticsearch points to $# indexes (${aliased:-none}), not to one"; return 1; }
  indices="$(es_get '_cat/indices/products_*?format=json&h=index,docs.count&expand_wildcards=all' 2>/dev/null | py_json '"\n".join(i["index"] + " " + str(i.get("docs.count") or 0) for i in d)' 2>/dev/null)" ||
    { check_reason="the indexes of Elasticsearch could not be read"; return 1; }
  documents="$(printf '%s\n' "$indices" | awk -v index_name="$1" '$1 == index_name { print $2 }')"
  case "$documents" in "" | *[!0-9]* | 0) check_reason="the index $1 of the alias products has no documents (${documents:-not listed})"; return 1 ;; esac
  orphans="$(printf '%s\n' "$indices" | awk -v index_name="$1" 'NF && $1 != index_name { printf "%s%s", sep, $1; sep = " " }')"
  [ -z "$orphans" ] || { check_reason="indexes without the alias products are left in Elasticsearch: $orphans"; return 1; }
  echo "generate: ok: Elasticsearch is $health, the alias products is on $1 ($documents documents), no index without the alias"

  load_job="load-$stamp"
  check_record "making the Job $load_job of the CronJob load"
  cronjob_job load "$load_job" || { check_reason="the Job $load_job could not be created from the CronJob load"; return 1; }
  echo "generate: the paths of the store (load/smoke.js, Job $load_job)"
  if ! await_job "$load_job" 0; then
    kc logs "job/$load_job" --container=load > "$results/load.log" 2>&1 || true
    tail -n 40 "$results/load.log" >&2 2>/dev/null || true
    check_reason="the Job $load_job of the CronJob load (load/smoke.js on the services of the cluster) failed: $job_reason"
    return 1
  fi
  kc logs "job/$load_job" --container=load > "$results/load.log" 2>&1 || true
  echo "generate: ok: the Job $load_job of the CronJob load passed"

  counts="$(kc exec statefulset/postgres -- psql -U shop -d shop -tA -c "$counts_sql" 2>/dev/null | tr -d '\r' | head -n 1)" || counts=""
  products="${counts%%|*}" orders="${counts#*|}"
  case "$counts" in *"|"*) ;; *) products="" ;; esac
  case "$products:$orders" in
    :* | *: | *[!0-9:]*) check_reason="the numbers of products and orders could not be read ('$counts')"; return 1 ;;
  esac
  rec_products="$products" rec_orders="$orders"
  echo "generate: ok: $products products and $orders orders"
}

# ---------------------------------------------------------------------------------------------------------------
# 6. The opening
# ---------------------------------------------------------------------------------------------------------------
open_shop() {
  rec_data="$profile"
  write_record opening
  stopped_note="the data $rec_data is generated and checked; the shop may still be closed"
  finish_opening
}

# finish_opening: step 6 from the deletion of the rule on.
finish_opening() {
  check_record "deleting the NetworkPolicy $rule_name"
  kc delete networkpolicy "$rule_name" --ignore-not-found > /dev/null
  restore_sync
  if ! probe open "$open_wait"; then
    stopped_note=""
    echo "generate: the probe does not say open in $open_wait s: $probe_report" >&2
    echo "generate: the data $rec_data is generated and checked and the rule $rule_name is deleted, but the shop does not answer as open; the record stays in the phase opening" >&2
    exit 1
  fi
  rec_root_automated="" rec_reason="" rec_finished="$(now_utc)"
  write_record idle
  stopped_note=""
  echo "generate: done: data $rec_data ($rec_products products, $rec_orders orders), the shop is open: $probe_report"
}

# ---------------------------------------------------------------------------------------------------------------
# 7. A failure after the record running
# ---------------------------------------------------------------------------------------------------------------
# fail_run <reason>: the log saved, the Secret deleted, the rule there (made, with Traefik restarted, when it is not), the
# synchronization back, and last the record idle with data failed and the reason. Exit 1; the shop is closed.
fail_run() {
  rec_reason="$(one_line "$1")"
  echo "generate: FAILED: $rec_reason" >&2
  save_results
  delete_photo_secret
  make_rule
  if [ "$rule_made" = 1 ]; then
    restart_traefik || echo "generate: Traefik is not ready $traefik_wait s after its pods were deleted (the rule $rule_name is there)" >&2
  fi
  restore_sync
  rec_data=failed rec_root_automated="" rec_finished="$(now_utc)"
  write_record idle
  stopped_note=""
  {
    echo "generate: the shop is CLOSED (the rule $rule_name) and the data is failed: $rec_reason"
    echo "generate: the log, the summary and the sizes are in $out/$rec_job (the whole log of the Job is in Grafana Cloud)"
    echo "generate: two ways out: try again with make -C infra/k8s generate PROFILE=$profile REPLACE=failed (a stop by the guard of the free space ends the same way: another profile or more room), or go back to the seed with make -C infra/k8s generate PROFILE=seed REPLACE=failed"
  } >&2
  exit 1
}

# ---------------------------------------------------------------------------------------------------------------
# The run
# ---------------------------------------------------------------------------------------------------------------
read_record || refuse "cannot read the record $record_name (ConfigMap of the namespace $namespace)"
own_version="$rec_version"
cur_phase="$rec_phase"
printf '%s\n' "$rec_data" | grep -Eqx '[a-z][a-z0-9-]{0,30}' ||
  refuse "the record $record_name says data '$rec_data', which is neither seed, failed nor a profile: it was not written by make generate"
if [ "$rec_phase" != idle ]; then
  refuse "the record $record_name says phase $rec_phase (target ${rec_target:-?}): an earlier run of make generate stopped there; this version of the target does not resume it yet (docs/features/demo-scale/spec.md, \"Przerwanie i wznowienie\")"
fi
check_idle
confirm
stamp="$(date -u +%Y%m%d%H%M%S)"
close_shop
start_generator
if await_job "$rec_job" 1; then
  echo "generate: the Job $rec_job completed"
  check_generated
else
  fail_run "$job_reason"
fi
