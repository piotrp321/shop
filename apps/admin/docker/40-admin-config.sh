#!/bin/sh
# Runs from /docker-entrypoint.d before nginx starts: writes /config.js (window.__ADMIN_CONFIG__) from the environment.
# The keys keep the names of the Vite variables the panel reads in development. A missing key stops the container.
# The optional SENTRY_DSN, SENTRY_ENVIRONMENT, SENTRY_RELEASE and SENTRY_TRACES_SAMPLE_RATE (Sentry, docs/features/sentry/spec.md)
# add a key of the same name only when they are set and not empty; without them config.js holds the four URLs only.
# ADMIN_CONFIG_DIR (default /tmp/admin-config, where nginx.conf reads it) is the output directory; only the test moves it.
set -eu

dir="${ADMIN_CONFIG_DIR:-/tmp/admin-config}"
mkdir -p "$dir"
out="$dir/config.js.tmp"

# Prints the value of the variable named by $1 as a JSON string. A variable that is not set or is empty stops the start
# with its name, unless $2 is "optional": then it prints nothing.
json_value() {
  name="$1"
  eval "value=\"\${$name:-}\""
  if [ -z "$value" ]; then
    [ "${2:-}" = optional ] && return 0
    echo "40-admin-config.sh: $name is not set" >&2
    exit 1
  fi
  # Whitespace and control characters (a newline too) cannot be in a URL; refuse them instead of escaping them.
  case "$value" in
    *[[:space:][:cntrl:]]*)
      echo "40-admin-config.sh: $name contains whitespace or a control character" >&2
      exit 1
      ;;
  esac
  escaped="$(printf '%s' "$value" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')"
  printf '"%s"' "$escaped"
}

api_url="$(json_value VITE_API_URL)"
keycloak_url="$(json_value VITE_KEYCLOAK_URL)"
admin_url="$(json_value VITE_ADMIN_URL)"
store_url="$(json_value VITE_STORE_URL)"
# The optional values are read (and refused when they hold whitespace) before the file is written.
optional_names="SENTRY_DSN SENTRY_ENVIRONMENT SENTRY_RELEASE SENTRY_TRACES_SAMPLE_RATE"
for name in $optional_names; do
  eval "optional_$name=\"\$(json_value $name optional)\""
done

first=1
# Writes one key of the object: the comma goes before every key but the first, so the last key has none.
entry() {
  if [ "$first" = 1 ]; then first=0; else printf ',\n'; fi
  printf '  "%s": %s' "$1" "$2"
}

{
  printf 'window.__ADMIN_CONFIG__ = {\n'
  entry VITE_API_URL "$api_url"
  entry VITE_KEYCLOAK_URL "$keycloak_url"
  entry VITE_ADMIN_URL "$admin_url"
  entry VITE_STORE_URL "$store_url"
  for name in $optional_names; do
    eval "json=\"\$optional_$name\""
    if [ -n "$json" ]; then entry "$name" "$json"; fi
  done
  printf '\n};\n'
} > "$out"
mv "$out" "$dir/config.js"
