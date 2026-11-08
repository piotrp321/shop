#!/bin/sh
# Tests of deploy-ref.sh on throwaway git repositories: a bare repository stands for the remote. The SSH path (key file,
# host keys) runs against a fake `ssh` on PATH that logs its arguments and the key file it was given, then fails.
set -u

here="$(cd "$(dirname "$0")" && pwd)"
script="$here/deploy-ref.sh"
generator="$here/../../infra/k8s/scripts/images-values.sh"
values_path=infra/k8s/environments/prod/values-images.yaml
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
failures=0

ok() { echo "ok   $1"; }
fail() { echo "FAIL $1: $2"; failures=$((failures + 1)); }

secret="TOP-SECRET-KEY-MATERIAL-$$"

# No identity anywhere: the script must bring its own for the snapshot commits.
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null
unset GIT_AUTHOR_NAME GIT_AUTHOR_EMAIL GIT_COMMITTER_NAME GIT_COMMITTER_EMAIL

git init -q --bare "$tmp/remote.git"
repo="$tmp/repo"
git init -q "$repo"
git -C "$repo" checkout -q -b main
commit() {
  mkdir -p "$repo/infra/k8s/environments/prod"
  echo "$1" > "$repo/file"
  git -C "$repo" add file
  git -C "$repo" -c user.name=Dev -c user.email=dev@example.test commit -qm "$1"
  git -C "$repo" rev-parse HEAD
}
c1="$(commit one)"
c2="$(commit two)"
c3="$(commit three)"
git -C "$repo" checkout -q -b side "$c1"
side="$(commit side)"
git -C "$repo" checkout -q main

# values <sha> <letter> <file>: the file the generator writes for the commit, with digests made of the letter.
values() {
  digest="sha256:$(printf '%064d' 0 | tr 0 "$2")"
  DIGEST_API="$digest" DIGEST_STORE="$digest" DIGEST_ADMIN="$digest" DIGEST_ELASTICSEARCH="$digest" sh "$generator" "$1" "$3"
}
values "$c1" a "$tmp/v1.yaml"
values "$c3" b "$tmp/v3.yaml"
values "$c2" c "$tmp/v2.yaml"
values "$side" d "$tmp/vside.yaml"

remote_ref() { git -C "$tmp/remote.git" rev-parse --verify -q "refs/heads/$1" || echo none; }

# run <key> <args...>: runs the script in the repository with DEPLOY_KEY=<key> (unset when empty).
run() {
  key="$1"
  shift
  out="$(cd "$repo" && if [ -n "$key" ]; then DEPLOY_KEY="$key" DEPLOY_SNAPSHOT_FILE="$tmp/snapshot" sh "$script" "$@" 2>&1; else env -u DEPLOY_KEY sh "$script" "$@" 2>&1; fi)"
  status=$?
}

# --- without a key ---------------------------------------------------------------------------------------------

run "" "$c1" "$tmp/remote.git" "$tmp/v1.yaml"
if [ "$status" -eq 0 ] && printf '%s' "$out" | grep -q '^::warning::' && [ "$(remote_ref deploy)" = none ] && [ "$(remote_ref deploy-images)" = none ]; then
  ok "no key: a warning, exit 0 and neither branch is created"; else
  fail "no key" "status $status, deploy $(remote_ref deploy), deploy-images $(remote_ref deploy-images), out '$out'"; fi

# --- the first run creates both branches -----------------------------------------------------------------------

run "$secret" "$c1" "$tmp/remote.git" "$tmp/v1.yaml"
snap1="$(remote_ref deploy-images)"
if [ "$status" -eq 0 ] && [ "$(remote_ref deploy)" = "$c1" ] && [ "$snap1" != none ]; then ok "the first run creates deploy at the commit and deploy-images"; else
  fail "create" "status $status, deploy $(remote_ref deploy), deploy-images $snap1, out '$out'"; fi
[ "$(cat "$tmp/snapshot" 2>/dev/null)" = "$snap1" ] && ok "the snapshot SHA is written to DEPLOY_SNAPSHOT_FILE" || fail "snapshot file" "$(cat "$tmp/snapshot" 2>&1)"

# parents_of <commit>: the parents, space separated
parents_of() { git -C "$tmp/remote.git" rev-list --parents -n 1 "$1" | cut -d' ' -f2-; }
[ "$(parents_of "$snap1")" = "$c1" ] && ok "the first snapshot has one parent: the commit of deploy" || fail "first parents" "$(parents_of "$snap1")"

