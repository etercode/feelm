#!/usr/bin/env bash
#
# Runs on the server, piped in over SSH by .github/workflows/deploy.yml.
# Also safe to run by hand:  bash /opt/feelm/deploy/remote.sh
#
# The server pulls its own code rather than having it copied in, so what is
# deployed is always a commit that exists on GitHub — nothing can arrive here
# that is not in the repository's history.
set -euo pipefail

APP_DIR=/opt/feelm
COMPOSE="docker compose --env-file .env.prod -f compose.yaml -f compose.prod.yaml"

cd "$APP_DIR"

echo "==> Fetching main"
git fetch --quiet origin main
# Hard reset, not merge: the server has no local commits worth keeping, and a
# merge conflict here would leave the deploy half-applied.
git reset --quiet --hard origin/main
echo "    now at $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"

echo "==> Building"
$COMPOSE build

echo "==> Starting"
$COMPOSE up -d --remove-orphans

echo "==> Waiting for the database"
for _ in $(seq 30); do
    if $COMPOSE exec -T database pg_isready -q; then break; fi
    sleep 2
done

echo "==> Migrating"
# --allow-no-migration: a deploy that changes no schema is not a failure.
$COMPOSE exec -T php bin/console doctrine:migrations:migrate \
    --no-interaction --allow-no-migration

echo "==> Checking"
# The container is only healthy once nginx and FPM agree, so ask through nginx.
for attempt in $(seq 15); do
    code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8093/api/items?limit=1 || true)
    if [ "$code" = "200" ]; then
        echo "    API answering (HTTP $code)"
        break
    fi
    if [ "$attempt" = "15" ]; then
        echo "    API not answering after 30s (last status: $code)" >&2
        $COMPOSE logs --tail 40 php nginx >&2
        exit 1
    fi
    sleep 2
done

# Images from previous deploys pile up; the crawl needs the disk more.
echo "==> Pruning old images"
docker image prune -f --filter 'until=168h' >/dev/null

echo "==> Done"
