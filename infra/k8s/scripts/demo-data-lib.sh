#!/bin/sh
# The functions of the demo data that load.sh (make -C infra/k8s load) and generate.sh (make -C infra/k8s generate) share. It is
# sourced (`. "$here/demo-data-lib.sh"`), not run, and sets no shell option: the callers run under `set -eu`, and so do these
# functions. docs/features/demo-scale/spec.md, "Logi i metryki".
#
# Every function takes its data from its arguments or from the variables and the function named here, never from a variable that
# only one script sets. The caller supplies:
#
#   kc                  a function: kubectl in the namespace of the shop (`kc logs job/x`, `kc exec statefulset/postgres -- ...`);
#                       used by create_photo_secret, save_generator_log and data_sizes
#   DEMO_DATA_TOOL      the name of the caller in the messages these functions print ("load: ..."); load by default
#   photos_endpoint, photos_bucket, photos_key_id, photos_secret_key
#                       the four read variables of the photo pool, set by read_photo_access (not exported) and read by
#                       create_photo_secret and save_generator_log
#
# A function that cannot do its job returns 1 and leaves the reason, without a value of the photo pool, in demo_data_error: the
# caller decides what to do with it (load.sh: `|| fail "$demo_data_error"`, which ends with 2). Every function that touches a
# value of the photo pool stops `set -x` for the time it does (trace_off, trace_on): the values are never in a trace, a command
# line, the output or a file the caller writes. The token that writes (DEMO_PHOTOS_WRITE_*) is skipped: it never reaches the
# cluster.
#
#   read_photo_access <file>                the four read variables of the photo pool from <file> (NAME=value lines, as data)
#   create_photo_secret <name>              the Secret <name> from those variables, through the standard input of kubectl
#   save_generator_log <job> <file>         the log of the container demo-data of the Job <job>, each line with its time, to <file>,
#                                           the values of the photo pool in it masked
#   generator_steps <log file>              the seconds of each step of bin/generate-demo from the [OK] lines of a saved log
#   data_sizes                              the databases, tables and indexes of Postgres and the indices of Elasticsearch
#   read_disk_peak <profile> <profiles file>  diskPeakGb of the profile into disk_peak and the limits of the room on the disks into
#                                           required_kib, floor_kib (KiB), required_gb and floor_gb (text, two decimals)

# The values of the photo pool never go through a traced command: trace_off stops `set -x` (sh -x load.sh) and trace_on
# brings it back when it was on.
trace_off() {
  case "$-" in
    *x*) traced=1; set +x ;;
    *) traced="" ;;
  esac
}
trace_on() { [ -z "$traced" ] || set -x; }
blank() {
  case "$1" in
    *[![:space:]]*) return 1 ;;
  esac
}

# Reads the four read variables of the photo pool from the file <file> as data (NAME=value lines, the value without the quotes
# around it and the carriage return of a Windows line; nothing is evaluated) into variables of this shell that are not exported.
# Every other line, the token that writes among them, is skipped. A missing file or variable: return 1, the reason naming the file
# or the variable, never a value, in demo_data_error.
read_photo_access() {
  photos_file="$1"
  if ! { [ -f "$photos_file" ] && [ -r "$photos_file" ]; }; then
    demo_data_error="$photos_file does not exist or cannot be read (set DEMO_PHOTOS_ENV_FILE; the variables are in docs/features/demo-data/spec.md, \"Wymagane przed startem\")"
    return 1
  fi
  trace_off
  photos_endpoint="" photos_bucket="" photos_key_id="" photos_secret_key=""
  photos_cr="$(printf '\r')"
  while IFS= read -r photos_line || [ -n "$photos_line" ]; do
    photos_line="${photos_line%"$photos_cr"}"
    photos_value="${photos_line#*=}"
    case "$photos_value" in
      \"*\") photos_value="${photos_value#\"}"; photos_value="${photos_value%\"}" ;;
      \'*\') photos_value="${photos_value#\'}"; photos_value="${photos_value%\'}" ;;
    esac
    case "$photos_line" in
      DEMO_PHOTOS_ENDPOINT=*) photos_endpoint="$photos_value" ;;
      DEMO_PHOTOS_BUCKET=*) photos_bucket="$photos_value" ;;
      DEMO_PHOTOS_ACCESS_KEY_ID=*) photos_key_id="$photos_value" ;;
      DEMO_PHOTOS_SECRET_ACCESS_KEY=*) photos_secret_key="$photos_value" ;;
    esac
  done < "$photos_file"
  photos_line="" photos_value=""
  photos_missing=""
  if blank "$photos_endpoint"; then photos_missing="$photos_missing DEMO_PHOTOS_ENDPOINT"; fi
  if blank "$photos_bucket"; then photos_missing="$photos_missing DEMO_PHOTOS_BUCKET"; fi
  if blank "$photos_key_id"; then photos_missing="$photos_missing DEMO_PHOTOS_ACCESS_KEY_ID"; fi
  if blank "$photos_secret_key"; then photos_missing="$photos_missing DEMO_PHOTOS_SECRET_ACCESS_KEY"; fi
  trace_on
  [ -z "$photos_missing" ] && return 0
  demo_data_error="these read variables are missing or empty in $photos_file:$photos_missing (the token that writes, DEMO_PHOTOS_WRITE_*, is not used)"
  return 1
}

