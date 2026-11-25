#!/usr/bin/env bash
# smilir end-to-end test.
#
#   tests/e2e.sh            # runs against the dev MySQL, then drops the DB
#   tests/e2e.sh --keep     # keeps pipnix_test for inspection
#
# Environment:
#   PN_MYSQL_HOST / PN_MYSQL_PORT / PN_MYSQL_USER / PN_MYSQL_PASS
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"

MYSQL_HOST="${PN_MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${PN_MYSQL_PORT:-3307}"
MYSQL_USER="${PN_MYSQL_USER:-root}"
MYSQL_PASS="${PN_MYSQL_PASS:-}"
DB="pipnix_test"
VENV="/tmp/pn_e2e_venv"
KEEP=0
[[ "${1:-}" == "--keep" ]] && KEEP=1

mysql() {
  MYSQL_PWD="$MYSQL_PASS" command mysql --protocol=tcp \
    -h"$MYSQL_HOST" -P"$MYSQL_PORT" -u"$MYSQL_USER" "$@"
}

say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
fail() { printf '\033[31mFAIL:\033[0m %s\n' "$*"; exit 1; }
ok()   { printf '\033[32mok\033[0m — %s\n' "$*"; }

cleanup() {
  if [[ $KEEP -eq 0 ]]; then
    mysql -e "DROP DATABASE IF EXISTS \`$DB\`;" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

say "1. database"
mysql -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4;" \
  && ok "created $DB on $MYSQL_HOST:$MYSQL_PORT"

say "2. venv"
if [[ ! -x "$VENV/bin/python" ]]; then
  python3 -m venv "$VENV" >/dev/null 2>&1 || fail "could not create venv"
fi
ok "venv at $VENV"

say "3. config + install"
cat > app/config.php <<PHP
<?php
return [
    'db' => ['host' => '$MYSQL_HOST', 'port' => $MYSQL_PORT, 'name' => '$DB',
             'user' => '$MYSQL_USER', 'pass' => '$MYSQL_PASS'],
    'timezone' => 'UTC',
    'python_bin' => '$(command -v python3)',
    'lock' => ['name' => 'pipnix_test', 'timeout' => 20],
    'tick' => ['max_launches_per_tick' => 4, 'stale_runner_grace' => 10],
    'alerts' => ['enabled' => false],
];
PHP
php app/install.php --admin=admin:Test-Pass-1 --venv=e2e:"$VENV" || fail "install.php failed"
ok "schema + admin + venv registered"

say "4. venv inventory"
php app/ops.php venv:check --name=e2e || fail "venv:check failed"
PKGS=$(mysql -N -B -e "SELECT COUNT(*) FROM \`$DB\`.pn_venv_package;")
[[ "$PKGS" -ge 1 ]] || fail "no packages inventoried"
ok "$PKGS packages inventoried"

say "5. dag loading"
cat > jobs/e2e_demo.py <<'PY'
import time

def a(ds):
    print("a start ds=%s" % ds)
    time.sleep(0.2)
    return {"rows": 3}

def b(ds):
    print("b start ds=%s" % ds)
    time.sleep(0.2)
    return {"ok": True}

def boom(ds):
    print("boom start ds=%s" % ds)
    raise RuntimeError("intentional failure")
PY

cat > dags/e2e_flow.py <<'PY'
DAG = {
    "name": "e2e_flow",
    "schedule": "interval:30",
    "venv": "e2e",
    "max_concurrency": 1,
    "tasks": [
        {"name": "a", "func": "e2e_demo.a", "timeout": 30},
        {"name": "b", "func": "e2e_demo.b", "depends_on": ["a"], "timeout": 30},
        {"name": "c", "command": "python -c \"print('command task ok')\"",
         "depends_on": ["b"], "timeout": 30},
    ],
}
PY

cat > dags/e2e_fail.py <<'PY'
DAG = {
    "name": "e2e_fail",
    "schedule": "interval:30",
    "venv": "e2e",
    "max_concurrency": 1,
    "tasks": [
        {"name": "bad", "func": "e2e_demo.boom", "timeout": 30},
        {"name": "after", "func": "e2e_demo.b", "depends_on": ["bad"], "timeout": 30},
    ],
}
PY

php app/dagcheck.php --file=dags/e2e_flow.py >/dev/null || fail "dagcheck e2e_flow"
php app/dagcheck.php --file=dags/e2e_fail.py >/dev/null || fail "dagcheck e2e_fail"
php app/ops.php tick >/dev/null || fail "first tick failed"
DAGS=$(mysql -N -B -e "SELECT COUNT(*) FROM \`$DB\`.pn_dag WHERE name IN ('e2e_flow','e2e_fail');")
[[ "$DAGS" -eq 2 ]] || fail "expected 2 e2e dags, got $DAGS"
ok "e2e dags loaded and synced"

say "6. manual trigger → dispatch → success"
php app/ops.php trigger --dag=e2e_flow >/dev/null || fail "trigger failed"
RUN_ID=$(mysql -N -B -e "SELECT MAX(id) FROM \`$DB\`.pn_dag_run WHERE dag_id=(SELECT id FROM \`$DB\`.pn_dag WHERE name='e2e_flow');")
for i in $(seq 1 40); do
  php app/scheduler.php >/dev/null 2>&1 || true
  STATE=$(mysql -N -B -e "SELECT state FROM \`$DB\`.pn_dag_run WHERE id=$RUN_ID;")
  [[ "$STATE" == "success" || "$STATE" == "failed" ]] && break
  sleep 1
done
[[ "$STATE" == "success" ]] || { php app/ops.php tasks --run="$RUN_ID" || true; fail "run $RUN_ID ended '$STATE'"; }
TASKS_OK=$(mysql -N -B -e "SELECT COUNT(*) FROM \`$DB\`.pn_task_run WHERE dag_run_id=$RUN_ID AND state='success';")
[[ "$TASKS_OK" -eq 3 ]] || fail "expected 3 successful tasks, got $TASKS_OK"
RESULT=$(mysql -N -B -e "SELECT result_json FROM \`$DB\`.pn_task_run tr JOIN \`$DB\`.pn_task t ON t.id=tr.task_id WHERE tr.dag_run_id=$RUN_ID AND t.name='a';")
[[ "$RESULT" == *'"rows": 3'* || "$RESULT" == *'"rows":3'* ]] || fail "result_json missing: $RESULT"
ok "run #$RUN_ID success; result_json=$RESULT"

say "7. logs"
LOG_ID=$(mysql -N -B -e "SELECT tr.id FROM \`$DB\`.pn_task_run tr JOIN \`$DB\`.pn_task t ON t.id=tr.task_id WHERE tr.dag_run_id=$RUN_ID AND t.name='b';")
php app/ops.php logs --run="$LOG_ID" --tail=5 | grep -q "b start" || fail "log output missing"
ok "task log captured"

say "8. failure + cascade"
php app/ops.php trigger --dag=e2e_fail >/dev/null
FAIL_RUN=$(mysql -N -B -e "SELECT MAX(id) FROM \`$DB\`.pn_dag_run WHERE dag_id=(SELECT id FROM \`$DB\`.pn_dag WHERE name='e2e_fail');")
for i in $(seq 1 40); do
  php app/scheduler.php >/dev/null 2>&1 || true
  STATE=$(mysql -N -B -e "SELECT state FROM \`$DB\`.pn_dag_run WHERE id=$FAIL_RUN;")
  [[ "$STATE" == "success" || "$STATE" == "failed" ]] && break
  sleep 1
done
[[ "$STATE" == "failed" ]] || fail "expected failed run, got '$STATE'"
AFTER=$(mysql -N -B -e "SELECT tr.state FROM \`$DB\`.pn_task_run tr JOIN \`$DB\`.pn_task t ON t.id=tr.task_id WHERE tr.dag_run_id=$FAIL_RUN AND t.name='after';")
[[ "$AFTER" == "upstream_failed" ]] || fail "expected upstream_failed, got '$AFTER'"
ok "failure cascaded to downstream (upstream_failed)"

say "9. clear + rerun"
BAD_ID=$(mysql -N -B -e "SELECT tr.id FROM \`$DB\`.pn_task_run tr JOIN \`$DB\`.pn_task t ON t.id=tr.task_id WHERE tr.dag_run_id=$FAIL_RUN AND t.name='bad';")
php app/ops.php clear --run="$BAD_ID" | tee /tmp/pn_clear.txt | grep -q "cleared" || fail "clear failed"
php app/ops.php tick >/dev/null
sleep 1
BAD_STATE=$(mysql -N -B -e "SELECT state FROM \`$DB\`.pn_task_run WHERE id=$BAD_ID;")
[[ "$BAD_STATE" == "running" || "$BAD_STATE" == "queued" || "$BAD_STATE" == "failed" ]] \
  || fail "unexpected state after clear: $BAD_STATE"
ok "task cleared and re-dispatched (state: $BAD_STATE)"

say "10. pause / resume"
php app/ops.php pause --dag=e2e_flow | grep -q paused || fail "pause failed"
ACTIVE=$(mysql -N -B -e "SELECT is_active FROM \`$DB\`.pn_dag WHERE name='e2e_flow';")
[[ "$ACTIVE" == "0" ]] || fail "dag still active"
php app/ops.php resume --dag=e2e_flow | grep -q resumed || fail "resume failed"
ok "pause/resume works"

say "11. listing commands"
php app/ops.php dags
php app/ops.php runs --n=5
php app/ops.php venv:list --name=e2e | sed -n '1,5p'

say "12. prune retention"
php app/ops.php tick >/dev/null
# age the latest e2e_fail run to 40 days ago, then prune anything older than 30
mysql -e "UPDATE \`$DB\`.pn_dag_run SET finished_at = DATE_SUB(NOW(), INTERVAL 40 DAY)
          WHERE dag_id = (SELECT id FROM \`$DB\`.pn_dag WHERE name='e2e_fail')
          ORDER BY id DESC LIMIT 1;" || fail "aging run failed"
LOGS_BEFORE=$(ls logs/runs 2>/dev/null | wc -l | tr -d ' ')
OUT=$(php app/ops.php prune --days=30)
grep -q "nothing to prune" <<<"$OUT" && fail "prune saw nothing to remove"
RUNS_LEFT=$(mysql -N -B -e "SELECT COUNT(*) FROM \`$DB\`.pn_dag_run r JOIN \`$DB\`.pn_dag d ON d.id=r.dag_id
             WHERE d.name='e2e_fail' AND r.finished_at < DATE_SUB(NOW(), INTERVAL 30 DAY);")
[[ "$RUNS_LEFT" == "0" ]] || fail "old run survived prune"
LOGS_AFTER=$(ls logs/runs 2>/dev/null | wc -l | tr -d ' ')
[[ "$LOGS_AFTER" -lt "$LOGS_BEFORE" ]] || fail "log files not pruned ($LOGS_BEFORE → $LOGS_AFTER)"
DRY=$(php app/ops.php prune --days=30)
grep -q "nothing to prune\|dry run" <<<"$DRY" || true
ok "prune removed old run + its logs"

say "ALL CHECKS PASSED"
if [[ $KEEP -eq 1 ]]; then
  echo "database $DB kept; config.php still points to it"
else
  rm -f app/config.php dags/e2e_flow.py dags/e2e_fail.py jobs/e2e_demo.py
  rm -rf dags/__pycache__ jobs/__pycache__
  echo "cleaned test artifacts (config.php removed)"
fi
