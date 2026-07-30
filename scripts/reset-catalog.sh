#!/usr/bin/env bash
#
# Drops the catalog database and rebuilds it from the migrations.
#
# Dropping rather than truncating: it resets identity sequences, cannot leave a
# stray column behind from a hand-run ALTER, and guarantees the schema is
# exactly what the migrations describe — which is the point of a production
# start.
#
#   scripts/reset-catalog.sh --yes                # database only
#   scripts/reset-catalog.sh --yes --media        # also delete mirrored posters
#   scripts/reset-catalog.sh --yes --no-backup    # skip the safety snapshot
#
# A verified backup is taken first unless --no-backup is given, or the database
# does not exist yet.
#
set -euo pipefail

cd "$(dirname "$0")/.."

DB="${POSTGRES_DB:-youknowme}"
USER="${POSTGRES_USER:-app}"
CONFIRMED=false
DROP_MEDIA=false
BACKUP=true

for arg in "$@"; do
    case "$arg" in
        --yes) CONFIRMED=true ;;
        --media) DROP_MEDIA=true ;;
        --no-backup) BACKUP=false ;;
        *) echo "unknown option: $arg" >&2; exit 1 ;;
    esac
done

if [ "$CONFIRMED" != true ]; then
    cat >&2 <<'MSG'
This deletes every row in the catalog database: works, credits, people, the
crawl queue, and any accounts, shelves and reviews.

Re-run with --yes once you are sure, and add --media to delete mirrored posters
as well.
MSG
    exit 1
fi

if [ "$BACKUP" = true ] && docker compose exec -T database psql -U "$USER" -lqt | cut -d'|' -f1 | grep -qw "$DB"; then
    echo "Backing up first…"
    ./scripts/backup.sh
else
    echo "Skipping backup."
fi

echo "Recreating $DB…"
docker compose exec -T database psql -U "$USER" -d postgres -c "DROP DATABASE IF EXISTS $DB WITH (FORCE);"
docker compose exec -T database psql -U "$USER" -d postgres -c "CREATE DATABASE $DB OWNER $USER;"

echo "Applying migrations…"
docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction

echo "Clearing crawl cursors and scratch files…"
# .json is the cursor state; .jsonl are dumps an earlier crawl left behind.
rm -f data/crawl/*.json data/crawl/*.jsonl

if [ "$DROP_MEDIA" = true ]; then
    echo "Clearing mirrored artwork…"
    docker compose exec -T php sh -c 'rm -rf public/media/tmdb'
fi

docker compose exec -T php bin/console doctrine:schema:validate

echo
echo "Empty and ready. Next:"
echo "  docker compose exec php bin/console app:catalog:queue --since=1990 --requests=2000"
