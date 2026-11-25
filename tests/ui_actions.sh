#!/usr/bin/env bash
# smilir web console — Phase 6 action tests (editor, import, trigger,
# pause/resume, clear/mark, venv check/install/uninstall, users, audit).
#
#   tests/ui_actions.sh [--keep]
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"

MYSQL_HOST="${PN_MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${PN_MYSQL_PORT:-3307}"
MYSQL_USER="${PN_MYSQL_USER:-root}"
MYSQL_PASS="${PN_MYSQL_PASS:-}"
DB="pipnix_act"
VENV="/tmp/pn_act_venv"
VENV_EXTRA="/tmp/pn_act_extra_venv"
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
PORT=$(python3 - <<'PY_FREE_PORT'
import socket
sock = socket.socket(); sock.bind(("127.0.0.1", 0)); print(sock.getsockname()[1]); sock.close()
PY_FREE_PORT
)
BASE="http://127.0.0.1:$PORT"
JAR=/tmp/pn_act_cookies.txt
JAR2=/tmp/pn_act_cookies2.txt

cleanup() {
  [[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null || true
  if [[ $KEEP -eq 0 ]]; then
    rm -f app/config.php dags/act_flow.py dags/act_fail.py dags/imported_flow.py dags/def_flow.py jobs/act_demo.py
    rm -rf dags/__pycache__ jobs/__pycache__ storage/imports storage/backups
    mysql -e "DROP DATABASE IF EXISTS \`$DB\`;" >/dev/null 2>&1 || true
    rm -rf "$VENV" "$VENV_EXTRA" "$JAR" "$JAR2" /tmp/act_import.py /tmp/pn_act_requirements.txt
  fi
}
trap cleanup EXIT

post() { # post <path> [curl args...]
  local path="$1"; shift
  curl -s -L -b "$JAR" -c "$JAR" "$@" "$BASE$path"
}
post_code() {
  local path="$1"; shift
  curl -s -o /dev/null -w '%{http_code}' -L -b "$JAR" -c "$JAR" "$@" "$BASE$path"
}

say "1. provision"
mysql -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4;"
[[ -x "$VENV/bin/python" ]] || python3 -m venv "$VENV" >/dev/null
cat > app/config.php <<PHP
<?php
return [
    'db' => ['host' => '$MYSQL_HOST', 'port' => $MYSQL_PORT, 'name' => '$DB',
             'user' => '$MYSQL_USER', 'pass' => '$MYSQL_PASS'],
    'timezone' => 'UTC',
    'python_bin' => '$(command -v python3)',
    'lock' => ['name' => 'pipnix_act', 'timeout' => 20],
    'tick' => ['max_launches_per_tick' => 4, 'stale_runner_grace' => 10],
    'alerts' => ['enabled' => false],
];
PHP
php app/install.php --admin=admin:Test-Pass-1 --venv=reporting:"$VENV" >/dev/null
cat > jobs/act_demo.py <<'PY'
import time

def step_one(ds):
    print("one ds=%s" % ds)
    return {"ok": 1}

def step_two(ds):
    print("two ds=%s" % ds)
    return {"ok": 2}

def boom(ds):
    raise RuntimeError("intentional")
PY
php app/ops.php tick >/dev/null
ok "db + venv + seed"

say "2. server + login"
php -S 127.0.0.1:"$PORT" -t public public/index.php >/tmp/pn_act_server.log 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 30); do curl -s -o /dev/null "$BASE/login" && break; sleep 0.3; done
LOGIN=$(curl -s -c "$JAR" "$BASE/login")
TOKEN=$(sed -n 's/.*name="_csrf" value="\([a-f0-9]*\)".*/\1/p' <<<"$LOGIN" | head -1)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -d "_csrf=$TOKEN&username=admin&password=Test-Pass-1" "$BASE/login"
HOME_HTML=$(curl -s -b "$JAR" -c "$JAR" "$BASE/")
grep -q "Failed Dags" <<<"$HOME_HTML" || fail "login failed"
TOKEN=$(sed -n 's/.*name="_csrf" value="\([a-f0-9]*\)".*/\1/p' <<<"$HOME_HTML" | head -1)
ok "admin session"

say "3. editor — create dag"
OUT=$(post /dags/new \
  -d "_csrf=$TOKEN" -d "name=act_flow" -d "schedule=interval:30" -d "venv=reporting" -d "max_concurrency=2" \
  -d "tn[]=one"  -d "td[]="     -d "tf[]=act_demo.step_one" -d "tc[]=" -d "tr[]=1" -d "tdl[]=120" -d "tt[]=60" -d "tem[]=0" \
  -d "tn[]=two"  -d "td[]=one"  -d "tf[]="                  -d "tc[]=python -c \"print(2)\"" -d "tr[]=0" -d "tdl[]=300" -d "tt[]=60" -d "tem[]=0" -d "tem[]=1")
[[ -f dags/act_flow.py ]] || fail "editor did not write dags/act_flow.py"
DAGS=$(curl -s -b "$JAR" "$BASE/dags")
grep -q "act_flow" <<<"$DAGS" || fail "act_flow not listed"
php app/dagcheck.php --file=dags/act_flow.py >/dev/null || fail "written dag is invalid"
ok "editor wrote + validated dags/act_flow.py"

say "4. editor — rejects a cycle"
post /dags/new \
  -d "_csrf=$TOKEN" -d "name=cycle_flow" -d "schedule=interval:30" \
  -d "tn[]=a" -d "td[]=b" -d "tf[]=act_demo.step_one" -d "tc[]=" -d "tr[]=0" -d "tdl[]=300" -d "tt[]=0" -d "tem[]=0" \
  -d "tn[]=b" -d "td[]=a" -d "tf[]=act_demo.step_two"  -d "tc[]=" -d "tr[]=0" -d "tdl[]=300" -d "tt[]=0" -d "tem[]=0" >/dev/null
[[ -f dags/cycle_flow.py ]] && fail "cycle dag should not be written" || true
ok "cycle rejected"

say "5. import"
cat > /tmp/act_import.py <<'PY'
DAG = {
    "name": "imported_flow",
    "schedule": "interval:45",
    "venv": "reporting",
    "tasks": [{"name": "only", "command": "python -c \"print(3)\"", "timeout": 30}],
}
PY
UP=$(curl -s -L -b "$JAR" -c "$JAR" -F "_csrf=$TOKEN" -F "file=@/tmp/act_import.py;type=text/x-python" "$BASE/dags/import")
grep -q "Parsed preview" <<<"$UP" || fail "no preview after upload"
grep -q "imported_flow" <<<"$UP" || fail "preview missing name"
CONFIRM=$(post /dags/import/confirm -d "_csrf=$TOKEN")
[[ -f dags/imported_flow.py ]] || fail "import did not commit file"
mysql -N -B -e "SELECT COUNT(*) FROM \`$DB\`.pn_dag WHERE name='imported_flow';" | grep -q 1 || fail "import not synced"
ok "imported_flow committed + synced"

say "6. trigger → success"
post /dags/act_flow/trigger -d "_csrf=$TOKEN" >/dev/null
for _ in $(seq 1 20); do
  php app/scheduler.php >/dev/null 2>&1 || true
  ST=$(mysql -N -B -e "SELECT state FROM \`$DB\`.pn_dag_run r JOIN \`$DB\`.pn_dag d ON d.id=r.dag_id WHERE d.name='act_flow' ORDER BY r.id DESC LIMIT 1;")
  [[ "$ST" == "success" || "$ST" == "failed" ]] && break
  sleep 1
done
[[ "$ST" == "success" ]] || fail "act_flow run: $ST"
ok "trigger + dispatch → success"

say "7. failure → clear → mark success"
post /dags/new \
  -d "_csrf=$TOKEN" -d "name=act_fail" -d "schedule=interval:30" -d "venv=reporting" \
  -d "tn[]=bad"   -d "td[]="     -d "tf[]=act_demo.boom"     -d "tc[]=" -d "tr[]=0" -d "tdl[]=300" -d "tt[]=60" -d "tem[]=0" \
  -d "tn[]=after" -d "td[]=bad"  -d "tf[]=act_demo.step_two" -d "tc[]=" -d "tr[]=0" -d "tdl[]=300" -d "tt[]=60" -d "tem[]=0" -d "tem[]=0" >/dev/null
post /dags/act_fail/trigger -d "_csrf=$TOKEN" >/dev/null
FAIL_RUN=""
for _ in $(seq 1 20); do
  php app/scheduler.php >/dev/null 2>&1 || true
  ST=$(mysql -N -B -e "SELECT state FROM \`$DB\`.pn_dag_run r JOIN \`$DB\`.pn_dag d ON d.id=r.dag_id WHERE d.name='act_fail' ORDER BY r.id DESC LIMIT 1;")
  [[ "$ST" == "failed" ]] && break
  sleep 1
done
[[ "$ST" == "failed" ]] || fail "act_fail did not fail ($ST)"
FAIL_RUN=$(mysql -N -B -e "SELECT r.id FROM \`$DB\`.pn_dag_run r JOIN \`$DB\`.pn_dag d ON d.id=r.dag_id WHERE d.name='act_fail' ORDER BY r.id DESC LIMIT 1;")
AFTER=$(mysql -N -B -e "SELECT tr.state FROM \`$DB\`.pn_task_run tr JOIN \`$DB\`.pn_task t ON t.id=tr.task_id WHERE tr.dag_run_id=$FAIL_RUN AND t.name='after';")
[[ "$AFTER" == "upstream_failed" ]] || fail "cascade missing ($AFTER)"
ok "failure cascaded (upstream_failed)"

BAD_ID=$(mysql -N -B -e "SELECT tr.id FROM \`$DB\`.pn_task_run tr JOIN \`$DB\`.pn_task t ON t.id=tr.task_id WHERE tr.dag_run_id=$FAIL_RUN AND t.name='bad';")
post "/task/$BAD_ID/mark-success" -d "_csrf=$TOKEN" >/dev/null
BAD_ST=$(mysql -N -B -e "SELECT state FROM \`$DB\`.pn_task_run WHERE id=$BAD_ID;")
[[ "$BAD_ST" == "success" ]] || fail "mark-success failed ($BAD_ST)"
php app/ops.php tick >/dev/null
sleep 2; php app/ops.php tick >/dev/null
for _ in $(seq 1 10); do
  ST=$(mysql -N -B -e "SELECT state FROM \`$DB\`.pn_dag_run WHERE id=$FAIL_RUN;")
  [[ "$ST" == "success" ]] && break
  php app/scheduler.php >/dev/null 2>&1 || true
  sleep 1
done
[[ "$ST" == "success" ]] || fail "run did not close success after mark ($ST)"
ok "mark-success requeued downstream → run success"

say "8. pause / resume"
post /dags/act_flow/pause -d "_csrf=$TOKEN" >/dev/null
ACT=$(mysql -N -B -e "SELECT is_active FROM \`$DB\`.pn_dag WHERE name='act_flow';")
[[ "$ACT" == "0" ]] || fail "pause failed"
post /dags/act_flow/resume -d "_csrf=$TOKEN" >/dev/null
ACT=$(mysql -N -B -e "SELECT is_active FROM \`$DB\`.pn_dag WHERE name='act_flow';")
[[ "$ACT" == "1" ]] || fail "resume failed"
ok "pause/resume"

say "9. venv check / install / uninstall"
post /python/reporting/check -d "_csrf=$TOKEN" >/dev/null
PYV=$(mysql -N -B -e "SELECT python_version FROM \`$DB\`.pn_venv WHERE name='reporting';")
[[ -n "$PYV" ]] || fail "venv check did not store python_version"
ok "venv:check via web ($PYV)"

post /python/reporting/install -d "_csrf=$TOKEN" -d "spec=six" >/dev/null
FOUND=0
for _ in $(seq 1 60); do
  N=$(mysql -N -B -e "SELECT COUNT(*) FROM \`$DB\`.pn_venv_package p JOIN \`$DB\`.pn_venv v ON v.id=p.venv_id WHERE v.name='reporting' AND p.name='six';")
  [[ "$N" == "1" ]] && FOUND=1 && break
  sleep 1
done
[[ "$FOUND" == "1" ]] || fail "six not installed (check logs/venv-reporting.log)"
LOG=$(curl -s -b "$JAR" "$BASE/python/reporting/log")
grep -q "Successfully installed" <<<"$LOG" || fail "pip log page missing install output"
ok "venv:install six + live log page"

post /python/reporting/uninstall -d "_csrf=$TOKEN" -d "pkg=six" >/dev/null
GONE=0
for _ in $(seq 1 60); do
  N=$(mysql -N -B -e "SELECT COUNT(*) FROM \`$DB\`.pn_venv_package p JOIN \`$DB\`.pn_venv v ON v.id=p.venv_id WHERE v.name='reporting' AND p.name='six';")
  [[ "$N" == "0" ]] && GONE=1 && break
  sleep 1
done
[[ "$GONE" == "1" ]] || fail "six not uninstalled"
ok "venv:uninstall six"


say "10. favorites, DAG tabs, task + log pages"
post /dags/act_flow/star -d "_csrf=$TOKEN" -d "from=list" >/dev/null
FAV=$(mysql -N -B -e "SELECT is_favorite FROM \`$DB\`.pn_dag WHERE name='act_flow';")
[[ "$FAV" == "1" ]] || fail "star did not persist"
HOME_FAV=$(curl -s -b "$JAR" "$BASE/")
grep -q "Favorite Dags" <<<"$HOME_FAV" || fail "dashboard favorites section missing"
grep -q 'href="/dags/act_flow"' <<<"$HOME_FAV" || fail "starred dag not on the dashboard"

GRAPH=$(curl -s -b "$JAR" "$BASE/dags/act_flow?tab=graph")
grep -q "Dependency graph" <<<"$GRAPH" || fail "graph tab missing"
GANTT=$(curl -s -b "$JAR" "$BASE/dags/act_flow?tab=gantt")
grep -q "Timeline" <<<"$GANTT" || fail "gantt tab missing"
CODE=$(curl -s -b "$JAR" "$BASE/dags/act_flow?tab=code")
grep -q "DAG = {" <<<"$CODE" || fail "code tab missing source"
SRC=$(curl -s -b "$JAR" "$BASE/dags/act_flow/source")
grep -q "DAG = {" <<<"$SRC" || fail "source download missing"

TR_ID=$(mysql -N -B -e "SELECT tr.id FROM \`$DB\`.pn_task_run tr JOIN \`$DB\`.pn_task t ON t.id=tr.task_id JOIN \`$DB\`.pn_dag_run r ON r.id=tr.dag_run_id JOIN \`$DB\`.pn_dag d ON d.id=r.dag_id WHERE d.name='act_flow' ORDER BY tr.id DESC LIMIT 1;")
TASK_HTML=$(curl -s -b "$JAR" "$BASE/task/$TR_ID")
grep -q "Resolved command" <<<"$TASK_HTML" || fail "task page missing"
grep -q "Attempts" <<<"$TASK_HTML" || fail "task attempts missing"
ATTEMPT=$(curl -s -b "$JAR" "$BASE/task/$TR_ID/attempt/1")
grep -q "smilir: start" <<<"$ATTEMPT" || fail "attempt log page missing"
DL_BODY=$(curl -s -b "$JAR" "$BASE/logs/$TR_ID/download")
grep -q "smilir: start" <<<"$DL_BODY" || fail "log download missing"

PREV=$(curl -s -b "$JAR" --get --data-urlencode "schedule=0 3 * * *" "$BASE/dags/schedule-preview")
grep -q '"human":"daily at 03:00"' <<<"$PREV" || fail "schedule preview human text missing"
grep -q '"next":\[' <<<"$PREV" || fail "schedule preview next runs missing"
PREVI=$(curl -s -b "$JAR" --get --data-urlencode "schedule=interval:15" "$BASE/dags/schedule-preview")
grep -q '"human":"every 15 min"' <<<"$PREVI" || fail "interval preview missing"
ok "favorites, graph/gantt/code tabs, task page, downloads, schedule preview"

say "11. settings apply to DAG defaults"
post /admin/settings -d "_csrf=$TOKEN" \
  -d "alerts_from=smilir@example.com" -d "alerts_to=ops@example.com, oncall@example.com" \
  -d "retention_days=45" -d "task_retries=2" -d "task_retry_delay=90" \
  -d "task_timeout=30" -d "task_email=1" \
  -d "updater_enabled=1" -d "updater_repo=qoisoctava/pipnix" \
  -d "updater_channel=branch" -d "updater_branch=main" >/dev/null
[[ "$(mysql -N -B -e "SELECT v FROM \`$DB\`.pn_setting WHERE k='task.retries';")" == "2" ]] || fail "task.retries not saved"
[[ "$(mysql -N -B -e "SELECT v FROM \`$DB\`.pn_setting WHERE k='retention.days';")" == "45" ]] || fail "retention not saved"
[[ "$(mysql -N -B -e "SELECT v FROM \`$DB\`.pn_setting WHERE k='updater.repo';")" == "qoisoctava/pipnix" ]] || fail "updater repo not saved"
[[ "$(mysql -N -B -e "SELECT COUNT(*) FROM \`$DB\`.pn_dag WHERE source_hash IS NOT NULL;")" == "0" ]] || fail "dag cache was not invalidated"

cat > dags/def_flow.py <<'PY'
DAG = {
    "name": "def_flow",
    "schedule": "interval:30",
    "tasks": [{"name": "plain", "command": "echo default"}],
}
PY
php app/ops.php tick >/dev/null
DEF=$(mysql -N -B -e "SELECT CONCAT(retries,',',retry_delay,',',timeout,',',email_on_failure) FROM \`$DB\`.pn_task t JOIN \`$DB\`.pn_dag d ON d.id=t.dag_id WHERE d.name='def_flow';")
[[ "$DEF" == "2,90,30,1" ]] || fail "global task defaults not applied ($DEF)"

SETTINGS_HTML=$(curl -s -b "$JAR" "$BASE/admin/settings")
grep -q 'value="45"' <<<"$SETTINGS_HTML" || fail "settings page missing retention"
PURGE=$(post /admin/settings/purge -d "_csrf=$TOKEN" -d "days=3650")
grep -q "Purge finished" <<<"$PURGE" || fail "purge did not report success"
ok "settings saved, DAG cache invalidated, defaults 2/90/30/1 applied, purge works"

say "12. venv lifecycle and delete guard"
post /python/create -d "_csrf=$TOKEN" -d "name=extra" -d "path=$VENV_EXTRA" \
  -d "python=$(command -v python3)" >/dev/null
mysql -N -B -e "SELECT COUNT(*) FROM \`$DB\`.pn_venv WHERE name='extra';" | grep -q 1 || fail "venv create did not register"
[[ -x "$VENV_EXTRA/bin/python" ]] || fail "venv create did not make a venv"

post /python/extra/delete -d "_csrf=$TOKEN" >/dev/null
mysql -N -B -e "SELECT COUNT(*) FROM \`$DB\`.pn_venv WHERE name='extra';" | grep -q 0 || fail "venv delete did not remove the registration"

post /python/reporting/delete -d "_csrf=$TOKEN" >/dev/null
mysql -N -B -e "SELECT COUNT(*) FROM \`$DB\`.pn_venv WHERE name='reporting';" | grep -q 1 || fail "referenced venv was deleted"

: > /tmp/pn_act_requirements.txt
curl -s -L -b "$JAR" -c "$JAR" -F "_csrf=$TOKEN" \
  -F "requirements=@/tmp/pn_act_requirements.txt;type=text/plain" \
  "$BASE/python/reporting/requirements" >/dev/null
REQ_OK=0
for _ in $(seq 1 40); do
  if grep -q "pip exited with 0" logs/venv-reporting.log 2>/dev/null; then REQ_OK=1; break; fi
  sleep 1
done
[[ "$REQ_OK" == "1" ]] || fail "requirements install did not finish"
ok "venv create/delete, reference guard, requirements upload"

say "13. users + roles"
post /admin/users/new -d "_csrf=$TOKEN" -d "username=viewer1" -d "email=v@example.com" -d "password=viewpass1" -d "role=viewer" >/dev/null
mysql -N -B -e "SELECT COUNT(*) FROM \`$DB\`.pn_user WHERE username='viewer1';" | grep -q 1 || fail "viewer1 not created"
LOGIN2=$(curl -s -c "$JAR2" "$BASE/login")
T2=$(sed -n 's/.*name="_csrf" value="\([a-f0-9]*\)".*/\1/p' <<<"$LOGIN2" | head -1)
curl -s -o /dev/null -b "$JAR2" -c "$JAR2" -d "_csrf=$T2&username=viewer1&password=viewpass1" "$BASE/login"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR2" "$BASE/admin")
[[ "$CODE" == "403" ]] || fail "viewer should get 403 on /admin ($CODE)"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR2" -d "_csrf=$T2" "$BASE/dags/act_flow/trigger")
[[ "$CODE" == "403" ]] || fail "viewer should get 403 on trigger ($CODE)"
ok "viewer role enforced (403 on admin + trigger)"

say "14. audit"
AUDIT=$(curl -s -b "$JAR" "$BASE/admin/audit")
grep -q "dag.imported" <<<"$AUDIT" || fail "audit missing dag.imported"
grep -q "task.marked_success" <<<"$AUDIT" || fail "audit missing mark_success"
grep -q "venv.install.started" <<<"$AUDIT" || fail "audit missing venv install"
grep -q "user.invited" <<<"$AUDIT" || fail "audit missing user.invited"
ok "audit log records web actions"

say "ALL ACTION TESTS PASSED"
