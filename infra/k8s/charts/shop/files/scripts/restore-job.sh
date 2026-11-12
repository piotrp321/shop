#!/bin/sh
# The pod of the restore (CronJob `restore`, docs/features/deploy-ops/spec.md, O4): every container of the pod runs one stage of
# this script, and the mode of the Job (RESTORE_MODE, set by make -C infra/k8s restore and backups) says what the stage does:
#
#   stage       image      list               check                      restore                              reindex
#   migrations  api        -                  the migration classes of the code into META_DIR/migrations (both modes)  -
#   restic      restic     the snapshots      the snapshot and its       the same check, then restic restore   -
#                                             migrations                 of the snapshot onto WORK_DIR
#   databases   postgres   -                  -                          DROP, CREATE and pg_restore of shop   -
#                                                                        and keycloak
#   objects     rclone     -                  -                          rclone sync back into product-images  -
#   redis       redis      -                  -                          FLUSHALL                              -
#   rabbitmq    rabbitmq   -                  -                          every queue purged                    -
#   api         api        -                  -                          -                                     catalog:reindex
#
# A snapshot whose manifest has a migration that is not a class of the deployed code (a snapshot newer than the code) is refused
# before anything is restored. Every stage stops at its first error (set -e) and the pod runs them one after another, so a
# failing stage fails the Job and no later stage runs. Nothing secret is printed or put on a command line: the access to the
# repository, the databases, Garage and RabbitMQ is in the environment of each container.
#
# Settings (the environment): RESTORE_MODE (list|check|restore|reindex), RESTORE_SNAPSHOT (the identifier of the snapshot, 8 to
# 64 hexadecimal digits, for check and restore), WORK_DIR (/work: the volume backup-work), META_DIR (/meta: an emptyDir the
# stages share), MIGRATIONS_DIR (/app/migrations of the api image), CONSOLE (bin/console), REDIS_HOST (redis). restic, psql,
# pg_restore, rclone and rabbitmqadmin read their own settings (RESTIC_*, PG*, RCLONE_CONFIG_GARAGE_*, RABBITMQADMIN_*).
set -eu

stage="${1:-}"
mode="${RESTORE_MODE:-}"
snapshot="${RESTORE_SNAPSHOT:-}"
work="${WORK_DIR:-/work}"
meta="${META_DIR:-/meta}"
migrations_dir="${MIGRATIONS_DIR:-/app/migrations}"
console="${CONSOLE:-bin/console}"
databases="shop keycloak"
bucket=product-images

fail() {
  printf '%s\n' "restore: $*" >&2
  exit 1
}

case "$mode" in
  list | check | restore | reindex) ;;
  *) fail "RESTORE_MODE '$mode' is none of list, check, restore and reindex" ;;
esac
case "$mode" in
  check | restore)
    printf '%s' "$snapshot" | grep -Eqx '[0-9a-f]{8,64}' ||
      fail "RESTORE_SNAPSHOT '$snapshot' is not the identifier of a snapshot (8 to 64 hexadecimal digits from make -C infra/k8s backups)"
    ;;
esac

# field <name> <JSON object>: the value of a string field.
field() {
  printf '%s' "$2" | sed -n "s/.*\"$1\":\"\\([^\"]*\\)\".*/\\1/p"
}

# describe <JSON object of a snapshot>: id time host tags, the time without the fraction of a second.
describe() {
  tags="$(printf '%s' "$1" | sed -n 's/.*"tags":\[\([^]]*\)\].*/\1/p' | tr -d '"')"
  printf '%s %s %s %s' "$(field id "$1")" "$(field time "$1" | sed 's/\.[0-9]*//')" "$(field hostname "$1")" "${tags:--}"
}

# manifest_value <manifest> <name>: a string value of the manifest.
manifest_value() {
  printf '%s' "$1" | tr -d '\n' | sed -n "s/.*\"$2\": *\"\\([^\"]*\\)\".*/\\1/p"
}

