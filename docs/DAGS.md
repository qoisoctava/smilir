# Writing DAGs for pipnix

A pipnix DAG is one Python file in `dags/` that exposes a single `DAG` dict.
No `pip import pipnix`, no framework classes — the file is plain Python and
the loader only reads the dict.

```python
# dags/my_pipeline.py
DAG = {
    "name": "my_pipeline",
    "schedule": "0 3 * * *",        # cron, or "interval:60"
    "venv": "reporting",            # optional; registered venv for all tasks
    "max_concurrency": 1,
    "tasks": [
        {"name": "fetch",  "func": "jobs.pipeline_demo.fetch", "retries": 2},
        {"name": "build",  "func": "jobs.pipeline_demo.build", "depends_on": ["fetch"]},
    ],
}
```

Worked examples live in [`dags/examples/`](../dags/examples/) — copy one to
`dags/` to activate it (only `dags/*.py` at the top level is scanned), then
validate with `php app/dagcheck.php --file=dags/<name>.py`.

---

## DAG-level keys

| Key | Required | Meaning |
| --- | --- | --- |
| `name` | ✔ | Lowercase letters/digits/`.`, `-`, `_`; unique; doubles as the file name (`dags/<name>.py`) |
| `schedule` | ✔ | `"m h dom mon dow"` (5-field cron) or `"interval:N"` (every N minutes, min 1). Granularity is one minute — that is the cron tick |
| `venv` | – | Name of a venv registered in **Python → Register environment** (or `--venv=` at install). All `func`/`command` tasks run in it unless the task overrides |
| `max_concurrency` | – | Max simultaneously running tasks of this DAG (default `1`, max `16`) |
| `tasks` | ✔ | Ordered list of task dicts (order is display only — execution follows `depends_on`) |

Every DAG is also subject to two global safety valves from `app/config.php`:
`tick.max_launches_per_tick` (default 4 — how many task processes one tick may
start across ALL dags) and the per-tick MySQL lock. On a $5 plan, leave them.

## Task-level keys

| Key | Required | Default | Meaning |
| --- | --- | --- | --- |
| `name` | ✔ | — | `[a-z0-9][a-z0-9_-]*`; unique inside the DAG; used in `depends_on` |
| `func` | one of func/command | — | Dotted `module.function`, resolved in the DAG's venv (repo root is on `PYTHONPATH`) |
| `command` | one of func/command | — | Shell string run with the venv's `bin` first on `PATH`; `{ds}` is replaced with the logical date. Run as `command` only, never both |
| `depends_on` | – | `[]` | Task names this task waits for. A failed dependency cascades the task to `upstream_failed` |
| `retries` | – | `0` | Extra attempts on failure (0–10) |
| `retry_delay` | – | `300` | Seconds between attempts (linear backoff: 300, 600, 900…) |
| `timeout` | – | none | Seconds per attempt; enforced by both the runner process and the tick |
| `email_on_failure` | – | `false` | Send an alert email when the final attempt fails (requires `alerts.enabled` in config) |

## The `func` contract

`lib/runner.py` imports your module inside the venv and calls the function
with **only the context kwargs it actually declares** — add just the
parameters you want:

```python
import os

def build(ds, run_id, attempt, params, task, dag):
    # ds        → logical date, "YYYY-MM-DD"
    # run_id    → e.g. "2026-11-16T03:00" or "manual__2026-11-16T03:12:44"
    # attempt   → 1-based attempt number
    # params    → JSON dict passed via `ops.php trigger --params='{"k":1}'`
    # task/dag  → names, e.g. for logging or routing
    ...
```

Rules of thumb:

- **Return a JSON-serializable dict** (`{"rows": 1200}`) and it is stored in
  `pn_task_run.result_json` — visible in the UI run detail.
- **`print()` goes to the task log** (`logs/runs/<dag>__<run>__<task>__a<n>.log`,
  viewable in the UI). Use `flush=True` in loops.
