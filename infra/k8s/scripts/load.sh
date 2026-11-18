#!/bin/sh
# The load measurement (make -C infra/k8s load, docs/features/hardening/spec.md "Testy obciążeniowe", D15): the chart with
# the profile `load` (ci with the resources of prod and the header of the client address, environments/load) on an empty
# k3d cluster with the production images built here, the demo data, the stocks raised, a warm-up, the ramp to saturation
# (reads and carts) and the steady run at 70% of it with a checkout of limited concurrency next to it (load/*.js, k6 in a
# container). Nothing is installed on the host and nothing is pushed. The cluster is deleted on every exit.
#
#   LOAD_OUT=<directory> load.sh   the directory for the results: required, an existing absolute path outside the repository
#                                  that Docker can mount for writing (Docker Desktop: shared in Settings > Resources > File
#                                  Sharing); a short container tries the mount before any image is built
#
# The data (LOAD_DATA, docs/features/demo-data/spec.md, "k3d"): seed (the default) measures on the demo data that the Job
# initialize loads and raises the stock of every variant. medium, server and full run, once the cluster is ready, one Job of
# the suspended CronJob demo-data (bin/generate-demo --profile=<LOAD_DATA>: it deletes the seed and generates the profile; hours
# for server and full) and wait for it; then the stock is raised only for the variants of the products of a few chunks of the
# sitemap (DISCOVER_CHUNKS, or DISCOVER_CHUNK_COUNT of them chosen at random from GET /api/v1/sitemap/product-chunks), and k6
# reads its products from those chunks (DISCOVER_CHUNKS is passed to it). Those profiles take their photos from the private
# bucket of the photo pool: the four read variables (DEMO_PHOTOS_ENDPOINT, DEMO_PHOTOS_BUCKET, DEMO_PHOTOS_ACCESS_KEY_ID,
# DEMO_PHOTOS_SECRET_ACCESS_KEY) are read as data (nothing in the file is evaluated) from DEMO_PHOTOS_ENV_FILE before the
# cluster is made, and become the Secret demo-photos of the cluster, which only the container of the CronJob reads. The values
# go to kubectl on its standard input: they are never in a command line, in the output, in `sh -x` or in LOAD_OUT (the log of
# the generator is masked in case it held one). The lines of the token that writes (DEMO_PHOTOS_WRITE_*) are skipped: it
# never reaches the cluster.
#
# The room on the disks (docs/features/demo-data/spec.md, W2; medium, server and full only, seed checks nothing): diskPeakGb of the
# profile (apps/api/fixtures/demo-data/profiles.yaml, the estimate of the peak disk use of the whole stack) is read before anything
# is made. The free space is read in two places: in the Docker VM (`df -Pk /` in a container, whose root is on the disk where Docker
# keeps its data) and on the disk of the host that holds the disk image of the VM (`df -Pk` of LOAD_DISK_PATH; the image of Docker
# Desktop grows as it is written, so the host is the real limit). Both must have at least 1.25 x diskPeakGb (GB of 10^9 bytes),
# else exit 2 before any image is built or cluster made, with the needed and the free space of both in the message. From the start
# on, every LOAD_SPACE_POLL seconds the two are read again, and a fall of either below 10% of diskPeakGb stops the Job of the
# generator and k6, deletes the cluster and ends with 2. A reading that fails or is not a number is exit 2 too, never enough room.
#
# Results in LOAD_OUT: environment.txt (host, Docker, versions, resources, LOAD_DATA and the data, the chunks), sizes.txt (the
# databases, the tables and the indexes of Postgres and the indices of Elasticsearch before the measurement), ramp.json,
# ramp.txt, steady.json, steady.txt, warmup.txt, top.log (kubectl top pods every 10 s), node-stats.log (the node container,
# every 10 s); with a generated profile also demo-data.log (the log of the Job, with the time of every line) and
# demo-data-steps.txt (the seconds of each step of the generator, from its [OK] lines). sizes.txt is written with every
# LOAD_DATA; the times of the steps only with a generator.
#
# Exit code: 0 the measurement was done (it has no thresholds of speed; a ramp that ended on saturation is the normal
# end), 2 the environment is not fit (LOAD_OUT missing, wrong or not mountable by Docker, an unknown LOAD_DATA, the file of the photo pool missing or
# without a read variable, no Docker, too few CPUs or too little memory in the Docker VM, too little room on the disks before the
# start or a fall below 10% of diskPeakGb during the run, a free space or diskPeakGb that cannot be read, pods not ready, the
# generator failed or did not finish in time, no chunks of the sitemap, the data of the demo or the stocks not prepared, the
# warm-up failed) with a message, 1 anything else that went wrong (a failed run of k6 in the ramp or the steady run, a failed build).
# Exit 125 of `docker run` of k6 is Docker failing to start it (named so in the message), not a shop that does not answer.
#
#   LOAD_DATA=seed        seed, medium, server or full (above)
#   DEMO_PHOTOS_ENV_FILE  the file of the user with the access of the photo pool (~/.config/shop/demo-photos.env)
#   LOAD_DATA_TIMEOUT     seconds to wait for the Job of the generator; by default its activeDeadlineSeconds (the time
#                         limit of the profile in the chart, demoData.activeDeadlineSeconds) and 600 more
#   LOAD_DATA_POLL=30     seconds between two readings of the Job of the generator
#   LOAD_DISK_PATH        a directory on the disk of the host that holds the disk image of the Docker VM (default: the home
#                         directory); its free space is the one that is checked on the host
#   LOAD_SPACE_POLL=30    seconds between two readings of the free space during the run
#   LOAD_PROFILES_FILE    the profiles of the generator (default apps/api/fixtures/demo-data/profiles.yaml)
#   LOAD_DATA_ANCHOR      the reference moment of the generated data (--anchor of bin/generate-demo, e.g. 2026-11-01), so
#                         that two measurements have the same data; by default the start of the current UTC day
#   DISCOVER_CHUNKS, DISCOVER_CHUNK_COUNT=3, DISCOVER_PRODUCTS=60   the choice of the products of k6 (load/README.md)
#   LOAD_BUILD=0          use the images shop-load-<cluster>/<app>:r1 that are already there
#   LOAD_TIMEOUT=1200     seconds to wait for the cluster to settle
#   LOAD_MIN_CPUS=10, LOAD_MIN_MEM_GIB=20   what the Docker VM must have (the pods of prod ask for about 5 vCPU and 8.5 GiB);
#                         the memory counts with a tolerance of 5% (docker info reports a little less than is set)
#   LOAD_CHECKOUT_VUS     virtual users of the checkout of the steady run; by default a quarter of the threads of
#                         FrankenPHP in the API (below half of them, D18)
#   LOAD_STEADY_RATE      iterations per second of the steady run; by default 70% of what the ramp held
#   LOAD_COOLDOWN=60      seconds between the ramp and the steady run
#   RAMP_*, STEADY_SECONDS, WARMUP_*, CHECKOUT_THINK_SECONDS   settings of the k6 scripts (see their headers), e.g.
#                         RAMP_STEPS=2 RAMP_STEP_SECONDS=10 STEADY_SECONDS=20 WARMUP_SECONDS=10 for a short run
#
# The tools run in containers on the Docker network of the cluster, so no port of the host is used. The cluster is called
# ld-<COMPOSE_PROJECT_NAME>; the smoke test's is sm-<...>, so both can exist.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tools.sh
. "$here/tools.sh"
LOAD_DIR="$TOOLS_REPO/load"
# shellcheck source=../../../load/k6.sh
. "$LOAD_DIR/k6.sh"

