#!/bin/sh
# Tests of deploy-ref.sh on throwaway git repositories: a bare repository stands for the remote. The SSH path (key file,
# host keys) runs against a fake `ssh` on PATH that logs its arguments and the key file it was given, then fails.
set -u

script="$(cd "$(dirname "$0")" && pwd)/deploy-ref.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
failures=0

ok() { echo "ok   $1"; }
fail() { echo "FAIL $1: $2"; failures=$((failures + 1)); }

secret="TOP-SECRET-KEY-MATERIAL-$$"

git init -q --bare "$tmp/remote.git"
repo="$tmp/repo"
git init -q "$repo"
git -C "$repo" config user.email ci@example.test
git -C "$repo" config user.name CI
git -C "$repo" checkout -q -b main
commit() { echo "$1" > "$repo/file"; git -C "$repo" add file; git -C "$repo" commit -qm "$1"; git -C "$repo" rev-parse HEAD; }
c1="$(commit one)"
c2="$(commit two)"
c3="$(commit three)"
git -C "$repo" checkout -q -b side "$c1"
side="$(commit side)"
git -C "$repo" checkout -q main

remote_ref() { git -C "$tmp/remote.git" rev-parse --verify -q refs/heads/deploy || echo none; }

# run <key> <args...>: runs the script in the repository with DEPLOY_KEY=<key> (unset when empty).
run() {
  key="$1"
  shift
  out="$(cd "$repo" && if [ -n "$key" ]; then DEPLOY_KEY="$key" sh "$script" "$@" 2>&1; else env -u DEPLOY_KEY sh "$script" "$@" 2>&1; fi)"
  status=$?
}

# --- without a key ---------------------------------------------------------------------------------------------

run "" "$c1" "$tmp/remote.git"
if [ "$status" -eq 0 ] && printf '%s' "$out" | grep -q '^::warning::' && [ "$(remote_ref)" = none ]; then
  ok "no key: a warning, exit 0 and the branch is not created"; else
  fail "no key" "status $status, ref $(remote_ref), out '$out'"; fi

# --- the branch is created, then moved only forward ------------------------------------------------------------

run "$secret" "$c1" "$tmp/remote.git"
if [ "$status" -eq 0 ] && [ "$(remote_ref)" = "$c1" ]; then ok "the first push creates the branch"; else
  fail "create" "status $status, ref $(remote_ref), out '$out'"; fi

run "$secret" "$c3" "$tmp/remote.git"
if [ "$status" -eq 0 ] && [ "$(remote_ref)" = "$c3" ]; then ok "a fast-forward (over two commits) is accepted"; else
  fail "fast-forward" "status $status, ref $(remote_ref), out '$out'"; fi

run "$secret" "$c3" "$tmp/remote.git"
if [ "$status" -eq 0 ] && [ "$(remote_ref)" = "$c3" ]; then ok "the same commit again is a no-op"; else
  fail "same commit" "status $status, ref $(remote_ref), out '$out'"; fi

run "$secret" "$side" "$tmp/remote.git"
if [ "$status" -eq 1 ] && [ "$(remote_ref)" = "$c3" ]; then ok "a diverged commit (non-fast-forward) is rejected, the ref stays"; else
  fail "diverged" "status $status, ref $(remote_ref), out '$out'"; fi

run "$secret" "$c2" "$tmp/remote.git"
if [ "$status" -eq 1 ] && [ "$(remote_ref)" = "$c3" ]; then ok "an older commit (moving back) is rejected, the ref stays"; else
  fail "older" "status $status, ref $(remote_ref), out '$out'"; fi

# The remote itself accepts a forced update (default configuration), so the rejection above came from the client:
# the script never forces.
if [ -f "$script" ] && ! grep -v '^[[:space:]]*#' "$script" | grep -Eq -- '--force|[ "]\+\$?[a-z{]|--mirror'; then ok "the script has no force option"; else
  fail "force" "the script mentions force"; fi

# --- the branch name -------------------------------------------------------------------------------------------

run "$secret" "$c1" "$tmp/remote.git" other
if [ "$status" -eq 0 ] && [ "$(git -C "$tmp/remote.git" rev-parse --verify -q refs/heads/other)" = "$c1" ]; then
  ok "a branch name can be given as the third argument"; else
  fail "branch" "status $status, out '$out'"; fi

# --- arguments -------------------------------------------------------------------------------------------------

run "$secret"
[ "$status" -eq 2 ] && ok "no arguments is a usage error" || fail "usage" "status $status"

run "$secret" "$c1"
[ "$status" -eq 2 ] && ok "no remote is a usage error" || fail "usage remote" "status $status"

before="$(remote_ref)"
run "$secret" 0123456789abcdef0123456789abcdef01234567 "$tmp/remote.git"
if [ "$status" -eq 2 ] && [ "$(remote_ref)" = "$before" ]; then ok "a commit that does not exist is an error, the ref stays"; else
  fail "unknown sha" "status $status, out '$out'"; fi

run "" 0123456789abcdef0123456789abcdef01234567 "$tmp/remote.git"
[ "$status" -eq 2 ] && ok "a commit that does not exist is an error also without a key" || fail "unknown sha, no key" "status $status"

run "$secret" main "$tmp/remote.git"
[ "$status" -eq 2 ] && ok "a branch name instead of a commit SHA is an error" || fail "ref instead of sha" "status $status"

run "$secret" "$c1" "$tmp/does-not-exist.git"
[ "$status" -eq 1 ] && ok "an unreachable remote fails with exit 1" || fail "unreachable remote" "status $status"

run "$secret" "$c1" "$tmp/remote.git" "bad branch"
[ "$status" -eq 2 ] && ok "an invalid branch name is a usage error" || fail "bad branch" "status $status"

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
out="$(cd "$repo" && PATH="$bin:$PATH" DEPLOY_KEY="$secret" DEPLOY_KNOWN_HOSTS="$known" sh "$script" "$c1" ssh://git@example.test/shop.git 2>&1)"
status=$?
log="$(cat "$FAKE_SSH_LOG")"
[ "$status" -eq 1 ] && ok "a failing SSH push fails the script with exit 1" || fail "ssh failure" "status $status"
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

run "$secret" "$c1" "$tmp/does-not-exist.git"
if ! printf '%s' "$out" | grep -qF -- "$secret"; then ok "the key is not printed on a failed push either"; else
  fail "key printed on failure" "$out"; fi

[ "$failures" -eq 0 ] || { echo "$failures failure(s)"; exit 1; }
echo "all passed"
