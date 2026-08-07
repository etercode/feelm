#!/usr/bin/env bash
#
# The morning digest. One cron entry, every hour, on the hour.
#
#   0 * * * * /opt/feelm/deploy/push-digest.sh
#
# Hourly for a once-a-day notification, because "nine in the morning" is a
# different instant in every timezone. Each run asks which zones are at 9am now
# and sends only to those people; most runs find one or two zones, some find
# none. See PushDigestCommand for why this beats sending everything at 02:00
# when the crawl discovers it.
#
# Safe to run as often as anything likes. users.push_digest_at means a second
# run in the same hour — a manual invocation, a cron that fires twice, a clock
# going back — finds everybody already served and sends nothing.
#
# Deliberately separate from nightly.sh. That one is a long chain of catalog
# jobs that runs once; this is short, frequent, and must not be delayed behind
# an IMDb import, nor skipped on a night the crawl fails.

set -u

APP_DIR=/opt/feelm
COMPOSE="docker compose --env-file .env.prod -f compose.yaml -f compose.prod.yaml"

LOG=/var/log/feelm-push.log
touch "$LOG" 2>/dev/null || LOG="$APP_DIR/push.log"

cd "$APP_DIR" || exit 1

say() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >>"$LOG"
}

# </dev/null for the same reason as the nightly: cron gives the job no stdin,
# and docker compose exec without it can sit waiting on a terminal that will
# never arrive.
if $COMPOSE exec -T --user www-data php \
    php bin/console app:push:digest </dev/null >>"$LOG" 2>&1; then
    exit 0
fi

# Only a failure is worth a message. An hourly job that reports success is an
# hourly notification, which is the thing this whole feature exists to avoid
# sending people.
say "digest FAILED"
$COMPOSE exec -T --user www-data php \
    php bin/console app:notify "Push digest failed" --event=error --fail \
    </dev/null >>"$LOG" 2>&1 || true

exit 1