LOAD_BUILD="${LOAD_BUILD:-1}"
LOAD_TIMEOUT="${LOAD_TIMEOUT:-1200}"
LOAD_POLL="${LOAD_POLL:-5}"
LOAD_MIN_CPUS="${LOAD_MIN_CPUS:-10}"
LOAD_MIN_MEM_GIB="${LOAD_MIN_MEM_GIB:-20}"
LOAD_COOLDOWN="${LOAD_COOLDOWN:-60}"
LOAD_DATA="${LOAD_DATA:-seed}"
LOAD_DATA_POLL="${LOAD_DATA_POLL:-30}"
LOAD_SPACE_POLL="${LOAD_SPACE_POLL:-30}"
LOAD_DISK_PATH="${LOAD_DISK_PATH:-$HOME}"
LOAD_PROFILES_FILE="${LOAD_PROFILES_FILE:-$TOOLS_REPO/apps/api/fixtures/demo-data/profiles.yaml}"
DEMO_PHOTOS_ENV_FILE="${DEMO_PHOTOS_ENV_FILE:-$HOME/.config/shop/demo-photos.env}"
namespace=shop
# The Secret of the read access of the photo pool (demoData.photosSecretName) and the one Job of the generator.
photos_secret=demo-photos
data_job=demo-data-load
started="$(date +%s)"

# A pause that a signal does not have to wait for: the trap of a shell runs after the foreground command, but at once in `wait`.
nap() {
  sleep "$1" &
  nap_pid=$!
  wait "$nap_pid" || true
  nap_pid=""
}
log() { printf '\n== %s (%ss)\n' "$1" "$(( $(date +%s) - started ))"; }
fail() { echo "load: $1" >&2; exit 2; }

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

# Reads the four read variables of the photo pool from DEMO_PHOTOS_ENV_FILE as data (NAME=value lines, the value without
# the quotes around it and the carriage return of a Windows line; nothing is evaluated) into variables of this shell that
# are not exported. Every other line, the token that writes among them, is skipped. A missing file or variable: exit 2,
# naming the file or the variable, never a value.
read_photo_access() {
  [ -f "$DEMO_PHOTOS_ENV_FILE" ] && [ -r "$DEMO_PHOTOS_ENV_FILE" ] \
    || fail "LOAD_DATA=$LOAD_DATA takes the photos from the private bucket of the photo pool, and $DEMO_PHOTOS_ENV_FILE does not exist or cannot be read (set DEMO_PHOTOS_ENV_FILE; the variables are in docs/features/demo-data/spec.md, \"Wymagane przed startem\")"
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
  done < "$DEMO_PHOTOS_ENV_FILE"
  photos_line="" photos_value=""
  photos_missing=""
  if blank "$photos_endpoint"; then photos_missing="$photos_missing DEMO_PHOTOS_ENDPOINT"; fi
  if blank "$photos_bucket"; then photos_missing="$photos_missing DEMO_PHOTOS_BUCKET"; fi
  if blank "$photos_key_id"; then photos_missing="$photos_missing DEMO_PHOTOS_ACCESS_KEY_ID"; fi
  if blank "$photos_secret_key"; then photos_missing="$photos_missing DEMO_PHOTOS_SECRET_ACCESS_KEY"; fi
  trace_on
  [ -z "$photos_missing" ] \
    || fail "LOAD_DATA=$LOAD_DATA takes the photos from the private bucket of the photo pool, and these read variables are missing or empty in $DEMO_PHOTOS_ENV_FILE:$photos_missing (the token that writes, DEMO_PHOTOS_WRITE_*, is not used)"
}

# The Secret of the photo pool in the cluster: the manifest goes to kubectl on its standard input, the values base64 in it.
# The output of kubectl is not shown (an error could quote the manifest).
create_photo_secret() {
  trace_off
  photos_status=0
  printf '{"apiVersion":"v1","kind":"Secret","metadata":{"name":"%s"},"type":"Opaque","data":{"DEMO_PHOTOS_ENDPOINT":"%s","DEMO_PHOTOS_BUCKET":"%s","DEMO_PHOTOS_ACCESS_KEY_ID":"%s","DEMO_PHOTOS_SECRET_ACCESS_KEY":"%s"}}' \
    "$photos_secret" "$(printf '%s' "$photos_endpoint" | base64 | tr -d '\n')" "$(printf '%s' "$photos_bucket" | base64 | tr -d '\n')" \
    "$(printf '%s' "$photos_key_id" | base64 | tr -d '\n')" "$(printf '%s' "$photos_secret_key" | base64 | tr -d '\n')" \
    | kc create -f - >/dev/null 2>&1 || photos_status=$?
  trace_on
  [ "$photos_status" -eq 0 ] || fail "the Secret $photos_secret of the photo pool could not be created (kubectl create -f - failed; its output is not shown, it could quote a value)"
}

# The log of the Job of the generator, each line with its time, to LOAD_OUT/demo-data.log. A value of the photo pool in it
# (the generator never prints one) is replaced with [masked]: the values reach awk on its standard input, before the log.
save_generator_log() {
  trace_off
  {
    printf '%s\n' "$photos_endpoint" "$photos_bucket" "$photos_key_id" "$photos_secret_key"
    kc logs "job/$data_job" --container=demo-data --timestamps 2>&1 || echo "load: the log of the Job $data_job could not be read"
  } | awk '
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
    END { if (masked) printf "load: the log of the generator held %d value(s) of the access of the photo pool; they are masked in demo-data.log\n", masked > "/dev/stderr" }' \
    > "$out/demo-data.log"
  trace_on
}

# The seconds of each step of the generator from demo-data.log: a step ends with the [OK] line of its command (SymfonyStyle;
# the parts of a step, one line per process) and is named by the text of that line up to ":" or " ("; it lasts from the end
# of the step before it (the first one from the first line of the log) to its last such line.
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
    }' "$out/demo-data.log"
}

