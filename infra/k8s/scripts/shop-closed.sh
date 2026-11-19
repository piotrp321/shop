#!/bin/sh
# shellcheck disable=SC2154 # usage is defined by the script that sources this file
# Sourced by generate.sh and open.sh after tools.sh (docs/features/demo-scale/spec.md): what both read before the cluster, the cluster
# through kubectl (KUBE_DIR, KUBE_NETWORK) in the namespace $namespace, and the probe of the public addresses. The caller defines usage
# <message> (exit 2).
#
#   read_environment            KUBE_DIR, KUBE_NETWORK, KUBE_NAMESPACE, GENERATE_PROBE_URL, GENERATE_PROBE_HOST and the seconds of
#                               GENERATE_CLOSE_WAIT (120), GENERATE_OPEN_WAIT (300), GENERATE_POLL (30), GENERATE_PROBE_POLL (5)
#   kc <arguments>              kubectl in the namespace
#   active_jobs                 the Jobs of the namespace that run (neither Complete nor Failed), separated by spaces
#   probe <closed|open> <s>     0 once every address says <state> in one round, 1 when <s> seconds passed first (probe_report: the
#                               last round). 502, 503 and 504 are closed, a code below 500 is open; any other code and no answer are
#                               a failed reading, tried again, never closed or open.

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
    if not {c.get("type") for c in (j.get("status", {}).get("conditions") or []) if c.get("status") == "True"} & {"Complete", "Failed"}))'
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