# The Secret <name> of the photo pool in the cluster: the manifest goes to kubectl on its standard input, the values base64 in it.
# The output of kubectl is not shown (an error could quote the manifest).
create_photo_secret() {
  photos_secret_name="$1"
  trace_off
  photos_status=0
  printf '{"apiVersion":"v1","kind":"Secret","metadata":{"name":"%s"},"type":"Opaque","data":{"DEMO_PHOTOS_ENDPOINT":"%s","DEMO_PHOTOS_BUCKET":"%s","DEMO_PHOTOS_ACCESS_KEY_ID":"%s","DEMO_PHOTOS_SECRET_ACCESS_KEY":"%s"}}' \
    "$photos_secret_name" "$(printf '%s' "$photos_endpoint" | base64 | tr -d '\n')" "$(printf '%s' "$photos_bucket" | base64 | tr -d '\n')" \
    "$(printf '%s' "$photos_key_id" | base64 | tr -d '\n')" "$(printf '%s' "$photos_secret_key" | base64 | tr -d '\n')" \
    | kc create -f - >/dev/null 2>&1 || photos_status=$?
  trace_on
  [ "$photos_status" -eq 0 ] && return 0
  demo_data_error="the Secret $photos_secret_name of the photo pool could not be created (kubectl create -f - failed; its output is not shown, it could quote a value)"
  return 1
}

# The log of the container demo-data of the Job <job>, each line with its time, to <file>. A value of the photo pool in it
# (the generator never prints one) is replaced with [masked]: the values reach awk on its standard input, before the log.
save_generator_log() {
  demo_job="$1" demo_log_file="$2" demo_tool="${DEMO_DATA_TOOL:-load}"
  trace_off
  {
    printf '%s\n' "$photos_endpoint" "$photos_bucket" "$photos_key_id" "$photos_secret_key"
    kc logs "job/$demo_job" --container=demo-data --timestamps 2>&1 || echo "$demo_tool: the log of the Job $demo_job could not be read"
  } | awk -v tool="$demo_tool" '
    NR <= 4 { if ($0 != "") value[++values] = $0; next }
    NR == 5 {
      # The longest value first, so that a value inside another one does not leave a part of the other one.
      for (i = 1; i <= values; i++) for (j = i + 1; j <= values; j++) if (length(value[j]) > length(value[i])) { v = value[i]; value[i] = value[j]; value[j] = v }
    }
    {
      line = $0
      for (i = 1; i <= values; i++) {
        rest = line; line = ""
        while ((at = index(rest, value[i])) > 0) { line = line substr(rest, 1, at - 1) "[masked]"; rest = substr(rest, at + length(value[i])); masked++ }
        line = line rest
      }
      print line
    }
    END { if (masked) printf "%s: the log of the generator held %d value(s) of the access of the photo pool; they are masked in demo-data.log\n", tool, masked > "/dev/stderr" }' \
    > "$demo_log_file"
  trace_on
}

# The seconds of each step of the generator from a saved log <log file>: a step ends with the [OK] line of its command
# (SymfonyStyle; the parts of a step, one line per process) and is named by the text of that line up to ":" or " ("; it lasts from
# the end of the step before it (the first one from the first line of the log) to its last such line.
generator_steps() {
  echo "# seconds of each step of bin/generate-demo (from the end of the step before it to its last [OK] line; the first one from the first line of the log)"
  awk '
    function epoch(stamp,   y, m, d, era, yoe, mp, doe) {
      y = substr(stamp, 1, 4) + 0; m = substr(stamp, 6, 2) + 0; d = substr(stamp, 9, 2) + 0
      if (m <= 2) y--
      era = int(y / 400); yoe = y - era * 400; mp = (m + 9) % 12
      doe = yoe * 365 + int(yoe / 4) - int(yoe / 100) + int((153 * mp + 2) / 5) + d - 1
      return (era * 146097 + doe - 719468) * 86400 + substr(stamp, 12, 2) * 3600 + substr(stamp, 15, 2) * 60 + substr(stamp, 18, 2)
    }
    $1 ~ /^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]/ {
      t = epoch($1)
      if (!seen) { seen = 1; first = t; previous = t }
      last = t
      at = index($0, "[OK] ")
      if (at == 0) next
      label = substr($0, at + 5)
      sub(/[ \t\r]+$/, "", label)
      if ((cut = index(label, ": ")) > 0) label = substr(label, 1, cut - 1)
      if ((cut = index(label, " (")) > 0) label = substr(label, 1, cut - 1)
      if (label != step) {
        if (step != "") { printf "%7d s  %s\n", ended - previous, step; previous = ended }
        step = label
      }
      ended = t
    }
    END {
      if (step == "") { print "no [OK] line in demo-data.log: the steps could not be timed"; exit }
      printf "%7d s  %s\n", ended - previous, step
      printf "total: %d s (from the first to the last line of the log)\n", last - first
    }' "$1"
}

