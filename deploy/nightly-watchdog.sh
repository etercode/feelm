#!/usr/bin/env bash
#
# Shouts if the nightly has not finished recently.
#
#   0 */6 * * * /opt/feelm/deploy/nightly-watchdog.sh
#
# ---- why this exists --------------------------------------------------------
#
# Every alert the nightly sends is sent *by the nightly*. If cron stops firing,
# the box reboots into a broken state, or the compose stack is down at 02:00,
# nothing fails — nothing runs. The signal is an absence, and an absence of
# Telegram messages is indistinguishable from a quiet week.
#
# That matters more here than it would elsewhere, because TMDB's changes feed
# only answers fourteen days back. A nightly that dies silently on the 1st is
# recoverable on the 10th and unrecoverable on the 20th: those edits cannot be
# asked for again, and the only way back is a full re-crawl of the catalogue.
# So the thing to watch is not whether a job failed but whether the run
# happened at all.
#
# Deliberately dumb: it reads the log's own end marker rather than asking the
# database or the app anything. If the log is stale, something upstream of
# every other check is already wrong.
set -u

APP_DIR=/opt/feelm
LOG=/var/log/feelm-nightly.log

# Six hours of slack past a daily run: long enough that a slow night or a
# clock skew is not an alert, short enough to catch it the same day.
MAX_AGE_HOURS=30

cd "$APP_DIR" || exit 1
COMPOSE="docker compose --env-file .env.prod -f compose.yaml -f compose.prod.yaml"

notify() {
    $COMPOSE exec -T --user www-data php \
        php bin/console app:notify "$@" --event=error </dev/null >/dev/null 2>&1 || true
}

if [ ! -f "$LOG" ]; then
    notify "Nightly watchdog: no log at all" --fail --fact="Path=$LOG"
    exit 1
fi

# The end marker, not the file's mtime: the log is appended to by every job, so
# mtime says "something wrote" rather than "the run completed".
last_end=$(grep -F '===== nightly end' "$LOG" | tail -1 | sed -n 's/^\[\(.*\)\].*/\1/p')

if [ -z "$last_end" ]; then
    notify "Nightly watchdog: no completed run in the log" --fail
    exit 1
fi

last_epoch=$(date -d "$last_end" +%s 2>/dev/null || echo 0)
age_hours=$(( ( $(date +%s) - last_epoch ) / 3600 ))

if [ "$last_epoch" -eq 0 ]; then
    notify "Nightly watchdog: cannot read the last run time" --fail --fact="Line=$last_end"
    exit 1
fi

if [ "$age_hours" -ge "$MAX_AGE_HOURS" ]; then
    # Said plainly, because the person reading it on a phone needs to know that
    # this one has a clock on it.
    notify "Nightly has not run for ${age_hours}h" --fail \
        --fact="Last=$last_end" \
        --fact="Changes feed=14 days, then unrecoverable" \
        --fact="Fix=/opt/feelm/deploy/nightly.sh"
    exit 1
fi

exit 0