# ---------------------------------------------------------------------------------------------------------------
# The room on the disks (W2)
# ---------------------------------------------------------------------------------------------------------------
# diskPeakGb of the profile LOAD_DATA from profiles.yaml (the fixed format of the file: `  <profile>:` under `profiles:`, then
# `    diskPeakGb: <number>`) into $disk_peak; exit 2 when it is not there or not a positive number.
read_disk_peak() {
  [ -f "$LOAD_PROFILES_FILE" ] && [ -r "$LOAD_PROFILES_FILE" ] \
    || fail "diskPeakGb of the profile $LOAD_DATA cannot be read: $LOAD_PROFILES_FILE is not a readable file"
  disk_peak="$(awk -v profile="$LOAD_DATA" '
    /^profiles:[ \t]*$/ { inside = 1; next }
    inside && /^[^ \t#]/ { inside = 0 }
    inside && /^  [A-Za-z0-9_-]+:[ \t]*(#.*)?$/ { name = $0; sub(/^  /, "", name); sub(/:.*/, "", name); current = name; next }
    inside && current == profile && /^    diskPeakGb:/ {
      value = $0; sub(/^    diskPeakGb:[ \t]*/, "", value); sub(/[ \t]*#.*$/, "", value); sub(/[ \t\r]+$/, "", value)
      print value; exit
    }' "$LOAD_PROFILES_FILE")" || disk_peak=""
  case "$disk_peak" in
    "" | *[!0-9.]* | .* | *. | *.*.*) fail "diskPeakGb of the profile $LOAD_DATA in $LOAD_PROFILES_FILE is missing or is not a positive number (read '$disk_peak')" ;;
  esac
  awk -v value="$disk_peak" 'BEGIN { exit !(value + 0 > 0) }' \
    || fail "diskPeakGb of the profile $LOAD_DATA in $LOAD_PROFILES_FILE is not a positive number (read '$disk_peak')"
  # 1 GB is 10^9 bytes; df counts KiB, so the limits are rounded up to whole KiB (a disk that holds a KiB less has less).
  required_kib="$(awk -v peak="$disk_peak" 'BEGIN { b = peak * 1250000000; k = int(b / 1024); if (k * 1024 < b) k++; printf "%d", k }')"
  floor_kib="$(awk -v peak="$disk_peak" 'BEGIN { b = peak * 100000000; k = int(b / 1024); if (k * 1024 < b) k++; printf "%d", k }')"
  required_gb="$(awk -v peak="$disk_peak" 'BEGIN { printf "%.2f", peak * 1.25 }')"
  floor_gb="$(awk -v peak="$disk_peak" 'BEGIN { printf "%.2f", peak * 0.1 }')"
}

# The free KiB of the line of `df -Pk` on the standard input (the column before the capacity, the one with the % sign).
df_available() {
  awk 'NR == 2 { for (i = 3; i <= NF; i++) if ($i ~ /^[0-9]+%$/) { print $(i - 1); found = 1; exit } } END { exit found ? 0 : 1 }'
}

# free_kib vm|host: prints the free KiB of the Docker VM (the root of a container is on the disk where Docker keeps its data) or
# of the disk of LOAD_DISK_PATH on the host; fails when df fails or answers something that is not a whole number.
free_kib() {
  case "$1" in
    vm) space_answer="$(docker run --rm --entrypoint df "$PYTHON_IMAGE" -Pk / 2>/dev/null)" || return 1 ;;
    *) space_answer="$(df -Pk "$LOAD_DISK_PATH" 2>/dev/null)" || return 1 ;;
  esac
  space_free="$(printf '%s\n' "$space_answer" | df_available)" || return 1
  case "$space_free" in
    "" | *[!0-9]*) return 1 ;;
  esac
  printf '%s' "$space_free"
}

gb() { awk -v kib="$1" 'BEGIN { printf "%.2f", kib * 1024 / 1000000000 }'; }

vm_unreadable="cannot read the free space of the Docker VM (docker run --entrypoint df ... -Pk / failed or answered something that is not a number)"
host_unreadable() { echo "cannot read the free space of the disk of the host ($LOAD_DISK_PATH; df -Pk failed or answered something that is not a number)"; }

# Before the cluster: both disks must have required_kib; exit 2 with the needed and the free space of both otherwise.
check_space_before_start() {
  vm_kib="$(free_kib vm)" || fail "$vm_unreadable; LOAD_DATA=$LOAD_DATA needs to know it"
  host_kib="$(free_kib host)" || fail "$(host_unreadable); LOAD_DATA=$LOAD_DATA needs to know it"
  if [ "$vm_kib" -lt "$required_kib" ] || [ "$host_kib" -lt "$required_kib" ]; then
    fail "LOAD_DATA=$LOAD_DATA needs at least $required_gb GB free in the Docker VM and on the disk of the host (1.25 x diskPeakGb $disk_peak GB of $LOAD_PROFILES_FILE); free: $(gb "$vm_kib") GB in the Docker VM, $(gb "$host_kib") GB on the disk of the host ($LOAD_DISK_PATH); make room, or point LOAD_DISK_PATH at the disk that holds the image of the Docker VM"
  fi
}

# During the run: prints the reason and fails when a disk fell below floor_kib or cannot be read.
check_space_once() {
  watch_vm="$(free_kib vm)" || { echo "$vm_unreadable during the run"; return 1; }
  watch_host="$(free_kib host)" || { echo "$(host_unreadable) during the run"; return 1; }
  if [ "$watch_vm" -lt "$floor_kib" ]; then
    echo "the free space of the Docker VM fell to $(gb "$watch_vm") GB, below 10% of diskPeakGb ($floor_gb GB)"
    return 1
  fi
  if [ "$watch_host" -lt "$floor_kib" ]; then
    echo "the free space of the disk of the host ($LOAD_DISK_PATH) fell to $(gb "$watch_host") GB, below 10% of diskPeakGb ($floor_gb GB)"
    return 1
  fi
}

