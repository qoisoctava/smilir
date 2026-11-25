"""Task code for the example DAGs in dags/examples/ — plain Python, stdlib only.

smilir never imports smilir itself: the runner puts the repo root on
PYTHONPATH, resolves the dotted `func` path, and calls the function with only
the context kwargs it declares (ds, run_id, attempt, params, task, dag).

Anything printed goes to the task log; a returned JSON-serializable dict is
stored in pn_task_run.result_json.
"""
import os
import sys
import time


def _log(message):
    print(message, flush=True)


def _ctx():
    return {
        "ds": os.environ.get("PN_RUN_ID", "?")[:10],
        "dag": os.environ.get("PN_DAG", "?"),
        "task": os.environ.get("PN_TASK", "?"),
        "attempt": os.environ.get("PN_ATTEMPT", "1"),
    }


# ---- used by dags/examples/01_linear_etl.py --------------------------------

def extract(ds):
    """Pull the day's source rows (demo: generate them)."""
    ctx = _ctx()
    _log("extract start ds=%s dag=%s" % (ctx["ds"], ctx["dag"]))
    time.sleep(0.2)
    return {"rows": 1200, "ds": ds}


def transform(ds):
    """Clean + enrich (demo: pretend)."""
    _log("transform on ds=%s" % ds)
    time.sleep(0.2)
    return {"rows": 1150, "dropped": 50}


def load(ds):
    """Write to the warehouse (demo: pretend)."""
    _log("load on ds=%s" % ds)
    return {"loaded": True}


# ---- used by dags/examples/02_parallel_fanout.py ---------------------------

def fetch_a(ds):
    _log("source A on %s" % ds)
    return {"a": 10}


def fetch_b(ds):
    _log("source B on %s" % ds)
    time.sleep(0.3)  # different durations → concurrency visible in the run view
    return {"b": 20}


def fetch_c(ds):
    _log("source C on %s" % ds)
    return {"c": 30}


def join(ds):
    """Converge point — runs after all three fetchers succeeded."""
    _log("join on %s" % ds)
    return {"total": 60}


# ---- used by dags/examples/04_retry_backoff.py -----------------------------

def flaky_api(ds):
    """Simulates a flaky endpoint: fails attempt 1, succeeds from attempt 2.

    `attempt` is one of the context kwargs — declare it and the runner passes
    it in. With retries=3 in the DAG this task always ends up green.
    """
    attempt = int(os.environ.get("PN_ATTEMPT", "1") or 1)
    _log("flaky_api attempt=%d ds=%s" % (attempt, ds))
    if attempt < 2:
        raise RuntimeError("simulated upstream 503 (this is the demo)")
    return {"http": 200, "attempt": attempt}


# ---- used by dags/examples/05_branch_join.py -------------------------------

def summarize(ds):
    """Runs after two independent branches (A, B) both finished."""
    _log("summarize both branches done on %s" % ds)
    return {"summary": True}


# ---- CLI entry used by 01_linear_etl.py's `publish` shell task --------------

def publish(date):
    _log("publish start date=%s" % date)
    _log("publish done")
    return 0


if __name__ == "__main__":
    command = sys.argv[1] if len(sys.argv) > 1 else ""
    if command == "publish":
        date = None
        for i, arg in enumerate(sys.argv):
            if arg in ("--date", "-d") and i + 1 < len(sys.argv):
                date = sys.argv[i + 1]
        sys.exit(publish(date or ""))
    print("usage: python -m jobs.pipeline_demo publish --date YYYY-MM-DD", file=sys.stderr)
    sys.exit(2)
