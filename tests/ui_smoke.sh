#!/usr/bin/env bash
# smilir web console smoke test.
#
#   tests/ui_smoke.sh [--keep]
#
# Env: PN_MYSQL_HOST/PORT/USER/PASS (defaults to the dev MySQL on :3307).
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"

MYSQL_HOST="${PN_MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${PN_MYSQL_PORT:-3307}"
MYSQL_USER="${PN_MYSQL_USER:-root}"
MYSQL_PASS="${PN_MYSQL_PASS:-}"
DB="pipnix_ui"
VENV="/tmp/pn_ui_venv"
PORT="${PN_UI_PORT:-$(python3 - <<'PY_FREE_PORT'
import socket
sock = socket.socket()
sock.bind(("127.0.0.1", 0))
print(sock.getsockname()[1])
sock.close()
PY_FREE_PORT
)}"
KEEP=0
[[ "${1:-}" == "--keep" ]] && KEEP=1

mysql() {
  MYSQL_PWD="$MYSQL_PASS" command mysql --protocol=tcp \
    -h"$MYSQL_HOST" -P"$MYSQL_PORT" -u"$MYSQL_USER" "$@"
}
say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
fail() { printf '\033[31mFAIL:\033[0m %s\n' "$*"; exit 1; }
ok()   { printf '\033[32mok\033[0m — %s\n' "$*"; }

