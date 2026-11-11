#!/bin/sh
# The daily verification of the backups (CronJob `backup-verify`, docs/features/deploy-ops/spec.md, O3). It reads the restic
# repository in S3, never the production database, and fails (non-zero exit, a message that says what is wrong) when any of
# these is not so:
#   1. the newest snapshot with the tag nightly of this cluster (the host is the identifier of the cluster) is younger than 26 h;
#   2. restic check passes, reading a seventh of the data (--read-data-subset=n/7, n the day of the week, so every byte is read
#      within a week) or all of it (VERIFY_READ_DATA=all);
#   3. both dumps of that snapshot restore into the temporary Postgres of the pod (restic dump into pg_restore through a pipe,
#      nothing of them is written to a disk), the table of migrations of shop is not empty, and the realms shop and backoffice
#      are in keycloak;
#   4. the number of files under objects/ in the snapshot (restic ls) is the one in manifest.json.
# Each step stops the script at its first error (set -e). Nothing secret is printed or put on a command line: the access to the
# repository is in the environment (RESTIC_PASSWORD, AWS_*).
#
# Settings (the environment): CLUSTER_ID_FILE (/cluster/id: the UID of the namespace kube-system, written by the first container
# of the pod), BACKUP_TAG (nightly), VERIFY_READ_DATA (subset|all), RESTIC_BIN (restic), and PGHOST and PGUSER (the temporary
# Postgres, read by psql and pg_restore). RESTIC_REPOSITORY and the rest of the restic settings are those of restic itself.
set -eu

cluster_id_file="${CLUSTER_ID_FILE:-/cluster/id}"
tag="${BACKUP_TAG:-nightly}"
read_data="${VERIFY_READ_DATA:-subset}"
restic_bin="${RESTIC_BIN:-restic}"
max_age_hours=26
max_age=$((max_age_hours * 3600))

fail() {
  printf '%s\n' "backup-verify: $*" >&2
  exit 1
}

cluster_id() {
  [ -f "$cluster_id_file" ] || fail "no identifier of the cluster ($cluster_id_file is missing)"
  id="$(tr -d ' \t\r\n' < "$cluster_id_file")"
  printf '%s' "$id" | grep -Eq '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' ||
    fail "the identifier of the cluster in $cluster_id_file is not a UID"
  printf '%s' "$id"
}

# number <digits>: the value of a decimal number of two digits (the shell reads a leading 0 as octal).
number() {
  case "$1" in
    0?) printf '%s' "${1#0}" ;;
    *) printf '%s' "$1" ;;
  esac
}

# epoch <time>: the seconds since 1970-01-01T00:00:00Z of a time of restic (RFC 3339: 2026-11-11T03:00:05.123456789+01:00).
# The date of the system is not asked to read it: the parsers of date differ in the offsets and the fractions of a second.
epoch() {
  printf '%s' "$1" | grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]+)?(Z|[+-][0-9]{2}:[0-9]{2})$' ||
    fail "cannot read the time of the snapshot: $1"
  year="$(printf '%s' "$1" | cut -c1-4)"
  month="$(number "$(printf '%s' "$1" | cut -c6-7)")"
  day="$(number "$(printf '%s' "$1" | cut -c9-10)")"
  hour="$(number "$(printf '%s' "$1" | cut -c12-13)")"
  minute="$(number "$(printf '%s' "$1" | cut -c15-16)")"
  second="$(number "$(printf '%s' "$1" | cut -c18-19)")"
  zone="$(printf '%s' "$1" | cut -c20- | sed 's/^\.[0-9]*//')"
  offset=0
  case "$zone" in
    Z) ;;
    [+-]*)
      offset=$(($(number "$(printf '%s' "$zone" | cut -c2-3)") * 3600 + $(number "$(printf '%s' "$zone" | cut -c5-6)") * 60))
      [ "$(printf '%s' "$zone" | cut -c1)" = "-" ] && offset=$((-offset))
      ;;
  esac
  # The days since 1970-01-01 of a date of the Gregorian calendar (the year starts in March, so the leap day is the last).
  [ "$month" -le 2 ] && year=$((year - 1))
  era=$((year / 400))
  year_of_era=$((year - era * 400))
  day_of_year=$(((153 * ((month + 9) % 12) + 2) / 5 + day - 1))
  day_of_era=$((year_of_era * 365 + year_of_era / 4 - year_of_era / 100 + day_of_year))
  days=$((era * 146097 + day_of_era - 719468))
  printf '%s' $((days * 86400 + hour * 3600 + minute * 60 + second - offset))
}

