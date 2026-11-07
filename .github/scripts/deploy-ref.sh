#!/bin/sh
# Moves the deployment branch to a commit of main by fast-forward, with the deploy key (D4 of docs/features/deploy):
#
#   DEPLOY_KEY=<private SSH key> deploy-ref.sh <commit sha> <remote url> [branch, default deploy]
#
# The push never forces: the branch is created if it is missing, moved forward if the commit descends from it, and left
# as it is (exit 1) if the commit is behind or diverged. Without DEPLOY_KEY (the key is not set up yet) it only prints
# a warning and exits 0. DEPLOY_KNOWN_HOSTS is a known_hosts file to check the host key against (SSH remotes); the
# default is the known_hosts of the user. The key is written to a private temporary file, removed on every exit, and
# never printed. Run it from a clone with the full history (the remote may be ahead of a shallow one).
# Exit codes: 0 pushed or nothing to push or no key, 1 the push failed, 2 wrong arguments.
set -u

fail() {
    echo "deploy-ref: $1" >&2
    exit "${2:-2}"
}

[ $# -ge 2 ] && [ $# -le 3 ] || fail "usage: deploy-ref.sh <commit sha> <remote url> [branch]"
sha="$1"
remote="$2"
branch="${3:-deploy}"

# A full SHA, not a name: what is pushed is exactly the commit that was built and tested.
case "$sha" in
    *[!0-9a-f]* | "") fail "'$sha' is not a full lowercase commit SHA" ;;
esac
[ "${#sha}" -eq 40 ] || fail "'$sha' is not a full commit SHA (40 hex digits)"
git cat-file -e "$sha^{commit}" 2>/dev/null || fail "commit $sha is not in this repository"
git check-ref-format --branch "$branch" >/dev/null 2>&1 || fail "'$branch' is not a valid branch name"

if [ -z "${DEPLOY_KEY:-}" ]; then
    echo "::warning::deploy-ref: the secret DEPLOY_KEY is not set, so $branch was not moved to $sha (set up the deploy key first)"
    exit 0
fi

work="$(umask 077 && mktemp -d)" || fail "cannot create a temporary directory" 1
trap 'rm -rf "$work"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
(umask 077 && printf '%s\n' "$DEPLOY_KEY" > "$work/key") || fail "cannot write the key file" 1

ssh_command="ssh -i $work/key -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes"
[ -z "${DEPLOY_KNOWN_HOSTS:-}" ] || ssh_command="$ssh_command -o UserKnownHostsFile=$DEPLOY_KNOWN_HOSTS"

# No --force and no leading +: git rejects what is not a fast-forward ("fetch first" or "non-fast-forward").
if GIT_SSH_COMMAND="$ssh_command" git push "$remote" "$sha:refs/heads/$branch"; then
    echo "deploy-ref: $branch is at $sha"
else
    fail "could not move $branch to $sha by fast-forward (a commit that is behind or diverged is rejected, never forced)" 1
fi
