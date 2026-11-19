#!/bin/sh
# Replaces the data of the shop with the demo data of a profile, or with the seed again, then opens the shop through open.sh:
# make -C infra/k8s generate PROFILE=<seed|profile> GENERATE_PROBE_URL='<url> ...' KUBE_DIR=<directory>. It REPLACES the data, so it
# runs only after the consent of the user in the conversation that names the operation, the host and the profile, and asks for tak.
# The run and what a failure leaves: docs/features/demo-scale/spec.md, "Cel make -C infra/k8s generate".
#
# Environment:
#   PROFILE                 seed, or the profile of the CronJob demo-data (demoData.profile of the deployed values)
#   GENERATE_PROBE_URL      the public addresses of the shop, separated by spaces (on the server 'https://api.worf.pl/ https://worf.pl/')
#   GENERATE_PROBE_HOST     their Host headers, one per address (a test cluster); empty: none
#   KUBE_DIR (required), KUBE_NETWORK (host), KUBE_NAMESPACE (shop)
#   DEMO_PHOTOS_ENV_FILE    the read access of the photo pool (~/.config/shop/demo-photos.env), when the CronJob reads its Secret
#   GENERATE_PROFILES_FILE  diskPeakGb of the profiles (apps/api/fixtures/demo-data/profiles.yaml)
#   GENERATE_CLOSE_WAIT (120), GENERATE_OPEN_WAIT (300), GENERATE_POLL (30), GENERATE_PROBE_POLL (5)   seconds
#
# Exit codes: 0 generated, checked, the shop open; 1 refused, not confirmed or failed (the message says whether the shop is closed);
# 2 wrong arguments or environment.
set -eu
set -f

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=kube-jobs.sh
. "$here/kube-jobs.sh"
# shellcheck source=demo-data-lib.sh
. "$here/demo-data-lib.sh"
# shellcheck source=shop-closed.sh
. "$here/shop-closed.sh"
DEMO_DATA_TOOL=generate

usage() {
  printf 'generate: %s\n' "$1" >&2
  echo "generate: usage: make -C infra/k8s generate PROFILE=<seed or the profile of the CronJob demo-data> GENERATE_PROBE_URL='<url> ...' [GENERATE_PROBE_HOST='<host> ...'] KUBE_DIR=<directory>" >&2
  echo "generate: nothing was changed" >&2
  exit 2
}
refuse() {
  printf 'generate: %s\n' "$*" >&2
  echo "generate: nothing was changed" >&2
  exit 1
}
# fail <reason>: after the closing; the shop stays closed.
fail() {
  printf 'generate: FAILED: %s\n' "$1" >&2
  echo "generate: the shop is CLOSED (the NetworkPolicy shop-closed). Two ways out: make -C infra/k8s generate PROFILE=$profile again, or back to the seed with PROFILE=seed" >&2
  exit 1
}

profile="${PROFILE:-}"
printf '%s\n' "$profile" | grep -Eqx '[a-z][a-z0-9-]{0,30}' || usage "PROFILE '$profile' is neither seed nor the name of a profile"
read_environment
profiles_file="${GENERATE_PROFILES_FILE:-$TOOLS_REPO/apps/api/fixtures/demo-data/profiles.yaml}"
DEMO_PHOTOS_ENV_FILE="${DEMO_PHOTOS_ENV_FILE:-$HOME/.config/shop/demo-photos.env}"
# The answer is read from the standard input of the script; every other command reads nothing (docker run -i would take it).
exec 3<&0 < /dev/null
job=demo-data-run
# Ingress to every pod of the namespace only from its pods: Traefik gets 502, the probes of the kubelet and the traffic between the pods
# go on. No labels and no annotations: Argo CD does not track it.
rule_manifest='{"apiVersion":"networking.k8s.io/v1","kind":"NetworkPolicy","metadata":{"name":"shop-closed"},"spec":{"podSelector":{},"policyTypes":["Ingress"],"ingress":[{"from":[{"podSelector":{}}]}]}}'
counts_sql="SELECT (SELECT count(*) FROM catalog_product), (SELECT count(*) FROM ordering_order)"

