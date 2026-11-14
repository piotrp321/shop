#!/bin/sh
# Refuses, without any change, while an AO run or one of its executors is alive: `sh scripts/ao-guard.sh <make target>`. The
# targets that write or mount KUBE_DIR (kubeconfig, rerun, create-admin, restore, backups) call it first in the Makefile
# (docs/features/agent-security/spec.md, R5 and path 14). KUBE_DIR holds the kubeconfig of production, the credentials of the
# cluster administrator, in a directory that the executors and their Docker can read, so it must not exist while one runs.
#
# Alive means a process of the list of the system (`ps`) that is
#   - AO itself: `python -m orchestrator ... run` (the module orchestrator or one below it, the word `run` among the
#     arguments; the same process behind caffeinate or env counts), or
#   - a runner of AO: `python .../orchestrator/runner.py` or `python -m orchestrator.runner` (the executor outlives AO: it
#     starts in a session of its own), or
#   - the wrapper of the executor: a process whose program is `claude-agent`, also as the script of a shell or an interpreter
#     (`sh .../claude-agent`) or behind env, caffeinate and the like.
# Not AO: `python -m orchestrator ... status`, `grep orchestrator`, an editor or `cat` with the name of a file, the claude of
# an interactive session. The list is taken first and read afterwards, so the check never sees its own command line.
#
# Fail closed: no list (ps fails, is missing or prints nothing) is a refusal. There is no switch that turns the check off, and
# it reads nothing from the standard input (the word `tak` of create-admin and restore stays there for their scripts). It is
# not in rerun.sh, create-admin.sh, restore.sh and backups.sh themselves, because smoke.sh runs those directly, also from an
# agent's run of AO, and the smoke test must work as before.
#
# Usage: ao-guard.sh <make target>   Exit codes: 0 nothing of AO runs; 1 refused; 2 no target.
set -eu

target="${1:-}"
if [ -z "$target" ]; then
  echo "ao-guard: usage: ao-guard.sh <make target>" >&2
  exit 2
fi

# printf, not echo: the lines of processes may hold backslashes that echo of some shells would take for escapes.
refuse() {
  printf 'ao-guard: %s: refused, %s\n' "$target" "$1" >&2
  echo "ao-guard: nothing was changed; stop AO or wait until its run ends (and its executors with it), then run the target again." >&2
  echo "ao-guard: the kubeconfig of production must not be readable while an executor runs." >&2
  exit 1
}

# One line per process: pid, parent pid and the whole command line (the lines of an executor are long: only the start is printed).
if ! listing="$(ps -axww -o pid= -o ppid= -o command= 2> /dev/null)"; then
  refuse "could not list the processes (ps failed), so it cannot tell whether AO runs."
fi
[ -n "$listing" ] || refuse "could not list the processes (ps printed nothing), so it cannot tell whether AO runs."

found="$(printf '%s\n' "$listing" | awk '
  function base(path,   b) { b = path; sub(/.*\//, "", b); return b }
  function is_python(token) { return base(token) ~ /^[Pp]ython[0-9.]*$/ }
  # What may stand before the wrapper of the executor on a command line: a shell, an interpreter, env and the like, their
  # options, assignments and the names given to env -u.
  function wrapper_like(i,   token) {
    token = t[i]
    return is_python(token) || base(token) ~ /^(sh|bash|zsh|dash|ksh|env|exec|nohup|caffeinate|command|time|timeout|setsid)$/ \
      || token ~ /^-/ || token ~ /=/ || t[i - 1] == "-u"
  }
  {
    n = split($0, t, " ")
    if (n < 3) next
    python = 0; orchestrator = 0; hit = ""
    for (i = 3; i <= n; i++) {
      if (!python && is_python(t[i])) { python = i; continue }
      if (!python) continue
      if (t[i] == "-m" && i < n) {
        if (t[i + 1] == "orchestrator.runner") hit = "a runner of AO"
        else if (t[i + 1] == "orchestrator" || t[i + 1] ~ /^orchestrator\./) orchestrator = 1
      }
      if (orchestrator && t[i] == "run") hit = "AO (a run)"
      if (t[i] ~ /(^|\/)orchestrator\/runner\.py$/) hit = "a runner of AO"
    }
    for (i = 3; i <= n && hit == ""; i++) {
      if (base(t[i]) != "claude-agent") continue
      ok = 1
      for (j = 3; j < i; j++) if (!wrapper_like(j)) ok = 0
      if (ok) hit = "an executor of AO (claude-agent)"
    }
    if (hit != "") {
      # The command without the directories of its words, cut: the prompt of a task is a long argument of its runner.
      line = $0
      sub(/^ *[0-9]+ +[0-9]+ +/, "", line)
      gsub(/[^ ]*\//, "", line)
      print "pid " t[1] ", " hit ": " substr(line, 1, 110)
    }
  }
')"

if [ -n "$found" ]; then
  first="$(printf '%s\n' "$found" | head -n 3)"
  refuse "AO or an executor of it is running:
$first"
fi
exit 0
