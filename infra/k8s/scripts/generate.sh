#!/bin/sh
# The demo data of a profile, or the seed again, on the cluster of the server (docs/features/demo-scale/spec.md, "Cel make -C infra/k8s
# generate"): make -C infra/k8s generate PROFILE=<seed|profile> REPLACE=<data of the record> GENERATE_OUT=<directory>
# GENERATE_PROBE_URL='<url> ...' KUBE_DIR=<directory>. It REPLACES the data of the shop, so it runs only after the consent of the user
# in the conversation, and asks for the word tak.
#
# Environment:
#   PROFILE               seed, or the profile of the CronJob demo-data of the deployed revision (demoData.profile; another one needs a
#                         change of the values and a deployment)
#   REPLACE               the data of the record demo-data-state (seed when there is no record, a profile, or failed)
#   GENERATE_OUT          an existing absolute directory outside the repository that can be written: the log of the container of the
#                         Job, its summary and the sizes of the data go to GENERATE_OUT/<job>/ (demo-data.log, summary.txt, sizes.txt)
#   GENERATE_PROBE_URL    the public addresses of the shop through the ingress, separated by spaces (on the server
#                         'https://api.worf.pl/ https://worf.pl/'), asked from this computer
#   GENERATE_PROBE_HOST   the Host headers of those addresses, one per address in the same order (a test cluster whose address is the
#                         node); empty: none
#   KUBE_DIR              the directory with the kubeconfig `config` of the cluster (required; no default)
#   KUBE_NETWORK (host), KUBE_NAMESPACE (shop), ARGOCD_NAMESPACE (argocd)   as for make restore; the probe runs on KUBE_NETWORK too
#   DEMO_PHOTOS_ENV_FILE  the file of the user with the read access of the photo pool (~/.config/shop/demo-photos.env); only a profile
#                         whose CronJob reads a Secret of the photo pool needs it
#   GENERATE_PROFILES_FILE  the profiles of the generator (apps/api/fixtures/demo-data/profiles.yaml): diskPeakGb
#   GENERATE_PROBE_POLL (5)  seconds between two rounds of the probe
#
# The probe: every address is asked with curl (in a container); 502, 503 and 504 are "closed", a code below 500 is "open", any other
# code and no answer are a failed reading, which is tried again until the limit and never counted as closed or open. The shop is closed
# or open only when every address says so in one round.
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
#   2. it says what it will do and goes on only after `tak`.
#
# A record in another phase is a run that stopped: this version of the target refuses it without any change.
#
# Exit codes: 1 refused, not confirmed, or failed (the message says whether the shop is closed); 2 wrong arguments or environment
# (PROFILE, REPLACE, GENERATE_OUT, GENERATE_PROBE_URL, GENERATE_PROBE_HOST, KUBE_DIR, a number setting, the file of the photo pool,
# diskPeakGb).
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

for setting in GENERATE_CLOSE_WAIT GENERATE_OPEN_WAIT GENERATE_POLL GENERATE_PROBE_POLL; do
  setting_value="$(printenv "$setting" || true)"
  case "$setting_value" in
    *[!0-9]*) usage "$setting must be a whole number of seconds, got '$setting_value'" ;;
  esac
done
probe_poll="${GENERATE_PROBE_POLL:-5}"
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

kc() { kubectl -n "$namespace" "$@"; }

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
# (rec_present 0). Each value is one line (whitespace runs become one space). Return 1 when it cannot be read.
read_record() {
  rec_present=0 rec_version="" rec_data=seed rec_phase=idle rec_target="" rec_job="" rec_anchor="" rec_revision="" rec_started=""
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
  rec_present=1 rec_data="" rec_phase=""
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
    echo "    (it was $root_automated_now);"
  fi
  echo "  - after the Job: the indexes of Postgres, the alias of the search, a Job of the CronJob load (load/smoke.js); then the shop opens."
  printf 'generate: type tak to go on: '
  answer=""
  IFS= read -r answer <&3 || answer=""
  echo
  [ "$answer" = tak ] || refuse "not confirmed (no 'tak')"
}

# ---------------------------------------------------------------------------------------------------------------
# The run
# ---------------------------------------------------------------------------------------------------------------
read_record || refuse "cannot read the record $record_name (ConfigMap of the namespace $namespace)"
printf '%s\n' "$rec_data" | grep -Eqx '[a-z][a-z0-9-]{0,30}' ||
  refuse "the record $record_name says data '$rec_data', which is neither seed, failed nor a profile: it was not written by make generate"
if [ "$rec_phase" != idle ]; then
  refuse "the record $record_name says phase $rec_phase (target ${rec_target:-?}): an earlier run of make generate stopped there; this version of the target does not resume it yet (docs/features/demo-scale/spec.md, \"Przerwanie i wznowienie\")"
fi
check_idle
confirm
refuse "the closing of the shop and the generation are not implemented yet"