# ---------------------------------------------------------------------------------------------------------------
# Before anything is made: the arguments and the machine
# ---------------------------------------------------------------------------------------------------------------
[ -n "${LOAD_OUT:-}" ] || fail "set LOAD_OUT=<directory> for the results (an existing absolute path outside the repository)"
case "$LOAD_OUT" in
  /*) ;;
  *) fail "LOAD_OUT '$LOAD_OUT' is not an absolute path" ;;
esac
[ -d "$LOAD_OUT" ] || fail "LOAD_OUT '$LOAD_OUT' is not an existing directory"
out="$(cd "$LOAD_OUT" && pwd -P)"
repo_real="$(cd "$TOOLS_REPO" && pwd -P)"
case "$out/" in
  "$repo_real"/*) fail "LOAD_OUT '$LOAD_OUT' is inside the repository; it must be a directory outside the repository (the results are not committed)" ;;
esac
for name in RAMP_START_RATE RAMP_RATE_STEP WARMUP_RATE CHECKOUT_THINK_SECONDS; do
  value="$(printenv "$name" || true)"
  case "$value" in
    *[!0-9.]*) fail "$name must be a number, got '$value'" ;;
  esac
done
for name in RAMP_STEPS RAMP_STEP_SECONDS RAMP_VUS RAMP_MAX_VUS STEADY_SECONDS STEADY_VUS STEADY_MAX_VUS WARMUP_SECONDS WARMUP_CHECKOUTS \
  LOAD_CHECKOUT_VUS LOAD_STEADY_RATE LOAD_TIMEOUT LOAD_POLL LOAD_COOLDOWN LOAD_MIN_CPUS LOAD_MIN_MEM_GIB \
  DISCOVER_CHUNK_COUNT DISCOVER_PRODUCTS LOAD_DATA_TIMEOUT LOAD_DATA_POLL LOAD_SPACE_POLL; do
  value="$(printenv "$name" || true)"
  case "$value" in
    *[!0-9]*) fail "$name must be a whole number, got '$value'" ;;
  esac
done
[ "$LOAD_SPACE_POLL" -ge 1 ] || fail "LOAD_SPACE_POLL must be at least 1 second, got '$LOAD_SPACE_POLL'"
# The keys of the chunks are base64url text (they go into the SQL of the stocks and to k6 as they are).
case "${DISCOVER_CHUNKS:-}" in
  *[!A-Za-z0-9_,-]*) fail "DISCOVER_CHUNKS must be keys of GET /api/v1/sitemap/product-chunks (base64url) separated with commas, got '$DISCOVER_CHUNKS'" ;;
esac
# The anchor as the schema of the chart takes it (demoData.anchor).
if [ -n "${LOAD_DATA_ANCHOR:-}" ] && ! printf '%s\n' "$LOAD_DATA_ANCHOR" | grep -Eqx '[0-9]{4}-[0-9]{2}-[0-9]{2}(T[0-9]{2}:[0-9]{2}:[0-9]{2}Z)?'; then
  fail "LOAD_DATA_ANCHOR must be a date or a UTC moment (2026-11-01 or 2026-11-01T00:00:00Z), got '$LOAD_DATA_ANCHOR'"
fi

# The data: a generated profile needs the read access of the photo pool, checked before anything is made.
case "$LOAD_DATA" in
  seed) ;;
  medium | server | full)
    read_photo_access
    [ -d "$LOAD_DISK_PATH" ] || fail "LOAD_DISK_PATH '$LOAD_DISK_PATH' is not an existing directory (a directory on the disk of the host that holds the image of the Docker VM)"
    read_disk_peak ;;
  *) fail "LOAD_DATA must be seed, medium, server or full, got '$LOAD_DATA'" ;;
esac

docker_info="$(docker info --format '{{.NCPU}} {{.MemTotal}}' 2>/dev/null)" || fail "Docker is not available (docker info failed)"
docker_cpus="${docker_info% *}"
docker_mem="${docker_info#* }"
case "$docker_cpus$docker_mem" in
  "" | *[!0-9]*) fail "cannot read the CPUs and the memory of the Docker VM (docker info answered '$docker_info')" ;;
esac
[ "$docker_cpus" -ge "$LOAD_MIN_CPUS" ] || fail "the Docker VM has $docker_cpus CPUs, the measurement needs at least $LOAD_MIN_CPUS (the pods of prod ask for about 5 vCPU; give Docker more CPUs, or lower LOAD_MIN_CPUS knowingly)"
# MemTotal of the Docker VM is a little below what is set (Docker Desktop: 32 GiB gives 31.3 GiB), so 95% of the limit counts.
[ "$docker_mem" -ge $(( LOAD_MIN_MEM_GIB * 1073741824 * 95 / 100 )) ] || fail "the Docker VM has $(( docker_mem / 1073741824 )) GiB of memory, the measurement needs at least $LOAD_MIN_MEM_GIB GiB (the pods of prod ask for about 8.5 GiB; give Docker more memory, or lower LOAD_MIN_MEM_GIB knowingly)"

# k6 writes its results to LOAD_OUT mounted into its container (k6_run); Docker Desktop mounts only the paths shared with it
# (Settings > Resources > File Sharing). The same mount, with the same user, is tried now with a short container of an image
# that is used anyway, not after the images, the cluster and the data.
if ! mount_answer="$(docker run --rm -u "$(id -u):$(id -g)" -v "$out:/out" --entrypoint sh "$PYTHON_IMAGE" -c 'touch /out/.mount-check && rm /out/.mount-check' 2>&1)"; then
  fail "Docker cannot mount LOAD_OUT '$out' for writing (Docker Desktop: the directory must be shared with Docker, Settings > Resources > File Sharing; the user of the host must be able to write to it); docker answered: $mount_answer"
fi

# The room on the disks, before any image is built or cluster made.
if [ "$LOAD_DATA" != seed ]; then
  check_space_before_start
fi

# The name of the cluster: from COMPOSE_PROJECT_NAME (or the .env of the worktree), at most 32 characters.
project="${COMPOSE_PROJECT_NAME:-}"
if [ -z "$project" ] && [ -f "$TOOLS_REPO/.env" ]; then
  project="$(sed -n 's/^COMPOSE_PROJECT_NAME=//p' "$TOOLS_REPO/.env" | head -n 1)"
fi
project="$(printf '%s' "${project:-shop}" | tr 'A-Z_' 'a-z-' | tr -c 'a-z0-9\n-' '-')"
cluster="ld-$project"
if [ "${#cluster}" -gt 32 ]; then
  cluster="$(printf '%s' "$cluster" | cut -c1-22)-$(printf '%s' "$project" | cksum | cut -d' ' -f1 | cut -c1-8)"
fi
node="k3d-$cluster-server-0"
registry="shop-load-$cluster"
KUBE_NETWORK="k3d-$cluster"
mkdir -p "$TOOLS_CACHE"
KUBE_DIR="$(mktemp -d "$TOOLS_CACHE/kube-XXXXXX")"
apps="api store admin elasticsearch"
sampler_pids=""
built=0
guard_pid=""
k6_pid=""
nap_pid=""
data_job_created=0
k6_name="k6-$cluster"
main_pid=$$
space_reason_file="$KUBE_DIR/space-reason"

# k6 (its container and the docker client that waits for it) stops; the container is removed first, so the client ends by itself.
stop_k6() {
  [ -z "$k6_pid" ] || docker rm -f "$k6_name" >/dev/null 2>&1 || true
  [ -z "$k6_pid" ] || kill "$k6_pid" 2>/dev/null || true
  k6_pid=""
}

cleanup() {
  status=$?
  [ -z "$guard_pid" ] || kill "$guard_pid" 2>/dev/null || true
  trap - EXIT INT TERM
  [ -z "$nap_pid" ] || kill "$nap_pid" 2>/dev/null || true
  stop_k6
  for pid in $sampler_pids; do kill "$pid" 2>/dev/null || true; done
  echo "load: deleting the cluster $cluster"
  k3d cluster delete "$cluster" >/dev/null 2>&1 || echo "load: could not delete the cluster $cluster" >&2
  if [ "$built" = 1 ]; then
    for app in $apps; do docker rmi -f "$registry/$app:r1" >/dev/null 2>&1 || true; done
  fi
  rm -rf "$KUBE_DIR"
  case "$status" in
    0) echo "load: done; the results are in $out" ;;
    *) echo "load: FAILED (exit $status)" >&2 ;;
  esac
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
# TERM is also what the guard of the free space sends: it leaves its reason in a file, and then the Job of the generator is
# stopped and the end is 2 (the cleanup stops k6 and deletes the cluster).
on_term() {
  if [ -s "$space_reason_file" ]; then
    echo "load: $(cat "$space_reason_file"); the Job of the generator and k6 are stopped and the cluster is deleted" >&2
    if [ "$data_job_created" = 1 ]; then
      kc delete job "$data_job" --wait=false --request-timeout=30s >/dev/null 2>&1 || echo "load: the Job $data_job could not be deleted" >&2
    fi
    exit 2
  fi
  exit 143
}
trap on_term TERM

# The guard of the free space: every LOAD_SPACE_POLL seconds it reads both disks; when one fell or cannot be read, it leaves the
# reason in a file and sends TERM to this shell, which waits only in `wait` and in nap, so that it hears it at once.
start_space_guard() {
  (
    trap - EXIT INT TERM
    guard_sleep=""
    trap 'kill "$guard_sleep" 2>/dev/null; exit 0' TERM
    while :; do
      sleep "$LOAD_SPACE_POLL" &
      guard_sleep=$!
      wait "$guard_sleep" || true
      if ! guard_reason="$(check_space_once)"; then
        printf '%s' "$guard_reason" > "$space_reason_file"
        kill -TERM "$main_pid"
        exit 0
      fi
    done
  ) &
  guard_pid=$!
}

kc() { kubectl -n "$namespace" "$@"; }

# ---------------------------------------------------------------------------------------------------------------
# Waiting for the cluster (as in smoke.sh)
# ---------------------------------------------------------------------------------------------------------------
dump_state() {
  echo "---- pods" >&2
  kc get pods -o wide >&2 || true
  echo "---- jobs" >&2
  kc get jobs >&2 || true
  echo "---- events" >&2
  kc get events --sort-by=.lastTimestamp 2>/dev/null | tail -n 25 >&2 || true
}

# Fails at once on what cannot recover: a job out of attempts, a container that keeps crashing, an image that is not there.
check_failures() {
  failed="$(kc get jobs -l shop/job-kind=version -o jsonpath='{range .items[*]}{.metadata.name}{" "}{.status.conditions[?(@.type=="Failed")].status}{"\n"}{end}' | awk '$2 == "True" {print $1}')"
  if [ -n "$failed" ]; then
    echo "load: failed jobs: $failed" >&2
    dump_state
    return 1
  fi
  broken="$(kc get pods -o jsonpath='{range .items[*]}{.metadata.name}{range .status.initContainerStatuses[*]}{" "}{.state.waiting.reason}:{.restartCount}{end}{range .status.containerStatuses[*]}{" "}{.state.waiting.reason}:{.restartCount}{end}{"\n"}{end}' \
    | awk '{ for (i = 2; i <= NF; i++) { split($i, p, ":"); if ((p[1] == "CrashLoopBackOff" && p[2] >= 3) || p[1] == "ErrImageNeverPull" || p[1] == "InvalidImageName") { print $1 ": " p[1]; break } } }')"
  if [ -n "$broken" ]; then
    echo "load: pods that cannot start:" >&2
    echo "$broken" >&2
    dump_state
    return 1
  fi
}

# Every version job complete (there must be six, backfill among them; a running job has no succeeded, and its line must
# count), all deployments and statefulsets rolled out with the new pods ready.
all_ready() {
  # name=succeeded per job: a job that still runs is "name=" and is not ready.
  jobs="$(kc get jobs -l shop/job-kind=version -o jsonpath='{range .items[*]}{.metadata.name}={.status.succeeded}{"\n"}{end}')"
  [ "$(echo "$jobs" | grep -c .)" -ge 6 ] || return 1
  [ -z "$(echo "$jobs" | grep . | grep -v '=1$' || true)" ] || return 1
  not_ready="$(kc get deployments -o jsonpath='{range .items[*]}{.metadata.generation}{" "}{.status.observedGeneration}{" "}{.status.replicas}{" "}{.status.updatedReplicas}{" "}{.status.readyReplicas}{" "}{.status.availableReplicas}{"\n"}{end}' \
    | awk 'NF != 6 || $1 != $2 || $3 != 1 || $4 != 1 || $5 != 1 || $6 != 1 {print}')"
  [ -z "$not_ready" ] || return 1
  not_ready="$(kc get statefulsets -o jsonpath='{range .items[*]}{.status.readyReplicas}{" "}{.status.updatedReplicas}{"\n"}{end}' | awk '$1 != 1 || $2 != 1 {print}')"
  [ -z "$not_ready" ]
}

wait_ready() {
  deadline=$(( $(date +%s) + LOAD_TIMEOUT ))
  while :; do
    check_failures || fail "pods of the cluster cannot start (above); the pods are not ready"
    if all_ready; then
      echo "load: all jobs complete and all deployments ready"
      return 0
    fi
    if [ "$(date +%s)" -ge "$deadline" ]; then
      dump_state
      fail "the pods are not ready in ${LOAD_TIMEOUT} s"
    fi
    nap "$LOAD_POLL"
  done
}

# k3s installs Traefik and its CRDs (the Middleware of the chart) in the background after the node is ready.
wait_for_traefik() {
  deadline=$(( $(date +%s) + 300 ))
  until kubectl get crd middlewares.traefik.io >/dev/null 2>&1; do
    if [ "$(date +%s)" -ge "$deadline" ]; then fail "the CRDs of Traefik were not installed in 300 s (the pods are not ready)"; fi
    nap 3
  done
  kubectl wait --for=condition=established crd/middlewares.traefik.io --timeout=120s >/dev/null
}

# ---------------------------------------------------------------------------------------------------------------
# k6
# ---------------------------------------------------------------------------------------------------------------
# The settings of the k6 scripts that the caller gave (DISCOVER_CHUNKS also the keys of the chunks whose stocks were raised),
# as `-e NAME=value` options.
script_settings() {
  settings=""
  for name in RAMP_START_RATE RAMP_RATE_STEP RAMP_STEPS RAMP_STEP_SECONDS RAMP_VUS RAMP_MAX_VUS STEADY_SECONDS STEADY_VUS STEADY_MAX_VUS \
    WARMUP_RATE WARMUP_SECONDS WARMUP_CHECKOUTS CHECKOUT_THINK_SECONDS DISCOVER_CHUNKS DISCOVER_CHUNK_COUNT DISCOVER_PRODUCTS; do
    value="$(printenv "$name" || true)"
    [ -z "$value" ] || settings="$settings -e $name=$value"
  done
  printf '%s' "$settings"
}

# 125 is the exit code of `docker run` itself (a mount refused, an image or a network missing), not of k6.
docker_not_started="Docker did not start k6 (docker run exit 125; above), the shop was not asked"

# run_k6 <script> <log name> [-e NAME=value...]: runs a script of load/ against the store and the API of the cluster, its
# output in LOAD_OUT/<log name>.txt and then on the terminal. Prints the exit code of k6 in $k6_status (0, 99 for crossed
# thresholds).
run_k6() {
  script="$1" logname="$2"
  shift 2
  k6_status=0
  # shellcheck disable=SC2046 # script_settings prints options
  # In the background and waited for with `wait`, so that the guard of the free space can end the wait; the container has a name,
  # so that it can be removed (stop_k6).
  k6_run "$KUBE_NETWORK" "$out" "$script" --name "$k6_name" -e "STORE_URL=http://$node:$traefik_port" -e STORE_HOST=localhost -e "API_URL=http://$node:$traefik_port" \
    -e API_HOST=api.localhost -e STORE_ORIGIN=http://localhost $(script_settings) "$@" > "$out/$logname.txt" 2>&1 &
  k6_pid=$!
  wait "$k6_pid" || k6_status=$?
  k6_pid=""
  cat "$out/$logname.txt"
}

start_samplers() {
  : > "$out/top.log"
  : > "$out/node-stats.log"
  (
    while :; do
      stamp="$(date +%s)"
      kc top pods --no-headers 2>/dev/null | sed "s/^/$stamp /" >> "$out/top.log" || true
      sleep 10
    done
  ) &
  sampler_pids="$sampler_pids $!"
  (
    while :; do
      stamp="$(date +%s)"
      docker stats --no-stream --format '{{.CPUPerc}} {{.MemUsage}}' "$node" 2>/dev/null | sed "s/^/$stamp /" >> "$out/node-stats.log" || true
      sleep 10
    done
  ) &
  sampler_pids="$sampler_pids $!"
}

# ---------------------------------------------------------------------------------------------------------------
# The data (LOAD_DATA)
# ---------------------------------------------------------------------------------------------------------------
# One Job of the CronJob demo-data (its profile and anchor are values of the chart), waited for: Complete goes on, Failed or
# no end in LOAD_DATA_TIMEOUT ends with 2, with the end of its log. Its whole log is in LOAD_OUT either way.
run_generator() {
  create_photo_secret
  kc create job "$data_job" --from=cronjob/demo-data >/dev/null || fail "the Job $data_job of the generator could not be created from the CronJob demo-data"
  data_job_created=1
  data_started="$(date +%s)"
  data_limit="${LOAD_DATA_TIMEOUT:-}"
  if [ -z "$data_limit" ]; then
    data_deadline="$(kc get job "$data_job" -o jsonpath='{.spec.activeDeadlineSeconds}' 2>/dev/null || true)"
    case "$data_deadline" in
      "" | *[!0-9]*) data_deadline=57600 ;;
    esac
    data_limit=$(( data_deadline + 600 ))
  fi
  echo "load: the Job $data_job generates the profile $LOAD_DATA; waiting at most $data_limit s"
  data_state=""
  data_reported="$data_started"
  while :; do
    data_now="$(date +%s)"
    data_conditions="$(kc get job "$data_job" -o jsonpath='{.status.conditions[?(@.status=="True")].type}' 2>/dev/null || true)"
    case " $data_conditions " in
      *" Failed "*) data_state=failed; break ;;
      *" Complete "*) data_state=complete; break ;;
    esac
    if [ $(( data_now - data_started )) -ge "$data_limit" ]; then data_state=timeout; break; fi
    if [ $(( data_now - data_reported )) -ge 600 ]; then
      echo "load: the generator has run $(( data_now - data_started )) s"
      data_reported="$data_now"
    fi
    nap "$LOAD_DATA_POLL"
  done
  data_seconds=$(( $(date +%s) - data_started ))
  save_generator_log
  case "$data_state" in
    complete)
      generator_steps > "$out/demo-data-steps.txt"
      echo "load: the generator finished in $data_seconds s; its steps:"
      cat "$out/demo-data-steps.txt" ;;
    *)
      echo "---- the end of the log of the Job $data_job (all of it in $out/demo-data.log)" >&2
      tail -n 40 "$out/demo-data.log" >&2
      dump_state
      if [ "$data_state" = failed ]; then
        fail "the generator failed (the Job $data_job, after $data_seconds s; its log above)"
      fi
      fail "the generator did not finish in $data_limit s (the Job $data_job; its log above; LOAD_DATA_TIMEOUT sets the limit)"
      ;;
  esac
}

# The keys of the chunks of the sitemap whose stocks are raised and that k6 reads: those of DISCOVER_CHUNKS, or
# DISCOVER_CHUNK_COUNT (3) chosen at random from the API of the cluster. The cut of the chunks is kept in the cache of the
# api for an hour, so it is cleared first: a cut taken before the generator would name the chunks of the seed.
choose_chunks() {
  chunks="$(printf '%s' "${DISCOVER_CHUNKS:-}" | tr ',' '\n' | awk 'NF && !seen[$0]++ { printf "%s%s", (n++ ? "," : ""), $0 }')"
  [ -z "$chunks" ] || return 0
  kc exec deployment/api -- bin/console cache:pool:clear cache.sitemap >/dev/null 2>&1 \
    || echo "load: the cache of the chunks of the sitemap could not be cleared (bin/console cache:pool:clear cache.sitemap); the chunks may be an hour old" >&2
  chunks_answer="$(docker run --rm --network "$KUBE_NETWORK" "$CURL_IMAGE" -fsS --max-time 600 -H 'Host: api.localhost' \
    "http://$node:$traefik_port/api/v1/sitemap/product-chunks")" || fail "the chunks of the sitemap could not be read (GET /api/v1/sitemap/product-chunks failed; above)"
  chunk_keys="$(printf '%s' "$chunks_answer" | grep -o '"key": *"[A-Za-z0-9_-]*"' | sed 's/.*"\([A-Za-z0-9_-]*\)"$/\1/' || true)"
  [ -n "$chunk_keys" ] || fail "the API named no chunks of the sitemap (GET /api/v1/sitemap/product-chunks answered '$(printf '%s' "$chunks_answer" | head -c 300)')"
  chunk_count="${DISCOVER_CHUNK_COUNT:-3}"
  [ "$chunk_count" -ge 1 ] || chunk_count=1
  chunks="$(printf '%s\n' "$chunk_keys" | awk 'BEGIN { srand() } { print rand() "\t" $0 }' | sort -n | cut -f2- | head -n "$chunk_count" \
    | awk '{ printf "%s%s", (NR > 1 ? "," : ""), $0 }')"
}

# The UPDATE of the stocks of the variants of the products of the chunks $chunks only. A key is what SitemapChunkKey::encode()
# makes (apps/api/src/Catalog/Application/Query): base64url without padding of {"f": <uuid>|null, "t": <uuid>|null}, the
# products from f inclusive to t exclusive by id, an open end when null (DbalSitemapProductSource::publishedInRange). A key of
# another shape stops the UPDATE with an error (the cast of 'not a chunk key ...' to uuid), so that it never raises everything.
chunk_stock_sql() {
  printf '%s' "WITH keys AS (
  SELECT k, convert_from(decode(rpad(translate(k, '-_', '+/'), (length(k) + 3) / 4 * 4, '='), 'base64'), 'UTF8')::jsonb AS j
  FROM unnest(ARRAY[$(printf '%s' "$chunks" | sed "s/[^,][^,]*/'&'/g")]::text[]) AS k
), checked AS (
  SELECT k, j, CASE WHEN jsonb_typeof(j) <> 'object' THEN false
    ELSE j ? 'f' AND j ? 't' AND j - 'f' - 't' = '{}'::jsonb AND jsonb_typeof(j -> 'f') IN ('null', 'string') AND jsonb_typeof(j -> 't') IN ('null', 'string') END AS valid
  FROM keys
), ranges AS (
  SELECT CASE WHEN valid THEN (j ->> 'f')::uuid ELSE ('not a chunk key ' || k)::uuid END AS f,
    CASE WHEN valid THEN (j ->> 't')::uuid ELSE ('not a chunk key ' || k)::uuid END AS t
  FROM checked
)
UPDATE inventory_stock_item s SET physical = s.physical + 100000, version = s.version + 1, updated_at = now()
FROM catalog_product_variant v, ranges r
WHERE v.id = s.variant_id AND s.physical > 0 AND (r.f IS NULL OR v.product_id >= r.f) AND (r.t IS NULL OR v.product_id < r.t)"
}

