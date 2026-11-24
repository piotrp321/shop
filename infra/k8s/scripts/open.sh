#!/bin/sh
# Checks the data that the Job demo-data-run of make generate gave and opens the shop: make -C infra/k8s open GENERATE_PROBE_URL='<url>
# ...' KUBE_DIR=<directory>; make generate runs it after a Job that completed. Idempotent. Run by hand, it takes the lock demo-data-lock
# (scripts/shop-closed.sh) before its first change, holds it to its end and releases it on every exit (HUP, INT and TERM too; a KILL
# leaves it); run by make generate, it goes on only while the lock of make generate is there (DEMO_DATA_LOCK_TOKEN). It refuses while
# another holds the lock, while a Job runs (active_jobs of scripts/shop-closed.sh: not one of the CronJobs of the short checks) and
# without a Job demo-data-run that completed (make generate deletes the one of the run before
# right before its new Job); a Secret of the photo pool that is left (make generate killed) is deleted once no Job runs. The checks and
# the opening: docs/features/demo-scale/spec.md, "Cel make -C infra/k8s open".
#
# Environment: GENERATE_PROBE_URL, GENERATE_PROBE_HOST, KUBE_DIR (required), KUBE_NETWORK, KUBE_NAMESPACE, GENERATE_OPEN_WAIT (300),
# GENERATE_POLL (30), GENERATE_PROBE_POLL (5), as for make generate; DEMO_DATA_LOCK_TOKEN is set only by make generate.
#
# Exit codes: 0 checked and open; 1 refused or a check failed (the shop stays closed) or the probe does not say open; 2 wrong arguments.
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
DEMO_DATA_TOOL=open

usage() {
  printf 'open: %s\n' "$1" >&2
  echo "open: usage: make -C infra/k8s open GENERATE_PROBE_URL='<url> ...' [GENERATE_PROBE_HOST='<host> ...'] KUBE_DIR=<directory>" >&2
  exit 2
}
refuse() {
  printf 'open: %s\n' "$*" >&2
  echo "open: nothing was changed" >&2
  exit 1
}
fail() {
  printf 'open: FAILED: %s\n' "$1" >&2
  echo "open: the shop stays CLOSED (the NetworkPolicy shop-closed); make -C infra/k8s generate PROFILE=<profile> again, or PROFILE=seed" >&2
  exit 1
}

read_environment
exec < /dev/null
job=demo-data-run

# The lock, before the first change: run by make generate, the one of make generate (it releases it); by hand, its own to its end.
if [ -n "${DEMO_DATA_LOCK_TOKEN:-}" ]; then
  lock_is "$DEMO_DATA_LOCK_TOKEN" || refuse "$lock_error"
else
  cleanup() {
    status=$?
    trap '' HUP INT TERM
    trap - EXIT
    lock_release || echo "open: the lock $lock_name could not be released: $(lock_message)" >&2
    exit "$status"
  }
  trap cleanup EXIT
  trap 'exit 129' HUP
  trap 'exit 130' INT
  trap 'exit 143' TERM
  lock_take open || refuse "$lock_error"
fi

active="$(active_jobs)" || refuse "cannot read the Jobs of the namespace $namespace"
[ -z "$active" ] || refuse "Jobs are active in the namespace $namespace: $active (make open runs after them)"

secret="$(kc get cronjob demo-data --ignore-not-found -o 'jsonpath={.spec.jobTemplate.spec.template.spec.containers[0].env[?(@.name=="DEMO_PHOTOS_ENDPOINT")].valueFrom.secretKeyRef.name}')" ||
  refuse "cannot read the CronJob demo-data"
if [ -n "$secret" ] && [ -n "$(kc get secret "$secret" --ignore-not-found -o name)" ]; then
  kc delete secret "$secret" --ignore-not-found > /dev/null || refuse "the Secret $secret of the photo pool could not be deleted"
  echo "open: the Secret $secret of the photo pool that was left is deleted"
fi

run="$(kc get job "$job" --ignore-not-found -o json)" || refuse "cannot read the Job $job"
[ -n "$run" ] ||
  refuse "there is no Job $job (make generate deletes the one of the run before right before its new Job): make -C infra/k8s generate PROFILE=<profile> again, or PROFILE=seed"
run_state="$(printf '%s' "$run" | job_state)"
[ "${run_state%% *}" = Complete ] ||
  refuse "the Job $job is ${run_state%% *}, not Complete (make status): make -C infra/k8s generate PROFILE=<profile> again, or PROFILE=seed"