SERVER_PID=""
cleanup() {
  [[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null || true
  if [[ $KEEP -eq 0 ]]; then
    rm -f app/config.php dags/ui_flow.py jobs/ui_demo.py
    rm -rf dags/__pycache__ jobs/__pycache__
    mysql -e "DROP DATABASE IF EXISTS \`$DB\`;" >/dev/null 2>&1 || true
    rm -rf "$VENV"
  fi
}
trap cleanup EXIT

say "1. database + venv"
mysql -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4;"
[[ -x "$VENV/bin/python" ]] || python3 -m venv "$VENV" >/dev/null
ok "ready ($DB, $VENV)"

say "2. config + install + seed"
cat > app/config.php <<PHP
<?php
return [
    'db' => ['host' => '$MYSQL_HOST', 'port' => $MYSQL_PORT, 'name' => '$DB',
             'user' => '$MYSQL_USER', 'pass' => '$MYSQL_PASS'],
    'timezone' => 'UTC',
    'python_bin' => '$(command -v python3)',
    'lock' => ['name' => 'pipnix_ui', 'timeout' => 20],
    'tick' => ['max_launches_per_tick' => 4, 'stale_runner_grace' => 10],
    'alerts' => ['enabled' => false],
];
PHP
php app/install.php --admin=admin:Test-Pass-1 --venv=reporting:"$VENV" >/dev/null
php app/ops.php venv:check --name=reporting >/dev/null || true

cat > jobs/ui_demo.py <<'PY'
import time

def step_one(ds):
    print("step one ds=%s" % ds)
    time.sleep(0.1)
    return {"ok": 1}

def step_two(ds):
    print("step two ds=%s" % ds)
    return {"ok": 2}
PY
cat > dags/ui_flow.py <<'PY'
DAG = {
    "name": "ui_flow",
    "schedule": "interval:30",
    "venv": "reporting",
    "tasks": [
        {"name": "one", "func": "ui_demo.step_one", "timeout": 30},
        {"name": "two", "func": "ui_demo.step_two", "depends_on": ["one"], "timeout": 30},
    ],
}
PY
php app/ops.php tick >/dev/null
php app/ops.php trigger --dag=ui_flow >/dev/null
for _ in $(seq 1 20); do
  php app/scheduler.php >/dev/null 2>&1 || true
  STATE=$(mysql -N -B -e "SELECT state FROM \`$DB\`.pn_dag_run ORDER BY id DESC LIMIT 1;")
  [[ "$STATE" == "success" || "$STATE" == "failed" ]] && break
  sleep 1
done
[[ "$STATE" == "success" ]] || fail "seed run did not succeed ($STATE)"
ok "seed run success"

say "3. start server"
php -S 127.0.0.1:"$PORT" -t public public/index.php >/tmp/pn_ui_server.log 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 30); do
  curl -s -o /dev/null "http://127.0.0.1:$PORT/login" && break
  sleep 0.3
done
ok "listening on :$PORT (pid $SERVER_PID)"

JAR=/tmp/pn_ui_cookies.txt
rm -f "$JAR"
BASE="http://127.0.0.1:$PORT"

get() { curl -s -b "$JAR" -c "$JAR" "$BASE$1"; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" "$BASE$1"; }

say "4. auth"
[[ "$(code /)" == "302" ]] || fail "unauthenticated / should redirect"
ok "anonymous request redirects to login"

LOGIN=$(get /login)
grep -q "Welcome back" <<<"$LOGIN" || fail "login page missing form"
grep -q "smilir" <<<"$LOGIN" || fail "login page is missing the brand name"
TOKEN=$(sed -n 's/.*name="_csrf" value="\([a-f0-9]*\)".*/\1/p' <<<"$LOGIN" | head -1)
[[ -n "$TOKEN" ]] || fail "no csrf token"
BAD=$(curl -s -b "$JAR" -c "$JAR" -d "_csrf=$TOKEN&username=admin&password=wrong" "$BASE/login")
grep -q "Invalid username or password" <<<"$BAD" || fail "wrong password not rejected"
ok "wrong password rejected"

curl -s -o /dev/null -b "$JAR" -c "$JAR" \
  -d "_csrf=$TOKEN&username=admin&password=Test-Pass-1" "$BASE/login"
[[ "$(code /)" == "200" ]] || fail "login did not establish a session"
ok "login works"

CSS_CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/app.css")
[[ "$CSS_CODE" == "200" ]] || fail "stylesheet not served ($CSS_CODE)"
LOGO_CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/logo-white.png")
[[ "$LOGO_CODE" == "200" ]] || fail "logo not served ($LOGO_CODE)"
ok "assets served (app.css, logo)"

say "5. pages"
echo "Home:"
HOME=$(get /)
grep -q "Failed Dags" <<<"$HOME" || fail "home missing stats"
grep -q "Runner Slots" <<<"$HOME" || fail "home missing slots"
grep -q "Recent Failures" <<<"$HOME" || fail "home missing failures"
ok "home renders stats/health/slots"

DAGS=$(get /dags)
grep -q "ui_flow" <<<"$DAGS" || fail "dags list missing ui_flow"
ok "dags list"

SEARCH=$(get "/search?q=ui_flow")
grep -q "results\? for\|result for" <<<"$SEARCH" || fail "search results header missing"
grep -q "ui_flow" <<<"$SEARCH" || fail "search did not match the dag"
grep -q "Task instances" <<<"$SEARCH" || fail "search missing task group"
ok "global search (+ cmd-k palette)"

DAG=$(get /dags/ui_flow)
grep -q "Run history" <<<"$DAG" || fail "dag detail missing run history"
grep -q "step_one\|ui_demo.step_one" <<<"$DAG" || fail "dag detail missing task func"
ok "dag detail + grid"

for TAB in graph gantt code; do
  TAB_HTML=$(get "/dags/ui_flow?tab=$TAB")
  case "$TAB" in
    graph) grep -q "Dependency graph" <<<"$TAB_HTML" || fail "graph tab missing" ;;
    gantt) grep -q "Timeline" <<<"$TAB_HTML" || fail "gantt tab missing" ;;
    code)  grep -q "DAG = {" <<<"$TAB_HTML" || fail "code tab missing" ;;
  esac
done
SRC=$(get /dags/ui_flow/source)
grep -q "DAG = {" <<<"$SRC" || fail "dag source download missing"
ok "dag tabs + source download"

RUNS=$(get /runs)
grep -q "ui_flow" <<<"$RUNS" || fail "runs list empty"
RUN_ID=$(sed -n 's#.*href="/runs/\([0-9]*\)".*#\1#p' <<<"$RUNS" | head -1)
[[ -n "$RUN_ID" ]] || fail "no run id in list"
RUN=$(get "/runs/$RUN_ID")
grep -q "Tasks in this run" <<<"$RUN" || fail "run detail missing tasks"
ok "runs list + run detail (#$RUN_ID)"

