#!/bin/sh
# Tests of infra/scripts/init-env.sh in a temporary copy of the repository layout (the script resolves .env.local
# relative to its own location), so a developer's real .env.local is never touched. Needs openssl, like the script.
# Exit code: 0 all cases passed, 1 a case failed.
set -u

script="$(cd "$(dirname "$0")" && pwd)/init-env.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/infra/scripts"
cp "$script" "$work/infra/scripts/init-env.sh"
target="$work/.env.local"
failures=0

payment_keys='PAYMENT_DEMO_TRANSFER_API_KEY PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET PAYMENT_DEMO_CARD_API_KEY PAYMENT_DEMO_CARD_WEBHOOK_SECRET'

fail() {
  echo "FAIL: $1" >&2
  failures=$((failures + 1))
}

run() {
  out="$(sh "$work/infra/scripts/init-env.sh" 2>&1)"
  status=$?
}

value_of() {
  sed -n "s/^$1=//p" "$target"
}

# 1. A fresh file has every key, the four provider secrets among them, each a non-empty random hex value.
rm -f "$target"
run
[ "$status" -eq 0 ] || fail "creating a fresh file exits $status: $out"
for key in $payment_keys; do
  value="$(value_of "$key")"
  case "$value" in
    '' ) fail "fresh file: $key is missing or empty" ;;
    *[!0-9a-f]* ) fail "fresh file: $key is not hex" ;;
  esac
  [ "${#value}" -ge 32 ] || fail "fresh file: $key is shorter than 16 bytes"
done
[ "$(for key in $payment_keys; do value_of "$key"; done | sort -u | wc -l | tr -d ' ')" -eq 4 ] || fail "fresh file: the four provider secrets are not distinct"

# 2. A second run leaves a complete file as it is.
before="$(cat "$target")"
run
[ "$status" -eq 0 ] || fail "complete file exits $status: $out"
[ "$before" = "$(cat "$target")" ] || fail "complete file was changed"

# 3. A file that lacks only the provider secrets (an older .env.local) gets them appended, the rest stays untouched.
grep -v '^PAYMENT_DEMO_' "$target" > "$target.old"
mv "$target.old" "$target"
old="$(cat "$target")"
run
[ "$status" -eq 0 ] || fail "old file exits $status: $out"
for key in $payment_keys; do
  [ "$(grep -c "^$key=" "$target")" -eq 1 ] || fail "old file: $key is not present exactly once after the run"
  [ -n "$(value_of "$key")" ] || fail "old file: $key is empty"
done
[ "$old" = "$(head -n "$(printf '%s\n' "$old" | wc -l | tr -d ' ')" "$target")" ] || fail "old file: existing lines were changed"
run
[ "$status" -eq 0 ] || fail "second run on the appended file exits $status"

# 4. The same when the old file has no newline at the end: the first appended key must not stick to the last line.
grep -v '^PAYMENT_DEMO_' "$target" | awk 'NR>1{printf "\n"} {printf "%s",$0}' > "$target.old"
mv "$target.old" "$target"
run
[ "$status" -eq 0 ] || fail "file without a final newline exits $status: $out"
for key in $payment_keys; do
  [ "$(grep -c "^$key=" "$target")" -eq 1 ] || fail "no final newline: $key is not on a line of its own"
done
[ "$(grep -c '^STORE_REVALIDATE_SECRET=[0-9a-f]*$' "$target")" -eq 1 ] || fail "no final newline: the last old line was damaged"

# 5. A file that lacks a key that cannot be appended still fails, says which one and is left as it was, also when
# the provider secrets are missing too (nothing is appended on a failing run).
grep -v '^VARNISH_PURGE_SECRET=' "$target" | grep -v '^PAYMENT_DEMO_' > "$target.old"
mv "$target.old" "$target"
before="$(cat "$target")"
run
[ "$status" -ne 0 ] || fail "a missing non-appendable key must fail"
case "$out" in
  *VARNISH_PURGE_SECRET* ) ;;
  * ) fail "the failure does not name the missing key: $out" ;;
esac
[ "$before" = "$(cat "$target")" ] || fail "a failing run changed the file"

# 6. A failing openssl (the tool is broken) fails the run and leaves no file behind, for a fresh file and for an
# old one that needs the provider secrets appended.
mkdir -p "$work/bin"
printf '#!/bin/sh\nexit 1\n' > "$work/bin/openssl"
chmod +x "$work/bin/openssl"
rm -f "$target"
out="$(PATH="$work/bin:$PATH" sh "$work/infra/scripts/init-env.sh" 2>&1)"
status=$?
[ "$status" -ne 0 ] || fail "a failing openssl must fail the creation"
[ ! -e "$target" ] || fail "a failing openssl left a .env.local"
[ -z "$(ls -A "$work" | grep '^\.env\.local\.')" ] || fail "a failing openssl left a temporary file"

run
[ "$status" -eq 0 ] || fail "recreating the file exits $status: $out"
grep -v '^PAYMENT_DEMO_' "$target" > "$target.old"
mv "$target.old" "$target"
before="$(cat "$target")"
out="$(PATH="$work/bin:$PATH" sh "$work/infra/scripts/init-env.sh" 2>&1)"
status=$?
[ "$status" -ne 0 ] || fail "a failing openssl must fail the append"
[ "$before" = "$(cat "$target")" ] || fail "a failing openssl changed the old file"

if [ "$failures" -eq 0 ]; then
  echo "init-env.sh: all cases passed"
  exit 0
fi
echo "init-env.sh: $failures failed" >&2
exit 1
