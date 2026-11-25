#!/usr/bin/env bash
#
# smilir container entry point.
#
# 1. wait for MySQL
# 2. render app/config.php from PIPNIX_* environment variables
# 3. create/upgrade the schema and the first admin account
# 4. make sure a default Python venv exists and is registered
# 5. run Apache and supercronic side by side (either one exiting stops the
#    container, so `docker ps` never shows a half-dead instance)
set -Eeuo pipefail

APP_DIR="${PIPNIX_APP_DIR:-/var/www/pipnix}"
ADMIN_USER="${PIPNIX_ADMIN_USER:-admin}"
ADMIN_PASS="${PIPNIX_ADMIN_PASS:-admin}"
DB_WAIT="${PIPNIX_DB_WAIT:-90}"

log() { printf '[smilir] %s\n' "$*"; }

cd "$APP_DIR"

# ------------------------------------------------------------- optional remap
# On Linux, bind mounts keep the host's UID. Setting PIPNIX_UID/PIPNIX_GID
# (usually `id -u` / `id -g`) makes www-data match, so DAGs created in the web
# editor stay editable on the host.
if [ -n "${PIPNIX_UID:-}" ]; then
    log "mapping www-data to ${PIPNIX_UID}:${PIPNIX_GID:-$PIPNIX_UID}"
    groupmod -o -g "${PIPNIX_GID:-$PIPNIX_UID}" www-data
    usermod  -o -u "${PIPNIX_UID}" www-data
fi

# ------------------------------------------------------------------ database
log "waiting for the database (max ${DB_WAIT}s)"
PIPNIX_DB_WAIT="$DB_WAIT" pipnix-wait-db

log "rendering app/config.php"
pipnix-configure
chown www-data:www-data app/config.php 2>/dev/null || true
chmod 0640 app/config.php 2>/dev/null || true

# --------------------------------------------------------------- filesystem
mkdir -p logs/runs storage dags jobs /venvs
chown -R www-data:www-data logs storage dags jobs /venvs 2>/dev/null \
    || log "chown skipped (bind mount not owned by this container)"
chmod -R ug+rwX logs storage dags jobs /venvs 2>/dev/null || true

# ------------------------------------------------------------------- install
php app/install.php >/dev/null
log "schema: ok"

USERS=$(php -r 'require "app/bootstrap.php"; echo (int) Db::one("SELECT COUNT(*) AS n FROM pn_user")["n"];')
if [ "$USERS" = "0" ]; then
    php app/install.php --admin="${ADMIN_USER}:${ADMIN_PASS}" >/dev/null
    FORCED=$(php -r 'require "app/bootstrap.php"; $u = Db::one("SELECT must_change_password AS f FROM pn_user WHERE username = ?", [getenv("PIPNIX_ADMIN_USER") ?: "admin"]); echo (int) ($u["f"] ?? 0);')
    if [ "$FORCED" = "1" ]; then
        # admin:admin and other weak passwords are the documented convenience
        # path: the console refuses everything until a new one is chosen.
        log "created the first admin account '${ADMIN_USER}' with the temporary password '${ADMIN_PASS}'"
        log "  the console asks for a new password before anything else"
    else
        log "created the first admin account '${ADMIN_USER}'"
    fi
fi

# --------------------------------------------------------------- python venv
if [ ! -x /venvs/default/bin/python3 ]; then
    log "creating the default Python venv at /venvs/default"
    python3 -m venv /venvs/default
    /venvs/default/bin/pip install --quiet --upgrade pip >/dev/null 2>&1 \
        || log "pip self-upgrade skipped (no network?)"
fi

VENVS=$(php -r 'require "app/bootstrap.php"; echo (int) Db::one("SELECT COUNT(*) AS n FROM pn_venv")["n"];')
if [ "$VENVS" = "0" ]; then
    php app/install.php --venv="default:/venvs/default" >/dev/null
    log "registered venv 'default' (/venvs/default)"
fi

# --------------------------------------------------------------------- start
log "starting Apache :80 and supercronic (scheduler, every minute)"

apache2-foreground &
APACHE_PID=$!

# The scheduler runs as www-data so log files match the files the web console
# creates; the MySQL lock protects against overlapping ticks.
setpriv --reuid=www-data --regid=www-data --init-groups \
    /usr/local/bin/supercronic -passthrough-logs /etc/pipnix/crontab &
CRON_PID=$!

shutdown() {
    trap - TERM INT
    log "stopping"
    kill -TERM "$CRON_PID" "$APACHE_PID" 2>/dev/null || true
    wait "$CRON_PID" "$APACHE_PID" 2>/dev/null || true
    exit 0
}
trap shutdown TERM INT

set +e
wait -n "$APACHE_PID" "$CRON_PID"
STATUS=$?
set -e

log "a service exited (status ${STATUS}) — stopping the container"
kill -TERM "$CRON_PID" "$APACHE_PID" 2>/dev/null || true
wait 2>/dev/null || true
exit "$STATUS"
