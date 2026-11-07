#!/bin/sh
# Tests of ghcr-image.sh. `key` runs on a throwaway git repository; `pull` and `push` run against a fake `docker`
# on PATH that logs its arguments and fails the commands named in $FAKE_DOCKER_FAIL.
set -u

script="$(cd "$(dirname "$0")" && pwd)/ghcr-image.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
failures=0

ok() { echo "ok   $1"; }
fail() { echo "FAIL $1: $2"; failures=$((failures + 1)); }

# --- key ---------------------------------------------------------------------------------------------------------

repo="$tmp/repo"
git init -q "$repo"
git -C "$repo" config user.email ci@example.test
git -C "$repo" config user.name CI
mkdir -p "$repo/app/docker/conf" "$repo/other"
echo 'FROM alpine' > "$repo/app/Dockerfile"
echo 'a=1' > "$repo/app/docker/conf/a.ini"
echo '{}' > "$repo/app/package-lock.json"
echo 'x' > "$repo/other/file"
git -C "$repo" add -A
git -C "$repo" commit -qm base

key() { (cd "$repo" && sh "$script" key "$@" 2>"$tmp/stderr"); }

k1="$(key app/Dockerfile app/docker app/package-lock.json)"; s1=$?
if [ "$s1" -eq 0 ] && printf '%s' "$k1" | grep -Eq '^[0-9a-f]{16}$'; then ok "key is 16 hex characters"; else
    fail "key format" "status $s1, key '$k1'"; fi

k2="$(key app/Dockerfile app/docker app/package-lock.json)"
[ "$k1" = "$k2" ] && ok "key is stable" || fail "key is stable" "'$k1' then '$k2'"

k3="$(key app/package-lock.json app/docker app/Dockerfile)"
[ "$k1" = "$k3" ] && ok "key ignores the order of the paths" || fail "path order" "'$k1' vs '$k3'"

echo 'x2' > "$repo/other/file"
git -C "$repo" commit -qam "other change"
k4="$(key app/Dockerfile app/docker app/package-lock.json)"
[ "$k1" = "$k4" ] && ok "a file outside the paths does not change the key" || fail "outside file" "'$k1' vs '$k4'"

echo 'a=2' > "$repo/app/docker/conf/a.ini"
git -C "$repo" commit -qam "conf change"
k5="$(key app/Dockerfile app/docker app/package-lock.json)"
[ "$k1" != "$k5" ] && ok "a file inside a directory changes the key" || fail "file in directory" "key stayed '$k5'"

git -C "$repo" mv app/docker/conf/a.ini app/docker/conf/b.ini
git -C "$repo" commit -qm rename
k6="$(key app/Dockerfile app/docker app/package-lock.json)"
[ "$k5" != "$k6" ] && ok "a renamed file changes the key" || fail "rename" "key stayed '$k6'"

out="$(key app/missing)"; s=$?
[ "$s" -eq 2 ] && grep -q "no tracked file" "$tmp/stderr" && ok "a path with no tracked file fails" ||
    fail "missing path" "status $s, stderr $(cat "$tmp/stderr")"

out="$(key)"; s=$?
[ "$s" -eq 2 ] && ok "key without paths is a usage error" || fail "key usage" "status $s"

# --- pull and push with a fake docker -----------------------------------------------------------------------------

bin="$tmp/bin"
mkdir -p "$bin"
cat > "$bin/docker" <<'EOF'
#!/bin/sh
echo "$*" >> "$FAKE_DOCKER_LOG"
case " ${FAKE_DOCKER_FAIL:-} " in *" $1 "*) echo "fake docker: $1 failed" >&2; exit 1 ;; esac
exit 0
EOF
chmod +x "$bin/docker"

# run <fail list> <args...>: runs the script with the fake docker; sets out, status and log.
run() {
    : > "$tmp/docker.log"
    out="$(PATH="$bin:$PATH" FAKE_DOCKER_LOG="$tmp/docker.log" FAKE_DOCKER_FAIL="$1" sh -c 'shift; sh "$0" "$@"' "$script" "$@" 2>&1)"
    status=$?
    log="$(cat "$tmp/docker.log")"
}

ref=ghcr.io/owner/shop-api:0123456789abcdef

run "" pull "$ref" shop-api shop-worker
expected="pull --quiet $ref
tag $ref shop-api
tag $ref shop-worker"
[ "$status" -eq 0 ] && [ "$log" = "$expected" ] && ok "pull tags every local name" ||
    fail "pull" "status $status, docker calls: $log"

run "pull" pull "$ref" shop-api
[ "$status" -eq 1 ] && [ "$log" = "pull --quiet $ref" ] && printf '%s' "$out" | grep -q "not in the registry" &&
    ok "a missing image exits 1 without tagging" || fail "pull missing" "status $status, out '$out', calls: $log"

run "tag" pull "$ref" shop-api
[ "$status" -eq 2 ] && ok "a failed tag is an error" || fail "tag failure" "status $status"

run "" pull "$ref"
[ "$status" -eq 2 ] && ok "pull without a local name is a usage error" || fail "pull usage" "status $status"

run "" push "$ref"
[ "$status" -eq 0 ] && [ "$log" = "push --quiet $ref" ] && ok "push pushes the reference" ||
    fail "push" "status $status, calls: $log"

run "push" push "$ref"
[ "$status" -eq 0 ] && printf '%s' "$out" | grep -q '::warning::' && ok "a failed push only warns" ||
    fail "push failure" "status $status, out '$out'"

run "" push
[ "$status" -eq 2 ] && ok "push without a reference is a usage error" || fail "push usage" "status $status"

run "" build "$ref"
[ "$status" -eq 2 ] && ok "an unknown action is a usage error" || fail "unknown action" "status $status"

[ "$failures" -eq 0 ] || { echo "$failures failure(s)"; exit 1; }
echo "all passed"