# The sizes of the data before the measurement (for the report): best effort, a failure is written down and the measurement
# goes on.
data_sizes() {
  echo "== postgres"
  kc exec statefulset/postgres -- psql -U shop -d shop -v ON_ERROR_STOP=1 \
    -c '\echo == the databases (bytes)' \
    -c "SELECT datname AS database_name, pg_database_size(datname) AS bytes, pg_size_pretty(pg_database_size(datname)) AS size FROM pg_database WHERE NOT datistemplate ORDER BY 2 DESC" \
    -c '\echo == the tables of the database shop (bytes; estimated_rows from the statistics)' \
    -c "SELECT c.relname AS table_name, pg_table_size(c.oid) AS table_bytes, pg_indexes_size(c.oid) AS index_bytes, pg_total_relation_size(c.oid) AS total_bytes, pg_size_pretty(pg_total_relation_size(c.oid)) AS total, c.reltuples::bigint AS estimated_rows FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind IN ('r', 'p') AND n.nspname = 'public' ORDER BY total_bytes DESC" \
    -c '\echo == the indexes of the database shop (bytes)' \
    -c "SELECT i.indexrelid::regclass AS index_name, t.relname AS table_name, pg_relation_size(i.indexrelid) AS bytes, pg_size_pretty(pg_relation_size(i.indexrelid)) AS size FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = 'public' ORDER BY bytes DESC" \
    2>&1 || { echo "(psql failed: the sizes of Postgres are not known)"; echo "load: the sizes of Postgres could not be read (sizes.txt)" >&2; }
  echo "== elasticsearch: the indices (bytes) and the aliases"
  kc exec statefulset/elasticsearch -- curl -fsS 'http://localhost:9200/_cat/indices?v&bytes=b&s=index' 'http://localhost:9200/_cat/aliases?v' \
    2>&1 || { echo "(curl failed: the sizes of Elasticsearch are not known)"; echo "load: the sizes of Elasticsearch could not be read (sizes.txt)" >&2; }
}