# 1. Checks, without changes.
cron_json="$(kc get cronjob demo-data --ignore-not-found -o json)" || refuse "cannot read the CronJob demo-data"
[ -n "$cron_json" ] || refuse "there is no CronJob demo-data in the namespace $namespace (demoData.enabled of the deployed values)"
cron_fields="$(printf '%s' "$cron_json" | py -c '
import json, sys
spec = json.load(sys.stdin)["spec"]
container = spec["jobTemplate"]["spec"]["template"]["spec"]["containers"][0]
argument = lambda name: next((a.split("=", 1)[1] for a in container.get("command") or [] if a.startswith(name + "=")), "-")
secret = next((((v.get("valueFrom") or {}).get("secretKeyRef") or {}).get("name", "-") for v in container.get("env") or []
               if v.get("name") == "DEMO_PHOTOS_ENDPOINT"), "-")
print(str(spec.get("suspend") is True).lower(), argument("--profile"), argument("--min-free-gb"), secret)')" ||
  refuse "cannot read the CronJob demo-data"
# shellcheck disable=SC2086 # four words
set -- $cron_fields
cron_suspend="$1" cron_profile="$2" cron_min_free="$3" cron_secret="$4"
[ "$cron_secret" != - ] || cron_secret=""
[ "$cron_suspend" = true ] || refuse "the CronJob demo-data is not suspended (the chart has it suspended)"
[ "$profile" = seed ] || [ "$profile" = "$cron_profile" ] ||
  refuse "PROFILE=$profile is neither seed nor the profile $cron_profile of the CronJob demo-data (another one needs demoData.profile and a deployment)"
case "$cron_min_free" in "" | *[!0-9]*) refuse "the CronJob demo-data has no --min-free-gb=<GB> in its command" ;; esac

active="$(active_jobs)" || refuse "cannot read the Jobs of the namespace $namespace"
[ -z "$active" ] || refuse "Jobs are active in the namespace $namespace: $active (wait for them to finish)"
unready=""
for kind in deployments statefulsets; do
  workloads="$(kc get "$kind" -o json)" || refuse "cannot read the $kind of the namespace $namespace"
  unready="$unready$(printf '%s' "$workloads" | py -c '
import json, sys
print("".join(" " + sys.argv[1] + "/" + w["metadata"]["name"] for w in json.load(sys.stdin)["items"]
    if min(w.get("status", {}).get("readyReplicas") or 0, w.get("status", {}).get("updatedReplicas") or 0) < w.get("spec", {}).get("replicas", 1)
    or (w.get("status", {}).get("observedGeneration") or 0) < (w.get("metadata", {}).get("generation") or 0)), end="")' "$kind")"
done
[ -z "$unready" ] || refuse "not ready:$unready (the generator needs every workload; wait until they are ready)"

photos_needed=0
if [ "$profile" != seed ] && [ -n "$cron_secret" ]; then
  photos_needed=1
  read_photo_access "$DEMO_PHOTOS_ENV_FILE" || {
    printf 'generate: PROFILE=%s takes the photos of the photo pool (the CronJob reads the Secret %s), and %s\n' "$profile" "$cron_secret" "$demo_data_error" >&2
    echo "generate: nothing was changed" >&2
    exit 2
  }
fi

# The free space of every node (the kubelet): a reading that is a number; for a profile at least minFreeGb and, for medium and server,
# 1.25 x diskPeakGb (GB is 10^9 bytes). The seed has no floor: it is the way back after a stop by the guard of the Job.
need=0 need_text="no floor (the seed)"
if [ "$profile" != seed ]; then
  need=$((cron_min_free * 1000000000)) need_text="$cron_min_free GB (demoData.minFreeGb)"
  case "$profile" in
    medium | server)
      read_disk_peak "$profile" "$profiles_file" || usage "$demo_data_error"
      peak=$((required_kib * 1024))
      [ "$peak" -le "$need" ] || need="$peak" need_text="$required_gb GB (1.25 x diskPeakGb $disk_peak GB of $profile)" ;;
  esac
