#!/bin/sh
# Encrypts the secrets of the environment with the certificate of the Sealed Secrets controller, so that they can be
# committed (D5 of docs/features/deploy): `make -C infra/k8s seal-secrets [WHAT="shop brevo tls backup"]`. kubeseal runs in a
# container (tools.sh); no cluster is needed, only the certificate in the repository.
#
#   shop   environments/prod/secrets/shop-secrets.yaml: the keys of infra/scripts/init-env.sh (the lists `secrets` and
#          `appendable`, read from the file). Only the keys the file lacks are added, with random values; the sealed
#          values that are there are never touched (they could not be recreated anyway: the data on the volumes was
#          initialized with them).
#   brevo  shop-brevo-smtp.yaml: BREVO_SMTP_LOGIN and BREVO_SMTP_PASSWORD of Brevo SMTP, asked for on the terminal.
#   tls    shop-origin-tls.yaml (kubernetes.io/tls): the Cloudflare Origin CA certificate and private key, pasted on
#          the terminal.
#   backup shop-backup.yaml: RESTIC_PASSWORD (the password of the restic repository of the backups), BACKUP_S3_ACCESS_KEY_ID
#          and BACKUP_S3_SECRET_ACCESS_KEY (the key of its S3 bucket), asked for on the terminal.
# Without arguments (WHAT) all four are processed. brevo, tls and backup are created once: when their file exists nothing
# is asked and nothing changes (a new password would make the earlier backups unreadable).
#
# External values are read from the terminal (/dev/tty) with the echo off: never from arguments or from the environment,
# never written anywhere in plain text and never printed. Each value is passed to kubeseal on its standard input.
# Everything is sealed into a temporary directory first and moved into place only when all of it succeeded: a missing
# certificate, an empty value, a failing kubeseal or an unexpected format of an existing file stop the script with an
# error and write nothing.
#
# SEAL_TTY replaces /dev/tty (the tests feed the values through a file; the echo is switched off only on a terminal).
# Exit codes: 0 done or nothing to do; 1 failure; 2 wrong arguments or no certificate.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"

namespace=shop
cert_rel=infra/k8s/environments/prod/sealed-secrets.pem
secrets_rel=infra/k8s/environments/prod/secrets
cert="$TOOLS_REPO/$cert_rel"
out_dir="$TOOLS_REPO/$secrets_rel"
init_env="$TOOLS_REPO/infra/scripts/init-env.sh"
tty_in="${SEAL_TTY:-/dev/tty}"

fail() {
  echo "seal-secrets: $1" >&2
  exit "${2:-1}"
}

what="$*"
[ -n "$what" ] || what="shop brevo tls backup"
for item in $what; do
  case "$item" in
    shop | brevo | tls | backup) ;;
    *) fail "unknown secret '$item' (shop, brevo, tls or backup)" 2 ;;
  esac
done

if [ ! -f "$cert" ]; then
  fail "no certificate at $cert_rel: bootstrap the server first (it writes the certificate of the controller to the repository)" 2
fi
case "$(head -n 1 "$cert")" in
  "-----BEGIN CERTIFICATE-----") ;;
  *) fail "$cert_rel is not a PEM certificate" 2 ;;
esac
[ -f "$init_env" ] || fail "no infra/scripts/init-env.sh to read the keys from" 2

