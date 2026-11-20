#!/bin/sh
# Sourced by the scripts that talk to the server over SSH (bootstrap, kubeconfig, status, reinitialize). The host is
# user@address (HOST of the Makefile); SSH_OPTS may add options for ssh (e.g. "-i ~/.ssh/shop -p 2222"). ssh never asks
# anything (BatchMode) and checks the host key against the known_hosts of the user: connect to a new server once by hand,
# and compare the fingerprint with the one the provider shows, before the first bootstrap.

# check_host <script name> <host>: sets host and address; a host that looks like an option or has unusual characters is
# refused (exit 2) before anything runs.
check_host() {
  host="${2:-}"
  case "$host" in
    "" ) echo "$1: set HOST=<user>@<address> (the server)" >&2; exit 2 ;;
    -* | *[!A-Za-z0-9._@:-]* | @* | *@ | *@*@*) echo "$1: HOST '$host' is not <user>@<address>" >&2; exit 2 ;;
    *@*) ;;
    *) echo "$1: HOST '$host' must be <user>@<address>" >&2; exit 2 ;;
  esac
  address="${host#*@}"
  export host address
}

# remote <command> [input]: runs a command on the server in the shell of the user.
remote() {
  # shellcheck disable=SC2086 # SSH_OPTS is a list of options
  ssh -o BatchMode=yes ${SSH_OPTS:-} -- "$host" "$@"
}

# remote_root <program> [arguments...]: runs a program on the server as root (sudo -n when the user is not root). The
# arguments are fixed words of our scripts (no spaces or quotes).
remote_root() {
  remote "sh -c 'if [ \"\$(id -u)\" -eq 0 ]; then exec \"\$@\"; else exec sudo -n \"\$@\"; fi' sh $*"
}

# worker_console <arguments...>: runs bin/console of the api in the pod of worker-commands (the Deployment that consumes the
# transports of the commands) in a PHP process of its own with 128 MB, so that it does not exceed the limit of the container
# next to the worker. The arguments are fixed words of our scripts or digits checked by the caller. The standard input of ssh
# is closed: it must not take the answer that the script reads afterwards.
worker_console() {
  remote_root k3s kubectl -n shop exec deploy/worker-commands -- php -d memory_limit=128M bin/console "$@" < /dev/null
}

# check_number <script name> <name> <value> <largest>: a positive integer without a leading zero, at most the largest (exit 2).
check_number() {
  case "$3" in
    "" | *[!0-9]* | 0*) echo "$1: $2 '$3' is not a positive integer" >&2; exit 2 ;;
  esac
  if [ "${#3}" -gt "${#4}" ] || { [ "${#3}" -eq "${#4}" ] && [ "$3" -gt "$4" ]; }; then
    echo "$1: $2 '$3' is above $4" >&2
    exit 2
  fi
}