# 1. The newest snapshot of the tag and of this cluster, and its age. Sets snapshot (its id).
find_snapshot() {
  listing="$("$restic_bin" snapshots --json --tag "$tag" --host "$1")" ||
    fail "cannot list the snapshots with the tag $tag of the cluster $1 (restic exit code $?): the repository is not readable"
  times="$(printf '%s' "$listing" | grep -o '"time":"[^"]*"' | sed 's/^"time":"//; s/"$//')"
  ids="$(printf '%s' "$listing" | grep -o '"id":"[0-9a-f]*"' | sed 's/^"id":"//; s/"$//')"
  [ -n "$times" ] || fail "no snapshot with the tag $tag of the cluster $1 in the repository"
  [ "$(printf '%s\n' "$times" | wc -l)" -eq "$(printf '%s\n' "$ids" | wc -l)" ] ||
    fail "cannot match the times of the snapshots with their identifiers"
  newest=-1
  index=0
  for time in $times; do
    index=$((index + 1))
    seconds="$(epoch "$time")"
    if [ "$seconds" -gt "$newest" ]; then
      newest="$seconds"
      newest_time="$time"
      snapshot="$(printf '%s\n' "$ids" | sed -n "${index}p")"
    fi
  done
  short="$(printf '%s' "$snapshot" | cut -c1-8)"
  age=$(($(date +%s) - newest))
  [ "$age" -le "$max_age" ] ||
    fail "the newest snapshot with the tag $tag ($short, $newest_time) is older than $max_age_hours h: $((age / 3600)) h"
  echo "backup-verify: the newest snapshot $short ($newest_time) is $((age / 60)) minutes old"
}

# 2. The structure of the repository and a part of the data (all of it with VERIFY_READ_DATA=all).
check_repository() {
  case "$read_data" in
    subset) read_data_option="--read-data-subset=$(date +%u)/7" ;;
    all) read_data_option="--read-data" ;;
  esac
  echo "backup-verify: restic check $read_data_option"
  "$restic_bin" check --retry-lock 30m "$read_data_option" || fail "restic check failed (exit code $?): the repository is damaged or not readable"
}

# restore_database <name>: the dump of the snapshot into a new database of the temporary Postgres, through a pipe. The status of
# restic dump is kept in a file: the status of a pipeline is that of its last command only.
restore_database() {
  database="$1"
  psql -X -v ON_ERROR_STOP=1 -c "CREATE DATABASE $database" > /dev/null || fail "cannot create the database $database in the temporary Postgres"
  rm -f "$work/dump-status"
  restore_status=0
  {
    if "$restic_bin" dump "$snapshot" "/work/dumps/$database.dump"; then
      echo 0 > "$work/dump-status"
    else
      echo "$?" > "$work/dump-status"
    fi
  } | pg_restore --no-owner --no-privileges -d "$database" || restore_status=$?
  # The repository first: a dump that restic could not read to the end makes pg_restore fail too, and the cause is restic's.
  [ "$(cat "$work/dump-status")" = 0 ] || fail "restic dump of $database.dump failed (exit code $(cat "$work/dump-status")): the dump is not readable"
  [ "$restore_status" -eq 0 ] || fail "pg_restore of the dump of $database failed (exit code $restore_status): the dump does not restore"
  echo "backup-verify: the dump of $database restored"
}

# query <database> <statement>: one result of the temporary Postgres.
query() {
  psql -X -tA -v ON_ERROR_STOP=1 -d "$1" -c "$2"
}

# 3. Both dumps, and what the databases hold.
check_dumps() {
  psql -X -tA -c "SELECT 1" > /dev/null || fail "the temporary Postgres does not answer"
  restore_database shop
  migrations="$(query shop "SELECT count(*) FROM doctrine_migration_versions")" ||
    fail "cannot read doctrine_migration_versions of the restored shop"
  [ "${migrations:-0}" -gt 0 ] || fail "doctrine_migration_versions of the restored shop is empty"
  echo "backup-verify: $migrations migrations in shop"
  restore_database keycloak
  realms="$(query keycloak "SELECT name FROM realm")" || fail "cannot read the realms of the restored keycloak"
  for realm in shop backoffice; do
    printf '%s\n' "$realms" | grep -qx "$realm" || fail "no realm $realm in the restored keycloak"
  done
  echo "backup-verify: the realms shop and backoffice are in keycloak"
}

# 4. The files under objects/ in the snapshot against the manifest.
check_objects() {
  manifest="$("$restic_bin" dump "$snapshot" /work/manifest.json)" || fail "cannot read manifest.json of the snapshot"
  expected="$(printf '%s' "$manifest" | tr -d ' \n' | sed -n 's/.*"objects":{"count":\([0-9][0-9]*\).*/\1/p')"
  [ -n "$expected" ] || fail "no count of the objects in manifest.json of the snapshot"
  # The listing goes through a pipe (it has a line per object); the status of restic ls is kept in a file.
  rm -f "$work/ls-status"
  found="$({
    if "$restic_bin" ls --json --recursive "$snapshot" /work/objects; then
      echo 0 > "$work/ls-status"
    else
      echo "$?" > "$work/ls-status"
    fi
  } | sed -n '/"type":"file"/p' | wc -l | tr -d ' ')"
  [ "$(cat "$work/ls-status")" = 0 ] || fail "restic ls of the objects of the snapshot failed (exit code $(cat "$work/ls-status")): cannot list the objects"
  [ "$found" -eq "$expected" ] || fail "the snapshot has $found objects, $expected in the manifest"
  echo "backup-verify: $found objects, as in the manifest"
}

case "$read_data" in
  subset | all) ;;
  *) fail "VERIFY_READ_DATA '$read_data' is neither subset nor all" ;;
esac
host="$(cluster_id)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
find_snapshot "$host"
check_repository
check_dumps
check_objects
echo "backup-verify: ok"