work="$(umask 077 && mktemp -d)" || fail "cannot create a temporary directory"
restore_tty=""
cleanup() {
  [ -z "$restore_tty" ] || stty "$restore_tty" < "$tty_in" 2> /dev/null || true
  rm -rf "$work"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# ---------------------------------------------------------------------------------------------------------------
# The keys of init-env.sh: lines "KEY PREFIX BYTES" of the lists `secrets` and `appendable`.
# ---------------------------------------------------------------------------------------------------------------
init_env_keys() {
  awk -v q="'" '
    /^(secrets|appendable)=/ { inblock = 1; sub(/^[a-z]+=/, "") }
    inblock {
      line = $0
      sub("^" q, "", line)
      ended = (line ~ (q "$"))
      sub(q "$", "", line)
      if (line != "") print line
      if (ended) inblock = 0
    }' "$init_env"
}

random_hex() {
  value="$(od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n')"
  [ "${#value}" -eq $(( $1 * 2 )) ] || fail "could not read random bytes"
  printf '%s' "$value"
}

# ---------------------------------------------------------------------------------------------------------------
# The files: a fixed format, so that an existing file can be read back and compared byte for byte.
#   write_file <name> <type> <path> <file with lines "KEY<TAB>ciphertext">
# ---------------------------------------------------------------------------------------------------------------
tab="$(printf '\t')"
write_file() {
  {
    echo "# Written by infra/k8s/scripts/seal-secrets.sh, encrypted with environments/prod/sealed-secrets.pem. Safe to commit."
    echo "apiVersion: bitnami.com/v1alpha1"
    echo "kind: SealedSecret"
    echo "metadata:"
    echo "  name: $1"
    echo "  namespace: $namespace"
    echo "spec:"
    echo "  encryptedData:"
    while IFS="$tab" read -r key ciphertext; do
      [ -z "$key" ] || echo "    $key: $ciphertext"
    done < "$4"
    echo "  template:"
    echo "    metadata:"
    echo "      name: $1"
    echo "      namespace: $namespace"
    echo "    type: $2"
  } > "$3"
}

# existing_pairs <path> <name> <type> <output file>: the pairs of a file written by write_file, or an error when the file is
# not exactly what write_file would write for them.
existing_pairs() {
  sed -n '/^  encryptedData:$/,/^  template:$/p' "$1" | sed '1d;$d' | sed -n 's/^    \([A-Za-z0-9_.-][A-Za-z0-9_.-]*\): \([A-Za-z0-9+/=][A-Za-z0-9+/=]*\)$/\1	\2/p' > "$4"
  write_file "$2" "$3" "$work/reread.yaml" "$4"
  cmp -s "$1" "$work/reread.yaml" ||
    fail "${1#"$TOOLS_REPO"/} is not in the format this script writes; it was not touched (nothing was written)"
}

# seal <name> <value on the standard input>: the ciphertext of one value, bound to the name and the namespace.
seal() {
  if ! ciphertext="$(kubeseal --raw --scope strict --name "$1" --namespace "$namespace" --cert "/repo/$cert_rel")"; then
    fail "kubeseal failed for $1 (nothing was written)"
  fi
  case "$ciphertext" in
    "" | *[!A-Za-z0-9+/=]*) fail "kubeseal returned something that is not a sealed value for $1 (nothing was written)" ;;
  esac
  printf '%s' "$ciphertext"
}

# ---------------------------------------------------------------------------------------------------------------
# Values from the terminal, without echo.
#   read_secret <prompt> [multi]: sets secret_value; with multi the lines are read up to the line "-----END ...".
# ---------------------------------------------------------------------------------------------------------------
tty_open=0
read_secret() {
  if [ "$tty_open" = 0 ]; then
    [ -r "$tty_in" ] || fail "no terminal to read from ($tty_in); run this in a terminal (nothing was written)"
    # Opened once: the next value continues where the previous one ended.
    exec 3< "$tty_in"
    tty_open=1
  fi
  printf '%s' "$1" >&2
  if [ -t 3 ]; then
    restore_tty="$(stty -g <&3)"
    stty -echo <&3
  fi
  secret_value=""
  carriage="$(printf '\r')"
  while IFS= read -r line <&3 || [ -n "$line" ]; do
    line="${line%"$carriage"}"
    if [ "${2:-}" = multi ]; then
      [ -n "$line" ] || continue
      secret_value="$secret_value$line
"
      case "$line" in "-----END "*) break ;; esac
    else
      secret_value="$line"
      break
    fi
  done
  if [ -n "$restore_tty" ]; then
    stty "$restore_tty" <&3
    restore_tty=""
    echo >&2
  fi
}

# ask <label> <multi?>: a value that must not be empty.
ask() {
  read_secret "$1: " "${2:-}"
  [ -n "$secret_value" ] || fail "no value for $1 (nothing was written)"
}

# add_pair <pairs file> <key> <name of the secret> <value>: seals the value and appends "key<TAB>ciphertext". The value is an
# argument of a shell function, not of a program, so it is not visible to other processes.
add_pair() {
  ciphertext="$(printf '%s' "$4" | seal "$3")" || exit 1
  printf '%s\t%s\n' "$2" "$ciphertext" >> "$1"
}

staged=""
stage() { staged="$staged $1"; }

# ---------------------------------------------------------------------------------------------------------------
# shop: the keys of init-env.sh that the sealed secret lacks.
# ---------------------------------------------------------------------------------------------------------------
seal_shop() {
  name=shop-secrets
  path="$out_dir/$name.yaml"
  : > "$work/$name.pairs"
  [ ! -f "$path" ] || existing_pairs "$path" "$name" Opaque "$work/$name.pairs"
  added=""
  init_env_keys > "$work/keys"
  [ -s "$work/keys" ] || fail "no keys found in infra/scripts/init-env.sh"
  while read -r key prefix bytes; do
    grep -q "^$key$tab" "$work/$name.pairs" && continue
    [ "$prefix" != "-" ] || prefix=""
    random="$(random_hex "$bytes")" || exit 1
    add_pair "$work/$name.pairs" "$key" "$name" "$prefix$random"
    random=""
    added="$added $key"
  done < "$work/keys"
  if [ -z "$added" ]; then
    echo "seal-secrets: shop-secrets.yaml has every key of init-env.sh, nothing to add"
    return 0
  fi
  write_file "$name" Opaque "$work/$name.yaml" "$work/$name.pairs"
  stage "$name"
  echo "seal-secrets: shop-secrets.yaml gets the keys:$added"
}

