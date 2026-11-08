#!/bin/sh
# Publishes a version for Argo CD with the deploy key (D4 of docs/features/deploy):
#
#   DEPLOY_KEY=<private SSH key> deploy-ref.sh <commit sha> <remote url> <images values file>
#
# Two branches move in ONE atomic push, so either both move or neither does:
#   deploy         fast-forward to the commit of main (the approved code);
#   deploy-images  a new snapshot commit on top: the tree of the commit plus the images values file
#                  (infra/k8s/environments/prod/values-images.yaml: the revision and the digests of the images built for it),
#                  with the parents <the previous deploy-images, if any> and <the commit>. Argo CD tracks this branch, so
#                  one revision carries the chart and the images that were tested together. Nothing is merged or forced.
# The values file must be exactly what infra/k8s/scripts/images-values.sh writes for the commit and the digests it
# contains; anything else is an error before anything is pushed. A re-run of the same commit with the same file adds
# no snapshot (the existing one is used).
#
# The push never forces: git rejects what is not a fast-forward ("fetch first" or "non-fast-forward") and with --atomic
# that rejects both branches. Without DEPLOY_KEY (the key is not set up yet) it only prints a warning and exits 0.
# DEPLOY_KNOWN_HOSTS is a known_hosts file to check the host key against (SSH remotes); the default is the known_hosts of
# the user. The key is written to a private temporary file, removed on every exit, and never printed. DEPLOY_SNAPSHOT_FILE,
# when set, receives the SHA of the deploy-images snapshot. Run it from a clone with the full history.
# Exit codes: 0 pushed or nothing to push or no key, 1 the fetch or the push failed, 2 wrong arguments or values file.
set -u

fail() {
    echo "deploy-ref: $1" >&2
    exit "${2:-2}"
}

here="$(cd "$(dirname "$0")" && pwd)"
generator="$here/../../infra/k8s/scripts/images-values.sh"
values_path=infra/k8s/environments/prod/values-images.yaml

[ $# -eq 3 ] || fail "usage: deploy-ref.sh <commit sha> <remote url> <images values file>"
sha="$1"
remote="$2"
values="$3"

# A full SHA, not a name: what is pushed is exactly the commit that was built and tested.
case "$sha" in
    *[!0-9a-f]* | "") fail "'$sha' is not a full lowercase commit SHA" ;;
esac
[ "${#sha}" -eq 40 ] || fail "'$sha' is not a full commit SHA (40 hex digits)"
git cat-file -e "$sha^{commit}" 2>/dev/null || fail "commit $sha is not in this repository"

# The values file is regenerated from its own digests and compared byte for byte: a file for another commit, a malformed
# digest, a missing image or an extra line all differ.
[ -f "$values" ] || fail "the values file '$values' does not exist"
digest_of() { sed -n "s/^    $1: \(sha256:[0-9a-f]\{64\}\)\$/\1/p" "$values"; }
work="$(umask 077 && mktemp -d)" || fail "cannot create a temporary directory" 1
trap 'rm -rf "$work"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
DIGEST_API="$(digest_of api)" DIGEST_STORE="$(digest_of store)" DIGEST_ADMIN="$(digest_of admin)" \
    DIGEST_ELASTICSEARCH="$(digest_of elasticsearch)" sh "$generator" "$sha" "$work/expected.yaml" 2> "$work/generator-error" ||
    fail "the values file '$values' is not valid: $(cat "$work/generator-error")"
cmp -s "$values" "$work/expected.yaml" || fail "the values file '$values' is not what images-values.sh writes for $sha and its digests"

if [ -z "${DEPLOY_KEY:-}" ]; then
    echo "::warning::deploy-ref: the secret DEPLOY_KEY is not set, so deploy and deploy-images were not moved to $sha (set up the deploy key first)"
    exit 0
fi

(umask 077 && printf '%s\n' "$DEPLOY_KEY" > "$work/key") || fail "cannot write the key file" 1

ssh_command="ssh -i $work/key -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes"
[ -z "${DEPLOY_KNOWN_HOSTS:-}" ] || ssh_command="$ssh_command -o UserKnownHostsFile=$DEPLOY_KNOWN_HOSTS"
GIT_SSH_COMMAND="$ssh_command"
export GIT_SSH_COMMAND

# The tip of deploy-images, if the branch exists (a lookup that fails is an error, not "no branch").
listing="$(git ls-remote "$remote" refs/heads/deploy-images)" || fail "could not look up deploy-images on the remote" 1
previous=""
if [ -n "$listing" ]; then
    git fetch --quiet --no-tags "$remote" refs/heads/deploy-images || fail "could not fetch deploy-images from the remote" 1
    previous="$(git rev-parse --verify "FETCH_HEAD^{commit}")" || fail "deploy-images on the remote is not a commit" 1
fi

# The tree: the tree of the commit plus the values file, built in a temporary index (the working tree is not touched).
index="$work/index"
blob="$(git hash-object -w "$values")" || fail "cannot store the values file" 1
tree="$(GIT_INDEX_FILE="$index" git read-tree "$sha" &&
    GIT_INDEX_FILE="$index" git update-index --add --cacheinfo "100644,$blob,$values_path" &&
    GIT_INDEX_FILE="$index" git write-tree)" || fail "cannot build the tree of the snapshot" 1

if [ -n "$previous" ] && [ "$(git rev-parse "$previous^{tree}")" = "$tree" ]; then
    snapshot="$previous"
    echo "deploy-ref: deploy-images already has this snapshot ($snapshot)"
else
    message="deploy-images: $sha

source: $sha
image.api: $(digest_of api)
image.store: $(digest_of store)
image.admin: $(digest_of admin)
image.elasticsearch: $(digest_of elasticsearch)"
    parents="-p $sha"
    [ -z "$previous" ] || parents="-p $previous -p $sha"
    # No identity is configured on a CI runner: the snapshot is committed by the workflow itself.
    bot_email='41898282+github-actions[bot]@users.noreply.github.com'
    # shellcheck disable=SC2086 # $parents is a list of arguments
    snapshot="$(GIT_AUTHOR_NAME=github-actions GIT_AUTHOR_EMAIL="$bot_email" \
        GIT_COMMITTER_NAME=github-actions GIT_COMMITTER_EMAIL="$bot_email" \
        git commit-tree "$tree" $parents -m "$message")" || fail "cannot create the snapshot commit" 1
fi

# No --force and no leading +: git rejects what is not a fast-forward, and --atomic then moves neither branch.
if git push --atomic "$remote" "$sha:refs/heads/deploy" "$snapshot:refs/heads/deploy-images"; then
    echo "deploy-ref: deploy is at $sha, deploy-images is at $snapshot"
    [ -z "${DEPLOY_SNAPSHOT_FILE:-}" ] || printf '%s\n' "$snapshot" > "$DEPLOY_SNAPSHOT_FILE"
else
    fail "could not move deploy to $sha and deploy-images to $snapshot (a commit that is behind or diverged is rejected, never forced; neither branch moved)" 1
fi