# The tree of the snapshot is the tree of the commit plus the values file, nothing else.
expected_tree() {
  idx="$tmp/index.$$"
  rm -f "$idx"
  GIT_INDEX_FILE="$idx" git -C "$repo" read-tree "$1"
  blob="$(git -C "$repo" hash-object -w "$2")"
  GIT_INDEX_FILE="$idx" git -C "$repo" update-index --add --cacheinfo "100644,$blob,$values_path"
  GIT_INDEX_FILE="$idx" git -C "$repo" write-tree
  rm -f "$idx"
}
if [ "$(git -C "$tmp/remote.git" rev-parse "$snap1^{tree}")" = "$(expected_tree "$c1" "$tmp/v1.yaml")" ]; then
  ok "the tree of the snapshot is the tree of the commit plus values-images.yaml"; else
  fail "tree" "the tree of the snapshot differs"; fi
[ "$(git -C "$tmp/remote.git" show "$snap1:$values_path")" = "$(cat "$tmp/v1.yaml")" ] && ok "values-images.yaml in the snapshot is the given file" || fail "values file" "differs"
[ "$(git -C "$tmp/remote.git" show "$snap1:file")" = one ] && ok "the files of the commit are in the snapshot" || fail "files" "file differs"
message="$(git -C "$tmp/remote.git" log -1 --format=%B "$snap1")"
if printf '%s' "$message" | grep -qF "$c1" && [ "$(printf '%s' "$message" | grep -c 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')" -eq 4 ]; then
  ok "the message has the commit and the four digests"; else
  fail "message" "$message"; fi
[ -n "$(git -C "$tmp/remote.git" log -1 --format=%an "$snap1")" ] && ok "the snapshot has an author although none is configured" || fail "author" "empty"

# --- the next version -------------------------------------------------------------------------------------------

run "$secret" "$c3" "$tmp/remote.git" "$tmp/v3.yaml"
snap3="$(remote_ref deploy-images)"
if [ "$status" -eq 0 ] && [ "$(remote_ref deploy)" = "$c3" ] && [ "$snap3" != "$snap1" ]; then ok "a fast-forward (over two commits) moves deploy and adds a snapshot"; else
  fail "next" "status $status, deploy $(remote_ref deploy), deploy-images $snap3, out '$out'"; fi
[ "$(parents_of "$snap3")" = "$snap1 $c3" ] && ok "the next snapshot has two parents: the previous snapshot, then the commit of deploy" || fail "parents" "$(parents_of "$snap3")"
git -C "$tmp/remote.git" merge-base --is-ancestor "$snap1" "$snap3" && ok "deploy-images only moves forward (the old snapshot is an ancestor)" || fail "ancestor" "no"
[ "$(git -C "$tmp/remote.git" rev-parse "$snap3^{tree}")" = "$(expected_tree "$c3" "$tmp/v3.yaml")" ] && ok "the second tree is the tree of its commit plus its file" || fail "tree 2" "differs"

run "$secret" "$c3" "$tmp/remote.git" "$tmp/v3.yaml"
if [ "$status" -eq 0 ] && [ "$(remote_ref deploy)" = "$c3" ] && [ "$(remote_ref deploy-images)" = "$snap3" ]; then ok "the same commit with the same file again makes no new snapshot"; else
  fail "same commit" "status $status, deploy-images $(remote_ref deploy-images), out '$out'"; fi
[ "$(cat "$tmp/snapshot")" = "$snap3" ] && ok "the existing snapshot is reported on a re-run" || fail "re-run snapshot" "$(cat "$tmp/snapshot")"

# deploy moved by hand to the commit, deploy-images missing its snapshot: a re-run still publishes consistently.
run "$secret" "$c3" "$tmp/remote.git" "$tmp/v3.yaml"

# The same commit with other digests (another run of it built something else) is a new snapshot, not a rewrite.
values "$c3" e "$tmp/v3b.yaml"
run "$secret" "$c3" "$tmp/remote.git" "$tmp/v3b.yaml"
snap3b="$(remote_ref deploy-images)"
if [ "$status" -eq 0 ] && [ "$snap3b" != "$snap3" ] && [ "$(parents_of "$snap3b")" = "$snap3 $c3" ]; then ok "other digests for the same commit are a new snapshot on top"; else
  fail "other digests" "status $status, out '$out'"; fi

# --- what is rejected moves nothing ----------------------------------------------------------------------------

both() { echo "$(remote_ref deploy) $(remote_ref deploy-images)"; }
before="$(both)"

