#!/usr/bin/env bash
#
# Runs the series crawl to completion, detached, on the server.
#
#   setsid nohup /opt/feelm/deploy/crawl-series.sh >/dev/null 2>&1 &
#
# The crawl is a day's work and the console command deliberately does not try
# to be one: it fetches a batch and exits, because a process that lives for
# seventeen hours is one that can lose seventeen hours. Everything it needs to
# resume is in the database, so this just calls it again until the queue is
# empty — a crash, a rate limit or a reboot costs one batch.
#
# A fresh process per batch also bounds memory: PHP's peak stays wherever one
# batch put it rather than climbing all day.
#
# Watch it at https://feelm.org/crawler?type=series, or tail the log below.
set -u

APP_DIR=/opt/feelm
LOG=/var/log/feelm-crawl-series.log
COMPOSE="docker compose --env-file .env.prod -f compose.yaml -f compose.prod.yaml"

# Titles per batch. Large enough that starting Symfony is noise, small enough
# that an interrupted batch is not much lost.
BATCH=${BATCH:-2000}

cd "$APP_DIR" || exit 1

say() { echo "[$(date -u '+%F %T')] $*" >> "$LOG"; }

say "starting — batch=$BATCH $*"

while :; do
    # </dev/null: `docker compose exec` reads stdin even with -T, and would
    # otherwise swallow whatever is feeding this script.
    output=$($COMPOSE exec -T --user www-data php \
        bin/console app:catalog:crawl-series --limit="$BATCH" "$@" 2>&1 </dev/null)
    status=$?

    echo "$output" >> "$LOG"

    if grep -q 'Every exported series is already' <<<"$output"; then
        say "queue empty — done"
        break
    fi

    if [ $status -ne 0 ]; then
        # A failed batch is usually TMDB or a container restart, and the next
        # one picks up where this stopped. Back off rather than spin.
        say "batch exited $status — retrying in 60s"
        sleep 60
        continue
    fi

    sleep 2
done

say "finished — $($COMPOSE exec -T --user www-data php bin/console app:catalog:crawl-series --status </dev/null 2>&1 | tr '\n' ' ')"
