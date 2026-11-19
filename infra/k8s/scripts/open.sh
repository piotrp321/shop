#!/bin/sh
# Checks the data that the Job demo-data-run of make generate gave and opens the shop: make -C infra/k8s open GENERATE_PROBE_URL='<url>
# ...' KUBE_DIR=<directory>; make generate runs it after a Job that completed. Idempotent. The checks and the opening:
# docs/features/demo-scale/spec.md, "Cel make -C infra/k8s open".
#
# Environment: GENERATE_PROBE_URL, GENERATE_PROBE_HOST, KUBE_DIR (required), KUBE_NETWORK, KUBE_NAMESPACE, GENERATE_OPEN_WAIT (300),
# GENERATE_POLL (30), GENERATE_PROBE_POLL (5), as for make generate.
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

active="$(active_jobs)" || refuse "cannot read the Jobs of the namespace $namespace"
[ -z "$active" ] || refuse "Jobs are active in the namespace $namespace: $active (make open runs after them)"

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