run "$secret" "$side" "$tmp/remote.git" "$tmp/vside.yaml"
if [ "$status" -eq 1 ] && [ "$(both)" = "$before" ]; then ok "a diverged commit (non-fast-forward) is rejected and neither branch moves"; else
  fail "diverged" "status $status, '$(both)' vs '$before', out '$out'"; fi

run "$secret" "$c2" "$tmp/remote.git" "$tmp/v2.yaml"
if [ "$status" -eq 1 ] && [ "$(both)" = "$before" ]; then ok "an older commit (moving back) is rejected and neither branch moves"; else
  fail "older" "status $status, '$(both)' vs '$before', out '$out'"; fi

# The remote rejects deploy-images (a hook), deploy would be fine: the atomic push moves neither.
values "$c1" f "$tmp/v1new.yaml"
git init -q --bare "$tmp/atomic.git"
(cd "$repo" && git push -q "$tmp/atomic.git" "$c1:refs/heads/deploy" 2>/dev/null)
git -C "$tmp/atomic.git" update-ref refs/heads/deploy "$c1"
mkdir -p "$tmp/atomic.git/hooks"
cat > "$tmp/atomic.git/hooks/update" <<'HOOK'
#!/bin/sh
[ "$1" = refs/heads/deploy-images ] && { echo "deploy-images rejected by the test hook" >&2; exit 1; }
exit 0
HOOK
chmod +x "$tmp/atomic.git/hooks/update"
run "$secret" "$c3" "$tmp/atomic.git" "$tmp/v3.yaml"
if [ "$status" -eq 1 ] && [ "$(git -C "$tmp/atomic.git" rev-parse refs/heads/deploy)" = "$c1" ] && ! git -C "$tmp/atomic.git" rev-parse -q --verify refs/heads/deploy-images >/dev/null; then
  ok "deploy-images rejected: deploy does not move either (atomic push)"; else
  fail "atomic" "status $status, deploy $(git -C "$tmp/atomic.git" rev-parse refs/heads/deploy), out '$out'"; fi

# The script never forces.
if [ -f "$script" ] && ! grep -v '^[[:space:]]*#' "$script" | grep -Eq -- '--force|[ "]\+\$?[a-z{]|--mirror|force-with-lease'; then ok "the script has no force option"; else
  fail "force" "the script mentions force"; fi
grep -v '^[[:space:]]*#' "$script" | grep -q -- '--atomic' && ok "the script pushes with --atomic" || fail "atomic flag" "the script does not push with --atomic"

# --- wrong files and arguments change nothing --------------------------------------------------------------------

before="$(both)"
nothing_pushed() { [ "$(both)" = "$before" ]; }

run "$secret" "$c3" "$tmp/remote.git" "$tmp/does-not-exist.yaml"
[ "$status" -eq 2 ] && nothing_pushed && ok "a missing values file is an error, nothing is pushed" || fail "missing file" "status $status, out '$out'"

run "" "$c3" "$tmp/remote.git" "$tmp/does-not-exist.yaml"
[ "$status" -eq 2 ] && ok "a missing values file is an error also without a key" || fail "missing file, no key" "status $status"

run "$secret" "$c3" "$tmp/remote.git" "$tmp/v1.yaml"
[ "$status" -eq 2 ] && nothing_pushed && ok "a file made for another commit is an error, nothing is pushed" || fail "wrong commit" "status $status, out '$out'"

sed 's/^    store: .*/    store: latest/' "$tmp/v3.yaml" > "$tmp/bad-digest.yaml"
run "$secret" "$c3" "$tmp/remote.git" "$tmp/bad-digest.yaml"
[ "$status" -eq 2 ] && nothing_pushed && ok "a file with a malformed digest is an error, nothing is pushed" || fail "bad digest" "status $status, out '$out'"

grep -v 'elasticsearch' "$tmp/v3.yaml" > "$tmp/no-es.yaml"
run "$secret" "$c3" "$tmp/remote.git" "$tmp/no-es.yaml"
[ "$status" -eq 2 ] && nothing_pushed && ok "a file without the digest of elasticsearch is an error, nothing is pushed" || fail "no elasticsearch" "status $status, out '$out'"

{ cat "$tmp/v3.yaml"; echo "extra: true"; } > "$tmp/extra.yaml"
run "$secret" "$c3" "$tmp/remote.git" "$tmp/extra.yaml"
[ "$status" -eq 2 ] && nothing_pushed && ok "a file with anything extra is an error, nothing is pushed" || fail "extra line" "status $status, out '$out'"

