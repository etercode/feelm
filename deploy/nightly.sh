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

# Telegram, if it is configured. Goes through the app because the token lives
# in the database where the admin can edit it, and this script has no database
# credentials. Never allowed to fail the run: || true, and the command itself
# always exits 0.
FAILURES=""
notify() {
    $COMPOSE exec -T --user www-data php \
        php bin/console app:notify "$@" --event=nightly </dev/null >>"$LOG" 2>&1 || true
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
        local code=$?
        say "--- $label FAILED (exit $code) after $((SECONDS - started))s"
        FAILURES="$FAILURES $label"
        # Straight away rather than only in the summary: a job that dies at 02:05
        # is worth knowing about before the run ends an hour later.
        notify "Nightly: $label failed" --fail \
            --fact="Job=$label" --fact="Exit=$code" --fact="After=$((SECONDS - started))s"
    fi
}

say "===== nightly start"

# Films TMDB added or edited since yesterday — the whole daily job for movies.
#
# This used to be `crawl-all --limit=2000`, which was not a daily crawler at
# all: it was the first crawl still running in instalments, taking 2,000 titles
# a night off a backlog of 400k. It looked like activity and hid the fact that
# nothing ever asked TMDB what had *changed* — a film we already held could have
# its runtime corrected or its poster replaced and we would never find out.
#
# Two days, not one, so a night that fails does not leave a hole. The windows
# overlap and persist() is an upsert, so seeing a title twice costs one request.
#
# --refresh-export re-downloads TMDB's id list. It is not this job's data, but
# this is the last movie job in the run and refresh-popularity below reads it.
# --include-backlog because there is no longer a backlog: the initial crawl
# finished on 2026-08-06 and "still to fetch" is 0. Until then this job had to
# tell a genuinely new title from one the first crawl had simply not reached,
# and used the id export to do it — anything already listed there was backlog
# and was left alone. With the catalogue complete that test inverts: a changed
# id we do not hold is now, by definition, one TMDB created after our last
# pass. Leaving the filter on would silently skip exactly the new releases this
# job exists to catch.
run "movies" php bin/console app:catalog:sync-changes --refresh-export --days=2 --include-backlog

# New series. Its backlog is finished — a 2,000 ceiling returned 43 titles last
# night — so this is already a new-titles-only job and stays as it is. Series
# *edits* are the next command's business.
run "series" php bin/console app:catalog:crawl-series --refresh-export --limit=2000 --since=1990

# New episodes for series we already hold. Two days of changes rather than one,
# so a night that fails does not leave a hole.
run "episodes" php bin/console app:catalog:refresh-series --days=2 --budget=600

# Must come after the two crawls: they are what re-download the id export this
# reads. Popularity is otherwise written once, on the day a title is crawled,
# and never again — which quietly leaves the whole site sorted by a measurement
# that stopped measuring. No API calls; it is a join against the export we
# already have.
run "popularity" php bin/console app:catalog:refresh-popularity

# The importer holds every known IMDb id in memory to join the dataset against,
# so its cost grows with the catalogue. It streams the ids now and fits in the
# default 256M at 531k of them; 1G stays as headroom, since the ceiling is the
# catalogue's size and not a fixed number.
run "imdb ratings" php -d memory_limit=1G bin/console app:catalog:imdb-ratings

# Stragglers the one-off backfill missed and any row whose fetch failed. The
# sync above now writes tags and similars itself, so on a normal night this
# finds only what series and the backlog crawl added. Bounded either way.
run "details" php bin/console app:catalog:backfill-details --limit=2000 --concurrency=20

# Artwork for anything new. It selects on poster_mirror IS NULL, so titles the
# sync added or re-posterised today are picked up without being told about.
#
# The original mirror finished on 2026-08-06 — every poster in the catalogue is
# in the bucket — so this now only ever sees a day's churn, a few hundred at
# most. The 3,000 ceiling is headroom for a day TMDB reposters something
# popular, not a queue being worked through.
run "artwork" php bin/console app:catalog:mirror-media --limit=3000 --posters-only

say "===== nightly end"

if [ -n "$FAILURES" ]; then
    notify "Nightly run finished with failures" --fail \
        --fact="Failed=${FAILURES# }" --fact="Took=$((SECONDS / 60))m"
else
    notify "Nightly run finished" --fact="Jobs=7" --fact="Took=$((SECONDS / 60))m"
fi
