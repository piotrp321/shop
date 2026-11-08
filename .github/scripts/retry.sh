#!/bin/sh
# Runs a command and runs it again when it fails, for steps that depend on the network (a download that fails with
# a transient error).
#   retry.sh <attempts> <delay_seconds> <command> [<arg>...]
# The command runs at most <attempts> times in total, with <delay_seconds> between the runs. The exit status is 0 after
# the first success, otherwise the status of the last attempt. Invalid arguments exit with status 2 and run nothing.
set -u

usage() {
    echo "usage: retry.sh <attempts> <delay_seconds> <command> [<arg>...]" >&2
    echo "retry.sh: $1" >&2
    exit 2
}

[ $# -ge 3 ] || usage "expected attempts, delay and a command"
attempts="$1"
delay="$2"
shift 2
case "$attempts" in
    '' | *[!0-9]* | 0 | 0[0-9]*) usage "attempts must be a positive integer, got '$attempts'" ;;
esac
case "$delay" in
    '' | *[!0-9]*) usage "delay must be a non-negative integer, got '$delay'" ;;
esac

attempt=1
while true; do
    "$@"
    status=$?
    [ "$status" -ne 0 ] || exit 0
    echo "retry.sh: attempt $attempt of $attempts failed with status $status" >&2
    [ "$attempt" -lt "$attempts" ] || exit "$status"
    echo "retry.sh: retrying in $delay s" >&2
    sleep "$delay"
    attempt=$((attempt + 1))
done
