#!/bin/sh
# Empties the request cache of the product index of the cluster, over SSH: `make -C infra/k8s search-cache-clear HOST=<user>@<address>`
# (docs/features/search/spec.md, "Weryfikacja wydajności (PERF-2 etap B)", "Zimny request cache"). The one call that changes
# anything is `POST /products/_cache/clear?request=true` against the alias products (app.search.alias of the application, the same name
# as in reindex.sh), made from the pod elasticsearch, so Elasticsearch resolves the alias to its index. It touches only the request cache
# of that index: no data, no other cache (not the query cache, the fielddata cache or the cache of the files of the system), no index
# setting, and no other index. The next searches fill the cache again. It is part of a measurement on the server, so the agent runs it
# only after the consent of the user in the conversation that names the operation and the host (no `tak`: nothing is lost).
# Steps, each a failure with a message and exit code 1 when it does not hold:
#   1. GET /_alias/products: the alias exists and is on exactly one index;
#   2. POST /products/_cache/clear?request=true: HTTP 200, no `error`, and in `_shards` a total above 0 and no failed shard.
#
# Usage: search-cache-clear.sh <user@address>      Environment: SSH_OPTS
# Exit codes: 0 emptied; 1 the server or Elasticsearch cannot be reached, the alias is missing or not on one index, Elasticsearch
# answered with an error or a failed shard; 2 wrong arguments (no HOST, HOST that is not <user>@<address>).
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
# shellcheck source=remote.sh
. "$here/remote.sh"

check_host search-cache-clear "${1:-}"
alias_name=products

# es <method> <path>: the answer of Elasticsearch from the pod elasticsearch. Sets es_code (the HTTP status) and es_body; fails when
# ssh, kubectl or curl fails (no answer). The standard input of ssh is closed. The words of the remote command are fixed (see
# remote_root): the format of -w and the URL are single-quoted words for the shell of the server.
es() {
  es_out="$(remote_root k3s kubectl -n shop exec statefulset/elasticsearch -- curl -sS -X "$1" -w "'\\n%{http_code}'" "'http://localhost:9200/$2'" < /dev/null)" || return 1
  es_code="${es_out##*
}"
  es_body="${es_out%
*}"
  case "$es_code" in
    [0-9][0-9][0-9]) ;;
    *) return 1 ;;
  esac
}

if ! es GET "_alias/$alias_name"; then
  echo "search-cache-clear: could not ask Elasticsearch on $host for the alias $alias_name (the server, the pod elasticsearch or curl failed); nothing was changed" >&2
  exit 1
fi
if [ "$es_code" = 404 ]; then
  echo "search-cache-clear: the alias $alias_name does not exist in Elasticsearch on $host; nothing was changed" >&2
  exit 1
fi
if [ "$es_code" != 200 ]; then
  echo "search-cache-clear: Elasticsearch answered HTTP $es_code to the read of the alias $alias_name: $es_body; nothing was changed" >&2
  exit 1
fi
indices="$(printf '%s' "$es_body" | grep -o '"aliases":' | wc -l | tr -d ' ')"
if [ "$indices" != 1 ]; then
  echo "search-cache-clear: the alias $alias_name is on $indices indices, not on exactly one: $es_body; nothing was changed" >&2
  exit 1
fi
index="$(printf '%s' "$es_body" | sed 's/^{"\([^"]*\)".*/\1/')"

if ! es POST "$alias_name/_cache/clear?request=true"; then
  echo "search-cache-clear: could not clear the request cache: the call to Elasticsearch on $host failed (nothing is known about the cache)" >&2
  exit 1
fi
if [ "$es_code" != 200 ]; then
  echo "search-cache-clear: Elasticsearch answered HTTP $es_code: $es_body" >&2
  exit 1
fi
case "$es_body" in
  *'"error"'*) echo "search-cache-clear: Elasticsearch answered with an error: $es_body" >&2; exit 1 ;;
esac
total="$(printf '%s' "$es_body" | sed -n 's/.*"_shards":{[^}]*"total":\([0-9]*\).*/\1/p')"
failed="$(printf '%s' "$es_body" | sed -n 's/.*"_shards":{[^}]*"failed":\([0-9]*\).*/\1/p')"
if [ -z "$total" ] || [ -z "$failed" ]; then
  echo "search-cache-clear: the answer of Elasticsearch has no _shards with total and failed: $es_body" >&2
  exit 1
fi
if [ "$total" -eq 0 ] || [ "$failed" -ne 0 ]; then
  echo "search-cache-clear: the request cache was not emptied on every shard (total $total, failed $failed): $es_body" >&2
  exit 1
fi
echo "search-cache-clear: done: the request cache of the index $index (alias $alias_name) is empty on $total shard(s) of $host"