# ---------------------------------------------------------------------------------------------------------------
# The measurement
# ---------------------------------------------------------------------------------------------------------------
if [ "$LOAD_DATA" != seed ]; then
  start_space_guard
fi

log "images"
if [ "$LOAD_BUILD" = 1 ]; then
  built=1
  for app in api store admin; do
    echo "load: docker build --target prod $app"
    docker build -q --target prod -t "$registry/$app:r1" "$TOOLS_REPO/apps/$app" >/dev/null
  done
  echo "load: docker build elasticsearch (infra/elasticsearch)"
  docker build -q -t "$registry/elasticsearch:r1" "$TOOLS_REPO/infra/elasticsearch" >/dev/null
fi
for app in $apps; do
  docker image inspect "$registry/$app:r1" >/dev/null 2>&1 || fail "the image $registry/$app:r1 is missing (run without LOAD_BUILD=0)"
done

log "cluster $cluster ($K3S_IMAGE)"
k3d cluster delete "$cluster" >/dev/null 2>&1 || true
# metrics-server stays on (kubectl top for the report); the load balancer is not needed.
k3d cluster create "$cluster" --no-lb --image "$K3S_IMAGE" --wait --timeout 300s --k3s-arg '--disable=servicelb@server:*' >/dev/null \
  || fail "the cluster $cluster could not be created (is the Docker VM out of resources?)"