stage_migrations() {
  case "$mode" in check | restore) ;; *) return 0 ;; esac
  mkdir -p "$meta"
  : > "$meta/migrations.partial"
  for file in "$migrations_dir"/Version*.php; do
    [ -f "$file" ] || continue
    name="${file##*/}"
    printf 'DoctrineMigrations\\%s\n' "${name%.php}" >> "$meta/migrations.partial"
  done
  [ -s "$meta/migrations.partial" ] || fail "no migrations in $migrations_dir of the api image"
  sort "$meta/migrations.partial" > "$meta/migrations"
  rm -f "$meta/migrations.partial"
  echo "restore: the deployed code has $(wc -l < "$meta/migrations" | tr -d ' ') migrations"
}

list_snapshots() {
  listing="$(restic snapshots --json --no-lock)" || fail "cannot list the snapshots of the repository (restic exit code $?)"
  objects="$(printf '%s' "$listing" | awk '{ gsub(/\},\{"time"/, "}\n{\"time\""); print }' | grep '"id":"' || [ $? -eq 1 ])"
  echo "restore: the snapshots of the repository, the oldest first (identifier, time, host, tags, revision):"
  [ -n "$objects" ] || { echo "restore: there are no snapshots"; return 0; }
  printf '%s\n' "$objects" | while IFS= read -r object; do
    # shellcheck disable=SC2046 # four fields without spaces
    set -- $(describe "$object")
    if manifest="$(restic dump --no-lock "$1" /work/manifest.json 2>/dev/null)"; then
      revision="$(manifest_value "$manifest" revision)"
    else
      revision="?"
    fi
    printf '%s  %s  %s  %s  %s\n' "$(printf '%s' "$1" | cut -c1-8)" "$2" "$3" "$4" "${revision:-?}"
  done
}

# check_snapshot: the snapshot is in the repository, its manifest is readable and has only migrations of the deployed code. Sets
# snapshot_id (the full identifier).
check_snapshot() {
  [ -s "$meta/migrations" ] || fail "no list of the migrations of the deployed code in $meta/migrations (the stage migrations did not run)"
  found="$(restic snapshots --json --no-lock "$snapshot")" || fail "cannot read the snapshot $snapshot (restic exit code $?)"
  object="$(printf '%s' "$found" | awk '{ gsub(/\},\{"time"/, "}\n{\"time\""); print }' | grep '"id":"' || [ $? -eq 1 ])"
  [ -n "$object" ] || fail "no snapshot $snapshot in the repository (make -C infra/k8s backups lists them)"
  [ "$(printf '%s\n' "$object" | wc -l | tr -d ' ')" -eq 1 ] || fail "$snapshot names more than one snapshot"
  # shellcheck disable=SC2046 # four fields without spaces
  set -- $(describe "$object")
  snapshot_id="$1"
  manifest="$(restic dump --no-lock "$snapshot_id" /work/manifest.json)" || fail "cannot read manifest.json of the snapshot $snapshot"
  versions="$(printf '%s' "$manifest" | sed -n 's/^ *"migrations": *\[\(.*\)\],*$/\1/p' | tr ',' '\n' | sed 's/^ *"//; s/" *$//; s/\\\\/\\/g' | sed '/^$/d')"
  [ -n "$versions" ] || fail "manifest.json of the snapshot $snapshot has no migrations"
  count="$(printf '%s' "$manifest" | tr -d ' \n' | sed -n 's/.*"objects":{"count":\([0-9]*\),"bytes":\([0-9]*\)}.*/\1 objects (\2 bytes)/p')"
  echo "restore: snapshot $snapshot_id: time $2, host $3, tags $4, revision $(manifest_value "$manifest" revision), $(printf '%s\n' "$versions" | wc -l | tr -d ' ') migrations, ${count:-no count of the objects}"
  status=0
  unknown="$(printf '%s\n' "$versions" | grep -Fvx -f "$meta/migrations")" || status=$?
  case "$status" in
    0) fail "the snapshot has migrations that the deployed code does not know (the snapshot is newer than the deployed code): $(printf '%s' "$unknown" | tr '\n' ' ')" ;;
    1) ;;
    *) fail "cannot compare the migrations of the snapshot with those of the code" ;;
  esac
  echo "restore: the snapshot can be restored: every migration of it is in the deployed code"
}

