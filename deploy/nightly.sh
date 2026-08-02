#!/usr/bin/env bash
#
# The nightly catalogue update. One cron entry, four jobs, in order.
#
#   0 2 * * * /opt/feelm/deploy/nightly.sh
#
# Order matters. New titles are fetched before ratings are imported, so a film
# that arrives tonight carries its IMDb score the same night rather than
# tomorrow — the ratings import joins on ids that have to already exist.
#
# Everything here is resumable and safe to run twice. Each command subtracts
# what is already stored, so a run that dies halfway costs nothing but the time,
# and the next night picks up the rest.
#
# Deliberately not run more than daily: TMDB publishes its id exports once a
# day and IMDb its ratings once a day, so a second run would re-read the same
# files to discover the same nothing.
set -u

APP_DIR=/opt/feelm
COMPOSE="docker compose --env-file .env.prod -f compose.yaml -f compose.prod.yaml"

LOG=/var/log/feelm-nightly.log
touch "$LOG" 2>/dev/null || LOG="$APP_DIR/nightly.log"

cd "$APP_DIR" || exit 1

say() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >>"$LOG"
}

# </dev/null on every exec: cron gives the job no stdin, and docker compose exec
# without it can sit waiting on a terminal that will never arrive.
run() {
    local label=$1
    shift
    local started=$SECONDS

    say "--- $label"
    if $COMPOSE exec -T --user www-data php "$@" </dev/null >>"$LOG" 2>&1; then
        say "--- $label done in $((SECONDS - started))s"
    else
        # Carry on to the next job. A TMDB outage should not also cost us the
        # ratings import, which reads a different service entirely.
        say "--- $label FAILED (exit $?) after $((SECONDS - started))s"
    fi
}

say "===== nightly start"

# New films and new series. --refresh-export re-downloads TMDB's id list, which
# is the only way anything published today enters the queue at all.
run "movies" php bin/console app:catalog:crawl-all --refresh-export --limit=2000

run "series" php bin/console app:catalog:crawl-series --refresh-export --limit=2000 --since=1990

# New episodes for series we already hold. Two days of changes rather than one,
# so a night that fails does not leave a hole.
run "episodes" php bin/console app:catalog:refresh-series --days=2 --budget=600

# 1G because the importer holds every known IMDb id in memory to join against;
# at 472k ids the default 256M dies about a quarter of the way in.
run "imdb ratings" php -d memory_limit=1G bin/console app:catalog:imdb-ratings

say "===== nightly end"
