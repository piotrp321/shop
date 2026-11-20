#!/bin/sh
# shellcheck disable=SC2154 # usage is defined by the script that sources this file
# Sourced by generate.sh, open.sh and smoke.sh after tools.sh (docs/features/demo-scale/spec.md): what both read before the cluster, the cluster
# through kubectl (KUBE_DIR, KUBE_NETWORK) in the namespace $namespace, and the probe of the public addresses. The caller defines usage
# <message> (exit 2).
#
#   read_environment            KUBE_DIR, KUBE_NETWORK, KUBE_NAMESPACE, GENERATE_PROBE_URL, GENERATE_PROBE_HOST and the seconds of
#                               GENERATE_CLOSE_WAIT (120), GENERATE_OPEN_WAIT (300), GENERATE_POLL (30), GENERATE_PROBE_POLL (5)
#   kc <arguments>              kubectl in the namespace
#   active_jobs                 the Jobs of the namespace that run (neither Complete nor Failed), separated by spaces; a Job owned by
#                               the CronJob rate-limit-check (every 5 minutes, at most a minute) is not counted
#   probe <closed|open> <s>     0 once every address says <state> in one round, 1 when <s> seconds passed first (probe_report: the
#                               last round). 502, 503 and 504 are closed, a code below 500 is open; any other code and no answer are
#                               a failed reading, tried again, never closed or open.
#
# The lock: make generate and make open run one at a time. It is the ConfigMap demo-data-lock of the namespace (data: holder, who
# holds it, and token, random), without labels and annotations (Argo CD does not track it). kubectl create makes it or fails with
# AlreadyExists, so only one run gets it; a run deletes it only while the token in it is its own.
#   lock_read                   lock_holder and lock_held_token of the lock (both empty: there is none); 1 when it cannot be read
#   lock_take <command> [<profile>]
#                               makes the lock with a new token (lock_token, set before the creation: lock_release deletes a lock
#                               that a creation with a lost answer made); 1 with lock_error when another holds it or it cannot be made
#   lock_release                deletes the lock of lock_token, if any, and never the lock of another; 1 when that cannot be done
#   lock_is <token>             0 when the lock is there with this token (make open run by make generate), else 1 with lock_error
#   lock_message                how the lock is left and how the operator deletes it

# shellcheck disable=SC2034 # close_wait, open_wait and poll are read by the scripts that source this file
read_environment() {
  [ -n "${KUBE_DIR:-}" ] || usage "set KUBE_DIR to the directory with the kubeconfig (file config) of the cluster"
  [ -f "$KUBE_DIR/config" ] || usage "no kubeconfig at $KUBE_DIR/config (set KUBE_DIR)"
  KUBE_NETWORK="${KUBE_NETWORK:-host}"
  namespace="${KUBE_NAMESPACE:-shop}"
  for setting in GENERATE_CLOSE_WAIT GENERATE_OPEN_WAIT GENERATE_POLL GENERATE_PROBE_POLL; do
    case "$(printenv "$setting" || true)" in
      *[!0-9]*) usage "$setting must be a whole number of seconds" ;;
    esac
  done
  close_wait="${GENERATE_CLOSE_WAIT:-120}" open_wait="${GENERATE_OPEN_WAIT:-300}"
  poll="${GENERATE_POLL:-30}" probe_poll="${GENERATE_PROBE_POLL:-5}"
  probe_urls="${GENERATE_PROBE_URL:-}" probe_hosts="${GENERATE_PROBE_HOST:-}"
  url_count=0 host_count=0
  for probe_url in $probe_urls; do
    printf '%s\n' "$probe_url" | grep -Eqx 'https?://[A-Za-z0-9.:-]+(/[^[:space:]]*)?' ||
      usage "GENERATE_PROBE_URL: '$probe_url' is not an http or https address"
    url_count=$((url_count + 1))
  done
  [ "$url_count" -gt 0 ] ||
    usage "set GENERATE_PROBE_URL to the public addresses of the shop, separated by spaces (on the server 'https://api.worf.pl/ https://worf.pl/')"
  for probe_host in $probe_hosts; do
    printf '%s\n' "$probe_host" | grep -Eqx '[A-Za-z0-9.:-]+' || usage "GENERATE_PROBE_HOST: '$probe_host' is not a host name"
    host_count=$((host_count + 1))
  done
  [ "$host_count" -eq 0 ] || [ "$host_count" -eq "$url_count" ] ||
    usage "GENERATE_PROBE_HOST has $host_count host(s) for $url_count address(es) of GENERATE_PROBE_URL: one per address, in the same order"
}