TASK_ID=$(mysql -N -B -e "SELECT id FROM \`$DB\`.pn_task_run ORDER BY id LIMIT 1;")
LOG=$(get "/logs/$TASK_ID")
grep -q "Log" <<<"$LOG" || fail "log page missing"
TASKPAGE=$(get "/task/$TASK_ID")
grep -q "Resolved command" <<<"$TASKPAGE" || fail "task page missing"
ATTEMPT=$(get "/task/$TASK_ID/attempt/1")
grep -q "Log" <<<"$ATTEMPT" || fail "attempt log page missing"
DL=$(get "/logs/$TASK_ID/download")
grep -q "smilir:" <<<"$DL" || fail "log download missing"
ok "log viewer + task page + download"

LOGIDX=$(get /logs)
grep -q "Task runs" <<<"$LOGIDX" || fail "logs index page missing"
grep -q "ui_flow" <<<"$LOGIDX" || fail "logs index missing recent task"
ok "logs index (nav landing)"

DOCS=$(get /docs)
grep -q "Panduan Lengkap\|Persyaratan" <<<"$DOCS" || fail "getting started guide missing"
grep -q "Instalasi\|Docker Compose" <<<"$DOCS" || fail "guide missing install paths"
DOCS_CP=$(get /docs/cpanel)
grep -q "Deploying to cPanel" <<<"$DOCS_CP" || fail "cPanel guide missing"
DOCS2=$(get /docs/dags)
grep -q "Writing DAGs\|DAG-level keys\|Task-level keys" <<<"$DOCS2" || fail "dags doc missing"
DOCS3=$(get /docs/docker)
grep -q "Running with Docker\|docker compose up" <<<"$DOCS3" || fail "docker doc missing"
DOCS4=$(get /docs/updates)
grep -q "Updating smilir\|update:apply" <<<"$DOCS4" || fail "updates doc missing"
ok "bundled docs pages"

PY=$(get /python)
grep -q "reporting" <<<"$PY" || fail "python page missing venv"
PYD=$(get /python/reporting)
grep -q "Installed packages" <<<"$PYD" || fail "python detail missing packages"
ok "python list + detail"

ADMIN=$(get /admin)
grep -q "Administrator\|admin" <<<"$ADMIN" || fail "admin page missing users"
grep -q "Settings" <<<"$ADMIN" || fail "admin page missing settings card"
ok "admin (admin role)"

SETTINGS=$(get /admin/settings)
grep -q "Global task defaults\|Retention" <<<"$SETTINGS" || fail "settings page missing"
grep -q "Updates" <<<"$SETTINGS" || fail "settings page missing updater section"
UPDATES=$(get /admin/updates)
grep -q "Software updates" <<<"$UPDATES" || fail "updates page missing"
grep -q "Latest on GitHub" <<<"$UPDATES" || fail "updates page missing remote card"
ok "settings + updates pages"

AUDIT=$(get /admin/audit)
grep -q "Audit log" <<<"$AUDIT" || fail "audit page missing"
ok "audit page"

EDITOR=$(get /dags/new)
grep -q "New Dag" <<<"$EDITOR" || fail "dag editor missing"
grep -q "Save &amp; validate" <<<"$EDITOR" || fail "editor missing save button"
ok "dag editor"

IMPORT=$(get /dags/import)
grep -q "Drop or choose a .py file" <<<"$IMPORT" || fail "import page missing dropzone"
ok "import page"

PYLOG=$(get /python/reporting/log)
[[ "$(code /python/reporting/log)" == "200" ]] || fail "pip log page not 200"
ok "pip log page"

[[ "$(code /no-such-page)" == "404" ]] || fail "404 route"
ok "404 page"

say "6. logout"
curl -s -o /dev/null -b "$JAR" -c "$JAR" -d "_csrf=$TOKEN" "$BASE/logout" || true
[[ "$(code /)" == "302" ]] || fail "logout did not clear session"
ok "logout clears session"

say "UI SMOKE PASSED"