# ---------------------------------------------------------------------------------------------------------------
# brevo, tls and backup: created once, from values typed in the terminal.
# ---------------------------------------------------------------------------------------------------------------
seal_brevo() {
  name=shop-brevo-smtp
  path="$out_dir/$name.yaml"
  if [ -f "$path" ]; then
    echo "seal-secrets: $name.yaml exists, nothing is asked and nothing changes"
    return 0
  fi
  ask "Brevo SMTP login (Brevo panel, SMTP & API)"
  login="$secret_value"
  ask "Brevo SMTP key"
  key="$secret_value"
  case "$key" in
    *[/?#]* | *[[:space:]]*) fail "the Brevo SMTP key must not contain / ? # or spaces (it goes into a URL); nothing was written" ;;
  esac
  case "$login" in
    *[[:space:]]*) fail "the Brevo SMTP login must not contain spaces; nothing was written" ;;
  esac
  : > "$work/$name.pairs"
  add_pair "$work/$name.pairs" BREVO_SMTP_LOGIN "$name" "$login"
  add_pair "$work/$name.pairs" BREVO_SMTP_PASSWORD "$name" "$key"
  secret_value="" login="" key=""
  write_file "$name" Opaque "$work/$name.yaml" "$work/$name.pairs"
  stage "$name"
  echo "seal-secrets: $name.yaml gets the keys: BREVO_SMTP_LOGIN BREVO_SMTP_PASSWORD"
}

seal_tls() {
  name=shop-origin-tls
  path="$out_dir/$name.yaml"
  if [ -f "$path" ]; then
    echo "seal-secrets: $name.yaml exists, nothing is asked and nothing changes"
    return 0
  fi
  ask "Cloudflare Origin CA certificate (PEM, paste it, up to the END CERTIFICATE line)" multi
  crt="$secret_value"
  case "$crt" in
    "-----BEGIN CERTIFICATE-----"*"-----END CERTIFICATE-----"*) ;;
    *) fail "that is not a PEM certificate; nothing was written" ;;
  esac
  ask "Cloudflare Origin CA private key (PEM, paste it, up to the END PRIVATE KEY line)" multi
  private="$secret_value"
  case "$private" in
    "-----BEGIN PRIVATE KEY-----"* | "-----BEGIN RSA PRIVATE KEY-----"* | "-----BEGIN EC PRIVATE KEY-----"*) ;;
    *) fail "that is not a PEM private key; nothing was written" ;;
  esac
  : > "$work/$name.pairs"
  add_pair "$work/$name.pairs" tls.crt "$name" "$crt"
  add_pair "$work/$name.pairs" tls.key "$name" "$private"
  secret_value="" crt="" private=""
  write_file "$name" kubernetes.io/tls "$work/$name.yaml" "$work/$name.pairs"
  stage "$name"
  echo "seal-secrets: $name.yaml gets the keys: tls.crt tls.key"
}

seal_backup() {
  name=shop-backup
  path="$out_dir/$name.yaml"
  if [ -f "$path" ]; then
    echo "seal-secrets: $name.yaml exists, nothing is asked and nothing changes"
    return 0
  fi
  ask "Password of the restic repository (keep a copy in the password manager: without it the backups cannot be read)"
  password="$secret_value"
  ask "Access key id of the S3 bucket of the backups (Cloudflare R2 API token)"
  key_id="$secret_value"
  ask "Secret access key of the S3 bucket of the backups"
  key="$secret_value"
  case "$key_id$key" in
    *[[:space:]]*) fail "the access key id and the secret access key must not contain spaces; nothing was written" ;;
  esac
  : > "$work/$name.pairs"
  add_pair "$work/$name.pairs" RESTIC_PASSWORD "$name" "$password"
  add_pair "$work/$name.pairs" BACKUP_S3_ACCESS_KEY_ID "$name" "$key_id"
  add_pair "$work/$name.pairs" BACKUP_S3_SECRET_ACCESS_KEY "$name" "$key"
  secret_value="" password="" key_id="" key=""
  write_file "$name" Opaque "$work/$name.yaml" "$work/$name.pairs"
  stage "$name"
  echo "seal-secrets: $name.yaml gets the keys: RESTIC_PASSWORD BACKUP_S3_ACCESS_KEY_ID BACKUP_S3_SECRET_ACCESS_KEY"
}

for item in $what; do
  case "$item" in
    shop) seal_shop ;;
    brevo) seal_brevo ;;
    tls) seal_tls ;;
    backup) seal_backup ;;
  esac
done

# All of it worked: move the files into place.
mkdir -p "$out_dir"
for name in $staged; do
  mv "$work/$name.yaml" "$out_dir/$name.yaml"
done
if [ -z "$staged" ]; then
  echo "seal-secrets: nothing changed"
else
  echo "seal-secrets: wrote$(for name in $staged; do printf ' %s/%s.yaml' "$secrets_rel" "$name"; done)"
fi
