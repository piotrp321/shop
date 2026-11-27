#!/bin/sh
# The rebuild of the search index on the cluster: `make -C infra/k8s reindex KUBE_DIR=<directory>` (docs/features/search/spec.md,
# "Infrastruktura"). The Job comes from the suspended CronJob reindex of the deployed chart, so catalog:reindex runs with the image, the
# environment and the secrets of the deployed revision (the new index has one primary shard, set in the code of the API). The shop
# stays open: the command builds a new index, checks it and only then moves the alias products, and a run that failed leaves the alias where it was. A change
# of a product, a price or a stock level written while the index is built reaches the new index too, through the alias products-rebuild
# (docs/features/search/spec.md, "Zapisy w trakcie przebudowy"). A deployment started while the Job runs fails at its Job migrate (catalog:search-index:setup refuses
# while a rebuild holds the lock of the index): it is run again after the rebuild (make rerun).
# Steps:
#   1. checks without changes: the CronJob reindex is there; nobody holds the lock demo-data-lock
#      (make generate and make open: scripts/shop-closed.sh); no Job of the namespace is active, except one of the CronJobs
#      rate-limit-check, queue-check and order-expiry (short checks that run every five minutes and do not touch the index; active_jobs
#      of scripts/shop-closed.sh); the index of the alias products can
#      be read from Elasticsearch;
#   2. says which index the alias is on (its shards, which may be several in an index built before the model with one), that the new
#      one gets one shard, and goes on only after `tak`;
#   3. takes the lock demo-data-lock and holds it to its end (the trap releases it; a KILL leaves it), so that make generate and make
#      open refuse without changes while the index is rebuilt, and reads the Jobs again: one that started while the script waited for
#      the answer is a refusal; then the Job reindex-<UTC time> from the CronJob, waited for until it completes or fails (at most its
#      activeDeadlineSeconds);
#   4. the end of the log of the Job; after a Job that completed the index of the alias is read again: a new index with one
#      primary shard is the success, anything else a failure.
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
# shellcheck source=shop-closed.sh
. "$here/shop-closed.sh"
# The name of this script in the messages of the lock (scripts/shop-closed.sh).
# shellcheck disable=SC2034 # read by lock_release
DEMO_DATA_TOOL=reindex

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

# alias_index: "<index> <primary shards> <Lucene documents>" of the one index the alias is on (the documents of Lucene count the nested
# documents of the variants too, so they are more than the products of the log of the Job); fails when Elasticsearch cannot be asked or the
# alias is not on exactly one index.
alias_index() {
  kubectl -n "$namespace" exec statefulset/elasticsearch -- curl -fsS \
    "http://localhost:9200/_cat/indices/$alias_name?format=json&h=index,pri,docs.count" |
    py_json '"%s %d %d" % (d[0]["index"], int(d[0]["pri"]), int(d[0]["docs.count"])) if len(d) == 1 else sys.exit(1)'
}

# The lock of this run is released on every exit (lock_release deletes only a lock with the token of this run). A signal after the Job
# was created: the Job goes on in the cluster, which the message says.
cleanup() {
  status=$?
  trap '' HUP INT TERM
  trap - EXIT
  lock_release || echo "reindex: the lock $lock_name could not be released: $(lock_message)" >&2
  exit "$status"
}
on_signal() {
  [ -z "$job" ] || echo "reindex: interrupted; the Job $job goes on in the cluster (kubectl -n $namespace get job $job)" >&2
  exit 130
}
trap cleanup EXIT
trap on_signal HUP INT TERM

# ---------------------------------------------------------------------------------------------------------------
# 1. Checks, without changes
# ---------------------------------------------------------------------------------------------------------------
kubectl -n "$namespace" get cronjob "$cronjob" -o json > /dev/null ||
  refuse "cannot read the CronJob $cronjob of the namespace $namespace (is the cluster reachable?)"
# The primary shards of every index (ProductIndexDefinition::SHARDS): the facets of the search model are exact only on one shard.
shards=1

lock_read || refuse "cannot read the lock $lock_name"
[ -z "$lock_held_token" ] || refuse "the lock $lock_name is held by $lock_holder: make generate, make open and make reindex run one at a time; $(lock_message)"

active="$(active_jobs)" || refuse "cannot read the Jobs of the namespace $namespace"
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
  - the alias $alias_name is on the index $before_index: $before_shards primary shard(s), $before_documents Lucene documents (the
    nested documents of the variants count too, so more than the products);
  - a Job of the CronJob $cronjob runs catalog:reindex of the deployed revision: a new index with $shards primary shard(s) is built from
    the database, checked, and only then the alias moves to it and the index $before_index is deleted;
  - the shop stays open and is served by the index $before_index until then; a run that fails leaves the alias on it.
  - a change of a product, a price or a stock level written during the build may be missing from the new index until the next
    change of that product or the next rebuild;
  - no deployment until the Job ends: the Job migrate of a new revision fails while the index is rebuilt (its
    catalog:search-index:setup refuses), and has to be run again after it (make -C infra/k8s rerun JOB=<migrate job>).
EOF
printf 'reindex: type tak to go on: '
answer=""
read -r answer <&3 || answer=""
echo
[ "$answer" = tak ] || refuse "not confirmed (no 'tak')"

# ---------------------------------------------------------------------------------------------------------------
# 3. The lock, the Jobs once more, the Job
# ---------------------------------------------------------------------------------------------------------------
# The answer may have taken long: the lock keeps make generate and make open out from here on (they refuse without changes), and a Job
# that started since the checks (a deployment, a CronJob) is a refusal before anything is created.
lock_take reindex || refuse "$lock_error"
active="$(active_jobs)" || refuse "cannot read the Jobs of the namespace $namespace"
[ -z "$active" ] || refuse "Jobs are active in the namespace $namespace: $active (they started after the checks; wait for them to finish)"

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
  after="$(alias_index)" && echo "reindex: the alias $alias_name is on (index, primary shards, Lucene documents): $after" >&2
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
  echo "reindex: FAILED: the new index $after_index has $after_shards primary shard(s), not the $shards of the index definition" >&2
  exit 1
fi
echo "reindex: done: the alias $alias_name is on the index $after_index: $after_shards primary shard(s), $after_documents Lucene documents (before: $before_index, $before_shards primary shard(s), $before_documents Lucene documents)"
