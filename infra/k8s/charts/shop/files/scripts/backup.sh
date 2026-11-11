#!/bin/sh
# The nightly backup (CronJob `backup`, docs/features/deploy-ops/spec.md, O1): both databases (pg_dump) and the objects of
# the bucket of the product images (rclone) go to the volume of the pod, with a manifest, and from there to the restic
# repository in S3 as one snapshot.
#
#   backup.sh dump       pg_dump of shop and keycloak into dumps/ and the migrations of shop into meta/    (image postgres)
#   backup.sh objects    rclone sync of the bucket product-images into objects/, the count and size in meta/ (image rclone)
#   backup.sh snapshot   manifest.json, then restic: init when there is no repository, unlock, backup, forget   (image restic)
#   backup.sh all        the three stages in this process (the tests; the pod runs each in the container of its tool)
#
# Every stage stops at its first error (set -e), and the pod runs them one after another: an error in any step before
# `restic backup` fails the Job and no snapshot is made. The snapshot stage also refuses stale or missing results. The
# secrets are in the environment of each container (PGPASSWORD, RCLONE_CONFIG_GARAGE_*, RESTIC_PASSWORD, AWS_*); they are
# never printed or passed on a command line.
#
# Settings (the environment): WORK_DIR (/work), CLUSTER_ID_FILE (/cluster/id: the UID of the namespace kube-system, written
# by the first container of the pod), REVISION (the tag of the api image), BACKUP_TAG (nightly), BACKUP_RETENTION (7d),
# POSTGRES_HOST (postgres). RESTIC_REPOSITORY and the rest of the restic settings are those of restic itself.
set -eu

work="${WORK_DIR:-/work}"
cluster_id_file="${CLUSTER_ID_FILE:-/cluster/id}"
retention="${BACKUP_RETENTION:-7d}"
tag="${BACKUP_TAG:-nightly}"
pg_host="${POSTGRES_HOST:-postgres}"
databases="shop keycloak"
bucket=product-images

fail() {
  printf '%s\n' "backup: $*" >&2
  exit 1
}

# json_string <text>: the text as a JSON string (backslashes and quotes escaped; the migration versions have backslashes).
json_string() {
  printf '"%s"' "$(printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g')"
}

stage_dump() {
  # Nothing of a previous run may be taken for a result of this one.
  rm -rf "$work/dumps" "$work/dumps.partial" "$work/meta" "$work/manifest.json"
  mkdir -p "$work/dumps.partial" "$work/meta"
  echo "backup: reading the migrations of shop"
  psql -h "$pg_host" -U postgres -d shop -tAc "SELECT version FROM doctrine_migration_versions ORDER BY version" > "$work/meta/migrations"
  [ -s "$work/meta/migrations" ] || fail "doctrine_migration_versions of shop is empty"
  for database in $databases; do
    echo "backup: dumping $database"
    pg_dump -h "$pg_host" -U postgres -Fc --no-owner --no-privileges -f "$work/dumps.partial/$database.dump" "$database"
  done
  # The directory appears only when every dump is there.
  mv "$work/dumps.partial" "$work/dumps"
}

stage_objects() {
  mkdir -p "$work/meta" "$work/objects"
  echo "backup: syncing the bucket $bucket"
  rclone sync "garage:$bucket" "$work/objects"
  size="$(rclone size --json "$work/objects" | tr -d ' \n')"
  count="$(printf '%s' "$size" | sed -n 's/.*"count":\([0-9][0-9]*\).*/\1/p')"
  bytes="$(printf '%s' "$size" | sed -n 's/.*"bytes":\([0-9][0-9]*\).*/\1/p')"
  [ -n "$count" ] && [ -n "$bytes" ] || fail "cannot read the count and the size of the objects from: $size"
  printf '%s %s\n' "$count" "$bytes" > "$work/meta/objects"
  echo "backup: $count objects, $bytes bytes"
}

cluster_id() {
  [ -f "$cluster_id_file" ] || fail "no identifier of the cluster ($cluster_id_file is missing)"
  id="$(tr -d ' \t\r\n' < "$cluster_id_file")"
  printf '%s' "$id" | grep -Eq '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' ||
    fail "the identifier of the cluster in $cluster_id_file is not a UID"
  printf '%s' "$id"
}

# What the earlier stages left must be whole: the dumps of both databases, not empty, the mirror, the numbers.
check_results() {
  for database in $databases; do
    [ -f "$work/dumps/$database.dump" ] || fail "no dump of $database in $work/dumps (the dump stage did not finish)"
    [ -s "$work/dumps/$database.dump" ] || fail "the dump of $database is empty"
  done
  [ -d "$work/objects" ] || fail "no mirror of the objects in $work/objects (the objects stage did not run)"
  [ -s "$work/meta/objects" ] || fail "no count of the objects (the objects stage did not finish)"
  [ -s "$work/meta/migrations" ] || fail "no migrations of shop (the dump stage did not finish)"
}

write_manifest() {
  host="$1"
  read -r count bytes < "$work/meta/objects"
  migrations="$(sed '/^$/d' "$work/meta/migrations" | while IFS= read -r version; do printf '%s,' "$(json_string "$version")"; done)"
  databases_json="$(for database in $databases; do printf '%s,' "$(json_string "$database")"; done)"
  {
    printf '%s\n' "{"
    printf '%s\n' "  \"revision\": $(json_string "$REVISION"),"
    printf '%s\n' "  \"time\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\","
    printf '%s\n' "  \"cluster\": $(json_string "$host"),"
    printf '%s\n' "  \"databases\": [${databases_json%,}],"
    printf '%s\n' "  \"migrations\": [${migrations%,}],"
    printf '%s\n' "  \"objects\": {\"count\": $count, \"bytes\": $bytes}"
    printf '%s\n' "}"
  } > "$work/manifest.json.partial"
  mv "$work/manifest.json.partial" "$work/manifest.json"
}

stage_snapshot() {
  [ -n "${REVISION:-}" ] || fail "REVISION (the tag of the api image) is not set"
  case "$tag" in
    "" | *[!A-Za-z0-9._-]*) fail "BACKUP_TAG '$tag' is not a tag (letters, digits, . _ -)" ;;
  esac
  host="$(cluster_id)"
  check_results
  write_manifest "$host"

  # The repository is created by the first backup; any other failure to open it (a wrong key, no access) is an error.
  status=0
  error_file="$work/meta/restic-open.err"
  restic cat config > /dev/null 2> "$error_file" || status=$?
  case "$status" in
    0) ;;
    # restic 0.17 and later: exit code 10 is "the repository does not exist".
    10)
      echo "backup: the repository does not exist, initializing it"
      restic init
      ;;
    *)
      cat "$error_file" >&2
      fail "cannot open the repository (restic exit code $status)"
      ;;
  esac

  # Only the locks that are stale (of a run that died): the ones in use are left alone.
  restic unlock
  echo "backup: snapshot with the tag $tag for the host $host"
  restic backup --retry-lock 30m --tag "$tag" --host "$host" "$work/dumps" "$work/objects" "$work/manifest.json"
  # Only the snapshots of this cluster are pruned: after the loss of a server the new one never removes its backups.
  restic forget --keep-within "$retention" --prune --retry-lock 30m --host "$host"
  echo "backup: done"
}

case "${1:-}" in
  dump) stage_dump ;;
  objects) stage_objects ;;
  snapshot) stage_snapshot ;;
  all)
    stage_dump
    stage_objects
    stage_snapshot
    ;;
  *)
    echo "usage: backup.sh dump|objects|snapshot|all" >&2
    exit 2
    ;;
esac