- Environment variables are available too: `PN_DAG`, `PN_TASK`, `PN_RUN_ID`,
  `PN_ATTEMPT` — handy inside `command` tasks.
- **No side effects at import time.** The module is *executed* when the DAG is
  loaded (every tick when the file's sha256 changes) — keep all work inside
  functions. Importing heavy libraries at module top level is fine; doing
  `requests.get(...)` at top level is not.
- The module must be importable in the DAG's venv: put code in `jobs/`
  (repo root is on `PYTHONPATH`) or install it into that venv. For a project
  directory use an editable install (`php app/ops.php venv:install
  --name=<env> -e /path/to/project`) — a plain copy install moves the package
  into `site-packages`, so paths resolved from `__file__` (`.env`, `sql/`,
  templates) no longer point at the project. See GUIDE §7.

## The `command` contract

```python
{"name": "publish", "command": "python -m jobs.pipeline_demo publish --date {ds}", "depends_on": ["build"]}
```

- `{ds}` is textually replaced with the logical date; escape as `{ds}` only
  where you want it (it is not a general template).
- Exit code 0 = success, anything else = failure (retries apply).
- The venv's `bin` directory is first on `PATH`, so bare `python` is the venv
  interpreter. System tools (`curl`, `mysql` client…) work as usual.

## Schedules

| Example | Meaning |
| --- | --- |
| `"0 3 * * *"` | daily at 03:00 (server timezone from `config.php`) |
| `"*/15 9-17 * * 1-5"` | every 15 min during business hours, Mon–Fri |
| `"interval:60"` | every 60 minutes, measured from when it was first seen/last fired |
| `""` | not valid — every DAG runs on some schedule; pause it instead (**Dags → ⏸**) |

Run id conventions: cron/interval runs get `"2026-11-16T03:00"`-style ids;
UI/CLI triggers get `manual__<timestamp>`. A scheduled minute never creates
two runs (`last_run_at` guard), and `createRun()` is idempotent per `run_id`.

## Validation — what the loader rejects

`lib/dag_loader.py` runs on every changed file and fails loudly with a JSON
error + traceback. Hard errors:

- `DAG` missing or not a dict (a `.to_dict()` object also works)
- `tasks` missing, empty, or containing non-dicts
- task without a name, duplicate task name, task without `func`/`command`

Warnings (DAG still loads): `func` module not importable *yet* (e.g. code not
uploaded/deployed yet), dotted path shape. The web **editor** additionally
rejects unknown `depends_on` targets and dependency cycles before writing the
file; **import** shows a parsed preview before committing.

`php app/dagcheck.php --file=dags/x.py` prints the normalized JSON the
scheduler will store — use it as a lint before committing.

## Patterns (see `dags/examples/`)

| File | Pattern |
| --- | --- |
| `01_linear_etl.py` | classic extract → transform → load → publish, `func` + one `command` |
| `02_parallel_fanout.py` | three independent fetchers run concurrently, then a single join |
| `03_shell_only.py` | command-only DAG, no venv needed, `{ds}` templating |
| `04_retry_backoff.py` | flaky external API: retries + delay + timeout + `email_on_failure` |
| `05_branch_join.py` | two independent branches converge on a final report task |

## Good practices

1. **Make tasks idempotent.** A retry re-runs the whole function — write so
   that re-applying the same `{ds}` is safe (upsert, not blind insert).
2. **One concern per task.** Small tasks → precise retries, readable logs,
   useful `result_json`.
3. **Set timeouts on anything touching the network.** A hung socket is the
   classic shared-hosting failure; the tick reaps it as `timed_out`.
4. **Keep secrets out of DAG files.** Read them from environment/config at
   runtime; the console and audit log show DAG contents.
5. **Don't rely on execution order.** The task list order is cosmetic —
   express ordering only via `depends_on`.
6. **Version your pipelines in git.** `dags/` is plain code; treat it like the
   rest of your repo (the UI's editor/import steps auto-backup to
   `storage/backups/` as a safety net).