k3d kubeconfig get "$cluster" | sed -E "s#server: https://[^:]+:[0-9]+#server: https://$node:6443#" > "$KUBE_DIR/config"
# shellcheck disable=SC2046 # the names have no spaces
sh "$here/import-images.sh" "$cluster" "$node" $(for app in $apps; do echo "$registry/$app:r1"; done) \
  || fail "the images could not be imported into the node $node"

log "install (profile load, LOAD_DATA=$LOAD_DATA)"
# A generated profile: the profile and the anchor of the CronJob demo-data and the Secret it reads the photo pool from.
data_values=""
if [ "$LOAD_DATA" != seed ]; then
  data_values="--set demoData.profile=$LOAD_DATA --set demoData.photosSecretName=$photos_secret${LOAD_DATA_ANCHOR:+ --set-string demoData.anchor=$LOAD_DATA_ANCHOR}"
fi
wait_for_traefik
# shellcheck disable=SC2086 # data_values is a list of options without spaces in them
helm_cluster upgrade --install shop charts/shop --namespace "$namespace" --create-namespace \
  -f environments/ci/values.yaml -f environments/load/values.yaml --set image.tag=r1 --set "image.registry=$registry" $data_values >/dev/null
wait_ready

traefik_port="$(kubectl -n kube-system get service traefik -o jsonpath='{.spec.ports[?(@.name=="web")].nodePort}')"
[ -n "$traefik_port" ] || fail "the service of Traefik has no node port (the pods are not ready)"

