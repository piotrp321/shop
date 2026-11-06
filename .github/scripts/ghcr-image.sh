#!/bin/sh
# Prebuilt CI images in GitHub Container Registry, tagged by the content they are built from.
#   ghcr-image.sh key <path>...                 prints a 16-hex key of the tracked files under the paths (content,
#                                               mode and path), so the tag changes exactly when the image inputs do
#   ghcr-image.sh pull <ref> <local-tag>...     pulls <ref> and tags it with every local name; exits 1 when the
#                                               registry does not have it (the job builds it then)
#   ghcr-image.sh push <ref>                    pushes <ref>; a failure (no permission, quota) only warns, the job
#                                               keeps the image it built
# Any other failure, and a usage error, exits 2.
set -u

usage() {
    echo "usage: ghcr-image.sh key <path>... | pull <ref> <local-tag>... | push <ref>" >&2
    exit 2
}

[ $# -ge 1 ] || usage
action="$1"
shift

case "$action" in
    key)
        [ $# -ge 1 ] || usage
        # Sorted by path; `git ls-files -s` lists mode, blob hash and path of each tracked file.
        files="$(git ls-files -s -- "$@")" || exit 2
        if [ -z "$files" ]; then
            echo "ghcr-image.sh: no tracked file under: $*" >&2
            exit 2
        fi
        printf '%s\n' "$files" | sort -k 4 | git hash-object --stdin | cut -c1-16
        ;;
    pull)
        [ $# -ge 2 ] || usage
        ref="$1"
        shift
        if ! docker pull --quiet "$ref" > /dev/null 2>&1; then
            echo "ghcr-image.sh: $ref is not in the registry"
            exit 1
        fi
        for tag in "$@"; do
            docker tag "$ref" "$tag" || exit 2
        done
        echo "ghcr-image.sh: pulled $ref as $*"
        ;;
    push)
        [ $# -eq 1 ] || usage
        if ! docker push --quiet "$1"; then
            echo "::warning::ghcr-image.sh: pushing $1 failed; the next run builds the image again"
        fi
        ;;
    *)
        usage
        ;;
esac