# The sizes of the data (for the report): best effort, a failure is written down and the caller goes on.
data_sizes() {
  demo_tool="${DEMO_DATA_TOOL:-load}"
  echo "== postgres"
  kc exec statefulset/postgres -- psql -U shop -d shop -v ON_ERROR_STOP=1 \
    -c '\echo == the databases (bytes)' \
    -c "SELECT datname AS database_name, pg_database_size(datname) AS bytes, pg_size_pretty(pg_database_size(datname)) AS size FROM pg_database WHERE NOT datistemplate ORDER BY 2 DESC" \
    -c '\echo == the tables of the database shop (bytes; estimated_rows from the statistics)' \
    -c "SELECT c.relname AS table_name, pg_table_size(c.oid) AS table_bytes, pg_indexes_size(c.oid) AS index_bytes, pg_total_relation_size(c.oid) AS total_bytes, pg_size_pretty(pg_total_relation_size(c.oid)) AS total, c.reltuples::bigint AS estimated_rows FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind IN ('r', 'p') AND n.nspname = 'public' ORDER BY total_bytes DESC" \
    -c '\echo == the indexes of the database shop (bytes)' \
    -c "SELECT i.indexrelid::regclass AS index_name, t.relname AS table_name, pg_relation_size(i.indexrelid) AS bytes, pg_size_pretty(pg_relation_size(i.indexrelid)) AS size FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = 'public' ORDER BY bytes DESC" \
    2>&1 || { echo "(psql failed: the sizes of Postgres are not known)"; echo "$demo_tool: the sizes of Postgres could not be read (sizes.txt)" >&2; }
  echo "== elasticsearch: the indices (bytes) and the aliases"
  kc exec statefulset/elasticsearch -- curl -fsS 'http://localhost:9200/_cat/indices?v&bytes=b&s=index' 'http://localhost:9200/_cat/aliases?v' \
    2>&1 || { echo "(curl failed: the sizes of Elasticsearch are not known)"; echo "$demo_tool: the sizes of Elasticsearch could not be read (sizes.txt)" >&2; }
}

# diskPeakGb of the profile <profile> from the profiles file <profiles file> (the fixed format of the file: `  <profile>:` under
# `profiles:`, then `    diskPeakGb: <number>`) into $disk_peak, and from it the limits of the room on the disks (W2): required_kib
# and required_gb are 1.25 x the peak, floor_kib and floor_gb 10% of it (1 GB is 10^9 bytes; df counts KiB, so the KiB are rounded
# up). Return 1 with the reason in demo_data_error when the file or the peak is not there or the peak is not a positive number.
# shellcheck disable=SC2034 # the results (demo_data_error, required_kib, floor_kib, required_gb, floor_gb) are read by the sourcing script
read_disk_peak() {
  peak_profile="$1" peak_file="$2"
  if ! { [ -f "$peak_file" ] && [ -r "$peak_file" ]; }; then
    demo_data_error="diskPeakGb of the profile $peak_profile cannot be read: $peak_file is not a readable file"
    return 1
  fi
  disk_peak="$(awk -v profile="$peak_profile" '
    /^profiles:[ \t]*$/ { inside = 1; next }
    inside && /^[^ \t#]/ { inside = 0 }
    inside && /^  [A-Za-z0-9_-]+:[ \t]*(#.*)?$/ { name = $0; sub(/^  /, "", name); sub(/:.*/, "", name); current = name; next }
    inside && current == profile && /^    diskPeakGb:/ {
      value = $0; sub(/^    diskPeakGb:[ \t]*/, "", value); sub(/[ \t]*#.*$/, "", value); sub(/[ \t\r]+$/, "", value)
      print value; exit
    }' "$peak_file")" || disk_peak=""
  case "$disk_peak" in
    "" | *[!0-9.]* | .* | *. | *.*.*)
      demo_data_error="diskPeakGb of the profile $peak_profile in $peak_file is missing or is not a positive number (read '$disk_peak')"
      return 1 ;;
  esac
  if ! awk -v value="$disk_peak" 'BEGIN { exit !(value + 0 > 0) }'; then
    demo_data_error="diskPeakGb of the profile $peak_profile in $peak_file is not a positive number (read '$disk_peak')"
    return 1
  fi
  required_kib="$(awk -v peak="$disk_peak" 'BEGIN { b = peak * 1250000000; k = int(b / 1024); if (k * 1024 < b) k++; printf "%d", k }')"
  floor_kib="$(awk -v peak="$disk_peak" 'BEGIN { b = peak * 100000000; k = int(b / 1024); if (k * 1024 < b) k++; printf "%d", k }')"
  required_gb="$(awk -v peak="$disk_peak" 'BEGIN { printf "%.2f", peak * 1.25 }')"
  floor_gb="$(awk -v peak="$disk_peak" 'BEGIN { printf "%.2f", peak * 0.1 }')"
}