data_seconds=""
if [ "$LOAD_DATA" != seed ]; then
  log "demo data (bin/generate-demo --profile=$LOAD_DATA)"
  run_generator
fi

log "data"
products="$(kc exec statefulset/postgres -- psql -U shop -d shop -tAc 'SELECT count(*) FROM catalog_product' | tr -d '[:space:]')"
[ "${products:-0}" -gt 0 ] || fail "the demo data is not there (0 products)"
# The stocks, so that the checkout does not use them up: a variant with a physical stock above 0 gets 100 000 more. A direct
# write for the data of this throwaway cluster only (the exception of the spec to writing through Inventory). The seed: every
# such variant. A generated profile: only the variants of the products of the chunks that k6 reads (an UPDATE of every
# variant would rewrite tens of millions of rows), and k6 gets their keys (DISCOVER_CHUNKS).
# Not a pipe into tr: the exit code of a pipe is the last one, and a failed psql must end the measurement (a checkout on
# the stocks of the demo data would use them up and skew the share of 409 in the results).
if [ "$LOAD_DATA" = seed ]; then
  stock_sql='UPDATE inventory_stock_item SET physical = physical + 100000, version = version + 1, updated_at = now() WHERE physical > 0'
  stock_scope="every variant"
else
  choose_chunks
  DISCOVER_CHUNKS="$chunks"
  export DISCOVER_CHUNKS
  stock_sql="$(chunk_stock_sql)"
  stock_scope="the variants of the chunks $chunks"
fi
update_answer="$(kc exec statefulset/postgres -- psql -U shop -d shop -v ON_ERROR_STOP=1 -tAc "$stock_sql")" \
  || fail "the stocks could not be raised (the UPDATE of $stock_scope failed; above)"
raised="$(printf '%s' "$update_answer" | tr -d '\r')"
printf '%s\n' "$raised" | grep -Eqx 'UPDATE [1-9][0-9]*' \
  || fail "the stocks were not raised (the UPDATE of $stock_scope answered '$raised', expected UPDATE <n> with n of at least 1)"
echo "load: $products products; stocks raised: $raised ($stock_scope)"
data_sizes > "$out/sizes.txt"

# The threads of FrankenPHP in the API decide how many checkouts run at once (D18): the log of the server names them.
api_threads="$(kc logs deployment/api 2>/dev/null | sed -n 's/.*"total_threads":\([0-9]*\).*/\1/p' | head -n 1)"
case "$api_threads" in
  "" | *[!0-9]*) api_threads=$(( 2 * docker_cpus )) ;;
esac
checkout_vus="${LOAD_CHECKOUT_VUS:-$(( api_threads / 4 ))}"
[ "$checkout_vus" -ge 1 ] || checkout_vus=1

{
  echo "date: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "revision: $(git -C "$TOOLS_REPO" rev-parse HEAD 2>/dev/null || echo unknown)$(git -C "$TOOLS_REPO" status --porcelain 2>/dev/null | grep -q . && echo ' (with uncommitted changes)' || true)"
  echo "cluster: $cluster ($K3S_IMAGE, profile load)"
  echo "host: $(uname -srm)"
  echo "docker vm: $docker_cpus CPUs, $(( docker_mem / 1048576 )) MiB, $(docker info --format '{{.OperatingSystem}}, Docker {{.ServerVersion}}' 2>/dev/null || echo unknown)"
  echo "host load: $(uptime 2>/dev/null || echo unknown)"
  if [ "$LOAD_DATA" = seed ]; then
    echo "LOAD_DATA: seed (the demo data of the Job initialize)"
  else
    echo "LOAD_DATA: $LOAD_DATA (bin/generate-demo --profile=$LOAD_DATA, anchor ${LOAD_DATA_ANCHOR:-the start of the UTC day}; the Job took $data_seconds s, its steps in demo-data-steps.txt)"
  fi
  echo "products: $products; stock items raised by 100000: $raised ($stock_scope)"
  echo "chunks of the sitemap read by k6 (DISCOVER_CHUNKS): ${DISCOVER_CHUNKS:-chosen at random by k6 (DISCOVER_CHUNK_COUNT ${DISCOVER_CHUNK_COUNT:-3})}; products: ${DISCOVER_PRODUCTS:-60}"
  echo "sizes of the data: sizes.txt"
  echo "api threads (FrankenPHP): $api_threads; checkout virtual users: $checkout_vus"
  echo "k6 image: $K6_IMAGE"
  echo "other containers on the host:"
  docker ps --format '  {{.Names}} ({{.Image}})' 2>/dev/null | grep -v "$node" || true
  echo "images of the pods:"
  kc get pods -o jsonpath='{range .items[*]}{range .spec.containers[*]}  {.name} {.image}{"\n"}{end}{end}' | sort -u
  echo "resources of the containers (requests and limits):"
  kc get pods -o jsonpath='{range .items[*]}{range .spec.containers[*]}  {.name} {.resources}{"\n"}{end}{end}' | sort -u
} > "$out/environment.txt"

start_samplers

log "warm-up"
run_k6 warmup.js warmup
[ "$k6_status" -ne 125 ] || fail "the warm-up failed: $docker_not_started"
[ "$k6_status" -eq 0 ] || fail "the warm-up failed (k6 exit $k6_status; above): the shop does not answer as the scripts expect"

log "ramp to saturation (reads and carts)"
rm -f "$out/steady-rate"
run_k6 saturate.js ramp
# 99 is the end on saturation (a threshold of a step with abortOnFail); anything else is a run that did not happen.
case "$k6_status" in
  0 | 99) ;;
  125) echo "load: the ramp failed: $docker_not_started" >&2; exit 1 ;;
  *) echo "load: the ramp failed (k6 exit $k6_status; above)" >&2; exit 1 ;;
esac
[ -s "$out/ramp.json" ] && [ -s "$out/steady-rate" ] || { echo "load: the ramp wrote no result (above)" >&2; exit 1; }

steady_rate="${LOAD_STEADY_RATE:-$(cat "$out/steady-rate")}"
echo "load: cooling down for ${LOAD_COOLDOWN} s; steady run: $steady_rate iterations/s and $checkout_vus checkout users"
nap "$LOAD_COOLDOWN"

log "steady run (70% of the saturation, with the checkout)"
run_k6 steady.js steady -e "STEADY_RATE=$steady_rate" -e "CHECKOUT_VUS=$checkout_vus"
# The steady run has no thresholds: any exit code but 0 is a run that did not happen.
[ "$k6_status" -ne 125 ] || { echo "load: the steady run failed: $docker_not_started" >&2; exit 1; }
[ "$k6_status" -eq 0 ] || { echo "load: the steady run failed (k6 exit $k6_status; above)" >&2; exit 1; }
[ -s "$out/steady.json" ] || { echo "load: the steady run wrote no result (above)" >&2; exit 1; }
echo "load: peak CPU and memory per pod: see top.log; the node: node-stats.log"
