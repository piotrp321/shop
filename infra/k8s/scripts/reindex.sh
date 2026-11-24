#!/bin/sh
# The rebuild of the search index on the cluster: `make -C infra/k8s reindex KUBE_DIR=<directory>` (docs/features/search/spec.md,
# "Infrastruktura"). The Job comes from the suspended CronJob reindex of the deployed chart, so catalog:reindex runs with the image, the
# environment (SEARCH_SHARDS: the shards of the new index) and the secrets of the deployed revision. The shop stays open: the command
# builds a new index, checks it and only then moves the alias products, and a run that failed leaves the alias where it was. It is not
# an online rebuild (docs/features/walking-skeleton/spec.md, "Ograniczenia tej przebudowy"): a change of a product, a price or a stock
# level written while the index is built goes to the previous index and may be missing from the new one until the next change of
# that product or the next rebuild.
# Steps:
#   1. checks without changes: the CronJob reindex is there and says the shards of a new index; no Job of the namespace is active,
#      except one of the CronJobs rate-limit-check, queue-check and order-expiry (short checks that run every five minutes); the index
#      of the alias products can be read from Elasticsearch;
#   2. says which index the alias is on, how many shards the new one gets, and goes on only after `tak`;
#   3. the Job reindex-<UTC time> from the CronJob, waited for until it completes or fails (at most its activeDeadlineSeconds);
#   4. the end of the log of the Job; after a Job that completed the index of the alias is read again: a new index with the shards
#      of the CronJob is the success, anything else a failure.
#
# Usage: reindex.sh (make -C infra/k8s reindex), the answer on the standard input.
# Environment: KUBE_DIR (required: the directory with the kubeconfig `config` of the cluster; no default, so that no index is rebuilt
# in whatever cluster ~/.kube points at), KUBE_NETWORK (the Docker network kubectl runs on, default host), KUBE_NAMESPACE (default
# shop), RESTORE_POLL_SECONDS (the time between two readings of the Job, 5).
# Exit codes: 0 rebuilt; 1 refused, not confirmed or failed; 2 no KUBE_DIR.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=kube-jobs.sh
. "$here/kube-jobs.sh"

if [ -z "${KUBE_DIR:-}" ]; then
  echo "reindex: set KUBE_DIR to the directory with the kubeconfig (file 'config') of the cluster" >&2
  exit 2
fi
KUBE_NETWORK="${KUBE_NETWORK:-host}"
namespace="${KUBE_NAMESPACE:-shop}"
if [ ! -f "$KUBE_DIR/config" ]; then
  echo "reindex: no kubeconfig at $KUBE_DIR/config (set KUBE_DIR)" >&2
  exit 1
fi

# The answer is read from the standard input of the script; every other command reads nothing (docker run -i would take it).
exec 3<&0 < /dev/null

cronjob=reindex
alias_name=products
# The CronJobs of short checks: an active Job of theirs (by its owner) is no reason to refuse (they do not touch the index).
check_cronjobs="rate-limit-check queue-check order-expiry"
job=""

refuse() {
  printf '%s\n' "reindex: $*" >&2
  echo "reindex: nothing changed" >&2
  exit 1
}

# py_json <python expression of d, the JSON document on the standard input>: prints the value (a string).
py_json() {
  py -c "import json, sys
d = json.load(sys.stdin)
print($1)"
}

# alias_index: "<index> <primary shards> <documents>" of the one index the alias is on; fails when Elasticsearch cannot be asked or the
# alias is not on exactly one index.
alias_index() {
  kubectl -n "$namespace" exec statefulset/elasticsearch -- curl -fsS \
    "http://localhost:9200/_cat/indices/$alias_name?format=json&h=index,pri,docs.count" |
    py_json '"%s %d %d" % (d[0]["index"], int(d[0]["pri"]), int(d[0]["docs.count"])) if len(d) == 1 else sys.exit(1)'
}

# A signal after the Job was created: the Job goes on in the cluster, which the message says.
on_signal() {
  [ -z "$job" ] || echo "reindex: interrupted; the Job $job goes on in the cluster (kubectl -n $namespace get job $job)" >&2
  exit 130
}
trap on_signal INT TERM

# ---------------------------------------------------------------------------------------------------------------
# 1. Checks, without changes
# ---------------------------------------------------------------------------------------------------------------
manifest="$(kubectl -n "$namespace" get cronjob "$cronjob" -o json)" ||
  refuse "cannot read the CronJob $cronjob of the namespace $namespace (the deployed revision has it since the chart with elasticsearch.shards; is the cluster reachable?)"
