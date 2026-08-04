#!/usr/bin/env bash
#
# Nightly offsite database backup.
#
# pg_dump runs in the database container and the uploader runs in the PHP one,
# so the dump is piped from one to the other through this script. Nothing is
# written to disk: the VPS has 71 GB free today, but a backup that needs local
# space is a backup that stops working on the night the disk is full, which is
# exactly the night you want it.
#
# -Fc is Postgres's custom format — compressed, and the only format pg_restore
# can restore selectively from. Restore with:
#
#   docker compose ... exec -T database pg_restore -U $POSTGRES_USER \
#       -d $POSTGRES_DB --clean --if-exists < feelm-YYYY-MM-DD-HHMMSS.dump
#
# set -o pipefail matters more than usual here. Without it the exit status is
# the uploader's, and the uploader is perfectly happy to store whatever a
# failed pg_dump gave it — which is how a backup job reports success for
# months and has nothing when it is needed.
set -uo pipefail

APP_DIR=/opt/feelm
LOG=/var/log/feelm-backup.log
KEEP=3

touch "$LOG" 2>/dev/null || LOG="$APP_DIR/backup.log"
cd "$APP_DIR" || exit 1

COMPOSE=(docker compose --env-file .env.prod -f compose.yaml -f compose.prod.yaml)

say() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >>"$LOG"; }

# Telegram, if it is set up. Through the app because the token lives in the
# database; never allowed to change this script's exit status, which is what a
# monitoring cron reads.
notify() {
	"${COMPOSE[@]}" exec -T --user www-data php \
		php bin/console app:notify "$@" --event=backup </dev/null >>"$LOG" 2>&1 || true
}

say "===== backup start"
started=$SECONDS

# shellcheck disable=SC2016
"${COMPOSE[@]}" exec -T database sh -lc 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' \
  | "${COMPOSE[@]}" exec -T --user www-data php bin/console app:db:backup --keep="$KEEP" \
  >>"$LOG" 2>&1

status=("${PIPESTATUS[@]}")

if [ "${status[0]}" -ne 0 ]; then
	say "pg_dump FAILED (exit ${status[0]}) — nothing uploaded, nothing pruned"
	notify "Backup failed" --fail \
		--fact="Stage=pg_dump" --fact="Exit=${status[0]}" \
		--fact="Note=nothing uploaded, nothing pruned"
	exit 1
fi

if [ "${status[1]}" -ne 0 ]; then
	say "upload FAILED (exit ${status[1]})"
	notify "Backup failed" --fail --fact="Stage=upload" --fact="Exit=${status[1]}"
	exit 1
fi

say "===== backup done in $((SECONDS - started))s"
notify "Backup finished" --fact="Took=$((SECONDS - started))s" --fact="Kept=$KEEP"
