#!/bin/sh
# Runs a command in the background of a CI job, so that later steps run while it works, and waits for it in a later
# step. A process started by a step keeps running until the end of the job.
#   background.sh start <name> <command> [<arg>...]   starts the command; its output goes to a log file
#   background.sh wait <name>                         waits for it, prints its output and exits with its status
# Files go to $BACKGROUND_DIR (default: $RUNNER_TEMP/background). `wait` gives up after $BACKGROUND_TIMEOUT seconds
# (default 1800) with status 124.
set -u

usage() {
    echo "usage: background.sh start <name> <command> [<arg>...] | background.sh wait <name>" >&2
    exit 2
}

[ $# -ge 2 ] || usage
action="$1"
name="$2"
shift 2
case "$name" in
    '' | *[!A-Za-z0-9_-]*) echo "background.sh: invalid name '$name' (letters, digits, - and _ only)" >&2; exit 2 ;;
esac

dir="${BACKGROUND_DIR:-${RUNNER_TEMP:-/tmp}/background}"
log="$dir/$name.log"
status_file="$dir/$name.status"

case "$action" in
    start)
        [ $# -ge 1 ] || usage
        mkdir -p "$dir" || exit 2
        if [ -e "$log" ]; then
            echo "background.sh: '$name' was already started" >&2
            exit 2
        fi
        : > "$log"
        # The status file is written last and in one rename, so `wait` never reads it half written. Standard streams
        # are detached, so the step that starts the command ends at once.
        (
            "$@" > "$log" 2>&1
            echo $? > "$status_file.tmp"
            mv "$status_file.tmp" "$status_file"
        ) < /dev/null > /dev/null 2>&1 &
        ;;
    wait)
        [ $# -eq 0 ] || usage
        if [ ! -e "$log" ]; then
            echo "background.sh: '$name' was not started" >&2
            exit 2
        fi
        timeout="${BACKGROUND_TIMEOUT:-1800}"
        waited=0
        while [ ! -e "$status_file" ]; do
            if [ "$waited" -ge "$timeout" ]; then
                cat "$log"
                echo "background.sh: '$name' did not finish within $timeout s" >&2
                exit 124
            fi
            sleep 1
            waited=$((waited + 1))
        done
        cat "$log"
        status="$(cat "$status_file")"
        [ "$status" -eq 0 ] || echo "background.sh: '$name' failed with status $status" >&2
        exit "$status"
        ;;
    *)
        usage
        ;;
esac