stage_restic() {
  case "$mode" in
    list) list_snapshots ;;
    check) check_snapshot ;;
    restore)
      check_snapshot
      echo "restore: restic restore of $snapshot_id onto $work (what is not in the snapshot is deleted)"
      restic restore --retry-lock 30m "$snapshot_id:/work" --target "$work" --delete || fail "restic restore of $snapshot_id failed (exit code $?)"
      for database in $databases; do
        [ -s "$work/dumps/$database.dump" ] || fail "the snapshot has no dump of $database"
      done
      [ -d "$work/objects" ] || fail "the snapshot has no objects/"
      ;;
  esac
}

stage_databases() {
  [ "$mode" = restore ] || return 0
  # Both dumps first: nothing is dropped unless both can be restored.
  for database in $databases; do
    [ -s "$work/dumps/$database.dump" ] || fail "no dump of $database in $work/dumps (the stage restic did not finish)"
  done
  for database in $databases; do
    echo "restore: the database $database"
    # WITH (FORCE) ends the connections to it first. The owners are those of 10-create-databases.sh.
    psql -X -v ON_ERROR_STOP=1 -d postgres -c "DROP DATABASE IF EXISTS $database WITH (FORCE)" || fail "cannot drop the database $database"
    psql -X -v ON_ERROR_STOP=1 -d postgres -c "CREATE DATABASE $database OWNER $database" || fail "cannot create the database $database"
    # The dumps have no owners: --role makes the owner of the database the owner of everything restored, as after the migrations.
    pg_restore --no-owner --no-privileges "--role=$database" --exit-on-error "--dbname=$database" "$work/dumps/$database.dump" ||
      fail "pg_restore of the dump of $database failed (exit code $?)"
  done
}

stage_objects() {
  [ "$mode" = restore ] || return 0
  [ -d "$work/objects" ] || fail "no objects in $work/objects (the stage restic did not finish)"
  echo "restore: rclone sync of the objects into the bucket $bucket (what is not in the snapshot is deleted)"
  rclone sync "$work/objects" "garage:$bucket" || fail "rclone sync into $bucket failed (exit code $?)"
}

stage_redis() {
  [ "$mode" = restore ] || return 0
  echo "restore: FLUSHALL of Redis (the sessions of the store)"
  reply="$(redis-cli -h "${REDIS_HOST:-redis}" FLUSHALL)" || fail "redis-cli FLUSHALL failed (exit code $?)"
  [ "$reply" = OK ] || fail "redis-cli FLUSHALL answered: $reply"
}

stage_rabbitmq() {
  [ "$mode" = restore ] || return 0
  queues="$(mktemp)"
  rabbitmqadmin --non-interactive list queues > "$queues" || fail "cannot list the queues of RabbitMQ (exit code $?)"
  # One line per queue: the name, then the virtual host (/) and the other columns, separated by spaces.
  sed 's/  *\/  .*$//' "$queues" | sed '/^ *$/d' > "$queues.names"
  while IFS= read -r queue; do
    echo "restore: purging the queue $queue"
    rabbitmqadmin --non-interactive purge queue --name "$queue" || fail "cannot purge the queue $queue (exit code $?)"
  done < "$queues.names"
  rm -f "$queues" "$queues.names"
}

stage_api() {
  if [ "$mode" = reindex ]; then
    echo "restore: catalog:reindex"
    "$console" catalog:reindex || fail "catalog:reindex failed (exit code $?)"
  fi
  echo "restore: $mode done"
}

case "$stage" in
  migrations) stage_migrations ;;
  restic) stage_restic ;;
  databases) stage_databases ;;
  objects) stage_objects ;;
  redis) stage_redis ;;
  rabbitmq) stage_rabbitmq ;;
  api) stage_api ;;
  *)
    echo "usage: restore-job.sh migrations|restic|databases|objects|redis|rabbitmq|api" >&2
    exit 2
    ;;
esac