# The pool cache.sitemap (a file, one per pod of the api), so that the checks read the new catalog.
api_pods="$(kc get pods -l app.kubernetes.io/name=api --field-selector=status.phase=Running -o 'jsonpath={.items[*].metadata.name}')" ||
  fail "the pods of the api cannot be read"
[ -n "$api_pods" ] || fail "no pod of the api runs"
for api_pod in $api_pods; do
  kc exec "$api_pod" -- bin/console cache:pool:clear cache.sitemap > /dev/null 2>&1 || fail "the pool cache.sitemap of the pod $api_pod could not be cleared"
done

# The indexes of Postgres, then the alias of the search: on exactly one index with documents, the cluster not red, no index without it.
invalid="$(kc exec statefulset/postgres -- psql -U shop -d shop -v ON_ERROR_STOP=1 -tA -c \
  "SELECT c.relname FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE NOT i.indisvalid OR NOT i.indisready ORDER BY 1")" ||
  fail "the indexes of Postgres could not be read"
invalid="$(printf '%s' "$invalid" | tr -d '\r' | tr '\n' ' ' | sed 's/ *$//')"
[ -z "$invalid" ] || fail "indexes of the database shop are not valid or not ready: $invalid"
echo "open: ok: every index of the database shop is valid and ready"
es() { kc exec statefulset/elasticsearch -- curl -fsS "http://localhost:9200/$1"; }
health="$(es _cluster/health)" || fail "the state of the cluster of Elasticsearch could not be read"
aliases="$(es '_cat/aliases/products?format=json&h=alias,index')" || fail "the alias products of Elasticsearch could not be read"
indices="$(es '_cat/indices/products_*?format=json&h=index,docs.count&expand_wildcards=all')" || fail "the indexes of Elasticsearch could not be read"
search="$(printf '%s\036%s\036%s' "$health" "$aliases" "$indices" | py -c '
import json, sys
health, aliases, indices = (json.loads(part) for part in sys.stdin.read().split("\x1e"))
aliased = sorted(a["index"] for a in aliases if a.get("alias") == "products")
documents = {i["index"]: int(i.get("docs.count") or 0) for i in indices}
if health.get("status") not in ("green", "yellow"):
    print("FAIL the cluster of Elasticsearch is %s" % health.get("status"))
elif len(aliased) != 1:
    print("FAIL the alias products points to %d indexes (%s), not to one" % (len(aliased), " ".join(aliased) or "none"))
elif not documents.get(aliased[0]):
    print("FAIL the index %s of the alias products has no documents" % aliased[0])
elif set(documents) - set(aliased):
    print("FAIL indexes without the alias products are left: %s" % " ".join(sorted(set(documents) - set(aliased))))
else:
    print("ok: Elasticsearch is %s, the alias products is on %s (%d documents)" % (health["status"], aliased[0], documents[aliased[0]]))')" ||
  fail "the answers of Elasticsearch could not be read"
case "$search" in FAIL*) fail "${search#FAIL }" ;; esac
echo "open: $search"

# The paths of the store: load/smoke.js in a Job of the CronJob load (listing, product, search, checkout of a guest).
load_job="load-$(date -u +%Y%m%d%H%M%S)"
cronjob_job load "$load_job" || fail "the Job $load_job could not be created from the CronJob load"
if ! RESTORE_POLL_SECONDS="$poll" wait_job "$load_job"; then
  job_logs "$load_job" api-reachable load >&2
  fail "the Job $load_job of the CronJob load (load/smoke.js) failed"
fi
echo "open: ok: the Job $load_job of the CronJob load passed"

counts="$(kc exec statefulset/postgres -- psql -U shop -d shop -tA -c \
  "SELECT (SELECT count(*) FROM catalog_product), (SELECT count(*) FROM ordering_order)" | tr -d '\r' | head -n 1)" || counts=""
echo "open: ${counts%%|*} products and ${counts#*|} orders; the sizes:"
data_sizes

# The opening.
kc delete networkpolicy shop-closed --ignore-not-found > /dev/null || fail "the NetworkPolicy shop-closed could not be deleted"
probe open "$open_wait" || {
  echo "open: the rule shop-closed is deleted, but the probe does not say open in $open_wait s: $probe_report (make open again)" >&2
  exit 1
}
echo "open: the shop is open: $probe_report"
