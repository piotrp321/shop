#!/bin/sh
# The job `keycloak-config` (D9), with the image of Keycloak (kcadm). For each realm of the files the chart renders in
# REALMS_DIR (templates/_keycloak.tpl):
#   1. a realm that does not exist is created from <realm>.json; in an existing one, partialImport with
#      ifResourceExists=SKIP adds the clients, roles and groups that are missing and leaves the existing ones as they are;
#   2. then, on every run, the values of the configuration are set: <realm>.settings.json (registration, e-mail
#      verification, SMTP) and <realm>.client.<clientId>.json (addresses of the domain, the secret of the store).
# Nothing is ever deleted: no user, password or resource. The secrets (the password of the administrator, the secret of
# the store client, the SMTP login and key) are never arguments of a command and never printed: the bodies go to kcadm
# on its standard input (-f -) and the password in KC_CLI_PASSWORD.
#
# Environment: KC_BOOTSTRAP_ADMIN_USERNAME, KC_BOOTSTRAP_ADMIN_PASSWORD, STORE_CLIENT_SECRET, and SMTP_USER and
# SMTP_PASSWORD when the realms send through Brevo; KCADM, REALMS_DIR and KEYCLOAK_URL default to the job's.
set -eu

kcadm="${KCADM:-/opt/keycloak/bin/kcadm.sh}"
realms_dir="${REALMS_DIR:-/realms}"
server="${KEYCLOAK_URL:-http://keycloak:8080}"

umask 077
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# The value as the content of a JSON string: backslashes and double quotes escaped.
json_escape() {
  rest="$1" escaped=""
  while :; do
    case "$rest" in
      *[\\\"]*)
        before="${rest%%[\\\"]*}"
        rest="${rest#"$before"}"
        escaped="$escaped$before\\${rest%"${rest#?}"}"
        rest="${rest#?}"
        ;;
      *) break ;;
    esac
  done
  printf '%s' "$escaped$rest"
}

# with_secrets <file>: prints the file with the placeholders replaced by the JSON-escaped values of the variables. A
# placeholder whose variable is not set stops the job before any change.
with_secrets() {
  text="$(cat "$1")"
  for variable in STORE_CLIENT_SECRET SMTP_USER SMTP_PASSWORD; do
    placeholder="\${$variable}"
    case "$text" in *"$placeholder"*) ;; *) continue ;; esac
    eval "value=\${$variable:-}"
    # shellcheck disable=SC2154 # set by the eval above
    if [ -z "$value" ]; then
      echo "keycloak-config: $1 needs $variable, which is not set" >&2
      return 1
    fi
    value="$(json_escape "$value")"
    replaced=""
    while :; do
      case "$text" in
        *"$placeholder"*)
          replaced="$replaced${text%%"$placeholder"*}$value"
          text="${text#*"$placeholder"}"
          ;;
        *) break ;;
      esac
    done
    text="$replaced$text"
  done
  printf '%s' "$text"
}

# Every body is prepared before the first change, so a missing secret changes nothing.
for file in "$realms_dir"/*.json; do
  with_secrets "$file" > "$work/${file##*/}"
done

KC_CLI_PASSWORD="$KC_BOOTSTRAP_ADMIN_PASSWORD" "$kcadm" config credentials --server "$server" --realm master \
  --user "$KC_BOOTSTRAP_ADMIN_USERNAME" >/dev/null

existing="$("$kcadm" get realms --fields realm)"

for realm in shop backoffice; do
  case "$existing" in
    *"\"realm\" : \"$realm\""*)
      "$kcadm" create partialImport -r "$realm" -s ifResourceExists=SKIP -o -f - < "$work/$realm.json" > "$work/import"
      added="$(grep -c '"action" : "ADDED"' "$work/import" || true)"
      skipped="$(grep -c '"action" : "SKIPPED"' "$work/import" || true)"
      echo "keycloak-config: realm $realm exists: $added added, $skipped skipped, nothing overwritten"
      ;;
    *)
      "$kcadm" create realms -f - < "$work/$realm.json"
      echo "keycloak-config: realm $realm created"
      ;;
  esac

  "$kcadm" update "realms/$realm" --merge -f - < "$work/$realm.settings.json"
  clients=""
  for file in "$work/$realm".client.*.json; do
    [ -e "$file" ] || continue
    client="${file#"$work/$realm.client."}"
    client="${client%.json}"
    id="$("$kcadm" get clients -r "$realm" -q "clientId=$client" --fields id --format csv --noquotes)"
    if [ -z "$id" ]; then
      echo "keycloak-config: client $client of the realm $realm is not in Keycloak" >&2
      exit 1
    fi
    "$kcadm" update "clients/$id" -r "$realm" --merge -f - < "$file"
    clients="$clients $client"
  done
  echo "keycloak-config: realm $realm: the values of $realm.settings.json set, and those of the clients:${clients:- none}"
done
echo "keycloak-config: done"