fi
nodes="$(kubectl get nodes -o 'jsonpath={.items[*].metadata.name}')" || refuse "cannot read the nodes of the cluster"
[ -n "$nodes" ] || refuse "the cluster has no node"
free_text=""
for node in $nodes; do
  free="$(kubectl get --raw "/api/v1/nodes/$node/proxy/stats/summary" | py -c '
import json, sys
value = json.load(sys.stdin).get("node", {}).get("fs", {}).get("availableBytes")
print(value if isinstance(value, int) and not isinstance(value, bool) and value >= 0 else "")')" || free=""
  case "$free" in "" | *[!0-9]*) refuse "cannot read the free space of the node $node (the kubelet, node.fs.availableBytes) as a number" ;; esac
  free_gb="$(awk -v b="$free" 'BEGIN { printf "%.2f", b / 1000000000 }')"
  [ "$free" -ge "$need" ] || refuse "the node $node has $free_gb GB free; PROFILE=$profile needs at least $need_text"
  free_text="$free_text${free_text:+, }$node $free_gb GB"
done

# 2. What it will do, and the confirmation.
server="$(kubectl config view --minify -o 'jsonpath={.clusters[0].cluster.server}' 2>/dev/null)" || server="(unknown)"
counts="$(kc exec statefulset/postgres -- psql -U shop -d shop -tA -c "$counts_sql" 2>/dev/null | tr -d '\r' | head -n 1)" || counts=""
case "$counts" in *"|"*) ;; *) counts="unknown|unknown" ;; esac
cat <<EOF
generate: the cluster $server ($KUBE_DIR/config), namespace $namespace; the shop at $probe_urls
  - the data now: ${counts%%|*} products and ${counts#*|} orders; REPLACED with PROFILE=$profile (a Job $job of the CronJob demo-data)
  - the free space: $free_text (needed: $need_text)
  - the shop is CLOSED from now until the checks after the generation pass (make open); a failure leaves it closed
EOF
printf 'generate: type tak to go on: '
answer=""
IFS= read -r answer <&3 || answer=""
echo
[ "$answer" = tak ] || refuse "not confirmed (no 'tak')"

# 3. The closing: the rule, Traefik restarted (its pods deleted: the connections opened before the rule end), the probe.
rule="$(kc get networkpolicy shop-closed --ignore-not-found -o name)" || fail "cannot read the NetworkPolicy shop-closed"
[ -n "$rule" ] || printf '%s' "$rule_manifest" | kc create -f - > /dev/null || fail "the NetworkPolicy shop-closed could not be made"
kubectl -n kube-system delete pods -l app.kubernetes.io/name=traefik --wait=true > /dev/null ||
  fail "the pods of Traefik could not be deleted (the connections opened before the rule may stay open)"
probe closed "$close_wait" || fail "the probe does not say closed in $close_wait s: $probe_report"
echo "generate: the shop is closed: $probe_report"

# 4. The generation.
if [ "$photos_needed" = 1 ]; then
  kc delete secret "$cron_secret" --ignore-not-found > /dev/null || fail "the Secret $cron_secret could not be deleted"
  create_photo_secret "$cron_secret" || fail "$demo_data_error"
fi
cronjob_job demo-data "$job" "DEMO_DATA_CONFIRM=$profile" || fail "the Job $job could not be created from the CronJob demo-data"
echo "generate: the Job $job runs (its log and the metrics go to Grafana Cloud); this target only waits"
job_ok=1
RESTORE_POLL_SECONDS="$poll" wait_job "$job" || job_ok=0
[ -z "$cron_secret" ] || kc delete secret "$cron_secret" --ignore-not-found > /dev/null || true
if [ "$job_ok" = 0 ]; then
  reason="$(kc get pods -l "batch.kubernetes.io/job-name=$job" -o 'jsonpath={.items[*].status.containerStatuses[*].state.terminated.message}' 2>/dev/null)" || reason=""
  fail "the Job $job did not complete: ${reason:-no reason in the termination log of its pod (the log is in Grafana Cloud)}"
fi
echo "generate: the Job $job completed"
sh "$here/open.sh"