kc() { kubectl -n "$namespace" "$@"; }

active_jobs() {
  kc get jobs -o json | py -c '
import json, sys
print(" ".join(j["metadata"]["name"] for j in json.load(sys.stdin)["items"]
    if not {c.get("type") for c in (j.get("status", {}).get("conditions") or []) if c.get("status") == "True"} & {"Complete", "Failed"}
    and not any(o.get("kind") == "CronJob" and o.get("name") == "rate-limit-check" for o in j["metadata"].get("ownerReferences") or [])))'
}

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

probe() {
  probe_until=$(($(date +%s) + $2))
  while :; do
    probe_round "$1" && return 0
    [ "$(date +%s)" -lt "$probe_until" ] || return 1
    sleep "$probe_poll"
  done
}

lock_name=demo-data-lock
lock_token=""

lock_read() {
  lock_read_out="$(kc get configmap "$lock_name" --ignore-not-found -o 'jsonpath={.metadata.name}{"/"}{.data.token}{"/"}{.data.holder}')" ||
    return 1
  lock_holder="" lock_held_token=""
  [ -n "$lock_read_out" ] || return 0
  lock_read_out="${lock_read_out#*/}"
  lock_held_token="${lock_read_out%%/*}"
  # It goes into messages: without control characters, and never empty for a lock that is there.
  lock_holder="$(printf '%s' "${lock_read_out#*/}" | tr -d '\000-\037\177')"
  lock_holder="${lock_holder:-(no holder written)}"
  lock_held_token="${lock_held_token:-(none)}"
}

lock_message() {
  echo "it stays after a make generate or make open killed with KILL, or one that could not release it; only when neither runs, the operator deletes it: kubectl -n $namespace delete configmap $lock_name (on the server: sudo k3s kubectl -n $namespace delete configmap $lock_name; make status shows it)"
}

lock_take() {
  lock_token="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
  case "$lock_token" in
    "" | *[!0-9a-f]*) lock_token="" lock_error="no random token for the lock $lock_name (/dev/urandom)"; return 1 ;;
  esac
  lock_host="$(uname -n 2>/dev/null | tr -cd 'A-Za-z0-9.-')"
  lock_mine="$1${2:+ PROFILE=$2} on ${lock_host:-unknown}, pid $$, since $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  printf '{"apiVersion":"v1","kind":"ConfigMap","metadata":{"name":"%s"},"data":{"token":"%s","holder":"%s"}}' \
    "$lock_name" "$lock_token" "$lock_mine" | kc create -f - > /dev/null 2>&1 && return 0
  if lock_read && [ -n "$lock_held_token" ] && [ "$lock_held_token" != "$lock_token" ]; then
    # Another made it first, so this creation made nothing: there is nothing to release.
    lock_token=""
    lock_error="the lock $lock_name is held by $lock_holder: make generate and make open run one at a time; $(lock_message)"
  else
    lock_error="the lock $lock_name could not be made"
  fi
  return 1
}

lock_release() {
  [ -n "$lock_token" ] || return 0
  lock_read || return 1
  [ -n "$lock_held_token" ] || return 0
  if [ "$lock_held_token" != "$lock_token" ]; then
    printf '%s: the lock %s is held by %s now, not by this run: it is left as it is\n' "$DEMO_DATA_TOOL" "$lock_name" "$lock_holder" >&2
    return 0
  fi
  kc delete configmap "$lock_name" --ignore-not-found > /dev/null
}

lock_is() {
  lock_read || { lock_error="cannot read the lock $lock_name"; return 1; }
  if [ -z "$lock_held_token" ]; then
    lock_error="there is no lock $lock_name, but DEMO_DATA_LOCK_TOKEN is set (make generate gives it to the make open it runs): unset it to run make open by hand"
    return 1
  fi
  [ "$lock_held_token" = "$1" ] && return 0
  lock_error="the lock $lock_name is held by $lock_holder, not by the make generate of DEMO_DATA_LOCK_TOKEN (make generate gives it to the make open it runs); $(lock_message)"
  return 1
}