: > "$tmp/empty.yaml"
run "$secret" "$c3" "$tmp/remote.git" "$tmp/empty.yaml"
[ "$status" -eq 2 ] && nothing_pushed && ok "an empty values file is an error, nothing is pushed" || fail "empty file" "status $status, out '$out'"

run "$secret"
[ "$status" -eq 2 ] && ok "no arguments is a usage error" || fail "usage" "status $status"

run "$secret" "$c1" "$tmp/remote.git"
[ "$status" -eq 2 ] && ok "no values file is a usage error" || fail "usage values" "status $status"

run "$secret" 0123456789abcdef0123456789abcdef01234567 "$tmp/remote.git" "$tmp/v3.yaml"
[ "$status" -eq 2 ] && nothing_pushed && ok "a commit that does not exist is an error, nothing is pushed" || fail "unknown sha" "status $status, out '$out'"

run "" 0123456789abcdef0123456789abcdef01234567 "$tmp/remote.git" "$tmp/v3.yaml"
[ "$status" -eq 2 ] && ok "a commit that does not exist is an error also without a key" || fail "unknown sha, no key" "status $status"

run "$secret" main "$tmp/remote.git" "$tmp/v3.yaml"
[ "$status" -eq 2 ] && ok "a branch name instead of a commit SHA is an error" || fail "ref instead of sha" "status $status"

run "$secret" "$c3" "$tmp/does-not-exist.git" "$tmp/v3.yaml"
[ "$status" -eq 1 ] && ok "an unreachable remote fails with exit 1" || fail "unreachable remote" "status $status"

# --- the SSH path ----------------------------------------------------------------------------------------------

bin="$tmp/bin"
mkdir -p "$bin"
cat > "$bin/ssh" <<'FAKE'
#!/bin/sh
# Logs the arguments, the mode of the key file and whether it holds the key, and then fails like an unreachable server.
{
  echo "args: $*"
  while [ $# -gt 0 ]; do
    if [ "$1" = -i ]; then
      key_file="$2"
      if [ -n "$(find "$key_file" -perm 600)" ]; then echo "key-mode: 600"; else echo "key-mode: other"; fi
      echo "key-file: $key_file"
      grep -qF -- "$FAKE_KEY" "$key_file" && echo "key-content: yes"
    fi
    shift
  done
} >> "$FAKE_SSH_LOG"
exit 255
FAKE
chmod +x "$bin/ssh"

known="$tmp/known_hosts"
echo "github.com ssh-ed25519 AAAA" > "$known"
export FAKE_KEY="$secret" FAKE_SSH_LOG="$tmp/ssh.log"
: > "$FAKE_SSH_LOG"
before="$(both)"
out="$(cd "$repo" && PATH="$bin:$PATH" DEPLOY_KEY="$secret" DEPLOY_KNOWN_HOSTS="$known" sh "$script" "$c3" ssh://git@example.test/shop.git "$tmp/v3.yaml" 2>&1)"
status=$?
log="$(cat "$FAKE_SSH_LOG")"
[ "$status" -eq 1 ] && ok "a failing SSH remote fails the script with exit 1" || fail "ssh failure" "status $status"
printf '%s' "$log" | grep -q 'IdentitiesOnly=yes' && printf '%s' "$log" | grep -q 'StrictHostKeyChecking=yes' &&
  printf '%s' "$log" | grep -q "UserKnownHostsFile=$known" &&
  ok "ssh uses only the deploy key and checks the host key against the given file" ||
  fail "ssh options" "$log"
printf '%s' "$log" | grep -q 'key-mode: 600' && printf '%s' "$log" | grep -q 'key-content: yes' &&
  ok "the key file holds the key and is private (mode 600) while ssh runs" || fail "key file" "$log"
key_file="$(printf '%s' "$log" | sed -n 's/^key-file: //p' | head -n 1)"
if [ -n "$key_file" ] && [ ! -e "$key_file" ]; then ok "the key file is removed afterwards"; else
  fail "key file removal" "'$key_file' still exists"; fi
if ! printf '%s' "$out" | grep -qF -- "$secret"; then ok "the key is not printed"; else fail "key printed" "$out"; fi
[ "$(both)" = "$before" ] && ok "the failing SSH remote left the branches as they were" || fail "ssh state" "changed"

run "$secret" "$c3" "$tmp/does-not-exist.git" "$tmp/v3.yaml"
if ! printf '%s' "$out" | grep -qF -- "$secret"; then ok "the key is not printed on a failed push either"; else
  fail "key printed on failure" "$out"; fi

[ "$failures" -eq 0 ] || { echo "$failures failure(s)"; exit 1; }
echo "all passed"
