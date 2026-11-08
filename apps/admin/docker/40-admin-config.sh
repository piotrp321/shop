#!/bin/sh
# Runs from /docker-entrypoint.d before nginx starts: writes /config.js (window.__ADMIN_CONFIG__) from the environment.
# The keys keep the names of the Vite variables the panel reads in development. A missing key stops the container.
set -eu

dir=/tmp/admin-config
mkdir -p "$dir"
out="$dir/config.js.tmp"

# Prints the value of the variable named by $1 as a JSON string, or stops with the name of the variable.
json_value() {
  name="$1"
  eval "value=\"\${$name:-}\""
  if [ -z "$value" ]; then
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

{
  printf 'window.__ADMIN_CONFIG__ = {\n'
  printf '  "VITE_API_URL": %s,\n' "$api_url"
  printf '  "VITE_KEYCLOAK_URL": %s,\n' "$keycloak_url"
  printf '  "VITE_ADMIN_URL": %s\n' "$admin_url"
  printf '};\n'
} > "$out"
mv "$out" "$dir/config.js"
