#!/usr/bin/env bash
#
# Takes a compressed, verified snapshot of the catalog database.
#
# The crawl takes days and is meant to run once, so losing the database means
# spending those days again. This is cheap enough to run on a timer while the
# crawl is going.
#
#   scripts/backup.sh              # write a snapshot, keep the newest 8
#   KEEP=20 scripts/backup.sh      # keep more of them
#
# Restore with:
#   docker compose cp var/backups/<file> database:/tmp/restore.dump
#   docker compose exec database pg_restore -U app -d feelm --clean --if-exists /tmp/restore.dump
#
set -euo pipefail

cd "$(dirname "$0")/.."

DB="${POSTGRES_DB:-feelm}"
USER="${POSTGRES_USER:-app}"
KEEP="${KEEP:-8}"
DIR="var/backups"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
FILE="$DIR/$DB-$STAMP.dump"
REMOTE="/tmp/backup-$STAMP.dump"

mkdir -p "$DIR"

# Dump inside the container: pg_restore needs a seekable file to verify, which
# a pipe is not, so the file is written and checked there before coming out.
docker compose exec -T database pg_dump -U "$USER" -d "$DB" -Fc -f "$REMOTE"

if ! docker compose exec -T database pg_restore --list "$REMOTE" > /dev/null 2>&1; then
    docker compose exec -T database rm -f "$REMOTE"
    echo "backup verification failed — not keeping it" >&2
    exit 1
fi

docker compose cp "database:$REMOTE" "$FILE" > /dev/null
docker compose exec -T database rm -f "$REMOTE"

echo "$FILE ($(du -h "$FILE" | cut -f1), verified restorable)"

# Prune, keeping the newest $KEEP.
ls -1t "$DIR/$DB-"*.dump 2>/dev/null | tail -n +"$((KEEP + 1))" | while read -r old; do
    rm -f "$old"
    echo "removed $old"
done