shards="$(printf '%s' "$manifest" | py_json '"".join(e.get("value", "") for c in d["spec"]["jobTemplate"]["spec"]["template"]["spec"]["containers"]
    for e in c.get("env") or [] if e["name"] == "SEARCH_SHARDS")')" ||
  refuse "cannot read SEARCH_SHARDS of the CronJob $cronjob"
case "$shards" in
  "" | *[!0-9]* | 0*) refuse "the CronJob $cronjob has no number of shards in SEARCH_SHARDS (got '$shards')" ;;
esac

active="$(kubectl -n "$namespace" get jobs -o json | py_json '" ".join(j["metadata"]["name"] for j in d["items"]
    if not {c.get("type") for c in (j.get("status", {}).get("conditions") or []) if c.get("status") == "True"} & {"Complete", "Failed"}
    and not any(o.get("kind") == "CronJob" and o.get("name") in "'"$check_cronjobs"'".split() for o in j["metadata"].get("ownerReferences") or []))')" ||
  refuse "cannot read the Jobs of the namespace $namespace"
[ -z "$active" ] || refuse "Jobs are active in the namespace $namespace: $active (wait for them to finish)"

before="$(alias_index)" || refuse "cannot read the index of the alias $alias_name from Elasticsearch (the alias has to be on exactly one index)"
# shellcheck disable=SC2086 # three words: the index, its shards, its documents
set -- $before
before_index="$1" before_shards="$2" before_documents="$3"

# ---------------------------------------------------------------------------------------------------------------
# 2. What it will do, and the confirmation
# ---------------------------------------------------------------------------------------------------------------
cat <<EOF
reindex: in the namespace $namespace of the cluster of $KUBE_DIR/config:
  - the alias $alias_name is on the index $before_index: $before_shards primary shard(s), $before_documents documents;
  - a Job of the CronJob $cronjob runs catalog:reindex of the deployed revision: a new index with $shards primary shard(s) is built from
    the database, checked, and only then the alias moves to it and the index $before_index is deleted;
  - the shop stays open and is served by the index $before_index until then; a run that fails leaves the alias on it.
  - a change of a product, a price or a stock level written during the build may be missing from the new index until the next
    change of that product or the next rebuild.
EOF
printf 'reindex: type tak to go on: '
answer=""
read -r answer <&3 || answer=""
echo
[ "$answer" = tak ] || refuse "not confirmed (no 'tak')"

# ---------------------------------------------------------------------------------------------------------------
# 3. The Job
# ---------------------------------------------------------------------------------------------------------------
job="reindex-$(date -u +%Y%m%d%H%M%S)"
echo "reindex: the Job $job"
cronjob_job "$cronjob" "$job" || {
  echo "reindex: FAILED: the Job $job could not be created from the CronJob $cronjob; the alias $alias_name stays on $before_index" >&2
  exit 1
}
status=0
wait_job "$job" || status=1

# ---------------------------------------------------------------------------------------------------------------
# 4. The log and the index the alias is on now
# ---------------------------------------------------------------------------------------------------------------
job_logs "$job" reindex
if [ "$status" -ne 0 ]; then
  echo "reindex: FAILED: the Job $job did not complete (its log is above); catalog:reindex moves the alias only to an index it built and checked" >&2
  after="$(alias_index)" && echo "reindex: the alias $alias_name is on (index, primary shards, documents): $after" >&2
  exit 1
fi

after="$(alias_index)" || {
  echo "reindex: FAILED: the Job $job completed, but the index of the alias $alias_name could not be read from Elasticsearch" >&2
  exit 1
}
# shellcheck disable=SC2086 # three words: the index, its shards, its documents
set -- $after
after_index="$1" after_shards="$2" after_documents="$3"
if [ "$after_index" = "$before_index" ]; then
  echo "reindex: FAILED: the Job $job completed, but the alias $alias_name is still on the index $before_index" >&2
  exit 1
fi
if [ "$after_shards" != "$shards" ]; then
  echo "reindex: FAILED: the new index $after_index has $after_shards primary shard(s), not the $shards of SEARCH_SHARDS" >&2
  exit 1
fi
echo "reindex: done: the alias $alias_name is on the index $after_index: $after_shards primary shard(s), $after_documents documents (before: $before_index, $before_shards primary shard(s), $before_documents documents)"
